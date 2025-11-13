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

if (!defined('_PS_VERSION_')) {
    exit;
}

class CfFootballBypassAjaxModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        parent::initContent();
        $this->ajax = true;
    }

    public function displayAjax()
    {
        header('Content-Type: application/json');

        if (!$this->isValidEmployee()) {
            die(json_encode([
                'success' => false,
                'message' => 'Access denied. Please reload the backoffice page.',
            ]));
        }

        $action = Tools::getValue('action');

        switch ($action) {
            case 'test_connection':
                $this->testConnection();
                break;
            case 'manual_check':
                $this->manualCheck();
                break;
            case 'get_status':
                $this->getStatus();
                break;
            case 'force_activate':
                $this->forceActivate();
                break;
            case 'force_deactivate':
                $this->forceDeactivate();
                break;
            case 'cron_diagnostics':
                $this->cronDiagnostics();
                break;
            case 'update_selected_records':
                $this->updateSelectedRecords();
                break;
            default:
                die(json_encode([
                    'success' => false,
                    'message' => 'Invalid action',
                ]));
        }
    }

    private function isValidEmployee()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return false;
        }

        $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
        $shop_url = Tools::getShopDomainSsl(true);

        if (empty($referer) || strpos($referer, $shop_url) !== 0) {
            return false;
        }

        return true;
    }

    private function testConnection()
    {
        $log = [];
        $module = Module::getInstanceByName('cffootballbypass');

        if (!$module || !($module instanceof CfFootballBypass)) {
            die(json_encode([
                'success' => false,
                'message' => 'Module not found',
                'log' => [],
            ]));
        }

        $settings = $this->getSettings();

        try {
            $log[] = 'Starting connection test...';
            $log[] = 'Auth: ' . ($settings['auth_type'] === 'token' ? 'Token' : 'Global');
            $log[] = 'Zone ID: ' . $this->maskString($settings['cloudflare_zone_id']);

            $trace = [];
            $test_result = $module->quickSettingsTest($settings, $trace);
            $log = array_merge($log, $trace);

            if ($test_result) {
                $log[] = 'Loading DNS records...';
                $records = $module->fetchDnsRecords(['A', 'AAAA', 'CNAME']);

                if (!empty($records)) {
                    $settings['dns_records_cache'] = json_encode($records);
                    $settings['dns_cache_last_sync'] = date('Y-m-d H:i:s');
                    $this->saveSettings($settings);

                    $log[] = 'DNS records loaded: ' . count($records);

                    $selected = json_decode($settings['selected_records'], true) ?: [];
                    $html = $this->renderDnsTable($records, $selected);

                    die(json_encode([
                        'success' => true,
                        'log' => $log,
                        'html' => $html,
                    ]));
                } else {
                    $log[] = 'Could not load DNS records';
                    die(json_encode([
                        'success' => false,
                        'message' => 'Could not load DNS records',
                        'log' => $log,
                    ]));
                }
            } else {
                die(json_encode([
                    'success' => false,
                    'message' => 'Connection error',
                    'log' => $log,
                ]));
            }
        } catch (Exception $e) {
            $log[] = 'Error: ' . $e->getMessage();
            die(json_encode([
                'success' => false,
                'message' => $e->getMessage(),
                'log' => $log,
            ]));
        }
    }

    private function manualCheck()
    {
        $log = [];
        $module = Module::getInstanceByName('cffootballbypass');

        if (!$module || !($module instanceof CfFootballBypass)) {
            die(json_encode([
                'success' => false,
                'message' => 'Module not found',
                'log' => [],
            ]));
        }

        try {
            $log[] = 'Running manual check...';

            $this->persistSelectedFromAjax();

            $module->checkFootballAndManageCloudflare();
            $settings = $this->getSettings();

            $log[] = 'Check completed';

            die(json_encode([
                'success' => true,
                'log' => $log,
                'last' => $settings['last_check'],
                'general' => $settings['last_status_general'],
                'domain' => $settings['last_status_domain'],
                'last_update' => $settings['last_update'],
            ]));
        } catch (Exception $e) {
            $log[] = 'Error: ' . $e->getMessage();
            die(json_encode([
                'success' => false,
                'message' => $e->getMessage(),
                'log' => $log,
            ]));
        }
    }

    private function getStatus()
    {
        try {
            $module = Module::getInstanceByName('cffootballbypass');

            if (!$module || !($module instanceof CfFootballBypass)) {
                die(json_encode([
                    'success' => false,
                    'message' => 'Module not found',
                ]));
            }

            $calc = $module->computeStatusesFromJson();

            die(json_encode([
                'success' => true,
                'general' => $calc['general'],
                'domain' => $calc['domain'],
                'ips' => $calc['domain_ips'],
                'last_update' => $calc['last_update'],
            ]));
        } catch (Exception $e) {
            die(json_encode([
                'success' => false,
                'message' => $e->getMessage(),
            ]));
        }
    }

    private function forceActivate()
    {
        $this->forceProxyStatus(true, 'Force Proxy ON');
    }

    private function forceDeactivate()
    {
        $this->forceProxyStatus(false, 'Force Proxy OFF');
    }

    private function forceProxyStatus($proxied_on, $operation_name)
    {
        $log = [];
        $module = Module::getInstanceByName('cffootballbypass');

        if (!$module || !($module instanceof CfFootballBypass)) {
            die(json_encode([
                'success' => false,
                'message' => 'Module not found',
                'log' => [],
            ]));
        }

        try {
            $log[] = $operation_name . ': starting...';

            $selected = $this->persistSelectedFromAjax();
            if (empty($selected)) {
                die(json_encode([
                    'success' => false,
                    'message' => 'No records selected',
                    'log' => $log,
                ]));
            }

            $settings = $this->getSettings();

            $records = $module->fetchDnsRecords(['A', 'AAAA', 'CNAME']);
            if (!empty($records)) {
                $settings['dns_records_cache'] = json_encode($records);
                $settings['dns_cache_last_sync'] = date('Y-m-d H:i:s');
                $this->saveSettings($settings);
            }

            $ok = 0;
            $fail = 0;
            $lines = [];

            foreach ($selected as $record_id) {
                $result = $module->updateRecordProxyStatus($record_id, $proxied_on);
                if ($result) {
                    $ok++;
                    $lines[] = 'OK: ' . $record_id . ' -> ' . ($proxied_on ? 'ON' : 'OFF');
                } else {
                    $fail++;
                    $lines[] = 'ERROR: ' . $record_id;
                }
            }

            $settings = $this->getSettings();
            $updated_records = json_decode($settings['dns_records_cache'], true) ?: [];
            $html = $this->renderDnsTable($updated_records, $selected);

            $message = ($proxied_on ? 'Proxy ON' : 'Proxy OFF') . " on {$ok} records" . ($fail ? "; failed: {$fail}" : "") . ".";

            die(json_encode([
                'success' => true,
                'message' => $message,
                'report' => implode("\n", $lines),
                'html' => $html,
                'log' => array_merge($log, $lines, [$message]),
            ]));
        } catch (Exception $e) {
            $log[] = 'Error: ' . $e->getMessage();
            die(json_encode([
                'success' => false,
                'message' => $e->getMessage(),
                'log' => $log,
            ]));
        }
    }

    private function cronDiagnostics()
    {
        try {
            $settings = $this->getSettings();
            $mins = max(5, min(60, (int)$settings['check_interval']));

            $msg = "Configured interval: {$mins} min\n";
            $msg .= "Last check: " . ($settings['last_check'] ?: '—') . "\n";
            $msg .= "General (IP blocks): " . ($settings['last_status_general'] ?: '—') . "\n";
            $msg .= "Domain blocked: " . ($settings['last_status_domain'] ?: '—') . "\n";
            $msg .= "Last update (IP JSON): " . ($settings['last_update'] ?: '—') . "\n";
            $msg .= "Records synced: " . ($settings['dns_cache_last_sync'] ?: '—');

            die(json_encode([
                'success' => true,
                'msg' => $msg,
            ]));
        } catch (Exception $e) {
            die(json_encode([
                'success' => false,
                'message' => $e->getMessage(),
            ]));
        }
    }

    private function updateSelectedRecords()
    {
        try {
            $selected = $this->persistSelectedFromAjax();
            die(json_encode([
                'success' => true,
                'selected' => $selected,
                'count' => count($selected),
            ]));
        } catch (Exception $e) {
            die(json_encode([
                'success' => false,
                'message' => $e->getMessage(),
            ]));
        }
    }

    private function persistSelectedFromAjax()
    {
        $settings = $this->getSettings();
        $selected = Tools::getValue('selected', []);

        if (is_string($selected)) {
            $selected = json_decode($selected, true) ?: [];
        }

        if (!is_array($selected)) {
            $selected = [];
        }

        $selected = array_filter(array_map('pSQL', $selected));

        if ($selected !== (json_decode($settings['selected_records'], true) ?: [])) {
            $settings['selected_records'] = json_encode($selected);
            $this->saveSettings($settings);
        }

        return $selected;
    }

    private function renderDnsTable($records, $selected)
    {
        $html = '<table class="table table-striped">';
        $html .= '<thead><tr>';
        $html .= '<th style="width:40px;"></th>';
        $html .= '<th>Name</th>';
        $html .= '<th>Type</th>';
        $html .= '<th>Content</th>';
        $html .= '<th>Proxy</th>';
        $html .= '<th>TTL</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($records as $record) {
            $id = $record['id'] ?? '';
            $name = $record['name'] ?? '';
            $type = $record['type'] ?? '';
            $content = $record['content'] ?? '';
            $proxied = isset($record['proxied']) ? ($record['proxied'] ? 'ON' : 'OFF') : '—';
            $ttl = $record['ttl'] ?? '';
            $checked = in_array($id, $selected) ? ' checked' : '';

            $html .= '<tr>';
            $html .= '<td><input type="checkbox" name="selected_records[]" value="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"' . $checked . '></td>';
            $html .= '<td>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>' . htmlspecialchars($content, ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>' . htmlspecialchars($proxied, ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>' . htmlspecialchars((string)$ttl, ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';

        return $html;
    }

    private function getSettings()
    {
        $defaults = [
            'cloudflare_email' => '',
            'cloudflare_api_key' => '',
            'cloudflare_zone_id' => '',
            'auth_type' => 'global',
            'check_interval' => 15,
            'selected_records' => '[]',
            'dns_records_cache' => '[]',
            'dns_cache_last_sync' => '',
            'last_check' => '',
            'last_status_general' => 'NO',
            'last_status_domain' => 'NO',
            'last_update' => '',
            'logging_enabled' => 1,
            'log_retention_days' => 30,
            'cron_secret' => '',
            'bypass_active' => 0,
            'bypass_blocked_ips' => '[]',
            'bypass_check_cooldown' => 60,
            'bypass_last_change' => 0,
        ];

        $settings = [];
        foreach ($defaults as $key => $default) {
            $value = Configuration::get('CFB_' . strtoupper($key));
            if ($value === false) {
                $value = $default;
            }
            $settings[$key] = $value;
        }

        return $settings;
    }

    private function saveSettings($settings)
    {
        foreach ($settings as $key => $value) {
            Configuration::updateValue(
                'CFB_' . strtoupper($key),
                is_array($value) ? json_encode($value) : $value
            );
        }
    }

    private function maskString($str, $left = 6, $right = 4)
    {
        if (empty($str)) {
            return '—';
        }
        $len = Tools::strlen($str);
        if ($len <= ($left + $right)) {
            return str_repeat('*', max(0, $len));
        }

        return Tools::substr($str, 0, $left) . str_repeat('*', $len - $left - $right) . Tools::substr($str, -$right);
    }
}