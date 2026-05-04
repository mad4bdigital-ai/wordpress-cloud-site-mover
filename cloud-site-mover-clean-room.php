diff --git a/cloud-site-mover-clean-room.php b/cloud-site-mover-clean-room.php
index 1181beaf175e10e256a5886cdb65e3b09e298e5a..7012fcff3d1429dcfa00bde659d2de934f042422 100644
--- a/cloud-site-mover-clean-room.php
+++ b/cloud-site-mover-clean-room.php
@@ -1,310 +1,277 @@
 <?php
 /**
  * Plugin Name: Cloud Site Mover Clean Room
  * Description: Clean-room WordPress cloud migration helper for DB push/pull, media/theme/plugin sync, profiles, WP-CLI, and guarded multisite planning.
- * Version: 1.1.0
+ * Version: 1.2.0
  * Author: Clean Room Build
  * License: GPL-2.0-or-later
  */
 
 if (!defined('ABSPATH')) { exit; }
 
-define('CSMCR_VERSION', '1.1.0');
+define('CSMCR_VERSION', '1.2.0');
 define('CSMCR_ACTIVE_JOB_OPT', 'csmcr_active_job');
 define('CSMCR_OPT', 'csmcr_options');
 define('CSMCR_PROFILE_OPT', 'csmcr_profiles');
 define('CSMCR_JOBS_OPT', 'csmcr_jobs');
 define('CSMCR_NS', 'cloud-site-mover/v1');
 define('CSMCR_CRON_HOOK', 'csmcr_background_step');
 define('CSMCR_BG_LOCK', 'csmcr_background_lock');
