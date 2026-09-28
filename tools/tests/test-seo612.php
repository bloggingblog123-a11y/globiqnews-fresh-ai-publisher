<?php
// Disposable WordPress only. Controlled evidence/model fixtures, real Rank Math analyzer.
require __DIR__.'/test-seo611.php';
$checks=0;$created=array();$backup=get_option(GNF5_OPTION);$cron=get_option('cron');$logs=get_option(GNF5_LOG_OPTION);$case='Solstice';$mode='normal';
$term=wp_insert_term('SEO612 '.wp_generate_password(7,false),'category');$cat=(int)$term['term_id'];
$mode612='expand';$calls612=0;$prompt612='';
$mock612=function($pre,$args,$url)use(&$mode612,&$calls612,&$prompt612){
 if(strpos($url,'generativelanguage.googleapis.com')!==false){$p=json_decode($args['body'],true)['contents'][0]['parts'][0]['text'];
  if(strpos($p,'TASK: OPTIMIZE_DRAFT_SEO')!==false){$calls612++;$prompt612=$p;$a=json_decode(explode("\nGENUINE_SEO_FEEDBACK:\n",explode("\nCURRENT_ARTICLE:\n",$p,2)[1],2)[0],true);
   if($mode612==='unchanged'){$a['short_reason']='Evidence contains no additional trial results to support expansion.';$a['seo_unresolved']=array('Title sentiment cannot be supported before results exist.');}
   else{$a['content_html']=body611(650,10);$a['seo_title']='Solstice laboratory: 12 engineers report successful testing';}
   return response6(wp_json_encode(array('candidates'=>array(array('content'=>array('parts'=>array(array('text'=>wp_json_encode($a)))))))),200,'application/json');
  }
  if($mode612==='reject' && strpos($p,'TASK: REVIEW_ARTICLE_QUALITY')!==false){
   $r=$GLOBALS['mock']($pre,$args,$url);$env=json_decode($r['body'],true);$out=json_decode($env['candidates'][0]['content']['parts'][0]['text'],true);$out['claim_checks'][1]['supported']=false;$out['claim_checks'][1]['reason']='Trial success is unsupported in this negative fixture.';$env['candidates'][0]['content']['parts'][0]['text']=wp_json_encode($out);$r['body']=wp_json_encode($env);return $r;
  }
 }
 return $GLOBALS['mock']($pre,$args,$url);
};
try{
 setup6($cat,2);$s=get_option(GNF5_OPTION);$s['rankmath_enabled']=1;$s['seo_analyzer_mode']='local';$s['internal_links']=1;update_option(GNF5_OPTION,$s);add_filter('pre_http_request',$mock612,9,3);
 $research=GNF5_Research::gather(array('url'=>'https://research.example.org/Solstice/trial','method'=>'Manual URL'),$cat);
 // Positive fixture explicitly contains successful test evidence; this is not a live AI assessment.
 $research['facts'][]=array('id'=>'F6','kind'=>'event','subject'=>'Solstice','detail'=>'The completed test was successful.','evidence'=>array(array('source'=>$research['sources'][0]['id'],'excerpt'=>'The completed test was successful.')));
 $research['sources'][0]['text'].=' The completed test was successful.';
 $a=GNF5_SEO::sanitize_article_data(article6('Solstice'));$a['title']='Solstice laboratory: evaluating the research program';$a['content_html']=body611(385,6);$a['seo_title']='Solstice laboratory: 12 engineers review evidence';
 v6(GNF5_Utils::word_count($a['content_html'])===385,'reported 385-word case reproduced');
 $check=GNF5_SEO::text_checks($a);v6($check['sentiment']['repair'] && $check['power_word']['repair'],'both title warnings participate in repair');
 $smaller=$a;$smaller['content_html']=body611(300,5);$smaller['seo_title']='Solstice laboratory: 12 engineers report successful testing';
 v6(!GNF5_SEO::text_repair_progress($a,$smaller),'improved title cannot conceal a shrinking short body');
 $id=draft611($a,$research,$cat);
 $related=wp_insert_post(array('post_status'=>'publish','post_title'=>'Research facilities in focus','post_content'=>"<p>Solstice operates a solar-powered laboratory, where testing outcomes are discussed.</p>",'post_category'=>array($cat)));$created[]=$related;
 $far=wp_insert_post(array('post_status'=>'publish','post_title'=>'A separate subject','post_content'=>'<p>Solstice '.str_repeat('unrelated ',45).'laboratory.</p>','post_category'=>array($cat)));$created[]=$far;
 $private=wp_insert_post(array('post_status'=>'private','post_title'=>'Private facilities','post_content'=>'<p>Solstice operates a laboratory.</p>'));$created[]=$private;
 $linked=GNF5_SEO::insert_internal_links($a['content_html'],$id,$cat);
 v6(strpos($linked,get_permalink($related))!==false,'same subject with intervening words is linked');
 v6(strpos($linked,get_permalink($far))===false,'widely separated terms do not establish relevance');
 v6(strpos($linked,get_permalink($private))===false,'private target remains excluded');
 v6(GNF5_SEO::insert_internal_links($linked,$id,$cat)===$linked,'internal link insertion remains idempotent');
 v6(GNF5_Runner::repair_scored_post($id,true),'385-word article expands after evidence review');
 $saved=get_post_meta($id,'_gnf5_article_data',true);$c=GNF5_SEO::text_checks($saved);
 foreach(array('length','density','sentiment','power_word') as $k)v6($c[$k]['status']==='PASS','saved fixture passes '.$k);
 v6(strpos($prompt612,'Recognized power-word examples')!==false && strpos($prompt612,'"current_words":385')!==false,'repair receives installed vocabulary and measured shortfall');
 update_post_meta($id,'_gnf5_state','awaiting_rankmath');GNF5_RankMath::reset_retry($id);GNF5_RankMath::run($id);$actual=get_post_meta($id,'_gnf5_seo_tests',true);
 foreach(array('titleSentiment','titleHasPowerWords','linksHasInternal') as $k)v6(isset($actual[$k]) && $actual[$k]['score']===$actual[$k]['maximum'],'actual Rank Math passes '.$k);
 v6(isset($actual['lengthContent']) && strpos($actual['lengthContent']['message'],'at least 600')===false,'actual Rank Math no longer flags the 600-word minimum');
 v6(get_post_status($id)==='draft' && !get_post_thumbnail_id($id),'repair preserves Draft-only and image OFF');
 $message=GNF5_Runner::seo_result_message($id);v6(strpos($message,'650 words')!==false && strpos($message,'Internal links: PASS')!==false,'action reports measured saved results');
 $a['title']='Solstice laboratory: unanswered questions in research';$short=draft611($a,$research,$cat);$mode612='unchanged';GNF5_Runner::optimize_text($short);
 $message=GNF5_Runner::seo_result_message($short);v6(strpos($message,'385 words (below 600)')!==false && strpos($message,'Evidence contains no additional')!==false,'unchanged short output retains the evidence reason and actual shortfall');
 $before=$calls612;GNF5_Runner::optimize_text($short);v6($calls612===$before,'unchanged candidate does not trigger endless retries');
 $a['title']='Solstice laboratory: methods and evidence limitations';$reject=draft611($a,$research,$cat);$r=get_post_meta($reject,'_gnf5_article_data',true);$r['quality']=GNF5_Quality::evaluate($r,$research,true,$reject);update_post_meta($reject,'_gnf5_article_data',$r);GNF5_Publish::checkpoint($reject);$mode612='reject';
 v6(!GNF5_Runner::repair_scored_post($reject,true) && get_post_meta($reject,'rank_math_title',true)===$a['seo_title'],'unsupported positive title is rejected by quality review');
 $s['internal_links']=0;update_option(GNF5_OPTION,$s);v6(GNF5_SEO::insert_internal_links($a['content_html'],$reject,$cat)===$a['content_html'],'internal links OFF is honored');
 $s['rankmath_enabled']=0;update_option(GNF5_OPTION,$s);GNF5_Runner::optimize_text($reject);v6(strpos(get_post_meta($reject,'_gnf5_seo_repair_note',true),'OFF')!==false,'disabled text optimization is explained');
 $s['rankmath_enabled']=1;$s['gemini_api_key']='';update_option(GNF5_OPTION,$s);GNF5_Runner::optimize_text($reject);v6(strpos(get_post_meta($reject,'_gnf5_seo_repair_note',true),'API key')!==false,'missing writer credential is explained without disclosing secrets');
 echo "TOTAL: $checks SEO 6.1.2 checks passed\n";
}finally{
 remove_filter('pre_http_request',$mock612,9);foreach($created as $pid){wp_clear_scheduled_hook(GNF5_RankMath::HOOK,array((int)$pid));delete_transient('gnf5_research_text_'.$pid);wp_delete_post($pid,true);}wp_delete_term($cat,'category');GNF5_Utils::release_lock($cat);update_option(GNF5_OPTION,$backup);update_option('cron',$cron);update_option(GNF5_LOG_OPTION,$logs);
}
