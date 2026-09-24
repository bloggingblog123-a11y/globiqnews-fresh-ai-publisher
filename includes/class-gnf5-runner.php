<?php
if (!defined('ABSPATH')) { exit; }

class GNF5_Runner {
    private static $bulk_lock_token='';
    private static $run_id='';
    private static function update_post_checked($args,$context='post update') {
        $result=GNF5_Publish::save($args);
        if(is_wp_error($result))return new WP_Error('post_update',$context.' failed: '.$result->get_error_message());
        if(!$result)return new WP_Error('post_update',$context.' failed: WordPress returned no post ID.');
        return absint($result);
    }

    public static function cron_run_category($cat_id) {
        $cs=GNF5_Utils::category_settings($cat_id);
        if(!empty($cs['enabled']))GNF6_Queue::enqueue(array($cat_id),'cron');
    }
    public static function cron_continue_category($cat_id,$remaining=1,$pass=1,$zero_streak=0) {
        // Compatibility for continuation events left by earlier releases.
        self::cron_run_category($cat_id);
    }

    public static function auto_recovery_cron() {
        GNF5_Publish::cron_check();
        $s=GNF5_Utils::settings();
        if(empty($s['auto_recovery_enabled']))return;

        $posts=GNF5_Utils::auto_recoverable_posts(2);
        if(!$posts)return;

        GNF5_Utils::log('AUTO RECOVERY START — '.count($posts).' failed draft(s) due for retry.','info',0);

        foreach($posts as $post){
            $post_id=absint($post->ID);
            $attempt=GNF5_Utils::recovery_attempts($post_id)+1;
            delete_post_meta($post_id,'_gnf5_recovery_next');

            $result=self::retry_post($post_id);

            // A busy category lock is not a real recovery attempt. Defer without consuming one.
            if(is_wp_error($result) && $result->get_error_code()==='locked'){
                update_post_meta($post_id,'_gnf5_recovery_next',time()+(5*MINUTE_IN_SECONDS));
                GNF5_Utils::log('AUTO RECOVERY DEFERRED — post #'.$post_id.' category is busy; retrying in about 5 minutes without consuming an attempt.','info',0);
                continue;
            }

            if(is_wp_error($result) && in_array($result->get_error_code(),array('manual_edit','research_missing'),true)){
                GNF5_Utils::set_state($post_id,'manual_review',$result->get_error_message());GNF5_Utils::clear_recovery_state($post_id);continue;
            }
            update_post_meta($post_id,'_gnf5_recovery_attempts',$attempt);

            if(!is_wp_error($result) && !empty($result['validated'])){
                GNF5_Utils::clear_recovery_state($post_id);
                GNF5_Utils::log('AUTO RECOVERY SUCCESS — post #'.$post_id.' recovered on attempt '.$attempt.'.','success',0);
                continue;
            }

            $message=is_wp_error($result)?$result->get_error_message():implode(' | ',array_slice((array)($result['errors']??array()),0,3));
            update_post_meta($post_id,'_gnf5_recovery_last_error',sanitize_text_field($message));

            $max=GNF5_Utils::recovery_max_attempts();
            if($attempt >= $max){
                update_post_meta($post_id,'_gnf5_recovery_exhausted',1);
                delete_post_meta($post_id,'_gnf5_recovery_next');
                GNF5_Utils::log('AUTO RECOVERY EXHAUSTED — post #'.$post_id.' failed after '.$attempt.' automatic attempts. Manual attention is now shown. '.$message,'warning',0);
            }else{
                $delay=GNF5_Utils::recovery_delay_for_attempt($attempt+1);
                update_post_meta($post_id,'_gnf5_recovery_next',time()+$delay);
                GNF5_Utils::log('AUTO RECOVERY RETRY SCHEDULED — post #'.$post_id.' attempt '.$attempt.' failed; next retry in '.human_time_diff(time(),time()+$delay).'. '.$message,'warning',0);
            }
        }
    }


