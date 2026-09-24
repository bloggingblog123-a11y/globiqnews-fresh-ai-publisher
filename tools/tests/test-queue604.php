<?php
define('DOING_AJAX',true);
require __DIR__.'/test-master-pipeline.php'; // real research/writer/Draft fixtures; 84 baseline assertions.
$start_checks=$checks;$backup=get_option(GNF5_OPTION);$cron=get_option('cron');$logs=get_option(GNF5_LOG_OPTION);$history=get_option('gnf5_topic_history');$cats=array();$created=array();$sent=array();$urls604=array();
class Ajax604 extends RuntimeException {}
add_filter('wp_die_ajax_handler',function(){return function($m=''){throw new Ajax604((string)$m);};});
function ajax604($method,$data,$user=1){wp_set_current_user($user);$_POST=wp_slash($data);$_REQUEST=$_POST;ob_start();try{GNF5_Admin::$method();}catch(Ajax604 $e){return array('json'=>json_decode(ob_get_clean(),true),'stop'=>$e->getMessage());}ob_end_clean();return array();}
function work604($cat){$j=GNF6_Queue::job($cat);return GNF6_Queue::work($cat,$j['id'],$j['token']);}
function reset604($cats,$limit=1){
 foreach($cats as $cat){$j=GNF6_Queue::job($cat);GNF5_Utils::release_token($cat,$j['token']??'');GNF5_Utils::detach_lock($cat);delete_option(GNF6_Queue::key($cat));delete_option('gnf5_run_cat_'.$cat);}
 $s=GNF5_Utils::settings();$s['max_concurrent_categories']=$limit;foreach($cats as $cat)$s['categories'][$cat]=GNF5_Utils::sanitize_category_row(array('author_id'=>1,'post_limit'=>1));update_option(GNF5_OPTION,$s);
}
$loopback=function($pre,$args,$url)use(&$sent){if($url===admin_url('admin-ajax.php') && ($args['body']['action']??'')==='gnf6_category_worker'){$sent[]=$args['body'];return response6('');}return $pre;};add_filter('pre_http_request',$loopback,5,3);add_filter('pre_http_request',$mock,10,3);
try{
 wp_set_current_user(1);
 foreach(array('Sports','Business','AI','Technology') as $name){$t=wp_insert_term('Queue604 '.$name.' '.wp_generate_password(5,false),'category');$cats[]=(int)$t['term_id'];}list($sports,$business,$ai,$tech)=$cats;
 $s=GNF5_Utils::defaults();$s['gemini_api_key']='FIXTURE-SECRET-NOT-REAL';$s['rankmath_enabled']=0;$s['debug_enabled']=1;update_option(GNF5_OPTION,$s);reset604($cats);
 $r=GNF6_Queue::enqueue(array($sports,$business,$ai));
 v6($r['added']===3 && GNF6_Queue::job($sports)['state']==='dispatched' && GNF6_Queue::job($business)['state']==='waiting','concurrency 1 persists all jobs and dispatches only Sports');
 $job=GNF6_Queue::job($sports);$count=count($sent);GNF6_Queue::dispatch_available_category_workers();
 v6(count($sent)===$count && count(GNF5_Utils::worker_states())===1,'repeat dispatcher creates no duplicate worker or occupied slot');
 v6(!GNF6_Queue::work($sports,$job['id'],'invalid-token'),'unauthenticated worker cannot consume a claim');
 v6(GNF6_Queue::enqueue(array($sports))['added']===0,'same category trigger reports already running/waiting');
 v6(work604($sports),'Sports empty-source worker executes');
 v6(GNF6_Queue::job($sports)['state']==='exhausted' && !GNF5_Utils::is_locked($sports),'zero-Draft exhausted category finalizes and releases category lock');
 v6(GNF6_Queue::job($business)['state']==='dispatched' && count($sent)===$count+1,'Business dispatched immediately from Sports completion, without cron or polling');
 v6(!GNF6_Queue::work($sports,$job['id'],$job['token']),'replayed completed claim cannot execute');
 work604($business);v6(GNF6_Queue::job($ai)['state']==='dispatched','AI dispatched immediately from Business completion');work604($ai);
 v6(count(GNF5_Utils::worker_states())===0,'exhausted queue drains with zero active slots');
 reset604($cats);$sent=array();
 $s=GNF5_Utils::settings();$s['categories'][$sports]['rss']='https://failure604.example.org/feed';update_option(GNF5_OPTION,$s);$urls604[]=$s['categories'][$sports]['rss'];
 $throw=function($pre,$args,$url){if(strpos($url,'failure604.example.org')!==false)throw new RuntimeException('Fixture source exception');return $pre;};add_filter('pre_http_request',$throw,7,3);
 GNF6_Queue::enqueue(array($sports,$business));work604($sports);remove_filter('pre_http_request',$throw,7);
 v6(GNF6_Queue::job($sports)['state']==='failed' && !GNF5_Utils::is_locked($sports),'thrown source exception finalizes failed and releases its lock');
 v6(GNF6_Queue::job($business)['state']==='dispatched','failed category immediately hands slot to Business');work604($business);
 reset604($cats,2);$sent=array();GNF6_Queue::enqueue(array($tech,$sports,$business,$ai));
 $tj=GNF6_Queue::job($tech);$running=$tj;$running['state']='processing';GNF5_Utils::compare_option(GNF6_Queue::key($tech),$tj,$running);
 v6(count(GNF5_Utils::worker_states())===2 && GNF6_Queue::job($sports)['state']==='dispatched' && GNF6_Queue::job($business)['state']==='waiting','concurrency 2 reserves Technology and Sports, leaves other categories waiting');
 work604($sports);
 v6(GNF6_Queue::job($tech)['state']==='processing' && GNF6_Queue::job($business)['state']==='dispatched' && GNF6_Queue::job($ai)['state']==='waiting','Sports completion starts Business while Technology remains active');
 v6(count(GNF5_Utils::worker_states())===2,'replacement worker stays within concurrency 2');
 work604($business);work604($ai);
 // Restore the deliberately held worker to its claim so its actual cleanup can run.
 GNF5_Utils::compare_option(GNF6_Queue::key($tech),$running,$tj);work604($tech);
 v6(count(GNF5_Utils::worker_states())===0,'all concurrency 2 workers release their own slots');
 reset604($cats);$s=GNF5_Utils::settings();$blocked='https://blocked.example.org/queue604-feed';$urls604[]=$blocked;$s['categories'][$sports]['rss']=$blocked;update_option(GNF5_OPTION,$s);delete_transient(GNF5_Utils::blocked_key($blocked));
 GNF6_Queue::enqueue(array($sports,$business));work604($sports);$retry=wp_next_scheduled(GNF6_Queue::RETRY,array($sports,'rss',$blocked));
 v6(GNF6_Queue::job($sports)['state']==='exhausted' && GNF6_Queue::job($business)['state']==='dispatched','blocked RSS exhausts Sports and immediately starts Business');
 v6($retry>=time()+21590 && $retry<=time()+21600 && !GNF5_Utils::is_locked($sports),'six-hour retry is independent and occupies no Sports slot');work604($business);
 // Authoritative empty RSS save and retry cancellation through actual registered sanitizer/AJAX.
 GNF5_Admin::register_settings();$r=ajax604('ajax_save_category',array('nonce'=>wp_create_nonce('gnf5_ajax'),'cat_id'=>$sports,'rss'=>''));
 v6(!empty($r['json']['success']) && GNF5_Utils::category_settings($sports)['rss']==='','explicit empty RSS persists through category save');
 v6(!wp_next_scheduled(GNF6_Queue::RETRY,array($sports,'rss',$blocked)),'removing source cancels pending six-hour retry');
 $before=GNF6_Queue::job($sports);GNF6_Queue::retry_source($sports,'rss',$blocked);v6(GNF6_Queue::job($sports)===$before,'stale retry callback cannot re-enqueue removed RSS');
 $r=ajax604('ajax_test_category_sources',array('nonce'=>wp_create_nonce('gnf5_ajax'),'cat_id'=>$sports));$d=$r['json']['data'];
 v6($d['configuration']['rss_configured']===0 && $d['configuration']['rss_tested']===0 && strpos($d['message'],'RSS status: SKIPPED')!==false,'source test explicitly reports RSS configured 0, tested 0, SKIPPED');
 remove_filter('sanitize_option_'.GNF5_OPTION,array('GNF5_Utils','sanitize_settings'));
 // TOI-like category HTML must never revive its removed declared RSS feed.
 $toi='https://timesofindia.indiatimes.com/rssfeeds/54829575.cms';$listing='https://timesofindia.indiatimes.com/sports';$seen=array();$urls604[]=$toi;
 $source_mock=function($pre,$args,$url)use(&$seen,$toi,$listing){if(strpos($url,'timesofindia.indiatimes.com')===false)return $pre;$seen[]=$url;if($url===$listing)return response6('<html><head><link rel="alternate" type="application/rss+xml" title="Sports" href="'.$toi.'"></head><body><h1>Sports</h1></body></html>');return response6('',404);};add_filter('pre_http_request',$source_mock,7,3);
 GNF5_Utils::mark_blocked($toi,'Fixture old blocked feed',403,'application/rss+xml');
 $s=GNF5_Utils::settings();$s['categories'][$sports]['urls']=$listing;update_option(GNF5_OPTION,$s);$r=ajax604('ajax_test_category_sources',array('nonce'=>wp_create_nonce('gnf5_ajax'),'cat_id'=>$sports));
 v6(!in_array($toi,$seen,true) && $r['json']['data']['configuration']['rss_tested']===0,'Source URL does not request its declared TOI RSS feed when RSS is empty');
 v6(strpos($r['json']['data']['message'],'Feed is temporarily skipped')===false,'old blocked feed history does not leak into current source test');
 remove_filter('pre_http_request',$source_mock,7);
 reset604($cats);GNF5_Utils::acquire_lock($tech);GNF6_Queue::enqueue(array($sports));
 $s=GNF5_Utils::settings();$s['categories'][$sports]['rss']=$blocked;update_option(GNF5_OPTION,$s);$s['categories'][$sports]['rss']='';update_option(GNF5_OPTION,$s);GNF5_Utils::release_lock($tech);work604($sports);
 v6(GNF6_Queue::job($sports)['state']==='exhausted' && strpos(GNF6_Queue::job($sports)['message'],'no automatic discovery method')!==false,'queued worker reloads latest saved empty RSS rather than a queue snapshot');
 reset604($cats);GNF6_Queue::enqueue(array($sports,$business));$j=GNF6_Queue::job($sports);GNF5_Utils::release_token($sports,$j['token']);GNF6_Queue::recover();
 v6(GNF6_Queue::job($sports)['token']!==$j['token'] && GNF6_Queue::job($sports)['state']==='dispatched','watchdog repairs missing processing lock and reclaims job');
 v6(!GNF6_Queue::work($sports,$j['id'],$j['token']),'stale worker token cannot consume replacement claim');work604($sports);work604($business);
 $orphan=array('category'=>$tech,'token'=>'orphan-fixture','time'=>time());GNF5_Utils::atomic_add(GNF5_Utils::worker_key(0),$orphan);v6(count(GNF5_Utils::worker_states())===0,'worker count derives from valid locks and removes orphaned slot');
 reset604($cats);GNF6_Queue::enqueue(array($sports,$business));$j=GNF6_Queue::job($sports);GNF6_Queue::cron_fallback();
 v6(GNF6_Queue::job($sports)['state']==='exhausted' && GNF6_Queue::job($business)['state']==='dispatched','cron fallback executes missed loopback and completion immediately dispatches next');work604($business);
 // Real successful article processing, with two saved Drafts bounded by one run ID.
 reset604($cats);$case='QueueCedar604';$s=GNF5_Utils::settings();$s['categories'][$sports]['rss']='https://research.example.org/'.$case.'/feed';$s['categories'][$sports]['post_limit']=1;update_option(GNF5_OPTION,$s);
 GNF6_Queue::enqueue(array($sports,$business));$j=GNF6_Queue::job($sports);work604($sports);
 $ids=get_posts(array('post_type'=>'post','post_status'=>'any','numberposts'=>10,'meta_key'=>'_gnf5_run_id','meta_value'=>$j['id'],'fields'=>'ids'));$created=array_merge($created,$ids);
 v6(count($ids)===1 && GNF6_Queue::job($sports)['state']==='completed','actual research/writer worker creates its target and completes');
 v6(get_post_status($ids[0])==='draft' && GNF6_Queue::job($sports)['created']===1,'actual queue article remains Draft with persisted created count');
 v6(GNF6_Queue::job($business)['state']==='dispatched','successful completion starts next category immediately');work604($business);
 v6(!GNF6_Queue::work($sports,$j['id'],$j['token']) && GNF5_Runner::run_count($j['id'])===1,'replayed worker cannot exceed post limit');
 $r=ajax604('ajax_enqueue_categories',array('nonce'=>'invalid','cat_ids'=>array($sports)));v6($r['stop']==='-1','enqueue requires valid administrator nonce');
 $r=ajax604('ajax_category_queue_status',array('nonce'=>wp_create_nonce('gnf5_ajax')),0);v6(empty($r['json']['success']),'queue status rejects unauthorized users');
 $encoded=wp_json_encode(GNF6_Queue::status());v6(strpos($encoded,'"token"')===false && strpos($encoded,'FIXTURE-SECRET')===false,'status exposes no worker token or provider credentials');
 reset604($cats);wp_set_current_user(1);$nonce=wp_create_nonce('gnf5_ajax');
 $r=ajax604('ajax_enqueue_categories',array('nonce'=>$nonce,'cat_ids'=>array($sports,$business,$ai)));
 v6(!empty($r['json']['success']) && $r['json']['data']['queue']['added']===3,'authenticated Run Selected handler accepts complete category array');
 work604($sports);work604($business);work604($ai);
 reset604($cats);$s=GNF5_Utils::settings();$s['categories'][$sports]['enabled']=1;update_option(GNF5_OPTION,$s);GNF6_Queue::enqueue(array($sports),'cron');GNF6_Queue::enqueue(array($business));
 $s['categories'][$sports]['enabled']=0;update_option(GNF5_OPTION,$s);work604($sports);
 v6(GNF6_Queue::job($sports)['state']==='cancelled' && GNF6_Queue::job($business)['state']==='dispatched','disabled scheduled job cancels and immediately dispatches Business');work604($business);
 reset604($cats);$s=GNF5_Utils::settings();$s['categories'][$sports]['enabled']=0;update_option(GNF5_OPTION,$s);GNF6_Queue::enqueue(array($sports,$business),'cron');
 v6(GNF6_Queue::job($sports)['state']==='skipped' && GNF6_Queue::job($business)['state']==='skipped' && !GNF5_Utils::worker_states(),'ineligible scheduled categories skip without retaining slots');
 reset604($cats);GNF6_Queue::enqueue(array($sports,$business));$j=GNF6_Queue::job($sports);$done=$j;$done['state']='completed';$done['token']='';GNF5_Utils::compare_option(GNF6_Queue::key($sports),$j,$done);GNF6_Queue::recover();
 v6(!GNF5_Utils::is_locked($sports) && GNF6_Queue::job($business)['state']==='dispatched','terminal job with leftover live-looking slot is repaired immediately');work604($business);
 reset604($cats);GNF5_Utils::acquire_lock($tech);GNF6_Queue::enqueue(array($sports));$j=GNF6_Queue::job($sports);$claimed=$j;$claimed['state']='claiming';
 v6(GNF5_Utils::compare_option(GNF6_Queue::key($sports),$j,$claimed) && !GNF5_Utils::compare_option(GNF6_Queue::key($sports),$j,$claimed),'two stale snapshots cannot both atomically claim the same waiting job');
 GNF5_Utils::compare_option(GNF6_Queue::key($sports),$claimed,$j);GNF5_Utils::release_lock($tech);work604($sports);
 $s=GNF5_Utils::settings();$s['categories'][$sports]['rss']=$blocked;update_option(GNF5_OPTION,$s);delete_transient(GNF5_Utils::blocked_key($blocked));
 $r=ajax604('ajax_test_category_sources',array('nonce'=>$nonce,'cat_id'=>$sports));$d=$r['json']['data']['sources'][0];
 v6($d['type']==='RSS' && $d['http']===403 && $d['content_type']==='text/html' && $d['parser']==='BLOCKED' && $d['next_retry']>=time()+21590,'blocked RSS test exposes actual HTTP, content type, parser and retry timestamp');
 $s['categories'][$sports]['rss']='https://research.example.org/Report604/feed';update_option(GNF5_OPTION,$s);$r=ajax604('ajax_test_category_sources',array('nonce'=>$nonce,'cat_id'=>$sports));$d=$r['json']['data']['sources'][0];
 v6($d['http']===200 && $d['content_type']==='application/rss+xml' && $d['parser']==='PASS' && $d['candidate_count']>0,'healthy RSS test exposes actual parser success and item count');
 reset604($cats);$case='QueueOak604';$s=GNF5_Utils::settings();$s['categories'][$sports]['rss']='https://queue604.example.org/feed';$s['categories'][$sports]['post_limit']=2;update_option(GNF5_OPTION,$s);
 $multi=function($pre,$args,$url)use(&$case){if($url!=='https://queue604.example.org/feed')return $pre;return response6('<rss version="2.0"><channel><title>Queue</title><link>https://queue604.example.org/</link><description>Fixture</description><item><title>'.$case.' laboratory study</title><link>https://research.example.org/'.$case.'/battery-study-a</link></item></channel></rss>',200,'application/rss+xml');};add_filter('pre_http_request',$multi,7,3);
 GNF6_Queue::enqueue(array($sports,$business));$j=GNF6_Queue::job($sports);work604($sports);$again=GNF6_Queue::job($sports);
 v6($again['state']==='dispatched' && $again['id']===$j['id'] && $again['created']===1 && GNF6_Queue::job($business)['state']==='waiting','partial category target immediately continues same persisted run before next category');
 $case='QueueElm604';work604($sports);$ids=get_posts(array('post_type'=>'post','post_status'=>'any','numberposts'=>10,'meta_key'=>'_gnf5_run_id','meta_value'=>$j['id'],'fields'=>'ids'));$created=array_merge($created,$ids);
 v6(count($ids)===2 && GNF6_Queue::job($sports)['state']==='completed' && GNF6_Queue::job($business)['state']==='dispatched','two background chunks reach exact post limit then start Business');
 v6(count(array_filter($ids,function($id){return get_post_status($id)==='draft';}))===2,'every article from multi-chunk queue remains Draft');work604($business);remove_filter('pre_http_request',$multi,7);
 reset604($cats);$s=GNF5_Utils::settings();$s['categories'][$sports]['rss']='https://research.example.org/BlockedBatch604/feed';update_option(GNF5_OPTION,$s);
 update_option('gnf5_run_cat_'.$sports,array('id'=>'legacy-active-batch','target'=>5,'updated'=>time(),'status'=>'waiting'),false);GNF6_Queue::enqueue(array($sports,$business));work604($sports);
 v6(GNF6_Queue::job($sports)['state']==='blocked' && GNF6_Queue::job($business)['state']==='dispatched','legacy batch conflict finalizes blocked and dispatches next category');work604($business);
 echo 'QUEUE/RSS: '.($checks-$start_checks)." checks passed\n";
}finally{
 remove_filter('sanitize_option_'.GNF5_OPTION,array('GNF5_Utils','sanitize_settings'));remove_filter('pre_http_request',$loopback,5);remove_filter('pre_http_request',$mock,10);
 foreach($cats as $cat){$j=GNF6_Queue::job($cat);GNF5_Utils::release_token($cat,$j['token']??'');GNF5_Utils::detach_lock($cat);delete_option(GNF6_Queue::key($cat));delete_option('gnf5_run_cat_'.$cat);wp_delete_term($cat,'category');}
 foreach($created as $id)wp_delete_post($id,true);foreach($urls604 as $url)delete_transient(GNF5_Utils::blocked_key($url));
 update_option(GNF5_OPTION,$backup);update_option('cron',$cron);update_option(GNF5_LOG_OPTION,$logs);update_option('gnf5_topic_history',$history);
}
