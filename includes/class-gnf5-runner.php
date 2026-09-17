<?php
if (!defined('ABSPATH')) { exit; }

class GNF5_Runner {
    private static $bulk_lock_token='';
    private static function update_post_checked($args,$context='post update') {
        $result=wp_update_post($args,true);
        if(is_wp_error($result))return new WP_Error('post_update',$context.' failed: '.$result->get_error_message());
        if(!$result)return new WP_Error('post_update',$context.' failed: WordPress returned no post ID.');
        return absint($result);
    }

    public static function cron_run_category($cat_id) {
        $cat_id=absint($cat_id);
        $s=GNF5_Utils::settings();
        $cs=GNF5_Utils::category_settings($cat_id,$s);
        if(empty($cs['enabled']))return;

        // Only one automatic target chain may exist for a category at a time.
        // This prevents the recurring schedule from starting a second chain while
        // continuation events from the previous target are still working.
        $target=max(1,absint($cs['post_limit']));
        if(!GNF5_Utils::start_cron_chain($cat_id,$target)){
            GNF5_Utils::log('CRON START SKIPPED â€” previous automatic target chain is still active for this category.','info',$cat_id);
            return;
        }
        self::cron_continue_category($cat_id,$target,1,0);
    }

    public static function cron_continue_category($cat_id,$remaining=1,$pass=1,$zero_streak=0) {
        $cat_id=absint($cat_id);
        $remaining=max(1,absint($remaining));
        $pass=min(4,max(1,absint($pass)));
        $zero_streak=max(0,absint($zero_streak));

        $s=GNF5_Utils::settings();
        $cs=GNF5_Utils::category_settings($cat_id,$s);
        if(empty($cs['enabled'])){GNF5_Utils::end_cron_chain($cat_id);return;}
        if(!GNF5_Utils::cron_chain_active($cat_id)){GNF5_Utils::start_cron_chain($cat_id,$remaining);}
        GNF5_Utils::touch_cron_chain($cat_id,$remaining);

        $result=self::run_category($cat_id,'cron-chunk',1,$pass);
        if(is_wp_error($result)){
            if($result->get_error_code()==='locked' && defined('GNF5_CRON_CONTINUE_HOOK')){
                GNF5_Utils::touch_cron_chain($cat_id,$remaining);
                $ok=wp_schedule_single_event(time()+90,GNF5_CRON_CONTINUE_HOOK,array($cat_id,$remaining,$pass,$zero_streak));
                if($ok){
                    GNF5_Utils::log('CRON CHUNK DEFERRED â€” category lock busy; retrying in about 90 seconds.','warning',$cat_id);
                }else{
                    GNF5_Utils::end_cron_chain($cat_id);
                    GNF5_Utils::log('CRON CHUNK STOPPED â€” could not schedule deferred continuation.','error',$cat_id);
                }
            }else{
                GNF5_Utils::end_cron_chain($cat_id);
                GNF5_Utils::log('CRON CHUNK FAILED â€” '.$result->get_error_message(),'warning',$cat_id);
            }
            return;
        }

        $made=absint($result['created']??0);
        if($made>0){$remaining=max(0,$remaining-$made);$zero_streak=0;}else{$zero_streak++;}

        if($remaining>0 && $zero_streak<5 && defined('GNF5_CRON_CONTINUE_HOOK')){
            $next_pass=min(4,$pass+1);
            GNF5_Utils::touch_cron_chain($cat_id,$remaining);
            $ok=wp_schedule_single_event(time()+75,GNF5_CRON_CONTINUE_HOOK,array($cat_id,$remaining,$next_pass,$zero_streak));
            if($ok){
                GNF5_Utils::log('CRON CHUNK CONTINUE â€” '.$remaining.' target post(s) still remaining; next chunk scheduled.','info',$cat_id);
            }else{
                GNF5_Utils::end_cron_chain($cat_id);
                GNF5_Utils::log('CRON TARGET STOPPED â€” continuation event could not be scheduled.','error',$cat_id);
            }
        }elseif($remaining>0){
            GNF5_Utils::end_cron_chain($cat_id);
            GNF5_Utils::log('CRON TARGET STOPPED â€” no usable new candidate found after repeated chunk attempts; '.$remaining.' target post(s) remain unfilled.','warning',$cat_id);
        }else{
            GNF5_Utils::end_cron_chain($cat_id);
            GNF5_Utils::log('CRON TARGET COMPLETE â€” automatic category target reached.','success',$cat_id);
        }
    }