    public static function run_category($cat_id,$trigger='manual',$override_limit=null,$scan_multiplier=1,$run_id='',$worker_token='') {
        $cat_id=absint($cat_id);
        $cat=get_category($cat_id);
        if(!$cat || is_wp_error($cat))return new WP_Error('category','WordPress category not found.');
        GNF5_Utils::option_cache_clear(GNF5_OPTION);
        $settings=GNF5_Utils::settings();
        $cs=GNF5_Utils::category_settings($cat_id,$settings);

        if (!GNF5_Utils::valid_author_id($cs['author_id'])) return new WP_Error('author', $cat->name.': select an author before running this category.');
        if($worker_token ? !GNF5_Utils::adopt_lock($cat_id,$worker_token) : !GNF5_Utils::acquire_lock($cat_id)){
            return new WP_Error('locked','This category is already running or all worker slots are occupied.');
        }
        $created=0;$attempted=0;
        try {
        GNF5_Sources::reset_budget();
        if(function_exists('set_time_limit'))@set_time_limit(300);

        $run_limit=$override_limit===null?absint($cs['post_limit']):min(absint($cs['post_limit']),max(1,absint($override_limit)));
        $scan_multiplier=min(4,max(1,absint($scan_multiplier)));
        GNF5_Utils::log('CATEGORY START — '.$cat->name.' ('.$trigger.'). Target: '.$run_limit.' post(s).','info',$cat_id);

        $created=0;$published=0;$drafts=0;$attempted=0;$duplicates=0;$failed_before_create=0;$validation_drafts=0;
        $rss_candidates=0;$source_candidates=0;$rss_failures=0;$source_failures=0;
        $diagnostics=array();
        $rss_urls=GNF5_Utils::urls_from_lines($cs['rss']);
        $source_urls=GNF5_Utils::urls_from_lines($cs['urls']);
        $seen_candidates=array();
        GNF5_Utils::log('RSS configured: '.count($rss_urls).(!$rss_urls?' — SKIPPED':''),'debug',$cat_id);

        if(!$rss_urls && !$source_urls && empty($cs['gdelt_enabled'])){
            $message=$cat->name.': no automatic discovery method (GDELT, RSS or Source URL) is configured. Save this category first, then run it.';
            GNF5_Utils::log('CATEGORY STOP — '.$message,'warning',$cat_id);
            return array(
                'created'=>0,'published'=>0,'drafts'=>0,'validation_drafts'=>0,'duplicates'=>0,
                'failed_before_create'=>0,'attempted'=>0,'target'=>$run_limit,'message'=>$message,
                'configured_rss'=>0,'configured_sources'=>0,'rss_candidates'=>0,'source_candidates'=>0,
                'rss_failures'=>0,'source_failures'=>0,'diagnostics'=>array($message),'no_candidates'=>1,
            );
        }

            $batch=self::begin_run($cat_id,$run_id,$cs['post_limit']);
            if(is_wp_error($batch))return $batch;
            self::$run_id=$batch['id'];
            $remaining=max(0,$batch['target']-self::run_count($batch['id']));
            $run_limit=min($run_limit,$remaining);
            if(!$run_limit)return array('created'=>0,'published'=>0,'drafts'=>0,'target'=>$batch['target'],'done'=>true,'message'=>$cat->name.': draft target already reached.');

            $base_scan=GNF5_Utils::adaptive_scan_limit($cs['post_limit']);
            $passes=$override_limit===null?array(1,2,3):array($scan_multiplier);

            foreach($passes as $pass_no){
                if($created>=$run_limit)break;
                $scan=min((int)$cs['max_candidates'],max(10,$base_scan*$pass_no));
                GNF5_Utils::log('SCAN PASS '.$pass_no.' — checking up to '.$scan.' candidates per source for '.$cat->name.'.','info',$cat_id);
                $items=array();
                if (!empty($cs['gdelt_enabled'])) {
                    $gdelt=GNF5_Sources::gdelt_items($cat_id);
                    if(is_wp_error($gdelt)){ $diagnostics['GDELT: '.$gdelt->get_error_message()]=true; GNF5_Utils::log('GDELT unavailable; continuing other discovery methods. '.$gdelt->get_error_message(),'warning',$cat_id); }
                    else $items=array_merge($items,$gdelt);
                }

                foreach($rss_urls as $feed_url){
                    if(!GNF6_Queue::configured($cat_id,'rss',$feed_url))continue;
                    GNF5_Utils::touch_lock($cat_id);
                    $rr=GNF5_Sources::rss_items($feed_url,$scan);
                    if(is_wp_error($rr)){
                        GNF6_Queue::schedule_source_retry($cat_id,'rss',$feed_url);
                        $rss_failures++;
                        $diag='RSS FAIL — '.$feed_url.' — '.$rr->get_error_message();
                        $diagnostics[$diag]=true;
                        GNF5_Utils::log($diag,'warning',$cat_id);
                        continue;
                    }
                    $count=count($rr);
                    $rss_candidates=max($rss_candidates,$count);
                    GNF5_Utils::log('RSS OK — '.$feed_url.' — '.$count.' candidate item(s).','info',$cat_id);
                    $items=array_merge($items,$rr);
                }

                foreach($source_urls as $source_url){
                    if(!GNF6_Queue::configured($cat_id,'urls',$source_url))continue;
                    GNF5_Utils::touch_lock($cat_id);
                    $sr=GNF5_Sources::discover_source($source_url,$scan);
                    if(is_wp_error($sr)){
                        GNF6_Queue::schedule_source_retry($cat_id,'urls',$source_url);
                        $source_failures++;
                        $diag='SOURCE FAIL/BLOCKED — '.$source_url.' — '.$sr->get_error_message();
                        $diagnostics[$diag]=true;
                        GNF5_Utils::log($diag,'warning',$cat_id);
                        continue;
                    }
                    $count=count($sr);
                    $source_candidates=max($source_candidates,$count);
                    $methods=array();foreach($sr as $si){if(!empty($si['method']))$methods[$si['method']]=true;}
                    GNF5_Utils::log('SOURCE OK — '.$source_url.' — '.$count.' candidate item(s) via '.implode(', ',array_keys($methods)).'.','info',$cat_id);
                    $items=array_merge($items,$sr);
                }

                foreach(GNF5_Topics::cluster(self::unique_items($items),$cat_id) as $item){
                    if($created>=$run_limit || $attempted>=(int)$cs['max_candidates'])break;
                    $u=GNF5_Utils::normalize_url($item['url']??'');
                    if(!$u)continue;
                    $seen_key=GNF5_Utils::source_identity_url($u)?:$u;
                    if(isset($seen_candidates[$seen_key]))continue;
                    $seen_candidates[$seen_key]=true;
                    $attempted++;

                    $dup=GNF5_Utils::duplicate_post_id($u);
                    if($dup){$duplicates++;GNF5_Utils::log('DUPLICATE — skipped '.$u.' (post #'.$dup.').','info',$cat_id);continue;}
                    if(!GNF5_Utils::acquire_source_lock($u)){
                        $duplicates++;GNF5_Utils::log('SOURCE BUSY — another category/request is already processing this source; skipped for this run.','info',$cat_id);continue;
                    }

                    try{
                        // Recheck after the atomic source claim to close the simultaneous-category duplicate race.
                        $dup=GNF5_Utils::duplicate_post_id($u);
                        if($dup){$duplicates++;GNF5_Utils::log('DUPLICATE — skipped '.$u.' (post #'.$dup.').','info',$cat_id);continue;}
                        $item['url']=$u;
                        $result=self::process_article($item,$cat_id,$cs,$trigger);
                    }finally{
                        GNF5_Utils::release_source_lock($u);
                    }
                    if(is_wp_error($result)){
                        $failed_before_create++;
                        $diagnostics[$result->get_error_message()]=true;
                        GNF5_Utils::log('SKIPPED BEFORE POST CREATE — '.$u.' — '.$result->get_error_message(),'warning',$cat_id);
                        continue;
                    }

                    if(!empty($result['post_id'])){
                        // Count immediately once WordPress has a post record.
                        $created++;
                        if(!empty($result['validated']) && $result['status']==='publish')$published++;
                        else{$drafts++;if(empty($result['validated']))$validation_drafts++;}
                    }
                }
            }

            if($created<$run_limit){
                GNF5_Utils::log('TARGET NOT FULLY REACHED — '.$cat->name.' requested '.$run_limit.' but created '.$created.'. No more usable new candidates were available.','warning',$cat_id);
            }

            $no_candidates=($attempted===0);
            $reason='';
            if($no_candidates){
                if($rss_urls && $rss_failures>0 && !$source_urls){
                    $reason=' RSS did not return any usable article candidates. Use Test RSS + Sources to see the exact feed error.';
                }elseif($rss_urls && $rss_candidates===0){
                    $reason=' Saved RSS feed(s) returned zero usable article candidates.';
                }elseif($source_urls && $source_candidates===0){
                    $reason=' Saved Source URL(s) returned zero usable article candidates.';
                }else{
                    $reason=' No usable article candidate reached processing.';
                }
            }

            $message=$cat->name.': target '.$run_limit.' | created '.$created.' | published '.$published.' | draft/pending '.$drafts.' | duplicates '.$duplicates.' | failed before create '.$failed_before_create.'.'.$reason;
            $log_type=$created>0?'success':'warning';
            GNF5_Utils::log('CATEGORY END — '.$message,$log_type,$cat_id);
            return array(
                'created'=>$created,'published'=>$published,'drafts'=>$drafts,'validation_drafts'=>$validation_drafts,
                'duplicates'=>$duplicates,'failed_before_create'=>$failed_before_create,'attempted'=>$attempted,'target'=>$run_limit,'message'=>$message,
                'configured_rss'=>count($rss_urls),'configured_sources'=>count($source_urls),
                'rss_candidates'=>$rss_candidates,'source_candidates'=>$source_candidates,
                'rss_failures'=>$rss_failures,'source_failures'=>$source_failures,
                'diagnostics'=>array_keys($diagnostics),'no_candidates'=>$no_candidates?1:0,
            );
        } catch (Throwable $e) {
            GNF5_Utils::log('Category processing interrupted: '.GNF5_Utils::redact($e->getMessage()),'failure',$cat_id);
            return new WP_Error('processing_failed','Processing interrupted. Saved Drafts and checkpoints are retained; see the plugin log.');
        } finally {
            self::finish_run($cat_id,self::$run_id,$created,$attempted);
            self::$run_id='';
            if(!$worker_token)GNF5_Utils::release_lock($cat_id);
        }
    }

