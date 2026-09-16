<?php
if (!defined('ABSPATH')) { exit; }

class GNF5_Admin {
    public static function menu() {
        add_menu_page('GlobiqNews Fresh AI Publisher','GlobiqNews Fresh AI','manage_options','globiqnews-fresh-ai-publisher',array(__CLASS__,'page'),'dashicons-rss',25);
    }

    public static function register_settings() {
        register_setting('gnf5_group',GNF5_OPTION,array('sanitize_callback'=>array('GNF5_Utils','sanitize_settings')));
    }

    public static function settings_updated($old,$new) {
        GNF5_Utils::reschedule_all();
        GNF5_Utils::ensure_recovery_schedule();
        GNF5_Publish::check_saved_scores(20);
        GNF5_Utils::log('Fresh V5 settings saved; category and recovery schedules rebuilt.','info');
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
        GNF5_Publish::check_saved_scores(10);
        $s=GNF5_Utils::settings();$cats=get_categories(array('hide_empty'=>false));
        $all_users=get_users(array('orderby'=>'display_name','order'=>'ASC'));
        $users=array();
        foreach($all_users as $u){if(user_can($u,'edit_posts'))$users[]=$u;}
        $logs=get_option(GNF5_LOG_OPTION,array());if(!is_array($logs))$logs=array();
        $failed=GNF5_Utils::failed_posts(100);$bulk_status=GNF5_Runner::bulk_recovery_status();$bulk_ids=array_map('absint',(array)($bulk_status['post_ids']??array()));
        ?>
        <div class="wrap gnf5-wrap">
            <?php settings_errors(); ?>
            <h1>GlobiqNews Fresh AI Publisher <span class="gnf5-badge">V<?php echo esc_html(GNF5_VERSION); ?> Rank Math 80+ Auto Publish</span></h1>
            <div class="notice notice-success inline"><p><strong>Upgrade-safe:</strong> V<?php echo esc_html(GNF5_VERSION); ?> keeps your V5 settings, API keys, category sources, custom instructions, recovery data and schedules. RSS, Source URLs and Trusted External Links are checked independently.</p></div>
            <p class="gnf5-flow">Category RSS / Source URL / Manual URL → safe extraction → Gemini + instructions → Draft checkpoint → Rank Math repair → 2 low-storage original images → Rank Math score 80+ → Auto Publish or Draft/Pending</p>
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
                        <label>Gemini API Key<input type="password" name="<?php echo esc_attr(GNF5_OPTION); ?>[gemini_api_key]" value="<?php echo esc_attr($s['gemini_api_key']); ?>" autocomplete="off"></label>
                        <label>Gemini Text Model<input type="text" name="<?php echo esc_attr(GNF5_OPTION); ?>[gemini_model]" value="<?php echo esc_attr($s['gemini_model']); ?>"></label>
                        <label>Optional Backup Gemini Model<input type="text" name="<?php echo esc_attr(GNF5_OPTION); ?>[gemini_backup_model]" value="<?php echo esc_attr($s['gemini_backup_model']); ?>"><small>Used only after the primary model fails its automatic retries.</small></label>
                    </div>
                    <p><button type="submit" class="button button-primary">Save Settings</button> <button type="button" class="button gnf5-test-gemini">Test Gemini Connection</button></p>
                    <p class="description">Saves your Gemini API key, text model, backup model and all other settings on this page. Save changes before testing the connection.</p>
                    <div class="gnf5-rule"><strong>Fault tolerance:</strong> Gemini requests retry up to 3 times. If you enter a backup model, it is tried only after the primary model still fails.</div>
                    <div class="gnf5-rule"><strong>Locked article length:</strong> 1000–1200 words, target about 1100.</div>
                </section>

                <section class="gnf5-card">
                    <h2>2. Auto Publish + V5.9 Automatic Recovery</h2>
                    <div class="gnf5-grid2">
                        <label class="gnf5-inline"><input type="hidden" name="<?php echo esc_attr(GNF5_OPTION); ?>[auto_publish_enabled]" value="0"><input type="checkbox" name="<?php echo esc_attr(GNF5_OPTION); ?>[auto_publish_enabled]" value="1" <?php checked(!empty($s['auto_publish_enabled'])); ?>> <strong>Auto Publish when Rank Math SEO score is 80 or more</strong></label>
                        <label>When Auto Publish is OFF<select name="<?php echo esc_attr(GNF5_OPTION); ?>[validated_success_status]"><option value="draft" <?php selected($s['validated_success_status'],'draft'); ?>>Keep articles as Draft</option><option value="pending" <?php selected($s['validated_success_status'],'pending'); ?>>Send articles to Pending Review</option></select></label>
                        <label class="gnf5-inline"><input type="hidden" name="<?php echo esc_attr(GNF5_OPTION); ?>[auto_publish_recovered]" value="0"><input type="checkbox" name="<?php echo esc_attr(GNF5_OPTION); ?>[auto_publish_recovered]" value="1" <?php checked(!empty($s['auto_publish_recovered'])); ?>> Auto-publish recovered drafts when Rank Math SEO score reaches 80+</label>
                        <label class="gnf5-inline"><input type="hidden" name="<?php echo esc_attr(GNF5_OPTION); ?>[auto_recovery_enabled]" value="0"><input type="checkbox" name="<?php echo esc_attr(GNF5_OPTION); ?>[auto_recovery_enabled]" value="1" <?php checked(!empty($s['auto_recovery_enabled'])); ?>> Automatic failed-draft recovery</label>
                        <label>Maximum Automatic Recovery Attempts<input type="number" min="1" max="5" name="<?php echo esc_attr(GNF5_OPTION); ?>[auto_recovery_max_attempts]" value="<?php echo esc_attr($s['auto_recovery_max_attempts']); ?>"></label>
                    </div>
                    <p><button type="button" class="button button-primary gnf5-check-publish-scores">Check &amp; Publish 80+ Drafts Now</button></p>
                    <p class="description">Save settings first. Checks up to 50 existing plugin drafts with a saved score of 80+ and publishes eligible articles. No article rewriting or image generation. See Draft Auto Publish Status below for reasons a post stays in Draft.</p>
                    <div class="gnf5-rule"><strong>Publish rule:</strong> Completed plugin drafts qualify with an actual saved Rank Math SEO score of at least 80/100. Strict validation checks and private score-tracking metadata do not block publishing. A missing or lower score keeps the article as Draft. Draft/Pending behavior above applies when Auto Publish is OFF.</div>
                    <div class="gnf5-rule"><strong>Score calculation:</strong> Rank Math calculates its score in the editor. Open and save a waiting draft with Rank Math active. This plugin watches that saved score and publishes automatically when the score reaches 80 or more; it does not invent scores or run Rank Math's JavaScript analyzer in WP-Cron. Waiting drafts are also checked by WP-Cron approximately every 15 minutes. After editing an article, save a fresh Rank Math score for the updated content.</div>
                    <div class="gnf5-rule"><strong>V5.9 recovery retained:</strong> failed image/post-processing drafts automatically retry at approximately 15 minutes, then 1 hour, then 6 hours. Successful images and article text are checkpointed and reused. Only exhausted failures appear in Failed Draft Recovery.</div>
                </section>

                <section class="gnf5-card">
                    <h2>3. Rank Math SEO — Writing Targets</h2>
                    <div class="gnf5-checks">
                        <label><input type="hidden" name="<?php echo esc_attr(GNF5_OPTION); ?>[rankmath_enabled]" value="0"><input type="checkbox" name="<?php echo esc_attr(GNF5_OPTION); ?>[rankmath_enabled]" value="1" <?php checked($s['rankmath_enabled']); ?>> Save and synchronize Rank Math title, meta description and focus keyword</label>
                        <label><input type="hidden" name="<?php echo esc_attr(GNF5_OPTION); ?>[toc_enabled]" value="0"><input type="checkbox" name="<?php echo esc_attr(GNF5_OPTION); ?>[toc_enabled]" value="1" <?php checked($s['toc_enabled']); ?>> Add real Rank Math Table of Contents block</label>
                        <label><input type="hidden" name="<?php echo esc_attr(GNF5_OPTION); ?>[internal_links]" value="0"><input type="checkbox" name="<?php echo esc_attr(GNF5_OPTION); ?>[internal_links]" value="1" <?php checked($s['internal_links']); ?>> Add real same-category internal links when available</label>
                    </div>
                    <div class="gnf5-grid2">
                        <div class="gnf5-rule"><strong>Keyword:</strong> exact focus keyword at beginning of SEO title, in WordPress title, meta, slug, first 10%, H2/H3, body, conclusion and at least one image ALT; density 1.0%–1.5% using Rank Math-style exact phrase occurrences.</div>
                        <div class="gnf5-rule"><strong>SEO title:</strong> unique vs existing posts, ≤60 characters, number/year, natural power word, and one truthful positive OR negative sentiment word.</div>
                        <div class="gnf5-rule"><strong>Structure:</strong> 1000–1200 words, no body H1, multiple H2/H3, short paragraphs, list, Rank Math TOC, exactly 3 FAQ questions, 5–8 tags and valid Gutenberg blocks.</div>
                        <div class="gnf5-rule"><strong>Media & links:</strong> exactly 2 original images; Image 1 featured + inline, Image 2 inline; unique ALT; valid local image files; internal links when available; supplied external links must be normal DoFollow and not broken.</div>
                        <div class="gnf5-rule"><strong>Metadata:</strong> final Rank Math title/description/focus keyword must match the final article; meta description 120–160 characters (writer targets 140–155); slug under 75 characters; Article schema recommendation stored.</div>
                        <div class="gnf5-rule"><strong>Safety:</strong> source URL is never shown in article content, source images are never copied/used, and failed article/image processing remains Draft for recovery. SEO writing targets are not strict publishing checks.</div>
                    </div>
                    <p class="description"><strong>Note:</strong> Rank Math Content AI is a separate Rank Math service. The rules above guide article generation. Auto Publish uses the saved Rank Math SEO score, not this checklist or a Content AI score.</p>
                </section>

                <section class="gnf5-card">
                    <h2>4. Original Image System — 2 Images Total</h2>
                    <p><strong>Image 1 = Featured + appears inline. Image 2 = separate inline image.</strong> Source/RSS images are never downloaded, copied, traced, transformed or sent to the image generator.</p>
                    <div class="gnf5-checks"><label><input type="hidden" name="<?php echo esc_attr(GNF5_OPTION); ?>[image_enabled]" value="0"><input type="checkbox" name="<?php echo esc_attr(GNF5_OPTION); ?>[image_enabled]" value="1" <?php checked($s['image_enabled']); ?>> Generate exactly 2 original images per article</label></div>
                    <div class="gnf5-grid3">
                        <label>Image Provider<select name="<?php echo esc_attr(GNF5_OPTION); ?>[image_provider]"><option value="openai" <?php selected($s['image_provider'],'openai'); ?>>OpenAI Image API</option><option value="webui" <?php selected($s['image_provider'],'webui'); ?>>Self-hosted SD / FLUX WebUI</option><option value="builtin" <?php selected($s['image_provider'],'builtin'); ?>>Built-in original graphics</option></select></label>
                        <label>OpenAI API Key<input type="password" name="<?php echo esc_attr(GNF5_OPTION); ?>[openai_api_key]" value="<?php echo esc_attr($s['openai_api_key']); ?>" autocomplete="off"></label>
                        <label>OpenAI Image Model<input type="text" name="<?php echo esc_attr(GNF5_OPTION); ?>[openai_model]" value="<?php echo esc_attr($s['openai_model']); ?>"></label>
                        <label>Image Quality<select name="<?php echo esc_attr(GNF5_OPTION); ?>[openai_quality]"><?php foreach(array('low','medium','high','xhigh','max','auto') as $q): ?><option value="<?php echo esc_attr($q); ?>" <?php selected($s['openai_quality'],$q); ?>><?php echo esc_html(ucfirst($q)); ?></option><?php endforeach; ?></select></label>
                        <label>Image Size<select name="<?php echo esc_attr(GNF5_OPTION); ?>[openai_size]"><option value="1536x1024" <?php selected($s['openai_size'],'1536x1024'); ?>>1536×1024 landscape</option><option value="1024x1024" <?php selected($s['openai_size'],'1024x1024'); ?>>1024×1024 square</option><option value="1024x1536" <?php selected($s['openai_size'],'1024x1536'); ?>>1024×1536 portrait</option></select></label>
                        <label>Low-Storage WebP Quality<input type="number" min="50" max="90" name="<?php echo esc_attr(GNF5_OPTION); ?>[webp_quality]" value="<?php echo esc_attr($s['webp_quality']); ?>"></label>
                    </div>
                    <p class="description">Every successful generated image is optimized locally to 1200×675 WebP when supported. If Image 1 succeeds but Image 2 fails, Image 1 is checkpointed and Retry generates only the missing image.</p>
                    <details><summary>Self-hosted SD / FLUX settings</summary><div class="gnf5-grid3 gnf5-details"><label>WebUI Base URL<input name="<?php echo esc_attr(GNF5_OPTION); ?>[webui_endpoint]" value="<?php echo esc_attr($s['webui_endpoint']); ?>"></label><label>Optional Bearer Token<input type="password" name="<?php echo esc_attr(GNF5_OPTION); ?>[webui_api_key]" value="<?php echo esc_attr($s['webui_api_key']); ?>"></label><label>Optional Checkpoint<input name="<?php echo esc_attr(GNF5_OPTION); ?>[webui_model]" value="<?php echo esc_attr($s['webui_model']); ?>"></label></div></details>
                    <p><label><input type="hidden" name="<?php echo esc_attr(GNF5_OPTION); ?>[builtin_fallback]" value="0"><input type="checkbox" name="<?php echo esc_attr(GNF5_OPTION); ?>[builtin_fallback]" value="1" <?php checked($s['builtin_fallback']); ?>> Use built-in original graphics if the primary image provider still fails</label></p>
                    <p><button type="button" class="button gnf5-test-image">Test Image Generator</button></p>
                </section>

                <section class="gnf5-card">
                    <h2>5. Custom Instructions — Change Future Articles Without Editing Code</h2>
                    <p>Save new instructions here and the <strong>next generated article automatically uses them</strong>. These instructions cannot override locked factual, 1000–1200 word, 2-image, category-isolation, source-image, or SEO writing targets.</p>
                    <div class="gnf5-grid3">
                        <label>Global Article Instructions<textarea rows="7" name="<?php echo esc_attr(GNF5_OPTION); ?>[global_article_instructions]" placeholder="Example: Use simple professional English. Explain technical terms clearly. Avoid clickbait."><?php echo esc_textarea($s['global_article_instructions']); ?></textarea></label>
                        <label>Global SEO Instructions<textarea rows="7" name="<?php echo esc_attr(GNF5_OPTION); ?>[global_seo_instructions]" placeholder="Example: Prefer concise headlines and natural subheadings."><?php echo esc_textarea($s['global_seo_instructions']); ?></textarea></label>
                        <label>Global Image Instructions<textarea rows="7" name="<?php echo esc_attr(GNF5_OPTION); ?>[global_image_instructions]" placeholder="Example: Clean editorial illustration, realistic lighting, no text."><?php echo esc_textarea($s['global_image_instructions']); ?></textarea></label>
                    </div>
                    <div class="gnf5-rule"><strong>Instruction priority:</strong> locked plugin rules → your global instructions → category-specific instructions → source facts.</div>
                </section>

                <section class="gnf5-card">
                    <h2>6. Category-wise Sources, Author, Post Limits, Timing & Instructions</h2>
                    <p>Every category remains isolated. RSS is optional. <strong>Source URLs auto-detect category pages, direct article URLs, and feed URLs.</strong> Category-page discovery ignores navigation/sidebar links and strongly prefers article URLs that match that category path.</p>
                    <details><summary>Advanced source safety & recovery</summary><div class="gnf5-grid2 gnf5-details">
<label>Blocked Source Retry Delay (hours)<input type="number" min="1" max="72" name="<?php echo esc_attr(GNF5_OPTION); ?>[blocked_retry_hours]" value="<?php echo esc_attr($s['blocked_retry_hours']); ?>"></label>
<div class="gnf5-rule">Default 6 hours. 401/403/429/CAPTCHA sources are skipped and retried later; the plugin does not bypass anti-bot systems.</div>
</div></details>

                    <div class="gnf5-category-list">
                    <?php foreach($cats as $cat): $cs=GNF5_Utils::category_settings($cat->term_id,$s); ?>
                        <div class="gnf5-category" id="gnf5-cat-<?php echo absint($cat->term_id); ?>">
                            <div class="gnf5-category-head">
                                <h3><?php echo esc_html($cat->name); ?> <small>Category ID <?php echo absint($cat->term_id); ?></small></h3>
                                <div class="gnf5-cat-actions"><button type="button" class="button gnf5-save-cat" data-cat="<?php echo absint($cat->term_id); ?>">Save <?php echo esc_html($cat->name); ?> Settings</button><button type="button" class="button gnf5-test-cat-sources" data-cat="<?php echo absint($cat->term_id); ?>">Test RSS + Sources + External Links</button><button type="button" class="button button-primary gnf5-run-cat" data-cat="<?php echo absint($cat->term_id); ?>">Run <?php echo esc_html($cat->name); ?> Now</button></div>
                            </div>
                            <div class="gnf5-grid3">
                                <label class="gnf5-inline"><input type="hidden" name="<?php echo esc_attr(GNF5_OPTION); ?>[categories][<?php echo absint($cat->term_id); ?>][enabled]" value="0"><input type="checkbox" name="<?php echo esc_attr(GNF5_OPTION); ?>[categories][<?php echo absint($cat->term_id); ?>][enabled]" value="1" <?php checked($cs['enabled']); ?>> Enable automatic importing</label>
                                <label>Target Posts Per Run<input type="number" min="1" max="10" name="<?php echo esc_attr(GNF5_OPTION); ?>[categories][<?php echo absint($cat->term_id); ?>][post_limit]" value="<?php echo esc_attr($cs['post_limit']); ?>"></label>
                                <label>Automatic Timing<select name="<?php echo esc_attr(GNF5_OPTION); ?>[categories][<?php echo absint($cat->term_id); ?>][interval]"><?php self::interval_options($cs['interval']); ?></select></label>
                                <label>Author for this Category<select name="<?php echo esc_attr(GNF5_OPTION); ?>[categories][<?php echo absint($cat->term_id); ?>][author_id]"><?php foreach($users as $u): ?><option value="<?php echo absint($u->ID); ?>" <?php selected($cs['author_id'],$u->ID); ?>><?php echo esc_html($u->display_name); ?> (<?php echo esc_html($u->user_login); ?>)</option><?php endforeach; ?></select></label>
                            </div>
                            <div class="gnf5-grid3">
                                <label><?php echo esc_html($cat->name); ?> — RSS / Atom Feeds<small>Optional · one feed URL per line · WordPress parser + raw XML fallback</small><textarea rows="5" name="<?php echo esc_attr(GNF5_OPTION); ?>[categories][<?php echo absint($cat->term_id); ?>][rss]"><?php echo esc_textarea($cs['rss']); ?></textarea></label>
                                <label><?php echo esc_html($cat->name); ?> — Source URLs<small>One URL per line · category/listing page OR direct article URL; mode is detected automatically</small><textarea rows="5" name="<?php echo esc_attr(GNF5_OPTION); ?>[categories][<?php echo absint($cat->term_id); ?>][urls]"><?php echo esc_textarea($cs['urls']); ?></textarea></label>
                                <label><?php echo esc_html($cat->name); ?> — Trusted External DoFollow Links<small>Recommended for the normal Rank Math external-link + followed-link tests · category-specific · only reachable/restricted-but-public normal DoFollow links are inserted · source article URL is never inserted · Save this category before using Test.</small><textarea rows="5" name="<?php echo esc_attr(GNF5_OPTION); ?>[categories][<?php echo absint($cat->term_id); ?>][external_links]"><?php echo esc_textarea($cs['external_links']); ?></textarea></label>
                            </div>
                            <label><?php echo esc_html($cat->name); ?> — Category Custom Instructions<small>Combined with Global Instructions only for this category.</small><textarea rows="5" name="<?php echo esc_attr(GNF5_OPTION); ?>[categories][<?php echo absint($cat->term_id); ?>][instructions]" placeholder="Example: Use a match-report style for Sports, but keep all locked factual and SEO rules."><?php echo esc_textarea($cs['instructions']); ?></textarea></label>
                            <input type="hidden" name="<?php echo esc_attr(GNF5_OPTION); ?>[categories][<?php echo absint($cat->term_id); ?>][_row_complete]" value="1">
                            <?php if(GNF5_Utils::is_locked($cat->term_id)): ?><p class="gnf5-lock">This category has an import lock. <button type="button" class="button-link gnf5-clear-lock" data-cat="<?php echo absint($cat->term_id); ?>">Clear only if genuinely stuck</button></p><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </section>

                <?php submit_button('Save All V'.GNF5_VERSION.' Settings'); ?>
            </form>

            <section class="gnf5-card">
                <h2>7. Immediate Manual Runs — No WP-Cron</h2>
                <button type="button" class="button button-primary gnf5-run-all">Run All Enabled Categories Now</button>
                <hr>
                <div class="gnf5-grid3">
                    <label>Manual Article URL<input type="url" id="gnf5-manual-url" placeholder="https://example.com/article"></label>
                    <label>WordPress Category<select id="gnf5-manual-cat"><?php foreach($cats as $cat): ?><option value="<?php echo absint($cat->term_id); ?>"><?php echo esc_html($cat->name); ?></option><?php endforeach; ?></select></label>
                    <div class="gnf5-button-cell"><button type="button" class="button button-primary gnf5-run-manual">Rewrite This Article Now</button><button type="button" class="button gnf5-test-source">Test Source Extraction Only</button></div>
                </div>
            </section>

            <?php $score_waiting=GNF5_Publish::waiting_posts(50); if($score_waiting): ?>
            <section class="gnf5-card">
                <h2>Draft Auto Publish Status</h2>
                <p>This is the score saved in WordPress, which may differ from an unsaved score shown in the editor. Auto Publish must be enabled; recovered drafts also require the recovered-draft publishing option.</p>
                <?php foreach($score_waiting as $p): $score=GNF5_Publish::score($p->ID); ?>
                    <p><strong>#<?php echo absint($p->ID); ?> — <?php echo esc_html(get_the_title($p)); ?></strong>
                    · Rank Math: <?php echo $score===null ? 'Not calculated' : esc_html($score.'/100'); ?>
                    <a class="button" href="<?php echo esc_url(get_edit_post_link($p->ID)); ?>">Open Draft to Calculate Score</a><br>
                    <small><?php $reason=GNF5_Publish::blocked_reason($p->ID); echo esc_html($reason?:((string)get_post_meta($p->ID,'_gnf5_publish_wait_reason',true)?:'Ready: click Check & Publish 80+ Drafts Now.')); ?></small></p>
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

            <section class="gnf5-card">
                <div class="gnf5-category-head"><h2><?php echo $failed ? '9' : '8'; ?>. Live Log</h2><button type="button" class="button gnf5-clear-log">Clear Fresh V5 Log</button></div>
                <div id="gnf5-log" class="gnf5-log"><?php if(!$logs): ?>No Fresh V5 log entries yet.<?php else: foreach($logs as $row): $catname=$row['cat']?get_cat_name($row['cat']):''; ?><div><span class="gnf5-time">[<?php echo esc_html($row['time']); ?>]</span> <strong><?php echo esc_html(strtoupper($row['type'])); ?></strong><?php echo $catname?' ['.esc_html($catname).']':''; ?> — <?php echo esc_html($row['message']); ?></div><?php endforeach; endif; ?></div>
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
        check_ajax_referer('gnf5_ajax','nonce');
    }