    public static function auto_recovery_cron() {
        GNF5_Publish::cron_check();
        $s=GNF5_Utils::settings();
        if(empty($s['auto_recovery_enabled']))return;

        $posts=GNF5_Utils::auto_recoverable_posts(2);
        if(!$posts)return;

        GNF5_Utils::log('AUTO RECOVERY START â€” '.count($posts).' failed draft(s) due for retry.','info',0);

        foreach($posts as $post){
            $post_id=absint($post->ID);
            $attempt=GNF5_Utils::recovery_attempts($post_id)+1;
            delete_post_meta($post_id,'_gnf5_recovery_next');

            $result=self::retry_post($post_id);

            // A busy category lock is not a real recovery attempt. Defer without consuming one.
            if(is_wp_error($result) && $result->get_error_code()==='locked'){
                update_post_meta($post_id,'_gnf5_recovery_next',time()+(5*MINUTE_IN_SECONDS));
                GNF5_Utils::log('AUTO RECOVERY DEFERRED â€” post #'.$post_id.' category is busy; retrying in about 5 minutes without consuming an attempt.','info',0);
                continue;
            }

            update_post_meta($post_id,'_gnf5_recovery_attempts',$attempt);

            if(!is_wp_error($result) && !empty($result['validated'])){
                GNF5_Utils::clear_recovery_state($post_id);
                GNF5_Utils::log('AUTO RECOVERY SUCCESS â€” post #'.$post_id.' recovered on attempt '.$attempt.'.','success',0);
                continue;
            }

            $message=is_wp_error($result)?$result->get_error_message():implode(' | ',array_slice((array)($result['errors']??array()),0,3));
            update_post_meta($post_id,'_gnf5_recovery_last_error',sanitize_text_field($message));

            $max=GNF5_Utils::recovery_max_attempts();
            if($attempt >= $max){
                update_post_meta($post_id,'_gnf5_recovery_exhausted',1);
                delete_post_meta($post_id,'_gnf5_recovery_next');
                GNF5_Utils::log('AUTO RECOVERY EXHAUSTED â€” post #'.$post_id.' failed after '.$attempt.' automatic attempts. Manual attention is now shown. '.$message,'warning',0);
            }else{
                $delay=GNF5_Utils::recovery_delay_for_attempt($attempt+1);
                update_post_meta($post_id,'_gnf5_recovery_next',time()+$delay);
                GNF5_Utils::log('AUTO RECOVERY RETRY SCHEDULED â€” post #'.$post_id.' attempt '.$attempt.' failed; next retry in '.human_time_diff(time(),time()+$delay).'. '.$message,'warning',0);
            }
        }
    }