    public static function run_manual_url($url,$cat_id) {
        $cat_id=absint($cat_id);
        $cat=get_category($cat_id);
        if(!$cat || is_wp_error($cat))return new WP_Error('category','Select a valid WordPress category.');
        $url=GNF5_Utils::normalize_url($url);
        if(!$url)return new WP_Error('url','Enter a valid public article URL.');
        if(GNF5_Utils::duplicate_post_id($url))return new WP_Error('duplicate','This source URL has already been processed.');
        if(!GNF5_Utils::acquire_lock($cat_id))return new WP_Error('locked','This category is already importing.');
        if(!GNF5_Utils::acquire_source_lock($url)){GNF5_Utils::release_lock($cat_id);return new WP_Error('source_busy','This source article is already being processed by another request.');}
        if(function_exists('set_time_limit'))@set_time_limit(300);
        try{
            $dup=GNF5_Utils::duplicate_post_id($url);
            if($dup)return new WP_Error('duplicate','This source URL has already been processed as post #'.$dup.'.');
            $cs=GNF5_Utils::category_settings($cat_id);
            return self::process_article(array('url'=>$url,'title'=>'','fallback_text'=>'','method'=>'Manual URL'),$cat_id,$cs,'manual');
        }catch(Throwable $e){
            GNF5_Utils::log('Manual research interrupted: '.GNF5_Utils::redact($e->getMessage()),'failure',$cat_id);
            return new WP_Error('processing_failed','Research interrupted. Existing Drafts and checkpoints are retained; see the plugin log.');
        }finally{GNF5_Utils::release_source_lock($url);GNF5_Utils::release_lock($cat_id);}
    }

    private static function process_article($item,$cat_id,$cs,$trigger) {
        $author=GNF5_Utils::valid_author_id($cs['author_id']??0);
        if(!$author)return new WP_Error('author',get_cat_name($cat_id).': an author must be selected.');
        $url=GNF5_Utils::normalize_url($item['url']??'');
                $job_key='gnf5_research_job_'.md5($cat_id.'|'.$url.'|'.wp_json_encode($cs));
        $research=get_transient($job_key);
        if(!is_array($research) || empty($research['facts']))$research=GNF5_Research::gather($item,$cat_id);
        if(!is_wp_error($research))set_transient($job_key,$research,6*HOUR_IN_SECONDS);
        if(is_wp_error($research))return $research;
        if(GNF5_Topics::duplicate($research))return new WP_Error('topic_duplicate','This event is already covered by a queued or saved article.');
        $opportunity=GNF5_Topics::opportunity($item,$research,$cat_id);
        if(($item['method']??'')==='GDELT' && ($research['independent_source_estimate']<(int)$cs['min_sources'] || $opportunity['score']<(int)$cs['opportunity_threshold'])){
            GNF5_Topics::remember($research,$cat_id,'failed',0,'Below GDELT source/opportunity threshold.');
            return new WP_Error('topic_low_opportunity','GDELT topic below configured source/opportunity threshold; no Draft created.');
        }
        $claim=GNF5_Topics::claim($research,$cat_id);if(is_wp_error($claim))return $claim;
        $article=GNF5_Writer::create_article($research,$cat_id);
        if(is_wp_error($article)){GNF5_Topics::remember($research,$cat_id,'failed',0,$article->get_error_message());return $article;}
        if(!GNF5_SEO::title_is_unique($article['title'],0,$article['focus_keyword'])){
            GNF5_Topics::remember($research,$cat_id,'failed',0,'Duplicate/similar existing title.');
            return new WP_Error('duplicate_title','Generated title duplicates an existing article; no duplicate Draft created.');
        }
        if(self::$run_id!==''){
            $run=get_option('gnf5_run_cat_'.$cat_id,array());
            if(($run['id']??'')!==self::$run_id || self::run_count(self::$run_id)>=(int)$run['target'])return new WP_Error('run_limit','Draft limit reached.');
            $run['reservation']=array('url'=>$url,'time'=>time());update_option('gnf5_run_cat_'.$cat_id,$run,false);
        }
        // Save a usable, checked body atomically with recovery metadata; never create an empty placeholder.
        $post_id=GNF5_Publish::save(array('post_type'=>'post','post_author'=>$author,'post_title'=>$article['title'],
            'post_name'=>$article['slug'],'post_excerpt'=>$article['excerpt'],'post_content'=>GNF5_SEO::build_gutenberg_content($article['content_html']),
            'post_category'=>array($cat_id),'tags_input'=>$article['tags'],
            'meta_input'=>array('_gnf5_generated_by'=>'fresh-v5','_gnf5_source_url'=>$url,'_gnf5_source_identity'=>GNF5_Utils::source_identity_url($url),
                '_gnf5_source_method'=>sanitize_text_field($item['method']??$trigger),'_gnf5_article_data'=>$article,'_gnf5_run_id'=>self::$run_id,
                '_gnf5_state'=>'draft_created','_gnf5_state_updated'=>time(),'_gnf5_topic_fingerprint'=>GNF5_Topics::fingerprint($research),
                '_gnf5_opportunity'=>$opportunity,'_gnf5_schema_recommendation'=>'NewsArticle')),true);
        if(is_wp_error($post_id)){GNF5_Topics::remember($research,$cat_id,'failed',0,$post_id->get_error_message());return $post_id;}
        delete_transient($job_key);
        GNF5_Research::store($post_id,$research);GNF5_Topics::remember($research,$cat_id,'draft',$post_id);
        GNF5_SEO::save_rank_math($post_id,$article);GNF5_Publish::checkpoint($post_id);
        GNF5_Utils::set_state($post_id,'draft_created','Original article saved for human review; finalizing optional images and SEO.');
        GNF5_Quality::store($post_id,$article['quality']);
        return self::finalize_post($post_id,$article,$research,$cat_id,$cs,false);
    }