    public static function ajax_check_publish_scores(){
        self::guard();
        $result=GNF5_Publish::check_saved_scores(50);
        $message='Checked '.$result['reviewed'].' qualifying draft(s); published '.$result['published'].'; still blocked '.$result['blocked'].'.';
        if($result['reasons'])$message.=' '.implode(' | ',$result['reasons']);
        elseif(!$result['reviewed'])$message.=' No eligible draft with a saved Rank Math score of 80+ was found. See Draft Auto Publish Status for saved scores and processing states.';
        $message.=' Refresh this page to update the draft status list.';
        GNF5_Utils::log($message,$result['blocked']?'warning':'info');
        wp_send_json_success(array('message'=>$message,'result'=>$result));
    }

    public static function ajax_save_category(){
        self::guard();
        $cat=absint($_POST['cat_id']??0);
        if(!$cat || !get_category($cat))wp_send_json_error(array('message'=>'Invalid WordPress category.'));
        $row=array(
            'enabled'=>empty($_POST['enabled'])?0:1,'post_limit'=>absint($_POST['post_limit']??1),
            'interval'=>sanitize_key($_POST['interval']??'hourly'),'author_id'=>absint($_POST['author_id']??0),
            'rss'=>wp_unslash($_POST['rss']??''),'urls'=>wp_unslash($_POST['urls']??''),
            'external_links'=>wp_unslash($_POST['external_links']??''),'instructions'=>wp_unslash($_POST['instructions']??''),
        );
        $settings=GNF5_Utils::settings();
        $settings['categories'][$cat]=GNF5_Utils::sanitize_category_row($row);
        // update_option triggers settings_updated(), which rebuilds schedules once.
        update_option(GNF5_OPTION,$settings,false);
        $name=get_cat_name($cat)?:('Category '.$cat);
        GNF5_Utils::log($name.' settings saved separately.','success',$cat);
        wp_send_json_success(array('message'=>$name.' settings saved.','category'=>$settings['categories'][$cat]));
    }

