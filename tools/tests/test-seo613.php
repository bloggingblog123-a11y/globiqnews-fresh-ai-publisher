<?php
// Disposable WordPress, controlled provider replies, actual installed Rank Math.
require __DIR__.'/test-master-pipeline.php';
function body611($words,$occ,$kw='Solstice laboratory'){
 $body='<h2>'.$kw.' evidence</h2> ';$remaining=$words-GNF5_Utils::word_count($body);$left=$occ-1;
 while($remaining>0){$size=min(60,$remaining);$tokens=array();if($left>0 && $size>=GNF5_Utils::word_count($kw)){$tokens=explode(' ',$kw);$left--;}
  while(count($tokens)<$size)$tokens[]=array('battery','trial','research','scope','evidence','interpretation','limits','context')[count($tokens)%8];
  $body.='<p>'.implode(' ',$tokens).'.</p> ';$remaining-=$size;
 }return $body;
}
function draft611($a,$r,$cat){global $created;$id=GNF5_Publish::save(array('post_title'=>$a['title'],'post_name'=>$a['slug'],'post_content'=>$a['content_html'],'post_excerpt'=>$a['excerpt'],'post_category'=>array($cat),'meta_input'=>array('_gnf5_generated_by'=>'fresh-v5','_gnf5_article_data'=>$a)),true);$created[]=$id;GNF5_Research::store($id,$r);GNF5_SEO::save_rank_math($id,$a);GNF5_Publish::checkpoint($id);return $id;}
$checks=0;$created=array();$backup=get_option(GNF5_OPTION);$cron=get_option('cron');$logs=get_option(GNF5_LOG_OPTION);$case='Solstice';$mode='normal';
$term=wp_insert_term('SEO613 '.wp_generate_password(7,false),'category');$cat=(int)$term['term_id'];
$calls613=0;$titleCalls613=0;$order613=array();$mode613='retry';$prompt613='';
$mock613=function($pre,$args,$url)use(&$calls613,&$titleCalls613,&$order613,&$mode613,&$prompt613){
 if(strpos($url,'generativelanguage.googleapis.com')!==false){$p=json_decode($args['body'],true)['contents'][0]['parts'][0]['text'];
  if(strpos($p,'TASK: WRITE_ORIGINAL_ARTICLE')!==false)$order613[]='body';
  if(strpos($p,'TASK: ORIGINAL_TITLE')!==false){
   $order613[]='title';$titleCalls613++;$a=article6('Solstice');
   $out=array('title'=>$a['title'],'seo_title'=>$titleCalls613===1?'12 engineers report successful battery tests':'Solstice laboratory: 12 engineers report successful testing');
   v6(strpos($p,'REQUIRED SEO TITLE')!==false,'independent title receives the final focus keyword');
   return response6(wp_json_encode(array('candidates'=>array(array('content'=>array('parts'=>array(array('text'=>wp_json_encode($out)))))))),200,'application/json');
  }
  if(strpos($p,'TASK: OPTIMIZE_DRAFT_SEO')!==false){$calls613++;$prompt613=$p;$a=json_decode(explode("\nGENUINE_SEO_FEEDBACK:\n",explode("\nCURRENT_ARTICLE:\n",$p,2)[1],2)[0],true);
   if($mode613!=='unchanged' && !($mode613==='retry' && $calls613===1)){
    $a['seo_title']='Solstice laboratory: 12 engineers report successful testing';
    $a['meta_description']='Solstice laboratory testing: what the 12 engineers studied and what the completed trial can establish.';
    $a['content_html']=body611(650,10);
   }
   return response6(wp_json_encode(array('candidates'=>array(array('content'=>array('parts'=>array(array('text'=>wp_json_encode($a)))))))),200,'application/json');
  }
 }
 return $GLOBALS['mock']($pre,$args,$url);
};
try{
 setup6($cat,2);$s=get_option(GNF5_OPTION);$s['rankmath_enabled']=1;$s['internal_links']=1;$s['auto_source_links']=0;$s['seo_analyzer_mode']='local';update_option(GNF5_OPTION,$s);add_filter('pre_http_request',$mock613,9,3);
 $research=GNF5_Research::gather(array('url'=>'https://research.example.org/Solstice/trial','method'=>'Manual URL'),$cat);
 $research['facts'][]=array('id'=>'F6','kind'=>'event','subject'=>'Solstice','detail'=>'The completed test was successful.','evidence'=>array(array('source'=>$research['sources'][0]['id'],'excerpt'=>'The completed test was successful.')));
 $research['sources'][0]['text'].=' The completed test was successful.';
 $generated=GNF5_Writer::create_article($research,$cat);
 v6(!is_wp_error($generated) && $order613[0]==='body' && $order613[1]==='title','body and keyword are chosen before the final independent title');
 v6($titleCalls613===2 && GNF5_SEO::begins_with_exact_phrase($generated['seo_title'],$generated['focus_keyword']),'missing-keyword first title is rejected and corrected');GNF5_Titles::release();
 $a=GNF5_SEO::sanitize_article_data(article6('Solstice'));$a['title']='Solstice laboratory: evidence from the trial';$a['seo_title']='12 engineers report successful battery tests';$a['meta_description']='Results from the completed research program and its limitations.';$a['content_html']=body611(556,2);
 $before=GNF5_SEO::text_checks($a);foreach(array('title_keyword','title_start','description','length','density') as $k)v6($before[$k]['status']==='REVIEW','reproduces screenshot failure: '.$k);
 v6(abs(GNF5_SEO::keyword_density($a['content_html'],$a['focus_keyword'])-0.36)<0.01,'reproduces 0.36 percent from two occurrences in 556 words');
 $id=draft611($a,$research,$cat);delete_post_meta($id,'_gnf5_rankmath_written');
 $published=wp_insert_post(array('post_status'=>'publish','post_title'=>'Other research in this category','post_content'=>'<p>An unrelated but public story.</p>','post_category'=>array($cat)));$created[]=$published;
 $linked=GNF5_SEO::insert_internal_links($a['content_html'],$id,$cat);
 v6(strpos($linked,get_category_link($cat))!==false && strpos($linked,get_permalink($published))===false,'category navigation replaces an unrelated-article fallback');
 v6(GNF5_SEO::insert_internal_links($linked,$id,$cat)===$linked,'category fallback is idempotent');
 $off=$s;$off['internal_links']=0;update_option(GNF5_OPTION,$off);v6(GNF5_SEO::insert_internal_links($a['content_html'],$id,$cat)===$a['content_html'],'internal-links OFF remains respected');update_option(GNF5_OPTION,$s);
 GNF5_Runner::optimize_text($id);
 v6($calls613===2,'one unchanged AI response does not permanently stop the remaining bounded attempts');
 v6(strpos($prompt613,'Previous candidate was unchanged')!==false && strpos($prompt613,'VALIDATION ORDER')!==false,'next attempt receives ordered targets and rejection feedback');
 $saved=GNF5_SEO::checklist($id);foreach(array('title_keyword','title_start','description','slug','introduction','heading','length','density','sentiment','power_word','title_number','paragraphs','internal_links') as $key)v6($saved[$key]['status']==='PASS','persisted WordPress fields pass '.$key);
 v6(get_post_meta($id,'rank_math_title',true)===get_post_meta($id,'_gnf5_article_data',true)['seo_title'],'legacy metadata matching the checkpoint is updated with the repaired article');
 v6(get_post_status($id)==='draft' && strpos(get_post_field('post_content',$id),'<img')===false && !get_post_thumbnail_id($id),'repair remains Draft with images unchanged');
 update_post_meta($id,'_gnf5_state','awaiting_rankmath');GNF5_RankMath::reset_retry($id);GNF5_RankMath::run($id);$actual=get_post_meta($id,'_gnf5_seo_tests',true);
 foreach(array('keywordInTitle','keywordInMetaDescription','titleStartWithKeyword','keywordDensity','linksHasInternal') as $key)v6(isset($actual[$key]) && $actual[$key]['score']===$actual[$key]['maximum'],'actual Rank Math passes '.$key);
 v6(isset($actual['lengthContent']) && strpos($actual['lengthContent']['message'],'at least 600')===false,'actual Rank Math clears minimum length');
 $message=GNF5_Runner::seo_result_message($id);v6(strpos($message,'Keyword in description: PASS')!==false && strpos($message,'Keyword density: PASS')!==false,'repair result reports the requested saved metadata and density checks');
 $a['title']='Solstice laboratory: a separate unchanged test';$unchanged=draft611($a,$research,$cat);$mode613='unchanged';$start=$calls613;GNF5_Runner::optimize_text($unchanged);
 v6($calls613-$start===3 && get_post_meta($unchanged,'_gnf5_text_seo_status',true)==='needs_review','three deficient responses stop with unmet targets, never a false PASS');
 $start=$calls613;GNF5_Runner::optimize_text($unchanged);v6($calls613===$start,'another click cannot create an endless retry cycle');
 $a['title']='Solstice laboratory: protected metadata example';$conflict=draft611($a,$research,$cat);delete_post_meta($conflict,'_gnf5_rankmath_written');update_post_meta($conflict,'rank_math_title','A separately entered editor title');GNF5_Publish::checkpoint($conflict);$start=$calls613;$oldBody=get_post_field('post_content',$conflict);
 v6(!GNF5_Runner::repair_scored_post($conflict,true) && $calls613===$start && get_post_field('post_content',$conflict)===$oldBody && get_post_meta($conflict,'rank_math_title',true)==='A separately entered editor title','unowned conflicting metadata blocks inconsistent text rewrites');
 $a['seo_title']='Solstice laboratoryX testing';v6(GNF5_SEO::text_checks($a)['title_start']['status']==='REVIEW','title-start check requires a complete exact phrase');
 echo "TOTAL: $checks SEO 6.1.3 checks passed\n";
}finally{
 GNF5_Titles::release();remove_filter('pre_http_request',$mock613,9);foreach($created as $pid){wp_clear_scheduled_hook(GNF5_RankMath::HOOK,array((int)$pid));delete_transient('gnf5_research_text_'.$pid);wp_delete_post($pid,true);}wp_delete_term($cat,'category');GNF5_Utils::release_lock($cat);update_option(GNF5_OPTION,$backup);update_option('cron',$cron);update_option(GNF5_LOG_OPTION,$logs);
}
