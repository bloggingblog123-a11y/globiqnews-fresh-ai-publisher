<?php
if (!defined('ABSPATH')) { exit; }

class GNF5_Utils {
    private static $lock_tokens = array();
    private static $source_lock_tokens = array();
    public static function defaults() {
        return array(
            // Existing V5.x keys are intentionally unchanged so an upgrade preserves current settings.
            'gemini_api_key' => '',
            'gemini_model' => 'gemini-3.5-flash-lite',
            'gemini_backup_model' => '',
            'post_status' => 'draft', // legacy compatibility
            'auto_publish_enabled' => 0,
            'auto_publish_recovered' => 1,
            'seo_analyzer_mode' => 'local',
            'seo_service_url' => '',
            'seo_service_key' => '',
            'seo_service_consent' => 0,
            'validated_success_status' => 'draft',
            'image_enabled' => 1,
            'image_provider' => 'openai',
            'openai_api_key' => '',
            'openai_model' => 'gpt-image-2.5-flare',
            'openai_quality' => 'low',
            'openai_size' => '1536x1024',
            'webui_endpoint' => 'http://127.0.0.1:7860',
            'webui_api_key' => '',
            'webui_model' => '',
            'builtin_fallback' => 1,
            'webp_quality' => 72,
            'blocked_retry_hours' => 6,
            'auto_recovery_enabled' => 1,
            'auto_recovery_max_attempts' => 3,
            'rankmath_enabled' => 1,
            'toc_enabled' => 1,
            'internal_links' => 1,
            // New V5.7 instruction fields are blank by default and do not change previous behavior.
            'global_article_instructions' => '',
            'global_seo_instructions' => '',
            'global_image_instructions' => '',
            'categories' => array(),
        );
    }

    public static function settings() {
        $saved = get_option(GNF5_OPTION, array());
        if (!is_array($saved)) { $saved = array(); }
        $had_auto_publish = array_key_exists('auto_publish_enabled', $saved);
        $settings = wp_parse_args($saved, self::defaults());

        // Upgrade migration: preserve the behavior of V5.11's legacy Final Status setting.
        if (!$had_auto_publish) {
            $legacy = in_array(($saved['post_status'] ?? 'draft'), array('draft','pending','publish'), true) ? ($saved['post_status'] ?? 'draft') : 'draft';
            $settings['auto_publish_enabled'] = ($legacy === 'publish') ? 1 : 0;
            $settings['validated_success_status'] = ($legacy === 'pending') ? 'pending' : 'draft';
            $settings['auto_publish_recovered'] = 1;
        }
        if (!isset($settings['categories']) || !is_array($settings['categories'])) {
            $settings['categories'] = array();
        }
        return $settings;
    }

    public static function category_settings($cat_id, $settings = null) {
        if ($settings === null) { $settings = self::settings(); }
        $defaults = array(
            'enabled' => 0,
            'post_limit' => 1,
            'interval' => 'hourly',
            'rss' => '',
            'urls' => '',
            'author_id' => self::fallback_author_id(),
            'external_links' => '',
            'instructions' => '',
        );
        $saved = isset($settings['categories'][$cat_id]) && is_array($settings['categories'][$cat_id])
            ? $settings['categories'][$cat_id] : array();
        return wp_parse_args($saved, $defaults);
    }

