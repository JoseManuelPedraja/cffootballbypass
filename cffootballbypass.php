<?php
/**
 * CF Football Bypass for PrestaShop
 *
 * Operates with Cloudflare to toggle Proxy (ON/CDN) and DNS Only (OFF)
 * based on La Liga IP blocks detected via hayahora.futbol.
 *
 * Based on ES Football Bypass for Cloudflare (WordPress) by David Carrero / Color Vivo.
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
    const FEED_URL = 'https://hayahora.futbol/estado/data.json';
    const FRESH_WINDOW = 900;
    const GITHUB_REPO = 'SurMod/cffootballbypass';
    const UPDATE_CHECK_INTERVAL = 86400;

    public function __construct()
    {
        $this->name = 'cffootballbypass';
        $this->tab = 'administration';
        $this->version = '2.1.0';
        $this->author = 'Jose Manuel Pedraja';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = [
            'min' => '8.1.0',
            'max' => _PS_VERSION_,
        ];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('CF Football Bypass');
        $this->description = $this->l('Operates with Cloudflare to toggle Proxy (ON/CDN) and DNS Only (OFF) based on La Liga IP blocks.');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall? All settings and logs will be removed.');
    }

    public function install()
    {
        $defaults = $this->getDefaultSettings();
        foreach ($defaults as $key => $value) {
            Configuration::updateValue(
                'CFB_' . strtoupper($key),
                is_array($value) ? json_encode($value) : $value
            );
        }

        $secret = bin2hex(random_bytes(16));
        Configuration::updateValue('CFB_LOG_SECRET', $secret);
        Configuration::updateValue('CFB_CRON_SECRET', bin2hex(random_bytes(16)));

        $this->ensureDirectories();

        return parent::install()
            && $this->registerHook('displayBackOfficeHeader');
    }

    public function uninstall()
    {
        $deleteData = (bool) Configuration::get('CFB_DELETE_DATA_ON_UNINSTALL');

        if ($deleteData) {
            $keys = array_keys($this->getDefaultSettings());
            $extra = ['LOG_SECRET', 'CRON_SECRET', 'VERSION', 'LAST_EMAIL_SENT'];
            foreach (array_merge($keys, $extra) as $key) {
                Configuration::deleteByName('CFB_' . strtoupper($key));
            }

            $logPath = $this->getLogFilePath();
            if (file_exists($logPath)) {
                @unlink($logPath);
            }
            $cachePath = $this->getCacheDir() . '/data.json';
            if (file_exists($cachePath)) {
                @unlink($cachePath);
            }
        }

        return parent::uninstall();
    }

    public function hookDisplayBackOfficeHeader()
    {
        if (Tools::getValue('configure') !== $this->name) {
            return;
        }
        $this->context->controller->addCSS($this->_path . 'views/css/admin.css');
        $this->context->controller->addJS($this->_path . 'views/js/admin.js');
    }

    /* ==================== Content / Tabs ==================== */

    public function getContent()
    {
        $this->normalizeSettings();

        if (Tools::isSubmit('submitCfbSettings')) {
            $this->processSettings();
        }

        $tab = Tools::getValue('cfb_tab', 'operation');
        $validTabs = ['operation', 'settings', 'now', 'logs'];
        if (!in_array($tab, $validTabs, true)) {
            $tab = 'operation';
        }

        $s = $this->getSettings();
        $isAdvanced = ($s['ui_mode'] === 'advanced');
        $baseUrl = $this->context->link->getAdminLink('AdminModules', true) . '&configure=' . $this->name . '&module_name=' . $this->name;

        $tabs = [
            ['id' => 'operation', 'label' => $this->l('Operation'), 'icon' => 'icon-shield'],
            ['id' => 'settings', 'label' => $this->l('Settings'), 'icon' => 'icon-cogs'],
            ['id' => 'now', 'label' => $this->l('Is there football?'), 'icon' => 'icon-futbol-o'],
        ];
        if ($isAdvanced) {
            $tabs[] = ['id' => 'logs', 'label' => $this->l('Logs'), 'icon' => 'icon-list-alt'];
        }

        $html = '';
        $update = $this->checkForUpdate();
        if ($update) {
            $html .= '<div class="alert alert-warning" style="display:flex;align-items:center;gap:12px;">'
                . '<i class="icon-download" style="font-size:24px;"></i>'
                . '<div><strong>' . sprintf($this->l('Update available: v%s'), htmlspecialchars($update['version'])) . '</strong><br>'
                . '<a href="' . htmlspecialchars($update['zip_url']) . '" target="_blank" rel="noopener">' . $this->l('Download ZIP') . '</a>'
                . ' &middot; <a href="' . htmlspecialchars($update['html_url']) . '" target="_blank" rel="noopener">' . $this->l('Release notes') . '</a>'
                . '</div></div>';
        }

        $html .= '<div class="cfb-module-wrap">';
        $html .= '<ul class="nav nav-tabs" role="tablist">';
        foreach ($tabs as $t) {
            $active = ($t['id'] === $tab) ? ' class="active"' : '';
            $html .= '<li' . $active . '><a href="' . htmlspecialchars($baseUrl . '&cfb_tab=' . $t['id']) . '"><i class="' . $t['icon'] . '"></i> ' . $t['label'] . '</a></li>';
        }
        $html .= '</ul>';
        $html .= '<div class="tab-content" style="padding-top:15px;">';

        switch ($tab) {
            case 'settings':
                $html .= $this->renderSettingsTab();
                break;
            case 'now':
                $html .= $this->renderNowTab();
                break;
            case 'logs':
                $html .= $isAdvanced ? $this->renderLogsTab() : $this->renderOperationTab();
                break;
            default:
                $html .= $this->renderOperationTab();
                break;
        }

        $html .= '</div></div>';
        $html .= '<p style="text-align:right;color:#999;font-size:12px;margin-top:20px;">Version ' . htmlspecialchars($this->version) . '</p>';

        return $html;
    }

    /* ==================== Settings Tab ==================== */

    private function renderSettingsTab()
    {
        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->identifier = $this->identifier;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->submit_action = 'submitCfbSettings';
        $helper->title = $this->displayName;

        $s = $this->getSettings();
        $isAdvanced = ($s['ui_mode'] === 'advanced');

        $fields = [];

        $fields[] = [
            'form' => [
                'legend' => ['title' => $this->l('Interface Mode'), 'icon' => 'icon-eye'],
                'input' => [
                    [
                        'type' => 'select',
                        'label' => $this->l('Interface mode'),
                        'name' => 'CFB_UI_MODE',
                        'options' => [
                            'query' => [
                                ['id' => 'simple', 'name' => $this->l('Simple (recommended for clients)')],
                                ['id' => 'advanced', 'name' => $this->l('Advanced (diagnostics, logs, technical details)')],
                            ],
                            'id' => 'id',
                            'name' => 'name',
                        ],
                        'desc' => $this->l('Simple mode hides feed diagnostics, logs tab, and advanced options. Logs are still written to disk.'),
                    ],
                ],
                'submit' => ['title' => $this->l('Save')],
            ],
        ];

        $fields[] = [
            'form' => [
                'legend' => ['title' => $this->l('Cloudflare Credentials'), 'icon' => 'icon-cloud'],
                'input' => [
                    [
                        'type' => 'select',
                        'label' => $this->l('Authentication type'),
                        'name' => 'CFB_AUTH_TYPE',
                        'options' => [
                            'query' => [
                                ['id' => 'global', 'name' => 'Global API Key'],
                                ['id' => 'token', 'name' => $this->l('API Token (Bearer)')],
                                ['id' => 'account_token', 'name' => $this->l('Account API Token (Bearer)')],
                            ],
                            'id' => 'id',
                            'name' => 'name',
                        ],
                        'desc' => $this->l('Global API Key requires email. Account tokens require Account ID. Minimum permissions: Zone:Read, DNS:Read, DNS:Edit.'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Cloudflare Email'),
                        'name' => 'CFB_CLOUDFLARE_EMAIL',
                        'desc' => $this->l('Required only for Global API Key authentication.'),
                    ],
                    [
                        'type' => 'password',
                        'label' => $this->l('API Key / Token'),
                        'name' => 'CFB_CLOUDFLARE_API_KEY',
                        'desc' => $this->l('Global API Key or Bearer Token. Never shown in logs.'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Zone ID'),
                        'name' => 'CFB_CLOUDFLARE_ZONE_ID',
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Account ID'),
                        'name' => 'CFB_CLOUDFLARE_ACCOUNT_ID',
                        'desc' => $this->l('Required only for Account API Token. Found in the Cloudflare dashboard URL.'),
                    ],
                ],
                'submit' => ['title' => $this->l('Save')],
            ],
        ];

        $bypassInputs = [
            [
                'type' => 'switch',
                'label' => $this->l('Force Proxy OFF during football'),
                'name' => 'CFB_FORCE_PROXY_OFF_OVERRIDE',
                'is_bool' => true,
                'values' => [
                    ['id' => 'on', 'value' => 1, 'label' => $this->l('Yes')],
                    ['id' => 'off', 'value' => 0, 'label' => $this->l('No')],
                ],
                'desc' => $this->l('Disable proxy when General=YES, even if this domain is not detected as blocked. Useful to avoid false negatives.'),
            ],
            [
                'type' => 'text',
                'label' => $this->l('Check interval (minutes)'),
                'name' => 'CFB_CHECK_INTERVAL',
                'class' => 'fixed-width-sm',
                'desc' => $this->l('How often the cron checks for blocks (5-60 minutes).'),
            ],
            [
                'type' => 'text',
                'label' => $this->l('Cooldown after disabling (minutes)'),
                'name' => 'CFB_BYPASS_CHECK_COOLDOWN',
                'class' => 'fixed-width-sm',
                'desc' => $this->l('Minutes to wait before re-enabling proxy after disabling it (5-1440).'),
            ],
            [
                'type' => 'text',
                'label' => $this->l('Minimum ISPs with blocks'),
                'name' => 'CFB_MIN_ISPS_BLOCKED',
                'class' => 'fixed-width-sm',
                'desc' => $this->l('Minimum distinct ISPs with blocked IPs to trigger bypass (1-20, default 2). Lower values are more sensitive.'),
            ],
        ];
        $fields[] = [
            'form' => [
                'legend' => ['title' => $this->l('Bypass Behavior'), 'icon' => 'icon-exchange'],
                'input' => $bypassInputs,
                'submit' => ['title' => $this->l('Save')],
            ],
        ];

        $fields[] = [
            'form' => [
                'legend' => ['title' => $this->l('Email Notifications'), 'icon' => 'icon-envelope'],
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->l('Enable email notifications'),
                        'name' => 'CFB_EMAIL_NOTIFICATIONS',
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'on', 'value' => 1, 'label' => $this->l('Yes')],
                            ['id' => 'off', 'value' => 0, 'label' => $this->l('No')],
                        ],
                        'desc' => $this->l('Send email when bypass turns on/off automatically. 2-minute throttle prevents bursts.'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Notification email'),
                        'name' => 'CFB_NOTIFICATION_EMAIL',
                        'desc' => $this->l('Leave empty to use shop admin email. Set a different address for agency/support notifications.'),
                    ],
                ],
                'submit' => ['title' => $this->l('Save')],
            ],
        ];

        $fields[] = [
            'form' => [
                'legend' => ['title' => $this->l('Uninstall'), 'icon' => 'icon-trash'],
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->l('Delete data on uninstall'),
                        'name' => 'CFB_DELETE_DATA_ON_UNINSTALL',
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'on', 'value' => 1, 'label' => $this->l('Yes')],
                            ['id' => 'off', 'value' => 0, 'label' => $this->l('No')],
                        ],
                        'desc' => $this->l('Remove all settings, credentials, DNS cache and logs when the module is uninstalled. Uncheck to keep configuration for reinstall.'),
                    ],
                ],
                'submit' => ['title' => $this->l('Save')],
            ],
        ];

        if ($isAdvanced) {
            $cronSecret = Configuration::get('CFB_CRON_SECRET') ?: '';
            $cronUrl = '';
            if ($cronSecret) {
                $cronUrl = Tools::getShopDomainSsl(true) . __PS_BASE_URI__ . 'modules/' . $this->name . '/cron.php?token=' . $cronSecret;
            }

            $advancedInputs = [
                [
                    'type' => 'text',
                    'label' => $this->l('Staleness threshold (hours)'),
                    'name' => 'CFB_STALE_THRESHOLD_HOURS',
                    'class' => 'fixed-width-sm',
                    'desc' => $this->l('Ignore blocked states older than this (1-72h, default 6). Prevents orphan records from keeping bypass active.'),
                ],
                [
                    'type' => 'switch',
                    'label' => $this->l('Action logging'),
                    'name' => 'CFB_LOGGING_ENABLED',
                    'is_bool' => true,
                    'values' => [
                        ['id' => 'on', 'value' => 1, 'label' => $this->l('Yes')],
                        ['id' => 'off', 'value' => 0, 'label' => $this->l('No')],
                    ],
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Log retention (days)'),
                    'name' => 'CFB_LOG_RETENTION_DAYS',
                    'class' => 'fixed-width-sm',
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('External cron token'),
                    'name' => 'CFB_CRON_SECRET',
                    'desc' => $cronUrl
                        ? $this->l('Cron URL:') . ' <code>' . htmlspecialchars($cronUrl) . '</code>'
                        : $this->l('Set a token to enable the external cron endpoint.'),
                ],
            ];
            $fields[] = [
                'form' => [
                    'legend' => ['title' => $this->l('Advanced Options'), 'icon' => 'icon-wrench'],
                    'description' => $this->l('Technical options. Defaults work fine for most sites.'),
                    'input' => $advancedInputs,
                    'submit' => ['title' => $this->l('Save')],
                ],
            ];
        }

        $helper->fields_value = [
            'CFB_UI_MODE' => $s['ui_mode'],
            'CFB_AUTH_TYPE' => $s['auth_type'],
            'CFB_CLOUDFLARE_EMAIL' => $s['cloudflare_email'],
            'CFB_CLOUDFLARE_API_KEY' => $s['cloudflare_api_key'],
            'CFB_CLOUDFLARE_ZONE_ID' => $s['cloudflare_zone_id'],
            'CFB_CLOUDFLARE_ACCOUNT_ID' => $s['cloudflare_account_id'],
            'CFB_FORCE_PROXY_OFF_OVERRIDE' => $s['force_proxy_off_override'],
            'CFB_CHECK_INTERVAL' => $s['check_interval'],
            'CFB_BYPASS_CHECK_COOLDOWN' => $s['bypass_check_cooldown'],
            'CFB_MIN_ISPS_BLOCKED' => $s['min_isps_blocked'],
            'CFB_EMAIL_NOTIFICATIONS' => $s['email_notifications'],
            'CFB_NOTIFICATION_EMAIL' => $s['notification_email'],
            'CFB_DELETE_DATA_ON_UNINSTALL' => $s['delete_data_on_uninstall'],
            'CFB_STALE_THRESHOLD_HOURS' => $s['stale_threshold_hours'],
            'CFB_LOGGING_ENABLED' => $s['logging_enabled'],
            'CFB_LOG_RETENTION_DAYS' => $s['log_retention_days'],
            'CFB_CRON_SECRET' => Configuration::get('CFB_CRON_SECRET') ?: '',
        ];

        $output = '';

        $ips = $this->getServerOutgoingIps();
        if (!empty($ips)) {
            $output .= '<div class="alert alert-info"><strong>' . $this->l('Server outgoing IP:') . '</strong> ';
            $output .= '<code>' . implode('</code>, <code>', array_map('htmlspecialchars', $ips)) . '</code>';
            $output .= '<br><small>' . $this->l('You can restrict your API Token to this IP for added security.') . '</small></div>';
        }

        $output .= $helper->generateForm($fields);
        return $output;
    }

    private function processSettings()
    {
        $s = $this->getSettings();

        $authType = Tools::getValue('CFB_AUTH_TYPE', 'global');
        if (!in_array($authType, ['global', 'token', 'account_token'], true)) {
            $authType = 'global';
        }
        $s['auth_type'] = $authType;
        $s['cloudflare_email'] = trim(Tools::getValue('CFB_CLOUDFLARE_EMAIL', ''));
        $s['cloudflare_api_key'] = trim(Tools::getValue('CFB_CLOUDFLARE_API_KEY', ''));
        $s['cloudflare_zone_id'] = trim(Tools::getValue('CFB_CLOUDFLARE_ZONE_ID', ''));
        $s['cloudflare_account_id'] = trim(Tools::getValue('CFB_CLOUDFLARE_ACCOUNT_ID', ''));
        $s['force_proxy_off_override'] = (int) Tools::getValue('CFB_FORCE_PROXY_OFF_OVERRIDE', 0);
        $s['check_interval'] = max(5, min(60, (int) Tools::getValue('CFB_CHECK_INTERVAL', 15)));
        $s['bypass_check_cooldown'] = max(5, min(1440, (int) Tools::getValue('CFB_BYPASS_CHECK_COOLDOWN', 60)));
        $s['min_isps_blocked'] = max(1, min(20, (int) Tools::getValue('CFB_MIN_ISPS_BLOCKED', 2)));
        $s['email_notifications'] = (int) Tools::getValue('CFB_EMAIL_NOTIFICATIONS', 0);
        $s['notification_email'] = trim(Tools::getValue('CFB_NOTIFICATION_EMAIL', ''));
        $s['delete_data_on_uninstall'] = (int) Tools::getValue('CFB_DELETE_DATA_ON_UNINSTALL', 1);

        $uiMode = Tools::getValue('CFB_UI_MODE', 'simple');
        $s['ui_mode'] = in_array($uiMode, ['simple', 'advanced'], true) ? $uiMode : 'simple';

        if ($s['ui_mode'] === 'advanced') {
            $s['stale_threshold_hours'] = max(1, min(72, (int) Tools::getValue('CFB_STALE_THRESHOLD_HOURS', 6)));
            $s['logging_enabled'] = (int) Tools::getValue('CFB_LOGGING_ENABLED', 1);
            $s['log_retention_days'] = max(1, min(365, (int) Tools::getValue('CFB_LOG_RETENTION_DAYS', 30)));
            $cronSecret = trim(Tools::getValue('CFB_CRON_SECRET', ''));
            Configuration::updateValue('CFB_CRON_SECRET', $cronSecret);
        }

        $this->saveSettings($s);

        $trace = [];
        $ok = $this->quickSettingsTest($s, $trace);

        if ($ok) {
            $this->context->controller->confirmations[] = $this->l('Settings saved. Connection verified.') . '<br><pre>' . htmlspecialchars(implode("\n", $trace)) . '</pre>';
        } else {
            $this->context->controller->errors[] = $this->l('Settings saved but connection failed:') . '<br><pre>' . htmlspecialchars(implode("\n", $trace)) . '</pre>';
        }
    }

    /* ==================== Operation Tab ==================== */

    private function renderOperationTab()
    {
        $s = $this->getSettings();
        $domain = $this->getSiteDomain();
        $isLocal = $this->isLocalDomain($domain);
        $isAdvanced = ($s['ui_mode'] === 'advanced');

        if ($isLocal) {
            return '<div class="alert alert-warning"><strong>' . $this->l('Local environment detected') . '</strong><br>'
                . sprintf($this->l('Domain %s is local. Install on a production site with a real domain.'), '<code>' . htmlspecialchars($domain) . '</code>')
                . '</div>';
        }

        $calc = $this->computeStatusesFromJson();
        $cache = $this->getJsonArray($s['dns_records_cache']);
        $selected = $this->getJsonArray($s['selected_records']);
        $ajaxToken = $this->generateAjaxToken();
        $ajaxUrl = $this->context->link->getModuleLink($this->name, 'ajax', [], true);

        $this->context->smarty->assign([
            'cfb_domain' => $domain,
            'cfb_settings' => $s,
            'cfb_status' => $calc,
            'cfb_dns_cache' => $cache,
            'cfb_selected' => $selected,
            'cfb_ajax_token' => $ajaxToken,
            'cfb_ajax_url' => $ajaxUrl,
            'cfb_is_advanced' => $isAdvanced,
            'cfb_check_url' => 'https://hayahora.futbol/#comprobador&domain=' . rawurlencode($domain),
            'cfb_zone_masked' => $this->mask($s['cloudflare_zone_id']),
            'cfb_auth_label' => $this->getAuthLabel($s['auth_type']),
            'cfb_version' => $this->version,
        ]);

        if ($isAdvanced) {
            $this->context->smarty->assign([
                'cfb_feed_diag' => $this->getFeedDiagnostics(),
            ]);
        }

        return $this->display(__FILE__, 'views/templates/admin/operation.tpl');
    }

    /* ==================== "Is there football?" Tab ==================== */

    private function renderNowTab()
    {
        $calc = $this->computeStatusesFromJson();
        $isBlocked = ($calc['general'] === 'SÍ');
        $lastUpd = !empty($calc['last_update']) ? $calc['last_update'] : '—';
        $isps = isset($calc['isps_blocked']) && is_array($calc['isps_blocked']) ? $calc['isps_blocked'] : [];

        $label = $isBlocked ? $this->l('YES') : $this->l('NO');
        $color = $isBlocked ? '#b32d2e' : '#007017';
        $subtitle = $isBlocked
            ? $this->l('Right now there are active IP blocks linked to La Liga matches. Your site may be affected.')
            : $this->l('No active IP blocks detected. Everything normal.');

        $html = '<div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:40px 30px;margin:20px 0;text-align:center;max-width:720px;">';
        $html .= '<div style="font-size:96px;font-weight:700;line-height:1;color:' . $color . ';">' . $label . '</div>';
        $html .= '<p style="font-size:16px;margin:18px 0 6px 0;">' . htmlspecialchars($subtitle) . '</p>';
        $html .= '<p style="color:#666;font-size:13px;margin:0;">' . sprintf($this->l('Last update: %s'), htmlspecialchars($lastUpd)) . '</p>';
        if ($isBlocked && !empty($isps)) {
            $html .= '<p style="color:#666;font-size:13px;margin:8px 0 0 0;">' . $this->l('Affected ISPs:') . ' <strong>' . htmlspecialchars(implode(', ', $isps)) . '</strong></p>';
        }
        $html .= '</div>';

        $html .= '<div class="panel" style="padding:14px;max-width:720px;">';
        $html .= '<h3 style="margin:0 0 8px 0;">' . $this->l('Where this data comes from') . '</h3>';
        $html .= '<p style="margin:0 0 6px 0;">' . $this->l('The answer above comes from the public feed published by hayahora.futbol, which monitors IP blocks during La Liga football matches in Spain from several ISPs (DIGI, Movistar, Orange, Masmovil...).') . '</p>';
        $html .= '<p style="margin:0;color:#666;font-size:13px;">Feed URL: <code>https://hayahora.futbol/estado/data.json</code></p>';
        $html .= '</div>';

        return $html;
    }

    /* ==================== Logs Tab ==================== */

    private function renderLogsTab()
    {
        $s = $this->getSettings();
        $path = $this->getLogFilePath();
        $enabled = !empty($s['logging_enabled']);
        $entries = $enabled ? $this->readLogEntries(250) : [];

        $html = '<div class="panel"><div class="panel-heading"><h3 class="panel-title"><i class="icon-list-alt"></i> ' . $this->l('Action Logs') . '</h3></div><div class="panel-body">';
        $html .= '<p>' . $this->l('File:') . ' <code>' . htmlspecialchars(basename($path)) . '</code> — ';
        $html .= $this->l('Status:') . ' <strong>' . ($enabled ? $this->l('Active') : $this->l('Disabled')) . '</strong>';
        $html .= ' — ' . sprintf($this->l('Retention: %d days'), (int) ($s['log_retention_days'] ?? 30)) . '</p>';

        if (!$enabled) {
            $html .= '<div class="alert alert-warning">' . $this->l('Logging is disabled. Enable it in Settings (Advanced mode).') . '</div>';
        }

        if ($enabled && empty($entries)) {
            $html .= '<p>' . $this->l('No events recorded yet.') . '</p>';
        }

        if ($enabled && !empty($entries)) {
            $html .= '<table class="table table-striped"><thead><tr>';
            $html .= '<th>' . $this->l('Date') . '</th><th>' . $this->l('Type') . '</th><th>' . $this->l('Message') . '</th><th>' . $this->l('Context') . '</th>';
            $html .= '</tr></thead><tbody>';
            foreach ($entries as $entry) {
                $html .= '<tr>';
                $html .= '<td>' . htmlspecialchars($entry['time'] ?? '') . '</td>';
                $html .= '<td>' . htmlspecialchars($entry['type'] ?? 'info') . '</td>';
                $html .= '<td>' . htmlspecialchars($entry['message'] ?? '') . '</td>';
                $html .= '<td>';
                if (!empty($entry['context']) && is_array($entry['context'])) {
                    $html .= '<small>' . htmlspecialchars(json_encode($entry['context'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</small>';
                }
                $html .= '</td></tr>';
            }
            $html .= '</tbody></table>';
            $html .= '<p style="font-size:12px;color:#666">' . $this->l('Showing the 250 most recent events.') . '</p>';
        }

        $html .= '</div></div>';
        return $html;
    }

    /* ==================== Cloudflare API ==================== */

    private function getApiHeaders($settings)
    {
        $h = ['Content-Type: application/json'];
        if ($settings['auth_type'] === 'global') {
            $h[] = 'X-Auth-Email: ' . $settings['cloudflare_email'];
            $h[] = 'X-Auth-Key: ' . $settings['cloudflare_api_key'];
        } else {
            $h[] = 'Authorization: Bearer ' . $settings['cloudflare_api_key'];
        }
        return $h;
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
            $trace[] = $this->l('Missing Account ID for account token.');
            return false;
        }

        $headers = $this->getApiHeaders($settings);

        if ($settings['auth_type'] === 'account_token') {
            $url = 'https://api.cloudflare.com/client/v4/accounts/' . rawurlencode($settings['cloudflare_account_id']) . '/tokens/verify';
        } elseif ($settings['auth_type'] === 'token') {
            $url = 'https://api.cloudflare.com/client/v4/user/tokens/verify';
        } else {
            $url = 'https://api.cloudflare.com/client/v4/user';
        }

        $trace[] = 'GET ' . $url;
        $resp = $this->makeHttpRequest($url, 'GET', $headers);
        if (!$resp || !isset($resp['success'])) {
            $trace[] = 'Error: ' . ($resp['_error'] ?? 'Request failed');
            return false;
        }
        $trace[] = 'Auth: ' . ($resp['success'] ? 'OK' : 'FAILED');
        if (!$resp['success']) {
            $trace[] = 'Error: ' . ($resp['errors'][0]['message'] ?? 'Unknown');
            return false;
        }

        $zoneUrl = 'https://api.cloudflare.com/client/v4/zones/' . rawurlencode($settings['cloudflare_zone_id']);
        $trace[] = 'GET ' . $zoneUrl;
        $zoneResp = $this->makeHttpRequest($zoneUrl, 'GET', $headers);
        if (!$zoneResp || empty($zoneResp['success'])) {
            $trace[] = 'Error accessing zone.';
            return false;
        }
        $zoneName = $zoneResp['result']['name'] ?? '?';
        $trace[] = 'Zone: ' . $zoneName;

        $siteDomain = $this->getSiteDomain();
        if (!$this->domainMatchesZone($siteDomain, $zoneName)) {
            $trace[] = 'Zone mismatch: domain=' . $siteDomain . ' zone=' . $zoneName;
            return false;
        }

        $dnsUrl = $zoneUrl . '/dns_records?per_page=50&page=1';
        $trace[] = 'GET ' . $dnsUrl;
        $dnsResp = $this->makeHttpRequest($dnsUrl, 'GET', $headers);
        if (!$dnsResp || empty($dnsResp['success'])) {
            $trace[] = 'Error reading DNS.';
            return false;
        }
        $count = isset($dnsResp['result']) ? count($dnsResp['result']) : 0;
        $trace[] = 'DNS records found: ' . $count;
        $trace[] = $this->l('Connection OK.');
        return true;
    }

    public function fetchDnsRecords($allowedTypes = ['A', 'AAAA', 'CNAME'], &$trace = null)
    {
        $s = $this->getSettings();
        if (empty($s['cloudflare_api_key']) || empty($s['cloudflare_zone_id'])) {
            return [];
        }
        $headers = $this->getApiHeaders($s);
        $page = 1;
        $perPage = 100;
        $accum = [];
        $maxPages = 50;
        $totalPages = 1;

        do {
            $url = 'https://api.cloudflare.com/client/v4/zones/' . rawurlencode($s['cloudflare_zone_id'])
                . '/dns_records?per_page=' . $perPage . '&page=' . $page;
            $resp = $this->makeHttpRequest($url, 'GET', $headers);
            if (!$resp || empty($resp['success'])) {
                break;
            }
            $ri = $resp['result_info'] ?? null;
            if ($ri && isset($ri['total_count'], $ri['per_page']) && (int) $ri['per_page'] > 0) {
                $totalPages = (int) ceil($ri['total_count'] / $ri['per_page']);
            }
            foreach (($resp['result'] ?? []) as $rr) {
                $t = strtoupper($rr['type'] ?? '');
                if (!in_array($t, $allowedTypes, true)) {
                    continue;
                }
                $accum[] = [
                    'id' => (string) ($rr['id'] ?? ''),
                    'name' => (string) ($rr['name'] ?? ''),
                    'type' => $t,
                    'content' => (string) ($rr['content'] ?? ''),
                    'proxied' => array_key_exists('proxied', $rr) ? (bool) $rr['proxied'] : null,
                    'ttl' => (int) ($rr['ttl'] ?? 1),
                ];
            }
            ++$page;
        } while ($page <= $totalPages && $page <= $maxPages);

        return $accum;
    }

    public function updateRecordProxyStatus($recordId, $proxiedOn)
    {
        $s = $this->getSettings();
        if (empty($s['cloudflare_api_key']) || empty($s['cloudflare_zone_id'])) {
            return false;
        }
        $cache = $this->getJsonArray($s['dns_records_cache']);
        $existing = null;
        foreach ($cache as $r) {
            if (!empty($r['id']) && $r['id'] === $recordId) {
                $existing = $r;
                break;
            }
        }
        if (!$existing) {
            $fresh = $this->fetchDnsRecords();
            if (!empty($fresh)) {
                $this->persistDnsCache($fresh);
            }
            foreach ($fresh as $r) {
                if (!empty($r['id']) && $r['id'] === $recordId) {
                    $existing = $r;
                    break;
                }
            }
            if (!$existing) {
                return false;
            }
        }

        $type = strtoupper($existing['type'] ?? '');
        if (!in_array($type, ['A', 'AAAA', 'CNAME'], true)) {
            return false;
        }
        if (array_key_exists('proxied', $existing) && (bool) $existing['proxied'] === (bool) $proxiedOn) {
            return true;
        }

        $headers = $this->getApiHeaders($s);
        $url = 'https://api.cloudflare.com/client/v4/zones/' . rawurlencode($s['cloudflare_zone_id'])
            . '/dns_records/' . rawurlencode($recordId);
        $ttl = (int) ($existing['ttl'] ?? 1);
        if ($proxiedOn) {
            $ttl = 1;
        }
        $payload = json_encode([
            'type' => $type,
            'name' => $existing['name'] ?? '',
            'content' => $existing['content'] ?? '',
            'ttl' => $ttl,
            'proxied' => (bool) $proxiedOn,
        ]);

        $resp = $this->makeHttpRequest($url, 'PUT', $headers, $payload);
        if (!$resp || empty($resp['success'])) {
            return false;
        }

        $cache = $this->getJsonArray(Configuration::get('CFB_DNS_RECORDS_CACHE'));
        foreach ($cache as &$rr) {
            if (!empty($rr['id']) && $rr['id'] === $recordId) {
                $rr['proxied'] = (bool) $proxiedOn;
                break;
            }
        }
        $this->persistDnsCache($cache);
        return true;
    }

    /* ==================== Football Check Logic ==================== */

    public function checkFootballAndManageCloudflare()
    {
        $settings = $this->getSettings();
        $calc = $this->computeStatusesFromJson();
        $general = $calc['general'];
        $domain = $calc['domain'];
        $blockedDomainIps = $calc['blocked_domain_ips'] ?? [];
        $storedBlocked = $this->getJsonArray($settings['bypass_blocked_ips']);
        $nowTs = time();
        $prevActive = !empty($settings['bypass_active']);
        $lastChange = (int) ($settings['bypass_last_change'] ?? 0);
        $cooldownMinutes = max(5, min(1440, (int) ($settings['bypass_check_cooldown'] ?? 60)));
        $cooldownSeconds = $cooldownMinutes * 60;

        $shouldDisable = ($domain === 'SÍ');
        $reason = $shouldDisable ? 'domain_blocked' : 'domain_clear';

        if (!$shouldDisable && !empty($settings['force_proxy_off_override']) && $general === 'SÍ') {
            $shouldDisable = true;
            $reason = 'override_general_football';
        }

        if (!$shouldDisable && $prevActive) {
            $stillWaiting = array_intersect($storedBlocked, $blockedDomainIps);
            if (!empty($stillWaiting)) {
                $shouldDisable = true;
                $reason = 'waiting_previous_ips';
            } elseif ($lastChange && ($nowTs - $lastChange) < $cooldownSeconds) {
                $shouldDisable = true;
                $reason = 'cooldown';
            }
        }

        $desiredProxied = !$shouldDisable;
        $updated = 0;
        $selected = $this->getJsonArray($settings['selected_records']);

        if (!empty($selected)) {
            $fresh = $this->fetchDnsRecords();
            if (!empty($fresh)) {
                $this->persistDnsCache($fresh);
            }
            foreach ($selected as $rid) {
                $result = $this->updateRecordProxyStatus($rid, $desiredProxied);
                if ($result) {
                    ++$updated;
                }
            }
        }

        $nowMysql = date('Y-m-d H:i:s');
        $settings['last_check'] = $nowMysql;
        $settings['last_status_general'] = ($general === 'SÍ') ? 'SI' : 'NO';
        $settings['last_status_domain'] = ($domain === 'SÍ') ? 'SI' : 'NO';
        $settings['last_update'] = $calc['last_update'] ?? $settings['last_update'];

        if ($shouldDisable) {
            if (!$prevActive) {
                $settings['bypass_last_change'] = $nowTs;
            }
            $settings['bypass_active'] = 1;
            $settings['bypass_blocked_ips'] = json_encode(!empty($blockedDomainIps) ? $blockedDomainIps : $storedBlocked);
        } else {
            if ($prevActive) {
                $settings['bypass_last_change'] = $nowTs;
            }
            $settings['bypass_active'] = 0;
            $settings['bypass_blocked_ips'] = '[]';
        }

        $this->saveSettings($settings);

        $newActive = !empty($settings['bypass_active']);
        if ($newActive !== $prevActive) {
            $this->maybeSendStateChangeEmail($newActive, $reason);
        }

        $this->logEvent('cron', 'Auto-check', [
            'general' => $settings['last_status_general'],
            'domain' => $settings['last_status_domain'],
            'selected' => count($selected),
            'updated' => $updated,
            'reason' => $reason,
            'bypass_active' => $settings['bypass_active'],
            'override' => !empty($settings['force_proxy_off_override']) ? 1 : 0,
        ]);
    }

    public function computeStatusesFromJson($forceRefreshIps = false)
    {
        $domain = $this->getSiteDomain();
        $data = $this->fetchStatusJson();
        if ($data === null) {
            return [
                'general' => 'NO', 'domain' => 'NO', 'fresh' => false,
                'domain_ips' => [], 'blocked_domain_ips' => [], 'last_update' => '',
                'isps_blocked' => [], 'min_isps' => 2,
            ];
        }

        $map = $data['ip_map'] ?? [];
        $ispsBlocked = $data['isps_blocked'] ?? [];
        $lastUpdateStr = $data['last_update'] ?? '';
        $s = $this->getSettings();
        $minIsps = max(1, (int) ($s['min_isps_blocked'] ?? 2));

        $blockedIpCount = 0;
        foreach ($map as $blocked) {
            if ($blocked === true) {
                ++$blockedIpCount;
            }
        }

        if ($blockedIpCount > 0 && empty($ispsBlocked)) {
            $generalBlocked = true;
        } else {
            $generalBlocked = ($blockedIpCount > 0) && (count($ispsBlocked) >= $minIsps);
        }

        $resolvedIps = $this->resolveDomainIps($domain, $forceRefreshIps);
        $blockedDomainIps = [];
        $domainBlocked = false;
        foreach ($resolvedIps as $ip) {
            if (isset($map[$ip]) && $map[$ip] === true) {
                $domainBlocked = true;
                $blockedDomainIps[] = $ip;
            }
        }

        return [
            'general' => $generalBlocked ? 'SÍ' : 'NO',
            'domain' => $domainBlocked ? 'SÍ' : 'NO',
            'fresh' => $data['fresh'] ?? false,
            'domain_ips' => $resolvedIps,
            'blocked_domain_ips' => $blockedDomainIps,
            'last_update' => $lastUpdateStr,
            'isps_blocked' => $ispsBlocked,
            'min_isps' => $minIsps,
        ];
    }

    /* ==================== Feed / JSON ==================== */

    private function fetchStatusJson()
    {
        $cacheDir = $this->getCacheDir();
        $localPath = $cacheDir . '/data.json';

        $localBody = null;
        $lastLocal = null;
        if (file_exists($localPath) && is_readable($localPath) && filesize($localPath) > 0) {
            $localBody = @file_get_contents($localPath);
            if ($localBody !== false) {
                $jsonLocal = json_decode($localBody, true);
                if (is_array($jsonLocal) && !empty($jsonLocal['lastUpdate']) && is_string($jsonLocal['lastUpdate'])) {
                    $lastLocal = $jsonLocal['lastUpdate'];
                }
            }
        }

        $remoteOk = false;
        $remoteBody = null;
        $lastRemote = null;

        $lastFetchFile = $cacheDir . '/last_fetch.json';
        $lastFetch = null;
        if (file_exists($lastFetchFile)) {
            $lastFetch = json_decode(@file_get_contents($lastFetchFile), true);
        }
        $fetchThrottle = 60;
        $skipRemote = ($fetchThrottle > 0 && is_string($localBody) && $localBody !== ''
            && is_array($lastFetch) && !empty($lastFetch['ok']) && !empty($lastFetch['time'])
            && (time() - (int) $lastFetch['time']) < $fetchThrottle);

        if (!$skipRemote) {
            $shopUrl = Tools::getShopDomainSsl(true) . __PS_BASE_URI__;
            $ch = curl_init(self::FEED_URL);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 25,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_USERAGENT => 'CFB/' . $this->version . '; ' . $shopUrl,
            ]);
            $remoteBody = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($httpCode === 200 && $remoteBody) {
                $tmp = json_decode($remoteBody, true);
                if (is_array($tmp)) {
                    $remoteOk = true;
                    if (!empty($tmp['lastUpdate']) && is_string($tmp['lastUpdate'])) {
                        $lastRemote = $tmp['lastUpdate'];
                    }
                }
            }

            @file_put_contents($lastFetchFile, json_encode([
                'time' => time(),
                'ok' => $remoteOk,
                'code' => $httpCode,
                'error' => $curlErr,
            ]));
        }

        $shouldWrite = false;
        if ($remoteOk) {
            if (!$localBody) {
                $shouldWrite = true;
            } elseif ($lastRemote && $lastLocal) {
                try {
                    $dr = new DateTime($lastRemote);
                    $dl = new DateTime($lastLocal);
                    if ($dr > $dl) {
                        $shouldWrite = true;
                    }
                } catch (Exception $e) {
                    if (md5($remoteBody) !== md5($localBody)) {
                        $shouldWrite = true;
                    }
                }
            } elseif (md5($remoteBody) !== md5($localBody)) {
                $shouldWrite = true;
            }
        }

        if ($shouldWrite) {
            $this->ensureDirectories();
            @file_put_contents($localPath, $remoteBody);
            $localBody = $remoteBody;
        }

        $body = $localBody ?: ($remoteOk ? $remoteBody : null);
        if (!is_string($body) || $body === '') {
            return null;
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            return null;
        }

        $lastUpdateStr = (!empty($json['lastUpdate']) && is_string($json['lastUpdate'])) ? $json['lastUpdate'] : '';
        $fresh = false;
        if ($lastUpdateStr) {
            $ts = strtotime($lastUpdateStr);
            if ($ts) {
                $fresh = ((time() - $ts) >= 0 && (time() - $ts) <= self::FRESH_WINDOW);
            }
        }

        $extracted = $this->extractIpBlockMap($json);
        return [
            'fresh' => $fresh,
            'ip_map' => $extracted['ip_map'] ?? [],
            'isps_blocked' => $extracted['isps_blocked'] ?? [],
            'last_update' => $lastUpdateStr,
        ];
    }

    private function extractIpBlockMap($json)
    {
        $map = [];
        $ispsBlocked = [];
        $s = $this->getSettings();
        $staleHours = max(1, (int) ($s['stale_threshold_hours'] ?? 6));
        $staleSeconds = $staleHours * 3600;

        $ips = null;
        foreach (['ips', 'ip', 'data', 'results'] as $k) {
            if (isset($json[$k])) {
                $ips = $json[$k];
                break;
            }
        }
        if ($ips === null && is_array($json)) {
            $allIps = true;
            foreach ($json as $k => $v) {
                if (!filter_var($k, FILTER_VALIDATE_IP)) {
                    $allIps = false;
                    break;
                }
            }
            if ($allIps) {
                $ips = $json;
            }
        }
        if (!is_array($ips)) {
            return ['ip_map' => [], 'isps_blocked' => []];
        }

        if ($this->isAssoc($ips)) {
            foreach ($ips as $ip => $val) {
                if (!is_string($ip)) {
                    continue;
                }
                if (is_bool($val) || is_int($val) || is_string($val)) {
                    $b = $this->normalizeBoolLike($val);
                    if ($b !== null) {
                        $map[$ip] = $b;
                    }
                    continue;
                }
                if (is_array($val)) {
                    $latest = null;
                    $statechanges = $this->arrayValueCi($val, 'statechanges');
                    if (is_array($statechanges)) {
                        $st = $this->latestStateFromChanges($statechanges, $staleSeconds);
                        if ($st !== null) {
                            $latest = $st;
                        }
                    }
                    foreach (['isps', 'providers', 'carriers'] as $k) {
                        if ($latest !== null) {
                            break;
                        }
                        $sub = $this->arrayValueCi($val, $k);
                        if (is_array($sub)) {
                            $any = null;
                            foreach ($sub as $ispKey => $ispVal) {
                                if (!is_array($ispVal)) {
                                    continue;
                                }
                                $ispState = null;
                                $scSub = $this->arrayValueCi($ispVal, 'statechanges');
                                if (is_array($scSub)) {
                                    $ispState = $this->latestStateFromChanges($scSub, $staleSeconds);
                                } else {
                                    foreach (['blocked', 'isBlocked', 'status'] as $kk) {
                                        $v = $this->arrayValueCi($ispVal, $kk);
                                        if ($v !== null) {
                                            $nb = $this->normalizeBoolLike($v);
                                            if ($nb !== null) {
                                                $ispState = $nb;
                                                break;
                                            }
                                        }
                                    }
                                }
                                if ($ispState !== null) {
                                    $any = ($any === null) ? $ispState : ($any || $ispState);
                                    if ($ispState === true) {
                                        $ispName = is_string($ispKey) ? $ispKey : '';
                                        if ($ispName === '') {
                                            foreach (['name', 'isp', 'provider', 'carrier'] as $nk) {
                                                $nv = $this->arrayValueCi($ispVal, $nk);
                                                if (is_string($nv) && $nv !== '') {
                                                    $ispName = $nv;
                                                    break;
                                                }
                                            }
                                        }
                                        if ($ispName !== '') {
                                            $ispsBlocked[$ispName] = true;
                                        }
                                    }
                                }
                            }
                            if ($any !== null) {
                                $latest = $any;
                            }
                        }
                    }
                    if ($latest !== null) {
                        $map[$ip] = (bool) $latest;
                    }
                    foreach (['blocked', 'isBlocked', 'status'] as $kk) {
                        $v = $this->arrayValueCi($val, $kk);
                        if ($v !== null) {
                            $b = $this->normalizeBoolLike($v);
                            if ($b !== null) {
                                $map[$ip] = $b;
                                continue 2;
                            }
                        }
                    }
                }
            }
        } else {
            foreach ($ips as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $ip = (string) ($entry['ip'] ?? '');
                if (!$ip) {
                    continue;
                }
                $b = null;
                foreach (['blocked', 'isBlocked', 'status'] as $k) {
                    $v = $this->arrayValueCi($entry, $k);
                    if ($v !== null) {
                        $b = $this->normalizeBoolLike($v);
                        if ($b !== null) {
                            break;
                        }
                    }
                }
                if ($b === null) {
                    $statechanges = $this->arrayValueCi($entry, 'statechanges');
                    if (is_array($statechanges)) {
                        $st = $this->latestStateFromChanges($statechanges, $staleSeconds);
                        if ($st !== null) {
                            $b = $st;
                        }
                    }
                }
                if ($b !== null) {
                    $prev = isset($map[$ip]) ? (bool) $map[$ip] : false;
                    $map[$ip] = $prev || (bool) $b;
                    if ((bool) $b === true) {
                        $ispName = '';
                        foreach (['isp', 'provider', 'carrier', 'name'] as $nk) {
                            $nv = $this->arrayValueCi($entry, $nk);
                            if (is_string($nv) && $nv !== '') {
                                $ispName = $nv;
                                break;
                            }
                        }
                        if ($ispName !== '') {
                            $ispsBlocked[$ispName] = true;
                        }
                    }
                }
            }
        }

        return [
            'ip_map' => $map,
            'isps_blocked' => array_keys($ispsBlocked),
        ];
    }

    private function latestStateFromChanges($changes, $staleSeconds)
    {
        if (!is_array($changes)) {
            return null;
        }
        $maxTs = null;
        $state = null;
        foreach ($changes as $chg) {
            if (!is_array($chg)) {
                continue;
            }
            $ts = null;
            if (isset($chg['timestamp'])) {
                $ts = strtotime((string) $chg['timestamp']);
            } elseif (isset($chg['time'])) {
                $ts = strtotime((string) $chg['time']);
            }
            if ($ts === false) {
                $ts = null;
            }
            $s = isset($chg['state']) ? $this->normalizeBoolLike($chg['state']) : (isset($chg['status']) ? $this->normalizeBoolLike($chg['status']) : null);
            if ($s !== null && ($maxTs === null || ($ts !== null && $ts > $maxTs))) {
                $maxTs = $ts;
                $state = $s;
            }
        }
        if ($state === true && $maxTs !== null && $staleSeconds > 0 && (time() - $maxTs) > $staleSeconds) {
            $state = false;
        }
        return $state;
    }

    public function getFeedDiagnostics()
    {
        $data = $this->fetchStatusJson();
        $blockedCount = 0;
        $totalCount = 0;
        $ispsBlocked = [];
        if (is_array($data) && isset($data['ip_map']) && is_array($data['ip_map'])) {
            $totalCount = count($data['ip_map']);
            foreach ($data['ip_map'] as $blocked) {
                if ($blocked === true) {
                    ++$blockedCount;
                }
            }
            if (isset($data['isps_blocked']) && is_array($data['isps_blocked'])) {
                $ispsBlocked = $data['isps_blocked'];
            }
        }

        $localPath = $this->getCacheDir() . '/data.json';
        $localExists = file_exists($localPath);
        $localSize = $localExists ? (int) filesize($localPath) : 0;
        $localMtime = $localExists ? (int) filemtime($localPath) : 0;
        $localLastUpdate = '';
        if ($localExists && $localSize > 0) {
            $contents = @file_get_contents($localPath);
            if ($contents) {
                $decoded = json_decode($contents, true);
                if (is_array($decoded) && !empty($decoded['lastUpdate'])) {
                    $localLastUpdate = $decoded['lastUpdate'];
                }
            }
        }

        $lastFetchFile = $this->getCacheDir() . '/last_fetch.json';
        $remoteLast = ['time' => 0, 'ok' => false, 'code' => 0, 'error' => ''];
        if (file_exists($lastFetchFile)) {
            $rf = json_decode(@file_get_contents($lastFetchFile), true);
            if (is_array($rf)) {
                $remoteLast = $rf;
            }
        }

        return [
            'local_exists' => $localExists,
            'local_size' => $localSize,
            'local_mtime' => $localMtime,
            'local_last_update' => $localLastUpdate,
            'remote_last' => $remoteLast,
            'blocked_count' => $blockedCount,
            'total_count' => $totalCount,
            'isps_blocked' => $ispsBlocked,
            'min_isps' => max(1, (int) ($this->getSettings()['min_isps_blocked'] ?? 2)),
        ];
    }

    public function clearDataCache()
    {
        $localPath = $this->getCacheDir() . '/data.json';
        $deleted = false;
        if (file_exists($localPath)) {
            $deleted = @unlink($localPath);
        }
        $lastFetchFile = $this->getCacheDir() . '/last_fetch.json';
        if (file_exists($lastFetchFile)) {
            @unlink($lastFetchFile);
        }
        return $deleted;
    }

    /* ==================== Email Notifications ==================== */

    private function maybeSendStateChangeEmail($nowActive, $reason = '')
    {
        $s = $this->getSettings();
        if (empty($s['email_notifications'])) {
            return;
        }

        $throttle = 120;
        $lastSent = (int) Configuration::get('CFB_LAST_EMAIL_SENT');
        if ($lastSent && (time() - $lastSent) < $throttle) {
            return;
        }

        $custom = trim($s['notification_email'] ?? '');
        $to = (!empty($custom) && Validate::isEmail($custom)) ? $custom : Configuration::get('PS_SHOP_EMAIL');
        if (!Validate::isEmail($to)) {
            return;
        }

        $shopName = Configuration::get('PS_SHOP_NAME');
        $shopUrl = Tools::getShopDomainSsl(true) . __PS_BASE_URI__;

        if ($nowActive) {
            $subject = sprintf('[%s] Cloudflare proxy disabled — football blocks', $shopName);
            $body = "Active IP blocks from La Liga detected.\n"
                . "Cloudflare proxy has been automatically disabled.\n\n"
                . "Site: $shopName\nURL: $shopUrl\nState: Proxy OFF (DNS Only)\n"
                . ($reason ? "Reason: $reason\n" : '')
                . "\nProxy will be re-enabled once blocks end and cooldown passes.\n"
                . "\n-- CF Football Bypass v" . $this->version;
        } else {
            $subject = sprintf('[%s] Cloudflare proxy re-enabled', $shopName);
            $body = "No active La Liga IP blocks detected.\n"
                . "Cloudflare proxy re-enabled automatically.\n\n"
                . "Site: $shopName\nURL: $shopUrl\nState: Proxy ON (CDN)\n"
                . "\n-- CF Football Bypass v" . $this->version;
        }

        $sent = @mail($to, $subject, $body, 'From: ' . Configuration::get('PS_SHOP_EMAIL'));
        if ($sent) {
            Configuration::updateValue('CFB_LAST_EMAIL_SENT', time());
        }
        $this->logEvent('email', 'Notification ' . ($sent ? 'sent' : 'FAILED') . ' to ' . $this->anonymizeIp($to));
    }

    /* ==================== Logging ==================== */

    public function logEvent($type, $message, $context = [])
    {
        $s = $this->getSettings();
        if (empty($s['logging_enabled'])) {
            return;
        }
        $path = $this->getLogFilePath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
            @file_put_contents($dir . '/.htaccess', "Deny from all\n");
            @file_put_contents($dir . '/index.php', "<?php\n");
        }

        if (!empty($context['ip'])) {
            $context['ip'] = $this->anonymizeIp($context['ip']);
        }

        $entry = json_encode([
            'time' => date('Y-m-d H:i:s'),
            'type' => $type,
            'message' => $message,
            'context' => $context,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        @file_put_contents($path, $entry . "\n", FILE_APPEND | LOCK_EX);

        $retDays = max(1, (int) ($s['log_retention_days'] ?? 30));
        $this->pruneOldLogs($path, $retDays);
    }

    private function readLogEntries($limit = 250)
    {
        $path = $this->getLogFilePath();
        if (!file_exists($path)) {
            return [];
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) {
            return [];
        }
        $lines = array_reverse($lines);
        $entries = [];
        foreach ($lines as $line) {
            if (count($entries) >= $limit) {
                break;
            }
            $entry = json_decode($line, true);
            if (is_array($entry)) {
                $entries[] = $entry;
            }
        }
        return $entries;
    }

    private function pruneOldLogs($path, $retentionDays)
    {
        static $pruned = false;
        if ($pruned) {
            return;
        }
        $pruned = true;

        if (!file_exists($path) || filesize($path) < 10240) {
            return;
        }
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) {
            return;
        }
        $kept = [];
        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if (is_array($entry) && isset($entry['time']) && $entry['time'] >= $cutoff) {
                $kept[] = $line;
            }
        }
        if (count($kept) < count($lines)) {
            @file_put_contents($path, implode("\n", $kept) . "\n", LOCK_EX);
        }
    }

    public function getLogFilePath()
    {
        $secret = Configuration::get('CFB_LOG_SECRET');
        if (empty($secret)) {
            $secret = bin2hex(random_bytes(16));
            Configuration::updateValue('CFB_LOG_SECRET', $secret);
        }
        return _PS_MODULE_DIR_ . $this->name . '/logs/cfb-' . $secret . '.log';
    }

    /* ==================== DNS Resolution ==================== */

    private function resolveDomainIps($domain, $force = false)
    {
        static $cache = null;
        if (!$force && $cache !== null && isset($cache['domain']) && $cache['domain'] === $domain) {
            return $cache['ips'];
        }
        $ips = [];
        if ($domain && !$this->isLocalDomain($domain)) {
            $records = @dns_get_record($domain, DNS_A | DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $r) {
                    if (!empty($r['type'])) {
                        if ($r['type'] === 'A' && !empty($r['ip'])) {
                            $ips[] = $r['ip'];
                        } elseif ($r['type'] === 'AAAA' && !empty($r['ipv6'])) {
                            $ips[] = $r['ipv6'];
                        }
                    }
                }
            }
        }
        $ips = array_values(array_unique($ips));
        $cache = ['domain' => $domain, 'ips' => $ips];
        return $ips;
    }

    public function persistDnsCache(array $records)
    {
        Configuration::updateValue('CFB_DNS_RECORDS_CACHE', json_encode($records));
        Configuration::updateValue('CFB_DNS_CACHE_LAST_SYNC', date('Y-m-d H:i:s'));
    }

    /* ==================== Settings Management ==================== */

    public function getDefaultSettings()
    {
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
        $defaults = $this->getDefaultSettings();
        $settings = [];
        foreach ($defaults as $key => $default) {
            $value = Configuration::get('CFB_' . strtoupper($key));
            $settings[$key] = ($value === false) ? $default : $value;
        }
        return $settings;
    }

    public function saveSettings($settings)
    {
        foreach ($settings as $key => $value) {
            Configuration::updateValue(
                'CFB_' . strtoupper($key),
                is_array($value) ? json_encode($value) : $value
            );
        }
    }

    private function normalizeSettings()
    {
        $s = $this->getSettings();
        $changed = false;

        if (!in_array($s['auth_type'], ['global', 'token', 'account_token'], true)) {
            $s['auth_type'] = 'global';
            $changed = true;
        }
        if (!in_array($s['ui_mode'], ['simple', 'advanced'], true)) {
            $s['ui_mode'] = 'simple';
            $changed = true;
        }
        $intFields = [
            'check_interval' => [5, 60, 15],
            'bypass_check_cooldown' => [5, 1440, 60],
            'min_isps_blocked' => [1, 20, 2],
            'stale_threshold_hours' => [1, 72, 6],
            'log_retention_days' => [1, 365, 30],
        ];
        foreach ($intFields as $field => [$min, $max, $def]) {
            $val = (int) $s[$field];
            $clamped = max($min, min($max, $val ?: $def));
            if ($clamped !== (int) $s[$field]) {
                $s[$field] = $clamped;
                $changed = true;
            }
        }

        $logSecret = Configuration::get('CFB_LOG_SECRET');
        if (empty($logSecret)) {
            Configuration::updateValue('CFB_LOG_SECRET', bin2hex(random_bytes(16)));
        }

        if ($changed) {
            $this->saveSettings($s);
        }
    }

    /* ==================== HTTP Helper ==================== */

    public function makeHttpRequest($url, $method = 'GET', $headers = [], $body = null, $timeout = 30)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, '_error' => $error, '_http' => $httpCode];
        }
        $json = json_decode($response, true);
        if (!is_array($json)) {
            return ['success' => false, '_error' => 'Invalid JSON', '_http' => $httpCode, '_raw' => substr($response, 0, 500)];
        }
        $json['_http'] = $httpCode;
        return $json;
    }

    /* ==================== Auto-Update Check ==================== */

    private function checkForUpdate()
    {
        $cacheFile = $this->getCacheDir() . '/update_check.json';
        $cached = null;
        if (file_exists($cacheFile)) {
            $cached = json_decode(@file_get_contents($cacheFile), true);
            if (is_array($cached) && !empty($cached['checked_at'])
                && (time() - (int) $cached['checked_at']) < self::UPDATE_CHECK_INTERVAL) {
                if (!empty($cached['version']) && version_compare($cached['version'], $this->version, '>')) {
                    return $cached;
                }
                return null;
            }
        }

        $url = 'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Accept: application/vnd.github.v3+json', 'User-Agent: CFB/' . $this->version],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = ['checked_at' => time(), 'version' => null, 'zip_url' => '', 'html_url' => ''];

        if ($code === 200 && $body) {
            $release = json_decode($body, true);
            if (is_array($release) && !empty($release['tag_name'])) {
                $tag = ltrim($release['tag_name'], 'vV');
                $result['version'] = $tag;
                $result['html_url'] = $release['html_url'] ?? '';
                foreach (($release['assets'] ?? []) as $asset) {
                    if (isset($asset['browser_download_url']) && str_ends_with($asset['name'] ?? '', '.zip')) {
                        $result['zip_url'] = $asset['browser_download_url'];
                        break;
                    }
                }
                if (empty($result['zip_url']) && !empty($release['zipball_url'])) {
                    $result['zip_url'] = $release['zipball_url'];
                }
            }
        }

        $this->ensureDirectories();
        @file_put_contents($cacheFile, json_encode($result));

        if (!empty($result['version']) && version_compare($result['version'], $this->version, '>')) {
            return $result;
        }
        return null;
    }

    /* ==================== Utility Methods ==================== */

    public function generateAjaxToken()
    {
        return Tools::encrypt($this->name . (int) $this->context->employee->id . date('Ymd'));
    }

    public function verifyAjaxToken($token)
    {
        $expected = Tools::encrypt($this->name . (int) $this->context->employee->id . date('Ymd'));
        return hash_equals($expected, $token);
    }

    public function getSiteDomain()
    {
        $shopUrl = Tools::getShopDomainSsl(false);
        if ($shopUrl) {
            return $shopUrl;
        }
        return isset($_SERVER['HTTP_HOST']) ? preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST']) : '';
    }

    public function isLocalDomain($domain)
    {
        if (empty($domain)) {
            return true;
        }
        $local = ['localhost', '127.0.0.1', '::1', '0.0.0.0'];
        if (in_array($domain, $local, true)) {
            return true;
        }
        if (preg_match('/\.local$|\.localhost$|\.test$|\.example$|\.invalid$|\.internal$/i', $domain)) {
            return true;
        }
        if (filter_var($domain, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        if (filter_var($domain, FILTER_VALIDATE_IP)) {
            return true;
        }
        return false;
    }

    private function domainMatchesZone($domain, $zoneName)
    {
        if (empty($domain) || empty($zoneName)) {
            return false;
        }
        $domain = strtolower(trim($domain, '.'));
        $zoneName = strtolower(trim($zoneName, '.'));
        return $domain === $zoneName || str_ends_with($domain, '.' . $zoneName);
    }

    public function mask($str, $left = 6, $right = 4)
    {
        if (empty($str)) {
            return '—';
        }
        $len = strlen($str);
        if ($len <= ($left + $right)) {
            return str_repeat('*', max(0, $len));
        }
        return substr($str, 0, $left) . str_repeat('*', $len - $left - $right) . substr($str, -$right);
    }

    public function anonymizeIp($ip)
    {
        if (empty($ip)) {
            return '';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            $parts[3] = 'xxx';
            return implode('.', $parts);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $expanded = inet_ntop(inet_pton($ip));
            $parts = explode(':', $expanded);
            $count = count($parts);
            for ($i = max(0, $count - 4); $i < $count; ++$i) {
                $parts[$i] = 'xxxx';
            }
            return implode(':', $parts);
        }
        return preg_replace('/\d/', 'x', $ip);
    }

    private function getServerOutgoingIps()
    {
        $ips = [];
        $services = [
            'https://api.ipify.org',
            'https://ipv4.icanhazip.com',
        ];
        foreach ($services as $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
            ]);
            $resp = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code === 200 && $resp) {
                $ip = trim($resp);
                if (filter_var($ip, FILTER_VALIDATE_IP) && !in_array($ip, $ips, true)) {
                    $ips[] = $ip;
                }
            }
            if (!empty($ips)) {
                break;
            }
        }
        return $ips;
    }

    private function getAuthLabel($authType)
    {
        $labels = [
            'global' => 'Global Key',
            'token' => 'Token',
            'account_token' => 'Account Token',
        ];
        return $labels[$authType] ?? $authType;
    }

    public function currentUserLabel()
    {
        if (isset($this->context->employee) && $this->context->employee->id) {
            return $this->context->employee->firstname . ' ' . $this->context->employee->lastname;
        }
        return 'system';
    }

    private function normalizeBoolLike($val)
    {
        if (is_bool($val)) {
            return $val;
        }
        if (is_int($val)) {
            return $val !== 0;
        }
        if (is_string($val)) {
            $low = strtolower(trim($val));
            if (in_array($low, ['true', '1', 'yes', 'si', 'sí', 'on', 'blocked', 'active'], true)) {
                return true;
            }
            if (in_array($low, ['false', '0', 'no', 'off', 'clear', 'inactive', 'free', ''], true)) {
                return false;
            }
        }
        return null;
    }

    private function isAssoc($arr)
    {
        if (!is_array($arr) || $arr === []) {
            return false;
        }
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    private function arrayValueCi($arr, $key)
    {
        if (!is_array($arr)) {
            return null;
        }
        $keyLow = strtolower($key);
        foreach ($arr as $k => $v) {
            if (strtolower((string) $k) === $keyLow) {
                return $v;
            }
        }
        return null;
    }

    private function getJsonArray($value)
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    private function getCacheDir()
    {
        return _PS_MODULE_DIR_ . $this->name . '/cache';
    }

    private function ensureDirectories()
    {
        $dirs = [
            _PS_MODULE_DIR_ . $this->name . '/logs',
            _PS_MODULE_DIR_ . $this->name . '/cache',
        ];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $htaccess = $dir . '/.htaccess';
            if (!file_exists($htaccess)) {
                @file_put_contents($htaccess, "Deny from all\n");
            }
            $index = $dir . '/index.php';
            if (!file_exists($index)) {
                @file_put_contents($index, "<?php\n");
            }
        }
    }

    public function formatSize($bytes)
    {
        $bytes = (int) $bytes;
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return number_format($bytes / 1024, 1) . ' KB';
        }
        return number_format($bytes / 1048576, 2) . ' MB';
    }
}
