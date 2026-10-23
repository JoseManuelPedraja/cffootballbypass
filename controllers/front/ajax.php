<?php
/**
 * CF Football Bypass — AJAX Front Controller v2.0.0
 *
 * @author    Jose Manuel Pedraja <josemanuelpedraja@gmail.com>
 * @copyright 2025-2026 SurMod
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
        header('Content-Type: application/json; charset=utf-8');

        if (!$this->isAuthorizedEmployee()) {
            $this->jsonDie(false, 'Access denied. Please reload the backoffice page.');
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
            case 'feed_diagnostics':
                $this->feedDiagnostics();
                break;
            case 'clear_data_cache':
                $this->clearDataCache();
                break;
            case 'get_server_ip':
                $this->getServerIp();
                break;
            case 'update_selected_records':
                $this->updateSelectedRecords();
                break;
            default:
                $this->jsonDie(false, 'Invalid action');
        }
    }

    // ── Security ────────────────────────────────────────────────────────

    private function isAuthorizedEmployee()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return false;
        }

        if (!$this->context->employee || !$this->context->employee->isLoggedBack()) {
            return false;
        }

        $token = Tools::getValue('token', '');
        $expected = Tools::getAdminTokenLite('AdminModules');
        if (!$token || !hash_equals($expected, $token)) {
            return false;
        }

        return true;
    }

    // ── Module bridge ───────────────────────────────────────────────────

    private function getModuleInstance()
    {
        $module = Module::getInstanceByName('cffootballbypass');
        if (!$module || !($module instanceof CfFootballBypass)) {
            $this->jsonDie(false, 'Module not found');
        }
        return $module;
    }

    private function getSettings()
    {
        return $this->getModuleInstance()->getSettings();
    }

    private function saveSettings($settings)
    {
        $this->getModuleInstance()->saveSettings($settings);
    }

    // ── Actions ─────────────────────────────────────────────────────────

    private function testConnection()
    {
        $log = [];
        $module = $this->getModuleInstance();
        $settings = $this->getSettings();

        try {
            $authType = $settings['auth_type'] ?? 'global';
            $log[] = 'Auth type: ' . $this->authTypeLabel($authType);
            $log[] = 'Zone ID: ' . $this->maskString($settings['cloudflare_zone_id'] ?? '');

            if ($authType === 'account_token') {
                $accountId = $settings['cloudflare_account_id'] ?? '';
                if (empty($accountId)) {
                    $this->jsonDie(false, 'Account ID is required for Account Token auth', $log);
                }
                $log[] = 'Account ID: ' . $this->maskString($accountId);
            }

            $trace = [];
            $testResult = $module->quickSettingsTest($settings, $trace);
            $log = array_merge($log, $trace);

            if ($testResult) {
                $log[] = 'Loading DNS records...';
                $records = $module->fetchDnsRecords(['A', 'AAAA', 'CNAME']);

                if (!empty($records)) {
                    $settings['dns_records_cache'] = json_encode($records);
                    $settings['dns_cache_last_sync'] = date('Y-m-d H:i:s');
                    $this->saveSettings($settings);

                    $log[] = 'DNS records loaded: ' . count($records);

                    $selected = json_decode($settings['selected_records'] ?? '[]', true) ?: [];
                    $html = $this->renderDnsTable($records, $selected);

                    $this->jsonDie(true, null, $log, ['html' => $html]);
                } else {
                    $log[] = 'Could not load DNS records';
                    $this->jsonDie(false, 'Could not load DNS records', $log);
                }
            } else {
                $this->jsonDie(false, 'Connection error', $log);
            }
        } catch (Exception $e) {
            $log[] = 'Error: ' . $e->getMessage();
            $this->jsonDie(false, $e->getMessage(), $log);
        }
    }

    private function manualCheck()
    {
        $log = [];
        $module = $this->getModuleInstance();

        try {
            $log[] = 'Running manual check...';

            $this->persistSelectedFromAjax();

            $module->checkFootballAndManageCloudflare();
            $settings = $this->getSettings();
            $calc = $module->computeStatusesFromJson();

            $log[] = 'Check completed';
            $log[] = 'General blocked: ' . ($settings['last_status_general'] ?? '—');
            $log[] = 'Domain blocked: ' . ($settings['last_status_domain'] ?? '—');

            if (!empty($calc['isps_blocked'])) {
                $log[] = 'ISPs blocked: ' . implode(', ', $calc['isps_blocked']);
            }

            $data = [
                'last' => $settings['last_check'] ?? '',
                'general' => $settings['last_status_general'] ?? '—',
                'domain' => $settings['last_status_domain'] ?? '—',
                'last_update' => $settings['last_update'] ?? '',
                'bypass_active' => !empty($settings['bypass_active']),
                'isps_blocked' => $calc['isps_blocked'] ?? [],
            ];

            $this->jsonDie(true, null, $log, $data);
        } catch (Exception $e) {
            $log[] = 'Error: ' . $e->getMessage();
            $this->jsonDie(false, $e->getMessage(), $log);
        }
    }

    private function getStatus()
    {
        try {
            $module = $this->getModuleInstance();
            $calc = $module->computeStatusesFromJson();

            $this->jsonDie(true, null, [], [
                'general' => $calc['general'] ?? 'NO',
                'domain' => $calc['domain'] ?? 'NO',
                'ips' => $calc['domain_ips'] ?? [],
                'last_update' => $calc['last_update'] ?? '',
                'isps_blocked' => $calc['isps_blocked'] ?? [],
            ]);
        } catch (Exception $e) {
            $this->jsonDie(false, $e->getMessage());
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

    private function forceProxyStatus($proxiedOn, $operationName)
    {
        $log = [];
        $module = $this->getModuleInstance();

        try {
            $log[] = $operationName . ': starting...';

            $selected = $this->persistSelectedFromAjax();
            if (empty($selected)) {
                $this->jsonDie(false, 'No records selected', $log);
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

            foreach ($selected as $recordId) {
                $result = $module->updateRecordProxyStatus($recordId, $proxiedOn);
                if (is_array($result) && !empty($result['success'])) {
                    $ok++;
                    $lines[] = 'OK: ' . $this->maskString($recordId, 8, 4) . ' -> ' . ($proxiedOn ? 'ON' : 'OFF');
                } else {
                    $fail++;
                    $error = is_array($result) ? ($result['error'] ?? 'unknown') : 'unknown';
                    $lines[] = 'ERROR: ' . $this->maskString($recordId, 8, 4) . ' (' . $error . ')';
                }
            }

            $settings = $this->getSettings();
            $updatedRecords = json_decode($settings['dns_records_cache'] ?? '[]', true) ?: [];
            $html = $this->renderDnsTable($updatedRecords, $selected);

            $message = ($proxiedOn ? 'Proxy ON' : 'Proxy OFF') . " on {$ok} records"
                . ($fail ? "; failed: {$fail}" : '') . '.';

            $remoteAddr = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
            $module->logEvent($proxiedOn ? 'force_on' : 'force_off', $message, [
                'records_ok' => $ok,
                'records_fail' => $fail,
                'ip' => $this->anonymizeIp($remoteAddr),
                'employee' => $this->context->employee ? $this->context->employee->email : 'unknown',
            ]);

            $this->jsonDie(true, $message, array_merge($log, $lines, [$message]), ['html' => $html]);
        } catch (Exception $e) {
            $log[] = 'Error: ' . $e->getMessage();
            $this->jsonDie(false, $e->getMessage(), $log);
        }
    }

    private function cronDiagnostics()
    {
        try {
            $settings = $this->getSettings();
            $mins = max(5, min(60, (int)($settings['check_interval'] ?? 15)));

            $lines = [];
            $lines[] = "Check interval: {$mins} min";
            $lines[] = 'Last check: ' . ($settings['last_check'] ?? '—');
            $lines[] = 'General (IP blocks): ' . ($settings['last_status_general'] ?? '—');
            $lines[] = 'Domain blocked: ' . ($settings['last_status_domain'] ?? '—');
            $lines[] = 'Last feed update: ' . ($settings['last_update'] ?? '—');
            $lines[] = 'DNS cache synced: ' . ($settings['dns_cache_last_sync'] ?? '—');
            $lines[] = '---';
            $lines[] = 'Bypass active: ' . (!empty($settings['bypass_active']) ? 'YES' : 'NO');
            $lines[] = 'Cooldown: ' . ($settings['bypass_check_cooldown'] ?? 60) . ' min';
            $lines[] = 'Stale threshold: ' . ($settings['stale_threshold_hours'] ?? 6) . ' hours';
            $lines[] = 'Min ISPs blocked: ' . ($settings['min_isps_blocked'] ?? 2);
            $lines[] = 'Force OFF override: ' . (!empty($settings['force_proxy_off_override']) ? 'ON' : 'OFF');
            $lines[] = 'Email notifications: ' . (!empty($settings['email_notifications']) ? 'ON' : 'OFF');

            if (!empty($settings['email_notifications'])) {
                $email = !empty($settings['notification_email']) ? $settings['notification_email'] : '(shop admin email)';
                $lines[] = 'Notification email: ' . $email;
            }

            $lines[] = 'UI mode: ' . ($settings['ui_mode'] ?? 'simple');
            $lines[] = 'Auth type: ' . $this->authTypeLabel($settings['auth_type'] ?? 'global');
            $lines[] = 'Selected records: ' . count(json_decode($settings['selected_records'] ?? '[]', true) ?: []);

            $cronToken = $settings['cron_secret'] ?? '';
            $lines[] = 'Cron token: ' . ($cronToken ? $this->maskString($cronToken) : 'NOT SET');

            $this->jsonDie(true, null, [], ['msg' => implode("\n", $lines)]);
        } catch (Exception $e) {
            $this->jsonDie(false, $e->getMessage());
        }
    }

    private function feedDiagnostics()
    {
        $log = [];

        try {
            $feedUrl = 'https://hayahora.futbol/estado/data.json';
            $log[] = 'Feed URL: ' . $feedUrl;

            $localCachePath = _PS_MODULE_DIR_ . 'cffootballbypass/cache/data.json';
            if (file_exists($localCachePath)) {
                $cacheSize = filesize($localCachePath);
                $cacheMtime = filemtime($localCachePath);
                $cacheAge = time() - $cacheMtime;
                $log[] = 'Local cache: EXISTS (' . $this->humanFileSize($cacheSize) . ')';
                $log[] = 'Cache age: ' . $this->humanDuration($cacheAge);
                $log[] = 'Cache modified: ' . date('Y-m-d H:i:s', $cacheMtime);

                $cacheContent = @file_get_contents($localCachePath);
                if ($cacheContent) {
                    $cacheJson = json_decode($cacheContent, true);
                    if (is_array($cacheJson) && !empty($cacheJson['lastUpdate'])) {
                        $log[] = 'Cache lastUpdate: ' . $cacheJson['lastUpdate'];
                    }
                }
            } else {
                $log[] = 'Local cache: NOT FOUND';
            }

            $log[] = '---';
            $log[] = 'Testing remote feed...';

            $ch = curl_init($feedUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_USERAGENT, 'CFB-PrestaShop/2.0.0');
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $totalTime = round(curl_getinfo($ch, CURLINFO_TOTAL_TIME), 2);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                $log[] = 'Remote: UNREACHABLE — ' . $curlError;
            } else {
                $log[] = 'Remote: HTTP ' . $httpCode . ' (' . $totalTime . 's)';

                if ($httpCode === 200) {
                    $remoteJson = json_decode($response, true);
                    if (is_array($remoteJson)) {
                        $log[] = 'Remote JSON: VALID';
                        if (!empty($remoteJson['lastUpdate'])) {
                            $log[] = 'Remote lastUpdate: ' . $remoteJson['lastUpdate'];
                        }
                        $ipCount = 0;
                        if (isset($remoteJson['ips']) && is_array($remoteJson['ips'])) {
                            $ipCount = count($remoteJson['ips']);
                        }
                        $log[] = 'IPs in feed: ' . $ipCount;

                        $blockedCount = 0;
                        if (isset($remoteJson['ips']) && is_array($remoteJson['ips'])) {
                            foreach ($remoteJson['ips'] as $status) {
                                $blocked = false;
                                if (is_bool($status)) {
                                    $blocked = $status;
                                } elseif (is_array($status) && isset($status['blocked'])) {
                                    $blocked = (bool)$status['blocked'];
                                }
                                if ($blocked) {
                                    $blockedCount++;
                                }
                            }
                        }
                        $log[] = 'Blocked IPs: ' . $blockedCount;
                    } else {
                        $log[] = 'Remote JSON: INVALID (parse error)';
                    }
                } else {
                    $log[] = 'Remote: unexpected HTTP code';
                }
            }

            $this->jsonDie(true, null, $log, ['msg' => implode("\n", $log)]);
        } catch (Exception $e) {
            $log[] = 'Error: ' . $e->getMessage();
            $this->jsonDie(false, $e->getMessage(), $log);
        }
    }

    private function clearDataCache()
    {
        $log = [];

        try {
            $localCachePath = _PS_MODULE_DIR_ . 'cffootballbypass/cache/data.json';
            if (file_exists($localCachePath)) {
                if (@unlink($localCachePath)) {
                    $log[] = 'Local cache deleted.';
                } else {
                    $log[] = 'ERROR: Could not delete local cache.';
                }
            } else {
                $log[] = 'No local cache to delete.';
            }

            $settings = $this->getSettings();
            $settings['feed_last_fetch_time'] = 0;
            $settings['feed_last_fetch_ok'] = 0;
            $this->saveSettings($settings);
            $log[] = 'Feed fetch throttle cleared.';

            $this->getModuleInstance()->logEvent('clear_cache', 'Data cache cleared', [
                'ip' => $this->anonymizeIp($_SERVER['REMOTE_ADDR'] ?? ''),
                'employee' => $this->context->employee ? $this->context->employee->email : 'unknown',
            ]);

            $this->jsonDie(true, 'Cache cleared', $log);
        } catch (Exception $e) {
            $log[] = 'Error: ' . $e->getMessage();
            $this->jsonDie(false, $e->getMessage(), $log);
        }
    }

    private function getServerIp()
    {
        try {
            $ips = $this->getServerOutgoingIps();

            if (!empty($ips)) {
                $this->jsonDie(true, null, [], [
                    'ips' => $ips,
                    'primary' => $ips[0],
                ]);
            } else {
                $this->jsonDie(false, 'Could not detect server outgoing IP');
            }
        } catch (Exception $e) {
            $this->jsonDie(false, $e->getMessage());
        }
    }

    private function updateSelectedRecords()
    {
        try {
            $selected = $this->persistSelectedFromAjax();
            $this->jsonDie(true, null, [], [
                'selected' => $selected,
                'count' => count($selected),
            ]);
        } catch (Exception $e) {
            $this->jsonDie(false, $e->getMessage());
        }
    }

    // ── Selected records persistence ────────────────────────────────────

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

        $selected = array_values(array_unique(array_filter(array_map(function ($v) {
            return preg_replace('/[^a-f0-9]/', '', strtolower((string)$v));
        }, $selected))));

        $cache = json_decode($settings['dns_records_cache'] ?? '[]', true) ?: [];
        if (!empty($cache)) {
            $knownIds = array_column($cache, 'id');
            $selected = array_values(array_intersect($selected, $knownIds));
        }

        $current = json_decode($settings['selected_records'] ?? '[]', true) ?: [];
        if ($selected !== $current) {
            $settings['selected_records'] = json_encode($selected);
            $this->saveSettings($settings);
        }

        return $selected;
    }

    // ── DNS table rendering ─────────────────────────────────────────────

    private function renderDnsTable($records, $selected)
    {
        $typeCounts = [];
        foreach ($records as $r) {
            $t = $r['type'] ?? '';
            if (!isset($typeCounts[$t])) {
                $typeCounts[$t] = 0;
            }
            $typeCounts[$t]++;
        }

        $html = '<div class="cfb-dns-toolbar" style="margin-bottom:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">';

        $html .= '<span style="font-size:12px;font-weight:600;color:#666;">FILTER:</span>';
        foreach ($typeCounts as $type => $count) {
            $html .= '<button type="button" class="btn btn-xs btn-default cfb-type-filter" data-type="'
                . htmlspecialchars($type, ENT_QUOTES, 'UTF-8')
                . '">' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8')
                . ' <span class="badge">' . (int)$count . '</span></button>';
        }
        $html .= '<button type="button" class="btn btn-xs btn-default cfb-type-filter cfb-filter-active" data-type="all">ALL <span class="badge">' . count($records) . '</span></button>';

        $html .= '<span style="margin-left:auto;font-size:12px;font-weight:600;color:#666;">SELECT:</span>';
        $html .= '<button type="button" class="btn btn-xs btn-default" onclick="cfbSelectAll()">All</button>';
        $html .= '<button type="button" class="btn btn-xs btn-default" onclick="cfbSelectNone()">None</button>';
        $html .= '</div>';

        $html .= '<table class="table table-striped cfb-dns-table">';
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
            $proxiedClass = isset($record['proxied'])
                ? ($record['proxied'] ? 'badge-success' : 'badge-default')
                : 'badge-warning';
            $ttl = $record['ttl'] ?? '';
            $checked = in_array($id, $selected) ? ' checked' : '';

            $html .= '<tr data-record-type="' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '">';
            $html .= '<td><input type="checkbox" name="selected_records[]" value="'
                . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"' . $checked . '></td>';
            $html .= '<td>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td><span class="badge badge-info">' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '</span></td>';
            $html .= '<td>' . htmlspecialchars($content, ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td><span class="badge ' . $proxiedClass . '">' . htmlspecialchars($proxied, ENT_QUOTES, 'UTF-8') . '</span></td>';
            $html .= '<td>' . htmlspecialchars((string)$ttl, ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        $html .= '<div style="font-size:12px;color:#888;">Total records: ' . count($records) . '</div>';

        return $html;
    }

    // ── Utilities ────────────────────────────────────────────────────────

    private function anonymizeIp($ip)
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

    private function authTypeLabel($type)
    {
        switch ($type) {
            case 'global':
                return 'Global API Key';
            case 'token':
                return 'API Token (Bearer)';
            case 'account_token':
                return 'Account API Token';
            default:
                return ucfirst($type);
        }
    }

    private function getServerOutgoingIps()
    {
        $services = [
            'https://api.ipify.org',
            'https://checkip.amazonaws.com',
            'https://icanhazip.com',
        ];

        $ips = [];
        foreach ($services as $url) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response !== false && $httpCode === 200) {
                $ip = trim($response);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    $ips[] = $ip;
                }
            }
        }

        return array_values(array_unique($ips));
    }

    private function humanFileSize($bytes)
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return round($bytes / 1048576, 1) . ' MB';
    }

    private function humanDuration($seconds)
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }
        if ($seconds < 3600) {
            return round($seconds / 60) . 'min';
        }
        if ($seconds < 86400) {
            return round($seconds / 3600, 1) . 'h';
        }
        return round($seconds / 86400, 1) . 'd';
    }

    private function jsonDie($success, $message = null, $log = [], $extra = [])
    {
        $response = ['success' => $success];

        if ($message !== null) {
            $response['message'] = $message;
        }
        if (!empty($log)) {
            $response['log'] = $log;
        }
        if (!empty($extra)) {
            $response = array_merge($response, $extra);
        }

        die(json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