    private static function compose_content($post_id,$article,$image_ids,$cat_id,$cs,$source_url) {
        $html=GNF5_SEO::insert_internal_links(GNF5_SEO::public_article_html($article['content_html']),$post_id,$cat_id);
        $html=GNF5_SEO::insert_external_links($html,$post_id,$cat_id);
        $content=GNF5_SEO::build_gutenberg_content($html);
        if(GNF5_Utils::images_enabled($cat_id))$content=GNF5_Images::insert_two_blocks($content,$image_ids,$article['image_alts'],$cat_id);
        // No automatic source/URL dump. Research is available in the private report.
        return $content.GNF5_Images::manual_image_markup($post_id);
    }

    private static function edit_token($post_id) {
        $data=array();foreach(array('post_title','post_name','post_content','post_excerpt','post_status','post_author') as $field)$data[$field]=get_post_field($field,$post_id);
        foreach(array('_thumbnail_id','rank_math_title','rank_math_description','rank_math_focus_keyword') as $key)$data[$key]=get_post_meta($post_id,$key,true);
        $data['categories']=wp_get_post_categories($post_id);$data['tags']=wp_get_post_tags($post_id,array('fields'=>'ids'));
        return hash('sha256',serialize($data));
    }

    private static function finalize_post($post_id,$article,$research,$cat_id,$cs,$is_recovery=false) {
        if(!GNF5_Publish::can_rewrite($post_id))return new WP_Error('manual_edit','Human edits detected; background recovery will not overwrite this Draft.');
        $token=self::edit_token($post_id);$image_error='';
        try {
            GNF5_Utils::set_state($post_id,'processing','Finalizing optional images and SEO; publication remains manual.');
            $image_ids=(array)get_post_meta($post_id,'_gnf5_image_ids',true);
            if(GNF5_Utils::images_enabled($cat_id)){
                $images=GNF5_Images::generate_for_post($article,$post_id,$cat_id);
                if(is_wp_error($images)){$image_error=$images->get_error_message();$image_ids=(array)get_post_meta($post_id,'_gnf5_image_ids',true);}
                else $image_ids=$images;
            }
            if(!hash_equals($token,self::edit_token($post_id)))return new WP_Error('manual_edit','Article changed while processing; generated text did not overwrite human edits.');
            $content=self::compose_content($post_id,$article,$image_ids,$cat_id,$cs,get_post_meta($post_id,'_gnf5_source_url',true));
            if(!hash_equals($token,self::edit_token($post_id)))return new WP_Error('manual_edit','Article changed during link validation. Your changes were preserved.');
            $updated=self::update_post_checked(array('ID'=>$post_id,'post_content'=>$content),'Draft finalization');
            if(is_wp_error($updated))throw new RuntimeException($updated->get_error_message());
            if(GNF5_Utils::images_enabled($cat_id) && !empty(GNF5_Utils::category_settings($cat_id)['image_featured']) && !empty($image_ids[0]) && !get_post_thumbnail_id($post_id))set_post_thumbnail($post_id,$image_ids[0]);
            GNF5_SEO::save_rank_math($post_id,$article);
            update_post_meta($post_id,'_gnf5_final_word_count',GNF5_Utils::word_count($article['content_html']));
            GNF5_Publish::checkpoint($post_id);
            self::optimize_text($post_id);
            if($image_error){
                GNF5_Utils::set_state($post_id,'image_pending','Draft body is ready. Optional image processing: '.$image_error);
                update_post_meta($post_id,'_gnf5_validation_errors',array($image_error));
            }else{
                GNF5_Utils::clear_recovery_state($post_id);
                GNF5_Publish::wait_for_score($post_id,$is_recovery);
            }
            // Scoring may have committed an independently checked SEO improvement.
            // Always report the latest saved article, not the pre-optimization body.
            $latest=get_post_meta($post_id,'_gnf5_article_data',true);
            if(is_array($latest) && !empty($latest['quality']))$article=$latest;
            $report=$article['quality']??array('warnings'=>array('Legacy article: quality checks NOT CHECKED.'));
            if($image_error)$report['warnings'][]=$image_error;
            $report['diagnostics']=GNF5_SEO::validate($post_id,$article,$image_ids);
            GNF5_Quality::store($post_id,$report);
            GNF5_Utils::log('DRAFT #'.$post_id.' saved for human review. Images '.(GNF5_Utils::images_enabled($cat_id)?'ON':'OFF').'; Rank Math '.(GNF5_Publish::score($post_id)??'N/A').'.','draft',$cat_id);
            return array('post_id'=>$post_id,'status'=>get_post_status($post_id),'validated'=>$image_error==='','errors'=>$image_error?array($image_error):array(),'warnings'=>$report['warnings']??array());
        }catch(Throwable $e){
            GNF5_Utils::set_state($post_id,'post_processing_failed','Draft saved; finalization failed: '.GNF5_Utils::redact($e->getMessage()));
            return array('post_id'=>$post_id,'status'=>get_post_status($post_id),'validated'=>false,'errors'=>array(GNF5_Utils::redact($e->getMessage())));
        }
    }

