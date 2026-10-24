<?php
/**
 * Upgrade to 2.1.0
 *
 * Migrates settings from v1.5.x to v2.1.0 schema.
 * Adds new configuration keys, generates security tokens, creates directories.
 *
 * @author    Jose Manuel Pedraja
 * @copyright 2007-2026 PrestaShop SA
 * @license   https://opensource.org/licenses/AFL-3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_0_0($module)
{
    $newDefaults = [
        'CFB_CLOUDFLARE_ACCOUNT_ID' => '',
        'CFB_FORCE_PROXY_OFF_OVERRIDE' => 0,
        'CFB_STALE_THRESHOLD_HOURS' => 6,
        'CFB_MIN_ISPS_BLOCKED' => 2,
        'CFB_EMAIL_NOTIFICATIONS' => 0,
        'CFB_NOTIFICATION_EMAIL' => '',
        'CFB_UI_MODE' => 'simple',
        'CFB_DELETE_DATA_ON_UNINSTALL' => 1,
    ];

    foreach ($newDefaults as $key => $value) {
        if (Configuration::get($key) === false) {
            Configuration::updateValue($key, $value);
        }
    }

    if (!Configuration::get('CFB_LOG_SECRET')) {
        Configuration::updateValue('CFB_LOG_SECRET', bin2hex(random_bytes(16)));
    }

    if (!Configuration::get('CFB_CRON_SECRET')) {
        Configuration::updateValue('CFB_CRON_SECRET', bin2hex(random_bytes(16)));
    }

    if (Configuration::get('CFB_AUTH_TYPE') === false) {
        Configuration::updateValue('CFB_AUTH_TYPE', 'global');
    }

    $dirs = [
        _PS_MODULE_DIR_ . $module->name . '/logs',
        _PS_MODULE_DIR_ . $module->name . '/cache',
    ];
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!file_exists($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Deny from all\n");
        }
        if (!file_exists($dir . '/index.php')) {
            @file_put_contents($dir . '/index.php', "<?php\n");
        }
    }

    $oldLog = _PS_MODULE_DIR_ . $module->name . '/logs/cfb-actions.log';
    if (file_exists($oldLog)) {
        $secret = Configuration::get('CFB_LOG_SECRET');
        $newLog = _PS_MODULE_DIR_ . $module->name . '/logs/cfb-' . $secret . '.log';
        if (!file_exists($newLog)) {
            @rename($oldLog, $newLog);
        }
    }

    Configuration::updateValue('CFB_VERSION', '2.0.0');

    return true;
}
