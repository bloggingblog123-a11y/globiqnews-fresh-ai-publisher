<?php
define('DOING_AJAX',true);
$_SERVER['HTTP_HOST']='globiqnews.localhost:8097';$_SERVER['REQUEST_URI']='/';
require_once __DIR__.'/bootstrap600.php';
$checks=0;$created=array();$backup=get_option(GNF5_OPTION);$logs=get_option(GNF5_LOG_OPTION,array());$migration=get_option('gnf5_migration_version');$cron_backup=get_option('cron');$queue_backup=get_option(GNF5_BULK_RECOVERY_OPTION,array());
function secure6($ok,$label){global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$label);echo 'PASS: '.$label."\n";$checks++;}
class StopAjax6 extends RuntimeException {}
add_filter('wp_die_ajax_handler',function(){return function($message=''){throw new StopAjax6(is_scalar($message)?(string)$message:'stopped');};});
function ajax6($post,$user=1){wp_set_current_user($user);$_POST=$post;$_REQUEST=$post;ob_start();try{GNF5_Admin::ajax_article_action();}catch(StopAjax6 $e){$out=ob_get_clean();return array('data'=>json_decode($out,true),'stop'=>$e->getMessage());}ob_end_clean();return array();}
try{
    $s=GNF5_Utils::defaults();$s['gemini_api_key']='SECURITY-FIXTURE-NOT-A-REAL-KEY';$s['rankmath_enabled']=0;update_option(GNF5_OPTION,$s);
    $id=GNF5_Publish::save(array('post_title'=>'Security test Draft','post_content'=>'<p>Local disposable Draft.</p>','meta_input'=>array('_gnf5_generated_by'=>'fresh-v5')),true);$created[]=$id;
    wp_set_current_user(1);$nonce=wp_create_nonce('gnf5_ajax');
    $base=array('nonce'=>$nonce,'post_id'=>$id,'task'=>'images');
    $r=ajax6($base,0);secure6(($r['data']['success']??true)===false,'anonymous user cannot run article actions');
    $r=ajax6(array_merge($base,array('nonce'=>'invalid')));secure6($r['stop']==='-1','invalid nonce rejected before processing');
    $r=ajax6(array_merge($base,array('nonce'=>array('invalid'))));secure6(($r['data']['success']??true)===false,'array nonce rejected without PHP fatal');
    $r=ajax6(array_merge($base,array('post_id'=>array($id))));secure6(($r['data']['success']??true)===false,'malformed post ID rejected without PHP fatal');
    $r=ajax6(array_merge($base,array('post_id'=>999999999)));secure6(($r['data']['success']??true)===false,'missing post cannot be acted on');
    $normal=wp_insert_post(array('post_status'=>'draft','post_title'=>'Ordinary local post'));$created[]=$normal;
    $r=ajax6(array_merge($base,array('post_id'=>$normal)));secure6(($r['data']['success']??true)===false,'ordinary WordPress post excluded from plugin actions');
    $r=ajax6(array_merge($base,array('task'=>'regenerate')));secure6(($r['data']['success']??true)===false && strpos($r['data']['data']['message'],'Confirm')!==false,'text regeneration requires explicit confirmation');
    $r=ajax6(array_merge($base,array('task'=>'save_alt','attachment_id'=>999999)));secure6(($r['data']['success']??true)===false,'unrelated attachment ALT cannot be changed');
    $r=ajax6($base);secure6(($r['data']['success']??false)===true && get_post_status($id)==='draft','permitted image diagnostic works and stays Draft');
    secure6(!GNF5_Utils::is_locked(0),'action releases its worker lock');
    wp_update_post(array('ID'=>$id,'post_status'=>'publish'));$r=ajax6($base);secure6(($r['data']['success']??true)===false && get_post_status($id)==='publish','human-published generated article remains untouched');
    delete_option('gnf5_migration_version');update_option('gnf5_migration_lock',time()-301,false);GNF5_Utils::migrate();
    secure6(get_option('gnf5_migration_version')==='6.0.0' && !get_option('gnf5_migration_lock'),'interrupted migration recovers stale lock');
    secure6(GNF5_Utils::settings()['gemini_api_key']===$s['gemini_api_key'],'migration retry preserves provider key');
    GNF5_Utils::log('sensitive '.$s['gemini_api_key'].' https://example.org/?token=private','failure');$log=get_option(GNF5_LOG_OPTION);
    secure6(strpos($log[0]['message'],$s['gemini_api_key'])===false && strpos($log[0]['message'],'token=private')===false,'logs redact stored credentials and sensitive URL parameters');
    $count=count($log);GNF5_Utils::log('debug disabled','debug');secure6(count(get_option(GNF5_LOG_OPTION))===$count,'debug OFF adds no diagnostic detail');
    $s['debug_enabled']=1;update_option(GNF5_OPTION,$s);GNF5_Utils::log('debug enabled','debug');secure6(get_option(GNF5_LOG_OPTION)[0]['type']==='debug','debug ON records diagnostic detail');
    $sources=array(array('id'=>'S1','url'=>'https://evidence.example.org/a','text'=>'The program involves 112 engineers. Research results will be published after the trial.'),array('id'=>'S2','url'=>'https://unrelated.example.net/b','text'=>'Unrelated information from another accessible page.'));
    $raw=array('facts'=>array(array('subject'=>'Program','detail'=>'The team has 12 engineers.','evidence'=>array(array('source'=>'S1','excerpt'=>'The program involves 112 engineers.')))));
    secure6(!GNF5_Research::validate($raw,$sources)['facts'],'numeric substrings do not masquerade as evidence');
    $raw['facts'][0]['detail']='The team has 112 engineers.';$research=GNF5_Research::validate($raw,$sources);
    secure6(count($research['facts'])===1,'numeric evidence accepts punctuation after a complete number');
    secure6($research['source_count']===1 && $research['fetched_source_count']===2 && $research['independent_source_estimate']===1,'unrelated fetched reference does not inflate source count');
    $quote='We will review every published result carefully before deciding how this research should progress into the next stage';
    $qs=array(array('id'=>'S1','url'=>'https://evidence.example.org/q','text'=>'Dr Rao said: '.$quote.'.'));
    $qraw=array('facts'=>array(array('kind'=>'quote','subject'=>'Dr Rao','detail'=>'Research review precedes the next stage.','quote'=>$quote,'attribution'=>'Dr Rao','evidence'=>array(array('source'=>'S1','excerpt'=>$qs[0]['text'])))));
    $qr=GNF5_Research::validate($qraw,$qs);secure6(count($qr['facts'])===1 && isset($qr['facts'][0]['quote']),'short direct quotation requires exact evidence and attribution');
    $qa=array('content_html'=>'<p>Dr Rao said: “'.$quote.'”.</p><p>Readers can distinguish a research process from a commercial promise through its stated review steps.</p>');
    secure6(GNF5_Quality::originality($qa,$qs,$qr['facts'])['status']==='PASS','evidence-backed marked quotation is exempt from lexical copying');
    $qraw['facts'][0]['attribution']='Invented speaker';secure6(!GNF5_Research::validate($qraw,$qs)['facts'],'invented quotation attribution rejected');
    GNF5_Sources::reset_budget();$calls=0;$mock=function($pre,$args,$url)use(&$calls){if(strpos($url,'security.example.org')===false)return $pre;$calls++;return new WP_Error('http_request_failed','fixture connection timeout');};add_filter('pre_http_request',$mock,10,3);
    $url='https://security.example.org/'.wp_generate_password(10,false);$r=GNF5_Sources::test_external_link($url,true);$again=GNF5_Sources::test_external_link($url,true);
    secure6($calls===1 && is_wp_error($again),'external-link timeout does not trigger fallback or forced retry');remove_filter('pre_http_request',$mock,10);
    $before=GNF5_Utils::settings();GNF5_Utils::activate();secure6(GNF5_Utils::settings()===$before,'activation preserves existing settings and keys');
    update_option(GNF5_BULK_RECOVERY_OPTION,array($id=>array('post_id'=>$id,'state'=>'queued')),false);
    wp_schedule_single_event(time()+600,GNF5_CRON_HOOK,array(1));wp_schedule_single_event(time()+610,GNF5_CRON_CONTINUE_HOOK,array(1,2,3,4));
    GNF5_Utils::deactivate();secure6(get_post($id) && GNF5_Utils::settings()===$before && isset(get_option(GNF5_BULK_RECOVERY_OPTION)[$id]),'deactivation preserves content, settings and pending queue');
    secure6(!wp_next_scheduled(GNF5_CRON_HOOK,array(1)) && !wp_next_scheduled(GNF5_CRON_CONTINUE_HOOK,array(1,2,3,4)),'deactivation unschedules hooks with category and continuation arguments');
    delete_option(GNF5_OPTION);GNF5_Utils::activate();secure6(GNF5_Utils::settings()['image_enabled']===0 && GNF5_Utils::settings()['auto_publish_enabled']===0,'fresh activation defaults to images OFF and Draft only');
    echo "TOTAL: $checks security/edge checks passed\n";
}finally{
    foreach($created as $id)wp_delete_post($id,true);GNF5_Utils::release_lock(0);update_option(GNF5_OPTION,$backup);update_option(GNF5_LOG_OPTION,$logs,false);update_option('gnf5_migration_version',$migration,false);delete_option('gnf5_migration_lock');update_option('cron',$cron_backup);update_option(GNF5_BULK_RECOVERY_OPTION,$queue_backup,false);
}
