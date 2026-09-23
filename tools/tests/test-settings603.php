<?php
// Isolated WordPress harness only; restores settings, categories, cron and logs.
define('DOING_AJAX',true);
$_SERVER['HTTP_HOST']='globiqnews.localhost:8097';$_SERVER['REQUEST_URI']='/';
require __DIR__.'/wp-test/wordpress/wp-load.php';
$backup=get_option(GNF5_OPTION);$cron=get_option('cron');$logs=get_option(GNF5_LOG_OPTION);$cats=array();$checks=0;$urls=array();
function ok603($ok,$message){global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$message);$checks++;echo 'PASS: '.$message."\n";}
class Ajax603 extends RuntimeException {}
add_filter('wp_die_ajax_handler',function(){return function($m=''){throw new Ajax603((string)$m);};});
function ajax603($method,$data){$_POST=wp_slash($data);$_REQUEST=$_POST;ob_start();try{GNF5_Admin::$method();}catch(Ajax603 $e){$raw=ob_get_clean();return array('json'=>json_decode($raw,true),'stop'=>$e->getMessage());}ob_end_clean();return array();}
function save603($cat,$section,$values){return GNF5_Utils::save_category_section($cat,$section,$values,GNF5_Utils::section_revision(GNF5_Utils::category_settings($cat),$section));}
try{
 wp_set_current_user(1);
 foreach(array('Technology','Sports') as $name){$t=wp_insert_term('603 '.$name.' '.wp_generate_password(6,false),'category');$cats[]=(int)$t['term_id'];}list($cat,$other)=$cats;
 $s=GNF5_Utils::defaults();$s['rankmath_enabled']=0;$s['categories'][$cat]=array('image_mode'=>'off','post_limit'=>1,'author_id'=>1);$s['categories'][$other]=array('author_id'=>1);update_option(GNF5_OPTION,$s);
 GNF5_Admin::register_settings();
 $image=array('image_mode'=>'on','image_featured'=>1,'image_inline'=>1,'image_webp'=>1);
 $links=array('manual_links_enabled'=>1,'manual_links_max'=>2,'manual_links'=>array(array('id'=>'samsung','url'=>'https://www.samsung.com/galaxy/','anchor'=>'Samsung Galaxy','note'=>'Specifications','enabled'=>1,'usage'=>'optional')));
 $nested=null;$fired=false;
 $interleave=function($value,$old)use($cat,$links,&$nested,&$fired){if(!$fired){$fired=true;$nested=save603($cat,'links',$links);}return $value;};
 add_filter('pre_update_option_'.GNF5_OPTION,$interleave,10,2);
 $first=save603($cat,'images',$image);remove_filter('pre_update_option_'.GNF5_OPTION,$interleave,10);
 ok603($fired && is_string($first),'outer image save commits during deterministic overlap');
 ok603(is_wp_error($nested) && $nested->get_error_code()==='settings_busy','overlapping link save reports busy instead of false success');
 ok603(strpos($nested->get_error_message(),'not saved')!==false,'busy message explicitly asks user to retry unsaved changes');
 ok603(is_string(save603($cat,'links',$links)),'retry succeeds after first save releases lock');
 $row=GNF5_Utils::category_settings($cat);ok603($row['image_mode']==='on' && $row['manual_links_enabled']===1 && count($row['manual_links'])===1,'both independent sections survive retry');
 ok603(get_option('gnf5_settings_write_lock',false)===false,'successful save releases lock');
 ok603(GNF5_Utils::acquire_settings_lock(),'can acquire shared lock');
 $before=get_option(GNF5_OPTION);$r=save603($other,'images',$image);
 ok603(is_wp_error($r) && $r->get_error_code()==='settings_busy','other category uses same option lock');
 $r=ajax603('ajax_save_category',array('nonce'=>wp_create_nonce('gnf5_ajax'),'cat_id'=>$cat,'post_limit'=>3));
 ok603(empty($r['json']['success']) && strpos($r['json']['data']['message'],'not saved')!==false,'general category AJAX reports overlapping write');
 ok603(get_option(GNF5_OPTION)===$before,'rejected writers leave all settings unchanged');GNF5_Utils::release_settings_lock();
 $r=ajax603('ajax_save_category',array('nonce'=>wp_create_nonce('gnf5_ajax'),'cat_id'=>$cat,'post_limit'=>3));
 ok603(!empty($r['json']['success']) && GNF5_Utils::category_settings($cat)['post_limit']===3,'general save retry persists');
 $row=GNF5_Utils::category_settings($cat);ok603($row['image_mode']==='on' && count($row['manual_links'])===1,'general save preserves both subsections');
 $r=GNF5_Utils::save_category_section($cat,'images',$image,'stale');ok603(is_wp_error($r) && $r->get_error_code()==='stale_settings','stale section revision still rejected');
 ok603(get_option('gnf5_settings_write_lock',false)===false,'stale rejection releases lock');
 ok603(is_string(save603($cat,'images',$image)) && get_option('gnf5_settings_write_lock',false)===false,'no-op save succeeds and releases lock');
 $throw=function(){throw new RuntimeException('fixture write failure');};add_filter('pre_update_option_'.GNF5_OPTION,$throw);
 try{save603($cat,'general',array('post_limit'=>4));}catch(RuntimeException $e){ok603($e->getMessage()==='fixture write failure','write exception exercised');}finally{remove_filter('pre_update_option_'.GNF5_OPTION,$throw);}
 ok603(get_option('gnf5_settings_write_lock',false)===false,'exception releases lock');
 add_option('gnf5_settings_write_lock',array('token'=>'expired','time'=>time()-121),'',false);
 ok603(GNF5_Utils::acquire_settings_lock(),'expired lock recovered');GNF5_Utils::release_settings_lock();
 // Simulate a second process inserting after this request cached the lock as absent.
 get_option('gnf5_settings_write_lock',false);$foreign=array('token'=>'other-worker','time'=>time());
 $wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,'no')",'gnf5_settings_write_lock',maybe_serialize($foreign)));
 ok603(!GNF5_Utils::acquire_settings_lock(),'database insert refuses competing owner despite cached absence');
 wp_cache_delete('notoptions','options');wp_cache_delete('gnf5_settings_write_lock','options');
 ok603(get_option('gnf5_settings_write_lock')===$foreign,'failed acquisition cannot replace another owner');GNF5_Utils::delete_lock_value('gnf5_settings_write_lock',$foreign);
 GNF5_Utils::acquire_settings_lock();update_option('gnf5_settings_write_lock',$foreign,false);GNF5_Utils::release_settings_lock();
 ok603(get_option('gnf5_settings_write_lock')===$foreign,'release cannot delete a replacement owner');GNF5_Utils::delete_lock_value('gnf5_settings_write_lock',$foreign);
 $_SERVER['REQUEST_METHOD']='POST';$_POST=array('option_page'=>'gnf5_group','action'=>'update','_wpnonce'=>wp_create_nonce('gnf5_group-options'));$_REQUEST=$_POST;
 GNF5_Admin::register_settings();$r=save603($cat,'images',$image);ok603(is_wp_error($r) && $r->get_error_code()==='settings_busy','full settings form holds shared lock through options.php write');
 try{GNF5_Admin::register_settings();ok603(false,'overlapping form must stop');}catch(Ajax603 $e){ok603(strpos($e->getMessage(),'Nothing from this form was saved')!==false,'overlapping main form reports no write');}
 $input=GNF5_Utils::settings();$input['auto_source_links']=1;update_option(GNF5_OPTION,$input);GNF5_Utils::release_settings_lock();$_POST=$_REQUEST=array();$_SERVER['REQUEST_METHOD']='GET';
 ok603(GNF5_Utils::settings()['auto_source_links']===1 && count(GNF5_Utils::category_settings($cat)['manual_links'])===1,'main form saves globals while preserving independently saved links');
 $requests=array();$mock=function($pre,$args,$url)use(&$requests){$requests[]=$url;$code=strpos($url,'missing')!==false?404:200;return array('response'=>array('code'=>$code,'message'=>'Fixture'),'body'=>'','headers'=>array('content-type'=>'text/html'));};add_filter('pre_http_request',$mock,9,3);
 $links['manual_links'][]=array('id'=>'broken','url'=>'https://www.apple.com/missing-603/','anchor'=>'Apple','note'=>'','enabled'=>1,'usage'=>'optional');
 $links['manual_links'][]=array('id'=>'disabled','url'=>'https://www.microsoft.com/disabled-603/','anchor'=>'Microsoft','note'=>'','enabled'=>0,'usage'=>'optional');
 save603($cat,'links',$links);$urls=array_column($links['manual_links'],'url');
 $r=ajax603('ajax_test_category_sources',array('nonce'=>wp_create_nonce('gnf5_ajax'),'cat_id'=>$cat));$d=$r['json']['data'];
 ok603(!empty($r['json']['success']) && $d['ok']===1 && $d['failed']===1,'source test counts enabled manual reachable and broken links');
 ok603(count($requests)===2 && !in_array($urls[2],$requests,true),'disabled manual entry is not requested');
 ok603(strpos($d['message'],'MANUAL EXTERNAL LINK OK')!==false && strpos($d['message'],'MANUAL EXTERNAL LINK BROKEN')!==false,'manual result labels identify statuses');
 $requests=array();$links['manual_links_enabled']=0;save603($cat,'links',$links);
 $r=ajax603('ajax_test_category_sources',array('nonce'=>wp_create_nonce('gnf5_ajax'),'cat_id'=>$cat));
 ok603(!$requests && strpos($r['json']['data']['message'],'MANUAL EXTERNAL LINKS OFF')!==false,'manual master OFF skips requests and explains why');
 $links['manual_links_enabled']=1;$links['manual_links']=array();save603($cat,'links',$links);
 $r=ajax603('ajax_test_category_sources',array('nonce'=>wp_create_nonce('gnf5_ajax'),'cat_id'=>$cat));
 ok603(!$requests && strpos($r['json']['data']['message'],'No enabled manual external links')!==false,'enabled empty list has explicit status');
 $links['manual_links']=array(array('id'=>'one','url'=>$urls[0],'anchor'=>'Samsung Galaxy','note'=>'','enabled'=>1,'usage'=>'optional'));save603($cat,'links',$links);
 remove_filter('sanitize_option_'.GNF5_OPTION,array('GNF5_Utils','sanitize_settings'));$s=GNF5_Utils::settings();$s['categories'][$cat]['external_links']=$urls[0]."\nhttps://www.intel.com/legacy-603/";update_option(GNF5_OPTION,$s);$urls[]='https://www.intel.com/legacy-603/';GNF5_Admin::register_settings();
 $requests=array();$r=ajax603('ajax_test_category_sources',array('nonce'=>wp_create_nonce('gnf5_ajax'),'cat_id'=>$cat));
 ok603(count($requests)===2 && count(array_keys($requests,$urls[0],true))===1,'manual and legacy duplicate URL tested once');
 ok603(strpos($r['json']['data']['message'],'LEGACY RESEARCH LINK OK')!==false,'legacy private research links still have separate result label');
 $requests=array();ajax603('ajax_test_category_sources',array('nonce'=>wp_create_nonce('gnf5_ajax'),'cat_id'=>$other));ok603(!$requests,'source test never requests another category links');
 remove_filter('pre_http_request',$mock,9);
 echo 'TOTAL: '.$checks." checks passed\n";
}finally{
 GNF5_Utils::release_settings_lock();remove_filter('sanitize_option_'.GNF5_OPTION,array('GNF5_Utils','sanitize_settings'));
 foreach($cats as $id)wp_delete_term($id,'category');foreach($urls as $url)delete_transient('gnf5_extcheck_'.md5($url));
 update_option(GNF5_OPTION,$backup);update_option('cron',$cron);update_option(GNF5_LOG_OPTION,$logs);
}
