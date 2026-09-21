<?php
if (!defined('ABSPATH')) { exit; }

/** Draft-only plugin writes. WordPress manual publishing is intentionally untouched. */
class GNF5_Publish {
    const MIN_SCORE = 80;
    private static $changed_scores = array();
    private static $writing = 0;

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
        update_post_meta($post_id, '_gnf5_publish_gate', 'draft-only');
        GNF5_Utils::clear_recovery_state($post_id);
        self::waiting_message($post_id);
        GNF5_RankMath::run($post_id);
    }

    private static function waiting_message($post_id) {
        GNF5_Utils::set_state($post_id, 'awaiting_rankmath', 'Draft saved for human review. Optional Rank Math analysis pending.');
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
            if (get_post_status($post_id) !== 'draft') continue;
            // Editor scores are legitimate saved values. Background verification does not publish.
            if (GNF5_RankMath::fresh($post_id)) continue;
            if (get_post_meta($post_id, '_gnf5_seo_attempt_source', true) !== self::fingerprint($post_id)) {
                GNF5_RankMath::reset_retry($post_id);
                update_post_meta($post_id, '_gnf5_seo_attempt_source', self::fingerprint($post_id));
            }
            GNF5_RankMath::schedule($post_id, 30);
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
        $result = array('reviewed'=>0,'published'=>0,'blocked'=>0,'reasons'=>array());
        $limit = max(1, min(5, absint($limit)));
        $offset = absint(get_option('gnf5_score_scan_offset', 0));
        $posts = self::waiting_posts($limit, true, $offset);
        if (!$posts && $offset) { $offset=0; $posts=self::waiting_posts($limit, true); }
        foreach ($posts as $post) {
            $result['reviewed']++;
            if ($manual_retry) GNF5_RankMath::reset_retry($post->ID);
            GNF5_RankMath::run($post->ID);
            if (self::score($post->ID) === null) {
                $result['blocked']++;
                $result['reasons'][] = '#'.$post->ID.': '.get_post_meta($post->ID,'_gnf5_seo_error',true);
            }
        }
        update_option('gnf5_score_scan_offset', count($posts)===$limit ? $offset+count($posts) : 0, false);
        return $result;
    }

    public static function blocked_reason($post_id) { return 'Draft only. Review the article and publish manually in WordPress.'; }

    private static function remember_block($post_id, $reason) {
        if (get_post_status($post_id) === 'draft' && get_post_meta($post_id, '_gnf5_generated_by', true) === 'fresh-v5') {
            update_post_meta($post_id, '_gnf5_publish_wait_reason', $reason);
        }
        return false;
    }

    public static function maybe_publish($post_id) {
        // Deprecated entry point retained for old cron/extensions. Never publishes.
        update_post_meta($post_id, '_gnf5_publish_gate', 'draft-only');
        return false;
    }

    public static function guard_status($data, $postarr) {
        if (self::$writing > 0 && ($data['post_type'] ?? 'post') === 'post') $data['post_status'] = 'draft';
        return $data;
    }

    public static function save($args, $insert = false) {
        self::$writing++;
        try {
            $args['post_status'] = 'draft';
            return $insert ? wp_insert_post(wp_slash($args), true) : wp_update_post(wp_slash($args), true);
        } finally { self::$writing--; }
    }

    public static function checkpoint($post_id) {
        update_post_meta($post_id, '_gnf5_managed_fingerprint', self::fingerprint($post_id));
    }

    public static function can_rewrite($post_id) {
        $saved = (string)get_post_meta($post_id, '_gnf5_managed_fingerprint', true);
        return get_post_status($post_id) === 'draft' && $saved !== '' && hash_equals($saved, self::fingerprint($post_id));
    }


}
