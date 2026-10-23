<?php
/**
 * External cron endpoint for CF Football Bypass
 *
 * Call via server cron: curl -s "https://yourshop.com/modules/cffootballbypass/cron.php?token=YOUR_TOKEN"
 *
 * @author    Jose Manuel Pedraja <josemanuelpedraja@gmail.com>
 * @copyright 2007-2026 PrestaShop SA
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

require_once dirname(__FILE__) . '/../../config/config.inc.php';

if (!defined('_PS_VERSION_')) {
    exit('No direct script access allowed');
}

$token = isset($_GET['token']) ? trim($_GET['token']) : '';
$storedToken = Configuration::get('CFB_CRON_SECRET');

if (empty($storedToken) || !hash_equals($storedToken, $token)) {
    http_response_code(403);
    die('CFB: Invalid token');
}

try {
    $module = Module::getInstanceByName('cffootballbypass');

    if (!$module || !$module->active) {
        http_response_code(500);
        die('CFB: Module not available');
    }

    if (!($module instanceof CfFootballBypass)) {
        http_response_code(500);
        die('CFB: Invalid module instance');
    }

    $rawIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $anonIp = $module->anonymizeIp($rawIp);

    $module->logEvent('external_cron', 'External cron executed', ['ip' => $anonIp]);
    $module->checkFootballAndManageCloudflare();

    $response = [
        'status' => 'success',
        'message' => 'CFB cron executed successfully',
        'timestamp' => date('Y-m-d H:i:s'),
    ];

    header('Content-Type: application/json');
    echo json_encode($response);
} catch (Exception $e) {
    http_response_code(500);

    $errorResponse = [
        'status' => 'error',
        'message' => 'Error executing cron: ' . $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s'),
    ];

    header('Content-Type: application/json');
    echo json_encode($errorResponse);

    if (isset($module) && $module instanceof CfFootballBypass) {
        $rawIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $module->logEvent('external_cron_error', $e->getMessage(), [
            'ip' => $module->anonymizeIp($rawIp),
        ]);
    }
}

exit;
