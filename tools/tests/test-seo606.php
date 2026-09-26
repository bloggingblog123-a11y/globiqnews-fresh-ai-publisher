<?php
// Disposable WordPress only. Includes the existing real-analyzer SEO regression.
require __DIR__.'/test-seo601.php';
$checks=0;$backup=get_option(GNF5_OPTION);$created=array();$mode='normal';
function body606($words,$opening=true){
    $body='<p>'.($opening?'Solstice laboratory ':'Reader context ').implode(' ',array_fill(0,38,'evidence')).'</p>';
    $body.='<h2>Solstice laboratory scope</h2>';
    while(GNF5_Utils::word_count($body)<$words){
        $remaining=$words-GNF5_Utils::word_count($body);
        $chunk=min(50,$remaining);$tokens=array_fill(0,$chunk,'context');
        if($chunk>=2){$tokens[0]='Solstice';$tokens[1]='laboratory';}
        $body.=' <p>'.implode(' ',$tokens).'</p> ';
    }
    return $body;
}
$a=GNF5_SEO::sanitize_article_data(article6('Solstice'));$a['content_html']=body606(437,false);
$b=$a;$b['content_html']=body606(437,true);
v6(GNF5_SEO::text_checks($a)['introduction']['status']==='REVIEW','heading keyword alone does not pass opening paragraph');
v6(GNF5_SEO::text_checks($b)['introduction']['status']==='PASS','opening paragraph keyword passes');
v6(GNF5_SEO::text_repair_progress($a,$b),'opening repair is accepted');
$a=$b;$b['content_html']=body606(520,true);
v6(count(GNF5_SEO::text_feedback($a))===count(GNF5_SEO::text_feedback($b)),'partial length expansion has same number of failed checks');
v6(GNF5_SEO::text_repair_progress($a,$b),'437 to 520 words is accepted for continued bounded repair');
$a=$b;$b['content_html']=body606(650,true);
v6(GNF5_SEO::text_repair_progress($a,$b) && GNF5_SEO::text_checks($b)['length']['status']==='PASS','continued expansion crosses 600');
$bad=$b;$bad['content_html']=body606(700,false);$bad['seo_title']='Solstice laboratory: 12 engineers';
v6(!GNF5_SEO::text_repair_progress($b,$bad),'title-number gain cannot undo a passing introduction');
$bad=$b;$bad['content_html']=body606(437,true);$bad['seo_title']='Solstice laboratory: 12 engineers';
v6(!GNF5_SEO::text_repair_progress($b,$bad),'title-number gain cannot undo minimum length');
$bad=$b;$bad['focus_keyword']='Other topic';
v6(!GNF5_SEO::text_repair_progress($b,$bad),'focus keyword cannot drift');
v6(!GNF5_SEO::text_repair_progress($b,$b),'unchanged article is rejected');
$number=$b;$number['seo_title']='Solstice laboratory: 12 engineers';
v6(GNF5_SEO::text_repair_progress($b,$number),'factual title number can improve a neutral title');
v6(GNF5_SEO::text_checks($number)['title_number']['status']==='PASS','title number detected');
v6(strpos(GNF5_Writer::text_repair_instruction(),'fact sheet')!==false && strpos(GNF5_Writer::text_repair_instruction(),'arbitrary year')!==false,'prompt requires evidence for title number');
v6(strpos(GNF5_Writer::text_repair_instruction(),'short_reason')!==false,'insufficient evidence requires explanation instead of padding');
$term=wp_insert_term('SEO606 '.wp_generate_password(5,false),'category');$cat=(int)$term['term_id'];
$calls606=0;$mock606=function($pre,$args,$url)use(&$calls606){
 if(strpos($url,'generativelanguage.googleapis.com')!==false){
  $j=json_decode($args['body'],true);$p=$j['contents'][0]['parts'][0]['text'];
  if(strpos($p,'TASK: OPTIMIZE_DRAFT_SEO')!==false){
   $calls606++;$out=json_decode(explode("\nGENUINE_SEO_FEEDBACK:\n",explode("\nCURRENT_ARTICLE:\n",$p,2)[1],2)[0],true);
   $out['seo_title']='Solstice laboratory: 12 engineers';$out['content_html']=body606(650,true);
   $out['image_prompts']=array('unwanted change');$out['image_alts']=array('unwanted change');
   return response6(wp_json_encode(array('candidates'=>array(array('content'=>array('parts'=>array(array('text'=>wp_json_encode($out)))))))),200,'application/json');
  }
 }
 return $pre!==false?$pre:$GLOBALS['mock']($pre,$args,$url);
};
try{
 setup6($cat,2);$s=get_option(GNF5_OPTION);$s['rankmath_enabled']=1;update_option(GNF5_OPTION,$s);
 add_filter('pre_http_request',$mock606,9,3);
 $research=GNF5_Research::gather(array('url'=>'https://research.example.org/Solstice/trial','method'=>'Manual URL'),$cat);
 $article=GNF5_SEO::sanitize_article_data(article6('Solstice'));$article['content_html']=body606(437,false);
 $id=draft601($cat,$article,$research);update_post_meta($id,'_gnf5_seo_repair_attempts',3);update_post_meta($id,'_gnf5_seo_repair_stopped',hash('sha256',serialize($article)));
 v6(GNF5_Runner::repair_scored_post($id,true),'old exhausted draft can use the improved policy');
 $saved=get_post_meta($id,'_gnf5_article_data',true);$check=GNF5_SEO::text_checks($saved);
 foreach(array('introduction','length','title_number') as $key)v6($check[$key]['status']==='PASS','saved draft passes '.$key);
 v6($saved['image_prompts']===$article['image_prompts'] && $saved['image_alts']===$article['image_alts'],'text repair preserves image prompts and alt metadata');
 v6(get_post_status($id)==='draft' && !get_post_thumbnail_id($id),'repair leaves status Draft and creates no image');
 v6(get_post_meta($id,'rank_math_seo_score',true)==='','preflight does not manufacture a Rank Math score');
 update_post_meta($id,'_gnf5_seo_repair_attempts',3);$calls=$calls606;GNF5_Runner::optimize_text($id);
 v6($calls606===$calls,'new-policy three-attempt limit is not reset on every click');
 update_post_meta($id,'_gnf5_state','awaiting_rankmath');GNF5_RankMath::reset_retry($id);GNF5_RankMath::run($id);
 $actual=get_post_meta($id,'_gnf5_seo_tests',true);
 v6(GNF5_Publish::score($id)!==null,'real Rank Math evaluates repaired fixture');
 foreach(array('keywordIn10Percent','titleHasNumber','keywordInTitle','keywordInMetaDescription','keywordInPermalink','keywordInContent','titleStartWithKeyword','contentHasShortParagraphs') as $name)
  v6(isset($actual[$name]) && $actual[$name]['score']===$actual[$name]['maximum'],'actual Rank Math passes '.$name);
 v6(isset($actual['lengthContent']) && $actual['lengthContent']['score']>0 && strpos($actual['lengthContent']['message'],'at least 600')===false,'actual Rank Math no longer reports under 600 words');
 v6(get_post_status($id)==='draft','actual analyzer does not publish repaired article');
 echo "TOTAL: $checks SEO 6.0.6 checks passed\n";
}finally{
 remove_filter('pre_http_request',$mock606,9);foreach($created as $id){wp_clear_scheduled_hook(GNF5_RankMath::HOOK,array((int)$id));wp_delete_post($id,true);}
 wp_delete_term($cat,'category');GNF5_Utils::release_lock($cat);update_option(GNF5_OPTION,$backup);
}