    /** Works without Node/remote scoring; shares the three-attempt limit with real-score repair. */
    public static function optimize_text($post_id) {
        if(empty(GNF5_Utils::settings()['rankmath_enabled']))return;
        for($i=0;$i<3;$i++)if(!self::repair_scored_post($post_id,true))break;
    }

    public static function improve_seo($post_id) {
        if(!GNF5_Publish::can_rewrite($post_id))return new WP_Error('manual_edit','Human edits detected. Your text was preserved; use the SEO checklist to make changes in the editor.');
        $article=get_post_meta($post_id,'_gnf5_article_data',true);
        if(!is_array($article) || empty($article['content_html']))return new WP_Error('article_missing','No generated article checkpoint exists.');
        $cats=wp_get_post_categories($post_id);$cat_id=(int)($cats[0]??0);$token=self::edit_token($post_id);
        $content=self::compose_content($post_id,$article,(array)get_post_meta($post_id,'_gnf5_image_ids',true),$cat_id,GNF5_Utils::category_settings($cat_id),'');
        if(!hash_equals($token,self::edit_token($post_id)))return new WP_Error('manual_edit','Article changed during link checks. Changes were not overwritten.');
        $saved=self::update_post_checked(array('ID'=>$post_id,'post_content'=>$content),'SEO link improvement');
        if(is_wp_error($saved))return $saved;
        GNF5_Publish::checkpoint($post_id);self::optimize_text($post_id);
        GNF5_Publish::wait_for_score($post_id,false);
        $latest=get_post_meta($post_id,'_gnf5_article_data',true);GNF5_Quality::store($post_id,$latest['quality']??array());
        return true;
    }

    /** One bounded, independently quality-checked improvement of an unchanged generated article. */
    public static function repair_scored_post($post_id,$local=false) {
        if(!GNF5_Publish::can_rewrite($post_id))return false;
        if((int)get_post_meta($post_id,'_gnf5_seo_repair_attempts',true)>=3){update_post_meta($post_id,'_gnf5_seo_repair_note','Three SEO optimization attempts used. Review remaining items manually; no further AI rewrite was requested.');return false;}
        $research=GNF5_Research::load($post_id);$article=get_post_meta($post_id,'_gnf5_article_data',true);
        if(is_wp_error($research) || !is_array($article) || empty(GNF5_Utils::settings()['gemini_api_key']))return false;
        $repair_key=hash('sha256',serialize($article));
        if(get_post_meta($post_id,'_gnf5_seo_repair_stopped',true)===$repair_key)return false;
        $token=self::edit_token($post_id);$cats=wp_get_post_categories($post_id);$cat_id=(int)($cats[0]??0);
        $errors=GNF5_SEO::text_feedback($article);
        if(!$local)foreach((array)get_post_meta($post_id,'_gnf5_seo_tests',true) as $name=>$test){
            // Images, paid Content AI and link placement are not tasks for the text writer.
            if(in_array($name,array('hasContentAI','keywordInImageAlt','contentHasAssets','linksHasInternal','linksHasExternals','linksNotAllExternals','linksHasDofollow'),true))continue;
            if(is_array($test) && ($test['score']??0)<($test['maximum']??0))$errors[]=wp_strip_all_tags($test['message']??$name);
        }
        if(!$errors)return false;
        $attempt=(int)get_post_meta($post_id,'_gnf5_seo_repair_attempts',true)+1;update_post_meta($post_id,'_gnf5_seo_repair_attempts',$attempt);
        update_post_meta($post_id,'_gnf5_seo_repair_note','Checking non-image SEO, attempt '.$attempt.' of 3.');
        $repaired=GNF5_Writer::repair_for_validation($article,$research,$errors,$cat_id);
        if(is_wp_error($repaired)){update_post_meta($post_id,'_gnf5_seo_repair_note','SEO improvement unavailable: '.GNF5_Utils::redact($repaired->get_error_message()));return false;}
        $same=true;foreach(array('title','seo_title','focus_keyword','slug','meta_description','excerpt','content_html') as $key)if(($article[$key]??'')!==($repaired[$key]??''))$same=false;
        if($same || ($local && count(GNF5_SEO::text_feedback($repaired))>=count($errors))){
            update_post_meta($post_id,'_gnf5_seo_repair_stopped',$repair_key);
            update_post_meta($post_id,'_gnf5_seo_repair_note','No measurable text-check improvement was returned. Original text retained; review the checklist.');return false;
        }
        $report=GNF5_Quality::evaluate($repaired,$research,true);
        if(($report['facts']['status']??'')!=='PASS' || in_array($report['originality']['status']??'UNKNOWN',array('FAIL','UNKNOWN'),true)){
            update_post_meta($post_id,'_gnf5_seo_repair_stopped',$repair_key);
            update_post_meta($post_id,'_gnf5_seo_repair_note','Suggested SEO changes failed factual/originality review. Original Draft retained.');
            GNF5_Utils::log('SEO optimization '.$attempt.' rejected: quality evidence insufficient. Original Draft retained.','seo_warning',$cat_id);return false;
        }
        if(!hash_equals($token,self::edit_token($post_id)) || !GNF5_Publish::can_rewrite($post_id))return false;
        $ids=(array)get_post_meta($post_id,'_gnf5_image_ids',true);
        $content=self::compose_content($post_id,$repaired,$ids,$cat_id,GNF5_Utils::category_settings($cat_id),'');
        if(!hash_equals($token,self::edit_token($post_id)) || !GNF5_Publish::can_rewrite($post_id))return false;
        $saved=self::update_post_checked(array('ID'=>$post_id,'post_title'=>$repaired['title'],'post_name'=>$repaired['slug'],'post_excerpt'=>$repaired['excerpt'],
            'post_content'=>$content,'tags_input'=>$repaired['tags']),'SEO optimization');
        if(is_wp_error($saved))return false;
        $repaired['quality']=$report;update_post_meta($post_id,'_gnf5_article_data',$repaired);
        update_post_meta($post_id,'_gnf5_final_word_count',GNF5_Utils::word_count($repaired['content_html']));
        GNF5_SEO::save_rank_math($post_id,$repaired);GNF5_Publish::checkpoint($post_id);GNF5_Quality::store($post_id,$report);
        update_post_meta($post_id,'_gnf5_seo_repair_note','Applied quality-checked SEO improvement '.$attempt.' of 3. Remaining items need review.');
        return true;
    }

