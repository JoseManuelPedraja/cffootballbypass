<?php
/**
 * 2007-2025 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * @author    Jose Manuel Pedraja <josemanuelpedraja@gmail.com>
 * @copyright 2007-2025 PrestaShop SA
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

require_once dirname(__FILE__) . '/../../config/config.inc.php';

if (!defined('_PS_VERSION_')) {
    exit('No direct script access allowed');
}

$token = isset($_GET['token']) ? trim($_GET['token']) : '';
$stored_token = Configuration::get('CFB_CRON_SECRET');

if (empty($stored_token) || $token !== $stored_token) {
    http_response_code(403);
    die('CFB: Invalid token');
}

$log_message = 'External cron executed from IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

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

    $module->logEvent('external_cron', $log_message);

    $module->checkFootballAndManageCloudflare();

    $response = [
        'status' => 'success',
        'message' => 'CFB cron executed successfully',
        'timestamp' => date('Y-m-d H:i:s'),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    ];

    header('Content-Type: application/json');
    echo json_encode($response);
} catch (Exception $e) {
    http_response_code(500);

    $error_response = [
        'status' => 'error',
        'message' => 'Error executing cron: ' . $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s'),
    ];

    header('Content-Type: application/json');
    echo json_encode($error_response);

    if (isset($module) && $module && ($module instanceof CfFootballBypass)) {
        $module->logEvent('external_cron_error', $e->getMessage(), [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'trace' => $e->getTraceAsString(),
        ]);
    }
}

exit;