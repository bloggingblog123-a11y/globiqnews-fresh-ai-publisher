<?php
if (!defined('ABSPATH')) exit;

/** Persistent category jobs; WordPress cron is a watchdog, not the normal handoff. */
class GNF6_Queue {
    const WATCHDOG='gnf6_category_queue_watchdog';
    const WAKE='gnf6_category_queue_wake';
    const RETRY='gnf6_configured_source_retry';
    private static $dispatching=false;
    private static $stopping=false;
    private static $current=null;
    public static function key($cat) { return 'gnf6_category_job_'.absint($cat); }
    public static function job($cat) { return GNF5_Utils::fresh_option(self::key($cat),array()); }
    public static function jobs() {
        global $wpdb;$rows=$wpdb->get_col($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s",$wpdb->esc_like('gnf6_category_job_').'%'));
        $jobs=array();foreach($rows as $raw){$j=maybe_unserialize($raw);if(is_array($j)&&!empty($j['id']))$jobs[]=$j;}
        usort($jobs,function($a,$b){return ($a['added']<=>$b['added'])?:($a['order']<=>$b['order']);});return $jobs;
    }
    public static function active($job) { return in_array($job['state']??'',array('waiting','claiming','dispatched','processing'),true); }
    private static function change($old,$new) { return GNF5_Utils::compare_option(self::key($old['category']),$old,$new); }
    public static function enqueue($categories,$trigger='manual') {
        $added=0;$already=array();$invalid=array();
        // Persist every selected job before starting any worker.
        foreach(array_values(array_unique(array_map('absint',(array)$categories))) as $order=>$cat){
            if(!GNF5_Utils::category_valid($cat)){$invalid[]=$cat;continue;}
            $old=self::job($cat);
            if(self::active($old)){$already[]=get_cat_name($cat);continue;}
            $legacy=GNF5_Utils::fresh_option('gnf5_run_cat_'.$cat,array());
            $id=$trigger==='cron' && in_array($legacy['status']??'',array('running','waiting'),true)?$legacy['id']:wp_generate_uuid4();
            $job=array('id'=>$id,'category'=>$cat,'trigger'=>$trigger,'state'=>'waiting','added'=>microtime(true),'order'=>$order,'updated'=>time(),'created'=>0,'target'=>0,'pass'=>1,'token'=>'','message'=>'Waiting for a worker slot.');
            $ok=$old?self::change($old,$job):GNF5_Utils::atomic_add(self::key($cat),$job);
            if($ok){$added++;GNF5_Utils::log('Category queued: '.get_cat_name($cat).'.','debug',$cat);}else $already[]=get_cat_name($cat);
        }
        self::ensure_schedule();self::dispatch_available_category_workers();
        return array('added'=>$added,'already'=>$already,'invalid'=>$invalid,'jobs'=>self::status());
    }
    public static function ensure_schedule() {
        if(!wp_next_scheduled(self::WATCHDOG))wp_schedule_event(time()+60,'gnf6_minute',self::WATCHDOG);
    }
    private static function wake() { if(!wp_next_scheduled(self::WAKE))wp_schedule_single_event(time(),self::WAKE); }
    public static function deactivate() {
        self::$stopping=true;wp_unschedule_hook(self::WATCHDOG);wp_unschedule_hook(self::WAKE);wp_unschedule_hook(self::RETRY);
    }
    public static function dispatch_available_category_workers() {
        if(self::$dispatching || self::$stopping)return;
        self::$dispatching=true;$dispatch=array();
        try{
            foreach(self::jobs() as $job){
                if(($job['state']??'')!=='waiting')continue;
                if(count(GNF5_Utils::worker_states())>=GNF5_Utils::worker_limit())break;
                $claimed=$job;$claimed['state']='claiming';$claimed['updated']=time();
                if(!self::change($job,$claimed))continue;
                $cat=$job['category'];
                if(!GNF5_Utils::category_valid($cat) || ($job['trigger']==='cron' && empty(GNF5_Utils::category_settings($cat)['enabled']))){
                    $done=$claimed;$done['state']='skipped';$done['message']='Category removed or automatic schedule disabled.';self::change($claimed,$done);continue;
                }
                if(!GNF5_Utils::acquire_lock($cat,$job['id'])){self::change($claimed,$job);continue;}
                $token=GNF5_Utils::lock_token($cat);$ready=$claimed;$ready['state']='dispatched';$ready['token']=$token;$ready['message']='Worker claimed; starting.';
                if(!self::change($claimed,$ready)){GNF5_Utils::release_lock($cat);continue;}
                GNF5_Utils::detach_lock($cat);
                GNF5_Utils::log('Next queued category found: '.get_cat_name($cat).'. Job claimed.','debug',$cat);
                $dispatch[]=$ready;
            }
        }finally{self::$dispatching=false;}
        foreach($dispatch as $job){
            // Per-dispatch unguessable token is sent only to this WordPress site's worker.
            // No user cookies, provider keys or article text are sent.
            $response=wp_remote_post(admin_url('admin-ajax.php'),array('timeout'=>0.5,'blocking'=>false,'redirection'=>0,
                'body'=>array('action'=>'gnf6_category_worker','cat_id'=>$job['category'],'job_id'=>$job['id'],'worker_token'=>$job['token'])));
            if(is_wp_error($response))GNF5_Utils::log('Queue loopback dispatch unavailable; immediate cron fallback retained. '.GNF5_Utils::redact($response->get_error_message()),'warning',$job['category']);
        }
        foreach(self::jobs() as $job)if(self::active($job)){self::wake();break;}
    }
    public static function worker_endpoint() {
        $cat=absint($_POST['cat_id']??0);$id=sanitize_text_field(wp_unslash($_POST['job_id']??''));$token=sanitize_text_field(wp_unslash($_POST['worker_token']??''));
        if(!self::work($cat,$id,$token))wp_send_json_error(array('message'=>'Invalid or already consumed worker claim.'),403);
        wp_send_json_success(array('message'=>'Category worker finished.'));
    }
    public static function work($cat,$id,$token) {
        $job=self::job($cat);
        if(($job['state']??'')!=='dispatched' || ($job['id']??'')!==$id || !$token || !hash_equals((string)($job['token']??''),(string)$token))return false;
        if(!GNF5_Utils::token_owns_lock($cat,$token))return false;
        $running=$job;$running['state']='processing';$running['updated']=time();$running['message']='Processing.';
        if(!self::change($job,$running))return false;
        if(!GNF5_Utils::adopt_lock($cat,$token))return false;
        self::$current=$running;register_shutdown_function(array(__CLASS__,'shutdown_cleanup'));
        if(function_exists('ignore_user_abort'))ignore_user_abort(true);
        $state='failed';$message='Category processing interrupted.';$result=null;
        try{
            GNF5_Utils::option_cache_clear(GNF5_OPTION);$cs=GNF5_Utils::category_settings($cat);
            if($running['trigger']==='cron' && empty($cs['enabled'])){$state='cancelled';$message='Automatic category schedule disabled.';}
            else{
                GNF5_Utils::log(get_cat_name($cat).' processing started.','debug',$cat);
                $result=GNF5_Runner::run_category($cat,'queue',1,min(4,$running['pass']),$id,$token);
                $count=GNF5_Runner::run_count($id);$batch=GNF5_Utils::fresh_option('gnf5_run_cat_'.$cat,array());
                $target=($batch['id']??'')===$id?(int)$batch['target']:(int)$cs['post_limit'];
                if(is_wp_error($result)){$state=$result->get_error_code()==='locked'?'blocked':'failed';$message=$result->get_error_message();}
                else{$state=$count>=$target || !empty($result['done'])?'completed':(empty($result['created'])?'exhausted':'waiting');$message=$result['message']??$state;}
            }
        }catch(Throwable $e){$message='Processing failed: '.GNF5_Utils::redact($e->getMessage());}
        finally{self::finish($running,$state,$message);}
        return true;
    }
    private static function finish($running,$state,$message) {
        $cat=$running['category'];$current=self::job($cat);
        try{
            if(($current['id']??'')===$running['id'] && ($current['token']??'')===$running['token'] && $current['state']==='processing'){
                $new=$current;$new['state']=$state;$new['updated']=time();$new['created']=GNF5_Runner::run_count($running['id']);$new['message']=GNF5_Utils::redact($message);$new['pass']++;$new['token']='';$new['target']=(int)GNF5_Utils::category_settings($cat)['post_limit'];
                $batch=GNF5_Utils::fresh_option('gnf5_run_cat_'.$cat,array());
                if(($batch['id']??'')===$running['id']){
                    $new['target']=(int)$batch['target'];$updated=$batch;$updated['status']=$state==='completed'?'complete':$state;$updated['updated']=time();unset($updated['reservation']);GNF5_Utils::compare_option('gnf5_run_cat_'.$cat,$batch,$updated);
                }
                self::change($current,$new);
                GNF5_Utils::end_cron_chain($cat);
                GNF5_Utils::log(get_cat_name($cat).' '.$state.'. Created '.$new['created'].' Draft(s).','debug',$cat);
            }
        }finally{
            self::$current=null;
            // The status/counters/log are persisted before releasing category and slot.
            GNF5_Utils::release_lock($cat);
            self::dispatch_available_category_workers();
        }
    }
    public static function shutdown_cleanup() {
        if(self::$current)self::finish(self::$current,'failed','Worker ended unexpectedly; saved Drafts retained.');
    }
    public static function recover() {
        GNF5_Utils::worker_states();
        foreach(self::jobs() as $job){
            $state=$job['state'];$age=time()-(int)$job['updated'];
            if($state==='claiming' && $age>120 || in_array($state,array('dispatched','processing'),true) && !GNF5_Utils::token_owns_lock($job['category'],$job['token'])){
                $new=$job;$new['state']='waiting';$new['token']='';$new['updated']=time();$new['message']='Stale worker recovered; waiting to resume saved run.';
                if(self::change($job,$new)){GNF5_Utils::release_token($job['category'],$job['token']);GNF5_Utils::log($new['message'],'warning',$job['category']);}
            }
        }
        self::dispatch_available_category_workers();
    }
    public static function cron_fallback() {
        self::recover();
        // The fallback may execute a claim whose loopback never arrived. CAS prevents
        // the cron request and a delayed HTTP request from processing it twice.
        foreach(self::jobs() as $job)if($job['state']==='dispatched')self::work($job['category'],$job['id'],$job['token']);
    }
    public static function status() {
        $out=array();foreach(self::jobs() as $job){$job['name']=get_cat_name($job['category']);unset($job['token']);$out[]=$job;}return $out;
    }
    public static function configured($cat,$type,$url) {
        if(!GNF5_Utils::category_valid($cat))return false;
        GNF5_Utils::option_cache_clear(GNF5_OPTION);$cs=GNF5_Utils::category_settings($cat);
        return in_array($type,array('rss','urls'),true) && in_array(GNF5_Utils::normalize_url($url),GNF5_Utils::urls_from_lines($cs[$type]),true);
    }
    public static function schedule_source_retry($cat,$type,$url) {
        if(!self::configured($cat,$type,$url))return;
        $block=get_transient(GNF5_Utils::blocked_key($url));if(!$block)return;
        $args=array(absint($cat),$type,$url);if(!wp_next_scheduled(self::RETRY,$args))wp_schedule_single_event(max(time()+1,(int)$block['next_retry']),self::RETRY,$args);
    }
    public static function retry_source($cat,$type,$url) {
        if(!self::configured($cat,$type,$url)){GNF5_Utils::log('Source retry cancelled: source is no longer configured.','debug',$cat);return;}
        if(GNF5_Utils::is_blocked_cached($url)){self::schedule_source_retry($cat,$type,$url);return;}
        self::enqueue(array($cat),'source-retry');
    }
    public static function settings_changed($old,$new) {
        foreach(_get_cron_array() as $events)foreach(($events[self::RETRY]??array()) as $event){
            $args=$event['args'];if(count($args)===3 && !self::configured($args[0],$args[1],$args[2])){wp_clear_scheduled_hook(self::RETRY,$args);GNF5_Utils::log('Removed source retry cancelled.','debug',$args[0]);}
        }
    }
}