    public static function sanitize_settings($input) {
        $input = is_array($input) ? $input : array();
        $d = self::defaults();
        $out = array();
        $previous = self::settings();
        $out['seo_analyzer_mode'] = in_array(($input['seo_analyzer_mode'] ?? $previous['seo_analyzer_mode']), array('local','remote'), true) ? ($input['seo_analyzer_mode'] ?? $previous['seo_analyzer_mode']) : 'local';
        $out['seo_service_url'] = esc_url_raw(trim($input['seo_service_url'] ?? $previous['seo_service_url']), array('https'));
        $out['seo_service_key'] = !empty($input['seo_service_key']) ? sanitize_text_field(trim($input['seo_service_key'])) : $previous['seo_service_key'];
        if (!empty($input['seo_service_clear_key'])) $out['seo_service_key'] = '';
        $out['seo_service_consent'] = array_key_exists('seo_service_consent', $input) ? (empty($input['seo_service_consent']) ? 0 : 1) : $previous['seo_service_consent'];
        $out['gemini_api_key'] = sanitize_text_field($input['gemini_api_key'] ?? '');
        $out['gemini_model'] = sanitize_text_field($input['gemini_model'] ?? $d['gemini_model']);
        $out['gemini_backup_model'] = sanitize_text_field($input['gemini_backup_model'] ?? '');
        $out['auto_publish_enabled'] = empty($input['auto_publish_enabled']) ? 0 : 1;
        $out['auto_publish_recovered'] = empty($input['auto_publish_recovered']) ? 0 : 1;
        $out['validated_success_status'] = in_array(($input['validated_success_status'] ?? 'draft'), array('draft','pending'), true)
            ? $input['validated_success_status'] : 'draft';
        // Keep this legacy key synchronized for old V5 code/data, but V5.12 uses desired_success_status().
        $out['post_status'] = $out['auto_publish_enabled'] ? 'publish' : $out['validated_success_status'];
        $out['image_enabled'] = empty($input['image_enabled']) ? 0 : 1;
        $out['image_provider'] = in_array(($input['image_provider'] ?? 'openai'), array('openai','webui','builtin'), true)
            ? $input['image_provider'] : 'openai';
        $out['openai_api_key'] = sanitize_text_field($input['openai_api_key'] ?? '');
        $out['openai_model'] = sanitize_text_field($input['openai_model'] ?? $d['openai_model']);
        $out['openai_quality'] = in_array(($input['openai_quality'] ?? 'low'), array('low','medium','high','xhigh','max','auto'), true)
            ? $input['openai_quality'] : 'low';
        $out['openai_size'] = in_array(($input['openai_size'] ?? '1536x1024'), array('1024x1024','1024x1536','1536x1024'), true)
            ? $input['openai_size'] : '1536x1024';
        $out['webui_endpoint'] = esc_url_raw(rtrim($input['webui_endpoint'] ?? $d['webui_endpoint'], '/'));
        $out['webui_api_key'] = sanitize_text_field($input['webui_api_key'] ?? '');
        $out['webui_model'] = sanitize_text_field($input['webui_model'] ?? '');
        $out['builtin_fallback'] = empty($input['builtin_fallback']) ? 0 : 1;
        $out['webp_quality'] = min(90, max(50, absint($input['webp_quality'] ?? 72)));
        $out['blocked_retry_hours'] = min(72, max(1, absint($input['blocked_retry_hours'] ?? 6)));
        $out['auto_recovery_enabled'] = empty($input['auto_recovery_enabled']) ? 0 : 1;
        $out['auto_recovery_max_attempts'] = min(5, max(1, absint($input['auto_recovery_max_attempts'] ?? 3)));
        $out['rankmath_enabled'] = empty($input['rankmath_enabled']) ? 0 : 1;
        $out['toc_enabled'] = empty($input['toc_enabled']) ? 0 : 1;
        $out['internal_links'] = empty($input['internal_links']) ? 0 : 1;
        $out['global_article_instructions'] = sanitize_textarea_field($input['global_article_instructions'] ?? '');
        $out['global_seo_instructions'] = sanitize_textarea_field($input['global_seo_instructions'] ?? '');
        $out['global_image_instructions'] = sanitize_textarea_field($input['global_image_instructions'] ?? '');
        // Preserve existing per-category settings if a category row is missing from the POST.
        // This protects large sites from PHP max_input_vars truncation and partial form submissions.
        $existing = get_option(GNF5_OPTION,array());
        $existing_categories = (is_array($existing) && !empty($existing['categories']) && is_array($existing['categories']))
            ? $existing['categories'] : array();
        $out['categories'] = array();

        foreach (get_categories(array('hide_empty' => false)) as $cat) {
            $id = absint($cat->term_id);
            $old_row = (isset($existing_categories[$id]) && is_array($existing_categories[$id])) ? $existing_categories[$id] : array();
            if (isset($input['categories'][$id]) && is_array($input['categories'][$id]) && !empty($input['categories'][$id]['_row_complete'])) {
                // The completion sentinel is rendered at the END of every category row. If PHP
                // max_input_vars truncates the POST before that sentinel, do not trust a partial
                // hidden checkbox value such as enabled=0; preserve the previously saved row.
                $merged_row = array_merge($old_row, $input['categories'][$id]);
                unset($merged_row['_row_complete']);
                $out['categories'][$id] = self::sanitize_category_row($merged_row);
            } elseif ($old_row) {
                $out['categories'][$id] = self::sanitize_category_row($old_row);
            } else {
                $out['categories'][$id] = self::sanitize_category_row(array());
            }
        }
        return $out;
    }

