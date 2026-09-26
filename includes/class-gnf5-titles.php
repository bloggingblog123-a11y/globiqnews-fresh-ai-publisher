<?php
if (!defined('ABSPATH')) { exit; }

/** Local/source headline comparisons, not proof of web-wide uniqueness. */
class GNF5_Titles {
    private static $claims=array();
    public static function normalize($text) {
        $text=html_entity_decode(wp_strip_all_tags((string)$text),ENT_QUOTES|ENT_HTML5,'UTF-8');
        $text=function_exists('mb_strtolower')?mb_strtolower($text,'UTF-8'):strtolower($text);
        return trim(preg_replace('/\s+/u',' ',preg_replace('/[^\p{L}\p{N}\s]/u',' ',$text)));
    }
    public static function compare($a,$b) {
        $a=self::normalize($a);$b=self::normalize($b);
        if(!$a || !$b)return 'NONE';
        if($a===$b)return 'EXACT';
        $tokens=function($s){return array_values(array_unique(array_map(function($w){return preg_replace('/^launch(?:es|ed|ing)$/','launch',$w);},explode(' ',$s))));};
        $aa=$tokens($a);$bb=$tokens($b);$intersection=count(array_intersect($aa,$bb));
        $jaccard=$intersection/max(1,count(array_unique(array_merge($aa,$bb))));
        $threshold=max(0.75,min(0.98,(float)apply_filters('gnf5_title_similarity_threshold',0.8)));
        // Complete framing/word overlap, not a match on names alone.
        return count($aa)>=5 && count($bb)>=5 && $jaccard>=$threshold?'NEAR':'NONE';
    }
    public static function source_titles($research) {
        $out=array();
        foreach(array_merge((array)($research['source_titles']??array()),array_column((array)($research['sources']??array()),'title')) as $title){
            $title=sanitize_text_field($title);$key=self::normalize($title);if($key!=='')$out[$key]=$title;
        }
        return array_values($out);
    }
    public static function check($article,$research,$ignore_post=0) {
        $titles=array('headline'=>$article['title']??'','seo_title'=>$article['seo_title']??$article['title']??'');
        $sources=self::source_titles($research);$matches=array();$exact=false;$near=false;$site=false;$compared=0;
        foreach($titles as $field=>$title)foreach($sources as $source){
            $match=self::compare($title,$source);if($match==='NONE')continue;
            $exact=$exact||$match==='EXACT';$near=$near||$match==='NEAR';
            $matches[]=array('field'=>$field,'origin'=>'source','match'=>$match,'title'=>$source);
        }
        global $wpdb;$cursor=0;$db_ok=true;
        do{
            $rows=$wpdb->get_results($wpdb->prepare("SELECT ID,post_title FROM {$wpdb->posts} WHERE post_type='post' AND ID>%d AND ID!=%d ORDER BY ID ASC LIMIT 500",$cursor,$ignore_post),ARRAY_A);
            if($wpdb->last_error){$db_ok=false;break;}
            foreach((array)$rows as $row){
                $cursor=(int)$row['ID'];$compared++;
                foreach(array_unique(array($row['post_title'],get_post_meta($cursor,'rank_math_title',true))) as $existing)foreach($titles as $field=>$title){
                    $match=self::compare($title,$existing);if($match==='NONE')continue;$site=true;
                    if(count($matches)<30)$matches[]=array('field'=>$field,'origin'=>'site','post_id'=>$cursor,'match'=>$match,'title'=>$existing);
                }
            }
        }while(count((array)$rows)===500);
        $prefix='gnf5_title_claim_';
        $claims=$wpdb->get_results($wpdb->prepare("SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name LIKE %s",$wpdb->esc_like($prefix).'%'),ARRAY_A);
        if($wpdb->last_error)$db_ok=false;
        foreach((array)$claims as $row){
            $value=maybe_unserialize($row['option_value']);
            if(!is_array($value) || ($value['time']??0)<time()-600){GNF5_Utils::delete_lock_value($row['option_name'],$value);continue;}
            if(isset(self::$claims[$row['option_name']]) && self::$claims[$row['option_name']]===$value)continue;
            foreach($titles as $field=>$title){$match=self::compare($title,$value['title']??'');if($match==='NONE')continue;$site=true;$matches[]=array('field'=>$field,'origin'=>'generation job','match'=>$match);}
        }
        foreach(GNF5_Topics::history() as $row){
            if($ignore_post && (int)($row['post_id']??0)===$ignore_post)continue;
            foreach((array)($row['titles']??array()) as $existing)foreach($titles as $field=>$title){$match=self::compare($title,$existing);if($match==='NONE')continue;$site=true;if(count($matches)<30)$matches[]=array('field'=>$field,'origin'=>'topic history','match'=>$match);}
        }
        $status=$exact||$near||$site?'FAIL':(($db_ok && $sources && !in_array('',array_map('trim',$titles),true))?'PASS':'UNKNOWN');
        return array('status'=>$status,'source_titles_compared'=>count($sources),'site_posts_compared'=>$compared,'exact_source_match'=>$exact,'near_source_match'=>$near,'existing_site_match'=>$site,
            'attempts'=>(int)($article['title_attempts']??0),'matches'=>array_slice($matches,0,30),'checked_at'=>time(),
            'method'=>'Normalized full titles and token overlap against collected headlines, site posts (including trash), topic history and live title claims. No external search; factual/title fit requires quality review.');
    }
    public static function claim($article) {
        foreach(array_unique(array($article['title'],$article['seo_title'])) as $title){
            $key='gnf5_title_claim_'.hash('sha256',self::normalize($title));
            if(isset(self::$claims[$key]))continue;
            $old=GNF5_Utils::fresh_option($key,false);if(is_array($old) && ($old['time']??0)<time()-600)GNF5_Utils::delete_lock_value($key,$old);
            $value=array('time'=>time(),'token'=>wp_generate_uuid4(),'title'=>$title);
            if(!GNF5_Utils::atomic_add($key,$value)){self::release();return false;}
            self::$claims[$key]=$value;
        }
        return true;
    }
    public static function release() {foreach(self::$claims as $key=>$value)GNF5_Utils::delete_lock_value($key,$value);self::$claims=array();}

