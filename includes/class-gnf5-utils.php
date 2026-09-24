<?php
if (!defined('ABSPATH')) { exit; }

class GNF5_Utils {
    private static $section_save = false;
    private static $settings_lock = null;
    private static $lock_tokens = array();
    private static $source_lock_tokens = array();
    public static function heartbeat_worker() {
        foreach(array_keys(self::$lock_tokens) as $cat_id)self::touch_lock($cat_id);
    }

    public static function defaults() {
        return array(
            // Existing V5.x keys are intentionally unchanged so an upgrade preserves current settings.
            'gemini_api_key' => '',
            'gemini_model' => 'gemini-3.5-flash-lite',
            'gemini_backup_model' => '',
            'post_status' => 'draft', // legacy compatibility
            'auto_publish_enabled' => 0,
            'auto_publish_recovered' => 0,
            'seo_analyzer_mode' => 'local',
            'seo_service_url' => '',
            'seo_service_key' => '',
            'seo_service_consent' => 0,
            'validated_success_status' => 'draft',
            'image_enabled' => 0,
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
            'auto_source_links' => 0,
            // New V5.7 instruction fields are blank by default and do not change previous behavior.
            'global_article_instructions' => '',
            'global_seo_instructions' => '',
            'global_image_instructions' => '',
            'added_value_target' => 70,
            'originality_retries' => 2,
            'history_days' => 90,
            'debug_enabled' => 0,
            'max_concurrent_categories' => 1,
            'categories' => array(),
        );
    }

    public static function settings() {
        $saved = get_option(GNF5_OPTION, array());
        $settings = wp_parse_args(is_array($saved) ? $saved : array(), self::defaults());
        $settings['auto_publish_enabled'] = 0;
        $settings['auto_publish_recovered'] = 0;
        $settings['post_status'] = $settings['validated_success_status'] = 'draft';
        if (!is_array($settings['categories'])) $settings['categories'] = array();
        return $settings;
    }

    public static function migrate() {
        if (get_option('gnf5_migration_version', '') === '6.0.0') return;
        $lock = get_option('gnf5_migration_lock', false);
        if ($lock !== false && (int)$lock < time() - 300) self::delete_lock_value('gnf5_migration_lock', $lock);
        $token = time();
        if (!add_option('gnf5_migration_lock', $token, '', false)) return;
        try {
            $saved = get_option(GNF5_OPTION, array());
            if (!is_array($saved)) $saved = array();
            if (!get_option('gnf5_legacy_publication_preferences', false)) {
                add_option('gnf5_legacy_publication_preferences', array_intersect_key($saved, array_flip(array('auto_publish_enabled','auto_publish_recovered','post_status','validated_success_status'))), '', false);
            }
            $out = wp_parse_args($saved, self::defaults());
            $out['auto_publish_enabled'] = $out['auto_publish_recovered'] = 0;
            $out['post_status'] = $out['validated_success_status'] = 'draft';
            $out['blocked_retry_hours'] = 6;
            // All existing category values, secrets and explicit image choices survive.
            update_option(GNF5_OPTION, $out, false);
            update_option('gnf5_migration_version', '6.0.0', false);
        } finally { self::delete_lock_value('gnf5_migration_lock', $token); }
    }

    public static function images_enabled($cat_id = 0) {
        $s = self::settings();
        $cs = $cat_id ? self::category_settings($cat_id, $s) : array();
        $mode = $cs['image_mode'] ?? 'global';
        return $mode === 'on' || ($mode === 'global' && !empty($s['image_enabled']));
    }

    public static function category_valid($cat_id) {
        $cat = get_category(absint($cat_id));
        return $cat && !is_wp_error($cat);
    }

    public static function category_settings($cat_id, $settings = null) {
        if ($settings === null) { $settings = self::settings(); }
        $defaults = array(
            'enabled' => 0,
            'post_limit' => 1,
            'interval' => 'hourly',
            'rss' => '',
            'urls' => '',
            'author_id' => 0,
            'external_links' => '', // Legacy research-only URLs; never implicit public-link consent.
            'manual_links_enabled' => 0, 'manual_links_max' => 2, 'manual_links' => array(),
            'image_featured' => 1, 'image_inline' => 1, 'image_webp' => 1,
            'instructions' => '',
            'image_mode' => 'global', 'gdelt_enabled' => 0, 'gdelt_keywords' => '',
            'gdelt_language' => 'english', 'gdelt_country' => '', 'gdelt_window' => '6h',
            'gdelt_results' => 50, 'gdelt_interval' => 60, 'min_sources' => 2,
            'max_candidates' => 50, 'opportunity_threshold' => 60,
        );
        $saved = isset($settings['categories'][$cat_id]) && is_array($settings['categories'][$cat_id])
            ? $settings['categories'][$cat_id] : array();
        return wp_parse_args($saved, $defaults);
    }

