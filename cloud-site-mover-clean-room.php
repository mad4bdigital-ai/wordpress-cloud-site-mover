<?php
/**
 * Plugin Name: Cloud Site Mover Clean Room
 * Description: Clean-room WordPress cloud migration helper for DB push/pull, media/theme/plugin sync, profiles, WP-CLI, and guarded multisite planning.
 * Version: 1.2.0
 * Author: Clean Room Build
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) { exit; }

define('CSMCR_VERSION', '1.2.0');
define('CSMCR_ACTIVE_JOB_OPT', 'csmcr_active_job');
define('CSMCR_OPT', 'csmcr_options');
define('CSMCR_PROFILE_OPT', 'csmcr_profiles');
define('CSMCR_JOBS_OPT', 'csmcr_jobs');
define('CSMCR_NS', 'cloud-site-mover/v1');
define('CSMCR_CRON_HOOK', 'csmcr_background_step');
define('CSMCR_BG_LOCK', 'csmcr_background_lock');
define('CSMCR_MAIN_FILE', __FILE__);

require_once __DIR__ . '/includes/Core/Loader.php';
CSMCR_Loader::require_core();
register_activation_hook(CSMCR_MAIN_FILE, ['CSMCR_Core_Plugin', 'on_activate']);
register_deactivation_hook(CSMCR_MAIN_FILE, ['CSMCR_Core_Plugin', 'on_deactivate']);

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

    public static function defaults() { return CSMCR_Settings::defaults(); }

    public static function opts() { return CSMCR_Settings::opts(); }

    public static function save_opts($new) { return CSMCR_Settings::save_opts((array)$new); }

    public static function profiles() { return CSMCR_Settings::profiles(); }

    public static function jobs() { return CSMCR_Jobs_Manager::jobs(); }

    public static function add_job($msg) { CSMCR_Jobs_Manager::add_job($msg); }

    public static function active_job() { return CSMCR_Jobs_Manager::get_active(); }

    public static function save_active_job($job) { return CSMCR_Jobs_Manager::save_active($job); }

    public static function clear_active_job() { CSMCR_Jobs_Manager::clear_active(); }

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

    public static function log($msg) { CSMCR_Logger::info($msg); }

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
                    <tr><th>Your shared secret</th><td>
                        <code><?php echo esc_html(CSMCR_Settings::mask_secret($o['secret'])); ?></code>
                        <details style="margin-top:6px"><summary>Reveal full secret</summary><code style="user-select:all"><?php echo esc_html($o['secret']); ?></code></details>
                    </td></tr>
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
                        <label><input type="checkbox" name="allow_legacy_auth" value="1" <?php checked($o['allow_legacy_auth']); ?> /> Allow legacy secret-only remote auth (compat mode)</label><br>
                        <label><input type="checkbox" name="auto_backup_db" value="1" <?php checked($o['auto_backup_db']); ?> /> Auto-create DB backup before pull/push overwrite</label><br>
                        <label><input type="checkbox" name="skip_same_files" value="1" <?php checked($o['skip_same_files']); ?> /> Skip files with same size and modified time</label><br>
                        <label><input type="checkbox" name="use_file_hash" value="1" <?php checked($o['use_file_hash']); ?> /> Add SHA1 hashes to file manifests when comparing files</label><br>
                        <label><input type="checkbox" name="manifest_include_hashes" value="1" <?php checked($o['manifest_include_hashes']); ?> /> Include SHA1 hashes in full-site manifest reports</label><br>
                        <label>AJAX auto-step delay <input type="number" min="250" max="10000" name="ajax_step_delay_ms" value="<?php echo esc_attr($o['ajax_step_delay_ms']); ?>" /> ms</label><br>
                        <label><input type="checkbox" name="background_enabled" value="1" <?php checked($o['background_enabled']); ?> /> Enable WP-Cron background runner</label><br>
                        <label>Background delay <input type="number" min="15" max="3600" name="background_delay_seconds" value="<?php echo esc_attr($o['background_delay_seconds']); ?>" /> seconds</label><br>
                        <label>Background max steps per run <input type="number" min="1" max="10" name="background_max_steps" value="<?php echo esc_attr($o['background_max_steps']); ?>" /></label><br>
                        <label>Keep latest reports <input type="number" min="1" max="100" name="max_reports" value="<?php echo esc_attr($o['max_reports']); ?>" /></label><br>
                        <label>Keep latest DB backups <input type="number" min="1" max="100" name="max_backups" value="<?php echo esc_attr($o['max_backups']); ?>" /></label><br>
                        <label>Multisite source blog ID <input type="number" min="1" max="999999" name="multisite_source_blog_id" value="<?php echo esc_attr($o['multisite_source_blog_id']); ?>" /></label><br>
                        <label>Multisite target blog ID <input type="number" min="1" max="999999" name="multisite_target_blog_id" value="<?php echo esc_attr($o['multisite_target_blog_id']); ?>" /></label><br>
                        <label>Package scopes <input class="regular-text" name="package_scopes" value="<?php echo esc_attr($o['package_scopes']); ?>" placeholder="uploads,themes,plugins" /></label>
                    </td></tr>
                </table>
                <?php submit_button('Save settings'); ?>
            </form>

            <h2>Actions</h2>
            <?php self::button('ping', 'Test remote connection'); ?>
            <?php self::button('dry_run', 'Dry run compare'); ?>
            <?php self::button('preflight', 'Run preflight checks'); ?>
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
                var running=false;
                var delay=<?php echo (int)$o['ajax_step_delay_ms']; ?>;
                var nonce='<?php echo esc_js(wp_create_nonce('csmcr_ajax')); ?>';
                var ajaxurl='<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
                function post(action){
                    var body=new URLSearchParams({action:action,_ajax_nonce:nonce});
                    return fetch(ajaxurl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body}).then(function(r){return r.json();});
                }
                function paint(payload){
                    if(!payload || !payload.success){throw new Error((payload && payload.data && payload.data.message) ? payload.data.message : 'Request failed');}
                    if(payload.data && payload.data.html){document.getElementById('csmcr-job-status').innerHTML=payload.data.html;}
                    if(payload.data && payload.data.job && payload.data.job.status==='complete'){running=false; document.getElementById('csmcr-auto-note').textContent='Complete.';}
                }
                function tick(){
                    if(!running){return;}
                    document.getElementById('csmcr-auto-note').textContent='Running next step...';
                    post('csmcr_job_step').then(function(res){paint(res); if(running){setTimeout(tick, delay);}}).catch(function(e){running=false; document.getElementById('csmcr-auto-note').textContent='Stopped: '+e.message;});
                }
                var run=document.getElementById('csmcr-auto-run');
                var stop=document.getElementById('csmcr-stop-auto-run');
                if(run){run.addEventListener('click',function(){running=true; document.getElementById('csmcr-auto-note').textContent='Started.'; tick();});}
                if(stop){stop.addEventListener('click',function(){running=false; document.getElementById('csmcr-auto-note').textContent='Stopped.';});}
            })();
            </script>
        </div>
        <?php
    }

    public static function ajax_job_status() {
        self::ajax_guard();
        wp_send_json_success(['job'=>CSMCR_Plugin::active_job(), 'html'=>CSMCR_Jobs::status_html()]);
    }

    public static function ajax_job_step() {
        self::ajax_guard();
        try { CSMCR_Jobs::step(); wp_send_json_success(['job'=>CSMCR_Plugin::active_job(), 'html'=>CSMCR_Jobs::status_html()]); }
        catch (Throwable $e) { wp_send_json_error(['message'=>$e->getMessage(), 'html'=>CSMCR_Jobs::status_html()], 400); }
    }

    public static function ajax_job_cancel() {
        self::ajax_guard();
        CSMCR_Jobs::cancel();
        wp_send_json_success(['job'=>[], 'html'=>CSMCR_Jobs::status_html()]);
    }

    private static function ajax_guard() {
        check_ajax_referer('csmcr_ajax');
        if (!current_user_can('manage_options')) { wp_send_json_error(['message'=>'Forbidden'], 403); }
    }

    public static function download_report() {
        if (!current_user_can('manage_options')) { wp_die('Forbidden', 403); }
        check_admin_referer('csmcr_download_report');
        $file = sanitize_file_name($_GET['file'] ?? '');
        $path = CSMCR_Plugin::report_dir() . $file;
        if (!$file || !is_file($path) || pathinfo($path, PATHINFO_EXTENSION) !== 'json') { wp_die('Report not found.', 404); }
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        readfile($path);
        exit;
    }

    public static function download_backup() {
        if (!current_user_can('manage_options')) { wp_die('Forbidden', 403); }
        check_admin_referer('csmcr_download_backup');
        $file = sanitize_file_name($_GET['file'] ?? '');
        $path = CSMCR_Backup::dir() . $file;
        if (!$file || !is_file($path) || pathinfo($path, PATHINFO_EXTENSION) !== 'sql') { wp_die('Backup not found.', 404); }
        nocache_headers();
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
                    'auto_backup_db' => !empty($_POST['auto_backup_db']) ? 1 : 0,
                    'skip_same_files' => !empty($_POST['skip_same_files']) ? 1 : 0,
                    'use_file_hash' => !empty($_POST['use_file_hash']) ? 1 : 0,
                    'manifest_include_hashes' => !empty($_POST['manifest_include_hashes']) ? 1 : 0,
                    'ajax_step_delay_ms' => max(250, (int)($_POST['ajax_step_delay_ms'] ?? 900)),
                    'background_enabled' => !empty($_POST['background_enabled']) ? 1 : 0,
                    'background_delay_seconds' => max(15, (int)($_POST['background_delay_seconds'] ?? 45)),
                    'background_max_steps' => max(1, min(10, (int)($_POST['background_max_steps'] ?? 1))),
                    'max_reports' => max(1, min(100, (int)($_POST['max_reports'] ?? 20))),
                    'max_backups' => max(1, min(100, (int)($_POST['max_backups'] ?? 10))),
                    'multisite_source_blog_id' => max(1, (int)($_POST['multisite_source_blog_id'] ?? 1)),
                    'multisite_target_blog_id' => max(1, (int)($_POST['multisite_target_blog_id'] ?? 1)),
                    'package_scopes' => sanitize_text_field(wp_unslash($_POST['package_scopes'] ?? 'uploads,themes,plugins')),
                ]);
                self::log('Settings saved.');
            } else {
                check_admin_referer('csmcr_action');
                if ($action === 'ping') { $r = CSMCR_HTTP::remote('ping'); self::log('Remote ping OK: ' . wp_json_encode($r)); }
                if ($action === 'dry_run') { CSMCR_Migrator::dry_run(); }
                if ($action === 'preflight') { CSMCR_Preflight::run(true); }
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

    private static function body_hash_from_request($request) {
        $body = (string)$request->get_body();
        return hash('sha256', $body);
    }

    private static function signature_payload($method, $route, $ts, $body_hash) {
        return strtoupper((string)$method) . "\n" . (string)$route . "\n" . (string)$ts . "\n" . (string)$body_hash;
    }

    public static function compute_signature($secret, $method, $route, $ts, $body_hash) {
        return hash_hmac('sha256', self::signature_payload($method, $route, $ts, $body_hash), (string)$secret);
    }

    private static function audit_remote_auth($code, $message) {
        $key = 'csmcr_audit_' . md5((string)$code . '|' . (string)$message);
        if (get_transient($key)) { return; }
        set_transient($key, 1, 60);
        self::add_job('AUTH ' . strtoupper((string)$code) . ': ' . $message);
    }

    public static function auth($request) {
        $o = self::opts();
        $secret = $request->get_header('x-csmcr-secret');
        if (!$secret || !hash_equals((string)$o['secret'], (string)$secret)) { self::audit_remote_auth('deny', 'Secret mismatch.'); return false; }

        $ts = (int)$request->get_header('x-csmcr-ts');
        $sig = (string)$request->get_header('x-csmcr-signature');
        $hash = (string)$request->get_header('x-csmcr-body-hash');
        if ($ts <= 0 || !$sig || !$hash) {
            if (!empty($o['allow_legacy_auth'])) { self::audit_remote_auth('legacy', 'Accepted legacy secret-only auth.'); return true; }
            self::audit_remote_auth('deny', 'Missing signed auth headers.');
            return false;
        }
        if (abs(time() - $ts) > 300) { self::audit_remote_auth('deny', 'Expired signed request.'); return false; }

        $route = isset($_SERVER['REQUEST_URI']) ? wp_parse_url((string)wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH) : (string)$request->get_route();
        $method = (string)$request->get_method();
        $expected_hash = self::body_hash_from_request($request);
        if (!hash_equals($expected_hash, $hash)) { self::audit_remote_auth('deny', 'Body hash mismatch.'); return false; }
        $expected_sig = self::compute_signature($secret, $method, $route, $ts, $hash);
        $ok = hash_equals($expected_sig, $sig);
        if (!$ok) { self::audit_remote_auth('deny', 'Signature mismatch.'); }
        return $ok;
    }

    public static function api_ping() {
        global $wpdb;
        return ['ok'=>true,'site'=>site_url(),'home'=>home_url(),'version'=>CSMCR_VERSION,'multisite'=>is_multisite(),'prefix'=>$wpdb->prefix];
    }
}

final class CSMCR_HTTP {
    public static function remote($path, $method='GET', $body=null) {
        $o = CSMCR_Plugin::opts();
        if (empty($o['remote_url']) || empty($o['remote_secret'])) { throw new Exception('Remote URL and secret are required.'); }
        $url = trailingslashit($o['remote_url']) . 'wp-json/' . CSMCR_NS . '/' . ltrim($path, '/');
        $args = ['method'=>$method, 'timeout'=>120, 'headers'=>['x-csmcr-secret'=>$o['remote_secret']]];
        $json_body = '';
        if ($body !== null) {
            $json_body = wp_json_encode($body);
            $args['headers']['content-type'] = 'application/json';
            $args['body'] = $json_body;
        }
        $ts = time();
        $body_hash = hash('sha256', (string)$json_body);
        $route = wp_parse_url((string)$url, PHP_URL_PATH);
        $args['headers']['x-csmcr-ts'] = (string)$ts;
        $args['headers']['x-csmcr-body-hash'] = $body_hash;
        $args['headers']['x-csmcr-signature'] = CSMCR_Plugin::compute_signature($o['remote_secret'], $method, $route, $ts, $body_hash);
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
            return str_replace($search, $replace, $value);
        }
        if (is_array($value)) { foreach ($value as $k=>$v) { $value[$k] = self::recursive_replace($v, $search, $replace); } return $value; }
        if (is_object($value)) { foreach ($value as $k=>$v) { $value->$k = self::recursive_replace($v, $search, $replace); } return $value; }
        return $value;
    }
}

final class CSMCR_Backup {
    public static function dir() {
        $u = wp_upload_dir();
        $dir = trailingslashit($u['basedir']) . 'csmcr-backups';
        wp_mkdir_p($dir);
        if (!file_exists($dir . '/index.html')) { file_put_contents($dir . '/index.html', ''); }
        if (!file_exists($dir . '/.htaccess')) { file_put_contents($dir . '/.htaccess', "Deny from all\n"); }
        return trailingslashit($dir);
    }

    public static function create_db_backup($reason='manual') {
        global $wpdb;
        $tables = CSMCR_API::db_tables()['tables'] ?? [];
        $name = 'db-' . gmdate('Ymd-His') . '-' . sanitize_key($reason) . '-' . substr(wp_hash(wp_generate_password(20, false)), 0, 10) . '.sql';
        $file = self::dir() . $name;
        $fh = fopen($file, 'wb');
        if (!$fh) { throw new Exception('Could not create DB backup file.'); }
        fwrite($fh, "-- Cloud Site Mover Clean Room DB backup\n-- Site: " . site_url() . "\n-- Created: " . gmdate('c') . "\n\nSET FOREIGN_KEY_CHECKS=0;\n\n");
        foreach ($tables as $table) {
            $row = $wpdb->get_row('SHOW CREATE TABLE `' . esc_sql($table) . '`', ARRAY_N);
            fwrite($fh, "DROP TABLE IF EXISTS `" . str_replace('`','``',$table) . "`;\n" . ($row[1] ?? '') . ";\n\n");
            $offset = 0; $limit = 500;
            do {
                $rows = $wpdb->get_results('SELECT * FROM `' . esc_sql($table) . '` LIMIT ' . (int)$offset . ', ' . (int)$limit, ARRAY_A);
                foreach ($rows as $r) {
                    $cols = array_map(function($c){ return '`' . str_replace('`','``',$c) . '`'; }, array_keys($r));
                    $vals = array_map(function($v) use ($wpdb){ return is_null($v) ? 'NULL' : "'" . esc_sql($v) . "'"; }, array_values($r));
                    fwrite($fh, 'INSERT INTO `' . str_replace('`','``',$table) . '` (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ");\n");
                }
                $offset += count($rows);
            } while (count($rows) >= $limit);
            fwrite($fh, "\n");
        }
        fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fh);
        CSMCR_Plugin::log('DB backup created: ' . basename($file));
        CSMCR_Plugin::add_job('DB backup created: ' . basename($file));
        return $file;
    }

    public static function list_files() {
        $files = glob(self::dir() . 'db-*.sql');
        if (!$files) { return []; }
        usort($files, function($a, $b){ return filemtime($b) <=> filemtime($a); });
        $o = CSMCR_Plugin::opts();
        return array_slice($files, 0, max(1, (int)$o['max_backups']));
    }

    public static function render_list() {
        $files = self::list_files();
        if (!$files) { echo '<p><em>No DB backups created by this plugin yet.</em></p>'; return; }
        echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>Backup</th><th>Created</th><th>Size</th><th>Action</th></tr></thead><tbody>';
        foreach ($files as $path) {
            $file = basename($path);
            $url = wp_nonce_url(admin_url('admin-post.php?action=csmcr_download_backup&file=' . rawurlencode($file)), 'csmcr_download_backup');
            echo '<tr><td><code>' . esc_html($file) . '</code></td><td>' . esc_html(gmdate('Y-m-d H:i:s', filemtime($path)) . ' UTC') . '</td><td>' . esc_html(size_format(filesize($path))) . '</td><td><a class="button" href="' . esc_url($url) . '">Download</a></td></tr>';
        }
        echo '</tbody></table>';
    }
}

final class CSMCR_Preflight {
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
        delete_transient(CSMCR_BG_LOCK);
        if ($log) {
            CSMCR_Plugin::log('Cleanup complete. Removed ' . count($removed) . ' old files.');
            CSMCR_Plugin::add_job('Cleanup complete. Removed ' . count($removed) . ' old files.');
        }
        return ['removed'=>$removed, 'count'=>count($removed)];
    }
    private static function prune($dir, $pattern, $keep) {
        $files = glob(trailingslashit($dir) . $pattern);
        if (!$files) { return []; }
        usort($files, function($a, $b){ return filemtime($b) <=> filemtime($a); });
        $remove = array_slice($files, $keep);
        $removed = [];
        foreach ($remove as $path) { if (@unlink($path)) { $removed[] = basename($path); } }
        return $removed;
    }
}

final class CSMCR_Package {
    public static function rest_manifest() { return self::site_manifest(false); }
    public static function rest_multisite_map() { return self::multisite_map(); }

    public static function site_manifest($log=false) {
        global $wpdb;
        $o = CSMCR_Plugin::opts();
        $tables = CSMCR_API::db_tables()['tables'] ?? [];
        $table_items = [];
        foreach ($tables as $table) {
            $count = $wpdb->get_var('SELECT COUNT(*) FROM `' . esc_sql($table) . '`');
            $status = $wpdb->get_row("SHOW TABLE STATUS LIKE '" . esc_sql($wpdb->esc_like($table)) . "'", ARRAY_A);
            $table_items[] = [
                'table' => $table,
                'rows' => (int)$count,
                'data_length' => isset($status['Data_length']) ? (int)$status['Data_length'] : 0,
                'index_length' => isset($status['Index_length']) ? (int)$status['Index_length'] : 0,
            ];
        }
        $scopes = [];
        $old_hash = $o['use_file_hash'];
        if (!empty($o['manifest_include_hashes'])) { CSMCR_Plugin::save_opts(['use_file_hash'=>1]); }
        foreach (['uploads','themes','plugins'] as $scope) {
            try {
                $files = CSMCR_Files::scan($scope, true);
                $bytes = 0;
                foreach ($files as $f) { $bytes += (int)($f['size'] ?? 0); }
                $scopes[$scope] = ['count'=>count($files), 'bytes'=>$bytes, 'sample'=>array_slice($files, 0, 20)];
            } catch (Throwable $e) { $scopes[$scope] = ['error'=>$e->getMessage()]; }
        }
        if (!empty($o['manifest_include_hashes'])) { CSMCR_Plugin::save_opts(['use_file_hash'=>$old_hash]); }
        $manifest = [
            'kind' => 'site_manifest',
            'plugin_version' => CSMCR_VERSION,
            'generated_at' => gmdate('c'),
            'site_url' => site_url(),
            'home_url' => home_url(),
            'is_multisite' => is_multisite(),
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'db_prefix' => $wpdb->prefix,
            'db_base_prefix' => $wpdb->base_prefix,
            'tables' => $table_items,
            'files' => $scopes,
            'active_theme' => wp_get_theme()->get('Name'),
            'active_plugins' => (array)get_option('active_plugins', []),
            'excludes' => ['tables'=>$o['exclude_tables'], 'paths'=>$o['exclude_paths']],
        ];
        if ($log) { CSMCR_Plugin::log('Full-site manifest generated. Tables=' . count($table_items) . '.'); }
        return $manifest;
    }

    public static function create_manifest_report($log=false) {
        $manifest = self::site_manifest($log);
        $file = CSMCR_Plugin::report_dir() . 'site-manifest-' . gmdate('Ymd-His') . '.json';
        file_put_contents($file, wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        CSMCR_Plugin::add_job('Created full-site manifest: ' . basename($file));
        if ($log) { CSMCR_Plugin::log('Created full-site manifest: ' . basename($file)); }
        return $file;
    }

    public static function create_handoff_plan($log=false) {
        $local = self::site_manifest(false);
        $remote = null; $remote_error = null;
        try { $remote = CSMCR_HTTP::remote('site/manifest'); } catch (Throwable $e) { $remote_error = $e->getMessage(); }
        $plan = [
            'kind' => 'migration_handoff_plan',
            'generated_at' => gmdate('c'),
            'local' => $local,
            'remote' => $remote,
            'remote_error' => $remote_error,
            'safe_order' => [
                'Run preflight on both sites.',
                'Create local and remote DB backups.',
                'Pull or push database using resumable job runner.',
                'Sync uploads/media.',
                'Sync themes and plugins only when explicitly allowed.',
                'Review reports and test wp-admin, frontend, media, forms, cache, and redirects.',
                'Disable write permissions and remove the plugin from both sites.',
            ],
            'risk_notes' => [
                'Do not enable theme/plugin writes on a live public target longer than needed.',
                'Serialized search/replace is supported, but custom binary blobs should be reviewed manually.',
                'Multisite conversion is planning-only in this build, not automatic destructive conversion.',
            ],
        ];
        $file = CSMCR_Plugin::report_dir() . 'migration-handoff-plan-' . gmdate('Ymd-His') . '.json';
        file_put_contents($file, wp_json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        CSMCR_Plugin::add_job('Created migration handoff plan: ' . basename($file));
        if ($log) { CSMCR_Plugin::log('Created migration handoff plan: ' . basename($file)); }
        return $file;
    }

    public static function multisite_map() {
        global $wpdb;
        $map = ['kind'=>'multisite_map','generated_at'=>gmdate('c'),'is_multisite'=>is_multisite(),'site_url'=>site_url(),'home_url'=>home_url(),'base_prefix'=>$wpdb->base_prefix,'current_prefix'=>$wpdb->prefix,'blogs'=>[],'conversion_status'=>'planning_only'];
        if (!is_multisite()) {
            $map['single_site_target_notes'] = ['Current install is single site.', 'To move into multisite, create a target blog first, then map DB tables and uploads into that blog ID.'];
            return $map;
        }
        $sites = get_sites(['number'=>1000]);
        foreach ($sites as $site) {
            $blog_id = (int)$site->blog_id;
            $prefix = $blog_id === 1 ? $wpdb->base_prefix : $wpdb->base_prefix . $blog_id . '_';
            $uploads = $blog_id === 1 ? 'wp-content/uploads/' : 'wp-content/uploads/sites/' . $blog_id . '/';
            $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($prefix) . '%'));
            $map['blogs'][] = ['blog_id'=>$blog_id,'domain'=>$site->domain,'path'=>$site->path,'table_prefix'=>$prefix,'table_count'=>count($tables),'tables_sample'=>array_slice($tables,0,25),'uploads_path'=>$uploads,'single_site_candidate_tables'=>['posts','postmeta','options','terms','term_taxonomy','term_relationships','termmeta','comments','commentmeta']];
        }
        $map['warnings'] = ['This is a map only. It does not rewrite blog IDs, upload paths, domains, or serialized network options.', 'Manual review is required before converting multisite to single site or single site to multisite.'];
        return $map;
    }

    public static function create_multisite_map_report($log=false) {
        $map = self::multisite_map();
        $file = CSMCR_Plugin::report_dir() . 'multisite-map-' . gmdate('Ymd-His') . '.json';
        file_put_contents($file, wp_json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        CSMCR_Plugin::add_job('Created multisite map: ' . basename($file));
        if ($log) { CSMCR_Plugin::log('Created multisite map: ' . basename($file)); }
        return $file;
    }
}


final class CSMCR_Simulation {
    public static function rest_multisite_simulation() { return self::multisite_conversion_simulation(false); }
    public static function rest_package_manifest() { return self::package_manifest(false); }

    public static function blog_prefix($blog_id) {
        global $wpdb;
        $blog_id = max(1, (int)$blog_id);
        return $blog_id === 1 ? $wpdb->base_prefix : $wpdb->base_prefix . $blog_id . '_';
    }

    public static function core_blog_tables() {
        return ['posts','postmeta','options','terms','term_taxonomy','term_relationships','termmeta','comments','commentmeta','links'];
    }

    public static function multisite_conversion_simulation($log=false) {
        global $wpdb;
        $o = CSMCR_Plugin::opts();
        $source_blog_id = max(1, (int)$o['multisite_source_blog_id']);
        $target_blog_id = max(1, (int)$o['multisite_target_blog_id']);
        $source_prefix = self::blog_prefix($source_blog_id);
        $target_prefix = self::blog_prefix($target_blog_id);
        $single_prefix = $wpdb->prefix;
        $map = CSMCR_Package::multisite_map();
        $table_map_to_single = [];
        $table_map_to_multisite = [];
        foreach (self::core_blog_tables() as $short) {
            $table_map_to_single[] = ['from'=>$source_prefix . $short, 'to'=>$single_prefix . $short, 'mode'=>'copy_or_rename_after_review'];
            $table_map_to_multisite[] = ['from'=>$single_prefix . $short, 'to'=>$target_prefix . $short, 'mode'=>'copy_into_target_blog_after_review'];
        }
        $source_uploads = $source_blog_id === 1 ? 'wp-content/uploads/' : 'wp-content/uploads/sites/' . $source_blog_id . '/';
        $target_uploads = $target_blog_id === 1 ? 'wp-content/uploads/' : 'wp-content/uploads/sites/' . $target_blog_id . '/';
        $simulation = [
            'kind' => 'multisite_conversion_simulation',
            'generated_at' => gmdate('c'),
            'mode' => is_multisite() ? 'multisite_to_single_or_blog_extract' : 'single_to_multisite_blog_import',
            'is_multisite' => is_multisite(),
            'source_blog_id' => $source_blog_id,
            'target_blog_id' => $target_blog_id,
            'source_prefix' => $source_prefix,
            'target_prefix' => $target_prefix,
            'current_prefix' => $wpdb->prefix,
            'base_prefix' => $wpdb->base_prefix,
            'table_map_to_single' => $table_map_to_single,
            'table_map_to_multisite' => $table_map_to_multisite,
            'uploads_map' => [
                'source' => $source_uploads,
                'target' => is_multisite() ? 'wp-content/uploads/' : $target_uploads,
                'target_multisite_blog_path' => $target_uploads,
            ],
            'network_tables_never_auto_overwrite' => [$wpdb->base_prefix . 'blogs', $wpdb->base_prefix . 'site', $wpdb->base_prefix . 'sitemeta', $wpdb->base_prefix . 'blogmeta', $wpdb->base_prefix . 'registration_log', $wpdb->base_prefix . 'signups'],
            'required_manual_decisions' => [
                'Confirm source and target blog IDs.',
                'Confirm domain/path rewrite for home and siteurl.',
                'Confirm uploads path rewrite inside post content and attachment metadata.',
                'Confirm whether users/usermeta should be merged or kept as target users.',
                'Confirm active theme/plugin availability before DNS switch.',
            ],
            'blocked_destructive_actions' => [
                'No tables are dropped by this simulation.',
                'No wp_blogs/wp_site/wp_sitemeta rows are modified.',
                'No upload paths are moved automatically.',
            ],
            'source_multisite_map' => $map,
        ];
        if ($log) { CSMCR_Plugin::log('Multisite conversion simulation generated. Source blog ' . $source_blog_id . ', target blog ' . $target_blog_id . '.'); }
        return $simulation;
    }

    public static function create_multisite_simulation_report($log=false) {
        $data = self::multisite_conversion_simulation($log);
        $file = CSMCR_Plugin::report_dir() . 'multisite-conversion-simulation-' . gmdate('Ymd-His') . '.json';
        file_put_contents($file, wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        CSMCR_Plugin::add_job('Created multisite conversion simulation: ' . basename($file));
        if ($log) { CSMCR_Plugin::log('Created multisite conversion simulation: ' . basename($file)); }
        return $file;
    }

    public static function package_manifest($log=false) {
        global $wpdb;
        $o = CSMCR_Plugin::opts();
        $scopes = array_filter(array_map('sanitize_key', array_map('trim', explode(',', (string)$o['package_scopes']))));
        if (!$scopes) { $scopes = ['uploads']; }
        $files = [];
        foreach ($scopes as $scope) {
            if (!in_array($scope, ['uploads','themes','plugins'], true)) { continue; }
            $list = CSMCR_Files::scan($scope, true);
            $bytes = 0;
            foreach ($list as $item) { $bytes += (int)($item['size'] ?? 0); }
            $files[$scope] = ['count'=>count($list), 'bytes'=>$bytes, 'sample'=>array_slice($list, 0, 25)];
        }
        $tables = CSMCR_API::db_tables()['tables'] ?? [];
        $table_rows = [];
        foreach ($tables as $table) {
            $count = $wpdb->get_var('SELECT COUNT(*) FROM `' . esc_sql($table) . '`');
            $table_rows[] = ['table'=>$table, 'rows'=>(int)$count];
        }
        $manifest = [
            'kind' => 'portable_package_manifest',
            'generated_at' => gmdate('c'),
            'site_url' => site_url(),
            'home_url' => home_url(),
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'is_multisite' => is_multisite(),
            'db_prefix' => $wpdb->prefix,
            'base_prefix' => $wpdb->base_prefix,
            'tables' => $table_rows,
            'file_scopes' => $files,
            'recommended_order' => ['preflight','db_backup','database','uploads','themes','plugins','search_replace_validation','permalinks_flush','cache_purge','dns_switch'],
            'restore_notes' => [
                'This manifest describes a package plan; it is not a full archive by itself.',
                'Use resumable jobs for remote-to-remote transfer on constrained hosting.',
                'Keep code-folder writes disabled except during a controlled migration window.',
            ],
        ];
        if ($log) { CSMCR_Plugin::log('Portable package manifest generated. Tables=' . count($table_rows) . ', scopes=' . implode(',', array_keys($files)) . '.'); }
        return $manifest;
    }

    public static function create_package_manifest_report($log=false) {
        $data = self::package_manifest($log);
        $file = CSMCR_Plugin::report_dir() . 'portable-package-manifest-' . gmdate('Ymd-His') . '.json';
        file_put_contents($file, wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        CSMCR_Plugin::add_job('Created portable package manifest: ' . basename($file));
        if ($log) { CSMCR_Plugin::log('Created portable package manifest: ' . basename($file)); }
        return $file;
    }
}

final class CSMCR_API {
    public static function table_allowed($table) {
        $o = CSMCR_Plugin::opts();
        return !CSMCR_Util::matches_any($table, CSMCR_Util::lines($o['exclude_tables']));
    }

    public static function preflight() {
        return CSMCR_Preflight::run(false);
    }

    public static function db_tables() {
        global $wpdb;
        $rows = $wpdb->get_col('SHOW TABLES');
        $rows = array_values(array_filter($rows, [__CLASS__, 'table_allowed']));
        return ['tables'=>$rows];
    }

    public static function db_schema($req) {
        global $wpdb;
        $table = sanitize_text_field($req->get_param('table'));
        if (!self::table_allowed($table)) { return new WP_Error('excluded_table','Table is excluded.',['status'=>403]); }
        $row = $wpdb->get_row('SHOW CREATE TABLE `' . esc_sql($table) . '`', ARRAY_N);
        return ['table'=>$table,'create'=>$row[1] ?? ''];
    }

    public static function db_rows($req) {
        global $wpdb;
        $table = sanitize_text_field($req->get_param('table'));
        if (!self::table_allowed($table)) { return new WP_Error('excluded_table','Table is excluded.',['status'=>403]); }
        $offset = max(0, (int)$req->get_param('offset'));
        $limit = max(1, min(5000, (int)$req->get_param('limit')));
        $rows = $wpdb->get_results('SELECT * FROM `' . esc_sql($table) . '` LIMIT ' . (int)$offset . ', ' . (int)$limit, ARRAY_A);
        return ['rows'=>$rows, 'next'=>$offset + count($rows), 'done'=>count($rows) < $limit];
    }

    public static function db_write_table($req) {
        global $wpdb;
        $o = CSMCR_Plugin::opts();
        if (empty($o['allow_db_write'])) { return new WP_Error('write_disabled','DB writes are disabled on this site.',['status'=>403]); }
        $data = self::request_json($req);
        $table = sanitize_text_field($data['table'] ?? '');
        if (!$table || !self::table_allowed($table)) { return new WP_Error('bad_table','Invalid or excluded table.',['status'=>400]); }
        $create = (string)($data['create'] ?? '');
        $rows = is_array($data['rows'] ?? null) ? $data['rows'] : [];
        $first = !empty($data['first']);
        $search = (string)($data['search'] ?? '');
        $replace = (string)($data['replace'] ?? '');
        if ($first) {
            $wpdb->query('DROP TABLE IF EXISTS `' . esc_sql($table) . '`');
            if ($create) { $wpdb->query($create); }
        }
        foreach ($rows as $row) {
            if (!is_array($row)) { continue; }
            foreach ($row as $k=>$v) { $row[$k] = CSMCR_Util::recursive_replace($v, $search, $replace); }
            $wpdb->insert($table, $row);
        }
        return ['ok'=>true,'table'=>$table,'inserted'=>count($rows)];
    }

    public static function db_backup($req) {
        $o = CSMCR_Plugin::opts();
        if (empty($o['allow_db_write'])) { return new WP_Error('write_disabled','DB backup endpoint requires DB writes permission.',['status'=>403]); }
        $data = self::request_json($req);
        $reason = sanitize_key($data['reason'] ?? 'remote-request');
        $file = CSMCR_Backup::create_db_backup($reason);
        return ['ok'=>true,'file'=>basename($file)];
    }

    public static function files_list($req) {
        $scope = sanitize_key($req->get_param('scope') ?: 'uploads');
        return ['scope'=>$scope, 'files'=>CSMCR_Files::scan($scope, false)];
    }

    public static function files_manifest($req) {
        $scope = sanitize_key($req->get_param('scope') ?: 'uploads');
        return ['scope'=>$scope, 'files'=>CSMCR_Files::scan($scope, true)];
    }

    public static function file_chunk($req) {
        $scope = sanitize_key($req->get_param('scope') ?: 'uploads');
        $rel = CSMCR_Files::safe_rel((string)$req->get_param('file'));
        $offset = max(0, (int)$req->get_param('offset'));
        $bytes = max(65536, min(5242880, (int)$req->get_param('bytes')));
        $path = CSMCR_Files::base($scope) . $rel;
        if (!is_file($path) || !CSMCR_Files::allowed_rel($rel)) { return new WP_Error('missing_file','File not found or excluded.',['status'=>404]); }
        $fh = fopen($path, 'rb');
        fseek($fh, $offset);
        $data = fread($fh, $bytes);
        fclose($fh);
        $next = $offset + strlen($data);
        $size = filesize($path);
        return ['data'=>base64_encode($data), 'next'=>$next, 'size'=>$size, 'done'=>$next >= $size, 'mtime'=>filemtime($path)];
    }

    public static function file_write($req) {
        $o = CSMCR_Plugin::opts();
        $data = self::request_json($req);
        $scope = sanitize_key($data['scope'] ?? 'uploads');
        if ($scope === 'uploads' && empty($o['allow_file_write'])) { return new WP_Error('file_write_disabled','Upload writes are disabled.',['status'=>403]); }
        if (($scope === 'themes' || $scope === 'plugins') && empty($o['allow_theme_plugin_write'])) { return new WP_Error('code_write_disabled','Theme/plugin writes are disabled.',['status'=>403]); }
        $rel = CSMCR_Files::safe_rel((string)($data['file'] ?? ''));
        if (!CSMCR_Files::allowed_rel($rel)) { return new WP_Error('excluded_file','File is excluded.',['status'=>403]); }
        $offset = max(0, (int)($data['offset'] ?? 0));
        $content = base64_decode((string)($data['data'] ?? ''), true);
        if ($content === false) { return new WP_Error('bad_payload','Invalid payload.',['status'=>400]); }
        $path = CSMCR_Files::base($scope) . $rel;
        wp_mkdir_p(dirname($path));
        file_put_contents($path, $content, $offset === 0 ? 0 : FILE_APPEND);
        if (!empty($data['mtime'])) { @touch($path, (int)$data['mtime']); }
        return ['ok'=>true,'file'=>$rel,'bytes'=>strlen($content)];
    }

    public static function multisite_plan() {
        $o = CSMCR_Plugin::opts();
        if (empty($o['allow_multisite_beta'])) { return new WP_Error('multisite_disabled','Multisite planning endpoint is disabled.',['status'=>403]); }
        global $wpdb;
        $blogs = [];
        if (is_multisite()) {
            $blogs = get_sites(['number'=>500]);
            $blogs = array_map(function($s){ return ['blog_id'=>(int)$s->blog_id,'domain'=>$s->domain,'path'=>$s->path]; }, $blogs);
        }
        return ['is_multisite'=>is_multisite(),'main_site'=>site_url(),'prefix'=>$wpdb->prefix,'base_prefix'=>$wpdb->base_prefix,'blogs'=>$blogs,'note'=>'Planning only. Conversion requires explicit table and uploads mapping.'];
    }

    private static function request_json($req) {
        if (method_exists($req, 'get_json_params')) {
            $json = $req->get_json_params();
            if (is_array($json)) { return $json; }
        }
        return is_array($req) ? $req : [];
    }
}

final class CSMCR_Files {
    public static function base($scope) {
        $u = wp_upload_dir();
        if ($scope === 'uploads') { return trailingslashit($u['basedir']); }
        if ($scope === 'themes') { return trailingslashit(get_theme_root()); }
        if ($scope === 'plugins') { return trailingslashit(WP_PLUGIN_DIR); }
        throw new Exception('Invalid file scope.');
    }
    public static function safe_rel($rel) {
        $rel = str_replace('\\', '/', (string)$rel);
        $rel = ltrim($rel, '/');
        $parts = [];
        foreach (explode('/', $rel) as $p) { if ($p === '' || $p === '.' || $p === '..') { continue; } $parts[] = $p; }
        return implode('/', $parts);
    }
    public static function allowed_rel($rel) {
        $o = CSMCR_Plugin::opts();
        if ($rel === '') { return false; }
        return !CSMCR_Util::matches_any($rel, CSMCR_Util::lines($o['exclude_paths']));
    }
    public static function scan($scope, $manifest=false) {
        $base = self::base($scope);
        if (!is_dir($base)) { return []; }
        $out = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile()) { continue; }
            $rel = self::safe_rel(substr($f->getPathname(), strlen($base)));
            if (!self::allowed_rel($rel)) { continue; }
            if ($manifest) {
                $item = ['file'=>$rel,'size'=>$f->getSize(),'mtime'=>$f->getMTime()];
                $opts = CSMCR_Plugin::opts();
                if (!empty($opts['use_file_hash'])) { $item['sha1'] = sha1_file($f->getPathname()); }
                $out[] = $item;
            }
            else { $out[] = $rel; }
        }
        sort($out);
        return $out;
    }
}

final class CSMCR_Migrator {
    public static function dry_run() {
        $remote = CSMCR_HTTP::remote('ping');
        $rtables = CSMCR_HTTP::remote('db/tables')['tables'] ?? [];
        $ltables = CSMCR_API::db_tables()['tables'] ?? [];
        $msg = 'Dry run OK. Remote=' . ($remote['site'] ?? 'unknown') . '. Local tables=' . count($ltables) . ', remote tables=' . count($rtables);
        foreach (['uploads','themes','plugins'] as $scope) {
            $r = CSMCR_HTTP::remote('files/manifest?scope=' . $scope)['files'] ?? [];
            $l = CSMCR_Files::scan($scope, true);
            $msg .= '. ' . $scope . ': local ' . count($l) . ', remote ' . count($r);
        }
        CSMCR_Plugin::log($msg);
    }

    public static function pull_db() {
        $o = CSMCR_Plugin::opts();
        if (!empty($o['auto_backup_db'])) { CSMCR_Backup::create_db_backup('before-pull'); }
        $tables = CSMCR_HTTP::remote('db/tables')['tables'] ?? [];
        foreach ($tables as $table) {
            $schema = CSMCR_HTTP::remote('db/schema?table=' . rawurlencode($table))['create'] ?? '';
            $offset = 0;
            do {
                $res = CSMCR_HTTP::remote('db/rows?table=' . rawurlencode($table) . '&offset=' . $offset . '&limit=' . (int)$o['db_batch']);
                $req = new WP_REST_Request('POST', '/');
                $req->set_body_params(['table'=>$table,'create'=>$schema,'rows'=>$res['rows'] ?? [],'first'=>$offset===0,'search'=>$o['search'],'replace'=>$o['replace']]);
                CSMCR_API::db_write_table($req);
                $offset = (int)($res['next'] ?? 0);
            } while (empty($res['done']));
            CSMCR_Plugin::log('Pulled DB table ' . $table);
        }
        CSMCR_Plugin::log('DB pull complete.');
        CSMCR_Plugin::add_job('DB pull complete from ' . ($o['remote_url'] ?? 'remote') . '.');
    }

    public static function push_db() {
        global $wpdb;
        $o = CSMCR_Plugin::opts();
        if (!empty($o['auto_backup_db'])) { CSMCR_HTTP::remote('db/backup', 'POST', ['reason'=>'before-push']); }
        $tables = CSMCR_API::db_tables()['tables'] ?? [];
        foreach ($tables as $table) {
            $row = $wpdb->get_row('SHOW CREATE TABLE `' . esc_sql($table) . '`', ARRAY_N);
            $schema = $row[1] ?? '';
            $offset = 0;
            do {
                $rows = $wpdb->get_results('SELECT * FROM `' . esc_sql($table) . '` LIMIT ' . (int)$offset . ', ' . (int)$o['db_batch'], ARRAY_A);
                CSMCR_HTTP::remote('db/write-table', 'POST', ['table'=>$table,'create'=>$schema,'rows'=>$rows,'first'=>$offset===0,'search'=>$o['search'],'replace'=>$o['replace']]);
                $offset += count($rows);
            } while (count($rows) >= (int)$o['db_batch']);
            CSMCR_Plugin::log('Pushed DB table ' . $table);
        }
        CSMCR_Plugin::log('DB push complete.');
        CSMCR_Plugin::add_job('DB push complete to ' . ($o['remote_url'] ?? 'remote') . '.');
    }

    public static function pull_files($scope) {
        $o = CSMCR_Plugin::opts();
        $remote = CSMCR_HTTP::remote('files/manifest?scope=' . $scope)['files'] ?? [];
        $cursor = (int)$o[$scope . '_cursor'];
        $batch = array_slice($remote, $cursor, (int)$o['file_batch']);
        foreach ($batch as $item) { self::pull_one_file($scope, $item['file'], $item); }
        CSMCR_Plugin::save_opts([$scope . '_cursor' => $cursor + count($batch)]);
        CSMCR_Plugin::log('Pulled ' . $scope . ' files ' . ($cursor + count($batch)) . '/' . count($remote));
        if (($cursor + count($batch)) >= count($remote)) { CSMCR_Plugin::add_job('File pull complete for ' . $scope . '.'); }
    }

    public static function push_files($scope) {
        $o = CSMCR_Plugin::opts();
        $local = CSMCR_Files::scan($scope, true);
        $remote_manifest = [];
        if (!empty($o['skip_same_files'])) {
            foreach ((CSMCR_HTTP::remote('files/manifest?scope=' . $scope)['files'] ?? []) as $rf) { $remote_manifest[$rf['file']] = $rf; }
        }
        $cursor = (int)$o[$scope . '_cursor'];
        $batch = array_slice($local, $cursor, (int)$o['file_batch']);
        foreach ($batch as $item) {
            if (!empty($o['skip_same_files']) && isset($remote_manifest[$item['file']]) && CSMCR_Jobs::same_file_meta($item, $remote_manifest[$item['file']])) { continue; }
            self::push_one_file($scope, $item['file'], $item['mtime']);
        }
        CSMCR_Plugin::save_opts([$scope . '_cursor' => $cursor + count($batch)]);
        CSMCR_Plugin::log('Pushed ' . $scope . ' files ' . ($cursor + count($batch)) . '/' . count($local));
        if (($cursor + count($batch)) >= count($local)) { CSMCR_Plugin::add_job('File push complete for ' . $scope . '.'); }
    }

    public static function pull_one_file($scope, $rel, $meta=[]) {
        $o = CSMCR_Plugin::opts();
        $local = CSMCR_Files::base($scope) . CSMCR_Files::safe_rel($rel);
        if (!empty($o['skip_same_files']) && is_file($local)) {
            $local_meta = ['size'=>filesize($local), 'mtime'=>filemtime($local)];
            if (!empty($o['use_file_hash'])) { $local_meta['sha1'] = sha1_file($local); }
            if (CSMCR_Jobs::same_file_meta($local_meta, $meta)) { return; }
        }
        $offset = 0;
        do {
            $res = CSMCR_HTTP::remote('files/chunk?scope=' . $scope . '&file=' . rawurlencode($rel) . '&offset=' . $offset . '&bytes=' . (int)$o['chunk_bytes']);
            $path = CSMCR_Files::base($scope) . CSMCR_Files::safe_rel($rel);
            wp_mkdir_p(dirname($path));
            file_put_contents($path, base64_decode($res['data']), $offset === 0 ? 0 : FILE_APPEND);
            if (!empty($res['mtime'])) { @touch($path, (int)$res['mtime']); }
            $offset = (int)$res['next'];
        } while (empty($res['done']));
    }

    public static function push_one_file($scope, $rel, $mtime=0) {
        $o = CSMCR_Plugin::opts();
        $path = CSMCR_Files::base($scope) . CSMCR_Files::safe_rel($rel);
        if (!is_file($path)) { return; }
        $offset = 0;
        $fh = fopen($path, 'rb');
        while (!feof($fh)) {
            $data = fread($fh, (int)$o['chunk_bytes']);
            CSMCR_HTTP::remote('files/write', 'POST', ['scope'=>$scope,'file'=>$rel,'offset'=>$offset,'data'=>base64_encode($data),'mtime'=>$mtime]);
            $offset += strlen($data);
        }
        fclose($fh);
    }
}


final class CSMCR_Reports {
    public static function list_files() {
        $dir = CSMCR_Plugin::report_dir();
        $files = glob($dir . '*.json');
        if (!$files) { return []; }
        usort($files, function($a, $b){ return filemtime($b) <=> filemtime($a); });
        $o = CSMCR_Plugin::opts();
        return array_slice($files, 0, max(1, (int)$o['max_reports']));
    }

    public static function render_list() {
        $files = self::list_files();
        if (!$files) { echo '<p><em>No reports yet.</em></p>'; return; }
        echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>Report</th><th>Created</th><th>Size</th><th>Action</th></tr></thead><tbody>';
        foreach ($files as $path) {
            $file = basename($path);
            $url = wp_nonce_url(admin_url('admin-post.php?action=csmcr_download_report&file=' . rawurlencode($file)), 'csmcr_download_report');
            echo '<tr><td><code>' . esc_html($file) . '</code></td><td>' . esc_html(gmdate('Y-m-d H:i:s', filemtime($path)) . ' UTC') . '</td><td>' . esc_html(size_format(filesize($path))) . '</td><td><a class="button" href="' . esc_url($url) . '">Download</a></td></tr>';
        }
        echo '</tbody></table>';
    }
}
final class CSMCR_Jobs {
    public static function start($kind) {
        $kind = sanitize_key($kind);
        $map = [
            'pull_db'=>['type'=>'db','direction'=>'pull','scope'=>'db'],
            'push_db'=>['type'=>'db','direction'=>'push','scope'=>'db'],
            'pull_uploads'=>['type'=>'files','direction'=>'pull','scope'=>'uploads'],
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
        if (($job['type'] ?? '') === 'db') { $job = self::step_db($job); }
        elseif (($job['type'] ?? '') === 'files') { $job = self::step_files($job); }
        else { throw new Exception('Invalid job state.'); }
        CSMCR_Plugin::save_active_job($job);
        if (($job['status'] ?? '') === 'complete') { self::finalize($job); }
        else { CSMCR_Background::maybe_schedule(); }
        return CSMCR_Plugin::active_job();
    }

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
            CSMCR_API::db_write_table($req);
            $count = count($res['rows'] ?? []);
            if (!empty($res['done'])) { $job['table_index'] = $idx + 1; $job['offset'] = 0; $job['done'] = $idx + 1; $job['messages'][] = 'Pulled table complete: ' . $table; }
            else { $job['offset'] = (int)($res['next'] ?? ($offset + $count)); $job['messages'][] = 'Pulled rows for ' . $table . ': +' . $count; }
        } else {
            $row = $wpdb->get_row('SHOW CREATE TABLE `' . esc_sql($table) . '`', ARRAY_N);
            $rows = $wpdb->get_results('SELECT * FROM `' . esc_sql($table) . '` LIMIT ' . (int)$offset . ', ' . (int)$limit, ARRAY_A);
            CSMCR_HTTP::remote('db/write-table', 'POST', ['table'=>$table,'create'=>$row[1] ?? '','rows'=>$rows,'first'=>$offset===0,'search'=>$o['search'],'replace'=>$o['replace']]);
            $count = count($rows);
            if ($count < $limit) { $job['table_index'] = $idx + 1; $job['offset'] = 0; $job['done'] = $idx + 1; $job['messages'][] = 'Pushed table complete: ' . $table; }
            else { $job['offset'] = $offset + $count; $job['messages'][] = 'Pushed rows for ' . $table . ': +' . $count; }
        }
        if ((int)$job['table_index'] >= count($tables)) { $job['status'] = 'complete'; $job['done'] = $job['total']; }
        return $job;
    }

    private static function step_files($job) {
        $o = CSMCR_Plugin::opts();
        $scope = $job['scope'];
        $direction = $job['direction'];
        $items = ($direction === 'pull') ? (CSMCR_HTTP::remote('files/manifest?scope=' . $scope)['files'] ?? []) : CSMCR_Files::scan($scope, true);
        $job['total'] = count($items);
        $cursor = (int)$job['cursor'];
        if ($cursor >= count($items)) { $job['status'] = 'complete'; $job['done'] = count($items); return $job; }
        $remote_manifest = [];
        if ($direction === 'push' && !empty($o['skip_same_files'])) {
            foreach ((CSMCR_HTTP::remote('files/manifest?scope=' . $scope)['files'] ?? []) as $rf) { $remote_manifest[$rf['file']] = $rf; }
        }
        $batch = array_slice($items, $cursor, (int)$o['file_batch']);
        $moved = 0; $skipped = 0;
        foreach ($batch as $item) {
            if ($direction === 'pull') {
                $local = CSMCR_Files::base($scope) . CSMCR_Files::safe_rel($item['file']);
                if (!empty($o['skip_same_files']) && is_file($local)) {
                    $local_meta = ['size'=>filesize($local), 'mtime'=>filemtime($local)];
                    if (!empty($o['use_file_hash'])) { $local_meta['sha1'] = sha1_file($local); }
                    if (self::same_file_meta($local_meta, $item)) { $skipped++; continue; }
                }
                CSMCR_Migrator::pull_one_file($scope, $item['file'], $item);
                $moved++;
            } else {
                if (!empty($o['skip_same_files']) && isset($remote_manifest[$item['file']]) && self::same_file_meta($item, $remote_manifest[$item['file']])) { $skipped++; continue; }
                CSMCR_Migrator::push_one_file($scope, $item['file'], $item['mtime'] ?? 0);
                $moved++;
            }
        }
        $job['cursor'] = $cursor + count($batch);
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
        echo '<div style="height:16px;background:#eee;border-radius:10px;overflow:hidden"><div style="height:16px;width:' . esc_attr($pct) . '%;background:#2271b1"></div></div>';
        echo '<p>' . esc_html($pct) . '% — done ' . esc_html((string)($job['done'] ?? 0)) . ' / ' . esc_html((string)($job['total'] ?? 0)) . '</p>';
        echo '<textarea readonly class="large-text code" rows="5">' . esc_textarea(implode("\\n", array_slice(array_reverse($job['messages'] ?? []), -8))) . '</textarea>';
        echo '</div>';
    }

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
        CSMCR_Plugin::add_job('Background runner disabled.');
    }

    public static function maybe_schedule($force=false) {
        $o = CSMCR_Plugin::opts();
        $job = CSMCR_Plugin::active_job();
        if (empty($o['background_enabled']) || empty($job) || ($job['status'] ?? '') !== 'running') { return; }
        if (!$force && wp_next_scheduled(CSMCR_CRON_HOOK)) { return; }
        self::unschedule();
        wp_schedule_single_event(time() + max(15, (int)$o['background_delay_seconds']), CSMCR_CRON_HOOK);
    }

    public static function unschedule() {
        $ts = wp_next_scheduled(CSMCR_CRON_HOOK);
        while ($ts) {
            wp_unschedule_event($ts, CSMCR_CRON_HOOK);
            $ts = wp_next_scheduled(CSMCR_CRON_HOOK);
        }
        delete_transient(CSMCR_BG_LOCK);
    }

    public static function run() {
        $o = CSMCR_Plugin::opts();
        if (empty($o['background_enabled'])) { return; }
        $job = CSMCR_Plugin::active_job();
        if (empty($job) || ($job['status'] ?? '') !== 'running') { self::unschedule(); return; }
        if (get_transient(CSMCR_BG_LOCK)) { self::maybe_schedule(true); return; }
        set_transient(CSMCR_BG_LOCK, 1, 10 * MINUTE_IN_SECONDS);
        try {
            $max = max(1, min(10, (int)$o['background_max_steps']));
            for ($i = 0; $i < $max; $i++) {
                $job = CSMCR_Plugin::active_job();
                if (empty($job) || ($job['status'] ?? '') !== 'running') { break; }
                CSMCR_Jobs::step();
            }
        } catch (Throwable $e) {
            CSMCR_Plugin::log('Background runner error: ' . $e->getMessage());
            CSMCR_Plugin::add_job('Background runner error: ' . $e->getMessage());
        }
        delete_transient(CSMCR_BG_LOCK);
        $job = CSMCR_Plugin::active_job();
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
        if ($verb === 'step') { CSMCR_Jobs::step(); WP_CLI::success('Job step completed.'); return; }
        if ($verb === 'run-until-complete') { $max = isset($assoc['max-steps']) ? (int)$assoc['max-steps'] : 500; for ($i=0; $i<$max; $i++) { $job=CSMCR_Plugin::active_job(); if (empty($job) || ($job['status'] ?? '') !== 'running') { WP_CLI::success('No running job, or job complete.'); return; } CSMCR_Jobs::step(); } WP_CLI::warning('Stopped at max steps.'); return; }
        if ($verb === 'start') { $kind = isset($args[1]) ? str_replace('-', '_', $args[1]) : ''; CSMCR_Jobs::start($kind); WP_CLI::success('Started job: ' . $kind); return; }
        WP_CLI::error('Use: wp csmcr job status|start <pull-db|push-db|pull-uploads|push-uploads|pull-themes|push-themes|pull-plugins|push-plugins>|step|cancel');
    }
    public function background($args, $assoc) { $verb = $args[0] ?? 'status'; if ($verb === 'enable') { CSMCR_Background::enable(); WP_CLI::success('Background runner enabled.'); return; } if ($verb === 'disable') { CSMCR_Background::disable(); WP_CLI::success('Background runner disabled.'); return; } if ($verb === 'status') { $o = CSMCR_Plugin::opts(); WP_CLI::line(wp_json_encode(['enabled'=>(bool)$o['background_enabled'], 'next_run'=>CSMCR_Background::next_run_label(), 'lock'=>(bool)get_transient(CSMCR_BG_LOCK)], JSON_PRETTY_PRINT)); return; } WP_CLI::error('Use: wp csmcr background status|enable|disable'); }
    public function preflight() { WP_CLI::line(wp_json_encode(CSMCR_Preflight::run(false), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); }
    public function cleanup() { $r = CSMCR_Maintenance::cleanup(true); WP_CLI::success('Cleanup complete. Removed ' . (int)$r['count'] . ' files.'); }
    public function manifest() { WP_CLI::line(wp_json_encode(CSMCR_Package::site_manifest(false), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); }
    public function handoff_plan() { $file = CSMCR_Package::create_handoff_plan(true); WP_CLI::success('Created handoff plan: ' . basename($file)); }
    public function multisite_map() { WP_CLI::line(wp_json_encode(CSMCR_Package::multisite_map(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); }
    public function multisite_simulate() { WP_CLI::line(wp_json_encode(CSMCR_Simulation::multisite_conversion_simulation(false), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); }
    public function package_manifest() { WP_CLI::line(wp_json_encode(CSMCR_Simulation::package_manifest(false), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); }
    public function multisite_plan() { WP_CLI::line(wp_json_encode(CSMCR_API::multisite_plan(), JSON_PRETTY_PRINT)); }
}

CSMCR_Core_Plugin::init();
