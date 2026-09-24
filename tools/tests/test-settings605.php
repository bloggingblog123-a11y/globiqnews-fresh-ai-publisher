<?php
define('DOING_AJAX',true);
$_SERVER['HTTP_HOST']='globiqnews.localhost:8097';$_SERVER['REQUEST_URI']='/';
require __DIR__.'/wp-test/wordpress/wp-load.php';
$backup=get_option(GNF5_OPTION);$cron=get_option('cron');$logs=get_option(GNF5_LOG_OPTION);$cats=array();$checks=0;
function ok605($ok,$message){global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$message);$checks++;echo 'PASS: '.$message.PHP_EOL;}
class Ajax605 extends RuntimeException {}
add_filter('wp_die_ajax_handler',function(){return function($m=''){throw new Ajax605((string)$m);};});
function ajax605($method,$data){$_POST=wp_slash($data);$_REQUEST=$_POST;ob_start();try{GNF5_Admin::$method();}catch(Ajax605 $e){return array('json'=>json_decode(ob_get_clean(),true),'stop'=>$e->getMessage());}ob_end_clean();return array();}
function form605(){ $s=GNF5_Utils::settings();$s['_global_revision']=GNF5_Utils::global_revision($s);$s['_form_complete']=1;foreach(get_categories(array('hide_empty'=>false)) as $cat){$row=GNF5_Utils::category_settings($cat->term_id);$s['categories'][$cat->term_id]=$row+array('_row_complete'=>1,'_revision'=>GNF5_Utils::section_revision($row,'general'));}return $s; }
try{
 wp_set_current_user(1);foreach(array('Save605','Other605') as $name){$term=wp_insert_term($name.' '.wp_generate_password(5,false),'category');$cats[]=(int)$term['term_id'];}list($cat,$other)=$cats;
 $s=GNF5_Utils::defaults();$s['gemini_api_key']='FIXTURE-KEEP-605';$s['categories'][$cat]=GNF5_Utils::sanitize_category_row(array('author_id'=>1,'rss'=>'https://example.org/old'));$s['categories'][$other]=GNF5_Utils::sanitize_category_row(array('author_id'=>1));update_option(GNF5_OPTION,$s);GNF5_Admin::register_settings();
 $nonce=wp_create_nonce('gnf5_ajax');$old=GNF5_Utils::category_settings($cat);$oldform=form605();$rev=GNF5_Utils::section_revision($old,'general');
 $values=array('enabled'=>1,'post_limit'=>8,'interval'=>'gnf5_4h','author_id'=>1,'rss'=>"https://example.org/new-feed\nhttps://example.org/feed2",'urls'=>'https://example.org/news','instructions'=>"Use clear English.\nKeep category details.",'gdelt_enabled'=>1,'gdelt_keywords'=>'technology, science','gdelt_language'=>'hindi','gdelt_country'=>'india','gdelt_window'=>'3d','gdelt_results'=>75,'gdelt_interval'=>120,'min_sources'=>3,'max_candidates'=>80,'opportunity_threshold'=>0);
 $data=$values+array('cat_id'=>$cat,'nonce'=>$nonce,'complete'=>1,'revision'=>$rev);
 $r=ajax605('ajax_save_category',$data);ok605(!empty($r['json']['success']),'complete category save reports success');
 $saved=GNF5_Utils::category_settings($cat,GNF5_Utils::fresh_option(GNF5_OPTION,array()));foreach($values as $key=>$value)ok605($saved[$key]===$value,'database retains category field '.$key);
 ok605($r['json']['data']['revision']===GNF5_Utils::section_revision($saved,'general'),'response supplies current general revision');
 ok605(GNF5_Utils::category_settings($other)==$s['categories'][$other],'category save preserves other categories');
 $r=ajax605('ajax_save_category',array_merge($data,array('post_limit'=>2)));ok605(empty($r['json']['success']) && strpos($r['json']['data']['message'],'another tab')!==false,'older category tab cannot overwrite latest successful save');
 ok605(GNF5_Utils::category_settings($cat)===$saved,'stale AJAX leaves all latest fields intact');
 $r=GNF5_Utils::validate_settings_form($oldform);ok605(is_wp_error($r) && $r->get_error_code()==='stale_settings','older full form detects changed category before writing');
 $_SERVER['REQUEST_METHOD']='POST';$_POST=wp_slash(array('option_page'=>'gnf5_group','action'=>'update','_wpnonce'=>wp_create_nonce('gnf5_group-options'),'gnf6_settings_form'=>1,GNF5_OPTION=>$oldform));$_REQUEST=$_POST;
 try{GNF5_Admin::register_settings();ok605(false,'stale options.php must stop');}catch(Ajax605 $e){ok605(strpos($e->getMessage(),'Nothing from this form was saved')!==false,'actual full form guard stops before options.php write');}
 ok605(GNF5_Utils::fresh_option('gnf5_settings_write_lock',false)===false,'rejected form releases settings lock');$_SERVER['REQUEST_METHOD']='GET';$_POST=$_REQUEST=array();
 $r=ajax605('ajax_save_category',array('cat_id'=>$cat,'nonce'=>$nonce));ok605(empty($r['json']['success']),'empty category payload never claims success');
 $broken=$data;unset($broken['urls']);$r=ajax605('ajax_save_category',$broken);ok605(empty($r['json']['success']) && strpos($r['json']['data']['message'],'Incomplete')!==false,'truncated complete category request rejected');
 $data['revision']=GNF5_Utils::section_revision($saved,'general');$data['rss']='';$data['urls']='';$data['enabled']=0;$data['gdelt_enabled']=0;$r=ajax605('ajax_save_category',$data);
 $row=GNF5_Utils::category_settings($cat,GNF5_Utils::fresh_option(GNF5_OPTION,array()));ok605(!empty($r['json']['success']) && $row['rss']==='' && $row['urls']==='' && $row['enabled']===0 && $row['gdelt_enabled']===0,'empty sources and OFF checkboxes persist');
 foreach(array('images'=>array('image_mode'=>'off','image_featured'=>0,'image_inline'=>1,'image_webp'=>0),'links'=>array('manual_links_enabled'=>1,'manual_links_max'=>1,'manual_links'=>array(array('id'=>'605-link','url'=>'https://www.samsung.com/galaxy/','anchor'=>'Samsung Galaxy','note'=>'Phone specifications','enabled'=>1,'usage'=>'preferred')))) as $section=>$v){
  $rev=GNF5_Utils::section_revision(GNF5_Utils::category_settings($cat),$section);$r=ajax605('ajax_save_section',array('nonce'=>$nonce,'cat_id'=>$cat,'section'=>$section,'revision'=>$rev,'values'=>wp_json_encode($v)));ok605(!empty($r['json']['success']),$section.' save succeeds');
  $row=GNF5_Utils::category_settings($cat,GNF5_Utils::fresh_option(GNF5_OPTION,array()));foreach($v as $key=>$value)ok605($row[$key]===$value,'database retains '.$key);
  $r=ajax605('ajax_save_section',array('nonce'=>$nonce,'cat_id'=>$cat,'section'=>$section,'revision'=>$rev,'values'=>wp_json_encode($v)));ok605(empty($r['json']['success']),$section.' stale revision rejected');
 }
 $f=form605();ok605(GNF5_Utils::validate_settings_form($f)===true,'fresh complete form accepted');$truncated=$f;unset($truncated['_form_complete']);ok605(is_wp_error(GNF5_Utils::validate_settings_form($truncated)),'truncated full page rejected');
 $truncated=$f;unset($truncated['categories'][$cat]['_row_complete']);ok605(is_wp_error(GNF5_Utils::validate_settings_form($truncated)),'partial category row rejects entire form');
 $globals=array('gemini_model'=>'fixture-model-605','gemini_backup_model'=>'fixture-backup','image_enabled'=>1,'image_provider'=>'builtin','openai_model'=>'fixture-image-605','openai_quality'=>'high','openai_size'=>'1024x1024','webui_endpoint'=>'https://example.org/webui','webui_model'=>'fixture-webui','builtin_fallback'=>0,'webp_quality'=>81,'auto_recovery_enabled'=>0,'auto_recovery_max_attempts'=>4,'added_value_target'=>0,'originality_retries'=>0,'history_days'=>30,'debug_enabled'=>1,'rankmath_enabled'=>0,'toc_enabled'=>0,'internal_links'=>0,'auto_source_links'=>1,'global_article_instructions'=>'Global article 605','global_seo_instructions'=>'Global SEO 605','global_image_instructions'=>'Global image 605','seo_analyzer_mode'=>'remote','seo_service_url'=>'https://example.org/scorer','seo_service_consent'=>0,'max_concurrent_categories'=>2);
 $fresh=array_merge($f,$globals);$fresh['gemini_api_key']='';$before=GNF5_Utils::category_settings($cat);ok605(GNF5_Utils::validate_settings_form($fresh)===true,'fresh form may change global values');
 update_option(GNF5_OPTION,$fresh);$stored=GNF5_Utils::fresh_option(GNF5_OPTION,array());foreach($globals as $key=>$value)ok605($stored[$key]===$value,'database retains global field '.$key);
 ok605($stored['gemini_api_key']==='FIXTURE-KEEP-605','blank key preserves existing secret');ok605(GNF5_Utils::category_settings($cat)===$before,'global save preserves independently saved image/link settings');
 ok605(is_wp_error(GNF5_Utils::validate_settings_form($f)),'older global tab cannot overwrite newer global values');
 ok605($stored['auto_publish_enabled']===0 && $stored['post_status']==='draft','settings saves preserve Draft-only policy');
 echo 'TOTAL: '.$checks.' settings persistence checks passed'.PHP_EOL;
}finally{
 GNF5_Utils::release_settings_lock();remove_filter('sanitize_option_'.GNF5_OPTION,array('GNF5_Utils','sanitize_settings'));foreach($cats as $cat)wp_delete_term($cat,'category');update_option(GNF5_OPTION,$backup);update_option('cron',$cron);update_option(GNF5_LOG_OPTION,$logs);
}
