<?php
$mode=$argv[1]??'no-rankmath';
if($mode==='no-rankmath')putenv('GNF5_TEST_NO_RANKMATH=1');
if($mode==='no-node')putenv('GNF5_TEST_NO_NODE=1');
$_SERVER['HTTP_HOST']='globiqnews.test';$_SERVER['REQUEST_URI']='/';
$test_root=getenv('GNF5_TEST_WP_ROOT');
if(!$test_root || !is_file($test_root.'/wp-load.php'))exit('Set GNF5_TEST_WP_ROOT to the disposable WordPress directory.');
require $test_root.'/wp-load.php';
if(wp_get_environment_type()!=='local' || strpos(home_url(),'globiqnews.localhost')===false)exit('Refusing to modify a non-test website.');
$id=wp_insert_post(array('post_title'=>'Solar energy benefits','post_content'=>'<p>Solar energy test article.</p>','post_status'=>'draft'));
foreach(array('_gnf5_generated_by'=>'fresh-v5','_gnf5_state'=>'awaiting_rankmath','rank_math_focus_keyword'=>'solar energy') as $k=>$v)update_post_meta($id,$k,$v);
GNF5_RankMath::run($id);
$valid=get_post_status($id)==='draft'&&GNF5_Publish::score($id)===null&&(int)get_post_meta($id,'_gnf5_seo_attempts',true)===1&&get_post_meta($id,'_gnf5_seo_status',true)==='SEO SCORE PENDING';
echo ($valid?'PASS':'FAIL').': '.$mode.' preserves Draft and schedules scoring-only retry. '.get_post_meta($id,'_gnf5_seo_error',true).PHP_EOL;
exit($valid?0:1);
