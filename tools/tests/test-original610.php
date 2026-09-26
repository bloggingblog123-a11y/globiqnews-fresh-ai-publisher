<?php
// Disposable WordPress only; external providers are controlled fixtures.
require __DIR__.'/test-master-pipeline.php';
$checks=0;$created=array();$backup=get_option(GNF5_OPTION);$history=get_option('gnf5_topic_history');$cron=get_option('cron');$logs=get_option(GNF5_LOG_OPTION);
$term=wp_insert_term('Original610 '.wp_generate_password(5,false),'category');$cat=(int)$term['term_id'];$case='Solstice';$mode='normal';
$titleMode='valid';$qualityMode='normal';$titleCalls=0;$writeCalls=0;$racePost=0;$captured=array();
$mock610=function($pre,$args,$url)use(&$titleMode,&$qualityMode,&$titleCalls,&$writeCalls,&$racePost,&$captured,$mock){
 if(strpos($url,'generativelanguage.googleapis.com')!==false){
  $request=json_decode($args['body'],true);$p=$request['contents'][0]['parts'][0]['text'];$captured[]=$p;
  if(strpos($p,'TASK: WRITE_ORIGINAL_ARTICLE')!==false)$writeCalls++;
  if(strpos($p,'TASK: ORIGINAL_TITLE')!==false){
   $titleCalls++;$a=article6('Solstice');$out=array('title'=>$a['title'],'seo_title'=>$a['seo_title']);
   if($titleMode==='copy' || ($titleMode==='retry' && $titleCalls===1))$out=array('title'=>'Solstice technology laboratory announces battery trial','seo_title'=>'Solstice technology laboratory announces battery trial');
   if($titleMode==='replacement')$out=array('title'=>'Solstice laboratory: interpreting the limits of an early trial','seo_title'=>'Solstice laboratory: research scope and unanswered questions');
   if($titleMode==='race' && $racePost)wp_update_post(array('ID'=>$racePost,'post_title'=>'Human title changed during generation'));
   return response6(wp_json_encode(array('candidates'=>array(array('content'=>array('parts'=>array(array('text'=>wp_json_encode($out)))))))),200,'application/json');
  }
  $response=$mock($pre,$args,$url);
  if(strpos($p,'TASK: REVIEW_ARTICLE_QUALITY')!==false && $qualityMode!=='normal'){
   $envelope=json_decode($response['body'],true);$review=json_decode($envelope['candidates'][0]['content']['parts'][0]['text'],true);
   if($qualityMode==='structure')foreach($review['structural_comparisons'] as &$r){$r['imitated']=true;$r['reason']='Same paragraph ordering, lead and conclusion despite paraphrased wording.';}unset($r);
   if($qualityMode==='unsupported')foreach($review['claim_checks'] as &$r){$r['supported']=false;$r['reason']='Unsupported title promise.';}unset($r);
   if($qualityMode==='missing_structure')$review['structural_comparisons']=array();
   $envelope['candidates'][0]['content']['parts'][0]['text']=wp_json_encode($review);$response['body']=wp_json_encode($envelope);
  }
  return $response;
 }
 return $mock($pre,$args,$url);
};
try{
 setup6($cat,2);add_filter('pre_http_request',$mock610,9,3);
 $research=GNF5_Research::gather(array('url'=>'https://research.example.org/Solstice/trial','title'=>'Discovery signal sentinel headline','fallback_text'=>'DISCOVERY_SNIPPET_SENTINEL This is a lengthy feed description that must not become a final article description.','method'=>'RSS'),$cat);
 v6(!is_wp_error($research),'research collected from safe source fixture');
 v6(in_array('Discovery signal sentinel headline',$research['source_titles'],true),'discovery headline retained alongside fetched title');
 $wire=wp_json_encode(GNF5_Research::writer_facts($research));
 v6(strpos($wire,'SOURCE_PROSE_SENTINEL')===false && strpos($wire,'Discovery signal sentinel')===false && strpos($wire,'DISCOVERY_SNIPPET_SENTINEL')===false,'writer facts exclude source prose, titles and feed descriptions');
 $r=array('sources'=>array(array('id'=>'S1','title'=>'Samsung Launches Galaxy X in India at ₹39,999')));
 $a=array('title'=>'Samsung Launches Galaxy X in India at ₹39,999','seo_title'=>'Samsung Launches Galaxy X in India at ₹39,999');
 $check=GNF5_Titles::check($a,$r);v6($check['status']==='FAIL' && $check['exact_source_match'],'exact source title rejected');
 $a['title']='SAMSUNG launches Galaxy X in India at ₹39,999!';$a['seo_title']=$a['title'];
 v6(GNF5_Titles::check($a,$r)['exact_source_match'],'punctuation/capitalization differences rejected');
 $a['title']='Samsung Galaxy X Launched in India at ₹39,999';$a['seo_title']=$a['title'];
 v6(GNF5_Titles::check($a,$r)['near_source_match'],'reordered launch headline rejected as near duplicate');
 $a['title']='Samsung Galaxy X India Launch Explained: Price, Upgrades and Availability';$a['seo_title']=$a['title'];
 v6(GNF5_Titles::check($a,$r)['status']==='PASS','distinct framing passes lexical title check, subject to factual review');
 $a['seo_title']=$r['sources'][0]['title'];v6(GNF5_Titles::check($a,$r)['status']==='FAIL','unique visible title cannot hide copied SEO title');
 v6(GNF5_Titles::normalize('CAFÉ “Trial”!')===GNF5_Titles::normalize('café trial'),'Unicode and quotation normalization');
 $existing=wp_insert_post(array('post_title'=>'A completely distinct existing site title','post_status'=>'draft'));$created[]=$existing;
 $a=array('title'=>'A completely distinct existing site title!','seo_title'=>'A completely distinct existing site title!');
 v6(GNF5_Titles::check($a,$research)['existing_site_match'],'existing draft normalized title detected');
 wp_trash_post($existing);v6(GNF5_Titles::check($a,$research)['existing_site_match'],'trash title still compared');
 $key='gnf5_title_claim_'.hash('sha256',GNF5_Titles::normalize($a['title']));$claim=array('time'=>time(),'token'=>'OTHER-WORKER','title'=>$a['title']);add_option($key,$claim,'',false);
 v6(!GNF5_Titles::claim($a),'atomic claim refuses another worker');delete_option($key);
 $fresh=array('title'=>'A pending generation about careful evidence handling','seo_title'=>'A pending generation about careful evidence handling');
 $key='gnf5_title_claim_'.hash('sha256',GNF5_Titles::normalize($fresh['title']));add_option($key,array('time'=>time(),'token'=>'OTHER-WORKER','title'=>$fresh['title']),'',false);
 v6(GNF5_Titles::check($fresh,$research)['existing_site_match'],'live generation title appears in comparisons');delete_option($key);
 v6(GNF5_Titles::claim($fresh),'first title claim succeeds');GNF5_Titles::release();v6(get_option($key,false)===false,'owned title claim released');
 $titleMode='retry';$titleCalls=0;$plan=GNF5_Writer::plan($research,$cat);$titles=GNF5_Titles::generate($research,$plan);
 v6($titles['title_check']['status']==='PASS' && $titles['title_attempts']===2,'copied first title regenerates from facts and angle');GNF5_Titles::release();
 $titleMode='copy';$titleCalls=0;$titles=GNF5_Titles::generate($research,$plan);
 v6($titleCalls===5 && $titles['title_check']['status']==='FAIL','five failed title attempts require manual review');GNF5_Titles::release();
 $titleMode='valid';$article=GNF5_SEO::sanitize_article_data(article6('Solstice'));$article['plan']=$plan;
 $copy=$article;$copy['content_html']='<p>A distinctive narrative sentence follows the unexpected journey through this remote valley.</p>'.str_repeat('<p>Independent unrelated material for a long comparison fixture.</p>',100);
 v6(GNF5_Quality::originality($copy,array(array('id'=>'S1','text'=>'A distinctive narrative sentence follows the unexpected journey through this remote valley.','headings'=>array())))['status']==='FAIL','one copied narrative sentence fails even when total overlap is low');
 $copy=$article;$copy['meta_description']=$research['discovery_snippets'][0];
 v6(GNF5_Quality::metadata_originality($copy,$research)['status']==='FAIL','RSS description leakage into meta detected');
 $copy=$article;$copy['excerpt']=$research['sources'][0]['text'];
 v6(GNF5_Quality::metadata_originality($copy,$research)['status']==='FAIL','source prose leakage into excerpt detected');
 $qualityMode='structure';$review=GNF5_Quality::evaluate($article,$research,true);
 v6($review['originality']['status']==='FAIL','semantic paragraph-order imitation fails despite independent wording');
 $qualityMode='missing_structure';$review=GNF5_Quality::evaluate($article,$research,true);
 v6($review['originality']['status']==='UNKNOWN','missing semantic comparisons cannot report PASS');
 $qualityMode='normal';$review=GNF5_Quality::evaluate($article,$research,true);
 v6($review['originality']['status']==='PASS' && $review['facts']['status']==='PASS','independent fixture with complete factual review passes');
 $neutral=$article;$neutral['seo_title']='Solstice laboratory: scope and context';$neutralChecks=GNF5_SEO::text_checks($neutral);
 v6(!$neutralChecks['sentiment']['repair'] && !$neutralChecks['power_word']['repair'] && $neutralChecks['title_number']['repair'],'neutral title words stay optional while factual numbers are requested');
 $badReview=$review;$badReview['originality']['status']='FAIL';v6(!GNF5_Quality::permits_seo($badReview),'failed originality blocks automatic text optimization');
 v6(count($review['added_value']['evidence'])===1,'repeating same passage in five categories does not multiply added value');
 $invented=$article;$invented['seo_title']='Solstice laboratory employs 9999 experts';
 v6(GNF5_Quality::evaluate($invented,$research,true)['facts']['status']==='FAIL','unsupported number fails even if mocked AI says supported');
 $conflict=$research;$conflict['conflicts']=array(array('detail'=>'Conflicting team counts','source_ids'=>array('S1','S2')));
 v6(GNF5_Quality::evaluate($article,$conflict,true)['facts']['status']==='FACT CONFLICT — MANUAL REVIEW REQUIRED','source conflicts remain explicit');
 $id=GNF5_Publish::save(array('post_title'=>$article['title'],'post_content'=>$article['content_html'],'post_excerpt'=>$article['excerpt'],'post_category'=>array($cat),'meta_input'=>array('_gnf5_generated_by'=>'fresh-v5','_gnf5_article_data'=>$article)),true);$created[]=$id;
 $s=get_option(GNF5_OPTION);$s['rankmath_enabled']=1;update_option(GNF5_OPTION,$s);GNF5_SEO::save_rank_math($id,$article);GNF5_Research::store($id,$research);GNF5_Publish::checkpoint($id);
 $stored=get_post_meta($id,'_gnf5_research',true);v6(!isset($stored['sources'][0]['text']) && !isset($stored['discovery_snippets']) && isset($stored['fact_sheet']),'persistent research uses structured facts, not full source prose');
 $held=$article;$held['quality']=$badReview;update_post_meta($id,'_gnf5_article_data',$held);GNF5_Publish::checkpoint($id);$beforeCalls=count($captured);GNF5_Runner::optimize_text($id);
 v6(count($captured)===$beforeCalls,'failed-originality draft makes no automatic SEO provider request');update_post_meta($id,'_gnf5_article_data',$article);GNF5_Publish::checkpoint($id);
 v6(isset(get_transient('gnf5_research_text_'.$id)['_discovery_snippets']),'temporary comparison cache retains discovery snippets');
 $beforeBody=get_post_field('post_content',$id);$beforeSlug=get_post_field('post_name',$id);$titleMode='replacement';
 $result=GNF5_Titles::regenerate_post($id);v6($result===true,'title-only regeneration applies passing factual original title');
 v6(get_post_field('post_content',$id)===$beforeBody && get_post_field('post_name',$id)===$beforeSlug,'title-only regeneration preserves body and slug');
 v6(get_post_status($id)==='draft' && !get_post_thumbnail_id($id),'title-only regeneration keeps Draft and image state');
 $beforeTitle=get_the_title($id);$qualityMode='unsupported';$result=GNF5_Titles::regenerate_post($id);v6(is_wp_error($result) && get_the_title($id)===$beforeTitle,'factual title failure does not overwrite old title');$qualityMode='normal';
 wp_update_post(array('ID'=>$id,'post_title'=>$research['sources'][0]['title']));$beforeBody=get_post_field('post_content',$id);$result=GNF5_Titles::recheck_post($id);
 v6($result['status']==='FAIL' && get_post_field('post_content',$id)===$beforeBody,'manual copied title can be rechecked without overwriting it or body');
 $racePost=$id;$titleMode='race';$result=GNF5_Titles::regenerate_post($id);v6(is_wp_error($result) && get_the_title($id)==='Human title changed during generation','concurrent manual title edit survives regeneration');$racePost=0;
 $titleMode='valid';$writeCalls=0;$qualityMode='structure';$s['originality_retries']=2;update_option(GNF5_OPTION,$s);
 $failed=GNF5_Writer::create_article($research,$cat,$id);v6(is_wp_error($failed) && $writeCalls===3,'structural failure discards body with initial plus two regeneration attempts');GNF5_Titles::release();$qualityMode='normal';
 $titlePrompts=array_filter($captured,function($p){return strpos($p,'TASK: ORIGINAL_TITLE')!==false;});
 foreach($titlePrompts as $p)v6(strpos($p,'SOURCE_PROSE_SENTINEL')===false && strpos($p,'Discovery signal sentinel')===false,'title writer never receives source prose/headlines');
 $normal=wp_insert_post(array('post_title'=>'Normal administrator post','post_status'=>'publish','post_content'=>'Independent manual post.'));$created[]=$normal;
 v6(get_post_status($normal)==='publish','normal WordPress publishing is unaffected');
 update_post_meta($id,'rank_math_seo_score',100);GNF5_Publish::maybe_publish($id);v6(get_post_status($id)==='draft','Rank Math 100 cannot publish a generated draft');
 wp_set_current_user(1);ob_start();GNF5_Admin::report_box(get_post($id));$html=ob_get_clean();
 v6(strpos($html,'Regenerate Title Only')!==false && strpos($html,'View Source Headlines')!==false && strpos($html,'Source Titles Compared')!==false,'review panel exposes title report and actions');
 echo "TOTAL: $checks originality/title checks passed\n";
}finally{
 GNF5_Titles::release();remove_filter('pre_http_request',$mock610,9);foreach($created as $id){wp_clear_scheduled_hook(GNF5_RankMath::HOOK,array((int)$id));delete_transient('gnf5_research_text_'.$id);wp_delete_post($id,true);}
 wp_delete_term($cat,'category');GNF5_Utils::release_lock($cat);update_option(GNF5_OPTION,$backup);update_option('gnf5_topic_history',$history);update_option('cron',$cron);update_option(GNF5_LOG_OPTION,$logs);
}
