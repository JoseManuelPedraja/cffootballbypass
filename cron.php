<?php
/**
 * CF Football Bypass — External Cron Endpoint
 *
 * @author    Jose Manuel Pedraja
 * @copyright 2025 SurMod
 * @license   https://opensource.org/licenses/AFL-3.0
 */

header('Content-Type: application/json; charset=utf-8');

$dir = dirname(__FILE__);

$config_path = $dir . '/../../config/config.inc.php';
if (!file_exists($config_path)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'PrestaShop config not found']);
    exit;
}

require_once $config_path;

if (!defined('_PS_VERSION_')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'PrestaShop not loaded']);
    exit;
}

$token = isset($_GET['token']) ? (string) $_GET['token'] : '';
if (empty($token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Missing token']);
    exit;
}

$stored_token = Configuration::get('CFB_CRON_SECRET');
if (empty($stored_token) || !hash_equals($stored_token, $token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid token']);
    exit;
}

$module = Module::getInstanceByName('cffootballbypass');
if (!$module || !($module instanceof CfFootballBypass)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Module not available']);
    exit;
}

$remote_ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
$anon_ip = anonymizeCronIp($remote_ip);

try {
    $module->checkFootballAndManageCloudflare();

    $module->logEvent('external_cron', 'External cron executed', [
        'ip' => $anon_ip,
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Cron executed successfully',
        'time' => date('Y-m-d H:i:s'),
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Execution error: ' . $e->getMessage(),
    ]);
}

function anonymizeCronIp($ip)
{
    if (empty($ip) || !is_string($ip)) {
        return '';
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $parts = explode('.', $ip);
        if (count($parts) === 4) {
            return $parts[0] . '.' . $parts[1] . '.xxx.xxx';
        }
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $parts = explode(':', $ip);
        if (count($parts) >= 4) {
            return $parts[0] . ':' . $parts[1] . ':' . $parts[2] . ':xxxx:xxxx:xxxx:xxxx:xxxx';
        }
    }
    return 'xxx.xxx.xxx.xxx';
}
