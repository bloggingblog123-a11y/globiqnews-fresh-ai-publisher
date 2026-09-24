<?php
if (!defined('ABSPATH')) { exit; }

/** Bounded topic history augments existing URL claims; Runner remains the only queue. */
class GNF5_Topics {
    public static function publisher_key($url) {
        $host=strtolower(preg_replace('/^www\./i','',(string)wp_parse_url($url,PHP_URL_HOST)));
        $parts=explode('.',$host);$tail=implode('.',array_slice($parts,-2));
        // Conservative common compound suffix handling; this is a publisher estimate, not ownership verification.
        return in_array($tail,array('co.uk','com.au','co.in','co.jp','co.nz','com.br','com.cn','com.sg','co.za'),true)?implode('.',array_slice($parts,-3)):$tail;
    }
    public static function keywords($text) {
        $stop=array_flip(explode(' ','a an and the in on of to for is are was with from as at by it new news latest says said after before this that how what why has have will its into over their more about'));
        return array_values(array_unique(array_filter(GNF5_Quality::tokens($text),function($word)use($stop){return strlen($word)>2 && !isset($stop[$word]);})));
    }
    public static function overlap($a,$b) {
        return count(array_intersect($a,$b))/max(1,min(count($a),count($b)));
    }
    public static function cluster($items,$cat_id) {
        $cs=GNF5_Utils::category_settings($cat_id);$clusters=array();$seen=array();
        foreach(array_slice($items,0,200) as $item){
            $id=GNF5_Utils::source_identity_url($item['url']??'');if(!$id || isset($seen[$id]))continue;$seen[$id]=true;
            $tokens=self::keywords($item['title']??'');$found=false;$date=strtotime($item['published_at']??'') ?: 0;
            foreach($clusters as &$cluster){
                $cdate=strtotime($cluster['published_at']??'') ?: 0;
                if(count(array_intersect($tokens,$cluster['_tokens']))>=3 && self::overlap($tokens,$cluster['_tokens'])>=0.55 && (!$date || !$cdate || abs($date-$cdate)<=2*DAY_IN_SECONDS)){
                    $cluster['supporting_items'][]=$item;$found=true;break;
                }
            }unset($cluster);
            if(!$found){$item['_tokens']=$tokens;$item['supporting_items']=array();$clusters[]=$item;}
        }
        return array_slice($clusters,0,(int)$cs['max_candidates']);
    }
    public static function fingerprint($research) {
        $parts=array();
        foreach($research['facts'] as $fact){
            // Source wording/order/headline does not contribute to identity.
            $tokens=self::keywords($fact['subject'].' '.$fact['kind'].' '.$fact['detail'].' '.$fact['value'].' '.$fact['date']);sort($tokens);
            $parts[]=implode(' ',$tokens);
        }
        sort($parts);return hash('sha256',implode('|',$parts));
    }
    public static function history() {
        $rows=GNF5_Utils::fresh_option('gnf5_topic_history',array());if(!is_array($rows))return array();
        $since=time()-(int)GNF5_Utils::settings()['history_days']*DAY_IN_SECONDS;
        return array_slice(array_values(array_filter($rows,function($r)use($since){return is_array($r) && ($r['time']??0)>$since;})),-1000);
    }
    public static function duplicate($research,$ignore_post=0) {
        $fingerprint=self::fingerprint($research);$tokens=self::fact_tokens($research);$entities=array_unique(array_map('strtolower',array_column($research['facts'],'subject')));$urls=array_map(array('GNF5_Utils','source_identity_url'),array_column($research['sources'],'url'));
        foreach(array_reverse(self::history()) as $row){
            if($ignore_post && (int)($row['post_id']??0)===$ignore_post)continue;
            if(($row['status']??'')==='failed')continue;
            if(($row['status']??'')==='processing' && ($row['time']??0)<time()-1800)continue;
            if($fingerprint===($row['fingerprint']??'') || (count(array_intersect($entities,$row['entities']??array()))>0 && self::overlap($tokens,$row['tokens']??array())>=0.9))return $row;
            // Same event with material new facts is allowed even when sources reuse URLs.
        }
        return false;
    }
    private static function fact_tokens($research) {
        $parts=array();foreach($research['facts'] as $f)$parts[]=$f['subject'].' '.$f['detail'].' '.$f['value'].' '.$f['date'];
        return self::keywords(implode(' ',$parts));
    }
    public static function claim($research,$cat_id) {
        $key='gnf6_topic_claim';$old=GNF5_Utils::fresh_option($key,array());
        if($old && ($old['time']??0)<time()-60)GNF5_Utils::delete_lock_value($key,$old);
        $lock=array('time'=>time(),'token'=>wp_generate_uuid4());
        if(!GNF5_Utils::atomic_add($key,$lock))return new WP_Error('topic_busy','Another worker is checking topic identity; candidate deferred.');
        try{
            if(self::duplicate($research))return new WP_Error('topic_duplicate','This event is already covered by a queued or saved article.');
            return self::remember($research,$cat_id,'processing');
        }finally{GNF5_Utils::delete_lock_value($key,$lock);}
    }
    public static function remember($research,$cat_id,$status,$post_id=0,$message='') {
        $fp=self::fingerprint($research);
        $entry=array('fingerprint'=>$fp,'tokens'=>self::fact_tokens($research),'entities'=>array_values(array_unique(array_map('strtolower',array_column($research['facts'],'subject')))),'time'=>time(),'category'=>(int)$cat_id,'status'=>$status,'post_id'=>(int)$post_id,
            'sources'=>array_column($research['sources'],'url'),'message'=>GNF5_Utils::redact($message));
        for($attempt=0;$attempt<20;$attempt++){
            $old=GNF5_Utils::fresh_option('gnf5_topic_history',false);$rows=is_array($old)?$old:array();$since=time()-(int)GNF5_Utils::settings()['history_days']*DAY_IN_SECONDS;
            $rows=array_values(array_filter($rows,function($r)use($fp,$since){return ($r['fingerprint']??'')!==$fp && ($r['time']??0)>$since;}));$rows[]=$entry;$rows=array_slice($rows,-1000);
            if($old===false?GNF5_Utils::atomic_add('gnf5_topic_history',$rows):GNF5_Utils::compare_option('gnf5_topic_history',$old,$rows))return true;
        }
        return new WP_Error('topic_busy','Topic history is busy; retry candidate later.');
    }
    public static function opportunity($item,$research,$cat_id) {
        $cs=GNF5_Utils::category_settings($cat_id);$dates=array();
        foreach($research['sources'] as $source){$date=strtotime($source['published_at']??'');if($date && $date<=time()+HOUR_IN_SECONDS)$dates[]=$date;}
        $age=$dates?max(0,time()-max($dates)):null;
        $query=self::keywords($cs['gdelt_keywords'].' '.get_cat_name($cat_id));$topic=self::fact_tokens($research);
        $points=array('freshness'=>$age===null?0:($age<=DAY_IN_SECONDS?20:($age<=3*DAY_IN_SECONDS?10:0)),
            'independent_sources'=>min(20,(int)$research['independent_source_estimate']*10),
            'primary_source'=>empty($research['primary_sources'])?0:20,
            'category_relevance'=>count(array_intersect($query,$topic))>0?20:0,
            'enough_facts'=>count($research['facts'])>=5?10:5,'not_already_covered'=>self::duplicate($research)?0:10);
        return array('score'=>array_sum($points),'target'=>(int)$cs['opportunity_threshold'],'points'=>$points,'date_known'=>$age!==null,
            'label'=>'Internal opportunity heuristic; source independence and primary identification require human confirmation.');
    }
}