    public static function run_category($cat_id,$trigger='manual',$override_limit=null,$scan_multiplier=1) {
        $cat_id=absint($cat_id);
        $cat=get_category($cat_id);
        if(!$cat || is_wp_error($cat))return new WP_Error('category','WordPress category not found.');
        $settings=GNF5_Utils::settings();
        $cs=GNF5_Utils::category_settings($cat_id,$settings);

        if(!GNF5_Utils::acquire_lock($cat_id)){
            return new WP_Error('locked','Another article is processing. Categories and SEO analysis run one at a time; retry shortly.');
        }
        if(function_exists('set_time_limit'))@set_time_limit(300);

        $run_limit=$override_limit===null?absint($cs['post_limit']):min(absint($cs['post_limit']),max(1,absint($override_limit)));
        $scan_multiplier=min(4,max(1,absint($scan_multiplier)));
        GNF5_Utils::log('CATEGORY START â€” '.$cat->name.' ('.$trigger.'). Target: '.$run_limit.' post(s).','info',$cat_id);

        $created=0;$published=0;$drafts=0;$attempted=0;$duplicates=0;$failed_before_create=0;$validation_drafts=0;
        $rss_candidates=0;$source_candidates=0;$rss_failures=0;$source_failures=0;
        $diagnostics=array();
        $rss_urls=GNF5_Utils::urls_from_lines($cs['rss']);
        $source_urls=GNF5_Utils::urls_from_lines($cs['urls']);
        $seen_candidates=array();

        if(!$rss_urls && !$source_urls){
            $message=$cat->name.': no saved RSS Feed or Source URL is configured. Save this category first, then run it.';
            GNF5_Utils::log('CATEGORY STOP â€” '.$message,'warning',$cat_id);
            GNF5_Utils::release_lock($cat_id);
            return array(
                'created'=>0,'published'=>0,'drafts'=>0,'validation_drafts'=>0,'duplicates'=>0,
                'failed_before_create'=>0,'attempted'=>0,'target'=>$run_limit,'message'=>$message,
                'configured_rss'=>0,'configured_sources'=>0,'rss_candidates'=>0,'source_candidates'=>0,
                'rss_failures'=>0,'source_failures'=>0,'diagnostics'=>array($message),'no_candidates'=>1,
            );
        }

        try {
            $base_scan=GNF5_Utils::adaptive_scan_limit($cs['post_limit']);
            $passes=$override_limit===null?array(1,2,3):array($scan_multiplier);

            foreach($passes as $pass_no){
                if($created>=$run_limit)break;
                $scan=min(150,max(10,$base_scan*$pass_no));
                GNF5_Utils::log('SCAN PASS '.$pass_no.' â€” checking up to '.$scan.' candidates per source for '.$cat->name.'.','info',$cat_id);
                $items=array();

                foreach($rss_urls as $feed_url){
                    GNF5_Utils::touch_lock($cat_id);
                    $rr=GNF5_Sources::rss_items($feed_url,$scan);
                    if(is_wp_error($rr)){
                        $rss_failures++;
                        $diag='RSS FAIL â€” '.$feed_url.' â€” '.$rr->get_error_message();
                        $diagnostics[$diag]=true;
                        GNF5_Utils::log($diag,'warning',$cat_id);
                        continue;
                    }
                    $count=count($rr);
                    $rss_candidates=max($rss_candidates,$count);
                    GNF5_Utils::log('RSS OK â€” '.$feed_url.' â€” '.$count.' candidate item(s).','info',$cat_id);
                    $items=array_merge($items,$rr);
                }

                foreach($source_urls as $source_url){
                    GNF5_Utils::touch_lock($cat_id);
                    $sr=GNF5_Sources::discover_source($source_url,$scan);
                    if(is_wp_error($sr)){
                        $source_failures++;
                        $diag='SOURCE FAIL/BLOCKED â€” '.$source_url.' â€” '.$sr->get_error_message();
                        $diagnostics[$diag]=true;
                        GNF5_Utils::log($diag,'warning',$cat_id);
                        continue;
                    }
                    $count=count($sr);
                    $source_candidates=max($source_candidates,$count);
                    $methods=array();foreach($sr as $si){if(!empty($si['method']))$methods[$si['method']]=true;}
                    GNF5_Utils::log('SOURCE OK â€” '.$source_url.' â€” '.$count.' candidate item(s) via '.implode(', ',array_keys($methods)).'.','info',$cat_id);
                    $items=array_merge($items,$sr);
                }

                foreach(self::unique_items($items) as $item){
                    if($created>=$run_limit)break;
                    $u=GNF5_Utils::normalize_url($item['url']??'');
                    if(!$u)continue;
                    $seen_key=GNF5_Utils::source_identity_url($u)?:$u;
                    if(isset($seen_candidates[$seen_key]))continue;
                    $seen_candidates[$seen_key]=true;
                    $attempted++;

                    $dup=GNF5_Utils::duplicate_post_id($u);
                    if($dup){$duplicates++;GNF5_Utils::log('DUPLICATE â€” skipped '.$u.' (post #'.$dup.').','info',$cat_id);continue;}
                    if(!GNF5_Utils::acquire_source_lock($u)){
                        $duplicates++;GNF5_Utils::log('SOURCE BUSY â€” another category/request is already processing this source; skipped for this run.','info',$cat_id);continue;
                    }

                    try{
                        // Recheck after the atomic source claim to close the simultaneous-category duplicate race.
                        $dup=GNF5_Utils::duplicate_post_id($u);
                        if($dup){$duplicates++;GNF5_Utils::log('DUPLICATE â€” skipped '.$u.' (post #'.$dup.').','info',$cat_id);continue;}
                        $item['url']=$u;
                        $result=self::process_article($item,$cat_id,$cs,$trigger);
                    }finally{
                        GNF5_Utils::release_source_lock($u);
                    }
                    if(is_wp_error($result)){
                        $failed_before_create++;
                        GNF5_Utils::log('SKIPPED BEFORE POST CREATE â€” '.$u.' â€” '.$result->get_error_message(),'warning',$cat_id);
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
                GNF5_Utils::log('TARGET NOT FULLY REACHED â€” '.$cat->name.' requested '.$run_limit.' but created '.$created.'. No more usable new candidates were available.','warning',$cat_id);
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
            GNF5_Utils::log('CATEGORY END â€” '.$message,$log_type,$cat_id);
            return array(
                'created'=>$created,'published'=>$published,'drafts'=>$drafts,'validation_drafts'=>$validation_drafts,
                'duplicates'=>$duplicates,'failed_before_create'=>$failed_before_create,'attempted'=>$attempted,'target'=>$run_limit,'message'=>$message,
                'configured_rss'=>count($rss_urls),'configured_sources'=>count($source_urls),
                'rss_candidates'=>$rss_candidates,'source_candidates'=>$source_candidates,
                'rss_failures'=>$rss_failures,'source_failures'=>$source_failures,
                'diagnostics'=>array_keys($diagnostics),'no_candidates'=>$no_candidates?1:0,
            );
        } finally {
            GNF5_Utils::release_lock($cat_id);
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
        }finally{GNF5_Utils::release_source_lock($url);GNF5_Utils::release_lock($cat_id);}
    }

    private static function process_article($item,$cat_id,$cs,$trigger='manual') {
        $url=GNF5_Utils::normalize_url($item['url']??'');
        if(!$url)return new WP_Error('url','Invalid candidate URL.');

        GNF5_Utils::touch_lock($cat_id);
        $source=GNF5_Sources::extract_article($url,$item['fallback_text']??'');
        if(is_wp_error($source))return $source;
        $resolved_url=GNF5_Utils::normalize_url($source['url']??'')?:$url;
        if(GNF5_Utils::source_identity_url($resolved_url)!==GNF5_Utils::source_identity_url($url)){
            $resolved_dup=GNF5_Utils::duplicate_post_id($resolved_url);
            if($resolved_dup)return new WP_Error('duplicate_resolved','Resolved source URL has already been processed as post #'.$resolved_dup.'.');
            $url=$resolved_url;
        }
        $source_words=GNF5_Utils::word_count($source['text']);
        GNF5_Utils::log('EXTRACTED â€” '.$source_words.' source words via '.$source['method'].' â€” '.$url,'info',$cat_id);
        if($source_words<80)return new WP_Error('thin_source','Insufficient source facts (under 80 words).');

        $article=GNF5_Writer::create_article($source,$cat_id);
        if(is_wp_error($article))return $article;

        $article=GNF5_SEO::normalize_metadata($article);
        $content=GNF5_SEO::build_gutenberg_content($article['content_html']);
        $author=GNF5_Utils::valid_author_id($cs['author_id']??0);
        if(!$author)return new WP_Error('author','No valid WordPress author with edit_posts permission is available for this category.');

        // Reliability rule: create a WordPress Draft as soon as the article text exists.
        $post_id=wp_insert_post(array(
            'post_type'=>'post','post_status'=>'draft','post_author'=>$author,
            'post_title'=>$article['title'],'post_name'=>$article['slug'],'post_excerpt'=>$article['excerpt'],
            'post_content'=>$content,'post_category'=>array(absint($cat_id)),'tags_input'=>$article['tags'],
        ),true);
        if(is_wp_error($post_id))return $post_id;

        update_post_meta($post_id,'_gnf5_source_url',$url);
        update_post_meta($post_id,'_gnf5_source_identity',GNF5_Utils::source_identity_url($url));
        update_post_meta($post_id,'_gnf5_source_method',sanitize_text_field($source['method']));
        update_post_meta($post_id,'_gnf5_generated_by','fresh-v5');
        update_post_meta($post_id,'_gnf5_schema_recommendation','NewsArticle');
        update_post_meta($post_id,'_gnf5_article_data',$article);
        update_post_meta($post_id,'_gnf5_source_facts',GNF5_Utils::safe_substr(wp_strip_all_tags($source['text']),0,24000));
        GNF5_Utils::set_state($post_id,'draft_created','Article text saved; continuing post-processing.');
        GNF5_SEO::save_rank_math($post_id,$article);

        return self::finalize_post($post_id,$article,$source['text'],$cat_id,$cs,false);
    }

    private static function compose_content($post_id,$article,$image_ids,$cat_id,$cs,$source_url) {
        $content=GNF5_SEO::build_gutenberg_content($article['content_html']);
        if(!empty(GNF5_Utils::settings()['image_enabled']) && count($image_ids)===2){
            $content=GNF5_Images::insert_two_blocks($content,$image_ids,$article['image_alts']);
        }
        $related=GNF5_SEO::related_links($post_id,$cat_id,3);
        $external=GNF5_SEO::external_links_section($cs['external_links']??'',$source_url);
        return $content.$related.$external;
    }

    private static function finalize_post($post_id,$article,$source_text,$cat_id,$cs,$is_recovery=false) {
        try {
            GNF5_Publish::reset($post_id);
            update_post_meta($post_id,'_gnf5_publish_is_recovery',$is_recovery?1:0);
            // Processing itself is recovery-queued so a hard timeout/fatal after Draft creation
            // cannot leave the post permanently stuck in a non-recoverable state.
            GNF5_Utils::set_state($post_id,'processing','Finalizing article, SEO and images.');
            $polished=GNF5_Writer::polish_after_checkpoint($article,$source_text,$cat_id);
            if(!is_wp_error($polished))$article=$polished;
            else GNF5_Utils::log('Post-checkpoint polish deferred: '.$polished->get_error_message(),'warning',$cat_id);
            $article=GNF5_SEO::normalize_metadata($article);
            update_post_meta($post_id,'_gnf5_article_data',$article);
            GNF5_SEO::save_rank_math($post_id,$article);

            $image_ids=array();
            if(!empty(GNF5_Utils::settings()['image_enabled'])){
                $images=GNF5_Images::generate_for_post($article,$post_id,$cat_id);
                if(is_wp_error($images)){
                    $partial=$images->get_error_data();
                    GNF5_Utils::set_state($post_id,'image_pending','Image generation incomplete: '.$images->get_error_message());
                    update_post_meta($post_id,'_gnf5_validation_errors',array('Image generation incomplete: '.$images->get_error_message()));
                    GNF5_Utils::log('DRAFT #'.$post_id.' â€” image generation incomplete. Successful image(s) are checkpointed; Retry will generate only missing image(s). '.$images->get_error_message(),'error',$cat_id);
                    return array('post_id'=>$post_id,'status'=>'draft','validated'=>false,'errors'=>array($images->get_error_message()));
                }
                $image_ids=$images;
                set_post_thumbnail($post_id,$image_ids[0]);
            }

            $source_url=(string)get_post_meta($post_id,'_gnf5_source_url',true);
            $content=self::compose_content($post_id,$article,$image_ids,$cat_id,$cs,$source_url);
            $updated=self::update_post_checked(array(
                'ID'=>$post_id,'post_title'=>$article['title'],'post_name'=>$article['slug'],
                'post_excerpt'=>$article['excerpt'],'post_content'=>$content,'tags_input'=>$article['tags'],
            ),'Initial finalized article save');
            if(is_wp_error($updated))throw new RuntimeException($updated->get_error_message());
            GNF5_SEO::save_rank_math($post_id,$article);

            // Strict SEO checks are no longer a publishing requirement.
            // Keep informational metrics and the legacy result shape for queue callers.
            $check=array('errors'=>array(),'warnings'=>array(),
                'word_count'=>GNF5_Utils::word_count($article['content_html']),
                'density'=>GNF5_SEO::keyword_density($article['content_html'],$article['focus_keyword']??''));
            update_post_meta($post_id,'_gnf5_validation_errors',array());
            update_post_meta($post_id,'_gnf5_validation_warnings',array());
            update_post_meta($post_id,'_gnf5_final_word_count',$check['word_count']);
            update_post_meta($post_id,'_gnf5_keyword_density',$check['density']);

            // Scoring also runs with Auto Publish off. Every failure remains Draft.
            if(get_post_status($post_id)!=='draft'){
                $draft=self::update_post_checked(array('ID'=>$post_id,'post_status'=>'draft'),'Score gate draft checkpoint');
                if(is_wp_error($draft))throw new RuntimeException($draft->get_error_message());
            }
            update_post_meta($post_id,'_gnf5_seo_repair_source',GNF5_Publish::fingerprint($post_id));
            GNF5_Publish::wait_for_score($post_id,$is_recovery);
            $final_status=get_post_status($post_id);
            return array('post_id'=>$post_id,'status'=>$final_status,'validated'=>true,'awaiting_rankmath'=>$final_status==='draft','errors'=>array(),'warnings'=>$check['warnings']);

        } catch(Throwable $e) {
            GNF5_Utils::set_state($post_id,'post_processing_failed','Post-processing error: '.$e->getMessage());
            update_post_meta($post_id,'_gnf5_validation_errors',array('Post-processing error: '.$e->getMessage()));
            GNF5_Utils::log('DRAFT #'.$post_id.' â€” article remains saved; post-processing failed and can be retried: '.$e->getMessage(),'error',$cat_id);
            return array('post_id'=>$post_id,'status'=>'draft','validated'=>false,'errors'=>array($e->getMessage()),'warnings'=>array());
        }
    }

    /** One bounded improvement of an unchanged generated article, using actual failed tests. */
    public static function repair_scored_post($post_id) {
        $source=GNF5_Publish::fingerprint($post_id);
        if(get_post_status($post_id)!=='draft' || get_post_meta($post_id,'_gnf5_seo_repair_source',true)!==$source
            || (int)get_post_meta($post_id,'_gnf5_seo_repair_attempts',true)>=1) return false;
        $article=get_post_meta($post_id,'_gnf5_article_data',true);
        $facts=get_post_meta($post_id,'_gnf5_source_facts',true);
        if(!is_array($article) || !$facts || empty(GNF5_Utils::settings()['gemini_api_key']))return false;
        $owned=(array)get_post_meta($post_id,'_gnf5_rankmath_written',true);
        foreach(array('rank_math_title','rank_math_description','rank_math_focus_keyword') as $key){
            if(!isset($owned[$key]) || (string)get_post_meta($post_id,$key,true)!==(string)$owned[$key])return false;
        }
        $errors=array('Keep 1000â€“1200 words, the same source facts and focus keyword. Improve only genuine SEO issues; do not keyword-stuff.');
        foreach((array)get_post_meta($post_id,'_gnf5_seo_tests',true) as $name=>$test){
            // Content AI is a separate paid service; the word target remains 1000â€“1200.
            if(in_array($name,array('hasContentAI','lengthContent'),true))continue;
            if(is_array($test) && ($test['score']??0)<($test['maximum']??0))$errors[]=wp_strip_all_tags($test['message']??$name);
        }
        $cats=wp_get_post_categories($post_id); $cat_id=(int)($cats[0]??0);
        update_post_meta($post_id,'_gnf5_seo_repair_attempts',1);
        GNF5_Utils::touch_lock($cat_id);
        try{
            $repaired=GNF5_Writer::repair_for_validation($article,$facts,$errors,$cat_id);
            if(is_wp_error($repaired))throw new RuntimeException($repaired->get_error_message());
            if(get_post_status($post_id)!=='draft' || GNF5_Publish::fingerprint($post_id)!==$source)return false;
            $repaired=GNF5_SEO::normalize_metadata($repaired);
            if($repaired['focus_keyword']!==$article['focus_keyword'])throw new RuntimeException('SEO repair changed the focus keyword; original article retained.');
            $words=GNF5_Utils::word_count($repaired['content_html']);
            if($words<1000 || $words>1200)throw new RuntimeException('SEO repair did not meet the 1000â€“1200 word requirement; original article retained.');
            $image_ids=(array)get_post_meta($post_id,'_gnf5_image_ids',true);
            $content=self::compose_content($post_id,$repaired,$image_ids,$cat_id,GNF5_Utils::category_settings($cat_id),(string)get_post_meta($post_id,'_gnf5_source_url',true));
            GNF5_Publish::reset($post_id);
            $saved=self::update_post_checked(array('ID'=>$post_id,'post_title'=>$repaired['title'],'post_name'=>$repaired['slug'],
                'post_excerpt'=>$repaired['excerpt'],'post_content'=>$content,'tags_input'=>$repaired['tags']),'SEO improvement');
            if(is_wp_error($saved))throw new RuntimeException($saved->get_error_message());
            foreach($image_ids as $index=>$image_id){
                if(isset($repaired['image_alts'][$index]))update_post_meta($image_id,'_wp_attachment_image_alt',sanitize_text_field($repaired['image_alts'][$index]));
            }
            update_post_meta($post_id,'_gnf5_article_data',$repaired);
            update_post_meta($post_id,'_gnf5_final_word_count',$words);
            GNF5_SEO::save_rank_math($post_id,$repaired);
            GNF5_Utils::touch_lock($cat_id);
            GNF5_Utils::log('SEO improvement saved for #'.$post_id.'; existing images reused. Re-analyzing final article.','info',$cat_id);
            return true;
        }catch(Throwable $e){
            GNF5_Utils::log('SEO improvement stopped for #'.$post_id.': '.$e->getMessage(),'warning',$cat_id);
            return false;
        }
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
            delete_option(GNF5_BULK_RECOVERY_LOCK);
            if(!add_option(GNF5_BULK_RECOVERY_LOCK,$state,'','no'))return false;
        }
        self::$bulk_lock_token=$token;
        return true;
    }

    private static function release_bulk_lock() {
        if(!defined('GNF5_BULK_RECOVERY_LOCK'))return;
        $old=get_option(GNF5_BULK_RECOVERY_LOCK,array());
        if(self::$bulk_lock_token!==''&&is_array($old)&&hash_equals((string)($old['token']??''),self::$bulk_lock_token)){
            delete_option(GNF5_BULK_RECOVERY_LOCK);
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

        if(count($clean)!==count($q))self::bulk_queue_save($clean);
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

            GNF5_Utils::log('BULK RETRY â€” selected draft #'.$post_id.' is being processed one-by-one.','info',0);
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
        if(!$post_id || get_post_type($post_id)!=='post')return new WP_Error('post','Post not found.');
        if(!in_array(get_post_status($post_id),array('draft','pending'),true))return new WP_Error('post','Recovery only supports draft or pending posts.');
        if(get_post_meta($post_id,'_gnf5_generated_by',true)!=='fresh-v5')return new WP_Error('post','This is not a GlobiqNews Fresh V5 post.');

        $cats=wp_get_post_categories($post_id);
        $cat_id=absint($cats[0]??0);
        if(!$cat_id)return new WP_Error('category','Post category is missing.');
        if(!GNF5_Utils::acquire_lock($cat_id))return new WP_Error('locked','This category is already importing.');

        try{
            $article=get_post_meta($post_id,'_gnf5_article_data',true);
            if(!is_array($article) || !$article)return new WP_Error('article_data','Stored article recovery data is missing.');
            $source_text=(string)get_post_meta($post_id,'_gnf5_source_facts',true);
            if($source_text===''){
                // If temporary facts were already cleared, try public extraction again only when needed.
                $source_url=(string)get_post_meta($post_id,'_gnf5_source_url',true);
                $source=GNF5_Sources::extract_article($source_url,'');
                if(!is_wp_error($source))$source_text=$source['text'];
            }
            $cs=GNF5_Utils::category_settings($cat_id);
            GNF5_Utils::log('RETRY START â€” post #'.$post_id.'. Existing successful images/state will be reused.','info',$cat_id);
            return self::finalize_post($post_id,$article,$source_text,$cat_id,$cs,true);
        }finally{
            GNF5_Utils::release_lock($cat_id);
        }
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
}
