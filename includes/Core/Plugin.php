<?php
if (!defined('ABSPATH')) { exit; }

final class CSMCR_Core_Plugin {
    public static function init() {
        CSMCR_Plugin::init();
    }

    public static function on_activate() {
        CSMCR_Settings::opts();
    }

    public static function on_deactivate() {
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook(CSMCR_CRON_HOOK);
        }
    }
}