    public static function ajax_test_category_sources(){
        self::guard();
        $cat=absint($_POST['cat_id']??0);
        if(!$cat || !get_category($cat))wp_send_json_error(array('message'=>'Invalid WordPress category.'));
        $cs=GNF5_Utils::category_settings($cat);
        $parts=array();$ok=0;$bad=0;
        $rss_urls=GNF5_Utils::urls_from_lines($cs['rss']);
        foreach($rss_urls as $u){
            $x=GNF5_Sources::rss_items($u,5);
            if(is_wp_error($x)){$bad++;$parts[]='RSS FAIL: '.$u.' — '.$x->get_error_message();}
            elseif(!$x){$bad++;$parts[]='RSS FAIL: '.$u.' — feed returned zero usable article items.';}
            else{$ok++;$parts[]='RSS OK: '.$u.' — '.count($x).' item(s) found via '.sanitize_text_field($x[0]['method']??'RSS').'.';}
        }
        foreach(GNF5_Utils::urls_from_lines($cs['urls']) as $u){
            $x=GNF5_Sources::discover_source($u,8);
            if(is_wp_error($x)){$bad++;$parts[]='SOURCE FAIL: '.$u.' — '.$x->get_error_message();}
            else{
                $ok++;$methods=array();foreach($x as $i){if(!empty($i['method']))$methods[$i['method']]=true;}
                $parts[]='SOURCE OK: '.$u.' — '.count($x).' candidate(s) via '.implode(', ',array_keys($methods)).'.';
            }
        }
        foreach(GNF5_Utils::urls_from_lines($cs['external_links']) as $u){
            $x=GNF5_Sources::test_external_link($u,true);
            if(is_wp_error($x)){
                $bad++;$parts[]='EXTERNAL FAIL: '.$u.' — '.$x->get_error_message();
            }else{
                $status=strtoupper((string)($x['status']??'unknown'));
                $code=absint($x['code']??0);
                $state=(string)($x['status']??'');
                if(in_array($state,array('ok','restricted'),true)){$ok++;}else{$bad++;}
                $parts[]='EXTERNAL '.$status.': '.$u.($code?' — HTTP '.$code:'').' — '.sanitize_text_field($x['message']??'');
            }
        }
        if(!$parts)$parts[]='No RSS, Source URLs or Trusted External Links are saved for this category.';
        $name=get_cat_name($cat)?:('Category '.$cat);
        wp_send_json_success(array('message'=>$name.' source test: '.$ok.' working/usable, '.$bad.' failed/broken. '.implode(' | ',$parts),'ok'=>$ok,'failed'=>$bad,'details'=>$parts));
    }

    public static function ajax_run_category(){
        self::guard();$cat=absint($_POST['cat_id']??0);$pass=max(1,absint($_POST['pass']??1));
        $r=GNF5_Runner::run_category($cat,'immediate',1,$pass);
        if(is_wp_error($r))wp_send_json_error(array('message'=>$r->get_error_message()));
        wp_send_json_success(array('message'=>$r['message']??'Category run finished.','result'=>$r));
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
        self::guard();$cat=absint($_POST['cat_id']??0);GNF5_Utils::force_clear_lock($cat);wp_send_json_success(array('message'=>'Category import lock cleared.'));
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
}
