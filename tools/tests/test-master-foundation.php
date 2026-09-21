<?php
$_SERVER['HTTP_HOST']='globiqnews.localhost:8097';$_SERVER['REQUEST_URI']='/';
require_once __DIR__.'/bootstrap600.php';
$checks=0;$ids=array();$backup=get_option(GNF5_OPTION);
function check6($ok,$label){global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$label);$checks++;echo "PASS: $label\n";}
try {
    delete_option('gnf5_article_worker');delete_option('gnf5_lock_cat_1');
    check6(GNF5_Utils::defaults()['image_enabled']===0,'fresh install images default OFF');
    $saved=GNF5_Utils::defaults();$saved['image_enabled']=1;$saved['gemini_api_key']='test-secret-preserved';$saved['auto_publish_enabled']=1;
    $saved['categories'][1]=array('author_id'=>1,'rss'=>'https://example.org/feed','image_mode'=>'off','instructions'=>'Preserve me');
    update_option(GNF5_OPTION,$saved);delete_option('gnf5_migration_version');GNF5_Utils::migrate();
    $up=get_option(GNF5_OPTION);
    check6($up['image_enabled']===1 && $up['gemini_api_key']===$saved['gemini_api_key'] && $up['categories']===$saved['categories'],'migration preserves images, credentials and complete category rows');
    check6($up['auto_publish_enabled']===0 && GNF5_Utils::desired_success_status()==='draft','legacy auto publish disabled');
    GNF5_Utils::migrate();check6(get_option(GNF5_OPTION)===$up,'migration idempotent');
    $out=GNF5_Utils::sanitize_settings(array('gemini_api_key'=>'','auto_publish_enabled'=>1));
    check6($out['gemini_api_key']===$saved['gemini_api_key'] && $out['auto_publish_enabled']===0,'blank key preserved and attempted auto publish setting ignored');
    check6(!GNF5_Utils::images_enabled(1) && GNF5_Utils::images_enabled(),'category image OFF overrides global ON');
    check6(GNF5_Utils::valid_author_id(999999)===0,'missing author never falls back');
    $id=GNF5_Publish::save(array('post_title'=>'Guard fixture','post_content'=>'<p>Test content</p>','post_status'=>'publish','post_type'=>'post','meta_input'=>array('_gnf5_generated_by'=>'fresh-v5')),true);$ids[]=$id;
    check6(!is_wp_error($id) && get_post_status($id)==='draft','plugin insertion forces Draft');
    update_post_meta($id,'rank_math_seo_score',100);GNF5_Publish::maybe_publish($id);GNF5_Publish::flush_scores();
    check6(get_post_status($id)==='draft','score 100 cannot publish');
    GNF5_Publish::checkpoint($id);check6(GNF5_Publish::can_rewrite($id),'unchanged managed draft is recoverable');
    wp_update_post(array('ID'=>$id,'post_content'=>'<p>Human edited the article.</p>'));
    check6(!GNF5_Publish::can_rewrite($id),'human edit breaks rewrite ownership');
    wp_update_post(array('ID'=>$id,'post_status'=>'publish'));
    check6(get_post_status($id)==='publish','human can publish a plugin-created article');
    $normal=wp_insert_post(array('post_title'=>'Ordinary editor post','post_status'=>'publish'));$ids[]=$normal;
    check6(get_post_status($normal)==='publish','ordinary WordPress posts unaffected');
    $saved['gemini_api_key']='';$saved['rankmath_enabled']=1;$saved['seo_analyzer_mode']='local';update_option(GNF5_OPTION,$saved);
    $fixtures=json_decode(file_get_contents(__DIR__.'/rankmath-fixtures.json'),true);
    foreach(array(76,79,80,81,84,85) as $expected){
        $v=$fixtures[$expected]['values'];
        $p=wp_insert_post(array('post_title'=>$v['title'],'post_name'=>'solar-energy-benefits','post_excerpt'=>$v['description'],'post_content'=>$v['content'],'post_status'=>'draft','post_category'=>array(1)));$ids[]=$p;
        foreach(array('_gnf5_generated_by'=>'fresh-v5','_gnf5_state'=>'awaiting_rankmath','rank_math_title'=>$v['title'],'rank_math_description'=>$v['description'],'rank_math_focus_keyword'=>$v['keyword']) as $k=>$value)update_post_meta($p,$k,$value);
        GNF5_RankMath::run($p);
        check6(GNF5_Publish::score($p)===(float)$expected,'genuine Rank Math analyzer score '.$expected.'; '.get_post_meta($p,'_gnf5_seo_error',true));
        check6(get_post_status($p)==='draft','genuine score '.$expected.' remains Draft');
    }
    check6(GNF5_Utils::acquire_lock(1),'worker lock acquired');
    check6(!GNF5_Utils::force_clear_lock(1) && GNF5_Utils::owns_lock(1),'healthy lock cannot be cleared');GNF5_Utils::release_lock(1);
    check6(GNF5_Utils::normalize_url('http://127.0.0.1/a')==='' && GNF5_Utils::normalize_url('http://user:password@example.org')==='','private/credential URLs refused');
    echo "TOTAL: $checks checks passed\n";
} finally {
    foreach($ids as $id)if(is_numeric($id))wp_delete_post($id,true);
    GNF5_Utils::release_lock(1);update_option(GNF5_OPTION,$backup);
}
