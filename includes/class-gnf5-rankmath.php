<?php
if (!defined('ABSPATH')) { exit; }

/** Runs the installed Rank Math analyzer on a committed article, locally in Node.js. */
class GNF5_RankMath {
    const VERSION = '1.0.278';
    const ENGINE = 'af1e4954b835a846b44d37eb1aa87951475def96bd6f257db1e394afa781e61f';
    const HOOK = 'gnf5_rankmath_score_retry';
    const MAX_ATTEMPTS = 3;
    private static $running = array();
    private static $writing_score = false;

    public static function available() {
        return defined('RANK_MATH_VERSION') && function_exists('rank_math') && class_exists('RankMath\\Helper') && is_object(rank_math()->variables);
    }

    public static function node_binary() {
        if (defined('GNF5_NODE_BINARY')) { return is_file(GNF5_NODE_BINARY) ? GNF5_NODE_BINARY : ''; }
        $paths = array('/usr/bin/node', '/usr/local/bin/node');
        foreach (array(24,22,20,18) as $version) {
            $paths[] = '/opt/alt/alt-nodejs'.$version.'/root/usr/bin/node';
            $paths[] = '/opt/cpanel/ea-nodejs'.$version.'/bin/node';
        }
        foreach ($paths as $path) { if (is_file($path) && is_executable($path)) { return $path; } }
        return '';
    }

    public static function compatibility_error() {
        if (!self::available()) { return 'Rank Math unavailable; article stays Draft.'; }
        if (defined('RANK_MATH_PRO_FILE')) { return 'Background analysis for Rank Math PRO has not been verified; article stays Draft.'; }
        if (RANK_MATH_VERSION !== self::VERSION) { return 'Rank Math '.RANK_MATH_VERSION.' is not yet verified. Supported analyzer: '.self::VERSION.'.'; }
        if (!function_exists('proc_open')) { return 'Background SEO analysis needs PHP proc_open enabled by your host.'; }
        if (!self::node_binary()) { return 'Background SEO analysis needs Node.js 18+ on this hosting account. Ask your host for the Node binary path; set GNF5_NODE_BINARY in wp-config.php if needed.'; }
        $engine = rank_math()->plugin_dir().'assets/admin/js/analyzer.js';
        if (!is_readable($engine) || hash_file('sha256', $engine) !== self::ENGINE) {
            return 'Rank Math analyzer differs from the verified 1.0.278 engine; article stays Draft.';
        }
        return '';
    }

