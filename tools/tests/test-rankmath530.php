<?php
$_SERVER['HTTP_HOST']='globiqnews.test'; $_SERVER['REQUEST_URI']='/';
$test_root=getenv('GNF5_TEST_WP_ROOT');
if(!$test_root || !is_file($test_root.'/wp-load.php'))exit('Set GNF5_TEST_WP_ROOT to the disposable WordPress directory.');
require $test_root.'/wp-load.php';
if(wp_get_environment_type()!=='local' || strpos(home_url(),'globiqnews.localhost')===false)exit('Refusing to modify a non-test website.');
$checks=0;
function verify($ok,$label){global $checks;if(!$ok){fwrite(STDERR,'FAIL: '.$label.PHP_EOL);exit(1);}echo 'PASS: '.$label.PHP_EOL;$checks++;}
function settings_auto($enabled){$s=GNF5_Utils::settings();$s['auto_publish_enabled']=$enabled;$s['auto_publish_recovered']=1;$s['validated_success_status']='draft';update_option(GNF5_OPTION,$s);}
function fixture_post($score,$cat=1){
    $fixtures=json_decode(file_get_contents(__DIR__.'/rankmath-fixtures.json'),true);
    $v=$fixtures[$score]['values'];
    $id=wp_insert_post(array('post_title'=>$v['title'],'post_name'=>'solar-energy-benefits','post_excerpt'=>$v['description'],
        'post_status'=>'draft','post_content'=>$v['content'],'post_category'=>array($cat)),true);
    if(is_wp_error($id))throw new Exception($id->get_error_message());
    foreach(array('_gnf5_generated_by'=>'fresh-v5','_gnf5_state'=>'awaiting_rankmath','rank_math_focus_keyword'=>$v['keyword'],
        'rank_math_title'=>$v['title'],'rank_math_description'=>$v['description']) as $k=>$value)update_post_meta($id,$k,$value);
    return $id;
}
function change_fixture($id,$score){
    $f=json_decode(file_get_contents(__DIR__.'/rankmath-fixtures.json'),true)[$score]['values'];
    wp_update_post(array('ID'=>$id,'post_title'=>$f['title'],'post_content'=>$f['content'],'post_excerpt'=>$f['description']));
    update_post_meta($id,'rank_math_title',$f['title']);update_post_meta($id,'rank_math_description',$f['description']);
}
delete_option('gnf5_article_worker'); delete_option('gnf5_lock_cat_1');
settings_auto(1);
$transitions=array();
add_action('transition_post_status',function($new,$old,$post)use(&$transitions){if($new==='publish'&&$old!=='publish')$transitions[$post->ID]=($transitions[$post->ID]??0)+1;},10,3);
foreach(array(80,81,84,85,79,76) as $expected){
    $id=fixture_post($expected);$published=GNF5_RankMath::run($id);
    verify(GNF5_Publish::score($id)===$expected*1.0,'Real analyzer returned '.$expected.' (post '.$id.'); actual='.var_export(GNF5_Publish::score($id),true).' '.get_post_meta($id,'_gnf5_seo_error',true));
    verify(get_post_status($id)===($expected>=80?'publish':'draft'),'Threshold '.$expected.' => '.get_post_status($id));
    if($expected>=80){GNF5_RankMath::run($id);GNF5_Publish::maybe_publish($id);verify(($transitions[$id]??0)===1,'No duplicate publication '.$id);}
}
$id=fixture_post(80);
verify(GNF5_Publish::score($id)===null&&!GNF5_Publish::maybe_publish($id)&&get_post_status($id)==='draft','Focus keyword without score cannot publish');
do_action(GNF5_RankMath::HOOK,$id);
verify(GNF5_Publish::score($id)===80.0&&get_post_status($id)==='publish','Scheduled hook obtains actual score and publishes without editor');
$id=fixture_post(79);
foreach(array('', 'N/A','invalid',-1,101,array(90)) as $invalid){update_post_meta($id,'rank_math_seo_score',$invalid);verify(!GNF5_Publish::maybe_publish($id)&&get_post_status($id)==='draft','Invalid score rejected: '.wp_json_encode($invalid));}
update_post_meta($id,'rank_math_seo_score',95);
verify(!GNF5_Publish::maybe_publish($id),'Unverified saved 95 cannot publish');
GNF5_RankMath::run($id);
verify(GNF5_Publish::score($id)===79.0&&get_post_status($id)==='draft','Unverified 95 replaced by real 79');
settings_auto(0);$id=fixture_post(85);GNF5_RankMath::run($id);
verify(GNF5_Publish::score($id)===85.0&&GNF5_RankMath::fresh($id)&&get_post_status($id)==='draft','Auto Publish off still computes a genuine score');
change_fixture($id,76);settings_auto(1);
verify(!GNF5_RankMath::fresh($id)&&!GNF5_Publish::maybe_publish($id)&&get_post_status($id)==='draft','Old 85 rejected after article edit');
GNF5_RankMath::run($id);verify(GNF5_Publish::score($id)===76.0&&get_post_status($id)==='draft','Edited article recalculates to 76');
change_fixture($id,84);GNF5_RankMath::run($id);
if(GNF5_Publish::score($id)!==84.0){file_put_contents(__DIR__.'/failed-payload.json',wp_json_encode(GNF5_RankMath::payload($id)));echo wp_json_encode(get_post_meta($id,'_gnf5_seo_tests',true)).PHP_EOL;}
verify(GNF5_Publish::score($id)===84.0&&get_post_status($id)==='publish','Improved article recalculates 76 => 84 and publishes');
settings_auto(0);$id=fixture_post(85);GNF5_RankMath::run($id);
wp_set_post_tags($id,array('changed tag'));
verify(!GNF5_RankMath::fresh($id),'Taxonomy change invalidates receipt');
GNF5_RankMath::run($id);update_post_meta($id,'rank_math_schema_Article',array('@type'=>'Article','headline'=>'Changed headline'));
verify(!GNF5_RankMath::fresh($id),'Schema change invalidates receipt');
GNF5_RankMath::run($id);update_post_meta($id,'rank_math_title','A custom title without the keyword');
verify(!GNF5_RankMath::fresh($id),'SEO metadata change invalidates receipt');
$id=fixture_post(85);update_post_meta($id,'rank_math_robots',array('noindex'));settings_auto(1);GNF5_RankMath::run($id);
verify(get_post_status($id)==='draft'&&GNF5_Publish::score($id)===null,'Noindex N/A remains Draft');
for($i=1;$i<4;$i++){delete_post_meta($id,'_gnf5_seo_next');GNF5_RankMath::run($id);}
verify((int)get_post_meta($id,'_gnf5_seo_attempts',true)===3,'Failed analysis is limited to 3 attempts');
verify(get_post_status($id)==='draft'&&get_post_meta($id,'_gnf5_seo_status',true)==='SEO SCORE PENDING','Exhausted analysis preserves pending Draft');
$cat=wp_insert_term('Second analysis category','category');$cat_id=is_wp_error($cat)?(int)$cat->get_error_data():$cat['term_id'];
verify(GNF5_Utils::acquire_lock(1),'First category obtains global worker');
verify(!GNF5_Utils::acquire_lock($cat_id),'Second category cannot process concurrently');
$id=fixture_post(80,$cat_id);GNF5_RankMath::run($id);
verify(get_post_status($id)==='draft'&&(int)get_post_meta($id,'_gnf5_seo_attempts',true)===0,'Busy worker defers scoring without consuming attempt');
GNF5_Utils::release_lock(1);GNF5_RankMath::reset_retry($id);GNF5_RankMath::run($id);
verify(get_post_status($id)==='publish'&&($transitions[$id]??0)===1,'Next category runs after first worker finishes');
foreach(array('processing','image_pending','skipped') as $state){$id=fixture_post(85);update_post_meta($id,'_gnf5_state',$state);GNF5_RankMath::run($id);verify(get_post_status($id)==='draft'&&GNF5_Publish::score($id)===null,'Incomplete/skipped state '.$state.' cannot score or publish');}
$id=fixture_post(85);update_post_meta($id,'_gnf5_auto_published',1);GNF5_RankMath::run($id);verify(get_post_status($id)==='draft','Returned published article never auto-republishes');
$s=GNF5_Utils::settings();$s['categories'][1]['instructions']='Preserved custom instructions';$s['gemini_api_key']='TEST-ONLY-NOT-A-REAL-KEY';update_option(GNF5_OPTION,$s);GNF5_Utils::activate();
verify(get_option(GNF5_OPTION)['categories'][1]['instructions']==='Preserved custom instructions'&&get_option(GNF5_OPTION)['gemini_api_key']==='TEST-ONLY-NOT-A-REAL-KEY','Activation preserves settings and stored keys');
$s['gemini_api_key']='';update_option(GNF5_OPTION,$s);
echo 'TOTAL '.$checks.' checks passed using WordPress '.get_bloginfo('version').' + Rank Math '.RANK_MATH_VERSION.' + real installed analyzer.'.PHP_EOL;