    public static function sanitize_category_row($row) {
        if (!is_array($row)) { $row = array(); }
        $valid_intervals = array('gnf5_30m','hourly','gnf5_2h','gnf5_4h','gnf5_6h','gnf5_12h','daily');
        return array(
            'enabled' => empty($row['enabled']) ? 0 : 1,
            'post_limit' => min(10, max(1, absint($row['post_limit'] ?? 1))),
            'interval' => in_array(($row['interval'] ?? 'hourly'), $valid_intervals, true) ? $row['interval'] : 'hourly',
            'rss' => self::sanitize_url_lines($row['rss'] ?? ''),
            'urls' => self::sanitize_url_lines($row['urls'] ?? ''),
            'author_id' => self::valid_author_id($row['author_id'] ?? 0),
            'external_links' => self::sanitize_url_lines($row['external_links'] ?? ''),
            'instructions' => sanitize_textarea_field($row['instructions'] ?? ''),
        );
    }

    public static function fallback_author_id() {
        $current = get_current_user_id();
        $current_user = $current ? get_user_by('id', $current) : false;
        if ($current_user && user_can($current_user, 'edit_posts')) { return absint($current); }

        // Query by capability so a valid author is still found on sites where the first
        // dozens/hundreds of users are Subscribers.
        $users = get_users(array('capability'=>'edit_posts','number'=>1,'orderby'=>'ID','order'=>'ASC','fields'=>'all'));
        foreach ($users as $user) {
            if ($user && user_can($user, 'edit_posts')) { return absint($user->ID); }
        }

        // Backward-compatible role fallback for older WordPress installs/custom query behavior.
        $users = get_users(array('role__in'=>array('administrator','editor','author','contributor'),'number'=>20,'orderby'=>'ID','order'=>'ASC','fields'=>'all'));
        foreach ($users as $user) {
            if ($user && user_can($user, 'edit_posts')) { return absint($user->ID); }
        }

        // A WordPress site should normally have at least one author-capable administrator.
        // Return 0 rather than silently assigning a Subscriber if the user table is abnormal.
        return 0;
    }

    public static function valid_author_id($author_id) {
        $author_id = absint($author_id);
        $user = $author_id ? get_user_by('id', $author_id) : false;
        if ($user && user_can($user, 'edit_posts')) { return $author_id; }
        return self::fallback_author_id();
    }

    public static function sanitize_url_lines($text) {
        $clean = array();
        foreach (preg_split('/\R/', (string)$text) as $line) {
            $u = self::normalize_url(trim($line));
            if ($u) { $clean[] = $u; }
        }
        return implode("\n", array_values(array_unique($clean)));
    }

    public static function urls_from_lines($text) {
        $out = array();
        foreach (preg_split('/\R/', trim((string)$text)) as $line) {
            $u = self::normalize_url(trim($line));
            if ($u) { $out[] = $u; }
        }
        return array_values(array_unique($out));
    }

