<?php
$_SERVER['HTTP_HOST']='globiqnews.localhost:8097'; $_SERVER['REQUEST_URI']='/';
$wp_root=getenv('GNF5_TEST_WP_ROOT'); if(!$wp_root)die('Set GNF5_TEST_WP_ROOT.'); require $wp_root.'/wp-load.php';
if(wp_get_environment_type()!=='local' || strpos(home_url(),'http://globiqnews.localhost:8097')!==0)die('Disposable local test site required.');
if(function_exists('proc_open'))die('Run this test with -d disable_functions=proc_open.');
$secret=trim(file_get_contents(getenv('GNF5_TEST_SCORER_SECRET_FILE')));
$original=GNF5_Utils::settings();
register_shutdown_function(function()use($original){update_option(GNF5_OPTION,$original);});
$settings=$original;$settings['seo_analyzer_mode']='remote';$settings['seo_service_url']='https://scorer.example.com';$settings['seo_service_key']=$secret;$settings['seo_service_consent']=1;$settings['gemini_api_key']='';update_option(GNF5_OPTION,$settings);
$remote_calls=0;$fault='';
add_filter('pre_http_request',function($pre,$args,$url)use(&$remote_calls,&$fault,$secret){
 if($url!=='https://scorer.example.com/v1/analyze')return $pre;
 $remote_calls++;
 if($args['redirection']!==0||$args['sslverify']!==true||empty($args['reject_unsafe_urls'])||$args['timeout']>20)throw new Exception('Unsafe remote transport arguments.');
 $wire=json_decode($args['body'],true);
 if(isset($wire['payload']['scripts'])||strpos($args['body'],'LOCAL-TEST-ONLY')!==false||strpos($args['body'],'TEST-ONLY-NOT-A-REAL-KEY')!==false)throw new Exception('Private server data in scoring payload.');
 if($fault==='unavailable')return new WP_Error('test_timeout','Synthetic cold start timeout');
 // Only synthetic local fixture data crosses this real loopback HTTP connection.
 $headers=array();$curl=curl_init('http://127.0.0.1:8098/v1/analyze');
 curl_setopt_array($curl,array(CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$args['body'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,
   CURLOPT_HTTPHEADER=>array('Content-Type: application/json','X-GNF5-Signature: '.$args['headers']['X-GNF5-Signature']),
   CURLOPT_HEADERFUNCTION=>function($ch,$line)use(&$headers){$parts=explode(':',$line,2);if(count($parts)===2)$headers[strtolower(trim($parts[0]))]=trim($parts[1]);return strlen($line);}));
 $raw=curl_exec($curl);$code=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
 if($raw===false)return new WP_Error('local_scorer_unavailable','Local test scorer not listening.');
 if($fault==='signature')$headers['x-gnf5-signature']=str_repeat('0',64);
 if(in_array($fault,array('fingerprint','request_id','out_of_range','numeric_string'),true)){
   $decoded=json_decode($raw,true);
   if($fault==='fingerprint')$decoded['result']['fingerprint']=str_repeat('0',64);
   if($fault==='request_id')$decoded['requestId']=str_repeat('0',32);
   if($fault==='out_of_range')$decoded['result']['score']=101;
   if($fault==='numeric_string')$decoded['result']['score']='80';
   $raw=wp_json_encode($decoded);$headers['x-gnf5-signature']=hash_hmac('sha256',$raw,$secret);
 }
 return array('headers'=>$headers,'body'=>$raw,'response'=>array('code'=>$code,'message'=>'Test scorer'),'cookies'=>array());
},20,3);
require __DIR__.'/test-rankmath530.php';
require __DIR__.'/test-repair530.php';
verify(!function_exists('proc_open') && $remote_calls>0,'Real remote analysis works with PHP proc_open disabled');
foreach(array('signature','fingerprint','request_id','out_of_range','numeric_string','unavailable') as $failure){
 $fault=$failure;$id=fixture_post(80);GNF5_RankMath::run($id);
 verify(get_post_status($id)==='draft' && GNF5_Publish::score($id)===null,'Remote '.$failure.' => N/A and Draft');
}
$fault='unavailable';$id=fixture_post(80);$content=get_post_field('post_content',$id);$start_calls=$remote_calls;
for($i=0;$i<5;$i++){delete_post_meta($id,'_gnf5_seo_next');GNF5_RankMath::run($id);}
verify($remote_calls-$start_calls===3 && get_post_status($id)==='draft' && get_post_field('post_content',$id)===$content,'Cold-start failures retry scoring only, at most 3 times');
$fault='';$id=fixture_post(80);$start_calls=$remote_calls;
$s=GNF5_Utils::settings();$s['seo_service_consent']=0;update_option(GNF5_OPTION,$s);GNF5_RankMath::run($id);
verify($remote_calls===$start_calls && get_post_status($id)==='draft','No remote transmission without saved article-sharing permission');
$s['seo_service_consent']=1;
foreach(array('http://scorer.example.com','https://127.0.0.1','https://user:pass@example.com','https://example.com?token=x','https://example.com/path') as $bad){
 $s['seo_service_url']=$bad;update_option(GNF5_OPTION,$s);$id=fixture_post(80);GNF5_RankMath::run($id);
 verify($remote_calls===$start_calls && get_post_status($id)==='draft','Unsafe or ambiguous service URL refused');
}
$s['seo_service_url']='https://scorer.example.com';update_option(GNF5_OPTION,$s);
$sanitized=GNF5_Utils::sanitize_settings(array('seo_service_key'=>''));
verify($sanitized['seo_service_key']===$secret && $sanitized['seo_service_url']===$s['seo_service_url'],'Partial settings save preserves service connection and blank secret');
echo 'REMOTE TOTAL '.$checks.' checks plus repair/finalizer tests passed.'.PHP_EOL;
