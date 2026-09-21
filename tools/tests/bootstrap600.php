<?php
if(PHP_SAPI!=='cli')exit('CLI only.');
$root=getenv('GNF5_TEST_WP_ROOT');
if(!$root || !is_file($root.'/wp-load.php'))throw new RuntimeException('Set GNF5_TEST_WP_ROOT to a disposable local WordPress directory.');
$_SERVER['HTTP_HOST']='globiqnews.localhost:8097';$_SERVER['REQUEST_URI']='/';
require_once $root.'/wp-load.php';
if(wp_get_environment_type()!=='local' || wp_parse_url(home_url(),PHP_URL_HOST)!=='globiqnews.localhost' || !defined('DISABLE_WP_CRON') || !DISABLE_WP_CRON || !defined('WP_HTTP_BLOCK_EXTERNAL') || !WP_HTTP_BLOCK_EXTERNAL)throw new RuntimeException('Tests require the isolated local host, disabled cron and blocked external HTTP.');
if(!defined('GNF5_VERSION') || version_compare(GNF5_VERSION,'6.0.0','<'))throw new RuntimeException('Activate the 6.0.0 plugin first.');