    public static function sanitize_settings($input) {
        $input = is_array($input) ? $input : array();
        $d = self::defaults();
        $previous = self::settings();
        $input = wp_parse_args($input, $previous);
        $out = $previous;
        $out['seo_analyzer_mode'] = in_array(($input['seo_analyzer_mode'] ?? $previous['seo_analyzer_mode']), array('local','remote'), true) ? ($input['seo_analyzer_mode'] ?? $previous['seo_analyzer_mode']) : 'local';
        $out['seo_service_url'] = esc_url_raw(trim($input['seo_service_url'] ?? $previous['seo_service_url']), array('https'));
        $out['seo_service_key'] = !empty($input['seo_service_key']) ? sanitize_text_field(trim($input['seo_service_key'])) : $previous['seo_service_key'];
        if (!empty($input['seo_service_clear_key'])) $out['seo_service_key'] = '';
        $out['seo_service_consent'] = array_key_exists('seo_service_consent', $input) ? (empty($input['seo_service_consent']) ? 0 : 1) : $previous['seo_service_consent'];
        $out['gemini_api_key'] = !empty($input['gemini_api_key']) ? sanitize_text_field($input['gemini_api_key']) : $previous['gemini_api_key'];
        if (!empty($input['clear_gemini_api_key'])) $out['gemini_api_key'] = '';
        $out['gemini_model'] = sanitize_text_field($input['gemini_model'] ?? $d['gemini_model']);
        $out['gemini_backup_model'] = sanitize_text_field($input['gemini_backup_model'] ?? '');
        $out['auto_publish_enabled'] = $out['auto_publish_recovered'] = 0;
        $out['post_status'] = $out['validated_success_status'] = 'draft';
        $out['image_enabled'] = empty($input['image_enabled']) ? 0 : 1;
        $out['image_provider'] = in_array(($input['image_provider'] ?? 'openai'), array('openai','webui','builtin'), true)
            ? $input['image_provider'] : 'openai';
        $out['openai_api_key'] = !empty($input['openai_api_key']) ? sanitize_text_field($input['openai_api_key']) : $previous['openai_api_key'];
        if (!empty($input['clear_openai_api_key'])) $out['openai_api_key'] = '';
        $out['openai_model'] = sanitize_text_field($input['openai_model'] ?? $d['openai_model']);
        $out['openai_quality'] = in_array(($input['openai_quality'] ?? 'low'), array('low','medium','high','xhigh','max','auto'), true)
            ? $input['openai_quality'] : 'low';
        $out['openai_size'] = in_array(($input['openai_size'] ?? '1536x1024'), array('1024x1024','1024x1536','1536x1024'), true)
            ? $input['openai_size'] : '1536x1024';
        $out['webui_endpoint'] = esc_url_raw(rtrim($input['webui_endpoint'] ?? $d['webui_endpoint'], '/'));
        $out['webui_api_key'] = !empty($input['webui_api_key']) ? sanitize_text_field($input['webui_api_key']) : $previous['webui_api_key'];
        if (!empty($input['clear_webui_api_key'])) $out['webui_api_key'] = '';
        $out['webui_model'] = sanitize_text_field($input['webui_model'] ?? '');
        $out['builtin_fallback'] = empty($input['builtin_fallback']) ? 0 : 1;
        $out['webp_quality'] = min(90, max(50, absint($input['webp_quality'] ?? 72)));
        $out['blocked_retry_hours'] = 6;
        $out['max_concurrent_categories'] = min(2,max(1,absint($input['max_concurrent_categories'] ?? $previous['max_concurrent_categories'])));
        $out['added_value_target'] = min(100, max(0, absint($input['added_value_target'] ?? $previous['added_value_target'])));
        $out['originality_retries'] = min(2, absint($input['originality_retries'] ?? $previous['originality_retries']));
        $out['history_days'] = min(365, max(7, absint($input['history_days'] ?? $previous['history_days'])));
        $out['debug_enabled'] = empty($input['debug_enabled']) ? 0 : 1;
        $out['auto_recovery_enabled'] = empty($input['auto_recovery_enabled']) ? 0 : 1;
        $out['auto_recovery_max_attempts'] = min(5, max(1, absint($input['auto_recovery_max_attempts'] ?? 3)));
        $out['rankmath_enabled'] = empty($input['rankmath_enabled']) ? 0 : 1;
        $out['toc_enabled'] = empty($input['toc_enabled']) ? 0 : 1;
        $out['internal_links'] = empty($input['internal_links']) ? 0 : 1;
        $out['auto_source_links'] = empty($input['auto_source_links']) ? 0 : 1;
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
                $submitted = $input['categories'][$id];
                if (!self::$section_save) foreach (self::section_keys() as $key) unset($submitted[$key]);
                $merged_row = array_merge($old_row, $submitted);
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
        $row = wp_parse_args($row, self::category_settings(0));
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
            'manual_links_enabled' => empty($row['manual_links_enabled']) ? 0 : 1,
            'manual_links_max' => min(3, max(0, (int)$row['manual_links_max'])),
            'manual_links' => is_array($row['manual_links']) ? $row['manual_links'] : array(),
            'image_featured' => empty($row['image_featured']) ? 0 : 1,
            'image_inline' => empty($row['image_inline']) ? 0 : 1,
            'image_webp' => empty($row['image_webp']) ? 0 : 1,
            'image_mode' => in_array($row['image_mode'] ?? 'global', array('global','on','off'), true) ? ($row['image_mode'] ?? 'global') : 'global',
            'gdelt_enabled' => empty($row['gdelt_enabled']) ? 0 : 1,
            'gdelt_keywords' => self::safe_substr(sanitize_text_field($row['gdelt_keywords'] ?? ''), 0, 500),
            'gdelt_language' => preg_replace('/[^a-z]/', '', strtolower($row['gdelt_language'] ?? 'english')),
            'gdelt_country' => preg_replace('/[^a-z]/', '', strtolower($row['gdelt_country'] ?? '')),
            'gdelt_window' => in_array($row['gdelt_window'] ?? '6h', array('1h','6h','12h','24h','3d','7d'), true) ? ($row['gdelt_window'] ?? '6h') : '6h',
            'gdelt_results' => min(100, max(5, absint($row['gdelt_results'] ?? 50))),
            'gdelt_interval' => min(1440, max(15, absint($row['gdelt_interval'] ?? 60))),
            'min_sources' => min(5, max(1, absint($row['min_sources'] ?? 2))),
            'max_candidates' => min(100, max(1, absint($row['max_candidates'] ?? 50))),
            'opportunity_threshold' => min(100, absint($row['opportunity_threshold'] ?? 60)),
        );
    }

    public static function section_keys($section = '') {
        $images=array('image_mode','image_featured','image_inline','image_webp');
        $links=array('manual_links_enabled','manual_links_max','manual_links');
        if($section==='general')return array_values(array_diff(array_keys(self::category_settings(0)),array_merge($images,$links,array('external_links'))));
        return $section==='images' ? $images : ($section==='links' ? $links : array_merge($images,$links,array('external_links')));
    }

    public static function section_revision($row,$section) {
        return hash('sha256',wp_json_encode(array_intersect_key($row,array_flip(self::section_keys($section)))));
    }

    /** Validate a complete browser form against the values it originally displayed. */
    public static function validate_settings_form($input) {
        if(!is_array($input) || empty($input['_form_complete']))return new WP_Error('incomplete_settings','The settings form was incomplete. Nothing was saved. Reload and use the individual category Save buttons, or ask your host to increase max_input_vars.');
        $settings=self::settings();
        if(!isset($input['_global_revision']) || !is_string($input['_global_revision']) || !hash_equals(self::global_revision($settings),$input['_global_revision']))return new WP_Error('stale_settings','Global settings changed after this page was opened. Nothing from this form was saved. Copy your unsaved edits, reload the page, and apply them again.');
        foreach((array)($input['categories']??array()) as $cat=>$row){
            if(!is_array($row) || empty($row['_row_complete']) || !isset($row['_revision']) || !is_string($row['_revision']))return new WP_Error('incomplete_settings','A category form was incomplete. Nothing was saved. Reload and try its category Save button.');
            if(!hash_equals(self::section_revision(self::category_settings($cat,$settings),'general'),$row['_revision']))return new WP_Error('stale_settings',get_cat_name($cat).' settings changed after this page was opened. Nothing from this form was saved. Copy your unsaved edits, reload the page, and apply them again.');
        }
        return true;
    }

    public static function global_revision($settings) {
        unset($settings['categories']);return wp_hash(wp_json_encode($settings));
    }

    /** One shared lock for all UI settings writers because categories share one WordPress option. */
    public static function acquire_settings_lock() {
        global $wpdb;
        if(self::$settings_lock!==null)return false;
        $key='gnf5_settings_write_lock';$old=get_option($key,false);
        if(is_array($old) && (int)($old['time']??0)<time()-120)self::delete_lock_value($key,$old);
        $value=array('token'=>wp_generate_uuid4(),'time'=>time());
        // add_option uses an upsert after a cached existence check. INSERT IGNORE
        // makes ownership atomic even when two workers both observed no lock.
        if(1!==(int)$wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,'no')",$key,maybe_serialize($value))))return false;
        wp_cache_delete($key,'options');wp_cache_delete('notoptions','options');
        self::$settings_lock=$value;
        // A waiting request may already have loaded the old option into its local/object cache.
        wp_cache_delete(GNF5_OPTION,'options');wp_cache_delete('alloptions','options');
        register_shutdown_function(array(__CLASS__,'release_settings_lock'));
        return true;
    }

    public static function release_settings_lock() {
        if(self::$settings_lock===null)return;
        self::delete_lock_value('gnf5_settings_write_lock',self::$settings_lock);
        self::$settings_lock=null;
    }

    /** Read, merge, validate and commit while holding the shared settings lock. */
    public static function save_category_section($cat,$section,$values,$revision='') {
        if(!in_array($section,array('images','links','general'),true) || !self::category_valid($cat))return new WP_Error('invalid_settings','Invalid settings section or category.');
        if(!self::acquire_settings_lock())return new WP_Error('settings_busy','Another settings save is in progress. Your changes were not saved. Wait for it to finish, then click Save again.');
        $previous_scope=self::$section_save;
        try {
            $settings=self::settings();$row=self::category_settings($cat,$settings);
            if(($section!=='general' || $revision!=='') && !hash_equals(self::section_revision($row,$section),(string)$revision))
                return new WP_Error('stale_settings','These settings changed in another tab. Reload this page before saving.');
            $row=array_merge($row,array_intersect_key($values,array_flip(self::section_keys($section))));
            $settings['categories'][$cat]=self::sanitize_category_row($row);
            $expected=self::section_revision($settings['categories'][$cat],$section);
            $settings['categories'][$cat]['_row_complete']=1;
            self::$section_save=$section!=='general';
            update_option(GNF5_OPTION,$settings,false);
            // Verify the committed database row, not a possibly stale object-cache value.
            self::option_cache_clear(GNF5_OPTION);
            $saved=self::category_settings($cat,self::fresh_option(GNF5_OPTION,array()));
            if(!hash_equals($expected,self::section_revision($saved,$section)))return new WP_Error('save_failed','Settings could not be saved. Reload the page and try again.');
            return self::section_revision($saved,$section);
        } finally {
            self::$section_save=$previous_scope;
            self::release_settings_lock();
        }
    }

    public static function sanitize_manual_links($rows) {
        if(!is_array($rows) || count($rows)>30)return new WP_Error('links','Use no more than 30 manual link entries.');
        $out=array();$ids=array();
        foreach($rows as $row){
            if(!is_array($row))return new WP_Error('links','Invalid manual link entry.');
            foreach($row as $value)if(!is_scalar($value))return new WP_Error('links','Invalid manual link field.');
            $url=self::normalize_url(trim((string)($row['url']??'')));
            if(!$url || !filter_var($url,FILTER_VALIDATE_URL))return new WP_Error('link_url','Every manual link needs a valid public HTTP or HTTPS URL. Remove incomplete entries before saving.');
            $host=(string)wp_parse_url($url,PHP_URL_HOST);
            if(strpos($host,'.')===false || preg_match('/\.(?:test|localhost|lan|home|invalid)$/i',$host) || (preg_match('/[.\d]$/',$host) && !filter_var($host,FILTER_VALIDATE_IP)))return new WP_Error('link_url','Local or private URLs are not allowed.');
            foreach((array)gethostbynamel($host) as $ip)if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))return new WP_Error('link_url','URLs resolving to private or local addresses are not allowed.');
            $id=sanitize_key($row['id']??'');if(!$id || isset($ids[$id]))$id=wp_generate_uuid4();$ids[$id]=true;
            $out[]=array('id'=>$id,'url'=>$url,'anchor'=>self::safe_substr(sanitize_text_field($row['anchor']??''),0,160),
                'note'=>self::safe_substr(sanitize_textarea_field($row['note']??''),0,500),'enabled'=>empty($row['enabled'])?0:1,
                'usage'=>($row['usage']??'optional')==='preferred'?'preferred':'optional');
        }
        return $out;
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
        return 0;
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

        if (!empty($parts['user']) || !empty($parts['pass'])) return '';
        $host_check = strtolower(trim($parts['host'], '[]'));
        if ($host_check === 'localhost' || preg_match('/\.(?:localhost|local|internal)$/i', $host_check) || strpos($host_check, '.') === false) return '';
        if (filter_var($host_check, FILTER_VALIDATE_IP) && !filter_var($host_check, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return '';
        if (isset($parts['port']) && !in_array((int)$parts['port'], array(80,443), true)) return '';

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
        if($type==='debug' && empty(self::settings()['debug_enabled']))return;
        $entry=array('time'=>current_time('mysql'),'type'=>sanitize_key($type),'cat'=>absint($cat_id),'message'=>self::redact($message));
        for($attempt=0;$attempt<20;$attempt++){
            $old=self::fresh_option(GNF5_LOG_OPTION,false);$log=is_array($old)?$old:array();array_unshift($log,$entry);$log=array_slice($log,0,250);
            if($old===false?self::atomic_add(GNF5_LOG_OPTION,$log):self::compare_option(GNF5_LOG_OPTION,$old,$log))return;
        }
        error_log('GlobiqNews log contention: '.$entry['message']);
    }

    public static function desired_success_status($is_recovery = false) { return 'draft'; }

    public static function adaptive_scan_limit($post_limit) {
        $post_limit = min(10, max(1, absint($post_limit)));
        return min(60, max(10, ($post_limit * 8) + 6));
    }

    public static function cron_schedules($schedules) {
        $schedules['gnf6_minute'] = array('interval'=>60,'display'=>'Every minute (category queue recovery)');
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
        self::migrate();
        self::reschedule_all();
        self::ensure_recovery_schedule();
    }

    public static function deactivate() {
        if(class_exists('GNF6_Queue'))GNF6_Queue::deactivate();
        if(class_exists('GNF5_RankMath'))wp_unschedule_hook(GNF5_RankMath::HOOK);
        wp_unschedule_hook(GNF5_CRON_HOOK);
        if (defined('GNF5_RECOVERY_CRON_HOOK')) { wp_clear_scheduled_hook(GNF5_RECOVERY_CRON_HOOK); }
        if (defined('GNF5_CRON_CONTINUE_HOOK')) { wp_unschedule_hook(GNF5_CRON_CONTINUE_HOOK); }
        if (defined('GNF5_BULK_RECOVERY_HOOK')) { wp_clear_scheduled_hook(GNF5_BULK_RECOVERY_HOOK); }
        if (defined('GNF5_BULK_RECOVERY_LOCK')) { delete_option(GNF5_BULK_RECOVERY_LOCK); }
        foreach(get_categories(array('hide_empty'=>false)) as $cat){ self::end_cron_chain($cat->term_id); self::force_clear_lock($cat->term_id); }
        // Preserve the bulk queue option so an upgrade/deactivate/reactivate cannot
        // silently lose the administrator's selected recovery work.
    }

    public static function reschedule_all() {
        wp_unschedule_hook(GNF5_CRON_HOOK);
        if (defined('GNF5_CRON_CONTINUE_HOOK')) { wp_unschedule_hook(GNF5_CRON_CONTINUE_HOOK); }
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
        if (empty($s['auto_recovery_enabled']) && empty($s['rankmath_enabled'])) {
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
        set_transient(self::cron_chain_key($cat_id),array('id'=>wp_generate_uuid4(),'started'=>time(),'target'=>max(1,absint($target)),'touched'=>time()),45*MINUTE_IN_SECONDS);
        return true;
    }

    public static function touch_cron_chain($cat_id,$target=1) {
        $state=get_transient(self::cron_chain_key($cat_id));
        if(!is_array($state)){self::start_cron_chain($cat_id,$target);return;}
        $state['touched']=time();set_transient(self::cron_chain_key($cat_id),$state,45*MINUTE_IN_SECONDS);
    }

    public static function end_cron_chain($cat_id) { delete_transient(self::cron_chain_key($cat_id)); }

    public static function lock_key($cat_id) { return 'gnf5_lock_cat_' . absint($cat_id); }

    private static function lock_fresh($state,$ttl=900) {
        return is_array($state) && !empty($state['time']) && (time()-absint($state['time'])) < max(60,absint($ttl));
    }

    // Read ownership directly from the database, avoiding stale per-request option caches.
    public static function fresh_option($key,$default=false) {
        global $wpdb;
        $raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$key));
        return $raw===null?$default:maybe_unserialize($raw);
    }
    public static function option_cache_clear($key) {
        wp_cache_delete($key,'options');wp_cache_delete('notoptions','options');wp_cache_delete('alloptions','options');
    }
    public static function atomic_add($key,$value) {
        global $wpdb;
        $ok=1===(int)$wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,'no')",$key,maybe_serialize($value)));
        if($ok)self::option_cache_clear($key);return $ok;
    }
    public static function compare_option($key,$old,$new) {
        global $wpdb;
        $ok=1===(int)$wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND option_value=%s",maybe_serialize($new),$key,maybe_serialize($old)));
        if($ok)self::option_cache_clear($key);return $ok;
    }
    public static function delete_lock_value($key,$value) {
        global $wpdb;
        $deleted=$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s",$key,maybe_serialize($value)));
        if($deleted)self::option_cache_clear($key);return (bool)$deleted;
    }
    public static function worker_key($slot) { return $slot===0?'gnf5_article_worker':'gnf6_article_worker_1'; }
    public static function worker_limit() { return min(2,max(1,(int)self::settings()['max_concurrent_categories'])); }
    public static function worker_states() {
        $active=array();
        for($i=0;$i<2;$i++){
            $key=self::worker_key($i);$state=self::fresh_option($key,array());
            if(!$state)continue;
            $cat=self::fresh_option(self::lock_key($state['category']??0),array());
            // New reservations can briefly precede the matching category slot assignment.
            $valid_job=true;
            if(!empty($state['job_id'])){
                $job=self::fresh_option('gnf6_category_job_'.absint($state['category']),array());
                $valid_job=($job['id']??'')===$state['job_id'] && (
                    (($job['state']??'')==='claiming' && ($job['updated']??0)>=time()-120) ||
                    (in_array($job['state']??'',array('dispatched','processing'),true) && ($job['token']??'')===($state['token']??'')));
            }
            if($valid_job && self::lock_fresh($state,1800) && is_array($cat) && ($cat['token']??'')===($state['token']??''))$active[$i]=$state;
            else {self::release_token($state['category']??0,$state['token']??'');}
        }
        return $active;
    }
    public static function acquire_lock($cat_id,$job_id='') {
        $cat_id=absint($cat_id);$key=self::lock_key($cat_id);
        $legacy=get_transient($key);if(self::lock_fresh($legacy,1800))return false;
        if($legacy)delete_transient($key);
        $old=self::fresh_option($key,array());
        if($old && self::lock_fresh($old,1800))return false;
        if($old)self::delete_lock_value($key,$old);
        $token=wp_generate_uuid4();$state=array('time'=>time(),'token'=>$token,'category'=>$cat_id);
        if($job_id)$state['job_id']=$job_id;
        if(!self::atomic_add($key,$state))return false;
        $limit=self::worker_limit();$active=self::worker_states();
        if(count($active)<$limit)for($slot=0;$slot<$limit;$slot++){
            if(!self::atomic_add(self::worker_key($slot),$state))continue;
            self::$lock_tokens[$cat_id]=$token;return true;
        }
        self::delete_lock_value($key,$state);return false;
    }
    public static function lock_token($cat_id) { return self::$lock_tokens[absint($cat_id)]??''; }
    public static function detach_lock($cat_id) { unset(self::$lock_tokens[absint($cat_id)]); }
    public static function token_owns_lock($cat_id,$token) {
        if(!$token)return false;$cat=self::fresh_option(self::lock_key($cat_id),array());
        if(!self::lock_fresh($cat,1800) || !hash_equals((string)($cat['token']??''),(string)$token))return false;
        foreach(self::worker_states() as $state)if(hash_equals((string)$state['token'],(string)$token))return true;
        return false;
    }
    public static function adopt_lock($cat_id,$token) {
        if(!self::token_owns_lock($cat_id,$token))return false;
        self::$lock_tokens[absint($cat_id)]=$token;return true;
    }
    public static function owns_lock($cat_id) { return self::token_owns_lock($cat_id,self::lock_token($cat_id)); }
    public static function touch_lock($cat_id) {
        $token=self::lock_token($cat_id);if(!$token)return false;
        $keys=array(self::lock_key($cat_id),self::worker_key(0),self::worker_key(1));$touched=false;
        foreach($keys as $key){$old=self::fresh_option($key,array());if(($old['token']??'')!==$token)continue;$new=$old;$new['time']=time();if($new!==$old)self::compare_option($key,$old,$new);$touched=true;}
        return $touched;
    }
    public static function release_token($cat_id,$token) {
        if(!$token)return;
        foreach(array(self::lock_key($cat_id),self::worker_key(0),self::worker_key(1)) as $key){$state=self::fresh_option($key,array());if(($state['token']??'')===$token)self::delete_lock_value($key,$state);}
    }
    public static function release_lock($cat_id) {
        $token=self::lock_token($cat_id);self::release_token($cat_id,$token);self::detach_lock($cat_id);
        if($token){GNF5_Utils::log('Worker slot released.','debug',$cat_id);do_action('gnf6_worker_released',$cat_id);}
    }
    public static function force_clear_lock($cat_id) {
        $state=self::fresh_option(self::lock_key($cat_id),array());
        if(self::lock_fresh($state,1800) || self::lock_fresh(get_transient(self::lock_key($cat_id)),1800))return false;
        if($state)self::release_token($cat_id,$state['token']??'');
        delete_transient(self::lock_key($cat_id));self::worker_states();
        do_action('gnf6_worker_released',$cat_id);return true;
    }
    public static function stale_lock($cat_id) {
        $state=self::fresh_option(self::lock_key($cat_id),array());return $state && !self::lock_fresh($state,1800);
    }
    public static function is_locked($cat_id) {
        $key=self::lock_key($cat_id);$state=self::fresh_option($key,array());
        if(self::lock_fresh($state,1800))return true;
        if($state)self::release_token($cat_id,$state['token']??'');
        return self::lock_fresh(get_transient($key),1800);
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
        if(!self::atomic_add($key,$state)){
            $existing=get_option($key,array());
            if(self::lock_fresh($existing,2700))return false;
            self::delete_lock_value($key,$existing);
            if(!self::atomic_add($key,$state))return false;
        }
        self::$source_lock_tokens[$key]=$token;
        return true;
    }

    public static function release_source_lock($url) {
        $key=self::source_lock_key($url);
        $token=self::$source_lock_tokens[$key]??'';
        $existing=get_option($key,array());
        if($token!=='' && is_array($existing) && hash_equals((string)($existing['token']??''),(string)$token)){
            self::delete_lock_value($key,$existing);
        }
        unset(self::$source_lock_tokens[$key]);
    }

    public static function blocked_key($url) { $id=self::normalize_url($url); return 'gnf5_block_' . md5($id?:trim((string)$url)); }
    public static function is_blocked_cached($url) { return (bool)get_transient(self::blocked_key($url)); }

    public static function mark_blocked($url, $reason = 'blocked',$http=null,$content_type='') {
        $key = self::blocked_key($url);
        $old = get_transient($key);
        $row = array('url'=>self::redact($url), 'reason'=>self::redact($reason), 'time'=>time(),'http'=>$http,'content_type'=>$content_type,
            'next_retry'=>time()+6*HOUR_IN_SECONDS, 'attempts'=>is_array($old) ? (int)($old['attempts'] ?? 0)+1 : 1);
        set_transient($key, $row, 6*HOUR_IN_SECONDS);
        return $row;
    }

    public static function redact($message) {
        $message = (string)$message;
        $saved = get_option(GNF5_OPTION, array());
        foreach (array('gemini_api_key','openai_api_key','webui_api_key','seo_service_key') as $key) {
            if (!empty($saved[$key])) $message = str_replace($saved[$key], '[redacted]', $message);
        }
        $message = preg_replace('/([?&](?:key|api_key|token|secret|signature|password)=)[^&\s]+/i', '$1[redacted]', $message);
        return self::safe_substr(wp_strip_all_tags($message), 0, 2500);
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
