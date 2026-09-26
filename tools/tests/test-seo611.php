<?php
// Disposable WordPress only. Real Rank Math engine; controlled research/AI fixtures.
require __DIR__.'/test-master-pipeline.php';
$checks=0;$created=array();$backup=get_option(GNF5_OPTION);$cron=get_option('cron');$logs=get_option(GNF5_LOG_OPTION);$mode='normal';$case='Solstice';
$term=wp_insert_term('SEO611 '.wp_generate_password(7,false),'category');$cat=(int)$term['term_id'];
$calls611=0;$mode611='expand';$last611='';$race611=0;
function body611($words,$occ,$kw='Solstice laboratory'){
 $body='<h2>'.$kw.' evidence</h2> ';$remaining=$words-GNF5_Utils::word_count($body);$left=$occ-1;
 while($remaining>0){$size=min(60,$remaining);$tokens=array();if($left>0 && $size>=GNF5_Utils::word_count($kw)){$tokens=explode(' ',$kw);$left--;}
  while(count($tokens)<$size)$tokens[]=array('battery','trial','research','scope','evidence','interpretation','limits','context')[count($tokens)%8];
  $body.='<p>'.implode(' ',$tokens).'.</p> ';$remaining-=$size;
 }return $body;
}
function draft611($a,$r,$cat){global $created;$id=GNF5_Publish::save(array('post_title'=>$a['title'],'post_name'=>$a['slug'],'post_content'=>$a['content_html'],'post_excerpt'=>$a['excerpt'],'post_category'=>array($cat),'meta_input'=>array('_gnf5_generated_by'=>'fresh-v5','_gnf5_article_data'=>$a)),true);$created[]=$id;GNF5_Research::store($id,$r);GNF5_SEO::save_rank_math($id,$a);GNF5_Publish::checkpoint($id);return $id;}
$mock611=function($pre,$args,$url)use(&$calls611,&$mode611,&$last611,&$race611){
 if(strpos($url,'generativelanguage.googleapis.com')!==false){$p=json_decode($args['body'],true)['contents'][0]['parts'][0]['text'];
  if(strpos($p,'TASK: OPTIMIZE_DRAFT_SEO')!==false){$calls611++;$last611=$p;$a=json_decode(explode("\nGENUINE_SEO_FEEDBACK:\n",explode("\nCURRENT_ARTICLE:\n",$p,2)[1],2)[0],true);
   if($mode611==='partial')$a['content_html']=body611(477,5);
   elseif($mode611==='unchanged'){$a['short_reason']='Verified facts cannot support additional useful coverage.';$a['seo_unresolved']=array('No supported number or useful list count is available.');}
   else{
    $kw=$mode611==='refine'?'Solstice laboratory battery trial':$a['focus_keyword'];$a['focus_keyword']=$kw;
    $a['seo_title']=$kw.': 12 engineers and research scope';$a['meta_description']=$kw.' research evidence and the limits of the announced program.';$a['slug']=sanitize_title($kw);
    $a['content_html']=body611(650,10,$kw);
   }
   return response6(wp_json_encode(array('candidates'=>array(array('content'=>array('parts'=>array(array('text'=>wp_json_encode($a)))))))),200,'application/json');
  }
  if($mode611==='race' && strpos($p,'TASK: REVIEW_ARTICLE_QUALITY')!==false && $race611)wp_update_post(array('ID'=>$race611,'post_content'=>'<p>Human change during evidence refresh.</p>'));
 }
 return $pre!==false?$pre:$GLOBALS['mock']($pre,$args,$url);
};
try{
 setup6($cat,2);$s=get_option(GNF5_OPTION);$s['rankmath_enabled']=1;$s['seo_analyzer_mode']='local';$s['internal_links']=1;update_option(GNF5_OPTION,$s);add_filter('pre_http_request',$mock611,9,3);
 $research=GNF5_Research::gather(array('url'=>'https://research.example.org/Solstice/trial','method'=>'Manual URL'),$cat);
 $a=GNF5_SEO::sanitize_article_data(article6('Solstice'));$a['title']='Solstice laboratory battery trial: research scope and evidence';$a['content_html']=body611(477,3);
 $target=GNF5_SEO::repair_targets($a);v6($target['current_words']===477 && $target['current_keyword_occurrences']===3,'477-word/three-occurrence problem is measured exactly');
 $compact=str_replace('> <','><',$a['content_html']);v6(GNF5_Utils::word_count($compact)===477,'adjacent HTML blocks do not merge words during length checks');
 v6(GNF5_SEO::keyword_occurrences($compact,$a['focus_keyword'])===3,'keyword at an adjacent block boundary is counted');
 v6($target['target_density_percent']===1.5 && $target['target_occurrences_at_suggested_length']===10,'650-word repair requests about ten natural occurrences');
 $b=$a;$b['content_html']=body611(477,5);v6(GNF5_SEO::text_repair_progress($a,$b),'density progress accepted before it crosses the PASS threshold');
 $over=$a;$over['content_html']=body611(477,20);v6(!GNF5_SEO::text_repair_progress($a,$over),'overshooting into keyword stuffing is not density progress');
 $id=draft611($a,$research,$cat);$mode611='partial';v6(GNF5_Runner::repair_scored_post($id,true),'partial density improvement is actually saved after quality review');
 v6(GNF5_SEO::keyword_occurrences(get_post_meta($id,'_gnf5_article_data',true)['content_html'],'Solstice laboratory')===5,'saved partial repair body has five occurrences before TOC composition');
 $mode611='expand';v6(GNF5_Runner::repair_scored_post($id,true),'supported expansion and numbered title applied');
 $saved=get_post_meta($id,'_gnf5_article_data',true);$checks611=GNF5_SEO::text_checks($saved);
 v6(GNF5_Utils::word_count($saved['content_html'])===650,'saved body reaches 650 words');
 v6($checks611['density']['status']==='PASS' && abs(GNF5_SEO::keyword_density($saved['content_html'],$saved['focus_keyword'])-1.5)<0.1,'saved exact phrase density is near 1.5 percent');
 v6($checks611['title_number']['status']==='PASS' && strpos($saved['seo_title'],'12')!==false,'saved SEO title uses verified team count');
 v6(strpos($last611,'MEASURED_REPAIR_TARGETS')!==false && strpos($last611,'target_occurrences_at_suggested_length')!==false,'writer receives numeric repair targets');
 $related=wp_insert_post(array('post_title'=>'Inside the testing program','post_status'=>'publish','post_content'=>'<p>Solstice laboratory evidence is examined here in a separate research discussion.</p>'));$created[]=$related;
 $linked=GNF5_SEO::insert_internal_links($saved['content_html'],$id,$cat);v6(strpos($linked,get_permalink($related))!==false,'relevant published target found from its body despite a different headline');
 $private=wp_insert_post(array('post_title'=>'Private trial notes','post_status'=>'private','post_content'=>'<p>Solstice laboratory evidence.</p>'));$created[]=$private;
 v6(strpos(GNF5_SEO::insert_internal_links($saved['content_html'],$id,$cat),get_permalink($private))===false,'private target excluded');
 $s['internal_links']=0;update_option(GNF5_OPTION,$s);v6(GNF5_SEO::insert_internal_links($saved['content_html'],$id,$cat)===$saved['content_html'] && strpos(get_post_meta($id,'_gnf5_internal_link_note',true),'OFF')!==false,'disabled linking is preserved and explained');$s['internal_links']=1;update_option(GNF5_OPTION,$s);
 // Original keyword is now reused by a different existing post.
 update_post_meta($related,'rank_math_focus_keyword','Solstice laboratory');$mode611='refine';delete_post_meta($id,'_gnf5_seo_repair_stopped');
 v6(GNF5_Runner::repair_scored_post($id,true),'reused broad keyword can be refined with a supported article qualifier');
 $saved=get_post_meta($id,'_gnf5_article_data',true);v6($saved['focus_keyword']==='Solstice laboratory battery trial' && GNF5_SEO::focus_keyword_is_unique($saved['focus_keyword'],$id),'refined keyword is saved and unique');
 foreach(array('title_keyword','title_start','description','slug','introduction','heading','density','length','title_number') as $key)v6(GNF5_SEO::text_checks($saved)[$key]['status']==='PASS','refined keyword keeps '.$key.' correct');
 $bad=$saved;$bad['focus_keyword']='Football betting';v6(!GNF5_SEO::valid_keyword_refinement($a,$bad,$id),'topic-switching keyword rejected');
 $calls=$calls611;GNF5_Runner::optimize_text($id);v6($calls611===$calls,'three-attempt repair cap survives another click');
 update_post_meta($id,'_gnf5_state','awaiting_rankmath');GNF5_RankMath::reset_retry($id);GNF5_RankMath::run($id);$actual=get_post_meta($id,'_gnf5_seo_tests',true);
 foreach(array('keywordDensity','titleHasNumber','keywordNotUsed','linksHasInternal') as $name)v6(isset($actual[$name]) && $actual[$name]['score']===$actual[$name]['maximum'],'real Rank Math passes '.$name);
 v6(isset($actual['lengthContent']) && strpos($actual['lengthContent']['message'],'at least 600')===false,'real Rank Math no longer reports under 600 words');
 v6(get_post_status($id)==='draft' && !get_post_thumbnail_id($id),'all repairs and actual scoring remain Draft with no images');
 // Old cached UNKNOWN reviews can be refreshed by the explicit repair action.
 delete_transient('gnf5_research_text_'.$id);$data=get_post_meta($id,'_gnf5_article_data',true);$data['quality']['originality']['status']='UNKNOWN';update_post_meta($id,'_gnf5_article_data',$data);GNF5_Publish::checkpoint($id);
 v6(GNF5_Runner::improve_seo($id)===true && get_post_meta($id,'_gnf5_article_data',true)['quality']['originality']['status']==='PASS','explicit improvement refreshes expired comparisons and prior UNKNOWN report');
 v6(is_array(get_transient('gnf5_research_text_'.$id)),'refreshed comparison cache is retained');
 $mode611='race';$race611=$id;$r=GNF5_Runner::improve_seo($id);v6(is_wp_error($r) && get_post_field('post_content',$id)==='<p>Human change during evidence refresh.</p>','human edit during research refresh is never overwritten');$race611=0;$mode611='expand';
 $short=$a;$short['title']='Solstice laboratory: understanding the uncertainty before commercial decisions';$short['seo_title']='Solstice laboratory evidence boundaries for readers';$shortId=draft611($short,$research,$cat);$mode611='unchanged';
 $before=get_post_field('post_content',$shortId);$calls=$calls611;GNF5_Runner::optimize_text($shortId);
 v6($calls611===$calls+1 && get_post_field('post_content',$shortId)===$before,'unsupported expansion stops without padding or repeated unchanged rewrites');
 v6(strpos(implode(' ',get_post_meta($shortId,'_gnf5_seo_unresolved',true)),'No supported number')!==false,'unresolved factual title-number reason is retained for the editor');$mode611='expand';
 // Verify the former 100-result cutoff no longer hides an exact older match.
 for($i=0;$i<101;$i++){$p=wp_insert_post(array('post_title'=>'Keyword lookup fixture '.$i,'post_status'=>'draft','meta_input'=>array('rank_math_focus_keyword'=>$i===100?'quasar evidence':'quasar evidence extended '.$i)));$created[]=$p;}
 v6(!GNF5_SEO::focus_keyword_is_unique('quasar evidence'),'exact keyword match beyond first hundred substring candidates is found');
 echo "TOTAL: $checks SEO 6.1.1 checks passed\n";
}finally{
 remove_filter('pre_http_request',$mock611,9);foreach($created as $pid){wp_clear_scheduled_hook(GNF5_RankMath::HOOK,array((int)$pid));delete_transient('gnf5_research_text_'.$pid);wp_delete_post($pid,true);}wp_delete_term($cat,'category');GNF5_Utils::release_lock($cat);update_option(GNF5_OPTION,$backup);update_option('cron',$cron);update_option(GNF5_LOG_OPTION,$logs);
}
