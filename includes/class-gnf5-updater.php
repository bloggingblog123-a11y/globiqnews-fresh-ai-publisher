<?php
if (!defined('ABSPATH')) { exit; }

class GNF5_Updater {
    const REPOSITORY = 'https://github.com/bloggingblog123-a11y/globiqnews-fresh-ai-publisher';
    const SLUG = 'globiqnews-fresh-ai-publisher';
    private static $checker;

    public static function init() {
        if (self::$checker) { return; }
        require_once GNF5_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
        self::$checker = \YahnisElsts\PluginUpdateChecker\v5p7\PucFactory::buildUpdateChecker(
            self::REPOSITORY, GNF5_FILE, self::SLUG
        );
        self::$checker->setBranch('main');
        $api = self::$checker->getVcsApi();
        // Require the packaged asset. A tag or an unfinished branch is not a release.
        $api->enableReleaseAssets('/^globiqnews-fresh-ai-publisher\.zip$/i',
            \YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Api::REQUIRE_RELEASE_ASSETS);
        $api->setReleaseFilter(array(__CLASS__, 'stable_release'),
            \YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Api::RELEASE_FILTER_SKIP_PRERELEASE);
        add_filter('puc_vcs_update_detection_strategies-' . self::SLUG,
            array(__CLASS__, 'release_only'));
    }

    public static function release_only($strategies) {
        $key = \YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Api::STRATEGY_LATEST_RELEASE;
        return isset($strategies[$key]) ? array($key => $strategies[$key]) : array();
    }

    public static function stable_release($version, $release) {
        return is_string($version) && preg_match('/^\d+\.\d+\.\d+$/D', $version)
            && empty($release->draft) && empty($release->prerelease);
    }
}
