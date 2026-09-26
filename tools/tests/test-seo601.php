<?php
// Isolated WordPress + real Rank Math; external providers are controlled fixtures.
require __DIR__.'/test-master-pipeline.php';
$checks=0;$created=array();$backup=get_option(GNF5_OPTION);$case='Solstice';$mode='normal';
$term=wp_insert_term('SEO regression '.wp_generate_password(6,false),'category');$cat=(int)$term['term_id'];
$other=wp_insert_term('Other SEO '.wp_generate_password(6,false),'category');$othercat=(int)$other['term_id'];
$seoCalls=0;$repairMode='improve';$lastPrompt='';$raceId=0;
$seoMock=function($pre,$args,$url)use(&$seoCalls,&$repairMode,&$lastPrompt,&$raceId){
    if(strpos($url,'generativelanguage.googleapis.com')!==false){
        $b=json_decode($args['body'],true);$p=$b['contents'][0]['parts'][0]['text'];
        if(strpos($p,'TASK: OPTIMIZE_DRAFT_SEO')!==false){
            $seoCalls++;$lastPrompt=$p;
            $current=json_decode(explode("\nGENUINE_SEO_FEEDBACK:\n",explode("\nCURRENT_ARTICLE:\n",$p,2)[1],2)[0],true);
            $out=$current;
            if($repairMode!=='same'){
                $out['seo_title']='Solstice laboratory: research review and key questions';
                $out['meta_description']='Solstice laboratory research scope, evidence and the questions that remain open.';
                $out['slug']='solstice-laboratory';
                $out['content_html']=str_replace('The scope of the investigation','Solstice laboratory investigation',$out['content_html']);
                // Deterministic improvement of missing metadata/headings; never fabricate body facts.
                if($repairMode==='bad')$out['content_html']='<p>'.str_repeat('SOLSTICE unsupported fact invented by the fixture. ',100).'</p>';
                if($repairMode==='keyword')$out['focus_keyword']='Unrelated keyword';
            }
            if($repairMode==='race' && $raceId)wp_update_post(array('ID'=>$raceId,'post_content'=>'<p>Human edit during SEO request.</p>'));
            return response6(wp_json_encode(array('candidates'=>array(array('content'=>array('parts'=>array(array('text'=>wp_json_encode($out)))))))),200,'application/json');
        }
    }
    if(strpos($url,'trusted.example.org')!==false)return response6('',200);
    if(strpos($url,'broken.example.org')!==false)return response6('',404);
    if(strpos($url,'restricted.example.org')!==false)return response6('',403);
    return $pre;
};
function draft601($cat,$article,$research){
    global $created;
    // Each independent repair fixture must have its own headline: production now
    // correctly rejects duplicate titles across drafts before attempting SEO.
    static $fixture=0;$fixture++;
    if($fixture>1){$label=array('Methods limits context perspective','Evidence boundaries practical questions','Independent design scope assessment','Cautious interpretation study timeline','Research sampling protocol overview')[$fixture-2]??wp_generate_password(16,false);$article['title']='Solstice laboratory: '.$label;$article['seo_title']=$article['title'];}
    $id=GNF5_Publish::save(array('post_title'=>$article['title'],'post_content'=>GNF5_SEO::build_gutenberg_content($article['content_html']),'post_name'=>$article['slug'],'post_status'=>'draft','post_category'=>array($cat),'meta_input'=>array('_gnf5_generated_by'=>'fresh-v5')),true);$created[]=$id;
    update_post_meta($id,'_gnf5_article_data',$article);GNF5_Research::store($id,$research);GNF5_SEO::save_rank_math($id,$article);GNF5_Publish::checkpoint($id);return $id;
}
try{
    setup6($cat,2);$s=get_option(GNF5_OPTION);$s['rankmath_enabled']=1;$s['internal_links']=1;$s['toc_enabled']=1;$s['seo_analyzer_mode']='local';update_option(GNF5_OPTION,$s);
    add_filter('pre_http_request',$seoMock,9,3);add_filter('pre_http_request',$mock,10,3);
    // Main fixture respects results from the targeted SEO provider mock.
    remove_filter('pre_http_request',$mock,10);
    $combined=function($pre,$args,$url)use($mock){return $pre!==false?$pre:$mock($pre,$args,$url);};add_filter('pre_http_request',$combined,10,3);
    $research=GNF5_Research::gather(array('url'=>'https://research.example.org/Solstice/trial','method'=>'Manual URL'),$cat);
    v6(!is_wp_error($research),'research fixture assembled from checked excerpts');
    $article=GNF5_SEO::sanitize_article_data(article6('Solstice'));$article['plan']=array();$article['seo_title']='Research review';$article['meta_description']='Research overview';$article['slug']='research-overview';
    $sample=$article;$sample['content_html']='<p>Solstice laboratory '.implode(' ',array_fill(0,433,'context')).' Solstice laboratory</p>';
    $sampleChecks=GNF5_SEO::text_checks($sample);
    v6($sampleChecks['length']['status']==='REVIEW' && $sampleChecks['density']['status']==='REVIEW','437-word/two-keyword screenshot conditions produce actionable feedback');
    $sample['content_html']='<p>'.implode(' ',array_fill(0,7,'Solstice laboratory')).' '.implode(' ',array_fill(0,586,'context')).'</p>';$sampleChecks=GNF5_SEO::text_checks($sample);
    v6($sampleChecks['length']['status']==='PASS' && $sampleChecks['density']['status']==='PASS','600-word and natural-density boundary passes local checks');
    $id=draft601($cat,$article,$research);
    $related=wp_insert_post(array('post_title'=>'Solstice laboratory research background','post_content'=>'<p>A related published discussion.</p>','post_status'=>'publish','post_category'=>array($othercat)));$created[]=$related;
    $unrelated=wp_insert_post(array('post_title'=>'Football stadium ticket prices','post_status'=>'publish','post_category'=>array($cat)));$created[]=$unrelated;
    $private=wp_insert_post(array('post_title'=>'Solstice laboratory private details','post_status'=>'draft','post_category'=>array($cat)));$created[]=$private;
    $password=wp_insert_post(array('post_title'=>'Solstice laboratory password protected','post_status'=>'publish','post_password'=>'fixture','post_category'=>array($cat)));$created[]=$password;
    $linked=GNF5_SEO::insert_internal_links($article['content_html'],$id,$cat);
    v6(strpos($linked,get_permalink($related))!==false,'cross-category relevant post without target focus-keyword metadata is linked');
    v6(strpos($linked,get_permalink($unrelated))===false && strpos($linked,get_permalink($private))===false && strpos($linked,get_permalink($password))===false,'unrelated, unpublished and password-protected targets excluded');
    v6(GNF5_SEO::insert_internal_links($linked,$id,$cat)===$linked,'link insertion is idempotent');
    $s['internal_links']=0;update_option(GNF5_OPTION,$s);v6(GNF5_SEO::insert_internal_links($article['content_html'],$id,$cat)===$article['content_html'],'internal-link OFF honored');$s['internal_links']=1;
    $s['categories'][$cat]['manual_links_enabled']=1;$s['categories'][$cat]['manual_links_max']=2;
    $s['categories'][$cat]['manual_links']=array();foreach(array('broken','restricted','trusted') as $domain)$s['categories'][$cat]['manual_links'][]=array('id'=>$domain,'url'=>'https://'.$domain.'.example.org/solstice-laboratory','anchor'=>'Solstice laboratory','note'=>'Research context','enabled'=>1,'usage'=>'optional');update_option(GNF5_OPTION,$s);
    $external=GNF5_SEO::insert_external_links($article['content_html'],$id,$cat);
    v6(strpos($external,'https://trusted.example.org/solstice-laboratory')!==false && strpos($external,'nofollow')===false,'validated trusted editorial URL added as followable link');
    v6(strpos($external,'https://broken.example.org')===false && strpos($external,'https://restricted.example.org')===false,'broken and restricted external destinations omitted');
    v6(strpos($external,'https://research.example.org')===false,'ordinary research URLs are not dumped into the article');
    $s['auto_source_links']=1;$s['categories'][$cat]['manual_links_enabled']=0;update_option(GNF5_OPTION,$s);
    $primary=$research;$primary['primary_sources']=array(array('source'=>$research['sources'][0]['id']));GNF5_Research::store($id,$primary);
    $external=GNF5_SEO::insert_external_links($article['content_html'],$id,$cat);
    v6(strpos($external,'https://research.example.org/Solstice/trial')!==false,'primary source supporting retained facts gets contextual link');
    GNF5_Research::store($id,$research);$s['auto_source_links']=0;$s['categories'][$cat]['manual_links_enabled']=1;update_option(GNF5_OPTION,$s);
    $before=GNF5_SEO::text_feedback($article);$repairMode='improve';$beforeCalls=$seoCalls;
    GNF5_Runner::optimize_text($id);$after=get_post_meta($id,'_gnf5_article_data',true);
    v6(count(GNF5_SEO::text_feedback($after))<count($before),'PHP preflight improves text checks without invoking a scoring service');
    v6($seoCalls>$beforeCalls && $seoCalls-$beforeCalls<=3,'preflight uses bounded provider calls');
    v6(get_post_meta($id,'rank_math_seo_score',true)==='','local checklist never writes a fake Rank Math score');
    v6(get_post_status($id)==='draft' && !get_post_thumbnail_id($id),'SEO improvement remains Draft and creates no images');
    v6(strpos($lastPrompt,'NON-IMAGE SEO')!==false && strpos($lastPrompt,'SOURCE_PROSE_SENTINEL')===false,'repair receives SEO guidance and structured facts, not source prose');
    $checksNow=GNF5_SEO::checklist($id);
    v6($checksNow['internal_links']['status']==='PASS' && $checksNow['external_links']['status']==='PASS' && $checksNow['dofollow']['status']==='PASS','final checklist detects real rendered links: '.wp_json_encode(array_intersect_key($checksNow,array_flip(array('internal_links','external_links','dofollow')))));
    v6(isset($checksNow['sentiment'],$checksNow['title_number'],$checksNow['toc'],$checksNow['keyword_unique']) && !isset($checksNow['images']),'all non-image title/readability/keyword checks covered separately');
    $repairMode='keyword';$repaired=GNF5_Writer::repair_for_validation($article,$research,$before,$cat);
    v6($repaired['focus_keyword']===$article['focus_keyword'],'AI cannot switch the focus keyword during repair');
    $nochange=draft601($cat,$article,$research);$repairMode='same';$beforeCalls=$seoCalls;GNF5_Runner::optimize_text($nochange);
    v6($seoCalls===$beforeCalls+1 && get_post_meta($nochange,'_gnf5_seo_repair_attempts',true)==1,'unchanged response stops immediately instead of wasting three rewrites');
    update_post_meta($nochange,'_gnf5_seo_repair_attempts',3);$beforeCalls=$seoCalls;GNF5_Runner::optimize_text($nochange);
    v6($seoCalls===$beforeCalls,'three-attempt limit survives repeated button/worker runs');
    $bad=draft601($cat,$article,$research);$original=get_post_field('post_content',$bad);$repairMode='bad';$mode='partial_review';GNF5_Runner::repair_scored_post($bad);$mode='normal';
    v6(get_post_field('post_content',$bad)===$original,'failed factual review retains original article');
    $raceId=draft601($cat,$article,$research);$repairMode='race';GNF5_Runner::repair_scored_post($raceId);
    v6(get_post_field('post_content',$raceId)==='<p>Human edit during SEO request.</p>','concurrent human edit is not overwritten');
    $beforeCalls=$seoCalls;$r=GNF5_Runner::improve_seo($raceId);
    v6(is_wp_error($r) && $seoCalls===$beforeCalls,'Improve SEO refuses manually edited draft without API calls');
    $repairMode='same';update_post_meta($id,'_gnf5_seo_repair_attempts',3);update_post_meta($id,'_gnf5_state','awaiting_rankmath');GNF5_RankMath::reset_retry($id);GNF5_RankMath::run($id);
    v6(GNF5_Publish::score($id)!==null,'real Rank Math 1.0.278 analyzer runs on the finished article');
    $actual=get_post_meta($id,'_gnf5_seo_tests',true);
    foreach(array('linksHasInternal','linksHasExternals','linksNotAllExternals') as $key){if(isset($actual[$key]))v6($actual[$key]['score']===$actual[$key]['maximum'],'real Rank Math '.$key.' passes');}
    v6(get_post_status($id)==='draft','actual score still cannot publish');
    wp_set_current_user(1);ob_start();GNF5_Admin::report_box(get_post($id));$report=ob_get_clean();
    v6(strpos($report,'Improve SEO &amp; Links')!==false && strpos($report,'Non-image SEO checklist')!==false,'editor renders improvement action and readable checklist');
    echo 'REAL ANALYZER SCORE: '.GNF5_Publish::score($id)."\n";echo "TOTAL: $checks SEO 6.0.1 checks passed\n";
} finally {
    remove_filter('pre_http_request',$seoMock,9);if(isset($combined))remove_filter('pre_http_request',$combined,10);
    foreach($created as $pid){wp_clear_scheduled_hook(GNF5_RankMath::HOOK,array((int)$pid));wp_delete_post($pid,true);}
    wp_delete_term($cat,'category');wp_delete_term($othercat,'category');delete_option('gnf5_run_cat_'.$cat);GNF5_Utils::release_lock($cat);update_option(GNF5_OPTION,$backup);
}