    public static function normalize_url($url) {
        $url = trim(html_entity_decode((string)$url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (!$url) { return ''; }
        $parts = wp_parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) { return ''; }
        if (!in_array(strtolower((string)$parts['scheme']), array('http','https'), true)) { return ''; }

        // Preserve functional query strings exactly (order, duplicate keys and encoding).
        // Rebuilding with parse_str/http_build_query can break signed feeds and publisher URLs.
        $kept_pairs = array();
        $raw_query = (string)($parts['query'] ?? '');
        if ($raw_query !== '') {
            foreach (explode('&', $raw_query) as $pair) {
                if ($pair === '') { continue; }
                $bits = explode('=', $pair, 2);
                $raw_key = $bits[0];
                $key = rawurldecode(str_replace('+', ' ', $raw_key));
                if (preg_match('/^(utm_|fbclid$|gclid$|dclid$|msclkid$|mc_cid$|mc_eid$|igshid$|vero_id$)/i', $key)) {
                    continue;
                }
                $kept_pairs[] = $pair;
            }
        }

        $host = strtolower((string)$parts['host']);
        $path = isset($parts['path']) ? preg_replace('~/+~', '/', (string)$parts['path']) : '/';
        $path = $path ?: '/';
        $port = isset($parts['port']) ? ':' . absint($parts['port']) : '';
        $normalized = strtolower((string)$parts['scheme']) . '://' . $host . $port . $path;
        if ($kept_pairs) { $normalized .= '?' . implode('&', $kept_pairs); }
        return esc_url_raw($normalized);
    }

    /**
     * Duplicate-detection identity. Fetch URLs keep functional query parameters,
     * while common referral/tracking presentation parameters are ignored only
     * for duplicate matching.
     */
    public static function source_identity_url($url) {
        $url = self::normalize_url($url);
        if (!$url) { return ''; }
        $parts = wp_parse_url($url);
        if (!$parts || empty($parts['host'])) { return ''; }

        // Be conservative: source/ref/campaign/from/output and similar parameters can be
        // functional on real publisher endpoints. Do not discard them for duplicate matching.
        // normalize_url() has already removed only well-known tracking parameters.
        $query = (string)($parts['query'] ?? '');
        $host = strtolower(preg_replace('/^www\./i','',(string)$parts['host']));
        $path = preg_replace('~/+~','/',(string)($parts['path'] ?? '/'));
        if ($path !== '/') { $path = rtrim($path,'/'); }
        if ($path === '') { $path = '/'; }
        $port = isset($parts['port']) ? ':'.absint($parts['port']) : '';

        // Identity ignores http/https presentation differences, but preserves the functional query.
        $identity = 'https://'.$host.$port.$path;
        if ($query !== '') { $identity .= '?'.$query; }
        return esc_url_raw($identity);
    }

    public static function same_resource_url($a, $b) {
        $a = self::normalize_url($a);
        $b = self::normalize_url($b);
        if (!$a || !$b) { return false; }
        $pa = wp_parse_url($a); $pb = wp_parse_url($b);
        if (!$pa || !$pb) { return false; }
        $ha = strtolower(preg_replace('/^www\./i','',(string)($pa['host'] ?? '')));
        $hb = strtolower(preg_replace('/^www\./i','',(string)($pb['host'] ?? '')));
        if ($ha !== $hb) { return false; }
        $path_a = rtrim(rawurldecode((string)($pa['path'] ?? '/')), '/');
        $path_b = rtrim(rawurldecode((string)($pb['path'] ?? '/')), '/');
        if ($path_a === '') { $path_a='/'; }
        if ($path_b === '') { $path_b='/'; }
        if ($path_a !== $path_b) { return false; }
        $qa=array();$qb=array();
        parse_str((string)($pa['query'] ?? ''),$qa); parse_str((string)($pb['query'] ?? ''),$qb);
        ksort($qa);ksort($qb);
        return $qa === $qb;
    }

    public static function word_count($html) {
        $text = html_entity_decode(wp_strip_all_tags((string)$html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match_all('/\b[\p{L}\p{N}][\p{L}\p{N}\'’\-]*\b/u', $text, $m)) {
            return count($m[0]);
        }
        return str_word_count($text);
    }

    public static function log($message, $type = 'info', $cat_id = 0) {
        $log = get_option(GNF5_LOG_OPTION, array());
        if (!is_array($log)) { $log = array(); }
        array_unshift($log, array(
            'time' => current_time('mysql'),
            'type' => sanitize_key($type),
            'cat' => absint($cat_id),
            'message' => wp_strip_all_tags((string)$message),
        ));
        update_option(GNF5_LOG_OPTION, array_slice($log, 0, 250), false);
    }

    public static function desired_success_status($is_recovery = false) {
        $s = self::settings();
        if (!empty($s['auto_publish_enabled'])) {
            if (!$is_recovery || !empty($s['auto_publish_recovered'])) { return 'publish'; }
        }
        return in_array(($s['validated_success_status'] ?? 'draft'), array('draft','pending'), true)
            ? $s['validated_success_status'] : 'draft';
    }

    public static function adaptive_scan_limit($post_limit) {
        $post_limit = min(10, max(1, absint($post_limit)));
        return min(60, max(10, ($post_limit * 8) + 6));
    }

    public static function cron_schedules($schedules) {
        $schedules['gnf5_15m'] = array('interval' => 900, 'display' => 'Every 15 minutes');
        $schedules['gnf5_30m'] = array('interval' => 1800, 'display' => 'Every 30 minutes');
        $schedules['gnf5_2h'] = array('interval' => 7200, 'display' => 'Every 2 hours');
        $schedules['gnf5_4h'] = array('interval' => 14400, 'display' => 'Every 4 hours');
        $schedules['gnf5_6h'] = array('interval' => 21600, 'display' => 'Every 6 hours');
        $schedules['gnf5_12h'] = array('interval' => 43200, 'display' => 'Every 12 hours');
        return $schedules;
    }

    public static function activate() {
        if (get_option(GNF5_OPTION, null) === null) {
            add_option(GNF5_OPTION, self::defaults(), '', false);
        }
        self::reschedule_all();
        self::ensure_recovery_schedule();
    }

    public static function deactivate() {
        if(class_exists('GNF5_RankMath'))wp_unschedule_hook(GNF5_RankMath::HOOK);
        wp_clear_scheduled_hook(GNF5_CRON_HOOK);
        if (defined('GNF5_RECOVERY_CRON_HOOK')) { wp_clear_scheduled_hook(GNF5_RECOVERY_CRON_HOOK); }
        if (defined('GNF5_CRON_CONTINUE_HOOK')) { wp_clear_scheduled_hook(GNF5_CRON_CONTINUE_HOOK); }
        if (defined('GNF5_BULK_RECOVERY_HOOK')) { wp_clear_scheduled_hook(GNF5_BULK_RECOVERY_HOOK); }
        if (defined('GNF5_BULK_RECOVERY_LOCK')) { delete_option(GNF5_BULK_RECOVERY_LOCK); }
        foreach(get_categories(array('hide_empty'=>false)) as $cat){ self::end_cron_chain($cat->term_id); self::force_clear_lock($cat->term_id); }
        // Preserve the bulk queue option so an upgrade/deactivate/reactivate cannot
        // silently lose the administrator's selected recovery work.
    }

    public static function reschedule_all() {
        wp_clear_scheduled_hook(GNF5_CRON_HOOK);
        if (defined('GNF5_CRON_CONTINUE_HOOK')) { wp_clear_scheduled_hook(GNF5_CRON_CONTINUE_HOOK); }
        foreach(get_categories(array('hide_empty'=>false)) as $cat){ self::end_cron_chain($cat->term_id); }
        $settings = self::settings();
        foreach (get_categories(array('hide_empty' => false)) as $cat) {
            $cs = self::category_settings($cat->term_id, $settings);
            if (!empty($cs['enabled'])) {
                wp_schedule_event(time() + 90 + (absint($cat->term_id) % 30), $cs['interval'], GNF5_CRON_HOOK, array(absint($cat->term_id)));
            }
        }
    }


    public static function ensure_recovery_schedule() {
        if (!defined('GNF5_RECOVERY_CRON_HOOK')) { return; }
        $s = self::settings();
        if (empty($s['auto_recovery_enabled']) && empty($s['auto_publish_enabled'])) {
            wp_clear_scheduled_hook(GNF5_RECOVERY_CRON_HOOK);
            return;
        }
        if (!wp_next_scheduled(GNF5_RECOVERY_CRON_HOOK)) {
            wp_schedule_event(time() + 120, 'gnf5_15m', GNF5_RECOVERY_CRON_HOOK);
        }
    }

    public static function recovery_attempts($post_id) {
        return max(0, absint(get_post_meta(absint($post_id),'_gnf5_recovery_attempts',true)));
    }

    public static function recovery_max_attempts() {
        $s = self::settings();
        return min(5,max(1,absint($s['auto_recovery_max_attempts'] ?? 3)));
    }

    public static function recovery_delay_for_attempt($attempt) {
        $attempt = max(1,absint($attempt));
        if ($attempt <= 1) { return 15 * MINUTE_IN_SECONDS; }
        if ($attempt === 2) { return HOUR_IN_SECONDS; }
        return 6 * HOUR_IN_SECONDS;
    }

    public static function queue_auto_recovery($post_id, $delay = null) {
        $post_id = absint($post_id);
        if (!$post_id || get_post_type($post_id)!=='post') { return; }
        $s = self::settings();
        if (empty($s['auto_recovery_enabled'])) { return; }
        if ((string)get_post_meta($post_id,'_gnf5_state',true)==='skipped') { return; }

        $attempts = self::recovery_attempts($post_id);
        $max = self::recovery_max_attempts();
        if ($attempts >= $max) {
            update_post_meta($post_id,'_gnf5_recovery_exhausted',1);
            delete_post_meta($post_id,'_gnf5_recovery_next');
            return;
        }

        if ($delay === null) { $delay = self::recovery_delay_for_attempt($attempts + 1); }
        update_post_meta($post_id,'_gnf5_recovery_next',time()+max(60,absint($delay)));
        delete_post_meta($post_id,'_gnf5_recovery_exhausted');
        self::ensure_recovery_schedule();
    }

    public static function clear_recovery_state($post_id) {
        foreach(array('_gnf5_recovery_attempts','_gnf5_recovery_next','_gnf5_recovery_exhausted','_gnf5_recovery_last_error') as $key) {
            delete_post_meta(absint($post_id),$key);
        }
    }

    public static function auto_recoverable_posts($limit = 2) {
        $s = self::settings();
        if (empty($s['auto_recovery_enabled'])) { return array(); }
        $now = time();
        $max = self::recovery_max_attempts();
        $q = new WP_Query(array(
            'post_type'=>'post',
            'post_status'=>array('draft','pending'),
            'posts_per_page'=>max(1,min(5,absint($limit))),
            'orderby'=>'modified',
            'order'=>'ASC',
            'no_found_rows'=>true,
            'meta_query'=>array(
                'relation'=>'AND',
                array('key'=>'_gnf5_generated_by','value'=>'fresh-v5','compare'=>'='),
                array('key'=>'_gnf5_state','value'=>array('draft_created','processing','image_pending','validation_failed','post_processing_failed'),'compare'=>'IN'),
                array(
                    'relation'=>'OR',
                    array('key'=>'_gnf5_recovery_next','compare'=>'NOT EXISTS'),
                    array('key'=>'_gnf5_recovery_next','value'=>$now,'type'=>'NUMERIC','compare'=>'<='),
                ),
                array(
                    'relation'=>'OR',
                    array('key'=>'_gnf5_recovery_exhausted','compare'=>'NOT EXISTS'),
                    array('key'=>'_gnf5_recovery_exhausted','value'=>'1','compare'=>'!='),
                ),
            ),
        ));
        $posts=array();
        foreach($q->posts as $p) {
            if (self::recovery_attempts($p->ID) < $max) { $posts[]=$p; }
        }
        return $posts;
    }

    public static function cron_chain_key($cat_id) { return 'gnf5_cron_chain_cat_' . absint($cat_id); }

    public static function cron_chain_active($cat_id) {
        $state=get_transient(self::cron_chain_key($cat_id));
        return is_array($state) && !empty($state['started']);
    }

    public static function start_cron_chain($cat_id,$target=1) {
        if(self::cron_chain_active($cat_id))return false;
        set_transient(self::cron_chain_key($cat_id),array('started'=>time(),'target'=>max(1,absint($target)),'touched'=>time()),45*MINUTE_IN_SECONDS);
        return true;
    }

    public static function touch_cron_chain($cat_id,$target=1) {
        set_transient(self::cron_chain_key($cat_id),array('started'=>time(),'target'=>max(1,absint($target)),'touched'=>time()),45*MINUTE_IN_SECONDS);
    }

    public static function end_cron_chain($cat_id) { delete_transient(self::cron_chain_key($cat_id)); }

    public static function lock_key($cat_id) { return 'gnf5_lock_cat_' . absint($cat_id); }

    private static function lock_fresh($state,$ttl=900) {
        return is_array($state) && !empty($state['time']) && (time()-absint($state['time'])) < max(60,absint($ttl));
    }

    public static function acquire_lock($cat_id) {
        $cat_id=absint($cat_id);
        $key=self::lock_key($cat_id);

        // Honor an active transient lock left by an older V5 build during an in-place upgrade.
        $legacy=get_transient($key);
        if(self::lock_fresh($legacy,900))return false;
        if($legacy)delete_transient($key);

        $token=wp_generate_uuid4();
        $state=array('time'=>time(),'token'=>$token,'category'=>$cat_id);
        // One worker across categories, manual imports, recovery and SEO retries.
        $global='gnf5_article_worker';
        if(!add_option($global,$state,'','no')){
            $old=get_option($global,array());
            if(self::lock_fresh($old,1800))return false;
            self::delete_lock_value($global,$old);
            if(!add_option($global,$state,'','no'))return false;
        }
        if(!add_option($key,$state,'','no')){
            $existing=get_option($key,array());
            if(self::lock_fresh($existing,900)){self::delete_lock_value($global,$state);return false;}
            self::delete_lock_value($key,$existing);
            if(!add_option($key,$state,'','no')){self::delete_lock_value($global,$state);return false;}
        }
        self::$lock_tokens[$cat_id]=$token;
        return true;
    }

    private static function delete_lock_value($key,$value) {
        global $wpdb;
        $deleted=$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s",$key,maybe_serialize($value)));
        if($deleted){wp_cache_delete($key,'options');wp_cache_delete('alloptions','options');}
        return (bool)$deleted;
    }

    public static function owns_lock($cat_id) {
        $token=self::$lock_tokens[absint($cat_id)]??'';
        $global=get_option('gnf5_article_worker',array());
        return $token!=='' && is_array($global) && hash_equals((string)($global['token']??''),$token);
    }

    public static function touch_lock($cat_id) {
        $cat_id=absint($cat_id);
        $key=self::lock_key($cat_id);
        $token=self::$lock_tokens[$cat_id]??'';
        if($token==='')return false;
        $existing=get_option($key,array());
        if(!is_array($existing) || !hash_equals((string)($existing['token']??''),(string)$token))return false;
        $existing['time']=time();
        update_option($key,$existing,false);
        $global=get_option('gnf5_article_worker',array());
        if(is_array($global)&&hash_equals((string)($global['token']??''),(string)$token)){
            $global['time']=time();update_option('gnf5_article_worker',$global,false);
        }
        return true;
    }

    public static function release_lock($cat_id) {
        $cat_id=absint($cat_id);
        $key=self::lock_key($cat_id);
        $token=self::$lock_tokens[$cat_id]??'';
        $existing=get_option($key,array());
        // Never delete a lock acquired later by another request after this request became stale.
        if($token!=='' && is_array($existing) && hash_equals((string)($existing['token']??''),(string)$token)){
            self::delete_lock_value($key,$existing);
        }
        $global=get_option('gnf5_article_worker',array());
        if($token!==''&&is_array($global)&&hash_equals((string)($global['token']??''),(string)$token)){self::delete_lock_value('gnf5_article_worker',$global);}
        unset(self::$lock_tokens[$cat_id]);
        delete_transient($key); // legacy cleanup only
    }

    public static function force_clear_lock($cat_id) {
        $cat_id=absint($cat_id);
        $global=get_option('gnf5_article_worker',array());
        if(is_array($global)&&(int)($global['category']??-1)===$cat_id&&!self::lock_fresh($global,1800)){self::delete_lock_value('gnf5_article_worker',$global);}
        delete_option(self::lock_key($cat_id));
        delete_transient(self::lock_key($cat_id));
        unset(self::$lock_tokens[$cat_id]);
    }

    public static function is_locked($cat_id) {
        $cat_id=absint($cat_id);
        $key=self::lock_key($cat_id);
        $state=get_option($key,array());
        if(self::lock_fresh($state,900))return true;
        if($state)delete_option($key);
        $legacy=get_transient($key);
        if(self::lock_fresh($legacy,900))return true;
        if($legacy)delete_transient($key);
        return false;
    }

    public static function source_lock_key($url) {
        $identity=self::source_identity_url($url)?:self::normalize_url($url);
        return 'gnf5_source_lock_' . md5((string)$identity);
    }

    public static function acquire_source_lock($url) {
        $identity=self::source_identity_url($url)?:self::normalize_url($url);
        if(!$identity)return false;
        $key=self::source_lock_key($identity);
        $token=wp_generate_uuid4();
        $state=array('time'=>time(),'token'=>$token);
        if(!add_option($key,$state,'','no')){
            $existing=get_option($key,array());
            if(self::lock_fresh($existing,2700))return false;
            delete_option($key);
            if(!add_option($key,$state,'','no'))return false;
        }
        self::$source_lock_tokens[$key]=$token;
        return true;
    }

    public static function release_source_lock($url) {
        $key=self::source_lock_key($url);
        $token=self::$source_lock_tokens[$key]??'';
        $existing=get_option($key,array());
        if($token!=='' && is_array($existing) && hash_equals((string)($existing['token']??''),(string)$token)){
            delete_option($key);
        }
        unset(self::$source_lock_tokens[$key]);
    }

    public static function blocked_key($url) { $id=self::normalize_url($url); return 'gnf5_block_' . md5($id?:trim((string)$url)); }
    public static function is_blocked_cached($url) { return (bool)get_transient(self::blocked_key($url)); }

    public static function mark_blocked($url, $reason = 'blocked') {
        $s = self::settings();
        $ttl = max(1, absint($s['blocked_retry_hours'])) * HOUR_IN_SECONDS;
        set_transient(self::blocked_key($url), sanitize_text_field($reason), $ttl);
    }

    public static function duplicate_post_id($url) {
        $url = self::normalize_url($url);
        if (!$url) { return 0; }
        $identity = self::source_identity_url($url);

        if ($identity) {
            $q = new WP_Query(array(
                'post_type'=>'post','post_status'=>'any','posts_per_page'=>1,'fields'=>'ids','no_found_rows'=>true,
                'meta_key'=>'_gnf5_source_identity','meta_value'=>$identity,
            ));
            if (!empty($q->posts[0])) { return absint($q->posts[0]); }
        }

        // Backward compatibility for V5 posts created before source identity metadata existed.
        $q = new WP_Query(array(
            'post_type'=>'post','post_status'=>'any','posts_per_page'=>1,'fields'=>'ids','no_found_rows'=>true,
            'meta_key'=>'_gnf5_source_url','meta_value'=>$url,
        ));
        return !empty($q->posts[0]) ? absint($q->posts[0]) : 0;
    }

    public static function valid_attachment($id) {
        $id = absint($id);
        if (!$id || get_post_type($id) !== 'attachment') { return false; }
        $file = get_attached_file($id);
        return $file && file_exists($file);
    }

    public static function set_state($post_id, $state, $message = '') {
        $post_id=absint($post_id);
        $state=sanitize_key($state);
        update_post_meta($post_id, '_gnf5_state', $state);
        update_post_meta($post_id, '_gnf5_state_updated', time());
        if ($message !== '') { update_post_meta($post_id, '_gnf5_state_message', sanitize_text_field($message)); }

        if (in_array($state,array('draft_created','processing','image_pending','validation_failed','post_processing_failed'),true)) {
            self::queue_auto_recovery($post_id);
        } elseif ($state==='complete') {
            self::clear_recovery_state($post_id);
        }
    }

    public static function failed_posts($limit = 20) {
        $s = self::settings();
        $meta = array(
            'relation'=>'AND',
            array('key'=>'_gnf5_generated_by','value'=>'fresh-v5','compare'=>'='),
            array('key'=>'_gnf5_state','value'=>array('draft_created','processing','image_pending','validation_failed','post_processing_failed'),'compare'=>'IN'),
        );
        // With automatic recovery enabled, show only exhausted items.
        // With it disabled, show failed drafts immediately so manual Retry is still available.
        if (!empty($s['auto_recovery_enabled'])) {
            $meta[] = array('key'=>'_gnf5_recovery_exhausted','value'=>'1','compare'=>'=');
        }

        $want=max(1,absint($limit));
        $q = new WP_Query(array(
            'post_type'=>'post','post_status'=>array('draft','pending'),'posts_per_page'=>min(100,max($want,$want*2)),
            'orderby'=>'modified','order'=>'DESC','no_found_rows'=>true,'meta_query'=>$meta,
        ));
        if (!empty($s['auto_recovery_enabled'])) { return array_slice($q->posts,0,$want); }

        // With automatic recovery OFF, don't mislabel a currently active checkpoint as a
        // manual failure. Transitional draft_created/processing states become manually
        // recoverable only after they have been stale for at least 15 minutes.
        $out=array();$stale_before=time()-(15*MINUTE_IN_SECONDS);
        foreach($q->posts as $post){
            $state=(string)get_post_meta($post->ID,'_gnf5_state',true);
            $updated=absint(get_post_meta($post->ID,'_gnf5_state_updated',true));
            if(in_array($state,array('draft_created','processing'),true) && $updated>$stale_before)continue;
            $out[]=$post;if(count($out)>=$want)break;
        }
        return $out;
    }

    public static function safe_substr($text, $start, $length = null) {
        if (function_exists('mb_substr')) {
            return $length === null ? mb_substr($text, $start) : mb_substr($text, $start, $length);
        }
        return $length === null ? substr($text, $start) : substr($text, $start, $length);
    }
}