    private static function bulk_queue_load() {
        if(!defined('GNF5_BULK_RECOVERY_OPTION'))return array();
        $q=get_option(GNF5_BULK_RECOVERY_OPTION,array());
        return is_array($q)?$q:array();
    }

    private static function bulk_queue_save($q) {
        if(!defined('GNF5_BULK_RECOVERY_OPTION'))return false;
        update_option(GNF5_BULK_RECOVERY_OPTION,is_array($q)?$q:array(),false);
        return true;
    }

    private static function acquire_bulk_lock() {
        if(!defined('GNF5_BULK_RECOVERY_LOCK'))return false;
        $token=wp_generate_uuid4();
        $state=array('time'=>time(),'token'=>$token);
        if(!add_option(GNF5_BULK_RECOVERY_LOCK,$state,'','no')){
            $old=get_option(GNF5_BULK_RECOVERY_LOCK,array());
            $fresh=is_array($old)&&!empty($old['time'])&&(time()-absint($old['time'])<30*MINUTE_IN_SECONDS);
            if($fresh)return false;
            GNF5_Utils::delete_lock_value(GNF5_BULK_RECOVERY_LOCK,$old);
            if(!add_option(GNF5_BULK_RECOVERY_LOCK,$state,'','no'))return false;
        }
        self::$bulk_lock_token=$token;
        return true;
    }

    private static function release_bulk_lock() {
        if(!defined('GNF5_BULK_RECOVERY_LOCK'))return;
        $old=get_option(GNF5_BULK_RECOVERY_LOCK,array());
        if(self::$bulk_lock_token!==''&&is_array($old)&&hash_equals((string)($old['token']??''),self::$bulk_lock_token)){
            GNF5_Utils::delete_lock_value(GNF5_BULK_RECOVERY_LOCK,$old);
        }
        self::$bulk_lock_token='';
    }

    private static function schedule_bulk_worker($delay=20) {
        if(!defined('GNF5_BULK_RECOVERY_HOOK'))return;
        $status=self::bulk_recovery_status();
        if(empty($status['pending']))return;
        if(!wp_next_scheduled(GNF5_BULK_RECOVERY_HOOK)){
            wp_schedule_single_event(time()+max(5,absint($delay)),GNF5_BULK_RECOVERY_HOOK);
        }
    }

    public static function enqueue_bulk_recovery($post_ids) {
        $post_ids=array_values(array_unique(array_filter(array_map('absint',(array)$post_ids))));
        if(!$post_ids)return new WP_Error('bulk_empty','Select at least one failed draft.');
        if(count($post_ids)>200)$post_ids=array_slice($post_ids,0,200);

        if(!self::acquire_bulk_lock())return new WP_Error('locked','Recovery queue is busy; selected drafts were not lost. Retry shortly.');
        try {
        $q=self::bulk_queue_load();
        $added=0;$already=0;$invalid=0;
        foreach($post_ids as $post_id){
            if(!$post_id||get_post_type($post_id)!=='post'||get_post_meta($post_id,'_gnf5_generated_by',true)!=='fresh-v5'){
                $invalid++;continue;
            }
            $state=(string)get_post_meta($post_id,'_gnf5_state',true);
            if(!in_array($state,array('draft_created','processing','image_pending','validation_failed','post_processing_failed'),true)){
                $invalid++;continue;
            }
            if(isset($q[$post_id])){$already++;continue;}
            if(count($q)>=200){$invalid++;continue;}
            $q[$post_id]=array(
                'post_id'=>$post_id,'state'=>'queued','added'=>time(),'updated'=>time(),
                'message'=>'Waiting for one-by-one recovery.',
            );
            $added++;
        }
        self::bulk_queue_save($q);
        if($added>0)self::schedule_bulk_worker(30);

        $status=self::bulk_recovery_status();
        $status['added']=$added;$status['already_queued']=$already;$status['invalid']=$invalid;
        return $status;
        } finally { self::release_bulk_lock(); }
    }

    public static function bulk_recovery_status() {
        $q=self::bulk_queue_load();
        $clean=array();$ids=array();$processing=0;$now=time();

        foreach($q as $key=>$item){
            $post_id=absint($item['post_id']??$key);
            if(!$post_id||get_post_type($post_id)!=='post'||get_post_meta($post_id,'_gnf5_generated_by',true)!=='fresh-v5'){
                continue;
            }
            $state=(string)($item['state']??'queued');
            $updated=absint($item['updated']??0);
            if($state==='processing'&&(!$updated||($now-$updated)>30*MINUTE_IN_SECONDS)){
                $state='queued';
                $item['state']='queued';
                $item['updated']=$now;
                $item['message']='Previous bulk worker timed out; safely re-queued.';
            }
            if($state==='processing')$processing++;
            $clean[$post_id]=$item;
            $ids[]=$post_id;
        }

        if($clean!==$q){
            if(self::$bulk_lock_token!=='')self::bulk_queue_save($clean);
            elseif(self::acquire_bulk_lock()){try{if(self::bulk_queue_load()===$q)self::bulk_queue_save($clean);}finally{self::release_bulk_lock();}}
        }
        return array(
            'pending'=>count($clean),
            'processing'=>$processing,
            'queued'=>max(0,count($clean)-$processing),
            'post_ids'=>$ids,
        );
    }

