<?php
$_SERVER['HTTP_HOST']='globiqnews.localhost:8097';$_SERVER['REQUEST_URI']='/';
$test_root=getenv('GNF5_TEST_WP_ROOT');
if(!$test_root || !is_file($test_root.'/wp-load.php'))exit('Set GNF5_TEST_WP_ROOT to the disposable WordPress directory.');
require $test_root.'/wp-load.php';
if(wp_get_environment_type()!=='local' || strpos(home_url(),'globiqnews.localhost')===false)exit('Refusing to modify a non-test website.');
function check_repair($yes,$message){if(!$yes)throw new Exception('FAIL: '.$message);echo 'PASS: '.$message.PHP_EOL;}
$f=json_decode(file_get_contents(__DIR__.'/rankmath-fixtures.json'),true);
$v=$f[84]['values'];
$article=array('title'=>$v['title'],'seo_title'=>$v['title'],'slug'=>'solar-energy-benefits','focus_keyword'=>'solar energy',
 'meta_description'=>$v['description'],'excerpt'=>$v['description'],'content_html'=>$v['content'],
 'tags'=>array('solar','energy','home','installation','technology'),'image_prompts'=>array('Original illustration of solar panels','Original illustration of home battery'),
 'image_alts'=>array('solar energy rooftop installation','solar energy home battery'));
