<?php
/**
 * AJAX Controller for CF Football Bypass
 *
 * Handles all AJAX operations from the admin operation panel.
 * Protected by employee session verification + CSRF token.
 *
 * @author    Jose Manuel Pedraja <josemanuelpedraja@gmail.com>
 * @copyright 2007-2026 PrestaShop SA
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

        if (!$this->isValidRequest()) {
            $this->jsonDie(false, 'Access denied. Reload the admin page.');
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
                $this->forceProxyStatus(true, 'Force Proxy ON');
                break;
            case 'force_deactivate':
                $this->forceProxyStatus(false, 'Force Proxy OFF');
                break;
            case 'save_selection':
                $this->saveSelection();
                break;
            case 'cron_diagnostics':
                $this->cronDiagnostics();
                break;
            case 'feed_diagnostics':
                $this->feedDiagnostics();
                break;
            case 'clear_cache':
                $this->clearCache();
                break;
            default:
                $this->jsonDie(false, 'Invalid action');
        }
    }

    private function isValidRequest()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return false;
        }

        $employee = $this->context->employee;
        if (!$employee || !$employee->id || !$employee->isLoggedBack()) {
            return false;
        }

        $token = Tools::getValue('cfb_token', '');
        if (empty($token)) {
            return false;
        }

        $module = Module::getInstanceByName('cffootballbypass');
        if (!$module || !($module instanceof CfFootballBypass)) {
            return false;
        }

        return $module->verifyAjaxToken($token);
    }

    private function getModule()
    {
        $module = Module::getInstanceByName('cffootballbypass');
        if (!$module || !($module instanceof CfFootballBypass)) {
            $this->jsonDie(false, 'Module not available');
        }
        return $module;
    }

    private function testConnection()
    {
        $module = $this->getModule();
        $log = [];
        $settings = $module->getSettings();

        $log[] = 'Auth: ' . ($settings['auth_type'] ?? 'global');
        $log[] = 'Zone: ' . $module->mask($settings['cloudflare_zone_id']);

        $trace = [];
        $testResult = $module->quickSettingsTest($settings, $trace);
        $log = array_merge($log, $trace);

        if ($testResult) {
            $log[] = 'Loading DNS records...';
            $records = $module->fetchDnsRecords(['A', 'AAAA', 'CNAME']);

            if (!empty($records)) {
                $module->persistDnsCache($records);
                $log[] = 'DNS records loaded: ' . count($records);

                $selected = $this->persistSelectedFromAjax($module);

                die(json_encode([
                    'success' => true,
                    'log' => $log,
                    'records' => $records,
                    'selected' => $selected,
                    'count' => count($records),
                ]));
            }
            $log[] = 'Could not load DNS records';
        }

        $this->jsonDie(false, 'Connection error', $log);
    }

    private function manualCheck()
    {
        $module = $this->getModule();
        $log = [];
        $log[] = 'Running manual check...';

        $this->persistSelectedFromAjax($module);
        $module->checkFootballAndManageCloudflare();
        $settings = $module->getSettings();

        $log[] = 'Check completed';

        die(json_encode([
            'success' => true,
            'log' => $log,
            'last' => $settings['last_check'] ?? '',
            'general' => $settings['last_status_general'] ?? 'NO',
            'domain' => $settings['last_status_domain'] ?? 'NO',
            'last_update' => $settings['last_update'] ?? '',
        ]));
    }

    private function getStatus()
    {
        $module = $this->getModule();
        $calc = $module->computeStatusesFromJson(true);

        die(json_encode([
            'success' => true,
            'general' => $calc['general'],
            'domain' => $calc['domain'],
            'ips' => $calc['domain_ips'],
            'last_update' => $calc['last_update'],
            'isps_blocked' => $calc['isps_blocked'] ?? [],
        ]));
    }

    private function forceProxyStatus($proxiedOn, $operationName)
    {
        $module = $this->getModule();
        $log = [];
        $log[] = $operationName . ': starting...';

        $selected = $this->persistSelectedFromAjax($module);
        if (empty($selected)) {
            $this->jsonDie(false, 'No records selected', $log);
        }

        $records = $module->fetchDnsRecords(['A', 'AAAA', 'CNAME']);
        if (!empty($records)) {
            $module->persistDnsCache($records);
        }

        $ok = 0;
        $fail = 0;
        $lines = [];

        foreach ($selected as $recordId) {
            $result = $module->updateRecordProxyStatus($recordId, $proxiedOn);
            if ($result) {
                $ok++;
                $lines[] = 'OK: ' . $recordId . ' -> ' . ($proxiedOn ? 'ON' : 'OFF');
            } else {
                $fail++;
                $lines[] = 'ERROR: ' . $recordId;
            }
        }

        $after = $module->fetchDnsRecords(['A', 'AAAA', 'CNAME']);
        if (!empty($after)) {
            $module->persistDnsCache($after);
        }

        $message = ($proxiedOn ? 'Proxy ON' : 'Proxy OFF') . " on {$ok} records" . ($fail ? "; failed: {$fail}" : '') . '.';

        $module->logEvent('manual', $operationName, [
            'user' => $module->currentUserLabel(),
            'processed' => count($selected),
            'ok' => $ok,
            'errors' => $fail,
        ]);

        die(json_encode([
            'success' => true,
            'message' => $message,
            'log' => array_merge($log, $lines, [$message]),
            'records' => $after ?: $records,
            'selected' => $selected,
        ]));
    }

    private function saveSelection()
    {
        $module = $this->getModule();
        $selected = $this->persistSelectedFromAjax($module);

        $module->logEvent('manual', 'DNS selection saved', [
            'user' => $module->currentUserLabel(),
            'count' => count($selected),
        ]);

        die(json_encode([
            'success' => true,
            'selected' => $selected,
            'count' => count($selected),
            'message' => count($selected)
                ? sprintf('Selection saved: %d record(s) managed.', count($selected))
                : 'Selection saved: no records selected.',
        ]));
    }

    private function cronDiagnostics()
    {
        $module = $this->getModule();
        $settings = $module->getSettings();
        $mins = max(5, min(60, (int) $settings['check_interval']));

        $msg = "Configured interval: {$mins} min\n";
        $msg .= "Last check: " . ($settings['last_check'] ?: '—') . "\n";
        $msg .= "General (IP blocks): " . ($settings['last_status_general'] ?: '—') . "\n";
        $msg .= "Domain blocked: " . ($settings['last_status_domain'] ?: '—') . "\n";
        $msg .= "Override active: " . (!empty($settings['force_proxy_off_override']) ? 'YES' : 'NO') . "\n";
        $msg .= "Last update (IP JSON): " . ($settings['last_update'] ?: '—') . "\n";
        $msg .= "Records synced: " . ($settings['dns_cache_last_sync'] ?: '—');

        die(json_encode(['success' => true, 'msg' => $msg]));
    }

    private function feedDiagnostics()
    {
        $module = $this->getModule();
        $diag = $module->getFeedDiagnostics();
        die(json_encode(['success' => true, 'diag' => $diag]));
    }

    private function clearCache()
    {
        $module = $this->getModule();
        $deleted = $module->clearDataCache();

        $module->logEvent('manual', 'Local data cache cleared', [
            'user' => $module->currentUserLabel(),
            'deleted' => $deleted,
        ]);

        $diag = $module->getFeedDiagnostics();

        die(json_encode([
            'success' => true,
            'message' => 'Cache cleared. Next check will download fresh data.',
            'diag' => $diag,
        ]));
    }

    private function persistSelectedFromAjax($module)
    {
        $rawSelected = Tools::getValue('selected', '');
        if (is_string($rawSelected)) {
            $selected = json_decode($rawSelected, true);
            if (!is_array($selected)) {
                $selected = [];
            }
        } else {
            $selected = is_array($rawSelected) ? $rawSelected : [];
        }

        $selected = array_filter(array_map(function ($v) {
            return preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $v);
        }, $selected));
        $selected = array_values($selected);

        $settings = $module->getSettings();
        $currentJson = $settings['selected_records'] ?? '[]';
        $current = json_decode($currentJson, true) ?: [];

        if ($selected !== $current) {
            $settings['selected_records'] = json_encode($selected);
            $module->saveSettings($settings);
        }

        return $selected;
    }

    private function jsonDie($success, $message, $log = [])
    {
        die(json_encode([
            'success' => $success,
            'message' => $message,
            'log' => $log,
        ]));
    }
}