    public static function recheck_post($post_id) {
        $research=GNF5_Research::load($post_id);if(is_wp_error($research))return $research;
        $hash=GNF5_Quality::review_hash($post_id);$report=self::check(GNF5_Quality::current_article($post_id),$research,$post_id);
        if($hash!==GNF5_Quality::review_hash($post_id))return new WP_Error('changed','Title changed during checking; stale result discarded.');
        $report['review_hash']=$hash;update_post_meta($post_id,'_gnf5_title_report',$report);return $report;
    }
    public static function regenerate_post($post_id) {
        $research=GNF5_Research::load($post_id,true);if(is_wp_error($research))return $research;
        $article=GNF5_Quality::current_article($post_id);$hash=GNF5_Quality::review_hash($post_id);$owned=GNF5_Publish::can_rewrite($post_id);
        try{
            $titles=self::generate($research,$article['plan']??array(),$article,$post_id);if(is_wp_error($titles))return $titles;
            $candidate=array_merge($article,$titles);$review=GNF5_Quality::evaluate($candidate,$research,true,$post_id);
            if(($review['title']['status']??'')!=='PASS' || ($review['facts']['status']??'')!=='PASS')return new WP_Error('title_review','TITLE ORIGINALITY/ACCURACY — MANUAL REVIEW REQUIRED. Existing title and body preserved.');
            if(get_post_status($post_id)!=='draft' || $hash!==GNF5_Quality::review_hash($post_id))return new WP_Error('changed','Article changed during title generation; replacement cancelled.');
            $saved=GNF5_Publish::save(array('ID'=>$post_id,'post_title'=>$titles['title']));if(is_wp_error($saved) || !$saved)return new WP_Error('title_save','Could not save the new title.');
            $written=(array)get_post_meta($post_id,'_gnf5_rankmath_written',true);
            foreach(array('rank_math_title','rank_math_facebook_title','rank_math_twitter_title') as $key){update_post_meta($post_id,$key,$titles['seo_title']);$written[$key]=$titles['seo_title'];}
            update_post_meta($post_id,'_gnf5_rankmath_written',$written);
            $checkpoint=(array)get_post_meta($post_id,'_gnf5_article_data',true);
            foreach(array('title','seo_title','title_attempts') as $key)$checkpoint[$key]=$titles[$key];
            $checkpoint['quality']=$review;update_post_meta($post_id,'_gnf5_article_data',$checkpoint);
            GNF5_Publish::reset($post_id);if($owned)GNF5_Publish::checkpoint($post_id);
            GNF5_Quality::store($post_id,$review);
            GNF5_Utils::log('Title regenerated for Draft #'.$post_id.' after '.$titles['title_attempts'].' attempt(s); title checks and factual review PASS. Body unchanged.','quality',0);
            return true;
        }finally{self::release();}
    }

    public static function generate($research,$plan,$article=array(),$ignore_post=0,$limit=5) {
        $last=array();$reasons=array();
        for($attempt=1;$attempt<=max(1,min(5,$limit));$attempt++){
            $prompt="TASK: ORIGINAL_TITLE\nCreate an independent factual headline and SEO title from the fact sheet, article angle, outline and reader intent. Never rewrite or synonymize another headline. No forced sentiment, power words, numbers or unsupported benefits/comparisons. Both titles must describe the actual article. Return JSON {title:string,seo_title:string}. Rejection reasons contain no source wording. Choose a new useful framing when retrying.\nFACT_SHEET:\n".wp_json_encode(GNF5_Research::writer_facts($research))."\nPLAN:\n".wp_json_encode($plan)."\nFINAL_ARTICLE_IF_AVAILABLE:\n".wp_json_encode(array_intersect_key($article,array_flip(array('content_html','focus_keyword'))))."\nREJECTION_REASONS:\n".wp_json_encode($reasons);
            $raw=GNF5_Writer::gemini_json($prompt);if(is_wp_error($raw))return $raw;
            $last=array('title'=>sanitize_text_field($raw['title']??''),'seo_title'=>sanitize_text_field($raw['seo_title']??$raw['title']??''),'title_attempts'=>$attempt);
            if(!$last['title'] || !$last['seo_title']){$reasons=array('No usable independent headline returned.');continue;}
            $report=self::check($last,$research,$ignore_post);$last['title_check']=$report;
            if($report['status']==='PASS' && self::claim($last))return $last;
            $reasons=array('Headline or SEO title is too close to collected/site/job headlines, or comparisons were incomplete. Generate a new framing from the facts and plan.');
        }
        if(empty($last['title']))return new WP_Error('title_generation','TITLE ORIGINALITY — MANUAL REVIEW REQUIRED: no usable title after five attempts.');
        $last['title_check']['status']='FAIL';return $last;
    }
}