$article['title']=$article['seo_title']='Solar Energy 7 Powerful Benefits for Your Home';
$article['content_html'].='<h2>Solar Energy Installation</h2><p>Review the property and household requirements.</p><h2>Planning and Maintenance</h2><p>Arrange professional checks and maintain the equipment.</p>';
$s=GNF5_Utils::settings();$s['auto_publish_enabled']=1;$s['gemini_api_key']='LOCAL-TEST-ONLY';$s['image_enabled']=1;update_option(GNF5_OPTION,$s);
$requests=0;
add_filter('pre_http_request',function($pre,$args,$url)use($article,&$requests){
 if(strpos($url,'generativelanguage.googleapis.com')===false)return $pre;
 $requests++;
 return array('response'=>array('code'=>200,'message'=>'OK'),'headers'=>array(),'body'=>wp_json_encode(array('candidates'=>array(array('content'=>array('parts'=>array(array('text'=>wp_json_encode($article)))))))));
},10,3);
$ids=array();$uploads=wp_upload_dir();wp_mkdir_p($uploads['path']);
for($i=0;$i<2;$i++){
 $image=imagecreatetruecolor(64,64);$color=imagecolorallocate($image,30+$i*80,100,120);imagefill($image,0,0,$color);
 $file=$uploads['path'].'/gnf5-test-'.$i.'.png';imagepng($image,$file);imagedestroy($image);
 $aid=wp_insert_attachment(array('post_title'=>'Local SEO image fixture '.$i,'post_mime_type'=>'image/png','guid'=>$uploads['url'].'/gnf5-test-'.$i.'.png'),$file);
 update_post_meta($aid,'_wp_attachment_metadata',array('width'=>64,'height'=>64,'file'=>_wp_relative_upload_path($file)));
 update_post_meta($aid,'_wp_attachment_image_alt',$article['image_alts'][$i]);$ids[]=$aid;
}
$id=wp_insert_post(array('post_title'=>$f[76]['values']['title'],'post_content'=>$f[76]['values']['content'],'post_name'=>'solar-energy-benefits','post_status'=>'draft','post_author'=>1));
foreach(array('_gnf5_generated_by'=>'fresh-v5','_gnf5_state'=>'awaiting_rankmath','_gnf5_article_data'=>$article,'_gnf5_image_ids'=>$ids,'_gnf5_source_facts'=>wp_strip_all_tags($article['content_html'])) as $k=>$value)update_post_meta($id,$k,$value);
GNF5_SEO::save_rank_math($id,$article);
update_post_meta($id,'_gnf5_seo_repair_source',GNF5_Publish::fingerprint($id));
GNF5_RankMath::run($id);
check_repair($requests===1,'Actual writer repair requested once with mocked Gemini transport');
check_repair((int)get_post_meta($id,'_gnf5_seo_repair_attempts',true)===1,'Repair budget recorded');
check_repair(GNF5_Publish::score($id)>=80&&get_post_status($id)==='publish','Repaired content re-analyzed and published with real score '.GNF5_Publish::score($id));
check_repair(get_post_meta($id,'_gnf5_image_ids',true)===$ids,'Two existing images reused');
GNF5_RankMath::run($id);check_repair($requests===1,'No repeat repair after success');
$custom=wp_insert_post(array('post_title'=>'Custom metadata test','post_status'=>'draft'));
GNF5_SEO::save_rank_math($custom,$article);update_post_meta($custom,'rank_math_title','My customized SEO title');
GNF5_SEO::save_rank_math($custom,array_merge($article,array('seo_title'=>'Solar Energy 8 Powerful Benefits')));
check_repair(get_post_meta($custom,'rank_math_title',true)==='My customized SEO title','Custom SEO title preserved');
$s['auto_publish_enabled']=0;$s['gemini_api_key']='';update_option(GNF5_OPTION,$s);
$draft=wp_insert_post(array('post_title'=>$v['title'],'post_content'=>$v['content'],'post_name'=>'solar-energy-benefits','post_status'=>'draft'));
foreach(array('_gnf5_generated_by'=>'fresh-v5','_gnf5_state'=>'awaiting_rankmath','rank_math_title'=>$v['title'],'rank_math_description'=>$v['description'],'rank_math_focus_keyword'=>$v['keyword']) as $k=>$value)update_post_meta($draft,$k,$value);
set_post_thumbnail($draft,$ids[0]);GNF5_RankMath::run($draft);check_repair(GNF5_RankMath::fresh($draft),'Image score receipt initially fresh');
update_post_meta($ids[0],'_wp_attachment_image_alt','Changed by editor');check_repair(!GNF5_RankMath::fresh($draft),'Featured image ALT edit invalidates old score');
GNF5_RankMath::run($draft);
$s['auto_publish_enabled']=1;update_option(GNF5_OPTION,$s);
add_filter('wp_insert_post_data',function($data,$args)use($draft){if(($args['ID']??0)===$draft&&$data['post_status']==='publish')$data['post_content'].='<p>A concurrent change.</p>';return $data;},999,2);
check_repair(!GNF5_Publish::maybe_publish($draft)&&get_post_status($draft)==='draft','Content changed by a publication filter cannot bypass gate');
$s['gemini_api_key']='LOCAL-TEST-ONLY';update_option(GNF5_OPTION,$s);
$final=wp_insert_post(array('post_title'=>$article['title'],'post_content'=>$article['content_html'],'post_status'=>'draft','post_author'=>1));
foreach(array('_gnf5_generated_by'=>'fresh-v5','_gnf5_state'=>'draft_created','_gnf5_article_data'=>$article,'_gnf5_image_ids'=>$ids,'_gnf5_source_facts'=>wp_strip_all_tags($article['content_html'])) as $k=>$value)update_post_meta($final,$k,$value);
GNF5_Utils::acquire_lock(1);
$method=new ReflectionMethod('GNF5_Runner','finalize_post');$method->setAccessible(true);
$result=$method->invoke(null,$final,$article,wp_strip_all_tags($article['content_html']),1,GNF5_Utils::category_settings(1),false);
GNF5_Utils::release_lock(1);
check_repair($result['status']==='publish'&&GNF5_Publish::score($final)>=80,'Existing finalizer saves final content, metadata and images before genuine scoring and publication');
check_repair(get_post_thumbnail_id($final)===$ids[0]&&get_post_meta($final,'_gnf5_image_ids',true)===$ids,'Finalizer preserves two-image checkpoint and featured image');
$s['gemini_api_key']='';update_option(GNF5_OPTION,$s);
echo 'TOTAL 11 extended checks passed.'.PHP_EOL;
