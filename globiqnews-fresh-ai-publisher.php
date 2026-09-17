<?php
/**
 * Plugin Name: GlobiqNews Fresh AI Publisher
 * Description: Standalone category-isolated AI publisher with verified RSS/source/external-link engine, Rank Math 80+ score publishing, automatic publish, V5.9 recovery, category-safe source discovery, custom instructions, and 2 original low-storage images.
 * Version: 5.30.0
 * Plugin URI: https://github.com/bloggingblog123-a11y/globiqnews-fresh-ai-publisher
 * Update URI: https://github.com/bloggingblog123-a11y/globiqnews-fresh-ai-publisher
 * Author: GlobiqNews
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) { exit; }

define('GNF5_VERSION', '5.30.0');
define('GNF5_FILE', __FILE__);
define('GNF5_DIR', plugin_dir_path(__FILE__));
define('GNF5_URL', plugin_dir_url(__FILE__));
define('GNF5_OPTION', 'gnf5_fresh_settings');
define('GNF5_LOG_OPTION', 'gnf5_fresh_log');
define('GNF5_CRON_HOOK', 'gnf5_fresh_category_cron');
define('GNF5_RECOVERY_CRON_HOOK', 'gnf5_fresh_auto_recovery_cron');
define('GNF5_CRON_CONTINUE_HOOK', 'gnf5_fresh_category_continue_cron');
define('GNF5_BULK_RECOVERY_HOOK', 'gnf5_fresh_bulk_recovery_cron');
define('GNF5_BULK_RECOVERY_OPTION', 'gnf5_fresh_bulk_recovery_queue');
define('GNF5_BULK_RECOVERY_LOCK', 'gnf5_fresh_bulk_recovery_lock');

require_once GNF5_DIR . 'includes/class-gnf5-utils.php';
require_once GNF5_DIR . 'includes/class-gnf5-sources.php';
require_once GNF5_DIR . 'includes/class-gnf5-seo.php';
require_once GNF5_DIR . 'includes/class-gnf5-writer.php';
require_once GNF5_DIR . 'includes/class-gnf5-images.php';
require_once GNF5_DIR . 'includes/class-gnf5-runner.php';
require_once GNF5_DIR . 'includes/class-gnf5-publish.php';
require_once GNF5_DIR . 'includes/class-gnf5-rankmath.php';
require_once GNF5_DIR . 'includes/class-gnf5-admin.php';
require_once GNF5_DIR . 'includes/class-gnf5-updater.php';
add_action('plugins_loaded', array('GNF5_Updater', 'init'));

add_filter('cron_schedules', array('GNF5_Utils', 'cron_schedules'));
add_action(GNF5_CRON_HOOK, array('GNF5_Runner', 'cron_run_category'), 10, 1);
add_action(GNF5_RECOVERY_CRON_HOOK, array('GNF5_Runner', 'auto_recovery_cron'));
add_action(GNF5_CRON_CONTINUE_HOOK, array('GNF5_Runner', 'cron_continue_category'), 10, 4);
add_action(GNF5_BULK_RECOVERY_HOOK, array('GNF5_Runner', 'bulk_recovery_cron'));
add_action('admin_menu', array('GNF5_Admin', 'menu'));
add_action('admin_init', array('GNF5_Admin', 'register_settings'));
add_action('init', array('GNF5_Utils', 'ensure_recovery_schedule'));
add_action('added_post_meta', array('GNF5_Publish', 'score_saved'), 10, 4);
add_action('updated_post_meta', array('GNF5_Publish', 'score_saved'), 10, 4);
add_action('deleted_post_meta', array('GNF5_Publish', 'score_saved'), 10, 4);
add_filter('wp_insert_post_data', array('GNF5_Publish', 'guard_status'), PHP_INT_MAX, 2);
add_filter('update_post_metadata', array('GNF5_Publish', 'score_update_attempt'), 10, 5);
add_action('save_post_post', array('GNF5_Publish', 'post_saved'), 100, 1);
add_action('shutdown', array('GNF5_Publish', 'flush_scores'), 20);
add_action(GNF5_RankMath::HOOK, array('GNF5_RankMath', 'run'), 10, 1);
add_action('admin_enqueue_scripts', array('GNF5_Admin', 'enqueue'));
add_action('update_option_'.GNF5_OPTION, array('GNF5_Admin', 'settings_updated'), 10, 2);

add_action('wp_ajax_gnf5_run_category', array('GNF5_Admin', 'ajax_run_category'));
add_action('wp_ajax_gnf5_run_manual', array('GNF5_Admin', 'ajax_run_manual'));
add_action('wp_ajax_gnf5_test_source', array('GNF5_Admin', 'ajax_test_source'));
add_action('wp_ajax_gnf5_test_gemini', array('GNF5_Admin', 'ajax_test_gemini'));
add_action('wp_ajax_gnf5_test_image', array('GNF5_Admin', 'ajax_test_image'));
add_action('wp_ajax_gnf5_clear_log', array('GNF5_Admin', 'ajax_clear_log'));
add_action('wp_ajax_gnf5_clear_lock', array('GNF5_Admin', 'ajax_clear_lock'));
add_action('wp_ajax_gnf5_save_category', array('GNF5_Admin', 'ajax_save_category'));
add_action('wp_ajax_gnf5_test_category_sources', array('GNF5_Admin', 'ajax_test_category_sources'));
add_action('wp_ajax_gnf5_retry_post', array('GNF5_Admin', 'ajax_retry_post'));
add_action('wp_ajax_gnf5_enqueue_bulk_recovery', array('GNF5_Admin', 'ajax_enqueue_bulk_recovery'));
add_action('wp_ajax_gnf5_bulk_recovery_step', array('GNF5_Admin', 'ajax_bulk_recovery_step'));
add_action('wp_ajax_gnf5_bulk_recovery_status', array('GNF5_Admin', 'ajax_bulk_recovery_status'));
add_action('wp_ajax_gnf5_skip_failed', array('GNF5_Admin', 'ajax_skip_failed'));
add_action('wp_ajax_gnf5_check_publish_scores', array('GNF5_Admin', 'ajax_check_publish_scores'));

register_activation_hook(__FILE__, array('GNF5_Utils', 'activate'));
register_deactivation_hook(__FILE__, array('GNF5_Utils', 'deactivate'));