+define('CSMCR_JOB_STEP_LOCK', 'csmcr_job_step_lock');
+define('CSMCR_MAIN_FILE', __FILE__);
+
+require_once __DIR__ . '/includes/Core/Loader.php';
+CSMCR_Loader::require_core();
+register_activation_hook(CSMCR_MAIN_FILE, ['CSMCR_Core_Plugin', 'on_activate']);
+register_deactivation_hook(CSMCR_MAIN_FILE, ['CSMCR_Core_Plugin', 'on_deactivate']);
 
 final class CSMCR_Plugin {
     public static function init() {
         add_action('admin_menu', [__CLASS__, 'admin_menu']);
         add_action('admin_init', [__CLASS__, 'handle_actions']);
         add_action('admin_post_csmcr_download_report', [__CLASS__, 'download_report']);
         add_action('admin_post_csmcr_download_backup', [__CLASS__, 'download_backup']);
         add_action('wp_ajax_csmcr_job_status', [__CLASS__, 'ajax_job_status']);
         add_action('wp_ajax_csmcr_job_step', [__CLASS__, 'ajax_job_step']);
         add_action('wp_ajax_csmcr_job_cancel', [__CLASS__, 'ajax_job_cancel']);
         add_action(CSMCR_CRON_HOOK, ['CSMCR_Background', 'run']);
         add_action('rest_api_init', [__CLASS__, 'rest_routes']);
         if (defined('WP_CLI') && WP_CLI) { CSMCR_CLI::register(); }
     }
 
-    public static function defaults() {
-        return [
-            'secret' => wp_generate_password(56, false, false),
-            'remote_url' => '',
-            'remote_secret' => '',
-            'search' => site_url(),
-            'replace' => site_url(),
-            'db_batch' => 500,
-            'file_batch' => 30,
-            'chunk_bytes' => 524288,
-            'exclude_tables' => '',
-            'exclude_paths' => "*.php\n*.phtml\n*.phar\n.htaccess\nwp-config.php",
-            'allow_db_write' => 0,
-            'allow_file_write' => 0,
-            'allow_theme_plugin_write' => 0,
-            'allow_multisite_beta' => 0,
-            'uploads_cursor' => 0,
-            'themes_cursor' => 0,
-            'plugins_cursor' => 0,
-            'last_log' => '',
-            'auto_backup_db' => 1,
-            'skip_same_files' => 1,
-            'use_file_hash' => 0,
-            'ajax_step_delay_ms' => 900,
-            'background_enabled' => 0,
-            'background_delay_seconds' => 45,
-            'background_max_steps' => 1,
-            'max_reports' => 20,
-            'max_backups' => 10,
-            'manifest_include_hashes' => 0,
-            'multisite_source_blog_id' => 1,
-            'multisite_target_blog_id' => 1,
-            'package_scopes' => 'uploads,themes,plugins',
-        ];
-    }
+    public static function defaults() { return CSMCR_Settings::defaults(); }
 
-    public static function opts() {
-        $opts = get_option(CSMCR_OPT, []);
-        if (!is_array($opts)) { $opts = []; }
-        $opts = array_merge(self::defaults(), $opts);
-        update_option(CSMCR_OPT, $opts, false);
-        return $opts;
-    }
+    public static function opts() { return CSMCR_Settings::opts(); }
 
-    public static function save_opts($new) {
-        $opts = self::opts();
-        foreach ($new as $k => $v) {
-            if (array_key_exists($k, $opts)) { $opts[$k] = $v; }
-        }
-        update_option(CSMCR_OPT, $opts, false);
-        return $opts;
-    }
+    public static function save_opts($new) { return CSMCR_Settings::save_opts((array)$new); }
 
-    public static function profiles() {
-        $profiles = get_option(CSMCR_PROFILE_OPT, []);
-        return is_array($profiles) ? $profiles : [];
-    }
+    public static function profiles() { return CSMCR_Settings::profiles(); }
 
-    public static function jobs() {
-        $jobs = get_option(CSMCR_JOBS_OPT, []);
-        return is_array($jobs) ? array_slice($jobs, 0, 40) : [];
-    }
+    public static function jobs() { return CSMCR_Jobs_Manager::jobs(); }
 
-    public static function add_job($msg) {
-        $jobs = self::jobs();
-        array_unshift($jobs, '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . wp_strip_all_tags((string)$msg));
-        update_option(CSMCR_JOBS_OPT, array_slice($jobs, 0, 40), false);
-    }
+    public static function add_job($msg) { CSMCR_Jobs_Manager::add_job($msg); }
 
-    public static function active_job() {
-        $job = get_option(CSMCR_ACTIVE_JOB_OPT, []);
-        return is_array($job) ? $job : [];
-    }
+    public static function active_job() { return CSMCR_Jobs_Manager::get_active(); }
 
-    public static function save_active_job($job) {
-        $job['updated_at'] = gmdate('c');
-        update_option(CSMCR_ACTIVE_JOB_OPT, $job, false);
-        return $job;
-    }
+    public static function save_active_job($job) { return CSMCR_Jobs_Manager::save_active($job); }
 
-    public static function clear_active_job() {
-        delete_option(CSMCR_ACTIVE_JOB_OPT);
-    }
+    public static function clear_active_job() { CSMCR_Jobs_Manager::clear_active(); }
 
     public static function report_dir() {
         $u = wp_upload_dir();
         $dir = trailingslashit($u['basedir']) . 'csmcr-reports';
         wp_mkdir_p($dir);
         if (!file_exists($dir . '/index.html')) { file_put_contents($dir . '/index.html', ''); }
         if (!file_exists($dir . '/.htaccess')) { file_put_contents($dir . '/.htaccess', "Deny from all\n"); }
         return trailingslashit($dir);
     }
 
     public static function save_profile($name) {
         $name = sanitize_key($name);
         if (!$name) { throw new Exception('Profile name is required.'); }
         $profiles = self::profiles();
         $o = self::opts();
         unset($o['secret'], $o['last_log']);
         $profiles[$name] = $o;
         update_option(CSMCR_PROFILE_OPT, $profiles, false);
         self::log('Saved profile: ' . $name);
     }
 
     public static function load_profile($name) {
         $name = sanitize_key($name);
         $profiles = self::profiles();
         if (empty($profiles[$name]) || !is_array($profiles[$name])) { throw new Exception('Profile not found.'); }
         self::save_opts($profiles[$name]);
         self::log('Loaded profile: ' . $name);
     }
 
-    public static function log($msg) {
-        $opts = self::opts();
-        $line = '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . wp_strip_all_tags((string)$msg);
-        $opts['last_log'] = $line . "\n" . substr((string)$opts['last_log'], 0, 16000);
-        update_option(CSMCR_OPT, $opts, false);
-    }
+    public static function log($msg) { CSMCR_Logger::info($msg); }
 
     public static function admin_menu() {
         add_management_page('Cloud Site Mover', 'Cloud Site Mover', 'manage_options', 'cloud-site-mover', [__CLASS__, 'admin_page']);
     }
 
     private static function button($action, $label, $class='button') {
         echo '<form method="post" style="display:inline-block;margin:4px 4px 4px 0">';
         wp_nonce_field('csmcr_action');
         echo '<input type="hidden" name="csmcr_action" value="' . esc_attr($action) . '" />';
         submit_button($label, $class, 'submit', false);
         echo '</form>';
     }
 
     public static function admin_page() {
         if (!current_user_can('manage_options')) { return; }
         $o = self::opts();
         $profiles = self::profiles();
+        $security = self::security_status_snapshot();
         $url = admin_url('tools.php?page=cloud-site-mover');
         ?>
         <div class="wrap">
             <h1>Cloud Site Mover Clean Room <small>v<?php echo esc_html(CSMCR_VERSION); ?></small></h1>
             <p><strong>Use only on trusted sites.</strong> Take a full hosting backup before DB/code-folder migration.</p>
             <h2>Connection</h2>
             <form method="post" action="<?php echo esc_url($url); ?>">
                 <?php wp_nonce_field('csmcr_save'); ?>
                 <input type="hidden" name="csmcr_action" value="save" />
                 <table class="form-table" role="presentation">
-                    <tr><th>Your shared secret</th><td><code style="user-select:all"><?php echo esc_html($o['secret']); ?></code></td></tr>
+                    <tr><th>Your shared secret</th><td>
+                        <code><?php echo esc_html(CSMCR_Settings::mask_secret($o['secret'])); ?></code>
+                        <details style="margin-top:6px"><summary><?php esc_html_e('Reveal full secret', 'cloud-site-mover-clean-room'); ?></summary><code style="user-select:all"><?php echo esc_html($o['secret']); ?></code></details>
+                        <div style="margin-top:6px"><?php self::button('rotate_secret', __('Rotate shared secret', 'cloud-site-mover-clean-room')); ?></div>
+                    </td></tr>
                     <tr><th>Remote/source URL</th><td><input class="regular-text" name="remote_url" value="<?php echo esc_attr($o['remote_url']); ?>" placeholder="https://old-site.com" /></td></tr>
                     <tr><th>Remote/source secret</th><td><input class="regular-text" name="remote_secret" value="<?php echo esc_attr($o['remote_secret']); ?>" /></td></tr>
                     <tr><th>Search URL/string</th><td><input class="regular-text" name="search" value="<?php echo esc_attr($o['search']); ?>" /></td></tr>
                     <tr><th>Replace URL/string</th><td><input class="regular-text" name="replace" value="<?php echo esc_attr($o['replace']); ?>" /></td></tr>
                     <tr><th>DB rows per batch</th><td><input type="number" min="50" max="5000" name="db_batch" value="<?php echo esc_attr($o['db_batch']); ?>" /></td></tr>
                     <tr><th>Files per batch</th><td><input type="number" min="1" max="500" name="file_batch" value="<?php echo esc_attr($o['file_batch']); ?>" /></td></tr>
                     <tr><th>File chunk bytes</th><td><input type="number" min="65536" max="5242880" name="chunk_bytes" value="<?php echo esc_attr($o['chunk_bytes']); ?>" /></td></tr>
                     <tr><th>Exclude DB tables</th><td><textarea name="exclude_tables" rows="4" class="large-text" placeholder="one table name or pattern per line"><?php echo esc_textarea($o['exclude_tables']); ?></textarea></td></tr>
                     <tr><th>Exclude file paths</th><td><textarea name="exclude_paths" rows="6" class="large-text"><?php echo esc_textarea($o['exclude_paths']); ?></textarea></td></tr>
                     <tr><th>Allow remote writes to this site</th><td>
                         <label><input type="checkbox" name="allow_db_write" value="1" <?php checked($o['allow_db_write']); ?> /> Database writes</label><br>
                         <label><input type="checkbox" name="allow_file_write" value="1" <?php checked($o['allow_file_write']); ?> /> Upload/media writes</label><br>
                         <label><input type="checkbox" name="allow_theme_plugin_write" value="1" <?php checked($o['allow_theme_plugin_write']); ?> /> Theme/plugin folder writes</label><br>
                         <label><input type="checkbox" name="allow_multisite_beta" value="1" <?php checked($o['allow_multisite_beta']); ?> /> Guarded multisite planning endpoints</label><br>
+                        <label><input type="checkbox" name="allow_legacy_auth" value="1" <?php checked($o['allow_legacy_auth']); ?> /> <?php esc_html_e('Allow legacy secret-only remote auth (compat mode)', 'cloud-site-mover-clean-room'); ?></label><br>
+                        <label><input type="checkbox" name="enforce_https" value="1" <?php checked($o['enforce_https']); ?> /> <?php esc_html_e('Require HTTPS for remote URL', 'cloud-site-mover-clean-room'); ?></label><br>
+                        <label><?php esc_html_e('Remote IP/CIDR allowlist (one entry per line)', 'cloud-site-mover-clean-room'); ?><br><textarea name="ip_allowlist" rows="3" class="regular-text" placeholder="203.0.113.10\n198.51.100.22\n10.0.0.0/24"><?php echo esc_textarea($o['ip_allowlist']); ?></textarea></label><br>
                         <label><input type="checkbox" name="auto_backup_db" value="1" <?php checked($o['auto_backup_db']); ?> /> Auto-create DB backup before pull/push overwrite</label><br>
                         <label><input type="checkbox" name="skip_same_files" value="1" <?php checked($o['skip_same_files']); ?> /> Skip files with same size and modified time</label><br>
                         <label><input type="checkbox" name="use_file_hash" value="1" <?php checked($o['use_file_hash']); ?> /> Add SHA1 hashes to file manifests when comparing files</label><br>
                         <label><input type="checkbox" name="manifest_include_hashes" value="1" <?php checked($o['manifest_include_hashes']); ?> /> Include SHA1 hashes in full-site manifest reports</label><br>
                         <label>AJAX auto-step delay <input type="number" min="250" max="10000" name="ajax_step_delay_ms" value="<?php echo esc_attr($o['ajax_step_delay_ms']); ?>" /> ms</label><br>
                         <label><input type="checkbox" name="background_enabled" value="1" <?php checked($o['background_enabled']); ?> /> Enable WP-Cron background runner</label><br>
                         <label>Background delay <input type="number" min="15" max="3600" name="background_delay_seconds" value="<?php echo esc_attr($o['background_delay_seconds']); ?>" /> seconds</label><br>
                         <label>Background max steps per run <input type="number" min="1" max="10" name="background_max_steps" value="<?php echo esc_attr($o['background_max_steps']); ?>" /></label><br>
+                        <label><?php esc_html_e('Max retries per failed step', 'cloud-site-mover-clean-room'); ?> <input type="number" min="0" max="10" name="max_step_retries" value="<?php echo esc_attr($o['max_step_retries']); ?>" /></label><br>
+                        <label><?php esc_html_e('Auth timestamp skew window', 'cloud-site-mover-clean-room'); ?> <input type="number" min="30" max="3600" name="auth_max_skew_seconds" value="<?php echo esc_attr($o['auth_max_skew_seconds']); ?>" /> <?php esc_html_e('seconds', 'cloud-site-mover-clean-room'); ?></label><br>
+                        <label><?php esc_html_e('Auth rate limit per minute', 'cloud-site-mover-clean-room'); ?> <input type="number" min="10" max="5000" name="auth_rate_limit_per_minute" value="<?php echo esc_attr($o['auth_rate_limit_per_minute']); ?>" /></label><br>
+                        <label><?php esc_html_e('Job step lock timeout', 'cloud-site-mover-clean-room'); ?> <input type="number" min="15" max="1800" name="job_step_lock_timeout_seconds" value="<?php echo esc_attr($o['job_step_lock_timeout_seconds']); ?>" /> <?php esc_html_e('seconds', 'cloud-site-mover-clean-room'); ?></label><br>
                         <label>Keep latest reports <input type="number" min="1" max="100" name="max_reports" value="<?php echo esc_attr($o['max_reports']); ?>" /></label><br>
                         <label>Keep latest DB backups <input type="number" min="1" max="100" name="max_backups" value="<?php echo esc_attr($o['max_backups']); ?>" /></label><br>
                         <label>Multisite source blog ID <input type="number" min="1" max="999999" name="multisite_source_blog_id" value="<?php echo esc_attr($o['multisite_source_blog_id']); ?>" /></label><br>
                         <label>Multisite target blog ID <input type="number" min="1" max="999999" name="multisite_target_blog_id" value="<?php echo esc_attr($o['multisite_target_blog_id']); ?>" /></label><br>
                         <label>Package scopes <input class="regular-text" name="package_scopes" value="<?php echo esc_attr($o['package_scopes']); ?>" placeholder="uploads,themes,plugins" /></label>
                     </td></tr>
                 </table>
                 <?php submit_button('Save settings'); ?>
             </form>
 
+            <h2><?php esc_html_e('Security Summary', 'cloud-site-mover-clean-room'); ?></h2>
+            <table class="widefat" style="max-width:760px;margin-bottom:12px">
+                <tbody>
+                    <tr><td><strong><?php esc_html_e('Legacy auth', 'cloud-site-mover-clean-room'); ?></strong></td><td><?php echo !empty($security['allow_legacy_auth']) ? esc_html__('Enabled', 'cloud-site-mover-clean-room') : esc_html__('Disabled', 'cloud-site-mover-clean-room'); ?></td></tr>
+                    <tr><td><strong><?php esc_html_e('HTTPS enforcement', 'cloud-site-mover-clean-room'); ?></strong></td><td><?php echo !empty($security['enforce_https']) ? esc_html__('Enabled', 'cloud-site-mover-clean-room') : esc_html__('Disabled', 'cloud-site-mover-clean-room'); ?></td></tr>
+                    <tr><td><strong><?php esc_html_e('Auth skew window', 'cloud-site-mover-clean-room'); ?></strong></td><td><?php echo esc_html((string)($security['auth_max_skew_seconds'] ?? 300)); ?>s</td></tr>
+                    <tr><td><strong><?php esc_html_e('Auth rate limit', 'cloud-site-mover-clean-room'); ?></strong></td><td><?php echo esc_html((string)($security['auth_rate_limit_per_minute'] ?? 120)); ?>/min</td></tr>
+                    <tr><td><strong><?php esc_html_e('Step lock timeout', 'cloud-site-mover-clean-room'); ?></strong></td><td><?php echo esc_html((string)($security['job_step_lock_timeout_seconds'] ?? 120)); ?>s</td></tr>
+                    <tr><td><strong><?php esc_html_e('Allowlist entries', 'cloud-site-mover-clean-room'); ?></strong></td><td><?php echo esc_html((string)($security['ip_allowlist_count'] ?? 0)); ?></td></tr>
+                </tbody>
+            </table>
+
             <h2>Actions</h2>
             <?php self::button('ping', 'Test remote connection'); ?>
             <?php self::button('dry_run', 'Dry run compare'); ?>
             <?php self::button('preflight', 'Run preflight checks'); ?>
+            <?php self::button('security_check', __('Run security check', 'cloud-site-mover-clean-room')); ?>
             <?php self::button('backup_db', 'Create local DB backup'); ?>
             <?php self::button('create_manifest', 'Create full-site manifest'); ?>
             <?php self::button('create_handoff_plan', 'Create migration handoff plan'); ?>
             <?php self::button('create_multisite_map', 'Create multisite map'); ?>
             <?php self::button('create_multisite_simulation', 'Create multisite conversion simulation'); ?>
             <?php self::button('create_package_manifest', 'Create portable package manifest'); ?>
             <?php self::button('cleanup_temp', 'Cleanup old migration files'); ?>
             <?php self::button('pull_db', 'Pull remote DB into this site', 'button button-primary'); ?>
             <?php self::button('push_db', 'Push this DB to remote'); ?>
             <br>
             <?php self::button('pull_uploads', 'Pull next media batch'); ?>
             <?php self::button('push_uploads', 'Push next media batch'); ?>
             <?php self::button('pull_themes', 'Pull next themes batch'); ?>
             <?php self::button('push_themes', 'Push next themes batch'); ?>
             <?php self::button('pull_plugins', 'Pull next plugins batch'); ?>
             <?php self::button('push_plugins', 'Push next plugins batch'); ?>
             <?php self::button('reset_cursors', 'Reset file cursors'); ?>
 
             <h2>Resumable Job Runner</h2>
             <p>Use this for large sites. Start a job, then run steps manually or use the AJAX auto-runner.</p>
             <div id="csmcr-job-status"><?php CSMCR_Jobs::render_status(); ?></div>
             <p>
                 <button type="button" class="button button-primary" id="csmcr-auto-run">Auto-run active job</button>
                 <button type="button" class="button" id="csmcr-stop-auto-run">Stop auto-runner</button>
                 <span id="csmcr-auto-note" style="margin-left:8px"></span>
             </p>
             <?php self::button('job_start_pull_db', 'Start job: Pull DB', 'button button-primary'); ?>
             <?php self::button('job_start_push_db', 'Start job: Push DB'); ?>
             <?php self::button('job_start_pull_uploads', 'Start job: Pull media'); ?>
             <?php self::button('job_start_push_uploads', 'Start job: Push media'); ?>
             <?php self::button('job_start_pull_themes', 'Start job: Pull themes'); ?>
             <?php self::button('job_start_push_themes', 'Start job: Push themes'); ?>
             <?php self::button('job_start_pull_plugins', 'Start job: Pull plugins'); ?>
             <?php self::button('job_start_push_plugins', 'Start job: Push plugins'); ?>
             <br>
             <?php self::button('job_step', 'Run next job step', 'button button-primary'); ?>
             <?php self::button('bg_enable', 'Enable background runner'); ?>
             <?php self::button('bg_disable', 'Disable background runner'); ?>
             <?php self::button('job_cancel', 'Cancel active job'); ?>
+            <?php self::button('job_retry_failed', __('Retry failed job', 'cloud-site-mover-clean-room')); ?>
             <p><strong>Background runner:</strong> <?php echo !empty($o['background_enabled']) ? 'Enabled' : 'Disabled'; ?>. Next scheduled run: <?php echo esc_html(CSMCR_Background::next_run_label()); ?></p>
 
             <h2>Profiles</h2>
             <form method="post" style="display:inline-block;margin-right:10px">
                 <?php wp_nonce_field('csmcr_action'); ?>
                 <input type="hidden" name="csmcr_action" value="save_profile" />
                 <input name="profile_name" placeholder="profile-name" />
                 <?php submit_button('Save profile', 'secondary', 'submit', false); ?>
             </form>
             <form method="post" style="display:inline-block">
                 <?php wp_nonce_field('csmcr_action'); ?>
                 <input type="hidden" name="csmcr_action" value="load_profile" />
                 <select name="profile_name"><?php foreach ($profiles as $name => $_) { echo '<option value="'.esc_attr($name).'">'.esc_html($name).'</option>'; } ?></select>
                 <?php submit_button('Load profile', 'secondary', 'submit', false); ?>
             </form>
 
             <h2>WP-CLI</h2>
             <pre>wp csmcr ping
 wp csmcr dry-run
 wp csmcr pull-db --yes
 wp csmcr push-db --yes
 wp csmcr pull-files --scope=uploads
 wp csmcr push-files --scope=themes
 wp csmcr profile save staging
 wp csmcr profile load staging
 wp csmcr multisite-plan
 wp csmcr job start pull-db
 wp csmcr job step
 wp csmcr job status
 wp csmcr job run-until-complete --max-steps=500
+wp csmcr job retry-failed
+wp csmcr secret show
+wp csmcr secret rotate
+wp csmcr security status
+wp csmcr security check --strict
 wp csmcr background enable
 wp csmcr background disable
 wp csmcr background status
 wp csmcr preflight
 wp csmcr manifest
 wp csmcr handoff-plan
 wp csmcr multisite-map
 wp csmcr multisite-simulate
 wp csmcr package-manifest
 wp csmcr cleanup
 wp csmcr job cancel</pre>
 
             <h2>Recent migration jobs</h2>
             <textarea readonly class="large-text code" rows="8"><?php echo esc_textarea(implode("\n", CSMCR_Plugin::jobs())); ?></textarea>
 
             <h2>Reports</h2>
             <?php CSMCR_Reports::render_list(); ?>
 
             <h2>DB Backups / Rollback Points</h2>
             <?php CSMCR_Backup::render_list(); ?>
 
             <h2>Last log</h2>
             <textarea readonly class="large-text code" rows="14"><?php echo esc_textarea($o['last_log']); ?></textarea>
             <script>
             (function(){
@@ -381,140 +348,319 @@ wp csmcr job cancel</pre>
         header('Content-Type: application/sql; charset=utf-8');
         header('Content-Disposition: attachment; filename="' . $file . '"');
         readfile($path);
         exit;
     }
     public static function handle_actions() {
         if (empty($_POST['csmcr_action']) || !current_user_can('manage_options')) { return; }
         $action = sanitize_key($_POST['csmcr_action']);
         try {
             if ($action === 'save') {
                 check_admin_referer('csmcr_save');
                 self::save_opts([
                     'remote_url' => esc_url_raw(wp_unslash($_POST['remote_url'] ?? '')),
                     'remote_secret' => sanitize_text_field(wp_unslash($_POST['remote_secret'] ?? '')),
                     'search' => sanitize_text_field(wp_unslash($_POST['search'] ?? '')),
                     'replace' => sanitize_text_field(wp_unslash($_POST['replace'] ?? '')),
                     'db_batch' => max(50, (int)($_POST['db_batch'] ?? 500)),
                     'file_batch' => max(1, (int)($_POST['file_batch'] ?? 30)),
                     'chunk_bytes' => max(65536, (int)($_POST['chunk_bytes'] ?? 524288)),
                     'exclude_tables' => sanitize_textarea_field(wp_unslash($_POST['exclude_tables'] ?? '')),
                     'exclude_paths' => sanitize_textarea_field(wp_unslash($_POST['exclude_paths'] ?? '')),
                     'allow_db_write' => !empty($_POST['allow_db_write']) ? 1 : 0,
                     'allow_file_write' => !empty($_POST['allow_file_write']) ? 1 : 0,
                     'allow_theme_plugin_write' => !empty($_POST['allow_theme_plugin_write']) ? 1 : 0,
                     'allow_multisite_beta' => !empty($_POST['allow_multisite_beta']) ? 1 : 0,
+                    'allow_legacy_auth' => !empty($_POST['allow_legacy_auth']) ? 1 : 0,
+                    'enforce_https' => !empty($_POST['enforce_https']) ? 1 : 0,
+                    'ip_allowlist' => sanitize_textarea_field(wp_unslash($_POST['ip_allowlist'] ?? '')), 
                     'auto_backup_db' => !empty($_POST['auto_backup_db']) ? 1 : 0,
                     'skip_same_files' => !empty($_POST['skip_same_files']) ? 1 : 0,
                     'use_file_hash' => !empty($_POST['use_file_hash']) ? 1 : 0,
                     'manifest_include_hashes' => !empty($_POST['manifest_include_hashes']) ? 1 : 0,
                     'ajax_step_delay_ms' => max(250, (int)($_POST['ajax_step_delay_ms'] ?? 900)),
                     'background_enabled' => !empty($_POST['background_enabled']) ? 1 : 0,
                     'background_delay_seconds' => max(15, (int)($_POST['background_delay_seconds'] ?? 45)),
                     'background_max_steps' => max(1, min(10, (int)($_POST['background_max_steps'] ?? 1))),
+                    'max_step_retries' => max(0, min(10, (int)($_POST['max_step_retries'] ?? 3))),
+                    'auth_max_skew_seconds' => max(30, min(3600, (int)($_POST['auth_max_skew_seconds'] ?? 300))),
+                    'auth_rate_limit_per_minute' => max(10, min(5000, (int)($_POST['auth_rate_limit_per_minute'] ?? 120))),
+                    'job_step_lock_timeout_seconds' => max(15, min(1800, (int)($_POST['job_step_lock_timeout_seconds'] ?? 120))),
                     'max_reports' => max(1, min(100, (int)($_POST['max_reports'] ?? 20))),
                     'max_backups' => max(1, min(100, (int)($_POST['max_backups'] ?? 10))),
                     'multisite_source_blog_id' => max(1, (int)($_POST['multisite_source_blog_id'] ?? 1)),
                     'multisite_target_blog_id' => max(1, (int)($_POST['multisite_target_blog_id'] ?? 1)),
                     'package_scopes' => sanitize_text_field(wp_unslash($_POST['package_scopes'] ?? 'uploads,themes,plugins')),
                 ]);
+                $raw_allow = (string)($_POST['ip_allowlist'] ?? '');
+                $valid_allow = self::ip_allowlist_values((string)wp_unslash($raw_allow));
+                $total_allow = count(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)wp_unslash($raw_allow)))));
+                if ($total_allow > count($valid_allow)) {
+                    self::log('Warning: Some IP allowlist entries were ignored as invalid. Valid: ' . count($valid_allow) . ' / Total: ' . $total_allow);
+                }
                 self::log('Settings saved.');
             } else {
                 check_admin_referer('csmcr_action');
                 if ($action === 'ping') { $r = CSMCR_HTTP::remote('ping'); self::log('Remote ping OK: ' . wp_json_encode($r)); }
+                if ($action === 'rotate_secret') { self::save_opts(['secret' => wp_generate_password(56, false, false)]); self::log('Shared secret rotated.'); }
                 if ($action === 'dry_run') { CSMCR_Migrator::dry_run(); }
                 if ($action === 'preflight') { CSMCR_Preflight::run(true); }
+                if ($action === 'security_check') {
+                    $snapshot = self::security_status_snapshot();
+                    $issues = self::security_policy_issues($snapshot);
+                    self::add_job(sprintf(__('Security check %1$s. Issues=%2$d', 'cloud-site-mover-clean-room'), empty($issues) ? __('passed', 'cloud-site-mover-clean-room') : __('warning', 'cloud-site-mover-clean-room'), count($issues)));
+                    self::log(__('Security check snapshot:', 'cloud-site-mover-clean-room') . ' ' . wp_json_encode(['ok'=>empty($issues), 'issues'=>$issues, 'snapshot'=>$snapshot]));
+                }
                 if ($action === 'cleanup_temp') { CSMCR_Maintenance::cleanup(true); }
                 if ($action === 'backup_db') { CSMCR_Backup::create_db_backup('manual'); }
                 if ($action === 'create_manifest') { CSMCR_Package::create_manifest_report(true); }
                 if ($action === 'create_handoff_plan') { CSMCR_Package::create_handoff_plan(true); }
                 if ($action === 'create_multisite_map') { CSMCR_Package::create_multisite_map_report(true); }
                 if ($action === 'create_multisite_simulation') { CSMCR_Simulation::create_multisite_simulation_report(true); }
                 if ($action === 'create_package_manifest') { CSMCR_Simulation::create_package_manifest_report(true); }
                 if ($action === 'pull_db') { CSMCR_Migrator::pull_db(); }
                 if ($action === 'push_db') { CSMCR_Migrator::push_db(); }
                 if ($action === 'pull_uploads') { CSMCR_Migrator::pull_files('uploads'); }
                 if ($action === 'push_uploads') { CSMCR_Migrator::push_files('uploads'); }
                 if ($action === 'pull_themes') { CSMCR_Migrator::pull_files('themes'); }
                 if ($action === 'push_themes') { CSMCR_Migrator::push_files('themes'); }
                 if ($action === 'pull_plugins') { CSMCR_Migrator::pull_files('plugins'); }
                 if ($action === 'push_plugins') { CSMCR_Migrator::push_files('plugins'); }
                 if ($action === 'reset_cursors') { self::save_opts(['uploads_cursor'=>0,'themes_cursor'=>0,'plugins_cursor'=>0]); self::log('File cursors reset.'); }
                 if (strpos($action, 'job_start_') === 0) { CSMCR_Jobs::start(substr($action, 10)); }
                 if ($action === 'job_step') { CSMCR_Jobs::step(); }
                 if ($action === 'bg_enable') { CSMCR_Background::enable(); }
                 if ($action === 'bg_disable') { CSMCR_Background::disable(); }
                 if ($action === 'job_cancel') { CSMCR_Jobs::cancel(); }
+                if ($action === 'job_retry_failed') { CSMCR_Jobs::retry_failed(); }
                 if ($action === 'save_profile') { self::save_profile($_POST['profile_name'] ?? ''); }
                 if ($action === 'load_profile') { self::load_profile($_POST['profile_name'] ?? ''); }
             }
         } catch (Throwable $e) { self::log('ERROR: ' . $e->getMessage()); }
         wp_safe_redirect(admin_url('tools.php?page=cloud-site-mover'));
         exit;
     }
 
     public static function rest_routes() {
         $routes = [
             ['GET','/ping',[__CLASS__,'api_ping']],
+            ['GET','/security/status',[__CLASS__,'api_security_status']],
+            ['GET','/security/check',[__CLASS__,'api_security_check']],
             ['GET','/preflight',['CSMCR_API','preflight']],
             ['GET','/db/tables',['CSMCR_API','db_tables']],
             ['GET','/db/schema',['CSMCR_API','db_schema']],
             ['GET','/db/rows',['CSMCR_API','db_rows']],
             ['POST','/db/write-table',['CSMCR_API','db_write_table']],
             ['POST','/db/backup',['CSMCR_API','db_backup']],
             ['GET','/files/list',['CSMCR_API','files_list']],
             ['GET','/files/manifest',['CSMCR_API','files_manifest']],
             ['GET','/files/chunk',['CSMCR_API','file_chunk']],
             ['POST','/files/write',['CSMCR_API','file_write']],
             ['GET','/multisite/plan',['CSMCR_API','multisite_plan']],
             ['GET','/site/manifest',['CSMCR_Package','rest_manifest']],
             ['GET','/multisite/map',['CSMCR_Package','rest_multisite_map']],
             ['GET','/multisite/simulation',['CSMCR_Simulation','rest_multisite_simulation']],
             ['GET','/package/manifest',['CSMCR_Simulation','rest_package_manifest']],
         ];
         foreach ($routes as $r) { register_rest_route(CSMCR_NS, $r[1], ['methods'=>$r[0], 'callback'=>$r[2], 'permission_callback'=>[__CLASS__,'auth']]); }
     }
 
