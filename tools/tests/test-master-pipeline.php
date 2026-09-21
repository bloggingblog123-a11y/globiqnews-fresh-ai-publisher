<?php
$_SERVER['HTTP_HOST']='globiqnews.localhost:8097';$_SERVER['REQUEST_URI']='/';
require_once __DIR__.'/bootstrap600.php';
require_once ABSPATH.'wp-admin/includes/template.php';
$checks=0;$created=array();$media=array();$settings_backup=get_option(GNF5_OPTION);$history_backup=get_option('gnf5_topic_history',array());
$requests=array();$prompts=array();$mode='normal';$serial=0;$case='Orion';
function v6($ok,$label){global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$label);$checks++;echo "PASS: $label\n";}
function response6($body,$code=200,$type='text/html'){return array('response'=>array('code'=>$code,'message'=>'Fixture'),'headers'=>array('content-type'=>$type),'body'=>$body);}
function after6($prompt,$label){$pos=strpos($prompt,$label);return $pos===false?array():json_decode(substr($prompt,$pos+strlen($label)),true);}
function source6($entity){return '<html><head><meta property="article:published_time" content="'.gmdate('c').'"></head><body><article><h1>'.$entity.' technology laboratory announces battery trial</h1><h2>Published statement</h2><p>'.$entity.' opened a battery testing laboratory in Pune. The program involves 12 engineers. Testing starts on 20 September 2026. The laboratory uses solar power. Results will be reviewed after the trial. SOURCE_PROSE_SENTINEL This announcement describes the research program and the stated scope of testing. It does not promise a commercial product or provide a release schedule. The published account identifies an initial team and a location, while leaving the outcome of the research uncertain.</p></article></body></html>';}
function article6($entity){return array('title'=>$entity.' laboratory trial: the questions its evidence can answer','seo_title'=>$entity.' laboratory trial and its limits','focus_keyword'=>$entity.' laboratory','slug'=>sanitize_title($entity.'-laboratory-trial'),'meta_description'=>'A guide to the stated scope of '.$entity.' laboratory work and the unanswered questions for readers.','excerpt'=>'Research context and limits for the announced trial.',
    'content_html'=>'<p>For readers assessing '.$entity.' laboratory work, the useful distinction is between a planned investigation and a finished product. An announced research program alone does not tell buyers when an item will reach stores, so the available evidence should be read within those limits.</p><h2>The scope of the investigation</h2><p>A team of 12 engineers will carry out the work in Pune. Their testing begins on 20 September 2026, and solar power supplies the research site. These details describe the setup; they do not establish how a battery will perform in ordinary use.</p><h2>What readers can watch for</h2><p>The next useful evidence would be the review of results after testing. Until that review exists, readers can separate the confirmed laboratory arrangements from unanswered commercial questions. This distinction helps avoid treating research activity as proof that a product is ready for sale.</p>',
    'tags'=>array('Technology','Research'),'image_prompts'=>array('Original abstract research laboratory illustration','Different conceptual energy research illustration'),'image_alts'=>array('Conceptual laboratory illustration','Conceptual energy research illustration'),'short_reason'=>'Limited source evidence');}
