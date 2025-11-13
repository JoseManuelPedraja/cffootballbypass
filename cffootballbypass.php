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

class CfFootballBypass extends Module
{
    private $log_file_path;

    public function __construct()
    {
        $this->name = 'cffootballbypass';
        $this->tab = 'administration';
        $this->version = '1.5.5';
        $this->author = 'Jose Manuel Pedraja';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = [
            'min' => '8.1.0',
            'max' => _PS_VERSION_,
        ];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('CF Football Bypass');
        $this->description = $this->l('Operates with Cloudflare to toggle Proxy (ON/CDN) and DNS Only (OFF) based on blocks, with persistent record caching.');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall this module?');

        $this->log_file_path = _PS_MODULE_DIR_ . $this->name . '/logs/cfb-actions.log';
    }

    public function install()
    {
        if (Shop::isFeatureActive()) {
            Shop::setContext(Shop::CONTEXT_ALL);
        }

        return parent::install()
            && $this->installTab()
            && $this->createLogDirectory()
            && $this->setDefaultConfiguration()
            && $this->registerHook('actionCronJob');
    }

    public function uninstall()
    {
        return parent::uninstall()
            && $this->uninstallTab()
            && $this->removeConfiguration();
    }

    private function installTab()
    {
        $tab = new Tab();
        $tab->active = true;
        $tab->class_name = 'AdminCfFootballBypass';
        $tab->name = [];
        foreach (Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = 'CF Football Bypass';
        }
        
        $parent_tab = Tab::getInstanceFromClassName('AdminParentModulesSf');
        if ($parent_tab && $parent_tab->id) {
            $tab->id_parent = (int)$parent_tab->id;
        } else {
            $tab->id_parent = 0;
        }
        
        $tab->module = $this->name;

        return $tab->add();
    }

    private function uninstallTab()
    {
        $tab_instance = Tab::getInstanceFromClassName('AdminCfFootballBypass');
        if ($tab_instance && $tab_instance->id) {
            return $tab_instance->delete();
        }

        return true;
    }

    private function createLogDirectory()
    {
        $log_dir = _PS_MODULE_DIR_ . $this->name . '/logs';
        if (!file_exists($log_dir)) {
            return mkdir($log_dir, 0755, true);
        }

        return true;
    }

    private function setDefaultConfiguration()
    {
        $defaults = $this->getDefaultSettings();
        foreach ($defaults as $key => $value) {
            Configuration::updateValue('CFB_' . strtoupper($key), is_array($value) ? json_encode($value) : $value);
        }

        return true;
    }