+    private static function body_hash_from_request($request) {
+        $body = (string)$request->get_body();
+        return hash('sha256', $body);
+    }
+
+    private static function signature_payload($method, $route, $ts, $body_hash) {
+        return strtoupper((string)$method) . "\n" . (string)$route . "\n" . (string)$ts . "\n" . (string)$body_hash;
+    }
+
+    public static function compute_signature($secret, $method, $route, $ts, $body_hash) {
+        return hash_hmac('sha256', self::signature_payload($method, $route, $ts, $body_hash), (string)$secret);
+    }
+
+    private static function remote_client_ip() {
+        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)wp_unslash($_SERVER['REMOTE_ADDR']) : 'unknown';
+        return preg_replace('/[^a-fA-F0-9:\.]/', '', $ip);
+    }
+
+    private static function allow_auth_attempt($opts) {
+        $ip = self::remote_client_ip();
+        $limit = max(10, (int)($opts['auth_rate_limit_per_minute'] ?? 120));
+        $bucket = 'csmcr_auth_rl_' . md5($ip . '|' . gmdate('YmdHi'));
+        $count = (int)get_transient($bucket);
+        if ($count >= $limit) { return false; }
+        set_transient($bucket, $count + 1, 70);
+        return true;
+    }
+
+
+    public static function ip_allowlist_values($text) {
+        $lines = preg_split('/\r\n|\r|\n/', (string)$text);
+        $out = [];
+        foreach ((array)$lines as $line) {
+            $v = trim((string)$line);
+            if ($v === '') { continue; }
+            if (strpos($v, '/') !== false) {
+                [$base, $mask] = array_pad(explode('/', $v, 2), 2, '');
+                if (filter_var($base, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && ctype_digit((string)$mask) && (int)$mask >= 0 && (int)$mask <= 32) {
+                    $out[] = $base . '/' . (int)$mask;
+                }
+                continue;
+            }
+            if (filter_var($v, FILTER_VALIDATE_IP)) { $out[] = $v; }
+        }
+        return array_values(array_unique($out));
+    }
+
+    private static function ip_in_cidr_v4($ip, $cidr) {
+        [$net, $bits] = array_pad(explode('/', (string)$cidr, 2), 2, '');
+        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) { return false; }
+        if (!filter_var($net, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) { return false; }
+        $bits = (int)$bits;
+        if ($bits < 0 || $bits > 32) { return false; }
+        $ip_long = ip2long($ip);
+        $net_long = ip2long($net);
+        if ($ip_long === false || $net_long === false) { return false; }
+        $mask = $bits === 0 ? 0 : (-1 << (32 - $bits));
+        return (($ip_long & $mask) === ($net_long & $mask));
+    }
+
+    private static function is_remote_ip_allowed($opts) {
+        $allow = self::ip_allowlist_values((string)($opts['ip_allowlist'] ?? ''));
+        if (empty($allow)) { return true; }
+        $ip = self::remote_client_ip();
+        foreach ($allow as $entry) {
+            if (strpos($entry, '/') !== false) {
+                if (self::ip_in_cidr_v4($ip, $entry)) { return true; }
+                continue;
+            }
+            if (hash_equals((string)$entry, (string)$ip)) { return true; }
+        }
+        return false;
+    }
+
+    private static function audit_remote_auth($code, $message) {
+        $ip = self::remote_client_ip();
+        $key = 'csmcr_audit_' . md5((string)$code . '|' . (string)$message . '|' . (string)$ip);
+        if (get_transient($key)) { return; }
+        set_transient($key, 1, 60);
+        self::add_job('AUTH ' . strtoupper((string)$code) . ': ' . $message . ' [ip=' . $ip . ']');
+    }
+
     public static function auth($request) {
         $o = self::opts();
+        if (!self::is_remote_ip_allowed($o)) { self::audit_remote_auth('deny', 'IP not in allowlist.'); return false; }
+        if (!self::allow_auth_attempt($o)) { self::audit_remote_auth('deny', 'Rate limit exceeded.'); return false; }
         $secret = $request->get_header('x-csmcr-secret');
-        return $secret && hash_equals((string)$o['secret'], (string)$secret);
+        if (!$secret || !hash_equals((string)$o['secret'], (string)$secret)) { self::audit_remote_auth('deny', 'Secret mismatch.'); return false; }
+
+        $ts = (int)$request->get_header('x-csmcr-ts');
+        $sig = (string)$request->get_header('x-csmcr-signature');
+        $hash = (string)$request->get_header('x-csmcr-body-hash');
+        if ($ts <= 0 || !$sig || !$hash) {
+            if (!empty($o['allow_legacy_auth'])) { self::audit_remote_auth('legacy', __('Accepted legacy secret-only auth.', 'cloud-site-mover-clean-room')); return true; }
+            self::audit_remote_auth('deny', 'Missing signed auth headers.');
+            return false;
+        }
+        $max_skew = max(30, (int)($o['auth_max_skew_seconds'] ?? 300));
+        if (abs(time() - $ts) > $max_skew) { self::audit_remote_auth('deny', 'Expired signed request.'); return false; }
+
+        $route = isset($_SERVER['REQUEST_URI']) ? wp_parse_url((string)wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH) : (string)$request->get_route();
+        $method = (string)$request->get_method();
+        $expected_hash = self::body_hash_from_request($request);
+        if (!hash_equals($expected_hash, $hash)) { self::audit_remote_auth('deny', 'Body hash mismatch.'); return false; }
+        $expected_sig = self::compute_signature($secret, $method, $route, $ts, $hash);
+        $ok = hash_equals($expected_sig, $sig);
+        if (!$ok) { self::audit_remote_auth('deny', 'Signature mismatch.'); }
+        return $ok;
+    }
+
+
+
+    public static function security_status_snapshot() {
+        $o = self::opts();
+        $allow = self::ip_allowlist_values((string)($o['ip_allowlist'] ?? ''));
+        return [
+            'policy_version' => 1,
+            'allow_legacy_auth' => (bool)!empty($o['allow_legacy_auth']),
+            'enforce_https' => (bool)!empty($o['enforce_https']),
+            'auth_max_skew_seconds' => (int)($o['auth_max_skew_seconds'] ?? 300),
+            'auth_rate_limit_per_minute' => (int)($o['auth_rate_limit_per_minute'] ?? 120),
+            'job_step_lock_timeout_seconds' => (int)($o['job_step_lock_timeout_seconds'] ?? 120),
+            'ip_allowlist_count' => count($allow),
+            'ip_allowlist_entries' => array_values($allow),
+        ];
+    }
+
+
+    public static function security_policy_issues($snapshot=null) {
+        $s = is_array($snapshot) ? $snapshot : self::security_status_snapshot();
+        $issues = [];
+        if (!empty($s['allow_legacy_auth'])) { $issues[] = __('Legacy auth compatibility is enabled.', 'cloud-site-mover-clean-room'); }
+        if (empty($s['enforce_https'])) { $issues[] = __('HTTPS enforcement is disabled.', 'cloud-site-mover-clean-room'); }
+        if ((int)($s['ip_allowlist_count'] ?? 0) < 1) { $issues[] = __('IP/CIDR allowlist is empty.', 'cloud-site-mover-clean-room'); }
+        if ((int)($s['auth_max_skew_seconds'] ?? 300) > 600) { $issues[] = __('Auth skew window is high (>600s).', 'cloud-site-mover-clean-room'); }
+        if ((int)($s['auth_rate_limit_per_minute'] ?? 120) > 1000) { $issues[] = __('Auth rate limit is high (>1000/min).', 'cloud-site-mover-clean-room'); }
+        return $issues;
+    }
+
+    public static function api_security_status() {
+        return self::security_status_snapshot();
+    }
+
+
+    public static function api_security_check() {
+        $snapshot = self::security_status_snapshot();
+        $issues = self::security_policy_issues($snapshot);
+        return ['ok'=>empty($issues), 'issues'=>$issues, 'snapshot'=>$snapshot];
     }
 
     public static function api_ping() {
         global $wpdb;
         return ['ok'=>true,'site'=>site_url(),'home'=>home_url(),'version'=>CSMCR_VERSION,'multisite'=>is_multisite(),'prefix'=>$wpdb->prefix];
     }
 }
 
 final class CSMCR_HTTP {
     public static function remote($path, $method='GET', $body=null) {
         $o = CSMCR_Plugin::opts();
-        if (empty($o['remote_url']) || empty($o['remote_secret'])) { throw new Exception('Remote URL and secret are required.'); }
+        if (empty($o['remote_url']) || empty($o['remote_secret'])) { throw new Exception(__('Remote URL and secret are required.', 'cloud-site-mover-clean-room')); }
         $url = trailingslashit($o['remote_url']) . 'wp-json/' . CSMCR_NS . '/' . ltrim($path, '/');
+        if (!empty($o['enforce_https']) && stripos((string)$o['remote_url'], 'https://') !== 0) { throw new Exception(__('Remote URL must use HTTPS when HTTPS enforcement is enabled.', 'cloud-site-mover-clean-room')); }
         $args = ['method'=>$method, 'timeout'=>120, 'headers'=>['x-csmcr-secret'=>$o['remote_secret']]];
-        if ($body !== null) { $args['headers']['content-type'] = 'application/json'; $args['body'] = wp_json_encode($body); }
+        $json_body = '';
+        if ($body !== null) {
+            $json_body = wp_json_encode($body);
+            $args['headers']['content-type'] = 'application/json';
+            $args['body'] = $json_body;
+        }
+        $ts = time();
+        $body_hash = hash('sha256', (string)$json_body);
+        $route = wp_parse_url((string)$url, PHP_URL_PATH);
+        $args['headers']['x-csmcr-ts'] = (string)$ts;
+        $args['headers']['x-csmcr-body-hash'] = $body_hash;
+        $args['headers']['x-csmcr-signature'] = CSMCR_Plugin::compute_signature($o['remote_secret'], $method, $route, $ts, $body_hash);
         $res = wp_remote_request($url, $args);
         if (is_wp_error($res)) { throw new Exception($res->get_error_message()); }
         $code = wp_remote_retrieve_response_code($res);
         $data = json_decode(wp_remote_retrieve_body($res), true);
         if ($code < 200 || $code >= 300) { throw new Exception('Remote HTTP ' . $code . ': ' . wp_remote_retrieve_body($res)); }
         return is_array($data) ? $data : [];
     }
 }
 
 final class CSMCR_Util {
     public static function lines($text) { return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)$text)))); }
     public static function matches_any($value, $patterns) {
         $value = ltrim(str_replace('\\','/', (string)$value), '/');
         foreach ((array)$patterns as $p) {
             $p = str_replace('\\','/', trim((string)$p));
             if ($p === '') { continue; }
             if (fnmatch($p, $value) || fnmatch($p, basename($value)) || stripos($value, $p) === 0) { return true; }
         }
         return false;
     }
     public static function recursive_replace($value, $search, $replace) {
         if ($search === '' || $search === $replace) { return $value; }
         if (is_string($value)) {
             $maybe = maybe_unserialize($value);
             if ($maybe !== $value) { return maybe_serialize(self::recursive_replace($maybe, $search, $replace)); }
@@ -591,50 +737,60 @@ final class CSMCR_Preflight {
     public static function run($log=false) {
         global $wpdb;
         $o = CSMCR_Plugin::opts();
         $checks = [];
         $add = function($name, $status, $detail='') use (&$checks) { $checks[] = ['name'=>$name, 'status'=>$status, 'detail'=>(string)$detail]; };
         $add('WordPress version', 'ok', get_bloginfo('version'));
         $add('Plugin version', 'ok', CSMCR_VERSION);
         $add('PHP version', version_compare(PHP_VERSION, '7.4', '>=') ? 'ok' : 'warning', PHP_VERSION);
         $add('JSON extension', function_exists('json_encode') ? 'ok' : 'fail', function_exists('json_encode') ? 'available' : 'missing');
         $add('OpenSSL extension', extension_loaded('openssl') ? 'ok' : 'warning', extension_loaded('openssl') ? 'available' : 'missing');
         $add('Memory limit', 'info', ini_get('memory_limit'));
         $add('Max execution time', 'info', ini_get('max_execution_time'));
         $add('DB tables visible', count(CSMCR_API::db_tables()['tables'] ?? []) > 0 ? 'ok' : 'fail', (string)count(CSMCR_API::db_tables()['tables'] ?? []));
         foreach (['uploads','themes','plugins'] as $scope) {
             try {
                 $base = CSMCR_Files::base($scope);
                 $add($scope . ' folder exists', is_dir($base) ? 'ok' : 'fail', $base);
                 $add($scope . ' folder writable', is_writable($base) ? 'ok' : 'warning', $base);
             } catch (Throwable $e) { $add($scope . ' folder check', 'fail', $e->getMessage()); }
         }
         $free = @disk_free_space(WP_CONTENT_DIR);
         if ($free !== false) { $add('Free disk space', $free > 100 * 1024 * 1024 ? 'ok' : 'warning', size_format($free)); }
         $add('DB write permission flag', !empty($o['allow_db_write']) ? 'warning' : 'ok', !empty($o['allow_db_write']) ? 'enabled on this site' : 'disabled');
         $add('Uploads write permission flag', !empty($o['allow_file_write']) ? 'warning' : 'ok', !empty($o['allow_file_write']) ? 'enabled on this site' : 'disabled');
         $add('Themes/plugins write permission flag', !empty($o['allow_theme_plugin_write']) ? 'warning' : 'ok', !empty($o['allow_theme_plugin_write']) ? 'enabled on this site' : 'disabled');
+
+        $security = CSMCR_Plugin::security_status_snapshot();
+        $add(__('Signed auth mode', 'cloud-site-mover-clean-room'), !empty($security['allow_legacy_auth']) ? 'warning' : 'ok', !empty($security['allow_legacy_auth']) ? __('legacy compatibility enabled', 'cloud-site-mover-clean-room') : __('legacy compatibility disabled', 'cloud-site-mover-clean-room'));
+        $add(__('HTTPS enforcement for remote', 'cloud-site-mover-clean-room'), !empty($security['enforce_https']) ? 'ok' : 'warning', !empty($security['enforce_https']) ? __('enabled', 'cloud-site-mover-clean-room') : __('disabled', 'cloud-site-mover-clean-room'));
+        $add('Auth skew window', ((int)($security['auth_max_skew_seconds'] ?? 300) <= 600) ? 'ok' : 'warning', (string)($security['auth_max_skew_seconds'] ?? 300) . ' seconds');
+        $add('Auth rate limit per minute', ((int)($security['auth_rate_limit_per_minute'] ?? 120) >= 60) ? 'ok' : 'warning', (string)($security['auth_rate_limit_per_minute'] ?? 120));
+        $add('IP/CIDR allowlist entries', ((int)($security['ip_allowlist_count'] ?? 0) > 0) ? 'ok' : 'warning', ((int)($security['ip_allowlist_count'] ?? 0) > 0) ? (string)($security['ip_allowlist_count']) : 'none configured');
+        $policy_issues = CSMCR_Plugin::security_policy_issues($security);
+        $add('Security policy check', empty($policy_issues) ? 'ok' : 'warning', empty($policy_issues) ? 'pass' : implode(' | ', $policy_issues));
+
         if (!empty($o['remote_url']) && !empty($o['remote_secret'])) {
             try {
                 $remote = CSMCR_HTTP::remote('ping');
                 $add('Remote ping', 'ok', ($remote['site'] ?? 'remote') . ' / plugin ' . ($remote['version'] ?? 'unknown'));
                 if (!empty($remote['prefix']) && $remote['prefix'] !== $wpdb->prefix) { $add('Table prefix comparison', 'warning', 'local=' . $wpdb->prefix . ', remote=' . $remote['prefix']); }
                 else { $add('Table prefix comparison', 'ok', 'local=' . $wpdb->prefix); }
             } catch (Throwable $e) { $add('Remote ping', 'fail', $e->getMessage()); }
         } else { $add('Remote settings', 'warning', 'Remote URL/secret not configured.'); }
         $fail = count(array_filter($checks, function($c){ return $c['status'] === 'fail'; }));
         $warning = count(array_filter($checks, function($c){ return $c['status'] === 'warning'; }));
         $result = ['ok'=>$fail === 0, 'failures'=>$fail, 'warnings'=>$warning, 'checks'=>$checks, 'generated_at'=>gmdate('c')];
         if ($log) {
             CSMCR_Plugin::log('Preflight complete. Failures=' . $fail . ', warnings=' . $warning . '. ' . wp_json_encode($checks));
             CSMCR_Plugin::add_job('Preflight complete. Failures=' . $fail . ', warnings=' . $warning . '.');
         }
         return $result;
     }
 }
 
 final class CSMCR_Maintenance {
     public static function cleanup($log=false) {
         $o = CSMCR_Plugin::opts();
         $removed = [];
         $removed = array_merge($removed, self::prune(CSMCR_Plugin::report_dir(), 'migration-report-*.json', max(1, (int)$o['max_reports'])));
         $removed = array_merge($removed, self::prune(CSMCR_Backup::dir(), 'db-*.sql', max(1, (int)$o['max_backups'])));
@@ -1233,77 +1389,115 @@ final class CSMCR_Jobs {
             'push_uploads'=>['type'=>'files','direction'=>'push','scope'=>'uploads'],
             'pull_themes'=>['type'=>'files','direction'=>'pull','scope'=>'themes'],
             'push_themes'=>['type'=>'files','direction'=>'push','scope'=>'themes'],
             'pull_plugins'=>['type'=>'files','direction'=>'pull','scope'=>'plugins'],
             'push_plugins'=>['type'=>'files','direction'=>'push','scope'=>'plugins'],
         ];
         if (empty($map[$kind])) { throw new Exception('Unknown job type: ' . $kind); }
         $o = CSMCR_Plugin::opts();
         if ($map[$kind]['type'] === 'db') {
             if ($map[$kind]['direction'] === 'pull' && !empty($o['auto_backup_db'])) { CSMCR_Backup::create_db_backup('before-job-pull'); }
             if ($map[$kind]['direction'] === 'push' && !empty($o['auto_backup_db'])) { CSMCR_HTTP::remote('db/backup', 'POST', ['reason'=>'before-job-push']); }
         }
         $job = array_merge($map[$kind], [
             'id'=>substr(wp_hash(uniqid('csmcr', true)), 0, 12),
             'kind'=>$kind,
             'status'=>'running',
             'table_index'=>0,
             'offset'=>0,
             'cursor'=>0,
             'total'=>0,
             'done'=>0,
             'tables'=>[],
             'started_at'=>gmdate('c'),
             'updated_at'=>gmdate('c'),
             'messages'=>[],
+            'retry_count'=>0,
+            'last_error'=>'',
         ]);
         CSMCR_Plugin::save_active_job($job);
         CSMCR_Background::maybe_schedule();
         CSMCR_Plugin::log('Started resumable job: ' . $kind);
         CSMCR_Plugin::add_job('Started resumable job: ' . $kind);
     }
 
     public static function cancel() {
         $job = CSMCR_Plugin::active_job();
         if ($job) { CSMCR_Plugin::add_job('Cancelled job: ' . ($job['kind'] ?? 'unknown')); }
         CSMCR_Background::unschedule();
         CSMCR_Plugin::clear_active_job();
         CSMCR_Plugin::log('Active job cancelled.');
     }
 
     public static function step() {
         $job = CSMCR_Plugin::active_job();
         if (empty($job) || ($job['status'] ?? '') !== 'running') { throw new Exception('No running job.'); }
-        if (($job['type'] ?? '') === 'db') { $job = self::step_db($job); }
-        elseif (($job['type'] ?? '') === 'files') { $job = self::step_files($job); }
-        else { throw new Exception('Invalid job state.'); }
+        $o = CSMCR_Plugin::opts();
+        $max_retries = max(0, (int)($o['max_step_retries'] ?? 3));
+        $lock_ttl = max(15, (int)($o['job_step_lock_timeout_seconds'] ?? 120));
+        if (get_transient(CSMCR_JOB_STEP_LOCK)) { throw new Exception('Job step is locked by another runner.'); }
+        set_transient(CSMCR_JOB_STEP_LOCK, 1, $lock_ttl);
+        try {
+            if (($job['type'] ?? '') === 'db') { $job = self::step_db($job); }
+            elseif (($job['type'] ?? '') === 'files') { $job = self::step_files($job); }
+            else { throw new Exception('Invalid job state.'); }
+            $job['retry_count'] = 0;
+            $job['last_error'] = '';
+        } catch (Throwable $e) {
+            $job['retry_count'] = (int)($job['retry_count'] ?? 0) + 1;
+            $job['last_error'] = $e->getMessage();
+            $job['messages'][] = 'Step failed (' . $job['retry_count'] . '/' . $max_retries . '): ' . $e->getMessage();
+            if ($job['retry_count'] > $max_retries) {
+                $job['status'] = 'failed';
+                CSMCR_Background::unschedule();
+                $job = self::finalize_failed($job);
+                CSMCR_Plugin::add_job('Job failed after retries: ' . ($job['kind'] ?? 'unknown'));
+            }
+        } finally {
+            delete_transient(CSMCR_JOB_STEP_LOCK);
+        }
         CSMCR_Plugin::save_active_job($job);
         if (($job['status'] ?? '') === 'complete') { self::finalize($job); }
-        else { CSMCR_Background::maybe_schedule(); }
+        elseif (($job['status'] ?? '') === 'running') { CSMCR_Background::maybe_schedule(); }
         return CSMCR_Plugin::active_job();
     }
 
+
+    public static function retry_failed() {
+        $job = CSMCR_Plugin::active_job();
+        if (empty($job)) { throw new Exception('No active job to retry.'); }
+        if (($job['status'] ?? '') !== 'failed') { throw new Exception('Active job is not failed.'); }
+        $job['status'] = 'running';
+        $job['retry_count'] = 0;
+        $job['last_error'] = '';
+        unset($job['failed_report'], $job['failed_at']);
+        $job['messages'][] = 'Retry requested by admin.';
+        CSMCR_Plugin::save_active_job($job);
+        CSMCR_Background::maybe_schedule(true);
+        CSMCR_Plugin::add_job('Retrying failed job: ' . ($job['kind'] ?? 'unknown'));
+    }
+
     public static function status_html() {
         ob_start();
         self::render_status();
         return ob_get_clean();
     }
 
     private static function step_db($job) {
         global $wpdb;
         $o = CSMCR_Plugin::opts();
         if (empty($job['tables'])) {
             $job['tables'] = ($job['direction'] === 'pull') ? (CSMCR_HTTP::remote('db/tables')['tables'] ?? []) : (CSMCR_API::db_tables()['tables'] ?? []);
             $job['total'] = count($job['tables']);
             $job['messages'][] = 'Loaded table list: ' . $job['total'];
         }
         $tables = $job['tables'];
         $idx = (int)$job['table_index'];
         if ($idx >= count($tables)) { $job['status'] = 'complete'; $job['done'] = $job['total']; return $job; }
         $table = $tables[$idx];
         $offset = (int)$job['offset'];
         $limit = (int)$o['db_batch'];
         if ($job['direction'] === 'pull') {
             $schema = CSMCR_HTTP::remote('db/schema?table=' . rawurlencode($table))['create'] ?? '';
             $res = CSMCR_HTTP::remote('db/rows?table=' . rawurlencode($table) . '&offset=' . $offset . '&limit=' . $limit);
             $req = new WP_REST_Request('POST', '/');
             $req->set_body_params(['table'=>$table,'create'=>$schema,'rows'=>$res['rows'] ?? [],'first'=>$offset===0,'search'=>$o['search'],'replace'=>$o['replace']]);
@@ -1357,56 +1551,79 @@ final class CSMCR_Jobs {
         $job['done'] = min($job['cursor'], count($items));
         $job['messages'][] = ucfirst($direction) . ' ' . $scope . ' batch: moved ' . $moved . ', skipped ' . $skipped . ', progress ' . $job['done'] . '/' . count($items);
         if ($job['cursor'] >= count($items)) { $job['status'] = 'complete'; }
         return $job;
     }
 
     public static function same_file_meta($a, $b) {
         if (!isset($a['size'], $b['size'])) { return false; }
         if ((int)$a['size'] !== (int)$b['size']) { return false; }
         if (!empty($a['sha1']) && !empty($b['sha1'])) { return hash_equals((string)$a['sha1'], (string)$b['sha1']); }
         return isset($a['mtime'], $b['mtime']) && (int)$a['mtime'] === (int)$b['mtime'];
     }
 
     public static function percent($job) {
         $total = max(1, (int)($job['total'] ?? 0));
         $done = max(0, (int)($job['done'] ?? 0));
         return min(100, round(($done / $total) * 100, 1));
     }
 
     public static function render_status() {
         $job = CSMCR_Plugin::active_job();
         if (!$job) { echo '<p><em>No active resumable job.</em></p>'; return; }
         $pct = self::percent($job);
         echo '<div style="max-width:760px;padding:12px;border:1px solid #ccd0d4;background:#fff;margin:8px 0">';
         echo '<p><strong>Active job:</strong> ' . esc_html($job['kind'] ?? '') . ' — ' . esc_html($job['status'] ?? '') . '</p>';
+        if (($job['status'] ?? '') === 'failed') {
+            echo '<p style="color:#b32d2e"><strong>' . esc_html__('Job failed.', 'cloud-site-mover-clean-room') . '</strong> ' . esc_html((string)($job['last_error'] ?? __('Unknown error', 'cloud-site-mover-clean-room'))) . '</p>'; 
+        }
         echo '<div style="height:16px;background:#eee;border-radius:10px;overflow:hidden"><div style="height:16px;width:' . esc_attr($pct) . '%;background:#2271b1"></div></div>';
         echo '<p>' . esc_html($pct) . '% — done ' . esc_html((string)($job['done'] ?? 0)) . ' / ' . esc_html((string)($job['total'] ?? 0)) . '</p>';
-        echo '<textarea readonly class="large-text code" rows="5">' . esc_textarea(implode("\\n", array_slice(array_reverse($job['messages'] ?? []), -8))) . '</textarea>';
+        echo '<p><strong>' . esc_html__('Retries:', 'cloud-site-mover-clean-room') . '</strong> ' . esc_html((string)($job['retry_count'] ?? 0));
+        if (!empty($job['last_error'])) { echo ' — <strong>' . esc_html__('Last error:', 'cloud-site-mover-clean-room') . '</strong> ' . esc_html((string)$job['last_error']); }
+        echo '</p>';
+        if (!empty($job['failed_report'])) {
+            $r = sanitize_file_name((string)$job['failed_report']);
+            $u = wp_nonce_url(admin_url('admin-post.php?action=csmcr_download_report&file=' . rawurlencode($r)), 'csmcr_download_report');
+            echo '<p><a class="button button-secondary" href="' . esc_url($u) . '">' . esc_html__('Download failed report', 'cloud-site-mover-clean-room') . '</a></p>';
+        }
+        echo '<textarea readonly class="large-text code" rows="5">' . esc_textarea(implode("\n", array_slice(array_reverse($job['messages'] ?? []), -8))) . '</textarea>';
         echo '</div>';
     }
 
+
+    private static function finalize_failed($job) {
+        $name = 'migration-report-' . gmdate('Ymd-His') . '-' . sanitize_key($job['kind'] ?? 'job') . '-failed.json';
+        $file = CSMCR_Plugin::report_dir() . $name;
+        $job['failed_at'] = gmdate('c');
+        $job['final_preflight'] = CSMCR_Preflight::run(false);
+        file_put_contents($file, wp_json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
+        $job['failed_report'] = $name;
+        CSMCR_Plugin::log('Failed resumable job. Report: ' . $name);
+        return $job;
+    }
+
     private static function finalize($job) {
         $name = 'migration-report-' . gmdate('Ymd-His') . '-' . sanitize_key($job['kind'] ?? 'job') . '.json';
         $file = CSMCR_Plugin::report_dir() . $name;
         $job['final_preflight'] = CSMCR_Preflight::run(false);
         file_put_contents($file, wp_json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
         CSMCR_Background::unschedule();
         CSMCR_Plugin::add_job('Completed job ' . ($job['kind'] ?? 'unknown') . '. Report: ' . $name);
         CSMCR_Plugin::log('Completed resumable job. Report: ' . $name);
         CSMCR_Plugin::save_active_job($job);
     }
 }
 
 
 final class CSMCR_Background {
     public static function enable() {
         CSMCR_Plugin::save_opts(['background_enabled'=>1]);
         self::maybe_schedule(true);
         CSMCR_Plugin::log('Background runner enabled.');
         CSMCR_Plugin::add_job('Background runner enabled.');
     }
 
     public static function disable() {
         CSMCR_Plugin::save_opts(['background_enabled'=>0]);
         self::unschedule();
         CSMCR_Plugin::log('Background runner disabled.');
@@ -1454,42 +1671,80 @@ final class CSMCR_Background {
         if (!empty($job) && ($job['status'] ?? '') === 'running') { self::maybe_schedule(true); }
     }
 
     public static function next_run_label() {
         $ts = wp_next_scheduled(CSMCR_CRON_HOOK);
         if (!$ts) { return 'not scheduled'; }
         return gmdate('Y-m-d H:i:s', $ts) . ' UTC';
     }
 }
 
 final class CSMCR_CLI {
     public static function register() { WP_CLI::add_command('csmcr', __CLASS__); }
     public function ping() { WP_CLI::line(wp_json_encode(CSMCR_HTTP::remote('ping'), JSON_PRETTY_PRINT)); }
     public function dry_run() { CSMCR_Migrator::dry_run(); WP_CLI::success('Dry run complete. Check Tools > Cloud Site Mover log.'); }
     public function backup_db() { CSMCR_Backup::create_db_backup('cli'); WP_CLI::success('DB backup created.'); }
     public function pull_db($args,$assoc) { if (empty($assoc['yes'])) { WP_CLI::confirm('Overwrite local database?'); } CSMCR_Migrator::pull_db(); WP_CLI::success('DB pull complete.'); }
     public function push_db($args,$assoc) { if (empty($assoc['yes'])) { WP_CLI::confirm('Overwrite remote database?'); } CSMCR_Migrator::push_db(); WP_CLI::success('DB push complete.'); }
     public function pull_files($args,$assoc) { $scope = $assoc['scope'] ?? 'uploads'; CSMCR_Migrator::pull_files($scope); WP_CLI::success('File pull batch complete for ' . $scope); }
     public function push_files($args,$assoc) { $scope = $assoc['scope'] ?? 'uploads'; CSMCR_Migrator::push_files($scope); WP_CLI::success('File push batch complete for ' . $scope); }
     public function reset_cursors() { CSMCR_Plugin::save_opts(['uploads_cursor'=>0,'themes_cursor'=>0,'plugins_cursor'=>0]); WP_CLI::success('Cursors reset.'); }
     public function profile($args,$assoc) { $verb=$args[0] ?? 'list'; if($verb==='list'){ WP_CLI::line(implode("\n", array_keys(CSMCR_Plugin::profiles()))); return; } $name=$args[1] ?? ''; if($verb==='save'){ CSMCR_Plugin::save_profile($name); WP_CLI::success('Saved profile.'); return; } if($verb==='load'){ CSMCR_Plugin::load_profile($name); WP_CLI::success('Loaded profile.'); return; } WP_CLI::error('Use: wp csmcr profile list|save <name>|load <name>'); }
     public function job($args, $assoc) {
         $verb = $args[0] ?? 'status';
         if ($verb === 'status') { WP_CLI::line(wp_json_encode(CSMCR_Plugin::active_job(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); return; }
         if ($verb === 'cancel') { CSMCR_Jobs::cancel(); WP_CLI::success('Active job cancelled.'); return; }
+        if ($verb === 'retry-failed') { CSMCR_Jobs::retry_failed(); WP_CLI::success('Failed job moved back to running state.'); return; }
         if ($verb === 'step') { CSMCR_Jobs::step(); WP_CLI::success('Job step completed.'); return; }
         if ($verb === 'run-until-complete') { $max = isset($assoc['max-steps']) ? (int)$assoc['max-steps'] : 500; for ($i=0; $i<$max; $i++) { $job=CSMCR_Plugin::active_job(); if (empty($job) || ($job['status'] ?? '') !== 'running') { WP_CLI::success('No running job, or job complete.'); return; } CSMCR_Jobs::step(); } WP_CLI::warning('Stopped at max steps.'); return; }
         if ($verb === 'start') { $kind = isset($args[1]) ? str_replace('-', '_', $args[1]) : ''; CSMCR_Jobs::start($kind); WP_CLI::success('Started job: ' . $kind); return; }
-        WP_CLI::error('Use: wp csmcr job status|start <pull-db|push-db|pull-uploads|push-uploads|pull-themes|push-themes|pull-plugins|push-plugins>|step|cancel');
+        WP_CLI::error('Use: wp csmcr job status|start <pull-db|push-db|pull-uploads|push-uploads|pull-themes|push-themes|pull-plugins|push-plugins>|step|cancel|retry-failed');
     }
     public function background($args, $assoc) { $verb = $args[0] ?? 'status'; if ($verb === 'enable') { CSMCR_Background::enable(); WP_CLI::success('Background runner enabled.'); return; } if ($verb === 'disable') { CSMCR_Background::disable(); WP_CLI::success('Background runner disabled.'); return; } if ($verb === 'status') { $o = CSMCR_Plugin::opts(); WP_CLI::line(wp_json_encode(['enabled'=>(bool)$o['background_enabled'], 'next_run'=>CSMCR_Background::next_run_label(), 'lock'=>(bool)get_transient(CSMCR_BG_LOCK)], JSON_PRETTY_PRINT)); return; } WP_CLI::error('Use: wp csmcr background status|enable|disable'); }
     public function preflight() { WP_CLI::line(wp_json_encode(CSMCR_Preflight::run(false), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); }
+
+    public function secret($args, $assoc) {
+        $verb = $args[0] ?? 'show';
+        if ($verb === 'show') {
+            $o = CSMCR_Plugin::opts();
+            WP_CLI::line(wp_json_encode(['masked' => CSMCR_Settings::mask_secret((string)$o['secret'])], JSON_PRETTY_PRINT));
+            return;
+        }
+        if ($verb === 'rotate') {
+            CSMCR_Plugin::save_opts(['secret' => wp_generate_password(56, false, false)]);
+            CSMCR_Plugin::log('Shared secret rotated via WP-CLI.');
+            WP_CLI::success('Shared secret rotated. Update remote peer secret accordingly.');
+            return;
+        }
+        WP_CLI::error('Use: wp csmcr secret show|rotate');
+    }
+
+
+    public function security($args, $assoc) {
+        $verb = $args[0] ?? 'status';
+        $s = CSMCR_Plugin::security_status_snapshot();
+        if ($verb === 'status') {
+            WP_CLI::line(wp_json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
+            return;
+        }
+        if ($verb === 'check') {
+            $issues = CSMCR_Plugin::security_policy_issues($s);
+            WP_CLI::line(wp_json_encode(['ok'=>empty($issues), 'issues'=>$issues, 'snapshot'=>$s], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
+            if (!empty($issues)) {
+                if (!empty($assoc['strict'])) { WP_CLI::error(__('Security check failed in strict mode.', 'cloud-site-mover-clean-room')); }
+                WP_CLI::warning(__('Security check reported configuration issues.', 'cloud-site-mover-clean-room'));
+            } else { WP_CLI::success(__('Security check passed.', 'cloud-site-mover-clean-room')); }
+            return;
+        }
+        WP_CLI::error('Use: wp csmcr security status|check [--strict]');
+    }
+
     public function cleanup() { $r = CSMCR_Maintenance::cleanup(true); WP_CLI::success('Cleanup complete. Removed ' . (int)$r['count'] . ' files.'); }
     public function manifest() { WP_CLI::line(wp_json_encode(CSMCR_Package::site_manifest(false), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); }
     public function handoff_plan() { $file = CSMCR_Package::create_handoff_plan(true); WP_CLI::success('Created handoff plan: ' . basename($file)); }
     public function multisite_map() { WP_CLI::line(wp_json_encode(CSMCR_Package::multisite_map(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); }
     public function multisite_simulate() { WP_CLI::line(wp_json_encode(CSMCR_Simulation::multisite_conversion_simulation(false), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); }
     public function package_manifest() { WP_CLI::line(wp_json_encode(CSMCR_Simulation::package_manifest(false), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); }
     public function multisite_plan() { WP_CLI::line(wp_json_encode(CSMCR_API::multisite_plan(), JSON_PRETTY_PRINT)); }
 }
 
-CSMCR_Plugin::init();
+CSMCR_Core_Plugin::init();
