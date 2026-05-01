<?php
if (!defined('ABSPATH')) { exit; }

final class CSMCR_Logger {
    public static function line($message) {
        return '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . wp_strip_all_tags((string)$message);
    }

    public static function info($message) {
        $opts = CSMCR_Settings::opts();
        $line = self::line($message);
        $opts['last_log'] = $line . "\n" . substr((string)$opts['last_log'], 0, 16000);
        update_option(CSMCR_OPT, $opts, false);
        return $line;
    }

    public static function error($message) {
        return self::info('ERROR: ' . $message);
    }
}