    /** Build the same inputs as Rank Math, including its PHP customization filters. */
    public static function payload($post_id) {
        if (!self::available() || RANK_MATH_VERSION !== self::VERSION || defined('RANK_MATH_PRO_FILE')) { return new WP_Error('rankmath_unavailable', 'Compatible Rank Math analyzer unavailable.'); }
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'post') { return new WP_Error('seo_post', 'Invalid article.'); }
        if(!class_exists('RankMath\\Replace_Variables\\Replacer') || !property_exists('RankMath\\Replace_Variables\\Replacer','replacements_cache')
            || !property_exists('RankMath\\Replace_Variables\\Replacer','non_cacheable_replacements') || !property_exists('RankMath\\Replace_Variables\\Replacer','content_processed')) {
            return new WP_Error('seo_compatibility','Rank Math replacement API is incompatible.');
        }
        $old_post = $GLOBALS['post'] ?? null;
        $old_query = $GLOBALS['wp_query'] ?? null;
        $old_replacements = \RankMath\Replace_Variables\Replacer::$replacements_cache;
        $old_non_cacheable = \RankMath\Replace_Variables\Replacer::$non_cacheable_replacements;
        $old_content = \RankMath\Replace_Variables\Replacer::$content_processed;
        \RankMath\Replace_Variables\Replacer::$replacements_cache=array();
        \RankMath\Replace_Variables\Replacer::$non_cacheable_replacements=null;
        \RankMath\Replace_Variables\Replacer::$content_processed=array();
        $GLOBALS['post'] = $post;
        $GLOBALS['wp_query'] = new WP_Query();
        try {
            if(!\RankMath\Helper::is_post_indexable($post_id,false) || !\RankMath\Helper::is_score_enabled())throw new RuntimeException('Rank Math score is unavailable for a noindex article or while its score display is disabled.');
            if (!class_exists('RankMath\\Admin\\Metabox\\Screen')) { throw new RuntimeException('Rank Math metabox compatibility API unavailable.'); }
            if (!function_exists('get_sample_permalink')) { require_once ABSPATH.'wp-admin/includes/post.php'; }
            $screen = new \RankMath\Admin\Metabox\Screen();
            $screen->load_screen('post');
            $localized = $screen->get_values();
            $config = array_intersect_key($localized, array_flip(array('parentDomain','noFollowDomains','noFollowExcludeDomains','noFollowExternalLinks','localeFull','postType','objectID','objectType')));
            $config['postType'] = 'post';
            $config['links'] = \RankMath\KB::get_links();
            $config['assessor'] = array_intersect_key($localized['assessor'], array_flip(array('powerWords','diacritics','researchesTests','hasTOCPlugin','focusKeywordLink','isReviewEnabled')));
            // Screen uses include_once for diacritics. Restore an array on later jobs in this request.
            if (!is_array($config['assessor']['diacritics'])) {
                $locale = explode('_', get_locale())[0];
                $locale = in_array($locale, array('en','de','ru'), true) ? $locale : 'en';
                $diacritics = include rank_math()->plugin_dir().'assets/vendor/diacritics/'.$locale.'.php';
                $config['assessor']['diacritics'] = apply_filters('rank_math/metabox/diacritics', $diacritics, $locale);
            }
            rank_math()->variables->setup();
            add_filter('rank_math/replacements/non_cacheable', array(__CLASS__, 'non_cacheable'));
            $keywords = array_values(array_filter(array_map('trim', explode(',', (string)get_post_meta($post_id, 'rank_math_focus_keyword', true)))));
            $sample = get_sample_permalink($post_id);
            $url = is_array($sample) ? str_replace(array('%postname%','%pagename%'), $sample[1], $sample[0]) : get_permalink($post_id);
            // A fresh Rank Math metadata object avoids its per-request Post cache
            // reusing a title/description from before an SEO repair in this worker.
            $metadata = new \RankMath\Post($post);
            $title = $metadata->get_metadata('title');
            $description = $metadata->get_metadata('description');
            $values = array(
                'title' => $title !== '' ? $title : \RankMath\Paper\Paper::get_from_options('pt_post_title',$post,'%title% %sep% %sitename%'),
                'description' => $description !== '' ? $description : \RankMath\Paper\Paper::get_from_options('pt_post_description',$post,'%excerpt%'),
                'keywords' => $keywords, 'keyword' => $keywords[0] ?? '',
                'content' => wpautop($post->post_content), 'url' => urldecode($url),
                'hasContentAi' => !empty(get_post_meta($post_id, 'rank_math_contentai_score', true)),
                'post_type' => 'post', 'schemas' => array(),
            );
            foreach (get_post_meta($post_id) as $key => $unused) {
                if (strpos($key, 'rank_math_schema_') === 0) { $values['schemas'][$key] = get_post_meta($post_id, $key, true); }
            }
            if (has_post_thumbnail($post_id)) {
                $values['thumbnail'] = get_the_post_thumbnail_url($post_id);
                $values['thumbnailAlt'] = get_post_meta(get_post_thumbnail_id($post_id), '_wp_attachment_image_alt', true);
            }
            $values = apply_filters('rank_math/recalculate_score/data', $values, $post_id);
            if (!is_array($values) || empty($values['keyword']) || empty($values['content'])) { throw new RuntimeException('Final content or focus keyword missing.'); }
            global $wpdb;
            $keyword_usage = array();
            foreach ($keywords as $keyword) {
                $keyword = function_exists('mb_strtolower') ? mb_strtolower($keyword, 'UTF-8') : strtolower($keyword);
                // Matches Rank Math Admin::is_keyword_new (published primary focus keywords).
                $found = $wpdb->get_var($wpdb->prepare("SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON p.ID=m.post_id WHERE p.post_status='publish' AND m.meta_key='rank_math_focus_keyword' AND (m.meta_value=%s OR m.meta_value LIKE %s) AND p.ID!=%d LIMIT 1", $keyword, $wpdb->esc_like($keyword).',%', $post_id));
                $keyword_usage[$keyword] = empty($found);
            }
            $scripts = array('analyzer'=>rank_math()->plugin_dir().'assets/admin/js/analyzer.js', 'lodash'=>ABSPATH.WPINC.'/js/dist/vendor/lodash.min.js');
            foreach (array('hooks','i18n','autop','url','wordcount') as $name) { $scripts[$name] = ABSPATH.WPINC.'/js/dist/'.$name.'.min.js'; }
            $hashes = array();
            foreach ($scripts as $name => $path) {
                if (!is_readable($path)) { throw new RuntimeException('Missing installed analyzer dependency: '.$name); }
                $hashes[$name] = hash_file('sha256', $path);
            }
            if ($hashes['analyzer'] !== self::ENGINE) { throw new RuntimeException('Unverified Rank Math analyzer.'); }
            $inputs = array('version'=>RANK_MATH_VERSION, 'values'=>$values, 'config'=>$config, 'keywordUsage'=>$keyword_usage, 'scripts'=>$hashes,
                'source'=>GNF5_Publish::fingerprint($post_id));
            $fingerprint = hash('sha256', wp_json_encode($inputs));
            return array('version'=>RANK_MATH_VERSION, 'values'=>$values, 'config'=>$config, 'keywordUsage'=>$keyword_usage, 'scripts'=>$scripts, 'fingerprint'=>$fingerprint);
        } catch (Throwable $e) { return new WP_Error('seo_compatibility', $e->getMessage()); }
        finally {
            remove_filter('rank_math/replacements/non_cacheable', array(__CLASS__, 'non_cacheable'));
            $GLOBALS['post'] = $old_post; $GLOBALS['wp_query'] = $old_query;
            \RankMath\Replace_Variables\Replacer::$replacements_cache=$old_replacements;
            \RankMath\Replace_Variables\Replacer::$non_cacheable_replacements=$old_non_cacheable;
            \RankMath\Replace_Variables\Replacer::$content_processed=$old_content;
        }
    }

    public static function non_cacheable($names) {
        return array_unique(array_merge($names, array('excerpt','excerpt_only','seo_description','seo_title','keywords','focuskw','title')));
    }

    private static function execute($payload) {
        $error = self::compatibility_error();
        if ($error) { return new WP_Error('seo_runtime', $error); }
        // Anonymous temporary files avoid pipe deadlocks (including Windows PHP).
        // They are outside the web root and removed automatically when closed.
        $files = array(); $process = null;
        $stdout = ''; $stderr = ''; $exit = -1;
        try {
            $json = wp_json_encode($payload);
            if (!$json || strlen($json) > 2*1024*1024) { throw new RuntimeException('Analysis payload is invalid or too large.'); }
            for ($i=0;$i<3;$i++) {
                $files[$i]=tmpfile();
                if (!is_resource($files[$i])) throw new RuntimeException('Host temporary storage unavailable.');
            }
            if (fwrite($files[0], $json)!==strlen($json)) throw new RuntimeException('Could not write analysis input.');
            rewind($files[0]);
            $pipes=array();
            $process=@proc_open(array(self::node_binary(), '--max-old-space-size=128', GNF5_DIR.'assets/rankmath-analyzer.cjs'), $files, $pipes, GNF5_DIR, null, array('bypass_shell'=>true));
            if (!is_resource($process)) throw new RuntimeException('Host could not start the Rank Math analyzer.');
            $start = microtime(true);
            while (true) {
                if (microtime(true)-$start > 20) { throw new RuntimeException('Rank Math analysis timed out after 20 seconds.'); }
                if (fstat($files[1])['size']+fstat($files[2])['size'] > 1024*1024) throw new RuntimeException('Analyzer output exceeds safe size.');
                $status = proc_get_status($process);
                if (!$status['running']) { $exit = $status['exitcode']; break; }
                usleep(10000);
            }
            rewind($files[1]); rewind($files[2]);
            $stdout=stream_get_contents($files[1],1024*1024); $stderr=stream_get_contents($files[2],1024*1024);
            if ($exit !== 0) { throw new RuntimeException('Rank Math analysis failed: '.substr(sanitize_text_field($stderr),0,500)); }
            $result = json_decode($stdout, true);
            if (!is_array($result) || !isset($result['score']) || !is_numeric($result['score']) || !is_finite((float)$result['score']) || $result['score']<0 || $result['score']>100 || ($result['engine']??'')!==self::ENGINE || ($result['fingerprint']??'')!==$payload['fingerprint']) { throw new RuntimeException('Analyzer returned an invalid or mismatched result.'); }
            return $result;
        } catch (Throwable $e) { if (is_resource($process)) @proc_terminate($process); return new WP_Error('seo_analysis', $e->getMessage()); }
        finally { if (is_resource($process)) proc_close($process); foreach ($files as $file) { if (is_resource($file)) fclose($file); } }
    }

    public static function fresh($post_id) {
        $receipt = get_post_meta($post_id, '_gnf5_seo_receipt', true);
        $score = GNF5_Publish::score($post_id);
        if (!is_array($receipt) || $score === null || (float)($receipt['score']??-1)!==$score || ($receipt['engine']??'')!==self::ENGINE) { return false; }
        $payload = self::payload($post_id);
        return !is_wp_error($payload) && hash_equals((string)($receipt['fingerprint']??''), $payload['fingerprint']);
    }

    public static function invalidate($post_id) {
        delete_post_meta($post_id, '_gnf5_seo_receipt');
        self::$writing_score = true;
        delete_post_meta($post_id, 'rank_math_seo_score');
        self::$writing_score = false;
    }

    public static function score_written_by_worker() { return self::$writing_score; }

    public static function schedule($post_id, $delay = 300) {
        if (get_post_status($post_id)!=='draft' || (int)get_post_meta($post_id,'_gnf5_seo_attempts',true)>=self::MAX_ATTEMPTS) return;
        $args = array((int)$post_id);
        if (!wp_next_scheduled(self::HOOK, $args)) {
            if(wp_schedule_single_event(time()+$delay, self::HOOK, $args))update_post_meta($post_id,'_gnf5_seo_next',time()+$delay);
        }
    }

    public static function reset_retry($post_id) {
        if(get_post_status($post_id)!=='draft')return;
        update_post_meta($post_id,'_gnf5_seo_attempts',0);
        delete_post_meta($post_id,'_gnf5_seo_next');
        wp_clear_scheduled_hook(self::HOOK,array((int)$post_id));
    }

    public static function run($post_id) {
        $post_id = absint($post_id);
        if (!$post_id || isset(self::$running[$post_id]) || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id) || get_post_status($post_id)!=='draft' || get_post_meta($post_id,'_gnf5_generated_by',true)!=='fresh-v5' || get_post_meta($post_id,'_gnf5_auto_published',true)) return false;
        if (!in_array(get_post_meta($post_id,'_gnf5_state',true), array('awaiting_rankmath','complete','validation_failed'), true)) return false;
        $cats=wp_get_post_categories($post_id); $cat_id=(int)($cats[0]??0);
        $owned = GNF5_Utils::owns_lock($cat_id);
        if (!$owned && !GNF5_Utils::acquire_lock($cat_id)) { self::schedule($post_id,90); return false; }
        self::$running[$post_id] = true;
        try {
          if(get_post_meta($post_id,'_gnf5_state',true)==='validation_failed')update_post_meta($post_id,'_gnf5_publish_is_recovery',1);
          GNF5_Utils::set_state($post_id,'awaiting_rankmath');
          GNF5_Utils::clear_recovery_state($post_id);
          for($round=0;$round<2;$round++) {
            // Draft slugs are not made unique by WordPress until publication.
            // Commit its proposed final slug before hashing and analyzing it.
            if(!function_exists('get_sample_permalink'))require_once ABSPATH.'wp-admin/includes/post.php';
            $sample=get_sample_permalink($post_id);
            if(!empty($sample[1]) && $sample[1]!==get_post_field('post_name',$post_id)){
                $repair_owned=get_post_meta($post_id,'_gnf5_seo_repair_source',true)===GNF5_Publish::fingerprint($post_id);
                $saved=wp_update_post(array('ID'=>$post_id,'post_name'=>$sample[1]),true);
                if(is_wp_error($saved))throw new RuntimeException($saved->get_error_message());
                if($repair_owned)update_post_meta($post_id,'_gnf5_seo_repair_source',GNF5_Publish::fingerprint($post_id));
            }
            if (!self::fresh($post_id)) {
            $source = GNF5_Publish::fingerprint($post_id);
            if (get_post_meta($post_id,'_gnf5_seo_attempt_source',true)!==$source) {
                update_post_meta($post_id,'_gnf5_seo_attempt_source',$source);
                self::reset_retry($post_id);
            }
            if((int)get_post_meta($post_id,'_gnf5_seo_next',true)>time())return false;
            $attempt=(int)get_post_meta($post_id,'_gnf5_seo_attempts',true);
            if ($attempt>=self::MAX_ATTEMPTS) return false;
            self::invalidate($post_id);
            update_post_meta($post_id,'_gnf5_seo_attempts',++$attempt);
            update_post_meta($post_id,'_gnf5_seo_status','SEO SCORE PENDING');
            $payload=self::payload($post_id);
            $result=is_wp_error($payload)?$payload:self::execute($payload);
            if (is_wp_error($result)) { throw new RuntimeException($result->get_error_message()); }
            $latest=self::payload($post_id);
            if (is_wp_error($latest) || !hash_equals($payload['fingerprint'],$latest['fingerprint'])) { throw new RuntimeException('Article or analyzer inputs changed during analysis; stale result discarded.'); }
            if (get_post_status($post_id)!=='draft') { throw new RuntimeException('Article status changed during analysis; result discarded.'); }
            self::$writing_score=true;
            update_post_meta($post_id,'rank_math_seo_score',$result['score']);
            self::$writing_score=false;
            if (GNF5_Publish::score($post_id)!==(float)$result['score']) { throw new RuntimeException('Rank Math score could not be read back from WordPress metadata.'); }
            update_post_meta($post_id,'_gnf5_seo_receipt',array('score'=>$result['score'],'fingerprint'=>$payload['fingerprint'],'engine'=>self::ENGINE,'version'=>self::VERSION,'time'=>time()));
            update_post_meta($post_id,'_gnf5_seo_tests',$result['tests']);
            update_post_meta($post_id,'_gnf5_seo_status',$result['score']>=80?'PASS':'FAIL');
            delete_post_meta($post_id,'_gnf5_seo_error');
            delete_post_meta($post_id,'_gnf5_seo_next');
            wp_clear_scheduled_hook(self::HOOK,array($post_id));
            }
            $score=GNF5_Publish::score($post_id);
            if($score!==null && $score<80 && $round===0 && GNF5_Runner::repair_scored_post($post_id))continue;
            $published=GNF5_Publish::maybe_publish($post_id);
            $recovered=(bool)get_post_meta($post_id,'_gnf5_publish_is_recovery',true);
            if(!$published && $score!==null && $score>=80 && self::fresh($post_id)
                && GNF5_Utils::desired_success_status($recovered)==='pending') {
                wp_update_post(array('ID'=>$post_id,'post_status'=>'pending'));
            }
            self::log($post_id,$published?'AUTO PUBLISHED':strtoupper(get_post_status($post_id)),$score!==null && $score>=80?get_post_meta($post_id,'_gnf5_publish_wait_reason',true):'Score below 80.');
            return $published;
          }
        } catch (Throwable $e) {
            self::$writing_score=false;
            self::invalidate($post_id);
            update_post_meta($post_id,'_gnf5_seo_status','SEO SCORE PENDING');
            update_post_meta($post_id,'_gnf5_seo_error',sanitize_text_field($e->getMessage()));
            update_post_meta($post_id,'_gnf5_publish_wait_reason',$e->getMessage());
            self::schedule($post_id,(int)get_post_meta($post_id,'_gnf5_seo_attempts',true)<2?300:1800);
            self::log($post_id,'DRAFT',$e->getMessage());
            return false;
        } finally { unset(self::$running[$post_id]); if (!$owned) GNF5_Utils::release_lock($cat_id); }
    }

    public static function log($post_id,$decision,$reason='') {
        $cats=wp_get_post_categories($post_id); $cat_id=(int)($cats[0]??0); $category=get_category($cat_id);
        $score=GNF5_Publish::score($post_id);
        GNF5_Utils::log(($category&&!is_wp_error($category)?$category->name:'Uncategorized').' | Post '.$post_id.' | '.get_the_title($post_id).' | Focus keyword: '.get_post_meta($post_id,'rank_math_focus_keyword',true).' | Rank Math Score: '.($score===null?'N/A':$score.'/100').' | SEO Status: '.get_post_meta($post_id,'_gnf5_seo_status',true).' | Decision: '.$decision.' | Attempts: '.(int)get_post_meta($post_id,'_gnf5_seo_attempts',true).' | Reason: '.$reason,$decision==='AUTO PUBLISHED'?'success':'info',$cat_id);
    }
}