    private function removeConfiguration()
    {
        $keys = [
            'CLOUDFLARE_EMAIL', 'CLOUDFLARE_API_KEY', 'CLOUDFLARE_ZONE_ID',
            'AUTH_TYPE', 'CHECK_INTERVAL', 'SELECTED_RECORDS', 'DNS_RECORDS_CACHE',
            'DNS_CACHE_LAST_SYNC', 'LAST_CHECK', 'LAST_STATUS_GENERAL',
            'LAST_STATUS_DOMAIN', 'LAST_UPDATE', 'LOGGING_ENABLED',
            'LOG_RETENTION_DAYS', 'CRON_SECRET', 'BYPASS_ACTIVE',
            'BYPASS_BLOCKED_IPS', 'BYPASS_CHECK_COOLDOWN', 'BYPASS_LAST_CHANGE',
        ];

        foreach ($keys as $key) {
            Configuration::deleteByName('CFB_' . $key);
        }

        return true;
    }

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitCfbSettings')) {
            $output .= $this->postProcess();
        }

        $output .= $this->displayForm();

        return $output;
    }

    private function postProcess()
    {
        $settings = $this->getSettings();

        $settings['cloudflare_email'] = Tools::getValue('cloudflare_email');
        $settings['cloudflare_api_key'] = Tools::getValue('cloudflare_api_key');
        $settings['cloudflare_zone_id'] = Tools::getValue('cloudflare_zone_id');
        $settings['auth_type'] = Tools::getValue('auth_type', 'global');
        $settings['check_interval'] = max(5, min(60, (int)Tools::getValue('check_interval', 15)));
        $settings['logging_enabled'] = (int)Tools::getValue('logging_enabled', 1);
        $settings['log_retention_days'] = max(1, (int)Tools::getValue('log_retention_days', 30));
        $settings['bypass_check_cooldown'] = max(5, min(1440, (int)Tools::getValue('bypass_check_cooldown', 60)));

        if (Tools::getValue('reset_settings')) {
            $settings = $this->getDefaultSettings(true);
            $this->clearLogs();
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

        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => $this->l('CF Football Bypass Settings'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'select',
                        'label' => $this->l('Authentication Type'),
                        'name' => 'auth_type',
                        'options' => [
                            'query' => [
                                ['id' => 'global', 'name' => 'Global API Key'],
                                ['id' => 'token', 'name' => 'API Token (Bearer)'],
                            ],
                            'id' => 'id',
                            'name' => 'name',
                        ],
                        'desc' => $this->l('Global API Key requires email; API Token does not.'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Cloudflare Email'),
                        'name' => 'cloudflare_email',
                        'desc' => $this->l('Required only for Global API Key'),
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
                        'desc' => $this->l('Wait time after deactivating Cloudflare (5-1440 min)'),
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Action Logging'),
                        'name' => 'logging_enabled',
                        'values' => [
                            ['id' => 'active_on', 'value' => 1, 'label' => $this->l('Yes')],
                            ['id' => 'active_off', 'value' => 0, 'label' => $this->l('No')],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Log Retention (days)'),
                        'name' => 'log_retention_days',
                        'class' => 'fixed-width-sm',
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Reset Settings'),
                        'name' => 'reset_settings',
                        'values' => [
                            ['id' => 'reset_on', 'value' => 1, 'label' => $this->l('Yes')],
                            ['id' => 'reset_off', 'value' => 0, 'label' => $this->l('No')],
                        ],
                        'desc' => $this->l('WARNING: Deletes all module configuration'),
                    ],
                ],
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
                'auth_type' => $settings['auth_type'],
                'cloudflare_email' => $settings['cloudflare_email'],
                'cloudflare_api_key' => $settings['cloudflare_api_key'],
                'cloudflare_zone_id' => $settings['cloudflare_zone_id'],
                'check_interval' => $settings['check_interval'],
                'bypass_check_cooldown' => $settings['bypass_check_cooldown'],
                'logging_enabled' => $settings['logging_enabled'],
                'log_retention_days' => $settings['log_retention_days'],
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

        $cron_url = $this->context->shop->getBaseURL(true) . 'modules/' . $this->name . '/cron.php?token=' . $cron_token;

        $html = '<div class="panel">';
        $html .= '<div class="panel-heading"><h3>' . $this->l('Operation') . '</h3></div>';
        $html .= '<div class="panel-body">';

        $html .= '<div class="alert alert-info">';
        $html .= '<h4>' . $this->l('Cron Job URL') . '</h4>';
        $html .= '<p>' . $this->l('Configure your cron job or external service (like EasyCron) with this URL:') . '</p>';
        $html .= '<input type="text" class="form-control" readonly value="' . htmlspecialchars($cron_url, ENT_QUOTES, 'UTF-8') . '" onclick="this.select();" style="font-family:monospace;">';
        $html .= '<p class="help-block">' . $this->l('Recommended: Every 15 minutes') . '</p>';
        $html .= '</div>';

        $html .= '<p><strong>' . $this->l('Domain') . ':</strong> ' . htmlspecialchars($domain, ENT_QUOTES, 'UTF-8') . '</p>';
        $html .= '<p><strong>' . $this->l('General status (blocks exist)') . ':</strong> ' . htmlspecialchars($calc['general'], ENT_QUOTES, 'UTF-8') . '</p>';
        $html .= '<p><strong>' . $this->l('Domain blocked?') . ':</strong> ' . htmlspecialchars($calc['domain'], ENT_QUOTES, 'UTF-8') . '</p>';

        if (!empty($cache)) {
            $html .= '<h4>' . $this->l('DNS Records') . '</h4>';
            $html .= '<div id="dns-table-container">';
            $html .= '<table class="table">';
            $html .= '<thead><tr><th>' . $this->l('Select') . '</th><th>' . $this->l('Name') . '</th><th>' . $this->l('Type') . '</th><th>' . $this->l('Content') . '</th><th>' . $this->l('Proxy') . '</th><th>TTL</th></tr></thead>';
            $html .= '<tbody>';
            foreach ($cache as $record) {
                $checked = in_array($record['id'], $selected) ? 'checked' : '';
                $proxy_status = isset($record['proxied']) ? ($record['proxied'] ? 'ON' : 'OFF') : '—';
                $html .= '<tr>';
                $html .= '<td><input type="checkbox" name="selected_records[]" value="' . htmlspecialchars($record['id'], ENT_QUOTES, 'UTF-8') . '" ' . $checked . '></td>';
                $html .= '<td>' . htmlspecialchars($record['name'], ENT_QUOTES, 'UTF-8') . '</td>';
                $html .= '<td>' . htmlspecialchars($record['type'], ENT_QUOTES, 'UTF-8') . '</td>';
                $html .= '<td>' . htmlspecialchars($record['content'], ENT_QUOTES, 'UTF-8') . '</td>';
                $html .= '<td>' . htmlspecialchars($proxy_status, ENT_QUOTES, 'UTF-8') . '</td>';
                $html .= '<td>' . htmlspecialchars($record['ttl'], ENT_QUOTES, 'UTF-8') . '</td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
            $html .= '</div>';
        }

        $html .= '<div class="btn-group" style="margin-top:15px;">';
        $html .= '<button type="button" class="btn btn-primary" onclick="testConnection()">' . $this->l('Test connection and load DNS') . '</button>';
        $html .= '<button type="button" class="btn btn-default" onclick="manualCheck()">' . $this->l('Manual check') . '</button>';
        $html .= '<button type="button" class="btn btn-warning" onclick="forceOff()">' . $this->l('Force Proxy OFF') . '</button>';
        $html .= '<button type="button" class="btn btn-success" onclick="forceOn()">' . $this->l('Force Proxy ON') . '</button>';
        $html .= '</div>';

        $html .= '<div id="cfb-console" class="alert alert-info" style="margin-top:15px;"><strong>' . $this->l('Console') . ':</strong><pre id="console-output" style="max-height:300px;overflow-y:auto;"></pre></div>';

        $html .= '</div></div>';

        $html .= $this->getJavaScript();

        return $html;
    }

    private function getJavaScript()
    {
        $ajax_url = $this->context->link->getModuleLink($this->name, 'ajax');

        return '
        <script>
        function addToConsole(message) {
            var console = document.getElementById("console-output");
            var timestamp = new Date().toLocaleTimeString();
            console.innerHTML += "[" + timestamp + "] " + message + "\\n";
            console.parentElement.scrollTop = console.parentElement.scrollHeight;
        }

        function clearConsole() {
            document.getElementById("console-output").innerHTML = "";
        }

        function getSelectedRecords() {
            var checkboxes = document.querySelectorAll("input[name=\'selected_records[]\']:checked");
            var selected = [];
            checkboxes.forEach(function(cb) {
                selected.push(cb.value);
            });
            return selected;
        }

        function testConnection() {
            clearConsole();
            addToConsole("' . $this->l('Testing connection...') . '");

            var selected = getSelectedRecords();
            var ajaxUrl = "' . $ajax_url . '";
            addToConsole("URL: " + ajaxUrl);

            fetch(ajaxUrl, {
                method: "POST",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded",
                },
                body: "action=test_connection&ajax=1&selected=" + encodeURIComponent(JSON.stringify(selected))
            })
            .then(response => {
                addToConsole("Status: " + response.status);
                return response.text();
            })
            .then(text => {
                if (!text || text.trim() === "") {
                    addToConsole("' . $this->l('ERROR: Empty server response') . '");
                    return;
                }

                addToConsole("Response: " + text.substring(0, 300));

                try {
                    var data = JSON.parse(text);

                    if (data.success) {
                        addToConsole("' . $this->l('Connection OK') . '");
                        if (data.log && data.log.length) {
                            data.log.forEach(line => addToConsole(line));
                        }
                        if (data.html) {
                            var container = document.getElementById("dns-table-container");
                            if (container) {
                                container.innerHTML = data.html;
                            }
                        }
                    } else {
                        addToConsole("' . $this->l('Error') . ': " + (data.message || "' . $this->l('Unknown error') . '"));
                        if (data.log && data.log.length) {
                            data.log.forEach(line => addToConsole(line));
                        }
                    }
                } catch (e) {
                    addToConsole("' . $this->l('ERROR parsing JSON') . ': " + e.message);
                }
            })
            .catch(error => {
                addToConsole("' . $this->l('Network error') . ': " + error);
            });
        }

        function manualCheck() {
            clearConsole();
            addToConsole("' . $this->l('Running manual check...') . '");

            var selected = getSelectedRecords();
            var ajaxUrl = "' . $ajax_url . '";

            fetch(ajaxUrl, {
                method: "POST",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded",
                },
                body: "action=manual_check&ajax=1&selected=" + encodeURIComponent(JSON.stringify(selected))
            })
            .then(response => {
                addToConsole("Status: " + response.status);
                return response.text();
            })
            .then(text => {
                if (!text || text.trim() === "") {
                    addToConsole("' . $this->l('ERROR: Empty server response') . '");
                    return;
                }

                addToConsole("Response: " + text.substring(0, 300));

                try {
                    var data = JSON.parse(text);

                    if (data.success) {
                        addToConsole("' . $this->l('Check completed') . '");
                        if (data.log && data.log.length) {
                            data.log.forEach(line => addToConsole(line));
                        }
                        addToConsole("General: " + (data.general || "—"));
                        addToConsole("Domain: " + (data.domain || "—"));
                    } else {
                        addToConsole("' . $this->l('Error') . ': " + (data.message || "' . $this->l('Unknown error') . '"));
                    }
                } catch (e) {
                    addToConsole("' . $this->l('ERROR parsing JSON') . ': " + e.message);
                }
            })
            .catch(error => {
                addToConsole("' . $this->l('Error') . ': " + error);
            });
        }

        function forceOff() {
            clearConsole();
            addToConsole("' . $this->l('Forcing Proxy OFF...') . '");

            var selected = getSelectedRecords();
            if (selected.length === 0) {
                addToConsole("' . $this->l('ERROR: No records selected') . '");
                return;
            }

            var ajaxUrl = "' . $ajax_url . '";

            fetch(ajaxUrl, {
                method: "POST",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded",
                },
                body: "action=force_deactivate&ajax=1&selected=" + encodeURIComponent(JSON.stringify(selected))
            })
            .then(response => {
                addToConsole("Status: " + response.status);
                return response.text();
            })
            .then(text => {
                if (!text || text.trim() === "") {
                    addToConsole("' . $this->l('ERROR: Empty server response') . '");
                    return;
                }

                addToConsole("Response: " + text.substring(0, 300));

                try {
                    var data = JSON.parse(text);

                    if (data.success) {
                        addToConsole("' . $this->l('Proxy OFF applied') . '");
                        if (data.message) addToConsole(data.message);
                        if (data.log && data.log.length) {
                            data.log.forEach(line => addToConsole(line));
                        }
                        if (data.html) {
                            var container = document.getElementById("dns-table-container");
                            if (container) {
                                container.innerHTML = data.html;
                            }
                        }
                    } else {
                        addToConsole("' . $this->l('Error') . ': " + (data.message || "' . $this->l('Unknown error') . '"));
                    }
                } catch (e) {
                    addToConsole("' . $this->l('ERROR parsing JSON') . ': " + e.message);
                }
            })
            .catch(error => {
                addToConsole("' . $this->l('Error') . ': " + error);
            });
        }

        function forceOn() {
            clearConsole();
            addToConsole("' . $this->l('Forcing Proxy ON...') . '");

            var selected = getSelectedRecords();
            if (selected.length === 0) {
                addToConsole("' . $this->l('ERROR: No records selected') . '");
                return;
            }

            var ajaxUrl = "' . $ajax_url . '";

            fetch(ajaxUrl, {
                method: "POST",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded",
                },
                body: "action=force_activate&ajax=1&selected=" + encodeURIComponent(JSON.stringify(selected))
            })
            .then(response => {
                addToConsole("Status: " + response.status);
                return response.text();
            })
            .then(text => {
                if (!text || text.trim() === "") {
                    addToConsole("' . $this->l('ERROR: Empty server response') . '");
                    return;
                }

                addToConsole("Response: " + text.substring(0, 300));

                try {
                    var data = JSON.parse(text);

                    if (data.success) {
                        addToConsole("' . $this->l('Proxy ON applied') . '");
                        if (data.message) addToConsole(data.message);
                        if (data.log && data.log.length) {
                            data.log.forEach(line => addToConsole(line));
                        }
                        if (data.html) {
                            var container = document.getElementById("dns-table-container");
                            if (container) {
                                container.innerHTML = data.html;
                            }
                        }
                    } else {
                        addToConsole("' . $this->l('Error') . ': " + (data.message || "' . $this->l('Unknown error') . '"));
                    }
                } catch (e) {
                    addToConsole("' . $this->l('ERROR parsing JSON') . ': " + e.message);
                }
            })
            .catch(error => {
                addToConsole("' . $this->l('Error') . ': " + error);
            });
        }
        </script>';
    }

    public function getSettings()
    {
        $settings = [];
        $defaults = $this->getDefaultSettings();

        foreach ($defaults as $key => $default) {
            $value = Configuration::get('CFB_' . strtoupper($key));
            if ($value === false) {
                $value = $default;
            }
            $settings[$key] = $value;
        }

        return $settings;
    }

    public function saveSettings($settings)
    {
        foreach ($settings as $key => $value) {
            Configuration::updateValue('CFB_' . strtoupper($key), is_array($value) ? json_encode($value) : $value);
        }
    }

    private function getDefaultSettings($force_new_token = false)
    {
        return [
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
            'cron_secret' => $force_new_token ? $this->generateCronSecret() : '',
            'bypass_active' => 0,
            'bypass_blocked_ips' => '[]',
            'bypass_check_cooldown' => 60,
            'bypass_last_change' => 0,
        ];
    }

    private function generateCronSecret()
    {
        return bin2hex(random_bytes(16));
    }

    private function getSiteDomain()
    {
        return Tools::getShopDomain();
    }

    private function clearLogs()
    {
        if (file_exists($this->log_file_path)) {
            unlink($this->log_file_path);
        }
    }

    public function logEvent($type, $message, $context = [])
    {
        $settings = $this->getSettings();
        if (!$settings['logging_enabled']) {
            return;
        }

        $log_dir = dirname($this->log_file_path);
        if (!file_exists($log_dir)) {
            mkdir($log_dir, 0755, true);
        }

        $entry = [
            'time' => date('Y-m-d H:i:s'),
            'type' => $type,
            'message' => $message,
        ];

        if (!empty($context)) {
            $entry['context'] = $context;
        }

        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        file_put_contents($this->log_file_path, $line, FILE_APPEND | LOCK_EX);
    }

    public function quickSettingsTest($settings, &$trace)
    {
        if (empty($settings['cloudflare_api_key'])) {
            $trace[] = 'Missing API Key/Token.';

            return false;
        }
        if (empty($settings['cloudflare_zone_id'])) {
            $trace[] = 'Missing Zone ID.';

            return false;
        }
        if ($settings['auth_type'] === 'global' && empty($settings['cloudflare_email'])) {
            $trace[] = 'Missing email for Global API Key.';

            return false;
        }

        $headers = $this->getApiHeaders($settings);

        if ($settings['auth_type'] === 'token') {
            $url = 'https://api.cloudflare.com/client/v4/user/tokens/verify';
        } else {
            $url = 'https://api.cloudflare.com/client/v4/user';
        }

        $response = $this->makeHttpRequest($url, 'GET', $headers);
        if (!$response || !$response['success']) {
            $trace[] = 'Authentication error: ' . ($response['error'] ?? 'unknown');

            return false;
        }

        $url = 'https://api.cloudflare.com/client/v4/zones/' . $settings['cloudflare_zone_id'];
        $response = $this->makeHttpRequest($url, 'GET', $headers);
        if (!$response || !$response['success']) {
            $trace[] = 'Error accessing zone: ' . ($response['error'] ?? 'unknown');

            return false;
        }

        $trace[] = 'Connection OK and DNS read available.';

        return true;
    }

    private function getApiHeaders($settings)
    {
        $headers = ['Content-Type: application/json'];

        if ($settings['auth_type'] === 'global') {
            $headers[] = 'X-Auth-Email: ' . $settings['cloudflare_email'];
            $headers[] = 'X-Auth-Key: ' . $settings['cloudflare_api_key'];
        } else {
            $headers[] = 'Authorization: Bearer ' . $settings['cloudflare_api_key'];
        }

        return $headers;
    }

    private function makeHttpRequest($url, $method = 'GET', $headers = [], $data = null)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($method === 'POST' || $method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            }
        }

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'error' => 'HTTP request failed'];
        }

        $json = json_decode($response, true);
        if (!$json) {
            return ['success' => false, 'error' => 'Invalid JSON response'];
        }

        return [
            'success' => $http_code === 200 && !empty($json['success']),
            'data' => $json,
            'error' => !empty($json['errors'][0]['message']) ? $json['errors'][0]['message'] : null,
        ];
    }

    public function computeStatusesFromJson()
    {
        $domain = $this->getSiteDomain();
        $data = $this->fetchStatusJson();

        if ($data === null) {
            return ['general' => 'NO', 'domain' => 'NO', 'domain_ips' => [], 'last_update' => ''];
        }

        $map = $data['ip_map'] ?? [];
        $general_blocked = false;

        foreach ($map as $ip => $blocked) {
            if ($blocked === true) {
                $general_blocked = true;
                break;
            }
        }

        $resolved_ips = $this->resolveDomainIps($domain);
        $domain_blocked = false;

        foreach ($resolved_ips as $ip) {
            if (isset($map[$ip]) && $map[$ip] === true) {
                $domain_blocked = true;
                break;
            }
        }

        return [
            'general' => $general_blocked ? 'YES' : 'NO',
            'domain' => $domain_blocked ? 'YES' : 'NO',
            'domain_ips' => $resolved_ips,
            'last_update' => $data['last_update'] ?? '',
        ];
    }

    private function fetchStatusJson()
    {
        $url = 'https://hayahora.futbol/estado/data.json';
        $response = $this->makeHttpRequest($url);

        if (!$response || !$response['success']) {
            return null;
        }

        $json = $response['data'];
        $last_update_str = !empty($json['lastUpdate']) ? $json['lastUpdate'] : '';
        $map = $this->extractIpBlockMap($json);

        return [
            'ip_map' => $map,
            'last_update' => $last_update_str,
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
                    $map[$ip] = (bool)$status['blocked'];
                }
            }
        }

        return $map;
    }

    private function resolveDomainIps($domain)
    {
        $ips = [];

        $a_records = dns_get_record($domain, DNS_A);
        if (is_array($a_records)) {
            foreach ($a_records as $record) {
                if (!empty($record['ip'])) {
                    $ips[] = $record['ip'];
                }
            }
        }

        $aaaa_records = dns_get_record($domain, DNS_AAAA);
        if (is_array($aaaa_records)) {
            foreach ($aaaa_records as $record) {
                if (!empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return array_unique($ips);
    }

    public function checkFootballAndManageCloudflare()
    {
        $settings = $this->getSettings();
        $calc = $this->computeStatusesFromJson();
        $general = $calc['general'];
        $domain = $calc['domain'];
        $blocked_domain_ips = $calc['domain_ips'] ?? [];

        $stored_blocked = json_decode($settings['bypass_blocked_ips'], true) ?: [];
        $now_ts = time();
        $prev_active = !empty($settings['bypass_active']);
        $last_change = (int)$settings['bypass_last_change'];
        $cooldown_minutes = max(5, min(1440, (int)$settings['bypass_check_cooldown']));
        $cooldown_seconds = $cooldown_minutes * 60;

        $should_disable = ($domain === 'YES');
        $reason = $should_disable ? 'domain_blocked' : 'domain_clear';

        if (!$should_disable && $prev_active) {
            $still_waiting_ips = array_intersect($stored_blocked, $blocked_domain_ips);
            if (!empty($still_waiting_ips)) {
                $should_disable = true;
                $reason = 'waiting_previous_ips';
            } elseif ($last_change && ($now_ts - $last_change) < $cooldown_seconds) {
                $should_disable = true;
                $reason = 'cooldown';
            }
        }

        $desired_proxied = !$should_disable;
        $updated = 0;

        $selected_records = json_decode($settings['selected_records'], true) ?: [];
        if (!empty($selected_records)) {
            $this->refreshDnsCache();
            foreach ($selected_records as $record_id) {
                $result = $this->updateRecordProxyStatus($record_id, $desired_proxied);
                if ($result) {
                    $updated++;
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

        $this->logEvent('cron', 'Auto-check executed', [
            'general' => $settings['last_status_general'],
            'domain' => $settings['last_status_domain'],
            'updated_records' => $updated,
            'bypass_active' => $settings['bypass_active'],
            'reason' => $reason,
        ]);
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
                    'id' => (string)($record['id'] ?? ''),
                    'name' => (string)($record['name'] ?? ''),
                    'type' => $type,
                    'content' => (string)($record['content'] ?? ''),
                    'proxied' => isset($record['proxied']) ? (bool)$record['proxied'] : null,
                    'ttl' => (int)($record['ttl'] ?? 1),
                ];
            }
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
                return false;
            }
        }

        $type = strtoupper($existing['type'] ?? '');
        if (!in_array($type, ['A', 'AAAA', 'CNAME'])) {
            return false;
        }

        if (isset($existing['proxied']) && (bool)$existing['proxied'] === (bool)$proxied_on) {
            return true;
        }

        $headers = $this->getApiHeaders($settings);
        $url = 'https://api.cloudflare.com/client/v4/zones/' . $settings['cloudflare_zone_id'] . '/dns_records/' . $record_id;

        $ttl = (int)($existing['ttl'] ?? 1);
        if ($proxied_on) {
            $ttl = 1;
        }

        $payload = json_encode([
            'type' => $type,
            'name' => $existing['name'] ?? '',
            'content' => $existing['content'] ?? '',
            'ttl' => $ttl,
            'proxied' => (bool)$proxied_on,
        ]);

        $response = $this->makeHttpRequest($url, 'PUT', $headers, $payload);
        if (!$response || !$response['success']) {
            return false;
        }

        foreach ($cache as &$record) {
            if (!empty($record['id']) && $record['id'] === $record_id) {
                $record['proxied'] = (bool)$proxied_on;
                $record['ttl'] = $ttl;
                break;
            }
        }

        $settings['dns_records_cache'] = json_encode($cache);
        $this->saveSettings($settings);

        return true;
    }
}