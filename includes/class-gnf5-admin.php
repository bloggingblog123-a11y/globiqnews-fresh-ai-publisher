<?php
if (!defined('ABSPATH')) { exit; }

class GNF5_Admin {
    public static function menu() {
        add_menu_page('GlobiqNews Fresh AI Publisher','GlobiqNews Fresh AI','manage_options','globiqnews-fresh-ai-publisher',array(__CLASS__,'page'),'dashicons-rss',25);
    }

    public static function register_settings() {
        register_setting('gnf5_group',GNF5_OPTION,array('sanitize_callback'=>array('GNF5_Utils','sanitize_settings')));
        // options.php persists the full settings form after admin_init. Hold the same lock
        // as category AJAX saves through that write and release it at request shutdown.
        if(($_SERVER['REQUEST_METHOD']??'')==='POST' && ($_POST['option_page']??'')==='gnf5_group' && ($_POST['action']??'')==='update'){
            if(!current_user_can('manage_options'))wp_die('Permission denied.','Permission denied',array('response'=>403));
            check_admin_referer('gnf5_group-options');
            if(!GNF5_Utils::acquire_settings_lock())wp_die('Another settings save is in progress. Nothing from this form was saved. Go back and retry after the other save finishes.','Settings busy',array('response'=>409,'back_link'=>true));
        }
    }

    public static function settings_updated($old,$new) {
        foreach(get_categories(array('hide_empty'=>false)) as $cat){
            $id=(int)$cat->term_id;$before=GNF5_Utils::category_settings($id,is_array($old)?$old:array());$after=GNF5_Utils::category_settings($id,is_array($new)?$new:array());
            if($before['enabled']===$after['enabled'] && $before['interval']===$after['interval'])continue;
            wp_clear_scheduled_hook(GNF5_CRON_HOOK,array($id));
            if(!empty($after['enabled']))wp_schedule_event(time()+90,$after['interval'],GNF5_CRON_HOOK,array($id));
            else {wp_clear_scheduled_hook(GNF5_CRON_CONTINUE_HOOK,array($id));GNF5_Utils::end_cron_chain($id);}
        }
        GNF5_Utils::ensure_recovery_schedule();
        GNF5_Utils::log('Settings saved. Existing category batches and unchanged schedules were preserved.','info');
    }

    public static function enqueue($hook) {
        if($hook!=='toplevel_page_globiqnews-fresh-ai-publisher')return;
        wp_enqueue_style('gnf5-admin',GNF5_URL.'assets/admin.css',array(),GNF5_VERSION);
        wp_enqueue_script('gnf5-admin',GNF5_URL.'assets/admin.js',array('jquery'),GNF5_VERSION,true);
        $s=GNF5_Utils::settings();$enabled=array();$limits=array();$cat_names=array();
        foreach(get_categories(array('hide_empty'=>false)) as $cat){
            $cs=GNF5_Utils::category_settings($cat->term_id,$s);$cid=absint($cat->term_id);
            $limits[$cid]=absint($cs['post_limit']);$cat_names[$cid]=$cat->name;if(!empty($cs['enabled']))$enabled[]=$cid;
        }
        wp_localize_script('gnf5-admin','GNF5Data',array(
            'ajaxurl'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('gnf5_ajax'),
            'enabledCats'=>$enabled,'catLimits'=>$limits,'catNames'=>$cat_names,
            'bulkRecovery'=>GNF5_Runner::bulk_recovery_status(),
            'strings'=>array(
                'running'=>'Running…','done'=>'Done','error'=>'Error',
                'confirmClear'=>'Clear the fresh plugin log?',
                'confirmSkip'=>'Skip automatic recovery for this draft? The draft itself will not be deleted.'
            )
        ));
    }

