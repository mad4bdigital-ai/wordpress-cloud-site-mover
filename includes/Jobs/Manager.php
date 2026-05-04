<?php
if (!defined('ABSPATH')) { exit; }

final class CSMCR_Jobs_Manager {
    public static function jobs() {
        $jobs = get_option(CSMCR_JOBS_OPT, []);
        return is_array($jobs) ? array_slice($jobs, 0, 40) : [];
    }

    public static function add_job($msg) {
        $jobs = self::jobs();
        array_unshift($jobs, '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . wp_strip_all_tags((string)$msg));
        update_option(CSMCR_JOBS_OPT, array_slice($jobs, 0, 40), false);
    }

    public static function get_active() {
        $job = get_option(CSMCR_ACTIVE_JOB_OPT, []);
        return is_array($job) ? $job : [];
    }

    public static function save_active($job) {
        $job['updated_at'] = gmdate('c');
        update_option(CSMCR_ACTIVE_JOB_OPT, $job, false);
        return $job;
    }

    public static function clear_active() {
        delete_option(CSMCR_ACTIVE_JOB_OPT);
    }
}
