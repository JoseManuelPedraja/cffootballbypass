<?php
/**
 * 2007-2026 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/afl-3.0.php
 *
 * @author    Jose Manuel Pedraja <josemanuelpedraja@gmail.com>
 * @copyright 2007-2026 PrestaShop SA
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class CfFootballBypass extends Module
{
    private $log_dir_path;
    private $log_file_path;
    private $data_dir_path;
    private $settings_cache;
    private $feed_fetch_throttle = 60;

    public function __construct()
    {
        $this->name = 'cffootballbypass';
        $this->tab = 'administration';
        $this->version = '2.0.0';
        $this->author = 'Jose Manuel Pedraja';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = [
            'min' => '8.1.0',
            'max' => _PS_VERSION_,
        ];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('CF Football Bypass');
        $this->description = $this->l('Operates with Cloudflare to toggle Proxy (ON/CDN) and DNS Only (OFF) based on La Liga IP blocks, with persistent DNS cache and AJAX controls.');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall this module? Check "Delete data on uninstall" in settings to also remove configuration.');

        $this->log_dir_path = _PS_MODULE_DIR_ . $this->name . '/logs';
        $this->data_dir_path = _PS_MODULE_DIR_ . $this->name . '/data';
        $this->log_file_path = '';
        $this->settings_cache = null;
    }

    public function install()
    {
        if (Shop::isFeatureActive()) {
            Shop::setContext(Shop::CONTEXT_ALL);
        }

        return parent::install()
            && $this->ensureLogDirectory()
            && $this->ensureDataDirectory()
            && $this->setDefaultConfiguration()
            && $this->registerHook('actionCronJob');
    }

    public function uninstall()
    {
        $settings = $this->getSettings();
        $delete_data = !empty($settings['delete_data_on_uninstall']);

        if (!parent::uninstall()) {
            return false;
        }

        if ($delete_data) {
            $this->removeConfiguration();
            $this->removeLogFiles();
            $this->removeDataFiles();
        }

        return true;
    }

    private function ensureLogDirectory()
    {
        if (!file_exists($this->log_dir_path)) {
            if (!mkdir($this->log_dir_path, 0755, true)) {
                return false;
            }
        }

        $htaccess = $this->log_dir_path . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Order Allow,Deny\nDeny from all\n");
        }

        $index = $this->log_dir_path . '/index.php';
        if (!file_exists($index)) {
            file_put_contents($index, '<?php // Silence is golden');
        }

        $this->migrateLogFile();

        return true;
    }

    private function ensureDataDirectory()
    {
        if (!file_exists($this->data_dir_path)) {
            if (!mkdir($this->data_dir_path, 0755, true)) {
                return false;
            }
        }

        $index = $this->data_dir_path . '/index.php';
        if (!file_exists($index)) {
            file_put_contents($index, '<?php // Silence is golden');
        }

        return true;
    }

    private function migrateLogFile()
    {
        $legacy = $this->log_dir_path . '/cfb-actions.log';
        $target = $this->getLogFilePath();
        if ($target !== $legacy && file_exists($legacy) && !file_exists($target)) {
            rename($legacy, $target);
        }
    }

    private function getLogFilePath()
    {
        if (!empty($this->log_file_path)) {
            return $this->log_file_path;
        }
        $s = $this->getSettings();
        $secret = (!empty($s['log_secret']) && is_string($s['log_secret'])) ? $s['log_secret'] : '';
        $name = $secret ? 'cfb-actions-' . $secret . '.log' : 'cfb-actions.log';
        $this->log_file_path = $this->log_dir_path . '/' . $name;
        return $this->log_file_path;
    }

    private function setDefaultConfiguration()
    {
        $defaults = $this->getDefaultSettings(true);
        foreach ($defaults as $key => $value) {
            Configuration::updateValue('CFB_' . strtoupper($key), is_array($value) ? json_encode($value) : $value);
        }
        return true;
    }

    private function removeConfiguration()
    {
        $defaults = $this->getDefaultSettings();
        foreach (array_keys($defaults) as $key) {
            Configuration::deleteByName('CFB_' . strtoupper($key));
        }
        return true;
    }

    private function removeLogFiles()
    {
        if (is_dir($this->log_dir_path)) {
            $files = glob($this->log_dir_path . '/*');
            if (is_array($files)) {
                foreach ($files as $f) {
                    if (is_file($f)) {
                        unlink($f);
                    }
                }
            }
        }
    }

    private function removeDataFiles()
    {
        if (is_dir($this->data_dir_path)) {
            $files = glob($this->data_dir_path . '/*');
            if (is_array($files)) {
                foreach ($files as $f) {
                    if (is_file($f)) {
                        unlink($f);
                    }
                }
            }
        }
    }

    /* ================== Utilities ================== */

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
        $s = (string) $str;
        $len = strlen($s);
        if ($len <= $left + $right) {
            return str_repeat('*', max(0, $len));
        }
        return substr($s, 0, $left) . str_repeat('*', $len - $left - $right) . substr($s, -$right);
    }

    private function normalizeBoolLike($v)
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v)) {
            return 0 !== $v;
        }
        if (is_string($v)) {
            $vv = strtolower(trim($v));
            if (in_array($vv, ['1', 'true', 'yes', 'si', 'on', 'blocked', 'proxy', 'cdn'], true)) {
                return true;
            }
            if (in_array($vv, ['0', 'false', 'no', 'off', 'unblocked'], true)) {
                return false;
            }
        }
        return null;
    }

    private function generateCronSecret()
    {
        return bin2hex(random_bytes(16));
    }

    private function generateLogSecret()
    {
        return bin2hex(random_bytes(8));
    }

    public function getSiteDomain()
    {
        return Tools::getShopDomain();
    }

    private function isLocalDomain($domain = null)
    {
        if ($domain === null) {
            $domain = $this->getSiteDomain();
        }
        if (empty($domain)) {
            return true;
        }
        if (filter_var($domain, FILTER_VALIDATE_IP)) {
            return true;
        }
        $local = ['localhost', 'localhost.localdomain'];
        if (in_array(strtolower($domain), $local, true)) {
            return true;
        }
        if (preg_match('/\.(local|test|ddev\.site|lndo\.site)$/i', $domain)) {
            return true;
        }
        return false;
    }

    public function getServerOutgoingIps()
    {
        $cache_key = 'CFB_SERVER_OUTGOING_IPS';
        $cached = Configuration::get($cache_key);
        if ($cached) {
            $data = json_decode($cached, true);
            if (is_array($data) && !empty($data['ips']) && (time() - ($data['ts'] ?? 0)) < 3600) {
                return $data['ips'];
            }
        }

        $services = [
            'https://api.ipify.org',
            'https://checkip.amazonaws.com',
            'https://icanhazip.com',
        ];
        $ips = [];
        foreach ($services as $url) {
            $resp = $this->makeHttpRequest($url, 'GET', [], null, 10);
            if ($resp && !empty($resp['raw_body'])) {
                $ip = trim($resp['raw_body']);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    $ips[] = $ip;
                }
            }
        }
        $ips = array_values(array_unique($ips));
        if (!empty($ips)) {
            Configuration::updateValue($cache_key, json_encode(['ips' => $ips, 'ts' => time()]));
        }
        return $ips;
    }

    /* ================== Settings ================== */

    private function getDefaultSettings($force_new_token = false)
    {
        $cron_secret = $force_new_token ? $this->generateCronSecret() : '';
        $log_secret = $force_new_token ? $this->generateLogSecret() : '';

        return [
            'cloudflare_email' => '',
            'cloudflare_api_key' => '',
            'cloudflare_zone_id' => '',
            'cloudflare_account_id' => '',
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
            'log_secret' => $log_secret,
            'cron_secret' => $cron_secret,
            'bypass_active' => 0,
            'bypass_blocked_ips' => '[]',
            'bypass_check_cooldown' => 60,
            'bypass_last_change' => 0,
            'force_proxy_off_override' => 0,
            'stale_threshold_hours' => 6,
            'min_isps_blocked' => 2,
            'email_notifications' => 0,
            'notification_email' => '',
            'ui_mode' => 'simple',
            'delete_data_on_uninstall' => 1,
        ];
    }

    public function getSettings()
    {
        if ($this->settings_cache !== null) {
            return $this->settings_cache;
        }

        $defaults = $this->getDefaultSettings();
        $settings = [];
        $changed = false;

        foreach ($defaults as $key => $default) {
            $value = Configuration::get('CFB_' . strtoupper($key));
            if ($value === false) {
                $value = $default;
                $changed = true;
            }
            $settings[$key] = $value;
        }

        $settings = $this->normalizeSettings($settings, $changed);

        $this->settings_cache = $settings;
        return $settings;
    }

    private function normalizeSettings($settings, $changed = false)
    {
        $auth = $settings['auth_type'];
        if (!in_array($auth, ['global', 'token', 'account_token'], true)) {
            $settings['auth_type'] = 'global';
            $changed = true;
        }

        $mins = max(5, min(60, (int) $settings['check_interval']));
        if ((int) $settings['check_interval'] !== $mins) {
            $settings['check_interval'] = $mins;
            $changed = true;
        }

        $cooldown = max(5, min(1440, (int) $settings['bypass_check_cooldown']));
        if ((int) $settings['bypass_check_cooldown'] !== $cooldown) {
            $settings['bypass_check_cooldown'] = $cooldown;
            $changed = true;
        }

        $stale = max(1, min(72, (int) $settings['stale_threshold_hours']));
        if ((int) $settings['stale_threshold_hours'] !== $stale) {
            $settings['stale_threshold_hours'] = $stale;
            $changed = true;
        }

        $min_isps = max(1, min(20, (int) $settings['min_isps_blocked']));
        if ((int) $settings['min_isps_blocked'] !== $min_isps) {
            $settings['min_isps_blocked'] = $min_isps;
            $changed = true;
        }

        $ui = $settings['ui_mode'];
        if (!in_array($ui, ['simple', 'advanced'], true)) {
            $settings['ui_mode'] = 'simple';
            $changed = true;
        }

        foreach (['last_status_general', 'last_status_domain'] as $k) {
            $v = strtoupper((string) $settings[$k]);
            if (!in_array($v, ['YES', 'NO'], true)) {
                $settings[$k] = 'NO';
                $changed = true;
            }
        }

        if ($changed) {
            $this->saveSettings($settings);
        }

        return $settings;
    }

    public function saveSettings($settings)
    {
        $this->settings_cache = null;
        $this->log_file_path = '';
        foreach ($settings as $key => $value) {
            Configuration::updateValue('CFB_' . strtoupper($key), is_array($value) ? json_encode($value) : $value);
        }
    }

    /* ================== Logging ================== */

    public function logEvent($type, $message, $context = [])
    {
        $settings = $this->getSettings();
        if (empty($settings['logging_enabled'])) {
            return;
        }

        $this->ensureLogDirectory();
        $path = $this->getLogFilePath();

        $entry = [
            'time' => date('Y-m-d H:i:s'),
            'type' => $type,
            'message' => $message,
        ];

        if (!empty($context)) {
            $entry['context'] = $context;
        }

        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($line === false) {
            return;
        }
        $line .= "\n";

        file_put_contents($path, $line, FILE_APPEND | LOCK_EX);

        $this->pruneLogs();
    }

    private function pruneLogs()
    {
        static $pruned = false;
        if ($pruned) {
            return;
        }
        $pruned = true;

        $settings = $this->getSettings();
        $days = max(1, (int) ($settings['log_retention_days'] ?? 30));
        $path = $this->getLogFilePath();

        if (!file_exists($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            return;
        }

        $cutoff = time() - ($days * 86400);
        $filtered = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $keep = true;
            $entry = json_decode($line, true);
            if (is_array($entry) && !empty($entry['time'])) {
                $ts = strtotime($entry['time']);
                if ($ts && $ts < $cutoff) {
                    $keep = false;
                }
            }
            if ($keep) {
                $filtered[] = $line;
            }
        }

        if (count($filtered) === count($lines)) {
            return;
        }

        $data = $filtered ? implode("\n", $filtered) . "\n" : '';
        file_put_contents($path, $data, LOCK_EX);
    }

    public function readLogEntries($limit = 200)
    {
        $path = $this->getLogFilePath();
        if (!file_exists($path) || !is_readable($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }

        $entries = [];
        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if (!is_array($entry)) {
                $entry = ['time' => '', 'type' => 'info', 'message' => $line];
            }
            $entries[] = $entry;
        }

        usort($entries, function ($a, $b) {
            $ta = isset($a['time']) ? strtotime((string) $a['time']) : 0;
            $tb = isset($b['time']) ? strtotime((string) $b['time']) : 0;
            return $tb - $ta;
        });

        if ($limit > 0 && count($entries) > $limit) {
            $entries = array_slice($entries, 0, $limit);
        }

        return $entries;
    }

    public function clearLogs()
    {
        $path = $this->getLogFilePath();
        if (file_exists($path)) {
            unlink($path);
        }
        $legacy = $this->log_dir_path . '/cfb-actions.log';
        if (file_exists($legacy)) {
            unlink($legacy);
        }
    }

    /* ================== HTTP & Cloudflare API ================== */

    private function getApiHeaders($settings = null)
    {
        if ($settings === null) {
            $settings = $this->getSettings();
        }

        $headers = ['Content-Type: application/json'];

        switch ($settings['auth_type']) {
            case 'global':
                $headers[] = 'X-Auth-Email: ' . $settings['cloudflare_email'];
                $headers[] = 'X-Auth-Key: ' . $settings['cloudflare_api_key'];
                break;
            case 'token':
            case 'account_token':
                $headers[] = 'Authorization: Bearer ' . $settings['cloudflare_api_key'];
                break;
        }

        return $headers;
    }

    private function makeHttpRequest($url, $method = 'GET', $headers = [], $data = null, $timeout = 30)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'CFB-PrestaShop/' . $this->version);

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($data !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            }
        }

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'error' => 'HTTP request failed: ' . $curl_error, 'raw_body' => ''];
        }

        $json = json_decode($response, true);

        $is_cf_api = (strpos($url, 'api.cloudflare.com') !== false);

        if ($is_cf_api) {
            if (!is_array($json)) {
                return ['success' => false, 'error' => 'Invalid JSON response', 'data' => null, 'raw_body' => $response];
            }
            return [
                'success' => $http_code === 200 && !empty($json['success']),
                'data' => $json,
                'error' => !empty($json['errors'][0]['message']) ? $json['errors'][0]['message'] : null,
                'raw_body' => $response,
            ];
        }

        return [
            'success' => $http_code === 200,
            'data' => $json,
            'raw_body' => $response,
            'error' => ($http_code !== 200) ? 'HTTP ' . $http_code : null,
        ];
    }

    public function quickSettingsTest($settings, &$trace)
    {
        if (empty($settings['cloudflare_api_key'])) {
            $trace[] = $this->l('Missing API Key/Token.');
            return false;
        }
        if (empty($settings['cloudflare_zone_id'])) {
            $trace[] = $this->l('Missing Zone ID.');
            return false;
        }
        if ($settings['auth_type'] === 'global' && empty($settings['cloudflare_email'])) {
            $trace[] = $this->l('Missing email for Global API Key.');
            return false;
        }
        if ($settings['auth_type'] === 'account_token' && empty($settings['cloudflare_account_id'])) {
            $trace[] = $this->l('Missing Account ID for Account Token auth.');
            return false;
        }

        $headers = $this->getApiHeaders($settings);

        if ($settings['auth_type'] === 'token' || $settings['auth_type'] === 'account_token') {
            $url = 'https://api.cloudflare.com/client/v4/user/tokens/verify';
        } else {
            $url = 'https://api.cloudflare.com/client/v4/user';
        }

        $response = $this->makeHttpRequest($url, 'GET', $headers);
        if (!$response || !$response['success']) {
            $trace[] = $this->l('Authentication error: ') . ($response['error'] ?? 'unknown');
            return false;
        }

        $url = 'https://api.cloudflare.com/client/v4/zones/' . $settings['cloudflare_zone_id'];
        $response = $this->makeHttpRequest($url, 'GET', $headers);
        if (!$response || !$response['success']) {
            $trace[] = $this->l('Error accessing zone: ') . ($response['error'] ?? 'unknown');
            return false;
        }

        $trace[] = $this->l('Connection OK and DNS read available.');
        return true;
    }

    /* ================== DNS Records ================== */

    public function fetchDnsRecords($allowed_types = ['A', 'AAAA', 'CNAME'])
    {
        $settings = $this->getSettings();
        if (empty($settings['cloudflare_api_key']) || empty($settings['cloudflare_zone_id'])) {
            return [];
        }

        $headers = $this->getApiHeaders($settings);
        $url = 'https://api.cloudflare.com/client/v4/zones/' . $settings['cloudflare_zone_id'] . '/dns_records?per_page=100';

        $response = $this->makeHttpRequest($url, 'GET', $headers);
        if (!$response || !$response['success']) {
            return [];
        }

        $records = [];
        if (isset($response['data']['result'])) {
            foreach ($response['data']['result'] as $record) {
                $type = strtoupper($record['type'] ?? '');
                if (!in_array($type, $allowed_types)) {
                    continue;
                }

                $records[] = [
                    'id' => (string) ($record['id'] ?? ''),
                    'name' => (string) ($record['name'] ?? ''),
                    'type' => $type,
                    'content' => (string) ($record['content'] ?? ''),
                    'proxied' => isset($record['proxied']) ? (bool) $record['proxied'] : null,
                    'ttl' => (int) ($record['ttl'] ?? 1),
                ];
            }
        }

        return $records;
    }

    public function refreshDnsCache()
    {
        $settings = $this->getSettings();
        $records = $this->fetchDnsRecords(['A', 'AAAA', 'CNAME']);

        if (!empty($records)) {
            $settings['dns_records_cache'] = json_encode($records);
            $settings['dns_cache_last_sync'] = date('Y-m-d H:i:s');
            $this->saveSettings($settings);
        }

        return $records;
    }

    public function updateRecordProxyStatus($record_id, $proxied_on)
    {
        $settings = $this->getSettings();
        $cache = json_decode($settings['dns_records_cache'], true) ?: [];

        $existing = null;
        foreach ($cache as $record) {
            if (!empty($record['id']) && $record['id'] === $record_id) {
                $existing = $record;
                break;
            }
        }

        if (!$existing) {
            $this->refreshDnsCache();
            $settings = $this->getSettings();
            $cache = json_decode($settings['dns_records_cache'], true) ?: [];

            foreach ($cache as $record) {
                if (!empty($record['id']) && $record['id'] === $record_id) {
                    $existing = $record;
                    break;
                }
            }

            if (!$existing) {
                return ['success' => false, 'error' => 'record_not_found', 'skipped' => false];
            }
        }

        $type = strtoupper($existing['type'] ?? '');
        if (!in_array($type, ['A', 'AAAA', 'CNAME'])) {
            return ['success' => false, 'error' => 'unsupported_type', 'skipped' => false];
        }

        if (isset($existing['proxied']) && (bool) $existing['proxied'] === (bool) $proxied_on) {
            return ['success' => true, 'skipped' => true, 'record' => $existing];
        }

        $headers = $this->getApiHeaders($settings);
        $url = 'https://api.cloudflare.com/client/v4/zones/' . $settings['cloudflare_zone_id'] . '/dns_records/' . $record_id;

        $ttl = (int) ($existing['ttl'] ?? 1);
        if ($proxied_on) {
            $ttl = 1;
        }

        $payload = json_encode([
            'type' => $type,
            'name' => $existing['name'] ?? '',
            'content' => $existing['content'] ?? '',
            'ttl' => $ttl,
            'proxied' => (bool) $proxied_on,
        ]);

        $response = $this->makeHttpRequest($url, 'PUT', $headers, $payload);
        if (!$response || !$response['success']) {
            return ['success' => false, 'error' => $response['error'] ?? 'api_error', 'skipped' => false, 'record' => $existing];
        }

        foreach ($cache as &$rec) {
            if (!empty($rec['id']) && $rec['id'] === $record_id) {
                $rec['proxied'] = (bool) $proxied_on;
                $rec['ttl'] = $ttl;
                break;
            }
        }
        unset($rec);

        $settings['dns_records_cache'] = json_encode($cache);
        $this->saveSettings($settings);

        $existing['proxied'] = (bool) $proxied_on;
        $existing['ttl'] = $ttl;
        return ['success' => true, 'skipped' => false, 'record' => $existing];
    }

    /* ================== Football Status (JSON Feed) ================== */

    public function computeStatusesFromJson($force_refresh_ips = false)
    {
        $domain = $this->getSiteDomain();
        $data = $this->fetchStatusJson();

        if ($data === null) {
            return [
                'general' => 'NO',
                'domain' => 'NO',
                'fresh' => false,
                'domain_ips' => [],
                'blocked_domain_ips' => [],
                'last_update' => '',
                'isps_blocked' => [],
                'min_isps' => 2,
            ];
        }

        $map = $data['ip_map'] ?? [];
        $isps_blocked = $data['isps_blocked'] ?? [];
        $last_update_str = $data['last_update'] ?? '';
        $settings = $this->getSettings();
        $min_isps = max(1, (int) ($settings['min_isps_blocked'] ?? 2));

        $blocked_ip_count = 0;
        foreach ($map as $blocked) {
            if ($blocked === true) {
                $blocked_ip_count++;
            }
        }

        if ($blocked_ip_count > 0 && empty($isps_blocked)) {
            $general_blocked = true;
        } else {
            $general_blocked = ($blocked_ip_count > 0) && (count($isps_blocked) >= $min_isps);
        }

        $resolved_ips = $this->resolveDomainIps($domain);
        $blocked_domain_ips = [];
        $domain_blocked = false;
        foreach ($resolved_ips as $ip) {
            if (isset($map[$ip]) && $map[$ip] === true) {
                $domain_blocked = true;
                $blocked_domain_ips[] = $ip;
            }
        }

        return [
            'general' => $general_blocked ? 'YES' : 'NO',
            'domain' => $domain_blocked ? 'YES' : 'NO',
            'fresh' => $data['fresh'] ?? false,
            'domain_ips' => $resolved_ips,
            'blocked_domain_ips' => $blocked_domain_ips,
            'last_update' => $last_update_str,
            'isps_blocked' => $isps_blocked,
            'min_isps' => $min_isps,
        ];
    }

    private function fetchStatusJson()
    {
        $this->ensureDataDirectory();
        $local_path = $this->data_dir_path . '/data.json';

        $local_body = null;
        $last_local = null;
        if (file_exists($local_path) && is_readable($local_path) && filesize($local_path) > 0) {
            $local_body = file_get_contents($local_path);
            if ($local_body !== false) {
                $json_local = json_decode($local_body, true);
                if (is_array($json_local) && !empty($json_local['lastUpdate']) && is_string($json_local['lastUpdate'])) {
                    $last_local = $json_local['lastUpdate'];
                }
            }
        }

        $remote_ok = false;
        $remote_body = null;
        $last_remote = null;
        $skip_remote = false;

        $last_fetch_file = $this->data_dir_path . '/last_fetch.json';
        if (file_exists($last_fetch_file)) {
            $lf = json_decode(file_get_contents($last_fetch_file), true);
            if (is_array($lf) && !empty($lf['ok']) && !empty($lf['time'])
                && (time() - (int) $lf['time']) < $this->feed_fetch_throttle
                && is_string($local_body) && $local_body !== '') {
                $skip_remote = true;
            }
        }

        if (!$skip_remote) {
            $url = 'https://hayahora.futbol/estado/data.json';
            $resp = $this->makeHttpRequest($url, 'GET', [], null, 25);

            if ($resp && $resp['success'] && !empty($resp['raw_body'])) {
                $tmp = json_decode($resp['raw_body'], true);
                if (is_array($tmp)) {
                    $remote_ok = true;
                    $remote_body = $resp['raw_body'];
                    if (!empty($tmp['lastUpdate']) && is_string($tmp['lastUpdate'])) {
                        $last_remote = $tmp['lastUpdate'];
                    }
                }
            }

            file_put_contents($last_fetch_file, json_encode([
                'time' => time(),
                'ok' => $remote_ok,
            ]));
        }

        $should_write = false;
        if ($remote_ok) {
            if (!$local_body) {
                $should_write = true;
            } elseif ($last_remote && $last_local) {
                try {
                    $dr = new DateTime($last_remote);
                    $dl = new DateTime($last_local);
                    if ($dr > $dl) {
                        $should_write = true;
                    }
                } catch (Exception $e) {
                    if (md5($remote_body) !== md5($local_body)) {
                        $should_write = true;
                    }
                }
            } elseif (md5($remote_body) !== md5($local_body)) {
                $should_write = true;
            }
        }

        if ($should_write && $remote_body) {
            file_put_contents($local_path, $remote_body, LOCK_EX);
            $local_body = $remote_body;
        }

        $body = $local_body ?: ($remote_ok ? $remote_body : null);
        if (!is_string($body) || $body === '') {
            return null;
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            return null;
        }

        $last_update_str = !empty($json['lastUpdate']) ? $json['lastUpdate'] : '';
        $map = $this->extractIpBlockMap($json);
        $isps_blocked = $this->extractIspsBlocked($json);
        $fresh = true;

        $settings = $this->getSettings();
        $stale_hours = max(1, (int) ($settings['stale_threshold_hours'] ?? 6));

        if (!empty($last_update_str)) {
            try {
                $last_dt = new DateTime($last_update_str);
                $now_dt = new DateTime();
                $diff_hours = ($now_dt->getTimestamp() - $last_dt->getTimestamp()) / 3600;
                if ($diff_hours > $stale_hours) {
                    $fresh = false;
                    $map = [];
                }
            } catch (Exception $e) {
                // keep data as-is
            }
        }

        return [
            'ip_map' => $map,
            'last_update' => $last_update_str,
            'fresh' => $fresh,
            'isps_blocked' => $isps_blocked,
        ];
    }

    private function extractIpBlockMap($json)
    {
        $map = [];

        if (isset($json['ips']) && is_array($json['ips'])) {
            foreach ($json['ips'] as $ip => $status) {
                if (is_bool($status)) {
                    $map[$ip] = $status;
                } elseif (is_array($status) && isset($status['blocked'])) {
                    $map[$ip] = (bool) $status['blocked'];
                }
            }
        }

        return $map;
    }

    private function extractIspsBlocked($json)
    {
        $isps = [];

        if (isset($json['isps']) && is_array($json['isps'])) {
            foreach ($json['isps'] as $isp_name => $isp_data) {
                if (is_array($isp_data) && !empty($isp_data['blocked'])) {
                    $isps[] = $isp_name;
                }
            }
        }

        if (empty($isps) && isset($json['ips']) && is_array($json['ips'])) {
            $isp_names = [];
            foreach ($json['ips'] as $ip => $status) {
                if (is_array($status) && !empty($status['blocked']) && !empty($status['isp'])) {
                    $isp_names[$status['isp']] = true;
                }
            }
            $isps = array_keys($isp_names);
        }

        return $isps;
    }

    private function resolveDomainIps($domain)
    {
        $ips = [];

        $a_records = @dns_get_record($domain, DNS_A);
        if (is_array($a_records)) {
            foreach ($a_records as $record) {
                if (!empty($record['ip'])) {
                    $ips[] = $record['ip'];
                }
            }
        }

        $aaaa_records = @dns_get_record($domain, DNS_AAAA);
        if (is_array($aaaa_records)) {
            foreach ($aaaa_records as $record) {
                if (!empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }

    /* ================== Core Bypass Logic ================== */

    public function checkFootballAndManageCloudflare()
    {
        $settings = $this->getSettings();
        $calc = $this->computeStatusesFromJson();
        $general = $calc['general'];
        $domain = $calc['domain'];
        $blocked_domain_ips = $calc['blocked_domain_ips'] ?? [];

        $stored_blocked = json_decode($settings['bypass_blocked_ips'], true) ?: [];
        if (!is_array($stored_blocked)) {
            $stored_blocked = [];
        }

        $now_ts = time();
        $prev_active = !empty($settings['bypass_active']);
        $last_change = (int) $settings['bypass_last_change'];
        $cooldown_minutes = max(5, min(1440, (int) $settings['bypass_check_cooldown']));
        $cooldown_seconds = $cooldown_minutes * 60;

        $should_disable = ($domain === 'YES');
        $reason = $should_disable ? 'domain_blocked' : 'domain_clear';

        if (!$should_disable && !empty($settings['force_proxy_off_override']) && $general === 'YES') {
            $should_disable = true;
            $reason = 'override_general_football';
        }

        $cooldown_waiting = false;
        $cooldown_remaining = 0;

        if (!$should_disable && $prev_active) {
            $still_waiting_ips = array_intersect($stored_blocked, $blocked_domain_ips);
            if (!empty($still_waiting_ips)) {
                $should_disable = true;
                $reason = 'waiting_previous_ips';
            } elseif ($last_change && ($now_ts - $last_change) < $cooldown_seconds) {
                $should_disable = true;
                $reason = 'cooldown';
                $cooldown_waiting = true;
                $cooldown_remaining = $cooldown_seconds - ($now_ts - $last_change);
            }
        }

        $desired_proxied = !$should_disable;
        $updated = 0;
        $detailed_results = [];

        $selected_records = json_decode($settings['selected_records'], true) ?: [];
        if (!empty($selected_records)) {
            $this->refreshDnsCache();
            foreach ($selected_records as $rid) {
                $res = $this->updateRecordProxyStatus($rid, $desired_proxied);
                if (is_array($res) && !empty($res['success'])) {
                    if (empty($res['skipped'])) {
                        $updated++;
                    }
                    $detailed_results[$rid] = $res;
                }
            }
        }

        $settings['last_check'] = date('Y-m-d H:i:s');
        $settings['last_status_general'] = ($general === 'YES') ? 'YES' : 'NO';
        $settings['last_status_domain'] = ($domain === 'YES') ? 'YES' : 'NO';
        $settings['last_update'] = $calc['last_update'] ?? $settings['last_update'];

        if ($should_disable) {
            if (!$prev_active) {
                $settings['bypass_last_change'] = $now_ts;
            }
            $settings['bypass_active'] = 1;
            $settings['bypass_blocked_ips'] = json_encode(!empty($blocked_domain_ips) ? $blocked_domain_ips : $stored_blocked);
        } else {
            if ($prev_active) {
                $settings['bypass_last_change'] = $now_ts;
            }
            $settings['bypass_active'] = 0;
            $settings['bypass_blocked_ips'] = json_encode([]);
        }

        $this->saveSettings($settings);

        $new_active = !empty($settings['bypass_active']);
        if ($new_active !== $prev_active) {
            $this->maybeSendStateChangeEmail($new_active, $reason);
        }

        $log_context = [
            'general' => $settings['last_status_general'],
            'domain' => $settings['last_status_domain'],
            'selected_records' => count($selected_records),
            'bypass_active' => $settings['bypass_active'],
            'cooldown_minutes' => $cooldown_minutes,
            'reason' => $reason,
            'override_active' => !empty($settings['force_proxy_off_override']) ? 1 : 0,
        ];

        if ($should_disable) {
            $action_labels = [
                'domain_blocked' => 'Proxy OFF (domain blocked)',
                'override_general_football' => 'Proxy OFF (OVERRIDE: General=YES, football detected)',
                'waiting_previous_ips' => 'Proxy OFF (waiting for previous IPs to unblock)',
                'cooldown' => 'Proxy OFF (cooldown period)',
            ];
            $log_context['action'] = $action_labels[$reason] ?? 'Proxy OFF';
        } else {
            $log_context['action'] = 'Proxy ON (domain clear)';
        }

        if ($updated > 0) {
            $log_context['changes'] = $updated . ' proxy changes applied.';
        } else {
            $log_context['changes'] = 'No changes; desired state already applied.';
        }

        if ($cooldown_waiting && $cooldown_remaining > 0) {
            $log_context['cooldown_remaining_sec'] = $cooldown_remaining;
            $log_context['cooldown_remaining_min'] = max(1, ceil($cooldown_remaining / 60));
        }

        $this->logEvent('cron', 'Auto-check executed', $log_context);
    }

    /* ================== Email Notifications ================== */

    private function maybeSendStateChangeEmail($now_active, $reason = '')
    {
        $settings = $this->getSettings();
        if (empty($settings['email_notifications'])) {
            return;
        }

        $custom = isset($settings['notification_email']) ? trim((string) $settings['notification_email']) : '';
        $to = '';
        if ($custom !== '' && Validate::isEmail($custom)) {
            $to = $custom;
        } elseif (isset($this->context->employee) && Validate::isEmail($this->context->employee->email)) {
            $to = $this->context->employee->email;
        } else {
            $to = Configuration::get('PS_SHOP_EMAIL');
        }

        if (empty($to) || !Validate::isEmail($to)) {
            return;
        }

        $shop_name = Configuration::get('PS_SHOP_NAME');
        $shop_url = $this->context->shop->getBaseURL(true);
        $domain = $this->getSiteDomain();

        if ($now_active) {
            $subject = '[' . $shop_name . '] Cloudflare proxy disabled due to football blocks';
            $body = "Hello,\n\n";
            $body .= "Active IP blocks from La Liga have been detected and the Cloudflare proxy has been automatically disabled on the configured DNS records.\n\n";
            $body .= "Site: {$shop_name}\n";
            $body .= "Domain: {$domain}\n";
            $body .= "URL: {$shop_url}\n";
            $body .= "State: Proxy OFF (DNS Only)\n";
            if ($reason !== '') {
                $body .= "Reason: {$reason}\n";
            }
            $body .= "\nThe selected DNS records have switched from Proxied (CDN) to DNS Only so your site remains reachable during the blocks.\n";
            $body .= "The proxy will be re-enabled automatically once the blocks end and the configured cooldown passes.\n\n";
            $body .= "-- \nCF Football Bypass for PrestaShop v{$this->version}\n";
        } else {
            $subject = '[' . $shop_name . '] Cloudflare proxy re-enabled';
            $body = "Hello,\n\n";
            $body .= "No active La Liga IP blocks are detected. The Cloudflare proxy has been re-enabled automatically.\n\n";
            $body .= "Site: {$shop_name}\n";
            $body .= "Domain: {$domain}\n";
            $body .= "URL: {$shop_url}\n";
            $body .= "State: Proxy ON (CDN)\n\n";
            $body .= "The selected DNS records are back on Proxied (CDN). Normal operation resumed.\n\n";
            $body .= "-- \nCF Football Bypass for PrestaShop v{$this->version}\n";
        }

        $sent = false;
        try {
            $sent = @mail($to, $subject, $body, "From: {$shop_name} <" . Configuration::get('PS_SHOP_EMAIL') . ">\r\nContent-Type: text/plain; charset=UTF-8");
        } catch (Exception $e) {
            // mail() failed
        }

        $this->logEvent('email', 'Notification ' . ($sent ? 'sent' : 'FAILED') . ' to ' . $this->maskString($to, 3, 8), [
            'state' => $now_active ? 'bypass_active' : 'bypass_inactive',
            'reason' => $reason,
        ]);
    }

    /* ================== Feed Diagnostics ================== */

    public function getFeedDiagnostics()
    {
        $diag = [];

        $diag['feed_url'] = 'https://hayahora.futbol/estado/data.json';
        $diag['local_cache'] = $this->data_dir_path . '/data.json';
        $diag['local_exists'] = file_exists($this->data_dir_path . '/data.json');
        $diag['local_size'] = $diag['local_exists'] ? filesize($this->data_dir_path . '/data.json') : 0;

        $last_fetch_file = $this->data_dir_path . '/last_fetch.json';
        if (file_exists($last_fetch_file)) {
            $lf = json_decode(file_get_contents($last_fetch_file), true);
            $diag['last_fetch_time'] = !empty($lf['time']) ? date('Y-m-d H:i:s', (int) $lf['time']) : 'unknown';
            $diag['last_fetch_ok'] = !empty($lf['ok']);
        } else {
            $diag['last_fetch_time'] = 'never';
            $diag['last_fetch_ok'] = false;
        }

        $resp = $this->makeHttpRequest($diag['feed_url'], 'GET', [], null, 15);
        $diag['remote_reachable'] = ($resp && $resp['success']);
        $diag['remote_status'] = $resp ? ($resp['success'] ? 'OK' : ($resp['error'] ?? 'error')) : 'unreachable';

        if ($diag['remote_reachable'] && !empty($resp['raw_body'])) {
            $tmp = json_decode($resp['raw_body'], true);
            $diag['remote_valid_json'] = is_array($tmp);
            $diag['remote_last_update'] = $tmp['lastUpdate'] ?? 'unknown';
            $diag['remote_ip_count'] = isset($tmp['ips']) ? count($tmp['ips']) : 0;
        }

        $settings = $this->getSettings();
        $diag['stale_threshold_hours'] = (int) ($settings['stale_threshold_hours'] ?? 6);
        $diag['min_isps_blocked'] = (int) ($settings['min_isps_blocked'] ?? 2);

        $domain = $this->getSiteDomain();
        $diag['domain'] = $domain;
        $diag['is_local'] = $this->isLocalDomain($domain);
        $diag['domain_ips'] = $this->resolveDomainIps($domain);
        $diag['server_ips'] = $this->getServerOutgoingIps();

        return $diag;
    }

    public function clearDataCache()
    {
        $files = [
            $this->data_dir_path . '/data.json',
            $this->data_dir_path . '/last_fetch.json',
        ];
        $cleared = 0;
        foreach ($files as $f) {
            if (file_exists($f)) {
                unlink($f);
                $cleared++;
            }
        }
        return $cleared;
    }

    /* ================== Admin UI ================== */

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitCfbSettings')) {
            $output .= $this->postProcess();
        }

        $this->context->controller->addCSS($this->_path . 'views/css/admin.css');
        $this->context->controller->addJS($this->_path . 'views/js/admin.js');

        $output .= $this->displayForm();

        return $output;
    }

    private function postProcess()
    {
        $settings = $this->getSettings();

        $settings['auth_type'] = Tools::getValue('auth_type', 'global');
        $settings['cloudflare_email'] = Tools::getValue('cloudflare_email', '');
        $settings['cloudflare_api_key'] = Tools::getValue('cloudflare_api_key', $settings['cloudflare_api_key']);
        $settings['cloudflare_zone_id'] = Tools::getValue('cloudflare_zone_id', '');
        $settings['cloudflare_account_id'] = Tools::getValue('cloudflare_account_id', '');
        $settings['check_interval'] = max(5, min(60, (int) Tools::getValue('check_interval', 15)));
        $settings['bypass_check_cooldown'] = max(5, min(1440, (int) Tools::getValue('bypass_check_cooldown', 60)));
        $settings['logging_enabled'] = (int) Tools::getValue('logging_enabled', 1);
        $settings['log_retention_days'] = max(1, (int) Tools::getValue('log_retention_days', 30));
        $settings['force_proxy_off_override'] = (int) Tools::getValue('force_proxy_off_override', 0);
        $settings['stale_threshold_hours'] = max(1, min(72, (int) Tools::getValue('stale_threshold_hours', 6)));
        $settings['min_isps_blocked'] = max(1, min(20, (int) Tools::getValue('min_isps_blocked', 2)));
        $settings['email_notifications'] = (int) Tools::getValue('email_notifications', 0);
        $settings['notification_email'] = Tools::getValue('notification_email', '');
        $settings['ui_mode'] = Tools::getValue('ui_mode', 'simple');
        $settings['delete_data_on_uninstall'] = (int) Tools::getValue('delete_data_on_uninstall', 1);

        if (Tools::getValue('reset_settings')) {
            $settings = $this->getDefaultSettings(true);
            $this->clearLogs();
            $this->clearDataCache();
        }

        $this->saveSettings($settings);

        $trace = [];
        $test_result = $this->quickSettingsTest($settings, $trace);

        if ($test_result) {
            return $this->displayConfirmation($this->l('Settings saved successfully. Cloudflare connection OK.'));
        } else {
            return $this->displayError($this->l('Settings saved but there are connection issues: ') . implode(' | ', $trace));
        }
    }

    private function displayForm()
    {
        $settings = $this->getSettings();
        $is_advanced = ($settings['ui_mode'] === 'advanced');

        $basic_inputs = [
            [
                'type' => 'select',
                'label' => $this->l('UI Mode'),
                'name' => 'ui_mode',
                'options' => [
                    'query' => [
                        ['id' => 'simple', 'name' => $this->l('Simple')],
                        ['id' => 'advanced', 'name' => $this->l('Advanced')],
                    ],
                    'id' => 'id',
                    'name' => 'name',
                ],
                'desc' => $this->l('Simple hides advanced options. Advanced shows all settings.'),
            ],
            [
                'type' => 'select',
                'label' => $this->l('Authentication Type'),
                'name' => 'auth_type',
                'options' => [
                    'query' => [
                        ['id' => 'global', 'name' => $this->l('Global API Key')],
                        ['id' => 'token', 'name' => $this->l('API Token (Bearer)')],
                        ['id' => 'account_token', 'name' => $this->l('Account API Token')],
                    ],
                    'id' => 'id',
                    'name' => 'name',
                ],
                'desc' => $this->l('Global API Key requires email. API Token uses Bearer auth. Account Token uses Bearer + Account ID.'),
            ],
            [
                'type' => 'text',
                'label' => $this->l('Cloudflare Email'),
                'name' => 'cloudflare_email',
                'desc' => $this->l('Required only for Global API Key'),
                'form_group_class' => 'cfb-auth-global',
            ],
            [
                'type' => 'password',
                'label' => $this->l('API Key/Token'),
                'name' => 'cloudflare_api_key',
                'desc' => $this->l('Your Cloudflare Global API Key or Token'),
            ],
            [
                'type' => 'text',
                'label' => $this->l('Zone ID'),
                'name' => 'cloudflare_zone_id',
                'desc' => $this->l('Zone ID in Cloudflare'),
            ],
            [
                'type' => 'text',
                'label' => $this->l('Account ID'),
                'name' => 'cloudflare_account_id',
                'desc' => $this->l('Required only for Account API Token auth type'),
                'form_group_class' => 'cfb-auth-account_token',
            ],
            [
                'type' => 'text',
                'label' => $this->l('Check Interval (minutes)'),
                'name' => 'check_interval',
                'class' => 'fixed-width-sm',
                'desc' => $this->l('Between 5 and 60 minutes'),
            ],
            [
                'type' => 'text',
                'label' => $this->l('Cooldown after deactivation (minutes)'),
                'name' => 'bypass_check_cooldown',
                'class' => 'fixed-width-sm',
                'desc' => $this->l('Wait time after deactivating Cloudflare proxy (5-1440 min)'),
            ],
        ];

        $advanced_inputs = [
            [
                'type' => 'switch',
                'label' => $this->l('Force Proxy OFF Override'),
                'name' => 'force_proxy_off_override',
                'desc' => $this->l('Force proxy OFF when there are general blocks (even if this domain is not directly blocked).'),
                'values' => [
                    ['id' => 'override_on', 'value' => 1, 'label' => $this->l('Yes')],
                    ['id' => 'override_off', 'value' => 0, 'label' => $this->l('No')],
                ],
                'form_group_class' => 'cfb-advanced',
            ],
            [
                'type' => 'text',
                'label' => $this->l('Stale Threshold (hours)'),
                'name' => 'stale_threshold_hours',
                'class' => 'fixed-width-sm',
                'desc' => $this->l('Ignore blocked states older than this many hours (1-72).'),
                'form_group_class' => 'cfb-advanced',
            ],
            [
                'type' => 'text',
                'label' => $this->l('Min ISPs Blocked'),
                'name' => 'min_isps_blocked',
                'class' => 'fixed-width-sm',
                'desc' => $this->l('Minimum number of distinct ISPs with blocked IPs to consider general blocking active (1-20).'),
                'form_group_class' => 'cfb-advanced',
            ],
            [
                'type' => 'switch',
                'label' => $this->l('Email Notifications'),
                'name' => 'email_notifications',
                'desc' => $this->l('Send email when the bypass state changes automatically.'),
                'values' => [
                    ['id' => 'notif_on', 'value' => 1, 'label' => $this->l('Yes')],
                    ['id' => 'notif_off', 'value' => 0, 'label' => $this->l('No')],
                ],
                'form_group_class' => 'cfb-advanced',
            ],
            [
                'type' => 'text',
                'label' => $this->l('Notification Email'),
                'name' => 'notification_email',
                'desc' => $this->l('Custom email recipient for notifications. Leave empty to use shop admin email.'),
                'form_group_class' => 'cfb-advanced',
            ],
            [
                'type' => 'switch',
                'label' => $this->l('Action Logging'),
                'name' => 'logging_enabled',
                'values' => [
                    ['id' => 'active_on', 'value' => 1, 'label' => $this->l('Yes')],
                    ['id' => 'active_off', 'value' => 0, 'label' => $this->l('No')],
                ],
                'form_group_class' => 'cfb-advanced',
            ],
            [
                'type' => 'text',
                'label' => $this->l('Log Retention (days)'),
                'name' => 'log_retention_days',
                'class' => 'fixed-width-sm',
                'form_group_class' => 'cfb-advanced',
            ],
            [
                'type' => 'switch',
                'label' => $this->l('Delete Data on Uninstall'),
                'name' => 'delete_data_on_uninstall',
                'desc' => $this->l('Remove all module configuration, logs and cached data when uninstalling.'),
                'values' => [
                    ['id' => 'delete_on', 'value' => 1, 'label' => $this->l('Yes')],
                    ['id' => 'delete_off', 'value' => 0, 'label' => $this->l('No')],
                ],
                'form_group_class' => 'cfb-advanced',
            ],
            [
                'type' => 'switch',
                'label' => $this->l('Reset Settings'),
                'name' => 'reset_settings',
                'values' => [
                    ['id' => 'reset_on', 'value' => 1, 'label' => $this->l('Yes')],
                    ['id' => 'reset_off', 'value' => 0, 'label' => $this->l('No')],
                ],
                'desc' => $this->l('WARNING: Resets all module configuration to defaults.'),
                'form_group_class' => 'cfb-advanced',
            ],
        ];

        $inputs = array_merge($basic_inputs, $advanced_inputs);

        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => $this->l('CF Football Bypass Settings'),
                    'icon' => 'icon-cogs',
                ],
                'input' => $inputs,
                'submit' => [
                    'title' => $this->l('Save'),
                    'name' => 'submitCfbSettings',
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitCfbSettings';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = [
            'fields_value' => [
                'ui_mode' => $settings['ui_mode'],
                'auth_type' => $settings['auth_type'],
                'cloudflare_email' => $settings['cloudflare_email'],
                'cloudflare_api_key' => $settings['cloudflare_api_key'],
                'cloudflare_zone_id' => $settings['cloudflare_zone_id'],
                'cloudflare_account_id' => $settings['cloudflare_account_id'],
                'check_interval' => $settings['check_interval'],
                'bypass_check_cooldown' => $settings['bypass_check_cooldown'],
                'force_proxy_off_override' => $settings['force_proxy_off_override'],
                'stale_threshold_hours' => $settings['stale_threshold_hours'],
                'min_isps_blocked' => $settings['min_isps_blocked'],
                'email_notifications' => $settings['email_notifications'],
                'notification_email' => $settings['notification_email'],
                'logging_enabled' => $settings['logging_enabled'],
                'log_retention_days' => $settings['log_retention_days'],
                'delete_data_on_uninstall' => $settings['delete_data_on_uninstall'],
                'reset_settings' => 0,
            ],
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        ];

        $form = $helper->generateForm([$fields_form]);
        $form .= $this->renderOperationPanel();

        return $form;
    }

    private function renderOperationPanel()
    {
        $settings = $this->getSettings();
        $domain = $this->getSiteDomain();
        $calc = $this->computeStatusesFromJson();

        $cache = json_decode($settings['dns_records_cache'], true) ?: [];
        $selected = json_decode($settings['selected_records'], true) ?: [];

        $cron_token = $settings['cron_secret'];
        if (empty($cron_token)) {
            $cron_token = $this->generateCronSecret();
            $settings['cron_secret'] = $cron_token;
            $this->saveSettings($settings);
        }

        if (empty($settings['log_secret'])) {
            $settings['log_secret'] = $this->generateLogSecret();
            $this->saveSettings($settings);
        }

        $cron_url = $this->context->shop->getBaseURL(true) . 'modules/' . $this->name . '/cron.php?token=' . $cron_token;
        $ajax_url = $this->context->link->getModuleLink($this->name, 'ajax');
        $admin_token = Tools::getAdminTokenLite('AdminModules');

        $server_ips = $this->getServerOutgoingIps();

        $this->context->smarty->assign([
            'domain' => $domain,
            'settings' => $settings,
            'status' => [
                'general' => $calc['general'],
                'domain' => $calc['domain'],
                'domain_ips' => $calc['domain_ips'] ?? [],
                'blocked_domain_ips' => $calc['blocked_domain_ips'] ?? [],
                'last_update' => $calc['last_update'] ?? '',
                'isps_blocked' => $calc['isps_blocked'] ?? [],
                'fresh' => $calc['fresh'] ?? true,
            ],
            'dns_records' => [
                'cache' => $cache,
                'selected' => $selected,
            ],
            'cron_url' => $cron_url,
            'admin_token' => $admin_token,
            'ajax_url' => $ajax_url,
            'ui_mode' => $settings['ui_mode'],
            'module_version' => $this->version,
            'bypass_active' => !empty($settings['bypass_active']),
            'last_check' => $settings['last_check'] ?? '',
            'server_ips' => $server_ips,
        ]);

        return $this->display(__FILE__, 'views/templates/admin/operation.tpl');
    }
}
