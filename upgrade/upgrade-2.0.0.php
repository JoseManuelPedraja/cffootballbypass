<?php
/**
 * Upgrade to 2.0.0
 *
 * @author    Jose Manuel Pedraja
 * @copyright 2025 SurMod
 * @license   https://opensource.org/licenses/AFL-3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_0_0($module)
{
    $new_keys = [
        'CLOUDFLARE_ACCOUNT_ID' => '',
        'FORCE_PROXY_OFF_OVERRIDE' => 0,
        'STALE_THRESHOLD_HOURS' => 6,
        'MIN_ISPS_BLOCKED' => 2,
        'EMAIL_NOTIFICATIONS' => 0,
        'NOTIFICATION_EMAIL' => '',
        'UI_MODE' => 'advanced',
        'DELETE_DATA_ON_UNINSTALL' => 1,
        'LOG_SECRET' => '',
        'DATA_CACHE_PATH' => '',
    ];

    foreach ($new_keys as $key => $default) {
        $full_key = 'CFB_' . $key;
        $existing = Configuration::get($full_key);
        if ($existing === false) {
            Configuration::updateValue($full_key, $default);
        }
    }

    $log_secret = Configuration::get('CFB_LOG_SECRET');
    if (empty($log_secret)) {
        $secret = bin2hex(random_bytes(12));
        Configuration::updateValue('CFB_LOG_SECRET', $secret);
    } else {
        $secret = $log_secret;
    }

    $log_dir = _PS_MODULE_DIR_ . $module->name . '/logs';
    if (!file_exists($log_dir)) {
        @mkdir($log_dir, 0755, true);
    }

    $htaccess = $log_dir . '/.htaccess';
    if (!file_exists($htaccess)) {
        $content = "# Deny access to log files\n";
        $content .= "<FilesMatch \"\\.(log|txt)$\">\n";
        $content .= "    Order Allow,Deny\n";
        $content .= "    Deny from all\n";
        $content .= "</FilesMatch>\n\n";
        $content .= "# Block directory listing\n";
        $content .= "Options -Indexes\n";
        @file_put_contents($htaccess, $content);
    }

    $index_php = $log_dir . '/index.php';
    if (!file_exists($index_php)) {
        @file_put_contents($index_php, '<?php // Silence is golden');
    }

    $legacy_log = $log_dir . '/cfb-actions.log';
    $new_log = $log_dir . '/cfb-actions-' . $secret . '.log';
    if (file_exists($legacy_log) && !file_exists($new_log)) {
        @rename($legacy_log, $new_log);
    }

    Configuration::updateValue('CFB_VERSION', '2.0.0');

    return true;
}