    public static function page() {
        if(!current_user_can('manage_options'))return;
        if(isset($_GET['report'])) { $post=get_post(absint($_GET['report'])); if($post && get_post_meta($post->ID,'_gnf5_generated_by',true)==='fresh-v5'){ echo '<div class="wrap"><h1>Article research and quality report</h1>';self::report_box($post);echo '</div>';return;} }
        $s=GNF5_Utils::settings();$cats=get_categories(array('hide_empty'=>false));
        $all_users=get_users(array('orderby'=>'display_name','order'=>'ASC'));
        $users=array();
        foreach($all_users as $u){if(user_can($u,'edit_posts'))$users[]=$u;}
        $logs=get_option(GNF5_LOG_OPTION,array());if(!is_array($logs))$logs=array();
        $failed=GNF5_Utils::failed_posts(100);$bulk_status=GNF5_Runner::bulk_recovery_status();$bulk_ids=array_map('absint',(array)($bulk_status['post_ids']??array()));
        ?>
        <div class="wrap gnf5-wrap">
            <?php settings_errors(); ?>
            <h1>GlobiqNews Fresh AI Publisher <span class="gnf5-badge">V<?php echo esc_html(GNF5_VERSION); ?> Research → Original Draft</span></h1>
            <div class="notice notice-success inline"><p><strong>Upgrade-safe:</strong> V<?php echo esc_html(GNF5_VERSION); ?> keeps your V5 settings, API keys, category sources, custom instructions, recovery data and schedules. RSS, Source URLs and Trusted External Links are checked independently.</p></div>
            <p class="gnf5-flow">GDELT / RSS / Source URL / Manual URL → Research → Fact sheet → Independent article → Quality checks → Optional images → Rank Math → Draft → Human review</p>
            <div class="gnf5-diagnostics">
                <span>PHP <?php echo esc_html(PHP_VERSION); ?></span>
                <span>DOM <?php echo class_exists('DOMDocument')?'OK':'MISSING'; ?></span>
                <span>GD <?php echo function_exists('imagecreatetruecolor')?'OK':'MISSING'; ?></span>
                <span>Rank Math <?php echo defined('RANK_MATH_VERSION')?'Detected':'Not detected'; ?></span>
                <?php if($failed): ?><span>Recovery Queue <?php echo count($failed); ?> draft(s)</span><?php endif; ?>
            </div>
            <div id="gnf5-status" class="gnf5-status" aria-live="polite" hidden></div>
            <div class="gnf5-rule"><strong>GitHub plugin updates:</strong> New published releases from
                <a href="https://github.com/bloggingblog123-a11y/globiqnews-fresh-ai-publisher/releases" target="_blank" rel="noopener noreferrer">your GitHub repository</a>
                appear on the <a href="<?php echo esc_url(admin_url('plugins.php')); ?>">Installed Plugins page</a>.
                Use <strong>Check for updates</strong>, then <strong>Update now</strong>, or enable WordPress <strong>auto-updates</strong> for this plugin.
                This updates the plugin code; your saved API keys and settings stay in WordPress.</div>

            <form method="post" action="options.php">
                <?php settings_fields('gnf5_group'); ?>

                <section class="gnf5-card">
                    <h2>1. Gemini Article Writer</h2>
                    <div class="gnf5-grid3">
                        <label>Gemini API Key<input type="password" name="<?php echo esc_attr(GNF5_OPTION); ?>[gemini_api_key]" value="" placeholder="Leave blank to keep the saved key" autocomplete="new-password" autocomplete="off"></label>
                        <label>Gemini Text Model<input type="text" name="<?php echo esc_attr(GNF5_OPTION); ?>[gemini_model]" value="<?php echo esc_attr($s['gemini_model']); ?>"></label>
                        <label>Optional Backup Gemini Model<input type="text" name="<?php echo esc_attr(GNF5_OPTION); ?>[gemini_backup_model]" value="<?php echo esc_attr($s['gemini_backup_model']); ?>"><small>Used only after the primary model fails its automatic retries.</small></label>
                    </div>
                    <p><button type="submit" class="button button-primary">Save Settings</button> <button type="button" class="button gnf5-test-gemini">Test Gemini Connection</button></p>
                    <p class="description">Saves global and general category settings. Use the separate category buttons for Images and Manual External Links. Save changes before testing the connection.</p>
                    <div class="gnf5-rule"><strong>Fault tolerance:</strong> Each Gemini stage makes at most 3 requests total, including an optional backup model.</div>
                    <div class="gnf5-rule"><strong>Preferred article length:</strong> 1000–1200 words when supported by research. Shorter useful articles are allowed; accuracy comes first.</div>
                </section>

                <section class="gnf5-card">
                    <h2>2. Draft-only workflow and automatic recovery</h2>
                    <div class="gnf5-rule"><strong>Every generated article remains Draft, even at Rank Math 100.</strong> Review and publish manually from the WordPress editor. Previous auto-publish preferences are archived and no longer used.</div>
                    <div class="gnf5-grid2">
                        <label><input type="hidden" name="<?php echo esc_attr(GNF5_OPTION); ?>[auto_recovery_enabled]" value="0"><input type="checkbox" name="<?php echo esc_attr(GNF5_OPTION); ?>[auto_recovery_enabled]" value="1" <?php checked($s['auto_recovery_enabled']); ?>> Recover incomplete drafts automatically</label>
                        <label>Maximum recovery attempts<input type="number" min="1" max="5" name="<?php echo esc_attr(GNF5_OPTION); ?>[auto_recovery_max_attempts]" value="<?php echo esc_attr($s['auto_recovery_max_attempts']); ?>"></label>
                        <label>Added Value Score target<input type="number" min="0" max="100" name="<?php echo esc_attr(GNF5_OPTION); ?>[added_value_target]" value="<?php echo esc_attr($s['added_value_target']); ?>"></label>
                        <label>Originality regeneration attempts<input type="number" min="0" max="2" name="<?php echo esc_attr(GNF5_OPTION); ?>[originality_retries]" value="<?php echo esc_attr($s['originality_retries']); ?>"></label>
                        <label>Topic history retention (days)<input type="number" min="7" max="365" name="<?php echo esc_attr(GNF5_OPTION); ?>[history_days]" value="<?php echo esc_attr($s['history_days']); ?>"></label>
                        <label><input type="hidden" name="<?php echo esc_attr(GNF5_OPTION); ?>[debug_enabled]" value="0"><input type="checkbox" name="<?php echo esc_attr(GNF5_OPTION); ?>[debug_enabled]" value="1" <?php checked($s['debug_enabled']); ?>> Enable diagnostic logging (secrets remain redacted)</label>
                    </div>
                    <p><strong>Preferred Rank Math target: 80 / 100 — optimization only.</strong></p>
                    <div class="gnf5-grid2">
                        <label>Where to calculate SEO scores<select name="<?php echo esc_attr(GNF5_OPTION); ?>[seo_analyzer_mode]"><option value="local" <?php selected($s['seo_analyzer_mode'],'local'); ?>>On this server (requires Node.js)</option><option value="remote" <?php selected($s['seo_analyzer_mode'],'remote'); ?>>My HTTPS scoring service (for shared hosting)</option></select></label>
                        <label>Scoring service address<input type="url" name="<?php echo esc_attr(GNF5_OPTION); ?>[seo_service_url]" value="<?php echo esc_attr($s['seo_service_url']); ?>" placeholder="https://your-scorer.onrender.com"></label>
                        <label>Scoring service secret<input type="password" name="<?php echo esc_attr(GNF5_OPTION); ?>[seo_service_key]" value="" autocomplete="new-password" placeholder="<?php echo !empty($s['seo_service_key'])?'Saved — leave blank to keep':'Paste the secret from your service'; ?>"><small>Use the same GNF5_SCORER_SECRET set on your scoring service. Saved secrets are not shown here.</small></label>
                        <label class="gnf5-inline"><input type="checkbox" name="<?php echo esc_attr(GNF5_OPTION); ?>[seo_service_clear_key]" value="1"> Remove saved scoring service secret</label>
                    </div>
                    <p><label><input type="hidden" name="<?php echo esc_attr(GNF5_OPTION); ?>[seo_service_consent]" value="0"><input type="checkbox" name="<?php echo esc_attr(GNF5_OPTION); ?>[seo_service_consent]" value="1" <?php checked(!empty($s['seo_service_consent'])); ?>> Allow sending my final article text, SEO metadata, links, image URLs and alt text to this service for scoring.</label></p>
                    <p class="description">The service runs the verified Rank Math analyzer. Articles always remain Draft for manual publishing. Writer API keys and WordPress passwords are not sent. A sleeping or unavailable service leaves articles as Draft and retries scoring.</p>
                    <p><button type="submit" class="button button-primary">Save Settings</button></p>
                    <p><button type="button" class="button button-primary gnf5-check-publish-scores">Recheck Draft SEO Scores</button></p>
                    <p class="description">Checks up to 5 completed drafts. Up to 3 natural SEO optimization attempts may improve unchanged generated articles. Human edits and manual images are protected. No score triggers publishing.</p>
                    <div class="gnf5-rule"><strong>Background scoring:</strong> Runs Rank Math Free 1.0.278's verified analyzer after article text, metadata, links and images are saved. No editor needs to stay open. Shared hosting can use your HTTPS scoring service without Node.js or proc_open on WordPress. Local mode requires both. Failed analysis retries after about 5 minutes, then 30 minutes, with a maximum of 3 attempts. WordPress scheduled tasks depend on site traffic or your host's cron service. Use the button above to retry after fixing a connection problem.</div>
                    <div class="gnf5-rule"><strong>Analyzer compatibility:</strong> <?php $seo_error=GNF5_RankMath::compatibility_error(); echo esc_html($seo_error ?: 'Required files and runtime found. Run analysis to verify execution.'); ?></div>
                    <div class="gnf5-rule"><strong>V5.9 recovery retained:</strong> failed image/post-processing drafts automatically retry at approximately 15 minutes, then 1 hour, then 6 hours. Successful images and article text are checkpointed and reused. Only exhausted failures appear in Failed Draft Recovery.</div>
                </section>

                <section class="gnf5-card">
                    <h2>3. Rank Math SEO — Writing Targets</h2>
                    <div class="gnf5-checks">
                        <label><input type="hidden" name="<?php echo esc_attr(GNF5_OPTION); ?>[rankmath_enabled]" value="0"><input type="checkbox" name="<?php echo esc_attr(GNF5_OPTION); ?>[rankmath_enabled]" value="1" <?php checked($s['rankmath_enabled']); ?>> Save and synchronize Rank Math title, meta description and focus keyword</label>
                        <label><input type="hidden" name="<?php echo esc_attr(GNF5_OPTION); ?>[toc_enabled]" value="0"><input type="checkbox" name="<?php echo esc_attr(GNF5_OPTION); ?>[toc_enabled]" value="1" <?php checked($s['toc_enabled']); ?>> Add real Rank Math Table of Contents block</label>
                        <label><input type="hidden" name="<?php echo esc_attr(GNF5_OPTION); ?>[internal_links]" value="0"><input type="checkbox" name="<?php echo esc_attr(GNF5_OPTION); ?>[internal_links]" value="1" <?php checked($s['internal_links']); ?>> Add real same-category internal links when available</label>
                    </div>
                    <p><label><input type="hidden" name="<?php echo esc_attr(GNF5_OPTION); ?>[auto_source_links]" value="0"><input type="checkbox" name="<?php echo esc_attr(GNF5_OPTION); ?>[auto_source_links]" value="1" <?php checked($s['auto_source_links']); ?>> Automatically Insert Research/Source Links in Article</label></p>
                    <p class="description">OFF by default. Research URLs and evidence remain in private reports. Category manual external links and internal links are controlled separately. Applies when composing new or regenerated drafts; existing articles are not edited by saving settings.</p>
                    <div class="gnf5-rule">Use accurate titles, natural keywords, relevant headings and useful links. There is no mandatory power word, sentiment, year, FAQ, table or keyword-density target. Rank Math remains responsible for canonical URLs, schema and sitemaps. Missing scores are shown as not checked.</div>
                </section>

                <section class="gnf5-card">
                    <h2>4. Optional original images or your own images</h2>
                    <p><strong>Maximum 1 featured image + 1 separate inline image.</strong> Source/RSS images are never downloaded, copied, traced, transformed or sent to the image generator.</p>
                    <div class="gnf5-checks"><label><input type="hidden" name="<?php echo esc_attr(GNF5_OPTION); ?>[image_enabled]" value="0"><input type="checkbox" name="<?php echo esc_attr(GNF5_OPTION); ?>[image_enabled]" value="1" <?php checked($s['image_enabled']); ?>> Generate original images (OFF by default on new installs)</label></div>
                    <div class="gnf5-grid3">
                        <label>Image Provider<select name="<?php echo esc_attr(GNF5_OPTION); ?>[image_provider]"><option value="openai" <?php selected($s['image_provider'],'openai'); ?>>OpenAI Image API</option><option value="webui" <?php selected($s['image_provider'],'webui'); ?>>Self-hosted SD / FLUX WebUI</option><option value="builtin" <?php selected($s['image_provider'],'builtin'); ?>>Built-in original graphics</option></select></label>
                        <label>OpenAI API Key<input type="password" name="<?php echo esc_attr(GNF5_OPTION); ?>[openai_api_key]" value="" placeholder="Leave blank to keep the saved key" autocomplete="new-password" autocomplete="off"></label>
                        <label>OpenAI Image Model<input type="text" name="<?php echo esc_attr(GNF5_OPTION); ?>[openai_model]" value="<?php echo esc_attr($s['openai_model']); ?>"></label>
                        <label>Image Quality<select name="<?php echo esc_attr(GNF5_OPTION); ?>[openai_quality]"><?php foreach(array('low','medium','high','xhigh','max','auto') as $q): ?><option value="<?php echo esc_attr($q); ?>" <?php selected($s['openai_quality'],$q); ?>><?php echo esc_html(ucfirst($q)); ?></option><?php endforeach; ?></select></label>
                        <label>Image Size<select name="<?php echo esc_attr(GNF5_OPTION); ?>[openai_size]"><option value="1536x1024" <?php selected($s['openai_size'],'1536x1024'); ?>>1536×1024 landscape</option><option value="1024x1024" <?php selected($s['openai_size'],'1024x1024'); ?>>1024×1024 square</option><option value="1024x1536" <?php selected($s['openai_size'],'1024x1536'); ?>>1024×1536 portrait</option></select></label>
                        <label>Low-Storage WebP Quality<input type="number" min="50" max="90" name="<?php echo esc_attr(GNF5_OPTION); ?>[webp_quality]" value="<?php echo esc_attr($s['webp_quality']); ?>"></label>
                    </div>
                    <p class="description">New generated images are optimized locally to 1200×675. Each category chooses WebP (default) or JPEG. If Image 1 succeeds but Image 2 fails, Image 1 is checkpointed and Retry generates only the missing image.</p>
                    <details><summary>Self-hosted SD / FLUX settings</summary><div class="gnf5-grid3 gnf5-details"><label>WebUI Base URL<input name="<?php echo esc_attr(GNF5_OPTION); ?>[webui_endpoint]" value="<?php echo esc_attr($s['webui_endpoint']); ?>"></label><label>Optional Bearer Token<input type="password" name="<?php echo esc_attr(GNF5_OPTION); ?>[webui_api_key]" value="" placeholder="Leave blank to keep the saved key" autocomplete="new-password"></label><label>Optional Checkpoint<input name="<?php echo esc_attr(GNF5_OPTION); ?>[webui_model]" value="<?php echo esc_attr($s['webui_model']); ?>"></label></div></details>
                    <p><label><input type="hidden" name="<?php echo esc_attr(GNF5_OPTION); ?>[builtin_fallback]" value="0"><input type="checkbox" name="<?php echo esc_attr(GNF5_OPTION); ?>[builtin_fallback]" value="1" <?php checked($s['builtin_fallback']); ?>> Use built-in original graphics if the primary image provider still fails</label></p>
                    <p><button type="button" class="button gnf5-test-image">Test Image Generator</button></p>
                </section>

                <section class="gnf5-card">
                    <h2>5. Custom Instructions — Change Future Articles Without Editing Code</h2>
                    <p>Save new instructions here and the <strong>next generated article automatically uses them</strong>. These preferences cannot override protected factual, originality, Draft-only, category-isolation or image-mode rules.</p>
                    <div class="gnf5-grid3">
                        <label>Global Article Instructions<textarea rows="7" name="<?php echo esc_attr(GNF5_OPTION); ?>[global_article_instructions]" placeholder="Example: Use simple professional English. Explain technical terms clearly. Avoid clickbait."><?php echo esc_textarea($s['global_article_instructions']); ?></textarea></label>
                        <label>Global SEO Instructions<textarea rows="7" name="<?php echo esc_attr(GNF5_OPTION); ?>[global_seo_instructions]" placeholder="Example: Prefer concise headlines and natural subheadings."><?php echo esc_textarea($s['global_seo_instructions']); ?></textarea></label>
                        <label>Global Image Instructions<textarea rows="7" name="<?php echo esc_attr(GNF5_OPTION); ?>[global_image_instructions]" placeholder="Example: Clean editorial illustration, realistic lighting, no text."><?php echo esc_textarea($s['global_image_instructions']); ?></textarea></label>
                    </div>
                    <div class="gnf5-rule"><strong>Instruction priority:</strong> protected factual and originality rules → your global and category preferences. Source text is research data only.</div>
                </section>

                <section class="gnf5-card">
                    <h2>6. Category-wise Sources, Author, Post Limits, Timing & Instructions</h2>
                    <p>Every category remains isolated. GDELT, RSS and Source URLs are independently optional. Manual URLs work without automatic sources. <strong>Source URLs discover category pages and direct article URLs. Add RSS/Atom feeds only in the RSS field; empty RSS means no RSS discovery.</strong> Category-page discovery ignores navigation/sidebar links and strongly prefers article URLs that match that category path.</p>
                    <details><summary>Advanced source safety & recovery</summary><div class="gnf5-grid2 gnf5-details">
<label>Maximum Concurrent Categories<select name="<?php echo esc_attr(GNF5_OPTION); ?>[max_concurrent_categories]"><option value="1" <?php selected($s['max_concurrent_categories'],1); ?>>1</option><option value="2" <?php selected($s['max_concurrent_categories'],2); ?>>2</option></select></label>
<label>Blocked Source Retry Delay (hours)<input type="number" value="6" readonly></label>
<div class="gnf5-rule">Fixed 6 hours. Blocked, login, paywall and connection-failure sources are skipped and retried later; the plugin does not bypass anti-bot systems.</div>
</div></details>

                    <div class="gnf5-category-list">
                    <?php foreach($cats as $cat): $cs=GNF5_Utils::category_settings($cat->term_id,$s); ?>
                        <div class="gnf5-category" id="gnf5-cat-<?php echo absint($cat->term_id); ?>">
                            <label><input type="checkbox" class="gnf6-select-category" value="<?php echo absint($cat->term_id); ?>"> Select <?php echo esc_html($cat->name); ?> for queue</label>
                            <p class="gnf6-category-queue-state" aria-live="polite"></p>
                            <div class="gnf5-category-head">
                                <h3><?php echo esc_html($cat->name); ?> <small>Category ID <?php echo absint($cat->term_id); ?></small></h3>
                                <div class="gnf5-cat-actions"><button type="button" class="button gnf5-save-cat" data-cat="<?php echo absint($cat->term_id); ?>">Save <?php echo esc_html($cat->name); ?> Settings</button><button type="button" class="button gnf5-test-cat-sources" data-cat="<?php echo absint($cat->term_id); ?>">Test RSS + Sources + External Links</button><button type="button" class="button button-primary gnf5-run-cat" data-cat="<?php echo absint($cat->term_id); ?>">Run <?php echo esc_html($cat->name); ?> Now</button></div>
                            </div>
                            <div class="gnf5-grid3">
                                <label class="gnf5-inline"><input type="hidden" name="<?php echo esc_attr(GNF5_OPTION); ?>[categories][<?php echo absint($cat->term_id); ?>][enabled]" value="0"><input type="checkbox" name="<?php echo esc_attr(GNF5_OPTION); ?>[categories][<?php echo absint($cat->term_id); ?>][enabled]" value="1" <?php checked($cs['enabled']); ?>> Enable automatic importing</label>
                                <label>Successful New Drafts Per Run<input type="number" min="1" max="10" name="<?php echo esc_attr(GNF5_OPTION); ?>[categories][<?php echo absint($cat->term_id); ?>][post_limit]" value="<?php echo esc_attr($cs['post_limit']); ?>"></label>
                                <label>Automatic Timing<select name="<?php echo esc_attr(GNF5_OPTION); ?>[categories][<?php echo absint($cat->term_id); ?>][interval]"><?php self::interval_options($cs['interval']); ?></select></label>
                                <label>Author for this Category<select name="<?php echo esc_attr(GNF5_OPTION); ?>[categories][<?php echo absint($cat->term_id); ?>][author_id]"><option value="0">Select an author (required)</option><?php foreach($users as $u): ?><option value="<?php echo absint($u->ID); ?>" <?php selected($cs['author_id'],$u->ID); ?>><?php echo esc_html($u->display_name); ?> (<?php echo esc_html($u->user_login); ?>)</option><?php endforeach; ?></select></label>
                            </div>
                            <?php self::category_fields($cat,$cs); self::category_sections($cat,$cs); ?>
                            <div class="gnf5-grid3">
                                <label><?php echo esc_html($cat->name); ?> — RSS / Atom Feeds<small>Optional · one feed URL per line · WordPress parser + raw XML fallback</small><textarea rows="5" name="<?php echo esc_attr(GNF5_OPTION); ?>[categories][<?php echo absint($cat->term_id); ?>][rss]"><?php echo esc_textarea($cs['rss']); ?></textarea></label>
                                <label><?php echo esc_html($cat->name); ?> — Source URLs<small>One URL per line · category/listing page OR direct article URL; mode is detected automatically</small><textarea rows="5" name="<?php echo esc_attr(GNF5_OPTION); ?>[categories][<?php echo absint($cat->term_id); ?>][urls]"><?php echo esc_textarea($cs['urls']); ?></textarea></label>

                            </div>
                            <label><?php echo esc_html($cat->name); ?> — Category Custom Instructions<small>Combined with Global Instructions only for this category.</small><textarea rows="5" name="<?php echo esc_attr(GNF5_OPTION); ?>[categories][<?php echo absint($cat->term_id); ?>][instructions]" placeholder="Example: Use a match-report style for Sports, but keep all locked factual and SEO rules."><?php echo esc_textarea($cs['instructions']); ?></textarea></label>
                            <input type="hidden" name="<?php echo esc_attr(GNF5_OPTION); ?>[categories][<?php echo absint($cat->term_id); ?>][_row_complete]" value="1">
                            <div class="gnf5-grid2"><label>Manual article for <?php echo esc_html($cat->name); ?><input type="url" class="gnf6-category-url" placeholder="https://example.com/article"></label><div class="gnf5-button-cell"><button type="button" class="button gnf6-category-manual" data-cat="<?php echo absint($cat->term_id); ?>">Research &amp; Create Draft</button></div></div>
                            <?php if(GNF5_Utils::stale_lock($cat->term_id)): ?><p class="gnf5-lock">This category has a stale import lock. <button type="button" class="button-link gnf5-clear-lock" data-cat="<?php echo absint($cat->term_id); ?>">Clear only if genuinely stuck</button></p><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </section>

                <details class="gnf5-card"><summary>Protected writing instruction</summary><p><?php echo esc_html(GNF5_Writer::protected_instruction()); ?></p></details>
                <?php submit_button('Save Global & General Category Settings'); ?>
            </form>

            <section class="gnf5-card">
                <h2>7. Background Category Queue</h2>
                <p>Categories start as worker slots become available, even after this page closes. Status refreshes automatically. Cron provides recovery if a background request is interrupted.</p>
                <button type="button" class="button button-primary gnf5-run-all">Run All Enabled Categories Now</button>
                <button type="button" class="button gnf6-run-selected">Run Selected Categories</button>
                <div id="gnf6-queue-status" aria-live="polite"></div>
                <hr>
                <div class="gnf5-grid3">
                    <label>Manual Article URL<input type="url" id="gnf5-manual-url" placeholder="https://example.com/article"></label>
                    <label>WordPress Category<select id="gnf5-manual-cat"><?php foreach($cats as $cat): ?><option value="<?php echo absint($cat->term_id); ?>"><?php echo esc_html($cat->name); ?></option><?php endforeach; ?></select></label>
                    <div class="gnf5-button-cell"><button type="button" class="button button-primary gnf5-run-manual">Research &amp; Create Draft</button><button type="button" class="button gnf5-test-source">Test Source Extraction Only</button></div>
                </div>
            </section>

            <?php $score_waiting=GNF5_Publish::waiting_posts(50); if($score_waiting): ?>
            <section class="gnf5-card">
                <h2>Draft review and SEO status</h2>
                <p>The saved Rank Math score is shown below. Every article requires human review and manual publishing; no score triggers publication.</p>
                <?php foreach($score_waiting as $p): $score=GNF5_Publish::score($p->ID); ?>
                    <p><strong>#<?php echo absint($p->ID); ?> — <?php echo esc_html(get_the_title($p)); ?></strong>
                    · Rank Math: <?php echo $score===null ? 'Not calculated' : esc_html($score.'/100'); ?>
                    · <?php echo esc_html(get_post_meta($p->ID,'_gnf5_seo_status',true) ?: 'SEO SCORE PENDING'); ?>
                    · Attempts: <?php echo absint(get_post_meta($p->ID,'_gnf5_seo_attempts',true)); ?>/3
                    <a class="button" href="<?php echo esc_url(get_edit_post_link($p->ID)); ?>">Edit Draft</a> <a class="button" href="<?php echo esc_url(admin_url("admin.php?page=globiqnews-fresh-ai-publisher&report=".$p->ID)); ?>">View Research / Quality</a><br>
                    <small><?php echo esc_html(get_post_meta($p->ID,'_gnf5_seo_error',true)); ?></small><br>
                    <small><?php $reason=GNF5_Publish::blocked_reason($p->ID); echo esc_html($reason?:((string)get_post_meta($p->ID,'_gnf5_publish_wait_reason',true)?:'Draft ready for human review.')); ?></small></p>
                <?php endforeach; ?>
            </section>
            <?php endif; ?>

            <?php if($failed): ?>
            <section class="gnf5-card" id="gnf5-recovery-section">
                <h2>8. Failed Draft Recovery <span class="gnf5-badge"><?php echo count($failed); ?> waiting</span></h2>
                <p>Select many failed drafts and click <strong>Retry Selected One-by-One</strong>. The plugin queues all selected articles but repairs <strong>only one article at a time</strong>. The next article starts only after the current article finishes, fails, or is safely deferred because its category is busy.</p>
                <div class="gnf5-bulk-recovery-toolbar">
                    <label class="gnf5-inline"><input type="checkbox" id="gnf5-select-all-recovery"> Select all visible drafts</label>
                    <button type="button" class="button button-primary gnf5-retry-selected">Retry Selected One-by-One</button>
                    <button type="button" class="button gnf5-retry-all-visible">Retry All Visible One-by-One</button>
                    <span id="gnf5-bulk-recovery-state"><?php echo !empty($bulk_status['pending']) ? esc_html(absint($bulk_status['pending']).' already queued') : 'Queue empty'; ?></span>
                </div>
                <div class="gnf5-rule"><strong>Safe queue:</strong> selecting 20 articles does not start 20 heavy requests. Only one recovery runs at a time. This page processes the queue immediately, and WP-Cron is a backup if you leave the page.</div>
                <div class="gnf5-recovery-list">
                <?php foreach($failed as $p):
                    $state=(string)get_post_meta($p->ID,'_gnf5_state',true);
                    $msg=(string)get_post_meta($p->ID,'_gnf5_state_message',true);
                    $errs=get_post_meta($p->ID,'_gnf5_validation_errors',true);
                    $errs=is_array($errs)?implode(' | ',array_slice($errs,0,3)):'';
                    $pcats=wp_get_post_categories($p->ID,array('fields'=>'names'));
                ?>
                    <div class="gnf5-recovery" id="gnf5-recovery-<?php echo absint($p->ID); ?>" data-post="<?php echo absint($p->ID); ?>">
                        <div>
                            <label class="gnf5-recovery-select-label"><input type="checkbox" class="gnf5-recovery-select" value="<?php echo absint($p->ID); ?>" <?php checked(in_array(absint($p->ID),$bulk_ids,true)); ?>> Select</label>
                            <strong>#<?php echo absint($p->ID); ?> — <?php echo esc_html(get_the_title($p)); ?></strong><br>
                            <small><?php echo esc_html(implode(', ',$pcats)); ?> · State: <?php echo esc_html($state); ?> · <span class="gnf5-row-queue-state"><?php echo in_array(absint($p->ID),$bulk_ids,true)?'Queued':'Not queued'; ?></span></small>
                        </div>
                        <div class="gnf5-recovery-message"><?php echo esc_html($errs?:$msg); ?></div>
                        <div><button type="button" class="button button-primary gnf5-retry-post" data-post="<?php echo absint($p->ID); ?>">Retry Now</button> <button type="button" class="button gnf5-skip-failed" data-post="<?php echo absint($p->ID); ?>">Skip Recovery</button> <a class="button" href="<?php echo esc_url(get_edit_post_link($p->ID)); ?>">Edit Draft</a></div>
                    </div>
                <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>

            <?php self::transparency(); ?>
            <section class="gnf5-card">
                <div class="gnf5-category-head"><h2><?php echo $failed ? '9' : '8'; ?>. Live Log</h2><button type="button" class="button gnf5-clear-log">Clear Fresh V5 Log</button></div>
                <p><label>Filter <select id="gnf5-log-filter"><option value="">All</option><?php foreach(array("success","failure","blocked","draft","originality","fact","seo_warning","image_warning","warning") as $filter): ?><option value="<?php echo esc_attr($filter); ?>"><?php echo esc_html(ucwords(str_replace("_"," ",$filter))); ?></option><?php endforeach; ?></select></label> <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url("admin-post.php?action=gnf5_export_log"),"gnf5_export_log")); ?>">Export log</a></p>
                <div id="gnf5-log" class="gnf5-log"><?php if(!$logs): ?>No Fresh V5 log entries yet.<?php else: foreach($logs as $row): $catname=$row['cat']?get_cat_name($row['cat']):''; ?><div data-log-type="<?php echo esc_attr($row['type']); ?>"><span class="gnf5-time">[<?php echo esc_html($row['time']); ?>]</span> <strong><?php echo esc_html(strtoupper($row['type'])); ?></strong><?php echo $catname?' ['.esc_html($catname).']':''; ?> — <?php echo esc_html($row['message']); ?></div><?php endforeach; endif; ?></div>
            </section>
        </div>
        <?php
    }

    private static function interval_options($selected){
        $options=array('gnf5_30m'=>'Every 30 minutes','hourly'=>'Every 1 hour','gnf5_2h'=>'Every 2 hours','gnf5_4h'=>'Every 4 hours','gnf5_6h'=>'Every 6 hours','gnf5_12h'=>'Every 12 hours','daily'=>'Every 24 hours');
        foreach($options as $v=>$label)echo '<option value="'.esc_attr($v).'" '.selected($selected,$v,false).'>'.esc_html($label).'</option>';
    }

    private static function guard(){
        if(!current_user_can('manage_options'))wp_send_json_error(array('message'=>'Permission denied.'),403);
        foreach($_POST as $key=>$value){
            if($key==='cat_ids' && is_array($value)){
                if(count($value)>100)wp_send_json_error(array('message'=>'Select no more than 100 categories.'),400);
                foreach($value as $id)if(!is_scalar($id))wp_send_json_error(array('message'=>'Invalid category selection.'),400);
            }elseif($key==='post_ids' && is_array($value)){
                if(count($value)>200)wp_send_json_error(array('message'=>'Select no more than 200 Drafts.'),400);
                foreach($value as $id)if(!is_scalar($id))wp_send_json_error(array('message'=>'Invalid Draft selection.'),400);
            }elseif(!is_scalar($value))wp_send_json_error(array('message'=>'Invalid request field: '.sanitize_key($key)),400);
        }
        check_ajax_referer('gnf5_ajax','nonce');
    }

    public static function ajax_check_publish_scores(){
        self::guard();
        $result=GNF5_Publish::check_saved_scores(5,true);
        $message='Checked '.$result['reviewed'].' draft(s). All remain Draft; '.$result['blocked'].' score(s) unavailable.';
        if($result['reasons'])$message.=' '.implode(' | ',$result['reasons']);
        elseif(!$result['reviewed'])$message.=' No completed draft awaiting analysis was found. See Draft review and SEO status for processing states.';
        $message.=' Refresh this page to update the draft status list.';
        GNF5_Utils::log($message,$result['blocked']?'warning':'info');
        wp_send_json_success(array('message'=>$message,'result'=>$result));
    }

    public static function ajax_save_category(){
        self::guard();
        $cat=absint($_POST['cat_id']??0);
        if(!GNF5_Utils::category_valid($cat))wp_send_json_error(array('message'=>'Invalid WordPress category.'));
        $row=array(
            'enabled'=>empty($_POST['enabled'])?0:1,'post_limit'=>absint($_POST['post_limit']??1),
            'interval'=>sanitize_key($_POST['interval']??'hourly'),'author_id'=>absint($_POST['author_id']??0),
            'rss'=>wp_unslash($_POST['rss']??''),'urls'=>wp_unslash($_POST['urls']??''),
            'instructions'=>wp_unslash($_POST['instructions']??''),
        );
        foreach(array('gdelt_enabled','gdelt_keywords','gdelt_language','gdelt_country','gdelt_window','gdelt_results','gdelt_interval','min_sources','max_candidates','opportunity_threshold') as $key) { if(isset($_POST[$key]) && is_scalar($_POST[$key]))$row[$key]=wp_unslash($_POST[$key]); }
        $row=array_intersect_key($row,$_POST);
        $result=GNF5_Utils::save_category_section($cat,'general',$row);
        if(is_wp_error($result))wp_send_json_error(array('message'=>$result->get_error_message()),409);
        $name=get_cat_name($cat)?:('Category '.$cat);
        GNF5_Utils::log($name.' settings saved separately.','success',$cat);
        wp_send_json_success(array('message'=>$name.' settings saved.','category'=>GNF5_Utils::category_settings($cat)));
    }

    public static function ajax_save_section() {
        self::guard();$cat=absint($_POST['cat_id']??0);$section=sanitize_key($_POST['section']??'');
        if(!GNF5_Utils::category_valid($cat) || !in_array($section,array('images','links'),true))wp_send_json_error(array('message'=>'Invalid category or settings section.'),400);
        $values=json_decode(wp_unslash($_POST['values']??''),true);
        if(!is_array($values) || array_diff(GNF5_Utils::section_keys($section),array_keys($values)))wp_send_json_error(array('message'=>'Incomplete settings. Nothing was saved.'),400);
        foreach($values as $key=>$value)if($key!=='manual_links' && !is_scalar($value))wp_send_json_error(array('message'=>'Invalid settings value.'),400);
        if($section==='links'){
            $links=GNF5_Utils::sanitize_manual_links($values['manual_links']);
            if(is_wp_error($links))wp_send_json_error(array('message'=>$links->get_error_message()),400);
            $values['manual_links']=$links;
        }elseif(!in_array($values['image_mode'],array('global','on','off'),true))wp_send_json_error(array('message'=>'Invalid image mode.'),400);
        $result=GNF5_Utils::save_category_section($cat,$section,$values,wp_unslash($_POST['revision']??''));
        if(is_wp_error($result))wp_send_json_error(array('message'=>$result->get_error_message()),409);
        $label=$section==='images'?'image':'external link';
        wp_send_json_success(array('message'=>get_cat_name($cat).' '.$label.' settings saved successfully.','revision'=>$result));
    }

    public static function manual_link_row($row=array()) {
        $row=wp_parse_args($row,array('id'=>'','url'=>'','anchor'=>'','note'=>'','enabled'=>1,'usage'=>'optional'));
        echo '<div class="gnf6-link-row"><input type="hidden" data-field="id" value="'.esc_attr($row['id']).'"><div class="gnf5-grid3">';
        foreach(array('url'=>'Public URL','anchor'=>'Anchor text (optional)','note'=>'Purpose / relevant topic') as $key=>$label)
            echo '<label>'.esc_html($label).'<input type="text" '.($key==='url'?'inputmode="url" ':'').'data-field="'.esc_attr($key).'" value="'.esc_attr($row[$key]).'"></label>';
        echo '</div><p><label><input type="checkbox" data-field="enabled" '.checked($row['enabled'],1,false).'> Enabled</label> <label>Usage <select data-field="usage"><option value="optional" '.selected($row['usage'],'optional',false).'>Optional</option><option value="preferred" '.selected($row['usage'],'preferred',false).'>Preferred</option></select></label> <button type="button" class="button gnf6-link-up">Move up</button> <button type="button" class="button gnf6-link-delete">Delete link</button></p></div>';
    }

    public static function category_sections($cat,$cs) {
        foreach(array('images'=>'Images','links'=>'Manual External Links') as $section=>$label){
            echo '<fieldset class="gnf6-section" data-section="'.esc_attr($section).'" data-cat="'.absint($cat->term_id).'" data-revision="'.esc_attr(GNF5_Utils::section_revision($cs,$section)).'"><legend><strong>'.esc_html($cat->name.' — '.$label).'</strong></legend>';
            if($section==='images'){
                echo '<div class="gnf5-grid3"><label>Image generation<select data-field="image_mode">';
                foreach(array('global'=>'Use Global','on'=>'ON','off'=>'OFF') as $value=>$text)echo '<option value="'.esc_attr($value).'" '.selected($cs['image_mode'],$value,false).'>'.esc_html($text).'</option>';
                echo '</select></label>';
                foreach(array('image_featured'=>'Generate featured image','image_inline'=>'Generate one inline image','image_webp'=>'Save new images as WebP (OFF uses optimized JPEG)') as $key=>$text)
                    echo '<label><input type="checkbox" data-field="'.esc_attr($key).'" '.checked($cs[$key],1,false).'> '.esc_html($text).'</label>';
                echo '</div><p class="description">Uses the saved global provider and quality. Existing attachments and your manually uploaded images are preserved.</p>';
            }else{
                echo '<p><label><input type="checkbox" data-field="manual_links_enabled" '.checked($cs['manual_links_enabled'],1,false).'> Enable manual external links for this category</label></p><label>Maximum links per article (0–3)<input type="number" min="0" max="3" data-field="manual_links_max" value="'.absint($cs['manual_links_max']).'"></label><p class="description">Only relevant, reachable links with suitable inline anchor text are inserted. Preferred links are considered first, never forced. An empty list or no relevant links is OK. Use a descriptive anchor or purpose such as Samsung Galaxy support to help match the article.</p><div class="gnf6-link-list">';
                foreach($cs['manual_links'] as $row)self::manual_link_row($row);
                echo '</div><template class="gnf6-link-template">';self::manual_link_row();echo '</template><p><button type="button" class="button gnf6-link-add">Add link</button></p>';
            }
            echo '<button type="button" class="button button-primary gnf6-save-section">'.($section==='images'?'Save Image Settings':'Save External Link Settings').'</button><p class="gnf6-section-result" role="status" aria-live="polite"></p></fieldset>';
        }
    }

    public static function ajax_test_category_sources(){
        self::guard();
        $cat=absint($_POST['cat_id']??0);
        if(!GNF5_Utils::category_valid($cat))wp_send_json_error(array('message'=>'Invalid WordPress category.'));
        $cs=GNF5_Utils::category_settings($cat);
        GNF5_Sources::reset_budget();$parts=array();$ok=0;$bad=0;$reports=array();
        $rss_urls=GNF5_Utils::urls_from_lines($cs['rss']);$source_urls=GNF5_Utils::urls_from_lines($cs['urls']);
        $counts=array('rss_configured'=>count($rss_urls),'rss_tested'=>0,'sources_configured'=>count($source_urls),'sources_tested'=>0,'gdelt_enabled'=>!empty($cs['gdelt_enabled']));
        $parts[]='RSS configured: '.count($rss_urls).(!$rss_urls?' — SKIPPED':'');
        $parts[]='Source URLs configured: '.count($source_urls);$parts[]='GDELT: '.($counts['gdelt_enabled']?'ENABLED':'DISABLED');
        foreach(array('rss'=>$rss_urls,'urls'=>$source_urls) as $type=>$urls)foreach($urls as $u){
            if(!GNF6_Queue::configured($cat,$type,$u))continue;
            $x=$type==='rss'?GNF5_Sources::rss_items($u,25):GNF5_Sources::discover_source($u,25);
            $counts[$type==='rss'?'rss_tested':'sources_tested']++;
            if(is_wp_error($x) || !$x){$bad++;GNF6_Queue::schedule_source_retry($cat,$type,$u);}else $ok++;
            $d=GNF5_Sources::diagnostic($type==='rss'?'RSS':'SOURCE',$u,$x);$reports[]=$d;$parts[]=GNF5_Sources::diagnostic_text($d);
        }
        $parts[]='RSS tested: '.$counts['rss_tested'].' | RSS status: '.(!$rss_urls?'SKIPPED':'TESTED');$parts[]='Source URLs tested: '.$counts['sources_tested'];
        if($counts['gdelt_enabled']){$g=GNF5_Sources::gdelt_items($cat);if(GNF5_Sources::gdelt_url()){$d=GNF5_Sources::diagnostic('GDELT',GNF5_Sources::gdelt_url(),$g);$reports[]=$d;$parts[]=GNF5_Sources::diagnostic_text($d);}if(is_wp_error($g)){$bad++;$parts[]='GDELT: '.$g->get_error_message();}else{$ok++;$parts[]='GDELT: '.count($g).' candidate(s).';}}
        $external=array();
        foreach(GNF5_Utils::urls_from_lines($cs['external_links']) as $u)$external[$u]='LEGACY RESEARCH LINK';
        if(empty($cs['manual_links_enabled']))$parts[]='MANUAL EXTERNAL LINKS OFF — configured entries were not requested.';
        else foreach((array)$cs['manual_links'] as $entry){
            if(empty($entry['enabled']))continue;
            $u=GNF5_Utils::normalize_url($entry['url']??'');
            if($u)$external[$u]='MANUAL EXTERNAL LINK';
        }
        if(!empty($cs['manual_links_enabled']) && !array_filter((array)$cs['manual_links'],function($entry){return !empty($entry['enabled']);}))$parts[]='No enabled manual external links are saved for this category.';
        foreach($external as $u=>$label){
            $x=GNF5_Sources::test_external_link($u,true);
            $d=GNF5_Sources::diagnostic($label,$u,$x);$d['candidate_count']=0;$d['parser']='NOT APPLICABLE (link reachability)';$reports[]=$d;$parts[]=GNF5_Sources::diagnostic_text($d);
            if(is_wp_error($x)){
                $bad++;$parts[]=$label.' FAIL: '.$u.' — '.$x->get_error_message();
            }else{
                $status=strtoupper((string)($x['status']??'unknown'));
                $code=absint($x['code']??0);
                $state=(string)($x['status']??'');
                if(in_array($state,array('ok','restricted'),true)){$ok++;}else{$bad++;}
                $parts[]=$label.' '.$status.': '.$u.($code?' — HTTP '.$code:'').' — '.sanitize_text_field($x['message']??'');
            }
        }
        if(!$parts)$parts[]='No RSS, Source URLs or external links are saved for this category.';
        $name=get_cat_name($cat)?:('Category '.$cat);
        wp_send_json_success(array('message'=>$name.' source test: '.$ok.' working/usable, '.$bad.' failed/broken. '.implode(' | ',$parts),'ok'=>$ok,'failed'=>$bad,'details'=>$parts,'configuration'=>$counts,'sources'=>$reports));
    }

    public static function ajax_run_category(){
        self::guard();$cat=absint($_POST['cat_id']??0);
        if(!GNF5_Utils::category_valid($cat))wp_send_json_error(array('message'=>'Invalid category.'),400);
        $r=GNF6_Queue::enqueue(array($cat));
        wp_send_json_success(array('message'=>$r['added']?'Category queued; available workers dispatched.':get_cat_name($cat).' is already running or waiting.','queue'=>$r));
    }
    public static function ajax_enqueue_categories(){
        self::guard();$ids=array_slice(array_filter(array_map('absint',(array)($_POST['cat_ids']??array()))),0,100);
        if(!$ids)wp_send_json_error(array('message'=>'Select at least one category.'),400);
        $r=GNF6_Queue::enqueue($ids);
        wp_send_json_success(array('message'=>$r['added'].' categories queued.'.($r['already']?' Already running/waiting: '.implode(', ',$r['already']).'.':''),'queue'=>$r));
    }
    public static function ajax_category_queue_status(){
        self::guard();wp_send_json_success(array('jobs'=>GNF6_Queue::status(),'active'=>count(GNF5_Utils::worker_states()),'limit'=>GNF5_Utils::worker_limit()));
    }

    public static function ajax_run_manual(){
        self::guard();$cat=absint($_POST['cat_id']??0);$url=esc_url_raw($_POST['url']??'');
        $r=GNF5_Runner::run_manual_url($url,$cat);
        if(is_wp_error($r))wp_send_json_error(array('message'=>$r->get_error_message()));
        wp_send_json_success(array('message'=>'Manual article saved as post #'.absint($r['post_id']??0).'. Status: '.sanitize_text_field($r['status']??'draft').'.','result'=>$r));
    }

    public static function ajax_test_source(){
        self::guard();$url=esc_url_raw($_POST['url']??'');$r=GNF5_Sources::extract_article($url,'');
        if(is_wp_error($r))wp_send_json_error(array('message'=>$r->get_error_message()));
        wp_send_json_success(array('message'=>'Extraction OK: '.GNF5_Utils::word_count($r['text']).' words via '.$r['method'].'. Title: '.($r['title']?:'(not detected)')));
    }

    public static function ajax_test_gemini(){
        self::guard();$r=GNF5_Writer::test();
        if(is_wp_error($r))wp_send_json_error(array('message'=>$r->get_error_message()));
        wp_send_json_success(array('message'=>'Gemini connection successful.'));
    }

    public static function ajax_test_image(){
        self::guard();$r=GNF5_Images::test();
        if(is_wp_error($r))wp_send_json_error(array('message'=>$r->get_error_message()));
        $id=absint($r);
        // A connection test should not permanently consume Media Library/storage space.
        $deleted=$id?wp_delete_attachment($id,true):false;
        $msg='Image generation successful.';
        $msg.=$deleted?' Temporary test image was removed automatically.':' Test image could not be auto-removed; Media ID #'.$id.'.';
        wp_send_json_success(array('message'=>$msg));
    }

    public static function ajax_clear_log(){
        self::guard();delete_option(GNF5_LOG_OPTION);wp_send_json_success(array('message'=>'Fresh V5 log cleared.'));
    }

    public static function ajax_clear_lock(){
        self::guard();$cat=absint($_POST['cat_id']??0);if(!GNF5_Utils::force_clear_lock($cat))wp_send_json_error(array('message'=>'Worker is healthy; its lock was not cleared.'));wp_send_json_success(array('message'=>'Category import lock cleared.'));
    }

    public static function ajax_retry_post(){
        self::guard();$post=absint($_POST['post_id']??0);$r=GNF5_Runner::retry_post($post);
        if(is_wp_error($r))wp_send_json_error(array('message'=>$r->get_error_message()));
        wp_send_json_success(array('message'=>'Post #'.$post.' retry finished. Status: '.sanitize_text_field($r['status']??'draft').'.','result'=>$r));
    }

    public static function ajax_enqueue_bulk_recovery(){
        self::guard();
        $ids=isset($_POST['post_ids'])?(array)$_POST['post_ids']:array();
        $ids=array_map('absint',$ids);
        $r=GNF5_Runner::enqueue_bulk_recovery($ids);
        if(is_wp_error($r))wp_send_json_error(array('message'=>$r->get_error_message()));
        $msg=absint($r['added']??0).' draft(s) added to the one-by-one recovery queue.';
        if(!empty($r['already_queued']))$msg.=' '.absint($r['already_queued']).' were already queued.';
        wp_send_json_success(array('message'=>$msg,'status'=>$r));
    }

    public static function ajax_bulk_recovery_step(){
        self::guard();
        $r=GNF5_Runner::bulk_recovery_step();
        if(is_wp_error($r))wp_send_json_error(array('message'=>$r->get_error_message()));
        wp_send_json_success(array('message'=>sanitize_text_field($r['message']??'Bulk recovery step finished.'),'status'=>$r));
    }

    public static function ajax_bulk_recovery_status(){
        self::guard();
        $r=GNF5_Runner::bulk_recovery_status();
        wp_send_json_success(array('message'=>absint($r['pending']??0).' draft(s) remain in the bulk recovery queue.','status'=>$r));
    }

    public static function ajax_skip_failed(){
        self::guard();$post=absint($_POST['post_id']??0);$r=GNF5_Runner::skip_failed($post);
        if(is_wp_error($r))wp_send_json_error(array('message'=>$r->get_error_message()));
        wp_send_json_success(array('message'=>'Post #'.$post.' removed from automatic recovery queue. The draft was not deleted.'));
    }
    public static function category_fields($cat,$cs) {
        $prefix=GNF5_OPTION.'[categories]['.(int)$cat->term_id.']';
        echo '<div class="gnf5-grid3"><label><input type="hidden" name="'.esc_attr($prefix.'[gdelt_enabled]').'" value="0"><input type="checkbox" name="'.esc_attr($prefix.'[gdelt_enabled]').'" value="1" '.checked($cs['gdelt_enabled'],1,false).'> Enable GDELT topic discovery</label></div><div class="gnf5-grid3">';
        $fields=array('gdelt_keywords'=>'Topic keywords (comma-separated)','gdelt_language'=>'Language (e.g. english)','gdelt_country'=>'Source country (e.g. india; blank = all)',
            'gdelt_results'=>'GDELT results per scan','gdelt_interval'=>'GDELT cache/scan interval (minutes)','min_sources'=>'Minimum independent sources for GDELT',
            'max_candidates'=>'Maximum candidates per run','opportunity_threshold'=>'Topic Opportunity Score target');
        foreach($fields as $key=>$label){$numeric=in_array($key,array('gdelt_results','gdelt_interval','min_sources','max_candidates','opportunity_threshold'),true);
            echo '<label>'.esc_html($label).'<input type="'.($numeric?'number':'text').'" name="'.esc_attr($prefix.'['.$key.']').'" value="'.esc_attr($cs[$key]).'"></label>';
        }
        echo '<label>Search window<select name="'.esc_attr($prefix.'[gdelt_window]').'">';
        foreach(array('1h','6h','12h','24h','3d','7d') as $window)echo '<option '.selected($cs['gdelt_window'],$window,false).'>'.esc_html($window).'</option>';
        echo '</select></label></div>';
        $run=get_option('gnf5_run_cat_'.$cat->term_id,array());$next=wp_next_scheduled(GNF5_CRON_HOOK,array((int)$cat->term_id));
        $state=$run['status']??'idle';if($state==='running' && ($run['updated']??0)<time()-1800)$state='interrupted — ready for retry';
        echo '<p><strong>'.esc_html($cat->name).' last recorded batch:</strong> '.esc_html($state).' · Recorded: '.esc_html(!empty($run['updated'])?wp_date('Y-m-d H:i',$run['updated']):'not run').' · Drafts: '.absint($run['created']??0).' · Next scheduled run: '.esc_html($next?wp_date('Y-m-d H:i',$next):'not scheduled').'</p>';
        echo '<p class="description">At least one automatic discovery method and an author are required for scheduled runs. Opportunity scores are internal heuristics, not traffic predictions. Failed sources do not disable the other methods.</p>';
    }

    public static function transparency() {
        echo '<section class="gnf5-card"><h2>Site transparency checklist</h2><p>Editorial reminders only. This plugin does not guarantee Google or AdSense approval.</p><ul>';
        foreach(array('about'=>'About','contact'=>'Contact','privacy-policy'=>'Privacy policy','editorial-policy'=>'Editorial policy','corrections-policy'=>'Corrections policy') as $slug=>$label){
            $page=get_page_by_path($slug);$present=$page && $page->post_status==='publish';
            echo '<li>'.esc_html($label).' — '.($present?'Published page found; review its accuracy.':'Not found at the expected slug; check manually.').'</li>';
        }
        echo '</ul><p>Verify author profiles, ownership, sourcing and corrections procedures for your site.</p></section>';
    }

    public static function meta_boxes($type,$post) {
        if($type==='post' && $post && get_post_meta($post->ID,'_gnf5_generated_by',true)==='fresh-v5' && current_user_can('edit_post',$post->ID))
            add_meta_box('gnf5-quality','GlobiqNews article quality report',array(__CLASS__,'report_box'),'post','normal','high');
    }

    public static function report_enqueue($hook) {
        if(!in_array($hook,array('post.php','toplevel_page_globiqnews-fresh-ai-publisher'),true))return;
        wp_enqueue_script('gnf6-report',GNF5_URL.'assets/report.js',array('jquery'),GNF5_VERSION,true);
        wp_localize_script('gnf6-report','GNF6Report',array('ajaxurl'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('gnf5_ajax')));
    }

    public static function report_box($post) {
        if(!current_user_can('edit_post',$post->ID))return;
        $report=get_post_meta($post->ID,'_gnf5_quality_report',true);$research=get_post_meta($post->ID,'_gnf5_research',true);
        $report=is_array($report)?$report:array();$cats=wp_get_post_categories($post->ID);$cat_id=(int)($cats[0]??0);
        $images=GNF5_Quality::images($post->ID,$cat_id);$score=GNF5_Publish::score($post->ID);
        echo '<div class="gnf6-report" data-post="'.absint($post->ID).'"><p><strong>Generated content requires human review and manual publishing.</strong></p>';
        if(!empty($report['content_hash']) && !hash_equals($report['content_hash'],hash('sha256',$post->post_content)))echo '<p><strong>Article changed after these checks. Recheck before relying on this report.</strong></p>';
        $rows=array('Status'=>get_post_status($post->ID),'Word count'=>GNF5_Utils::word_count($post->post_content),
            'Sources actually researched'=>$research['source_count']??'NOT CHECKED','Independent publisher estimate'=>$research['independent_source_estimate']??'UNKNOWN',
            'Originality'=>$report['originality']['status']??'NOT CHECKED','Factual review'=>$report['facts']['status']??'NOT CHECKED',
            'Added Value Score'=>isset($report['added_value']['score'])?$report['added_value']['score'].' / 100':'NOT CHECKED',
            'Rank Math score'=>$score===null?'NOT CHECKED':$score.' / 100','SEO optimizations'=>absint(get_post_meta($post->ID,'_gnf5_seo_repair_attempts',true)).' / 3',
            'Image generation'=>$images['generation'],'Featured image'=>$images['featured'],'Inline image'=>$images['inline'],'Image SEO'=>$images['seo']);
        $rows=array_merge($rows,GNF5_SEO::link_report($post->ID));
        echo '<table class="widefat striped"><tbody>';foreach($rows as $label=>$value)echo '<tr><th>'.esc_html($label).'</th><td>'.esc_html((string)$value).'</td></tr>';echo '</tbody></table>';
        echo '<p>Originality and quality scores are internal comparisons and AI-assisted evidence reviews. They are not plagiarism-proof, independent human fact checks or search-engine approval scores.</p>';
        foreach((array)($report['warnings']??array()) as $warning)echo '<p>'.esc_html($warning).'</p>';
        echo '<details open><summary><strong>Non-image SEO checklist</strong></summary><p>These local checks work without a scoring service. Rank Math calculates the actual score separately. REVIEW is an editorial item, not a failed article. Neutral titles and shorter articles may be appropriate when the evidence requires them.</p><table class="widefat striped"><tbody>';
        foreach(GNF5_SEO::checklist($post->ID) as $name=>$check)echo '<tr><th>'.esc_html(ucwords(str_replace('_',' ',$name))).'</th><td>'.esc_html($check['status']).'</td><td>'.esc_html($check['message']).'</td></tr>';
        echo '</tbody></table><p>'.esc_html(get_post_meta($post->ID,'_gnf5_seo_repair_note',true)).'</p></details>';
        echo '<p><a class="button" href="'.esc_url(get_preview_post_link($post->ID)).'" target="_blank" rel="noopener">Preview</a> <a class="button" href="'.esc_url(get_edit_post_link($post->ID)).'">Edit Draft</a> ';
        if(current_user_can('delete_post',$post->ID))echo '<a class="button" href="'.esc_url(get_delete_post_link($post->ID)).'">Move Draft to Trash</a> ';
        echo '</p>';
        foreach(array('Research and sources'=>$research,'Article quality evidence'=>$report,'Image file and ALT checks'=>$images,'Topic opportunity'=>get_post_meta($post->ID,'_gnf5_opportunity',true)) as $label=>$data){
            echo '<details><summary>'.esc_html($label).'</summary><pre style="max-height:360px;overflow:auto;white-space:pre-wrap">'.esc_html(wp_json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)).'</pre></details>';
        }
        if(current_user_can('manage_options') && get_post_status($post->ID)==='draft'){
            echo '<p>Save editor changes before using these actions.</p><p>';
            foreach(array('quality'=>'Recheck Originality, Value & Facts','improve_seo'=>'Improve SEO & Links','seo'=>'Recheck Rank Math','images'=>'Recheck Images / Image SEO','links'=>'Recheck Links','regenerate'=>'Regenerate Draft','generate_images'=>'Generate Missing Images','regenerate_images'=>'Regenerate Generated Images','remove_images'=>'Remove Generated Images') as $action=>$label)
                echo '<button type="button" class="button gnf6-action" data-action="'.esc_attr($action).'">'.esc_html($label).'</button> ';
            echo '</p><details><summary>Manual image ALT text — suggestions to review</summary><p>Suggested text uses attachment titles, not visual recognition. Edit it to accurately describe your own image before saving.</p>';
            foreach($images['attachments'] as $image){$id=$image['id'];$alt=get_post_meta($id,'_wp_attachment_image_alt',true);$suggestion=trim((string)get_post_field('post_title',$id));
                echo '<p><label>Attachment #'.absint($id).' <input type="text" class="gnf6-alt" data-attachment="'.absint($id).'" value="'.esc_attr($alt ?: $suggestion).'" size="55"></label> <button type="button" class="button gnf6-action" data-action="save_alt" data-attachment="'.absint($id).'">Save reviewed ALT</button></p>';
            }echo '</details>';
        }
        echo '<p class="gnf6-result" role="status" aria-live="polite"></p></div>';
    }

    public static function ajax_article_action() {
        self::guard();$id=absint($_POST['post_id']??0);$action=sanitize_key($_POST['task']??'');
        if(!current_user_can('edit_post',$id) || get_post_type($id)!=='post' || get_post_meta($id,'_gnf5_generated_by',true)!=='fresh-v5' || get_post_status($id)!=='draft')wp_send_json_error(array('message'=>'A permitted plugin Draft is required.'),403);
        if(in_array($action,array('regenerate','regenerate_images','remove_images'),true) && ($_POST['confirmed']??'')!=='yes')wp_send_json_error(array('message'=>'Confirm the specific replacement/removal action first.'),400);
        $cats=wp_get_post_categories($id);$cat_id=(int)($cats[0]??0);
        if(!GNF5_Utils::acquire_lock($cat_id))wp_send_json_error(array('message'=>'Another article is processing. Please retry shortly.'),409);
        $result=true;$message='Checks updated. Draft remains unpublished.';
        try{
            GNF5_Sources::reset_budget();
            if($action==='regenerate'){$result=GNF5_Runner::regenerate($id);$message='Draft regeneration finished. Review before manually publishing.';}
            elseif($action==='improve_seo'){$result=GNF5_Runner::improve_seo($id);$message='Link and non-image SEO improvement finished. '.get_post_meta($id,'_gnf5_seo_repair_note',true).' Review the checklist and reopen the editor to see Rank Math’s actual score.';}
            elseif($action==='seo'){GNF5_RankMath::reset_retry($id);GNF5_RankMath::run($id);$message='Rank Math recheck finished. Score: '.(GNF5_Publish::score($id)??'NOT CHECKED').'. '.get_post_meta($id,'_gnf5_seo_error',true);}
            elseif($action==='quality'){
                $research=GNF5_Research::load($id,true);
                if(is_wp_error($research))$result=$research;
                else{
                    $article=array('title'=>get_the_title($id),'content_html'=>get_post_field('post_content',$id));
                    $hash=hash('sha256',$article['content_html']);$report=GNF5_Quality::evaluate($article,$research,true);
                    if($hash!==hash('sha256',get_post_field('post_content',$id)))$result=new WP_Error('changed','Article changed during checking; stale report discarded.');
                    else GNF5_Quality::store($id,$report);
                }
            }elseif($action==='images'){
                $r=(array)get_post_meta($id,'_gnf5_quality_report',true);$r['images']=GNF5_Quality::images($id,$cat_id);update_post_meta($id,'_gnf5_quality_report',$r);
            }elseif($action==='links'){
                preg_match_all('/<a\b[^>]*href=["\']([^"\']+)/i',get_post_field('post_content',$id),$links);$checks=array();
                foreach(array_slice(array_unique($links[1]),0,10) as $url){
                    $local=url_to_postid($url);
                    $check=$local?array('status'=>get_post_status($local)==='publish'?'ok':'unavailable','message'=>'Local post lookup'):GNF5_Sources::test_external_link($url);
                    $checks[]=array('url'=>$url,'result'=>is_wp_error($check)?array('status'=>'UNKNOWN','message'=>$check->get_error_message()):$check);
                }
                $r=(array)get_post_meta($id,'_gnf5_quality_report',true);$r['links']=$checks;update_post_meta($id,'_gnf5_quality_report',$r);
            }elseif($action==='save_alt'){
                $attachment=absint($_POST['attachment_id']??0);$images=GNF5_Quality::images($id,$cat_id);
                if(!in_array($attachment,array_column($images['attachments'],'id'),true) || !current_user_can('edit_post',$attachment))$result=new WP_Error('attachment','This attachment is not part of this article or cannot be edited.');
                else {update_post_meta($attachment,'_wp_attachment_image_alt',sanitize_text_field(wp_unslash($_POST['alt']??'')));$message='Reviewed ALT text saved. Recheck Rank Math when ready.';}
            }elseif(in_array($action,array('generate_images','regenerate_images','remove_images'),true)){
                if($action!=='remove_images' && !GNF5_Utils::images_enabled($cat_id))$result=new WP_Error('images_off','Image generation is OFF. No image call was made. Enable it in the category/global settings first.');
                else $result=GNF5_Runner::image_action($id,$action);
            }else $result=new WP_Error('action','Unknown action.');
        }catch(Throwable $e){$result=new WP_Error('action_failed',GNF5_Utils::redact($e->getMessage()));}
        finally{GNF5_Utils::release_lock($cat_id);}
        if(is_wp_error($result))wp_send_json_error(array('message'=>$result->get_error_message()));
        wp_send_json_success(array('message'=>$message.' Refresh to view the report.'));
    }

    public static function export_log() {
        if(!current_user_can('manage_options'))wp_die('Permission denied.',403);
        check_admin_referer('gnf5_export_log');
        nocache_headers();header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="globiqnews-log.json"');
        echo wp_json_encode(get_option(GNF5_LOG_OPTION,array()),JSON_PRETTY_PRINT);exit;
    }
}
