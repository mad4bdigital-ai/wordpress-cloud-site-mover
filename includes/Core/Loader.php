<?php
if (!defined('ABSPATH')) { exit; }

final class CSMCR_Loader {
    public static function require_core() {
        require_once __DIR__ . '/Settings.php';
        require_once __DIR__ . '/Plugin.php';
        require_once dirname(__DIR__) . '/Reports/Logger.php';
        require_once dirname(__DIR__) . '/Jobs/Manager.php';
    }
}
