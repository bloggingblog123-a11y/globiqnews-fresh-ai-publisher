<?php
// Reuse the isolated pipeline fixtures; this also runs their 84 checks.
require __DIR__.'/test-master-pipeline.php';
$start_checks=$checks;$backup=get_option(GNF5_OPTION);$queue_backup=get_option(GNF5_BULK_RECOVERY_OPTION,array());$history_backup=get_option('gnf5_topic_history',array());
$term=wp_insert_term('Media test '.wp_generate_password(6,false),'category');$cat=(int)$term['term_id'];$created=array();$media=array();$case='Sequoia';$mode='normal';
add_filter('pre_http_request',$mock,10,3);
function draft6($cat,$article,$research){
    $id=GNF5_Publish::save(array('post_title'=>$article['title'],'post_content'=>GNF5_SEO::build_gutenberg_content($article['content_html']),'post_author'=>1,'post_category'=>array($cat),'meta_input'=>array('_gnf5_generated_by'=>'fresh-v5','_gnf5_state'=>'image_pending','_gnf5_article_data'=>$article)),true);
    GNF5_Research::store($id,$research);GNF5_SEO::save_rank_math($id,$article);GNF5_Publish::checkpoint($id);return $id;
}
try{
    setup6($cat,0);$research=GNF5_Research::gather(array('url'=>'https://research.example.org/'.$case.'/battery-study-a','method'=>'Manual URL'),$cat);
    v6(!is_wp_error($research),'media fixtures have real structured research');
    $article=GNF5_Writer::create_article($research,$cat);v6(!is_wp_error($article),'media fixture article passes checks');
    $s=GNF5_Utils::settings();$s['image_enabled']=1;$s['image_provider']='builtin';$s['rankmath_enabled']=0;update_option(GNF5_OPTION,$s);
    $id=draft6($cat,$article,$research);$created[]=$id;
    $r=GNF5_Runner::retry_post($id);$ids=(array)get_post_meta($id,'_gnf5_image_ids',true);$media=array_merge($media,$ids);
    v6(!is_wp_error($r) && count($ids)===2,'image ON recovery generates exactly two actual local images');
    v6(get_post_thumbnail_id($id)===$ids[0],'first generated image is featured');
    $content=get_post_field('post_content',$id);
    v6(strpos($content,'wp-image-'.$ids[0])===false && substr_count($content,'wp-image-'.$ids[1])===1,'featured image is not duplicated inline; second appears once');
    foreach($ids as $image){$file=get_attached_file($image);$info=getimagesize($file);v6($info[0]===1200 && $info[1]===675 && $info['mime']==='image/webp','actual generated file is 1200x675 WebP');v6(filesize($file)<400000,'generated WebP is lightweight');}
    v6(md5_file(get_attached_file($ids[0]))!==md5_file(get_attached_file($ids[1])),'two generated images have different file contents');
    $again=GNF5_Runner::retry_post($id);v6(!is_wp_error($again) && get_post_meta($id,'_gnf5_image_ids',true)===$ids,'recovery reuses valid image checkpoints');
    // Actual manual media upload fixture (ownership marker deliberately absent).
    $upload=wp_upload_dir();$file=$upload['path'].'/manual-review-'.wp_generate_password(6,false).'.png';$im=imagecreatetruecolor(90,60);imagepng($im,$file);imagedestroy($im);
    $manual=wp_insert_attachment(array('post_title'=>'My own laboratory photograph','post_mime_type'=>'image/png','post_parent'=>$id),$file,$id,true);$media[]=$manual;
    wp_update_attachment_metadata($manual,array('width'=>90,'height'=>60,'file'=>_wp_relative_upload_path($file)));update_post_meta($manual,'_wp_attachment_image_alt','My reviewed manual ALT');
    set_post_thumbnail($id,$manual);wp_update_post(array('ID'=>$id,'post_content'=>$content.GNF5_Images::block($manual,'My reviewed manual ALT')));
    $before=count(get_posts(array('post_type'=>'attachment','post_status'=>'inherit','numberposts'=>-1)));
    v6(is_wp_error(GNF5_Runner::retry_post($id)),'manual image insertion prevents background body rewrite');
    v6(GNF5_Utils::acquire_lock($cat),'explicit image action obtains single-worker lock');
    $action=GNF5_Runner::image_action($id,'generate_images');GNF5_Utils::release_lock($cat);
    v6(!is_wp_error($action) && count(get_posts(array('post_type'=>'attachment','post_status'=>'inherit','numberposts'=>-1)))===$before,'explicit missing-image action creates nothing when both slots have manual media');
    v6(get_post_thumbnail_id($id)===$manual && strpos(get_post_field('post_content',$id),'wp-image-'.$manual)!==false && get_post_meta($manual,'_wp_attachment_image_alt',true)==='My reviewed manual ALT','manual featured/body image and ALT preserved');
    $removed=GNF5_Images::remove_generated($id);
    v6(!is_wp_error($removed) && GNF5_Utils::valid_attachment($manual) && get_post_thumbnail_id($id)===$manual,'remove generated images leaves actual manual file and thumbnail untouched');
    v6(!get_post($ids[0]) && !get_post($ids[1]),'remove generated deletes only owned generated attachments');
    // A partial OpenAI response is mocked; optimization, media insertion and recovery are real.
    $s=GNF5_Utils::settings();$s['image_provider']='openai';$s['openai_api_key']='FAKE-IMAGE-KEY';$s['builtin_fallback']=0;update_option(GNF5_OPTION,$s);
    $im=imagecreatetruecolor(40,30);$color=imagecolorallocate($im,20,50,90);imagefill($im,0,0,$color);ob_start();imagepng($im);$png=ob_get_clean();imagedestroy($im);$image_calls=0;
    $image_mock=function($pre,$args,$url)use(&$image_calls,$png){if(strpos($url,'api.openai.com/v1/images/generations')===false)return $pre;$image_calls++;return $image_calls===1?response6(wp_json_encode(array('data'=>array(array('b64_json'=>base64_encode($png))))),200,'application/json'):response6('Fixture unavailable',503);};
    add_filter('pre_http_request',$image_mock,20,3);
    $partial=draft6($cat,$article,$research);$created[]=$partial;$r=GNF5_Runner::retry_post($partial);$saved=(array)get_post_meta($partial,'_gnf5_image_ids',true);$media=array_merge($media,$saved);
    v6(!is_wp_error($r) && empty($r['validated']) && count($saved)===1 && get_post_status($partial)==='draft','partial image failure retains body, Draft status and first successful attachment');
    v6($image_calls===4,'one successful image plus at most three failed requests for missing slot');
    remove_filter('pre_http_request',$image_mock,20);
    $s=GNF5_Utils::settings();$s['image_provider']='builtin';update_option(GNF5_OPTION,$s);$r=GNF5_Runner::retry_post($partial);$finished=(array)get_post_meta($partial,'_gnf5_image_ids',true);$media=array_merge($media,$finished);
    v6(!is_wp_error($r) && !empty($r['validated']) && count($finished)===2 && $finished[0]===$saved[0],'retry generates only missing image and preserves first checkpoint');
    // Originality regeneration discards failed prose rather than giving it back to the writer.
    $mode='copy';$before=count($prompts);$failed=GNF5_Writer::create_article($research,$cat);$new_prompts=array_slice($prompts,$before);$writes=0;$plans=0;
    foreach($new_prompts as $p){if(strpos($p['prompt'],'TASK: WRITE_ORIGINAL_ARTICLE')!==false)$writes++;if(strpos($p['prompt'],'TASK: PLAN_ORIGINAL_ARTICLE')!==false)$plans++;}
    v6(is_wp_error($failed) && $failed->get_error_code()==='originality_failed' && $writes===3 && $plans===3,'originality failure regenerates with new planning at most twice; result='.(is_wp_error($failed)?$failed->get_error_code():'article').', writes='.$writes.', plans='.$plans);$mode='normal';
    // Actual Rank Math analyzer with fixture Gemini optimizations; no publication at any score.
    $s=GNF5_Utils::settings();$s['rankmath_enabled']=1;$s['seo_analyzer_mode']='local';$s['image_enabled']=0;update_option(GNF5_OPTION,$s);
    $seo=draft6($cat,$article,$research);$created[]=$seo;GNF5_Utils::set_state($seo,'awaiting_rankmath');$before=count($prompts);GNF5_RankMath::run($seo);
    $attempts=(int)get_post_meta($seo,'_gnf5_seo_repair_attempts',true);
    v6(GNF5_Publish::score($seo)!==null && get_post_status($seo)==='draft','actual Rank Math scores the research article and leaves Draft');
    v6($attempts>=1 && $attempts<=3,'low-score article uses at most three actual SEO optimization calls');
    $before=count($prompts);GNF5_RankMath::run($seo);v6(count($prompts)===$before,'repeat scoring does not restart exhausted SEO optimizations');
    // Existing queue handles one article per step, keeps busy work, and resumes stale processing.
    $s['rankmath_enabled']=0;update_option(GNF5_OPTION,$s);delete_option(GNF5_BULK_RECOVERY_OPTION);delete_option(GNF5_BULK_RECOVERY_LOCK);
    $q1=draft6($cat,$article,$research);$q2=draft6($cat,$article,$research);$created[]=$q1;$created[]=$q2;
    $enqueued=GNF5_Runner::enqueue_bulk_recovery(array($q1,$q2,$q1));v6(!is_wp_error($enqueued) && $enqueued['pending']===2,'bulk recovery deduplicates and queues two drafts');
    GNF5_Utils::acquire_lock($cat);$busy=GNF5_Runner::bulk_recovery_step();GNF5_Utils::release_lock($cat);
    v6(!empty($busy['deferred']) && $busy['pending']===2,'busy worker preserves queue and defers without losing a draft');
    $one=GNF5_Runner::bulk_recovery_step();v6($one['pending']===1 && !empty($one['processed_post']),'one bulk step processes exactly one draft');
    $two=GNF5_Runner::bulk_recovery_step();v6($two['pending']===0,'second bulk step drains the remaining draft');
    update_option(GNF5_BULK_RECOVERY_OPTION,array($q1=>array('post_id'=>$q1,'state'=>'processing','updated'=>time()-1900)),false);
    $status=GNF5_Runner::bulk_recovery_status();$persisted=get_option(GNF5_BULK_RECOVERY_OPTION);
    v6($status['queued']===1 && $persisted[$q1]['state']==='queued','stale processing state is re-queued and persisted even when count is unchanged');
    // Partial settings submits and API errors do not erase credentials or invent success.
    $before_settings=GNF5_Utils::settings();$clean=GNF5_Utils::sanitize_settings(array('global_article_instructions'=>'Changed instruction'));
    v6($clean['image_enabled']===$before_settings['image_enabled'] && $clean['categories'][$cat]['author_id']===1 && $clean['gemini_api_key']===$before_settings['gemini_api_key'],'partial save preserves omitted global/category settings and API key');
    $mode='provider_error';$before=count($prompts);$error=GNF5_Writer::gemini_json('Test provider error');v6(is_wp_error($error) && count($prompts)-$before===3,'provider failure is bounded to three transport requests');$mode='normal';
    $report=GNF5_Quality::evaluate($article,$research,false);v6($report['originality']['status']==='UNKNOWN' && $report['facts']['status']==='NOT CHECKED','unperformed semantic/factual checks never claim PASS');
    // Failed candidates never fill a three-Draft target; GDELT failure leaves RSS usable.
    $limit_mock=function($pre,$args,$url)use(&$case){
        if(strpos($url,'api.gdeltproject.org')!==false)return response6('Fixture GDELT outage',503);
        if(strpos($url,'limits.example.org/feed')!==false){
            $xml='<rss version="2.0"><channel><title>Bounded batch</title><link>https://limits.example.org/</link><description>Fixtures</description>';
            foreach(array('Denied','Sable','Meridian','Vesta','Lumen') as $entity)$xml.='<item><title>'.$entity.'</title><link>https://limits.example.org/'.$entity.'</link></item>';
            return response6($xml.'</channel></rss>',200,'application/rss+xml');
        }
        if(strpos($url,'limits.example.org/Denied')!==false)return response6('Forbidden',403);
        if(strpos($url,'limits.example.org/')!==false){$case=basename(wp_parse_url($url,PHP_URL_PATH));return response6(source6($case));}
        return $pre;
    };add_filter('pre_http_request',$limit_mock,30,3);
    $s=GNF5_Utils::settings();$s['categories'][$cat]['rss']='https://limits.example.org/feed';$s['categories'][$cat]['urls']='';
    $s['categories'][$cat]['gdelt_enabled']=1;$s['categories'][$cat]['gdelt_keywords']='unique-limit-test-'.wp_generate_password(6,false);$s['categories'][$cat]['post_limit']=3;update_option(GNF5_OPTION,$s);
    $batch='limits-'.wp_generate_uuid4();$results=array();
    for($i=0;$i<3;$i++)$results[]=GNF5_Runner::run_category($cat,'test',1,1,$batch);
    $batch_ids=get_posts(array('post_type'=>'post','post_status'=>'draft','numberposts'=>10,'meta_key'=>'_gnf5_run_id','meta_value'=>$batch,'fields'=>'ids'));$created=array_merge($created,$batch_ids);
    v6(count($batch_ids)===3 && GNF5_Runner::run_count($batch)===3,'three-request batch creates exactly three usable Drafts after first candidate fails: '.wp_json_encode($results));
    v6(!is_wp_error($results[0]) && $results[0]['failed_before_create']>=1,'failed source does not consume a successful-Draft slot');
    v6(strpos(implode(' ', $results[0]['diagnostics']),'GDELT:')!==false,'GDELT outage reported while RSS still creates Draft');
    $extra=GNF5_Runner::run_category($cat,'test',1,1,$batch);v6(!is_wp_error($extra) && !empty($extra['done']) && GNF5_Runner::run_count($batch)===3,'repeated completed run cannot exceed configured three-Draft target');
    remove_filter('pre_http_request',$limit_mock,30);
    echo 'MEDIA/QUEUE: '.($checks-$start_checks)." checks passed\nTOTAL PIPELINE + MEDIA/QUEUE: $checks checks passed\n";
}finally{
    remove_filter('pre_http_request',$mock,10);if(isset($image_mock))remove_filter('pre_http_request',$image_mock,20);if(isset($limit_mock))remove_filter('pre_http_request',$limit_mock,30);
    foreach(array_unique($media) as $id)if(get_post($id))wp_delete_attachment($id,true);foreach($created as $id)wp_delete_post($id,true);
    GNF5_Utils::release_lock($cat);wp_delete_term($cat,'category');delete_option('gnf5_run_cat_'.$cat);delete_option(GNF5_BULK_RECOVERY_LOCK);
    update_option(GNF5_OPTION,$backup);update_option(GNF5_BULK_RECOVERY_OPTION,$queue_backup,false);update_option('gnf5_topic_history',$history_backup,false);
}