    /**
     * Process exactly ONE selected draft. Never run selected drafts in parallel.
     */
    public static function bulk_recovery_step() {
        if(!self::acquire_bulk_lock()){
            $status=self::bulk_recovery_status();
            $status['busy']=1;
            $status['message']='Bulk recovery worker is already processing one article.';
            return $status;
        }

        try{
            $q=self::bulk_queue_load();
            if(!$q){
                return array('pending'=>0,'queued'=>0,'processing'=>0,'post_ids'=>array(),'message'=>'Bulk recovery queue is empty.');
            }

            $post_id=0;$item=array();
            foreach($q as $id=>$candidate){
                $state=(string)($candidate['state']??'queued');
                $updated=absint($candidate['updated']??0);
                if($state==='processing'){
                    if(!$updated||(time()-$updated)>30*MINUTE_IN_SECONDS){
                        $candidate['state']='queued';$candidate['updated']=time();$q[$id]=$candidate;
                        $state='queued';
                    }else{
                        continue;
                    }
                }
                if($state==='queued'){
                    $post_id=absint($candidate['post_id']??$id);
                    $item=$candidate;
                    break;
                }
            }

            if(!$post_id){
                self::bulk_queue_save($q);
                $status=self::bulk_recovery_status();
                $status['message']='No queued draft is ready right now.';
                return $status;
            }

            $q[$post_id]['state']='processing';
            $q[$post_id]['updated']=time();
            $q[$post_id]['message']='Repairing this draft now. Other selected drafts are waiting.';
            self::bulk_queue_save($q);

            GNF5_Utils::log('BULK RETRY — selected draft #'.$post_id.' is being processed one-by-one.','info',0);
            $result=self::retry_post($post_id);

            $q=self::bulk_queue_load();

            if(is_wp_error($result)&&$result->get_error_code()==='locked'){
                $deferred=$q[$post_id]??$item;
                unset($q[$post_id]);
                $deferred['state']='queued';
                $deferred['updated']=time();
                $deferred['message']='Category is busy; queued again automatically.';
                $q[$post_id]=$deferred; // move to back
                self::bulk_queue_save($q);
                self::schedule_bulk_worker(60);

                $status=self::bulk_recovery_status();
                $status['processed_post']=$post_id;
                $status['deferred']=1;
                $status['message']='Draft #'.$post_id.' is waiting because its category is busy. It stays in the queue.';
                return $status;
            }

            // One selected draft gets one full retry attempt, then the queue moves on.
            // This prevents one stubborn SEO draft from blocking all other selections.
            unset($q[$post_id]);
            self::bulk_queue_save($q);

            $validated=!is_wp_error($result)&&!empty($result['validated']);
            $status=self::bulk_recovery_status();
            $status['processed_post']=$post_id;
            $status['validated']=$validated?1:0;
            $status['result']=is_wp_error($result)?array():$result;

            if(is_wp_error($result)){
                $status['message']='Draft #'.$post_id.' retry failed: '.$result->get_error_message();
                $status['error']=1;
            }elseif($validated){
                $status['message']='Draft #'.$post_id.' recovered successfully. Moving to the next selected article.';
            }else{
                $errs=(array)($result['errors']??array());
                $status['message']='Draft #'.$post_id.' was retried but still needs correction'.($errs?': '.sanitize_text_field($errs[0]):'.').' Moving to the next selected article.';
                $status['error']=1;
            }

            if(!empty($status['pending']))self::schedule_bulk_worker(30);
            return $status;
        }finally{
            self::release_bulk_lock();
        }
    }

    public static function bulk_recovery_cron() {
        $status=self::bulk_recovery_step();
        if(is_array($status)&&!empty($status['pending'])){
            self::schedule_bulk_worker(!empty($status['deferred'])?60:30);
        }
    }

    public static function retry_post($post_id) {
        $post_id=absint($post_id);
        if(get_post_type($post_id)!=='post' || get_post_meta($post_id,'_gnf5_generated_by',true)!=='fresh-v5')return new WP_Error('post','Plugin Draft not found.');
        if(!GNF5_Publish::can_rewrite($post_id))return new WP_Error('manual_edit','Draft was manually edited or has no safe ownership checkpoint. Use explicit regeneration only after reviewing your edits.');
        $cats=wp_get_post_categories($post_id);$cat_id=(int)($cats[0]??0);
        if(!GNF5_Utils::acquire_lock($cat_id))return new WP_Error('locked','Another article worker is active.');
        try{
            $article=get_post_meta($post_id,'_gnf5_article_data',true);$research=GNF5_Research::load($post_id);
            if(!is_array($article) || is_wp_error($research))return new WP_Error('research_missing','Research recovery checkpoint missing; explicit regeneration is required.');
            return self::finalize_post($post_id,$article,$research,$cat_id,GNF5_Utils::category_settings($cat_id),true);
        }finally{GNF5_Utils::release_lock($cat_id);}
    }

    private static function begin_run($cat_id,$requested,$target) {
        $key='gnf5_run_cat_'.$cat_id;$old=get_option($key,array());
        $requested=preg_replace('/[^a-zA-Z0-9_-]/','',substr((string)$requested,0,80));
        if(!$requested && GNF5_Utils::cron_chain_active($cat_id)){$chain=get_transient(GNF5_Utils::cron_chain_key($cat_id));$requested='cron-'.($chain['id']??$chain['started']);}
        if(!$requested)$requested=wp_generate_uuid4();
        if(($old['id']??'')===$requested)return $old;
        if(in_array(($old['status']??''),array('running','waiting'),true) && ($old['updated']??0)>time()-1800)return new WP_Error('locked','This category already has an active batch.');
        $run=array('id'=>$requested,'target'=>min(10,max(1,(int)$target)),'started'=>time(),'updated'=>time(),'status'=>'running','created'=>0,'attempted'=>0);
        update_option($key,$run,false);return $run;
    }

