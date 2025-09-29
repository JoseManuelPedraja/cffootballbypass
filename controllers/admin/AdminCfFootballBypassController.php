<?php

class AdminCfFootballBypassController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        $this->table = 'cfb';
        $this->className = 'CfFootballBypass';
        $this->lang = false;
        $this->addRowAction('view');
        $this->addRowAction('edit');
        $this->addRowAction('delete');
        
        $this->context = Context::getContext();
        
        parent::__construct();
        
        $this->meta_title = $this->l('CF Football Bypass');
        $this->toolbar_title = $this->l('CF Football Bypass - Gestión Cloudflare');
    }

    public function renderView()
    {
        $this->addCSS(_MODULE_DIR_ . 'cffootballbypass/views/css/admin.css');
        $this->addJS(_MODULE_DIR_ . 'cffootballbypass/views/js/admin.js');

        $module = Module::getInstanceByName('cffootballbypass');
        $settings = $this->getSettings();
        
        $this->context->smarty->assign([
            'module_dir' => _MODULE_DIR_ . 'cffootballbypass/',
            'settings' => $settings,
            'domain' => Tools::getShopDomain(),
            'status' => $this->getStatus(),
            'dns_records' => $this->getDnsRecords(),
            'ajax_url' => $this->context->link->getModuleLink('cffootballbypass', 'ajax'),
            'admin_token' => Tools::getAdminTokenLite('AdminCfFootballBypass')
        ]);

        return $this->context->smarty->fetch(_PS_MODULE_DIR_ . 'cffootballbypass/views/templates/admin/operation.tpl');
    }

    public function renderForm()
    {
        $settings = $this->getSettings();

        $this->fields_form = [
            'legend' => [
                'title' => $this->l('Configuración'),
                'icon' => 'icon-cogs'
            ],
            'input' => [
                [
                    'type' => 'select',
                    'label' => $this->l('Tipo de autenticación'),
                    'name' => 'auth_type',
                    'options' => [
                        'query' => [
                            ['id' => 'global', 'name' => 'Global API Key'],
                            ['id' => 'token', 'name' => 'API Token (Bearer)']
                        ],
                        'id' => 'id',
                        'name' => 'name'
                    ],
                    'desc' => $this->l('Global API Key requiere email; API Token no.')
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Email Cloudflare'),
                    'name' => 'cloudflare_email',
                    'desc' => $this->l('Requerido solo para Global API Key')
                ],
                [
                    'type' => 'password',
                    'label' => $this->l('API Key/Token'),
                    'name' => 'cloudflare_api_key',
                    'desc' => $this->l('Tu API Key Global o Token de Cloudflare')
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Zone ID'),
                    'name' => 'cloudflare_zone_id',
                    'desc' => $this->l('ID de la zona en Cloudflare')
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Intervalo de comprobación (minutos)'),
                    'name' => 'check_interval',
                    'class' => 'fixed-width-sm',
                    'desc' => $this->l('Entre 5 y 60 minutos')
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Cooldown tras desactivar (minutos)'),
                    'name' => 'bypass_check_cooldown',
                    'class' => 'fixed-width-sm',
                    'desc' => $this->l('Tiempo de espera después de desactivar Cloudflare (5-1440 min)')
                ],
                [
                    'type' => 'switch',
                    'label' => $this->l('Registro de acciones'),
                    'name' => 'logging_enabled',
                    'values' => [
                        ['id' => 'active_on', 'value' => 1, 'label' => $this->l('Sí')],
                        ['id' => 'active_off', 'value' => 0, 'label' => $this->l('No')]
                    ]
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Retención de logs (días)'),
                    'name' => 'log_retention_days',
                    'class' => 'fixed-width-sm'
                ]
            ],
            'submit' => [
                'title' => $this->l('Guardar'),
                'class' => 'btn btn-default pull-right'
            ]
        ];

        $this->fields_value = [
            'auth_type' => $settings['auth_type'],
            'cloudflare_email' => $settings['cloudflare_email'],
            'cloudflare_api_key' => $settings['cloudflare_api_key'],
            'cloudflare_zone_id' => $settings['cloudflare_zone_id'],
            'check_interval' => $settings['check_interval'],
            'bypass_check_cooldown' => $settings['bypass_check_cooldown'],
            'logging_enabled' => $settings['logging_enabled'],
            'log_retention_days' => $settings['log_retention_days']
        ];

        return parent::renderForm();
    }

    public function postProcess()
    {
        if (Tools::isSubmit('submitAdd' . $this->table)) {
            $settings = $this->getSettings();
            
            $settings['auth_type'] = Tools::getValue('auth_type', 'global');
            $settings['cloudflare_email'] = Tools::getValue('cloudflare_email');
            $settings['cloudflare_api_key'] = Tools::getValue('cloudflare_api_key');
            $settings['cloudflare_zone_id'] = Tools::getValue('cloudflare_zone_id');
            $settings['check_interval'] = max(5, min(60, (int)Tools::getValue('check_interval', 15)));
            $settings['bypass_check_cooldown'] = max(5, min(1440, (int)Tools::getValue('bypass_check_cooldown', 60)));
            $settings['logging_enabled'] = (int)Tools::getValue('logging_enabled', 1);
            $settings['log_retention_days'] = max(1, (int)Tools::getValue('log_retention_days', 30));

            $this->saveSettings($settings);

            // Test connection
            $module = Module::getInstanceByName('cffootballbypass');
            $trace = [];
            $test_result = $module->quickSettingsTest($settings, $trace);
            
            if ($test_result) {
                $this->confirmations[] = $this->l('Configuración guardada correctamente. Conexión con Cloudflare OK.');
            } else {
                $this->errors[] = $this->l('Configuración guardada pero hay problemas de conexión: ') . implode(' | ', $trace);
            }
        }

        return parent::postProcess();
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
            Configuration::updateValue('CFB_' . strtoupper($key), is_array($value) ? json_encode($value) : $value);
        }
    }

    private function getStatus()
    {
        $module = Module::getInstanceByName('cffootballbypass');
        return $module->computeStatusesFromJson();
    }

    private function getDnsRecords()
    {
        $settings = $this->getSettings();
        $cache = json_decode($settings['dns_records_cache'], true);
        $selected = json_decode($settings['selected_records'], true);
        
        return [
            'cache' => $cache ?: [],
            'selected' => $selected ?: []
        ];
    }

    public function initContent()
    {
        $this->show_page_header_toolbar = true;
        $this->page_header_toolbar_title = $this->l('CF Football Bypass');
        $this->page_header_toolbar_btn['new'] = [
            'href' => self::$currentIndex . '&add' . $this->table . '&token=' . $this->token,
            'desc' => $this->l('Configurar'),
            'icon' => 'process-icon-new'
        ];

        parent::initContent();
    }

    public function renderList()
    {
        // Instead of a list, show the operation panel
        return $this->renderView();
    }
}