$mock=function($pre,$args,$url)use(&$requests,&$prompts,&$mode,&$serial,&$case){
    $requests[]=$url;
    if(strpos($url,'generativelanguage.googleapis.com')!==false){
        $body=json_decode($args['body'],true);$p=$body['contents'][0]['parts'][0]['text'];$prompts[]=array('prompt'=>$p,'body'=>$body,'url'=>$url,'args'=>$args);
        if($mode==='provider_error')return response6('temporarily unavailable',503);
        if(strpos($p,'TASK: EXTRACT_RESEARCH_FACTS')!==false){
            $sources=after6($p,"RESEARCH_INPUTS:\n");$facts=array();
            foreach($sources as $source){
                $entity=preg_split('/\s+/',$source['title'])[0];
                foreach(array(array('event','A battery laboratory opened in Pune.',$entity.' opened a battery testing laboratory in Pune.'),array('statistic','The research team has 12 engineers.','The program involves 12 engineers.'),array('date','Testing begins on 20 September 2026.','Testing starts on 20 September 2026.'),array('background','Solar power supplies the laboratory.','The laboratory uses solar power.'),array('availability','Results await review after the trial.','Results will be reviewed after the trial.')) as $f)
                    $facts[]=array('kind'=>$f[0],'subject'=>$entity,'detail'=>$f[1],'value'=>'','date'=>'','evidence'=>array(array('source'=>$source['id'],'excerpt'=>$f[2])));
            }
            $out=array('facts'=>$facts,'conflicts'=>array(),'uncertainties'=>array('No commercial release date.'),'primary_sources'=>array());
        }elseif(strpos($p,'TASK: PLAN_ORIGINAL_ARTICLE')!==false){$out=array('angle'=>'Explain the difference between research scope and commercial readiness.','outline'=>array(array('heading'=>'The scope of the investigation','fact_ids'=>array('F1','F2')),array('heading'=>'What readers can watch for','fact_ids'=>array('F5'))),'added_value_plan'=>array(array('kind'=>'explanation','description'=>'Explain evidence limits','fact_ids'=>array('F1','F5'))));}
        elseif(strpos($p,'TASK: WRITE_ORIGINAL_ARTICLE')!==false || strpos($p,'TASK: OPTIMIZE_DRAFT_SEO')!==false){
            preg_match('/"subject":"([^"\\\\]+)"/',$p,$entity);$out=article6($entity[1]??$case);
            if($mode==='copy')$out['content_html']='<p>'.str_repeat(wp_strip_all_tags(source6($entity[1]??$case)).' ',2).'</p>';
            if($mode==='empty')$out['content_html']='';
        }elseif(strpos($p,'TASK: REVIEW_ARTICLE_QUALITY')!==false){
            $a=explode("\nFACTS_WITH_EVIDENCE:\n",explode("\nARTICLE:\n",$p,2)[1],2)[0];$article=json_decode($a,true);
            preg_match_all('/<p[^>]*>(.*?)<\/p>/is',$article['html'],$pars);$claims=array();
            foreach(array_merge(array($article['title']),$pars[1]) as $claim)$claims[]=array('claim'=>GNF5_Research::text($claim),'fact_ids'=>array('F1','F2','F3','F4','F5'),'supported'=>true,'reason'=>'Fixture evidence review');
            $sources=after6($p,"SOURCE_COMPARISON_ONLY:\n");$comp=array();foreach($sources as $source)$comp[]=array('source'=>$source['id'],'imitated'=>false,'reason'=>'Fixture independent organization');
            $value=array();foreach(array('background','explanation','comparison','implications','additional') as $kind)$value[]=array('kind'=>$kind,'excerpt'=>GNF5_Research::text($pars[1][0]),'fact_ids'=>array('F1'));
            $out=array('claim_checks'=>$mode==='partial_review'?array_slice($claims,0,1):$claims,'conflicts'=>array(),'structural_comparisons'=>$comp,'added_value'=>$value);
        }else $out=array('status'=>'ok');
        $envelope=array('candidates'=>array(array('content'=>array('parts'=>array(array('text'=>wp_json_encode($out)))))));
        return response6(wp_json_encode($envelope),200,'application/json');
    }
    if(strpos($url,'blocked.example.org')!==false)return response6('Access denied',403);
    if(strpos($url,'paywall.example.org')!==false)return response6('<script type="application/ld+json">{"isAccessibleForFree":false}</script>'.source6($case));
    if(strpos($url,'timeout.example.org')!==false)return new WP_Error('http_request_failed','Fixture timeout');
    if(strpos($url,'captcha.example.org')!==false)return response6('<h1>Verify you are human</h1><div class="cf-chl-container">challenge-platform</div>');
    if(strpos($url,'api.gdeltproject.org')!==false)return response6(wp_json_encode(array('articles'=>array(array('url'=>'https://research.example.org/'.$case.'/battery-study-a','title'=>$case.' technology laboratory battery trial','seendate'=>gmdate('Ymd\THis\Z'),'domain'=>'research.example.org','language'=>'English','sourcecountry'=>'India')))),200,'application/json');
    if(strpos($url,'/feed')!==false)return response6('<?xml version="1.0"?><rss version="2.0"><channel><title>Research feed</title><link>https://research.example.org/</link><description>Fixture</description><item><title>'.$case.' technology laboratory trial</title><link>https://research.example.org/'.$case.'/battery-study-a</link><pubDate>'.gmdate(DATE_RSS).'</pubDate><description>Battery laboratory news</description></item><item><title>'.$case.' technology laboratory second study</title><link>https://research.example.org/'.$case.'/battery-study-b</link></item></channel></rss>',200,'application/rss+xml');
    if(strpos($url,'/listing')!==false)return response6('<html><body><main><article><h2><a href="https://research.example.org/'.$case.'/battery-study-a">'.$case.' technology laboratory battery trial</a></h2></article></main></body></html>');
    if(strpos($url,'research.example.org')!==false)return response6(source6($case));
    return $pre;
};
add_filter('pre_http_request',$mock,10,3);
$term=wp_insert_term('Technology test '.wp_generate_password(6,false),'category');$cat=(int)$term['term_id'];
function setup6($cat,$combo){global $case;$s=GNF5_Utils::defaults();$s['gemini_api_key']='FIXTURE-SECRET-NOT-REAL';$s['rankmath_enabled']=0;$s['image_enabled']=0;
    $s['categories'][$cat]=GNF5_Utils::sanitize_category_row(array('author_id'=>1,'post_limit'=>1,'rss'=>($combo&2)?'https://research.example.org/'.$case.'/feed':'','urls'=>($combo&4)?'https://research.example.org/'.$case.'/listing':'','gdelt_enabled'=>($combo&1)?1:0,'gdelt_keywords'=>$case.' technology','min_sources'=>1,'opportunity_threshold'=>0));update_option(GNF5_OPTION,$s);
    delete_option('gnf5_topic_history');delete_option('gnf5_run_cat_'.$cat);delete_option('gnf5_article_worker');delete_option('gnf5_lock_cat_'.$cat);GNF5_Sources::reset_budget();
}
try{
    setup6($cat,2);
    $rss=GNF5_Sources::rss_items('https://research.example.org/test/feed',5);
    v6(!is_wp_error($rss) && count($rss)===2,'RSS parser works through safe bounded transport'.(is_wp_error($rss)?': '.$rss->get_error_message():''));
    foreach(array('blocked','paywall','timeout','captcha') as $type){
        $u='https://'.$type.'.example.org/story';delete_transient(GNF5_Utils::blocked_key($u));$before=count($requests);
        $r=GNF5_Sources::extract_article($u,str_repeat('RSS fallback must not bypass blocked source. ',30));
        v6(is_wp_error($r) && count($requests)===$before+1,$type.' stops without alternative/source-prose bypass');
        $record=get_transient(GNF5_Utils::blocked_key($u));v6(is_array($record) && $record['next_retry']-$record['time']===21600,$type.' records fixed six-hour retry');
        GNF5_Sources::extract_article($u);v6(count($requests)===$before+1,$type.' cached retry makes no new request');
    }
    foreach(range(0,7) as $combo){
        $case=array('Orion','Nimbus','Kestrel','Maple','Cobalt','Juniper','Atlas','Zephyr')[$combo];setup6($cat,$combo);
        if($combo===0){$r=GNF5_Runner::run_category($cat,'test',1,1,'combo0');v6(!is_wp_error($r) && $r['created']===0,'no automatic source creates no automated Draft');$r=GNF5_Runner::run_manual_url('https://research.example.org/'.$case.'/battery-study-a',$cat);$id=is_wp_error($r)?0:($r['post_id']??0);}
        else{$r=GNF5_Runner::run_category($cat,'test',1,1,'combo'.$combo);$ids=get_posts(array('post_type'=>'post','post_status'=>'draft','numberposts'=>5,'meta_key'=>'_gnf5_run_id','meta_value'=>'combo'.$combo,'fields'=>'ids'));$id=$ids[0]??0;}
        v6(!is_wp_error($r) && $id>0,'discovery combination '.$combo.' creates a Draft'.(is_wp_error($r)?': '.$r->get_error_message():': '.wp_json_encode($r)));
        $created[]=$id;v6(get_post_status($id)==='draft','combination '.$combo.' remains Draft');
        $research=get_post_meta($id,'_gnf5_research',true);$report=get_post_meta($id,'_gnf5_quality_report',true);
        v6(!empty($research['facts']) && !isset($research['sources'][0]['text']),'structured research persists without permanent full source prose');
        v6(($report['images']['generation']??'')==='OFF' && !get_post_thumbnail_id($id),'images OFF creates no thumbnail');
        v6(($report['originality']['status']??'')==='PASS' && ($report['facts']['status']??'')==='PASS','actual fixture comparison and complete evidence-review coverage recorded');
        if($combo){$again=GNF5_Runner::run_category($cat,'test',1,1,'combo'.$combo);v6(!is_wp_error($again) && $again['created']===0 && GNF5_Runner::run_count('combo'.$combo)===1,'repeated same batch respects one-Draft limit');}
    }
    $writes=array_filter($prompts,function($r){return strpos($r['prompt'],'TASK: WRITE_ORIGINAL_ARTICLE')!==false || strpos($r['prompt'],'TASK: OPTIMIZE_DRAFT_SEO')!==false;});
    v6(count($writes)>=8,'final writer requests captured');
    foreach($writes as $request){v6(strpos($request['prompt'],'SOURCE_PROSE_SENTINEL')===false && !empty($request['body']['systemInstruction']['parts'][0]['text']),'final writer excludes source prose and includes protected system instruction');}
    v6(strpos($prompts[0]['url'],'key=')===false && ($prompts[0]['args']['headers']['x-goog-api-key']??'')==='FIXTURE-SECRET-NOT-REAL','Gemini credential sent in header, not URL');
    $id=end($created);$research=GNF5_Research::load($id);$article=get_post_meta($id,'_gnf5_article_data',true);
    $mode='partial_review';$partial=GNF5_Quality::evaluate($article,$research,true);v6($partial['facts']['status']==='UNKNOWN','incomplete factual review cannot claim PASS');$mode='normal';
    $copy=$article;$copy['content_html']='<p>'.$research['sources'][0]['text'].'</p>';$bad=GNF5_Quality::originality($copy,$research['sources']);v6($bad['status']==='FAIL','copied source prose fails measured originality');
    $facts=GNF5_Research::writer_facts($research);v6(!isset($facts['sources']) && !isset($facts['facts'][0]['evidence']),'writer boundary excludes source body/headings/evidence prose');
    $invalid=array('facts'=>array(array('subject'=>'Test','detail'=>'The trial has 9999 engineers.','evidence'=>array(array('source'=>'S1','excerpt'=>'The program involves 12 engineers.'))),array('subject'=>'Test','detail'=>'A made-up fact','evidence'=>array(array('source'=>'S1','excerpt'=>'This excerpt never existed.')))));
    v6(count(GNF5_Research::validate($invalid,$research['sources'])['facts'])===0,'unsupported numeric facts and invented evidence are rejected');
    $one=$research;$one['sources']=array_slice($research['sources'],0,1);$one['facts']=array_reverse($one['facts']);
    v6(GNF5_Topics::fingerprint($one)===GNF5_Topics::fingerprint($research),'topic fingerprint ignores fact ordering and headlines');
    GNF5_Topics::remember($research,$cat,'draft',$id);v6((bool)GNF5_Topics::duplicate($one),'persistent topic history detects same event');
    $new=$research;foreach($new['facts'] as &$f){$f['detail']='A fundamentally different commercial launch in London with 9999 units';$f['value']='9999';}unset($f);v6(!GNF5_Topics::duplicate($new),'materially new development can pass topic history');
    $before=count($requests);$before_media=count(get_posts(array('post_type'=>'attachment','post_status'=>'inherit','numberposts'=>-1,'fields'=>'ids')));
    v6(is_wp_error(GNF5_Images::test()) && is_wp_error(GNF5_Images::generate_one('No image','Prompt')) && is_wp_error(GNF5_Images::generate_one_with_retry('No image','Prompt',$cat)),'OFF refuses test/direct/retry generation entry points');
    v6(GNF5_Images::generate_for_post($article,$id,$cat)===array() && GNF5_Images::generate_two($article)===array(),'OFF skips article and compatibility image entry points');
    v6(count($requests)===$before && count(get_posts(array('post_type'=>'attachment','post_status'=>'inherit','numberposts'=>-1,'fields'=>'ids')))===$before_media,'OFF performs zero API requests and creates zero attachments');
    wp_update_post(array('ID'=>$id,'post_content'=>'<p>Human edited this draft.</p>'));$before=count($requests);$retry=GNF5_Runner::retry_post($id);
    v6(is_wp_error($retry) && $retry->get_error_code()==='manual_edit' && count($requests)===$before,'recovery preserves manual edits without calling AI');
    // Render admin output with saved secrets; nothing secret should appear in HTML.
    wp_set_current_user(1);ob_start();GNF5_Admin::page();$html=ob_get_clean();
    v6(strpos($html,'FIXTURE-SECRET-NOT-REAL')===false && strpos($html,'gdelt_keywords')!==false && strpos($html,'Auto Publish when')===false,'admin shows new settings and never renders saved API secret');
    v6(strpos($html,'Select an author (required)')!==false,'admin author picker has an explicit unselected option');
    echo "TOTAL: $checks pipeline checks passed\n";
} finally {
    remove_filter('pre_http_request',$mock,10);
    foreach($created as $id)wp_delete_post($id,true);foreach($media as $id)wp_delete_attachment($id,true);
    wp_delete_term($cat,'category');delete_option('gnf5_run_cat_'.$cat);GNF5_Utils::release_lock($cat);
    update_option(GNF5_OPTION,$settings_backup);update_option('gnf5_topic_history',$history_backup,false);
}
