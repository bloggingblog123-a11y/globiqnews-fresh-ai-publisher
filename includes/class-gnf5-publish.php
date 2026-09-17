<?php
if (!defined('ABSPATH')) { exit; }

/** Publishes completed plugin drafts using Rank Math's actual saved SEO score. */
class GNF5_Publish {
    const MIN_SCORE = 80;
    private static $changed_scores = array();
    private static $publishing = array();

    public static function score($post_id) {
        $raw = get_post_meta($post_id, 'rank_math_seo_score', true);
        if (!is_scalar($raw) || !is_numeric($raw)) { return null; }
        $score = (float) $raw;
        return is_finite($score) && $score >= 0 && $score <= 100 ? $score : null;
    }

    public static function reset($post_id) {
        GNF5_RankMath::invalidate($post_id);
        wp_clear_scheduled_hook(GNF5_RankMath::HOOK,array((int)$post_id));
        delete_post_meta($post_id, '_gnf5_validated_fingerprint');
        delete_post_meta($post_id, '_gnf5_score_fingerprint');
        // Regeneration/repair changes the article: never reuse its previous score.
        delete_post_meta($post_id, 'rank_math_seo_score');
        unset(self::$changed_scores[$post_id]);
    }

    public static function fingerprint($post_id) {
        $data = array();
        foreach (array('post_title','post_name','post_content','post_excerpt') as $field) {
            $data[$field] = get_post_field($field, $post_id);
        }
        foreach (array('rank_math_title','rank_math_description','rank_math_focus_keyword',
            '_gnf5_article_data','_gnf5_image_ids','_thumbnail_id','_gnf5_source_url') as $key) {
            $data[$key] = get_post_meta($post_id, $key, true);
        }
        $image_ids = array_unique(array_merge((array)$data['_gnf5_image_ids'],array((int)$data['_thumbnail_id'])));
        foreach ($image_ids as $id) {
            $data['alt_' . $id] = get_post_meta($id, '_wp_attachment_image_alt', true);
        }
        $data['categories'] = wp_get_post_categories($post_id);
        $data['tags'] = wp_get_post_tags($post_id, array('fields'=>'ids'));
        sort($data['categories']);
        if (is_array($data['tags'])) { sort($data['tags']); }
        return hash('sha256', serialize($data));
    }

    public static function wait_for_score($post_id, $is_recovery) {
        update_post_meta($post_id, '_gnf5_publish_is_recovery', $is_recovery ? 1 : 0);
        delete_post_meta($post_id, '_gnf5_validated_fingerprint');
        update_post_meta($post_id, '_gnf5_publish_gate', 'waiting-rankmath-80');
        GNF5_Utils::clear_recovery_state($post_id);
        self::waiting_message($post_id);
        GNF5_RankMath::run($post_id);
    }

    private static function waiting_message($post_id) {
        $score = self::score($post_id);
        $message = 'Waiting for a saved Rank Math SEO score of at least 80; current score: '
            . ($score === null ? 'not available' : $score) . '. Background Rank Math analysis pending.';
        GNF5_Utils::set_state($post_id, 'awaiting_rankmath', $message);
    }

    public static function score_saved($meta_id, $post_id, $key, $value) {
        if (get_post_meta($post_id, '_gnf5_generated_by', true) !== 'fresh-v5') { return; }
        if ($key === 'rank_math_seo_score' && GNF5_RankMath::score_written_by_worker()) { return; }
        if(strpos($key,'rank_math_')!==0 && !in_array($key,array('_thumbnail_id','_gnf5_image_ids'),true))return;
        // Wait until the whole editor/REST save completes before checking the gate.
        self::$changed_scores[$post_id] = true;
    }

    public static function score_update_attempt($check, $post_id, $key, $value, $previous) {
        // WordPress skips updated_post_meta when the value is unchanged.
        // Observe the attempt, but never short-circuit or change the metadata write.
        self::score_saved(0, $post_id, $key, $value);
        return $check;
    }

