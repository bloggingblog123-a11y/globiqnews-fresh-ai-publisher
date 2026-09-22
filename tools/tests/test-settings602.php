<?php
define('DOING_AJAX',true);
$_SERVER['HTTP_HOST']='globiqnews.localhost:8097';$_SERVER['REQUEST_URI']='/';
require __DIR__.'/wp-test/wordpress/wp-load.php';
$backup=get_option(GNF5_OPTION);$cron=get_option('cron');$checks=0;$posts=array();$media=array();$cats=array();
function ok602($ok,$message){global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$message);$checks++;echo 'PASS: '.$message."\n";}
class Ajax602 extends RuntimeException {}
add_filter('wp_die_ajax_handler',function(){return function($m=''){throw new Ajax602((string)$m);};});
function ajax602($data,$method='ajax_save_section',$user=1){wp_set_current_user($user);$_POST=wp_slash($data);$_REQUEST=$_POST;ob_start();try{GNF5_Admin::$method();}catch(Ajax602 $e){$raw=ob_get_clean();return array('json'=>json_decode($raw,true),'stop'=>$e->getMessage());}ob_end_clean();return array();}
function save602($cat,$section,$values){$row=GNF5_Utils::category_settings($cat);return ajax602(array('nonce'=>wp_create_nonce('gnf5_ajax'),'cat_id'=>$cat,'section'=>$section,'revision'=>GNF5_Utils::section_revision($row,$section),'values'=>wp_json_encode($values)));}
try{
 wp_set_current_user(1);delete_option('gnf5_article_worker');delete_option('gnf5_lock_cat_0');
 foreach(array('Technology','Sports') as $name){$term=wp_insert_term($name.' test '.wp_generate_password(6,false),'category');$cats[]=(int)$term['term_id'];}
 list($tech,$sports)=$cats;
 $s=GNF5_Utils::defaults();$s['gemini_api_key']='FIXTURE-ONLY';$s['image_provider']='builtin';$s['rankmath_enabled']=0;$s['categories'][$tech]=array('post_limit'=>4,'rss'=>'https://example.org/feed','urls'=>'https://example.org/news','author_id'=>1,'instructions'=>'Preserve this','external_links'=>'https://legacy.example.org/research');$s['categories'][$sports]=array('author_id'=>1);
 update_option(GNF5_OPTION,$s);$other=GNF5_Utils::category_settings($sports);
 ok602(!GNF5_Utils::settings()['auto_source_links'] && !GNF5_Utils::category_settings($tech)['manual_links_enabled'] && !GNF5_Utils::category_settings($tech)['manual_links'],'upgrade defaults keep source/manual links OFF and empty');
 GNF5_Admin::register_settings(); // Exercise the real admin_init sanitizer, not just direct update_option.
 $image=array('image_mode'=>'on','image_featured'=>0,'image_inline'=>1,'image_webp'=>0);
 $r=save602($tech,'images',$image);ok602(!empty($r['json']['success']),'image AJAX succeeds through registered sanitizer');
 $row=GNF5_Utils::category_settings($tech);foreach($image as $key=>$value)ok602($row[$key]===$value,'image field persists: '.$key);
 ok602($row['rss']===$s['categories'][$tech]['rss'] && $row['instructions']==='Preserve this' && $row['post_limit']===4 && $row['external_links']===$s['categories'][$tech]['external_links'],'image save preserves research and general settings');
 $links=array('manual_links_enabled'=>1,'manual_links_max'=>2,'manual_links'=>array(array('id'=>'samsung','url'=>'https://www.samsung.com/galaxy/','anchor'=>'Samsung Galaxy','note'=>'Galaxy phone specifications','enabled'=>1,'usage'=>'preferred'),array('id'=>'apple','url'=>'https://www.apple.com/iphone/','anchor'=>'Apple iPhone','note'=>'Apple iPhone features','enabled'=>1,'usage'=>'optional')));
 $r=save602($tech,'links',$links);ok602(!empty($r['json']['success']),'manual link AJAX saves multiple rows');
 ok602(strpos($r['json']['data']['message'],'external link settings saved successfully.')!==false,'category-specific external success message');
 $row=GNF5_Utils::category_settings($tech);ok602($row['image_mode']==='on' && $row['image_webp']===0 && count($row['manual_links'])===2,'external save preserves images');
 $r=ajax602(array('nonce'=>wp_create_nonce('gnf5_ajax'),'cat_id'=>$tech,'post_limit'=>7,'image_mode'=>'off','manual_links_enabled'=>0),'ajax_save_category');
 $row=GNF5_Utils::category_settings($tech);ok602(!empty($r['json']['success']) && $row['post_limit']===7,'partial general save persists requested post limit');
 ok602($row['image_mode']==='on' && $row['manual_links_enabled']===1 && count($row['manual_links'])===2 && $row['rss']===$s['categories'][$tech]['rss'],'general save ignores subsection injection and omitted fields');
 $out=GNF5_Utils::sanitize_settings(array('categories'=>array($tech=>array('_row_complete'=>1,'post_limit'=>8,'image_mode'=>'off','manual_links'=>array(),'manual_links_enabled'=>0))));
 ok602($out['categories'][$tech]['post_limit']===8 && $out['categories'][$tech]['image_mode']==='on' && count($out['categories'][$tech]['manual_links'])===2,'main form preserves latest independently saved sections');
 ok602(GNF5_Utils::category_settings($sports)===$other,'another category remains unchanged');
 $before=get_option(GNF5_OPTION);$base=array('nonce'=>wp_create_nonce('gnf5_ajax'),'cat_id'=>$tech,'section'=>'images','revision'=>GNF5_Utils::section_revision($row,'images'),'values'=>wp_json_encode($image));
 $r=ajax602(array_merge($base,array('nonce'=>'bad')));ok602($r['stop']==='-1','bad image nonce rejected');
 $r=ajax602($base,'ajax_save_section',0);ok602(empty($r['json']['success']),'unauthorized image save rejected');
 wp_set_current_user(1);$r=ajax602(array_merge($base,array('revision'=>'stale')));ok602(empty($r['json']['success']),'stale tab revision rejected');
 $r=ajax602(array_merge($base,array('values'=>'{"image_mode":"off"}')));ok602(empty($r['json']['success']),'partial image section rejected');
 $r=ajax602(array_merge($base,array('section'=>'links','nonce'=>'bad')));ok602($r['stop']==='-1','bad external-link nonce rejected');
 $r=ajax602(array_merge($base,array('section'=>'links')),'ajax_save_section',0);ok602(empty($r['json']['success']),'unauthorized external-link save rejected');
 wp_set_current_user(1);ok602(get_option(GNF5_OPTION)===$before,'rejected saves do not change any settings');
 foreach(array('javascript:alert(1)','data:text/html,a','file:///tmp/a','https://localhost/a','http://127.0.0.1/a','http://10.0.0.2/a','https://site.local/a','http://127.1/a','https://bad host.org/a','https://example.org:8888/a','') as $url)ok602(is_wp_error(GNF5_Utils::sanitize_manual_links(array(array('url'=>$url)))),'reject unsafe/incomplete URL: '.$url);
 $clean=GNF5_Utils::sanitize_manual_links(array(array('url'=>'https://www.samsung.com/galaxy/','anchor'=>'<b>Samsung Galaxy</b>','note'=>'<script>bad()</script>Galaxy specifications')));
 ok602(!is_wp_error($clean) && $clean[0]['anchor']==='Samsung Galaxy' && strpos($clean[0]['note'],'<')===false,'anchor and purpose sanitized');
 $id=GNF5_Publish::save(array('post_title'=>'Samsung Galaxy specifications','post_status'=>'publish','post_category'=>array($tech),'meta_input'=>array('_gnf5_generated_by'=>'fresh-v5')),true);$posts[]=$id;
 $html='<p>Samsung Galaxy phones have published specifications. The Samsung Galaxy support pages explain the options.</p>';
 $research=array('sources'=>array(array('id'=>'S1','url'=>'https://research.example.org/samsung/','text'=>'Private research sentinel')),'facts'=>array(array('subject'=>'Samsung Galaxy','evidence'=>array(array('source'=>'S1')))),'primary_sources'=>array(array('source'=>'S1')));
 update_post_meta($id,'_gnf5_research',$research);$calls=0;
 $mock=function($pre,$args,$url)use(&$calls){$calls++;return array('response'=>array('code'=>200,'message'=>'OK'),'body'=>'','headers'=>array('content-type'=>'text/html'));};add_filter('pre_http_request',$mock,9,3);
 $linked=GNF5_SEO::insert_external_links($html,$id,$tech);
 ok602(strpos($linked,'href="https://www.samsung.com/galaxy/"')!==false,'relevant manual link inserted in an existing paragraph');
 ok602(strpos($linked,'apple.com')===false && strpos($linked,'research.example.org')===false && strpos($linked,'legacy.example.org')===false,'irrelevant Apple, research primary and legacy URLs excluded');
 ok602(substr_count($linked,'<a ')===1 && strpos($linked,'Further information')===false && strpos($linked,'Sources')===false,'no repeated domain or appended external-link list');
 $extra=$links;$extra['manual_links_max']=0;save602($tech,'links',$extra);ok602(GNF5_SEO::insert_external_links($html,$id,$tech)===$html,'maximum zero disables manual insertion');
 $extra['manual_links_max']=3;$extra['manual_links'][0]['enabled']=0;save602($tech,'links',$extra);ok602(GNF5_SEO::insert_external_links($html,$id,$tech)===$html,'disabled relevant entry is skipped');
 $extra['manual_links'][0]['enabled']=1;$extra['manual_links'][]=array('id'=>'same-domain','url'=>'https://www.samsung.com/galaxy/support/','anchor'=>'Galaxy support','note'=>'Samsung Galaxy','enabled'=>1,'usage'=>'optional');save602($tech,'links',$extra);
 ok602(substr_count(GNF5_SEO::insert_external_links($html,$id,$tech),'<a ')===1,'same-domain configured entries insert at most once');
 save602($tech,'links',$links);
 $raw='<p>Samsung Galaxy report.</p><p>Source: https://research.example.org/report</p><h2>References</h2><ul><li>https://publisher.example.org/news</li></ul>';
 $cleanbody=GNF5_SEO::public_article_html($raw);ok602($cleanbody==='<p>Samsung Galaxy report.</p>','writer-added raw URLs, credit and bibliography removed under OFF policy');
 ok602(GNF5_SEO::insert_external_links($html,$id,$sports)===$html,'manual links never cross categories');
 $links['manual_links_enabled']=0;save602($tech,'links',$links);
 foreach(array('RSS','GDELT','Source URL','Manual URL','Discovered reference') as $method){$research['sources'][0]['method']=$method;update_post_meta($id,'_gnf5_research',$research);ok602(GNF5_SEO::insert_external_links($html,$id,$tech)===$html,$method.' source links stay private when OFF');}
 ok602(get_post_meta($id,'_gnf5_research',true)===$research,'link visibility never destroys private evidence');
 $links['manual_links_enabled']=1;$links['manual_links']=array();save602($tech,'links',$links);ok602(GNF5_SEO::insert_external_links($html,$id,$tech)===$html,'enabled empty list succeeds with zero links');
 $linked=GNF5_SEO::external_links_section('https://www.samsung.com/galaxy/');ok602($linked==='','legacy source-list helper cannot bypass privacy');
 $s=GNF5_Utils::settings();$s['auto_source_links']=1;update_option(GNF5_OPTION,$s);ok602(strpos(GNF5_SEO::insert_external_links($html,$id,$tech),'research.example.org')!==false,'explicit global opt-in allows relevant primary reference');
 $s['auto_source_links']=0;update_option(GNF5_OPTION,$s);
 $related=wp_insert_post(array('post_title'=>'Samsung Galaxy guide','post_status'=>'publish','post_category'=>array($tech)));$posts[]=$related;update_post_meta($related,'rank_math_focus_keyword','Samsung Galaxy');update_post_meta($id,'rank_math_focus_keyword','Samsung Galaxy');
 ok602(strpos(GNF5_SEO::insert_internal_links($html,$id,$tech),get_permalink($related))!==false,'internal linking works with both external systems OFF/empty');
 wp_update_post(array('ID'=>$id,'post_content'=>$html));GNF5_Publish::checkpoint($id);
 $article=array('title'=>'Samsung Galaxy','image_prompts'=>array('Original abstract one','Original abstract two'),'image_alts'=>array('Concept one','Concept two'));
 $ids=GNF5_Images::generate_for_post($article,$id,$tech);if(!is_wp_error($ids))$media=array_merge($media,$ids);
 ok602(!is_wp_error($ids) && !isset($ids[0]) && !empty($ids[1]),'inline-only preference generates just slot one');
 ok602(get_post_mime_type($ids[1])==='image/jpeg','WebP OFF saves an optimized JPEG');
 $meta=wp_get_attachment_metadata($ids[1]);ok602($meta['width']===1200 && $meta['height']===675,'JPEG retains low-storage dimensions');
 $image['image_inline']=0;$image['image_featured']=1;$image['image_webp']=1;save602($tech,'images',$image);
 $ids2=GNF5_Images::generate_for_post($article,$id,$tech,true);if(!is_wp_error($ids2))$media=array_merge($media,$ids2);
 ok602(!is_wp_error($ids2) && isset($ids2[0]) && !isset($ids2[1]) && get_post_mime_type($ids2[0])==='image/webp','featured-only preference saves only slot zero as WebP');
 ok602((get_post_meta($id,'_gnf5_image_ids',true)[1]??0)===$ids[1],'disabled generated slot checkpoint preserved for later reuse');
 ok602(GNF5_Images::insert_two_blocks($html,$ids,$article['image_alts'],$tech)===$html,'disabled inline preference prevents checkpoint insertion');
 $image['image_mode']='off';save602($tech,'images',$image);$n=$calls;ok602(GNF5_Images::generate_for_post($article,$id,$tech)===array() && $calls===$n,'image OFF makes no image request');
 update_post_meta($id,'rank_math_seo_score',100);GNF5_Publish::maybe_publish($id);ok602(get_post_status($id)==='draft','Rank Math 100 still remains Draft');
 $report=GNF5_SEO::link_report($id);ok602($report['Automatic research/source links']==='OFF' && isset($report['Internal links'],$report['Manual links inserted at last composition']),'quality report exposes independent link policy and counts');
 ok602(GNF5_SEO::checklist($id)['external_links']['status']==='OPTIONAL','zero external links is optional, not article failure');
 echo "TOTAL: $checks settings/link/image checks passed\n";
}finally{
 remove_filter('sanitize_option_'.GNF5_OPTION,array('GNF5_Utils','sanitize_settings'));
 foreach(array_unique($media) as $id)wp_delete_attachment($id,true);foreach($posts as $id)wp_delete_post($id,true);foreach($cats as $cat)wp_delete_term($cat,'category');update_option(GNF5_OPTION,$backup);update_option('cron',$cron);
}
