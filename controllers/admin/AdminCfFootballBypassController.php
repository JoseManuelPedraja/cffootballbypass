<?php
/**
 * AJAX Admin Controller for CF Football Bypass
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminCfFootballBypassAjaxController extends ModuleAdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->ajax = true;
    }

    public function displayAjax()
    {
        header('Content-Type: application/json');
        
        $action = Tools::getValue('action');
        
        switch ($action) {
            case 'test_connection':
                $this->ajaxProcessTestConnection();
                break;
            case 'manual_check':
                $this->ajaxProcessManualCheck();
                break;
            case 'get_status':
                $this->ajaxProcessGetStatus();
                break;
            case 'force_activate':
                $this->ajaxProcessForceActivate();
                break;
            case 'force_deactivate':
                $this->ajaxProcessForceDeactivate();
                break;
            case 'cron_diagnostics':
                $this->ajaxProcessCronDiagnostics();
                break;
            case 'update_selected_records':
                $this->ajaxProcessUpdateSelectedRecords();
                break;
            default:
                $this->ajaxDie(json_encode([
                    'success' => false,
                    'message' => 'Acción no válida: ' . $action
                ]));
        }
    }

    public function ajaxProcessTestConnection()
    {
        $log = [];
        $module = Module::getInstanceByName('cffootballbypass');
        
        if (!$module) {
            $this->ajaxDie(json_encode([
                'success' => false,
                'message' => 'Módulo no encontrado',
                'log' => []
            ]));
        }
        
        $settings = $this->getModuleSettings();

        try {
            $log[] = 'Iniciando test de conexión...';
            $log[] = 'Auth: ' . ($settings['auth_type'] === 'token' ? 'Token' : 'Global');
            $log[] = 'Zone ID: ' . $this->maskString($settings['cloudflare_zone_id']);

            $trace = [];
            $test_result = $module->quickSettingsTest($settings, $trace);
            $log = array_merge($log, $trace);
            
            if ($test_result) {
                $log[] = 'Cargando registros DNS...';
                $records = $module->fetchDnsRecords(['A', 'AAAA', 'CNAME']);
                
                if (!empty($records)) {
                    $settings['dns_records_cache'] = json_encode($records);
                    $settings['dns_cache_last_sync'] = date('Y-m-d H:i:s');
                    $this->saveModuleSettings($settings);
                    
                    $log[] = 'Registros DNS cargados: ' . count($records);
                    
                    $selected = json_decode($settings['selected_records'], true) ?: [];
                    $html = $this->renderDnsTable($records, $selected);
                    
                    $this->ajaxDie(json_encode([
                        'success' => true,
                        'log' => $log,
                        'html' => $html
                    ]));
                } else {
                    $log[] = 'No se pudieron cargar registros DNS';
                    $this->ajaxDie(json_encode([
                        'success' => false,
                        'message' => 'No se pudieron cargar registros DNS',
                        'log' => $log
                    ]));
                }
            } else {
                $this->ajaxDie(json_encode([
                    'success' => false,
                    'message' => 'Error de conexión',
                    'log' => $log
                ]));
            }
        } catch (Exception $e) {
            $log[] = 'Error: ' . $e->getMessage();
            $this->ajaxDie(json_encode([
                'success' => false,
                'message' => $e->getMessage(),
                'log' => $log
            ]));
        }
    }

    public function ajaxProcessManualCheck()
    {
        $log = [];
        $module = Module::getInstanceByName('cffootballbypass');

        try {
            $log[] = 'Ejecutando comprobación manual...';
            
            $this->persistSelectedFromAjax();
            $module->checkFootballAndManageCloudflare();
            $settings = $this->getModuleSettings();
            
            $log[] = 'Comprobación completada';
            
            $this->ajaxDie(json_encode([
                'success' => true,
                'log' => $log,
                'last' => $settings['last_check'],
                'general' => $settings['last_status_general'],
                'domain' => $settings['last_status_domain'],
                'last_update' => $settings['last_update']
            ]));
        } catch (Exception $e) {
            $log[] = 'Error: ' . $e->getMessage();
            $this->ajaxDie(json_encode([
                'success' => false,
                'message' => $e->getMessage(),
                'log' => $log
            ]));
        }
    }

    public function ajaxProcessGetStatus()
    {
        try {
            $module = Module::getInstanceByName('cffootballbypass');
            $calc = $module->computeStatusesFromJson();
            
            $this->ajaxDie(json_encode([
                'success' => true,
                'general' => $calc['general'],
                'domain' => $calc['domain'],
                'ips' => $calc['domain_ips'] ?? [],
                'last_update' => $calc['last_update']
            ]));
        } catch (Exception $e) {
            $this->ajaxDie(json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]));
        }
    }

    public function ajaxProcessForceActivate()
    {
        $this->processForceProxy(true, 'Forzar Proxy ON');
    }

    public function ajaxProcessForceDeactivate()
    {
        $this->processForceProxy(false, 'Forzar Proxy OFF');
    }

    private function processForceProxy($proxied_on, $operation_name)
    {
        $log = [];
        $module = Module::getInstanceByName('cffootballbypass');

        try {
            $log[] = $operation_name . ': iniciando...';
            
            $selected = $this->persistSelectedFromAjax();
            if (empty($selected)) {
                $this->ajaxDie(json_encode([
                    'success' => false,
                    'message' => 'No hay registros seleccionados',
                    'log' => $log
                ]));
            }

            $settings = $this->getModuleSettings();
            $records = $module->fetchDnsRecords(['A', 'AAAA', 'CNAME']);
            
            if (!empty($records)) {
                $settings['dns_records_cache'] = json_encode($records);
                $settings['dns_cache_last_sync'] = date('Y-m-d H:i:s');
                $this->saveModuleSettings($settings);
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

            $settings = $this->getModuleSettings();
            $updated_records = json_decode($settings['dns_records_cache'], true) ?: [];
            $html = $this->renderDnsTable($updated_records, $selected);

            $message = ($proxied_on ? 'Proxy ON' : 'Proxy OFF') . " en $ok registros" . ($fail ? "; fallidos: $fail" : "") . ".";
            
            $this->ajaxDie(json_encode([
                'success' => true,
                'message' => $message,
                'report' => implode("\n", $lines),
                'html' => $html,
                'log' => array_merge($log, $lines, [$message])
            ]));
        } catch (Exception $e) {
            $log[] = 'Error: ' . $e->getMessage();
            $this->ajaxDie(json_encode([
                'success' => false,
                'message' => $e->getMessage(),
                'log' => $log
            ]));
        }
    }

    public function ajaxProcessCronDiagnostics()
    {
        try {
            $settings = $this->getModuleSettings();
            $mins = max(5, min(60, (int)$settings['check_interval']));
            
            $msg = "Intervalo configurado: {$mins} min\n";
            $msg .= "Última comprobación: " . ($settings['last_check'] ?: '—') . "\n";
            $msg .= "General (bloqueos IPs): " . ($settings['last_status_general'] ?: '—') . "\n";
            $msg .= "Dominio bloqueado: " . ($settings['last_status_domain'] ?: '—') . "\n";
            $msg .= "Última actualización (JSON de IPs): " . ($settings['last_update'] ?: '—') . "\n";
            $msg .= "Registros sincronizados: " . ($settings['dns_cache_last_sync'] ?: '—');
            
            $this->ajaxDie(json_encode([
                'success' => true,
                'msg' => $msg
            ]));
        } catch (Exception $e) {
            $this->ajaxDie(json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]));
        }
    }

    public function ajaxProcessUpdateSelectedRecords()
    {
        try {
            $selected = $this->persistSelectedFromAjax();
            $this->ajaxDie(json_encode([
                'success' => true,
                'selected' => $selected,
                'count' => count($selected)
            ]));
        } catch (Exception $e) {
            $this->ajaxDie(json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]));
        }
    }

    private function persistSelectedFromAjax()
    {
        $settings = $this->getModuleSettings();
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
            $this->saveModuleSettings($settings);
        }

        return $selected;
    }

    private function renderDnsTable($records, $selected)
    {
        $html = '<table class="table table-striped">';
        $html .= '<thead><tr>';
        $html .= '<th style="width:40px;"></th>';
        $html .= '<th>Nombre</th>';
        $html .= '<th>Tipo</th>';
        $html .= '<th>Contenido</th>';
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
            $html .= '<td>' . htmlspecialchars($ttl, ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        return $html;
    }

    private function getModuleSettings()
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

    private function saveModuleSettings($settings)
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
        if (empty($str)) return '—';
        $len = strlen($str);
        if ($len <= $left + $right) {
            return str_repeat('*', max(0, $len));
        }
        return substr($str, 0, $left) . str_repeat('*', $len - $left - $right) . substr($str, -$right);
    }
}
3. ACTUALIZA el archivo JavaScript views/js/admin.js
Cambia la URL de AJAX (busca la función makeAjaxCall y modifica la URL):