    public static function post_saved($post_id) {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) { return; }
        if (get_post_type($post_id) === 'post'
            && get_post_meta($post_id, '_gnf5_generated_by', true) === 'fresh-v5') {
            self::$changed_scores[$post_id] = true;
        }
    }

    public static function flush_scores() {
        $ids = array_keys(self::$changed_scores);
        self::$changed_scores = array();
        foreach ($ids as $post_id) {
            if (get_post_status($post_id)!=='draft') continue;
            if (GNF5_RankMath::fresh($post_id)) { self::maybe_publish($post_id); }
            else {
                GNF5_RankMath::invalidate($post_id);
                // A content change starts a new bounded analysis cycle, never a rewrite.
                if (get_post_meta($post_id,'_gnf5_seo_attempt_source',true)!==self::fingerprint($post_id)) {
                    GNF5_RankMath::reset_retry($post_id);
                    update_post_meta($post_id,'_gnf5_seo_attempt_source',self::fingerprint($post_id));
                }
                update_post_meta($post_id,'_gnf5_seo_status','SEO SCORE PENDING');
                GNF5_RankMath::schedule($post_id,5);
            }
        }
    }

    public static function waiting_posts($limit = 20, $eligible_only = false, $offset = 0) {
        $meta = array(
            array('key'=>'_gnf5_generated_by','value'=>'fresh-v5'),
            array('relation'=>'OR',
                array('key'=>'_gnf5_auto_published','compare'=>'NOT EXISTS'),
                array('key'=>'_gnf5_auto_published','value'=>'1','compare'=>'!=')),
        );
        if ($eligible_only) {
            $meta[] = array('key'=>'_gnf5_state','value'=>array('awaiting_rankmath','complete','validation_failed'),'compare'=>'IN');
        }
        return get_posts(array('post_type'=>'post','post_status'=>'draft','numberposts'=>$limit,
            'orderby'=>'ID','order'=>'ASC','offset'=>absint($offset),'meta_query'=>$meta));
    }

    public static function cron_check() {
        return self::check_saved_scores();
    }

    public static function check_saved_scores($limit = 5, $manual_retry = false) {
        $result = array('reviewed'=>0, 'published'=>0, 'blocked'=>0, 'reasons'=>array());
        $limit = max(1, min(5, absint($limit)));
        $offset = absint(get_option('gnf5_score_scan_offset', 0));
        $posts = self::waiting_posts($limit, true, $offset);
        if (!$posts && $offset) {
            $offset = 0;
            $posts = self::waiting_posts($limit, true);
        }
        foreach ($posts as $post) {
            $result['reviewed']++;
            if($manual_retry)GNF5_RankMath::reset_retry($post->ID);
            if (GNF5_RankMath::run($post->ID)) { $result['published']++; }
            else {
                $result['blocked']++;
                if (count($result['reasons']) < 5) {
                    $result['reasons'][] = '#' . $post->ID . ': ' . get_post_meta($post->ID, '_gnf5_publish_wait_reason', true);
                }
            }
        }
        // Move past blocked rows so they cannot starve later qualifying drafts.
        $next = count($posts) === $limit ? $offset + count($posts) - $result['published'] : 0;
        update_option('gnf5_score_scan_offset', $next, false);
        return $result;
    }

    public static function blocked_reason($post_id) {
        if (get_post_type($post_id) !== 'post' || get_post_meta($post_id, '_gnf5_generated_by', true) !== 'fresh-v5') {
            return 'This is not a GlobiqNews-generated article.';
        }
        if (get_post_status($post_id) !== 'draft') { return 'Only Draft posts are eligible.'; }
        if (get_post_meta($post_id, '_gnf5_auto_published', true)) { return 'Previously published article was returned to Draft; automatic republishing is disabled.'; }
        $state = get_post_meta($post_id, '_gnf5_state', true);
        if (!in_array($state, array('awaiting_rankmath','complete','validation_failed'), true)) {
            return 'Article is not ready for publishing (state: ' . ($state ?: 'unknown') . '). Finish generation/recovery first; skipped posts stay skipped.';
        }
        if (GNF5_Utils::desired_success_status(false) !== 'publish') { return 'Auto Publish is OFF. Save the enabled setting first.'; }
        $recovered = $state === 'validation_failed' || (bool) get_post_meta($post_id, '_gnf5_publish_is_recovery', true);
        if (GNF5_Utils::desired_success_status($recovered) !== 'publish') { return 'Auto-publish recovered drafts is OFF. Enable it and save settings.'; }
        if (!GNF5_RankMath::available()) { return 'Rank Math is not active.'; }
        $score = self::score($post_id);
        if ($score === null) { return 'SEO SCORE PENDING: no valid numeric Rank Math result for this article.'; }
        if (!GNF5_RankMath::fresh($post_id)) { return 'SEO SCORE PENDING: saved score is unverified or stale; final content must be analyzed again.'; }
        if ($score < self::MIN_SCORE) { return 'Saved Rank Math score is ' . $score . '/100; at least 80 is required.'; }
        return '';
    }

    private static function remember_block($post_id, $reason) {
        if (get_post_status($post_id) === 'draft' && get_post_meta($post_id, '_gnf5_generated_by', true) === 'fresh-v5') {
            update_post_meta($post_id, '_gnf5_publish_wait_reason', $reason);
        }
        return false;
    }

    public static function maybe_publish($post_id) {
        $reason=self::blocked_reason($post_id);
        if($reason!=='')return self::remember_block($post_id,$reason);
        $cats=wp_get_post_categories($post_id);$cat_id=(int)($cats[0]??0);
        $owned=GNF5_Utils::owns_lock($cat_id);
        if(!$owned&&!GNF5_Utils::acquire_lock($cat_id))return self::remember_block($post_id,'Another article worker is active; publishing is deferred.');
        try{return self::publish_locked($post_id);}
        finally{if(!$owned)GNF5_Utils::release_lock($cat_id);}
    }

    public static function guard_status($data,$postarr) {
        $post_id=(int)($postarr['ID']??0);
        if(($data['post_status']??'')!=='publish' || empty(self::$publishing[$post_id]))return $data;
        foreach(array('post_title','post_name','post_content','post_excerpt') as $field){
            if(wp_unslash($data[$field]??'')!==get_post_field($field,$post_id)){
                $data['post_status']='draft';
                return $data;
            }
        }
        if(!GNF5_RankMath::fresh($post_id))$data['post_status']='draft';
        return $data;
    }

    private static function publish_locked($post_id) {
        if (isset(self::$publishing[$post_id])) { return false; }
        $reason = self::blocked_reason($post_id);
        if ($reason !== '') { return self::remember_block($post_id, $reason); }
        $score = self::score($post_id);
        $fingerprint = self::fingerprint($post_id);
        // An atomic option lock prevents duplicate publish transitions across requests.
        $lock = 'gnf5_score_publish_' . absint($post_id);
        if (!add_option($lock, time(), '', false)) {
            if ((int) get_option($lock) < time() - 900) {
                delete_option($lock);
                if (!add_option($lock, time(), '', false)) { return self::remember_block($post_id, 'Another publication attempt is running. Check again shortly.'); }
            } else { return self::remember_block($post_id, 'Another publication attempt is running. Check again shortly.'); }
        }
        self::$publishing[$post_id] = true;
        try {
            // Re-read settings, score and content after acquiring the publish lock.
            $score = self::score($post_id);
            $reason = self::blocked_reason($post_id);
            if ($reason !== '') { return self::remember_block($post_id, $reason); }
            if (self::fingerprint($post_id) !== $fingerprint) { return self::remember_block($post_id, 'Article changed during the publication attempt. Check again after saving.'); }
            $result = wp_update_post(array('ID'=>$post_id, 'post_status'=>'publish'), true);
            if (is_wp_error($result) || !$result || get_post_status($post_id) !== 'publish') {
                GNF5_Utils::log('Auto Publish deferred for #' . $post_id . ': WordPress could not publish the draft.', 'error');
                return self::remember_block($post_id, is_wp_error($result) ? $result->get_error_message() : 'WordPress did not change the post status to Published.');
            }
            update_post_meta($post_id, '_gnf5_auto_published', 1);
            update_post_meta($post_id, '_gnf5_auto_published_at', time());
            update_post_meta($post_id, '_gnf5_publish_gate', 'passed-rankmath-80');
            update_post_meta($post_id, '_gnf5_publish_score', $score);
            delete_post_meta($post_id, '_gnf5_publish_wait_reason');
            GNF5_Utils::set_state($post_id, 'complete', 'Published with Rank Math SEO score ' . $score . '.');
            delete_post_meta($post_id, '_gnf5_source_facts');
            GNF5_Utils::log('PUBLISHED #' . $post_id . ' â€” Rank Math SEO score ' . $score . '/100.', 'success');
            return true;
        } catch (Throwable $e) {
            GNF5_Utils::log('Auto Publish deferred for #' . $post_id . ': ' . $e->getMessage(), 'error');
            return self::remember_block($post_id, $e->getMessage());
        } finally {
            unset(self::$publishing[$post_id]);
            delete_option($lock);
        }
    }
}