    public static function run_count($run_id) {
        if(!$run_id)return 0;
        $q=new WP_Query(array('post_type'=>'post','post_status'=>array('draft','pending','publish','future','private','trash'),'fields'=>'ids','posts_per_page'=>11,
            'no_found_rows'=>true,'meta_key'=>'_gnf5_run_id','meta_value'=>$run_id));
        return count($q->posts);
    }

    private static function finish_run($cat_id,$id,$created,$attempted) {
        if(!$id)return;$key='gnf5_run_cat_'.$cat_id;$run=get_option($key,array());if(($run['id']??'')!==$id)return;
        $run['created']=self::run_count($id);$run['attempted']=(int)($run['attempted']??0)+(int)$attempted;
        $run['updated']=time();$run['status']=$run['created']>=$run['target']?'complete':($created===0?'exhausted':'waiting');
        unset($run['reservation']);update_option($key,$run,false);
    }

    public static function skip_failed($post_id) {
        $post_id=absint($post_id);
        if(!$post_id || get_post_type($post_id)!=='post')return new WP_Error('post','Post not found.');
        if(get_post_meta($post_id,'_gnf5_generated_by',true)!=='fresh-v5')return new WP_Error('post','This is not a GlobiqNews Fresh V5 post.');
        GNF5_Utils::set_state($post_id,'skipped','User skipped automatic recovery.');
        GNF5_Utils::clear_recovery_state($post_id);
        update_post_meta($post_id,'_gnf5_validation_warnings',array('Automatic recovery skipped by administrator.'));
        return true;
    }

    private static function unique_items($items) {
        $seen=array();$out=array();
        foreach((array)$items as $item){
            $u=GNF5_Utils::normalize_url($item['url']??'');
            if(!$u)continue;
            $key=GNF5_Utils::source_identity_url($u);
            if(!$key)$key=$u;
            if(isset($seen[$key]))continue;
            $seen[$key]=true;$item['url']=$u;$out[]=$item;
        }
        return $out;
    }
    public static function regenerate($post_id) {
        $cats=wp_get_post_categories($post_id);$cat_id=(int)($cats[0]??0);$cs=GNF5_Utils::category_settings($cat_id);
        if(!GNF5_Utils::valid_author_id($cs['author_id']))return new WP_Error('author','Select a category author before regeneration.');
        $token=self::edit_token($post_id);$url=get_post_meta($post_id,'_gnf5_source_url',true);
        $old=get_post_meta($post_id,'_gnf5_research',true);$support=array();foreach((array)($old['sources']??array()) as $source)$support[]=array('url'=>$source['url'],'method'=>'Saved research reference');
        $research=GNF5_Research::gather(array('url'=>$url,'supporting_items'=>$support,'method'=>'Explicit regeneration'),$cat_id);
        if(is_wp_error($research))return $research;
        $article=GNF5_Writer::create_article($research,$cat_id);if(is_wp_error($article))return $article;
        if(!hash_equals($token,self::edit_token($post_id)))return new WP_Error('manual_edit','Article changed during regeneration; replacement cancelled.');
        $manual=GNF5_Images::manual_image_markup($post_id);
        $result=self::update_post_checked(array('ID'=>$post_id,'post_title'=>$article['title'],'post_name'=>$article['slug'],'post_excerpt'=>$article['excerpt'],
            'post_content'=>GNF5_SEO::build_gutenberg_content($article['content_html']).$manual,'tags_input'=>$article['tags']),'Explicit regeneration');
        if(is_wp_error($result))return $result;
        update_post_meta($post_id,'_gnf5_article_data',$article);delete_post_meta($post_id,'_gnf5_seo_repair_attempts');
        delete_post_meta($post_id,'_gnf5_seo_repair_stopped');delete_post_meta($post_id,'_gnf5_seo_repair_note');
        GNF5_Research::store($post_id,$research);GNF5_Topics::remember($research,$cat_id,'draft',$post_id);
        GNF5_SEO::save_rank_math($post_id,$article);GNF5_Publish::checkpoint($post_id);
        return self::finalize_post($post_id,$article,$research,$cat_id,$cs,false);
    }

    public static function image_action($post_id,$action) {
        $cats=wp_get_post_categories($post_id);$cat_id=(int)($cats[0]??0);$owned=GNF5_Publish::can_rewrite($post_id);
        if($action!=='remove_images' && !GNF5_Utils::images_enabled($cat_id))return new WP_Error('images_off','Image generation is OFF.');
        if(in_array($action,array('remove_images','regenerate_images'),true)){$result=GNF5_Images::remove_generated($post_id);if(is_wp_error($result))return $result;}
        if($action==='remove_images'){if($owned)GNF5_Publish::checkpoint($post_id);return true;}
        $token=self::edit_token($post_id);$article=get_post_meta($post_id,'_gnf5_article_data',true);
        if(!is_array($article))return new WP_Error('article_data','Image prompt data missing.');
        $ids=GNF5_Images::generate_for_post($article,$post_id,$cat_id,true);if(is_wp_error($ids))return $ids;
        if(!hash_equals($token,self::edit_token($post_id)))return new WP_Error('manual_edit','Article changed during image generation; image insertion cancelled.');
        $content=get_post_field('post_content',$post_id);
        if(!GNF5_Images::has_manual_inline($post_id))$content=GNF5_Images::insert_two_blocks($content,$ids,$article['image_alts'],$cat_id);
        $result=self::update_post_checked(array('ID'=>$post_id,'post_content'=>$content),'Explicit image insertion');if(is_wp_error($result))return $result;
        if(!empty(GNF5_Utils::category_settings($cat_id)['image_featured']) && !get_post_thumbnail_id($post_id) && !empty($ids[0]))set_post_thumbnail($post_id,$ids[0]);
        if($owned)GNF5_Publish::checkpoint($post_id);
        return true;
    }
}
