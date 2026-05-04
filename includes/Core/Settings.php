<?php
if (!defined('ABSPATH')) { exit; }

final class CSMCR_Settings {
    public static function defaults() {
        return [
            'secret' => wp_generate_password(56, false, false),
            'remote_url' => '',
            'remote_secret' => '',
            'search' => site_url(),
            'replace' => site_url(),
            'db_batch' => 500,
            'file_batch' => 30,
            'chunk_bytes' => 524288,
            'exclude_tables' => '',
            'exclude_paths' => "*.php
*.phtml
*.phar
.htaccess
wp-config.php",
            'allow_db_write' => 0,
            'allow_file_write' => 0,
            'allow_theme_plugin_write' => 0,
            'allow_multisite_beta' => 0,
            'allow_legacy_auth' => 1,
            'enforce_https' => 0,
            'ip_allowlist' => '',
            'uploads_cursor' => 0,
            'themes_cursor' => 0,
            'plugins_cursor' => 0,
            'last_log' => '',
            'auto_backup_db' => 1,
            'skip_same_files' => 1,
            'use_file_hash' => 0,
            'ajax_step_delay_ms' => 900,
            'background_enabled' => 0,
            'background_delay_seconds' => 45,
            'background_max_steps' => 1,
            'max_step_retries' => 3,
            'auth_max_skew_seconds' => 300,
            'auth_rate_limit_per_minute' => 120,
            'job_step_lock_timeout_seconds' => 120,
            'max_reports' => 20,
            'max_backups' => 10,
            'manifest_include_hashes' => 0,
            'multisite_source_blog_id' => 1,
            'multisite_target_blog_id' => 1,
            'package_scopes' => 'uploads,themes,plugins',
        ];
    }

    public static function opts() {
        $opts = get_option(CSMCR_OPT, []);
        if (!is_array($opts)) { $opts = []; }
        $opts = array_merge(self::defaults(), $opts);
        update_option(CSMCR_OPT, $opts, false);
        return $opts;
    }

    public static function save_opts(array $new) {
        $opts = self::opts();
        foreach ($new as $k => $v) {
            if (array_key_exists($k, $opts)) { $opts[$k] = $v; }
        }
        update_option(CSMCR_OPT, $opts, false);
        return $opts;
    }


    public static function mask_secret($secret) {
        $secret = (string)$secret;
        $len = strlen($secret);
        if ($len <= 8) { return str_repeat('*', $len); }
        return substr($secret, 0, 4) . str_repeat('*', max(0, $len - 8)) . substr($secret, -4);
    }

    public static function profiles() {
        $profiles = get_option(CSMCR_PROFILE_OPT, []);
        return is_array($profiles) ? $profiles : [];
    }
}
