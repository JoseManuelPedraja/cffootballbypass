<?php
/**
 * AJAX Controller for CF Football Bypass
 * Location: modules/cffootballbypass/controllers/front/ajax.php
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class CfFootballBypassAjaxModuleFrontController extends ModuleFrontController
{
    public function init()
    {
        parent::init();
        $this->ajax = true;
    }

    public function initContent()
    {
        // No renderizar contenido para AJAX
    }

    public function displayAjax()
    {
        header('Content-Type: application/json');
        
        // Verificar autenticación del empleado - VERSIÓN CORREGIDA
        if (!$this->isValidEmployee()) {
            die(json_encode([
                'success' => false,
                'message' => 'Acceso denegado - Debe estar autenticado como administrador'
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
                    'message' => 'Acción no válida: ' . $action
                ]));
        }
    }

    private function isValidEmployee()
    {
        // Método corregido para PrestaShop 8.x
        $context = Context::getContext();
        
        // Verificar si existe el empleado en el contexto
        if (!isset($context->employee) || !$context->employee) {
            return false;
        }
        
        // Verificar si tiene ID válido
        if (!isset($context->employee->id) || !$context->employee->id) {
            return false;
        }
        
        // Verificar si el objeto está cargado
        if (!Validate::isLoadedObject($context->employee)) {
            return false;
        }
        
        // Verificar cookie de empleado (más fiable en AJAX)
        if (isset($context->cookie->id_employee) && $context->cookie->id_employee) {
            if ((int)$context->cookie->id_employee === (int)$context->employee->id) {
                return true;
            }
        }
        
        return false;
    }

    private function testConnection()
    {
        $log = [];
        $module = Module::getInstanceByName('cffootballbypass');
        
        if (!$module) {
            die(json_encode([
                'success' => false,
                'message' => 'Módulo no encontrado',
                'log' => ['Error: Módulo cffootballbypass no cargado']
            ]));
        }
        
        $settings = $this->getSettings();

        try {
            $log[] = 'Iniciando test de conexión...';
            $log[] = 'Auth: ' . ($settings['auth_type'] === 'token' ? 'Token' : 'Global');
            $log[] = 'Zone ID: ' . $this->maskString($settings['cloudflare_zone_id']);

            // Test authentication
            $trace = [];
            
            // Intentar usar método del módulo principal
            if (method_exists($module, 'quickSettingsTest')) {
                $test_result = $module->quickSettingsTest($settings, $trace);
            } else {
                $test_result = $this->quickSettingsTestLocal($settings, $trace);
            }
            
            $log = array_merge($log, $trace);
            
            if ($test_result) {
                $log[] = 'Cargando registros DNS...';
                
                // Intentar usar método del módulo principal
                if (method_exists($module, 'fetchDnsRecords')) {
                    $records = $module->fetchDnsRecords(['A', 'AAAA', 'CNAME']);
                } else {
                    $records = $this->fetchDnsRecordsLocal($settings, ['A', 'AAAA', 'CNAME'], $log);
                }
                
                if (!empty($records)) {
                    $settings['dns_records_cache'] = json_encode($records);
                    $settings['dns_cache_last_sync'] = date('Y-m-d H:i:s');
                    $this->saveSettings($settings);
                    
                    $log[] = 'Registros DNS cargados: ' . count($records);
                    
                    $selected = json_decode($settings['selected_records'], true) ?: [];
                    $html = $this->renderDnsTable($records, $selected);
                    
                    die(json_encode([
                        'success' => true,
                        'log' => $log,
                        'html' => $html
                    ]));
                } else {
                    $log[] = 'No se pudieron cargar registros DNS';
                    die(json_encode([
                        'success' => false,
                        'message' => 'No se pudieron cargar registros DNS',
                        'log' => $log
                    ]));
                }
            } else {
                die(json_encode([
                    'success' => false,
                    'message' => 'Error de conexión con Cloudflare',
                    'log' => $log
                ]));
            }
        } catch (Exception $e) {
            $log[] = 'Excepción: ' . $e->getMessage();
            die(json_encode([
                'success' => false,
                'message' => $e->getMessage(),
                'log' => $log
            ]));
        }
    }

    private function manualCheck()
    {
        $log = [];
        $module = Module::getInstanceByName('cffootballbypass');

        if (!$module) {
            die(json_encode([
                'success' => false,
                'message' => 'Módulo no encontrado',
                'log' => []
            ]));
        }

        try {
            $log[] = 'Ejecutando comprobación manual...';
            
            // Save selected records first
            $this->persistSelectedFromAjax();
            
            // Run the check
            if (method_exists($module, 'checkFootballAndManageCloudflare')) {
                $module->checkFootballAndManageCloudflare();
            }
            
            $settings = $this->getSettings();
            
            $log[] = 'Comprobación completada';
            
            die(json_encode([
                'success' => true,
                'log' => $log,
                'last' => $settings['last_check'],
                'general' => $settings['last_status_general'],
                'domain' => $settings['last_status_domain'],
                'last_update' => $settings['last_update']
            ]));
        } catch (Exception $e) {
            $log[] = 'Error: ' . $e->getMessage();
            die(json_encode([
                'success' => false,
                'message' => $e->getMessage(),
                'log' => $log
            ]));
        }
    }

    private function getStatus()
    {
        try {
            $module = Module::getInstanceByName('cffootballbypass');
            
            if (!$module || !method_exists($module, 'computeStatusesFromJson')) {
                throw new Exception('Método no disponible');
            }
            
            $calc = $module->computeStatusesFromJson();
            
            die(json_encode([
                'success' => true,
                'general' => $calc['general'],
                'domain' => $calc['domain'],
                'ips' => $calc['domain_ips'] ?? [],
                'last_update' => $calc['last_update']
            ]));
        } catch (Exception $e) {
            die(json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]));
        }
    }

    private function forceActivate()
    {
        $this->forceProxyStatus(true, 'Forzar Proxy ON');
    }

    private function forceDeactivate()
    {
        $this->forceProxyStatus(false, 'Forzar Proxy OFF');
    }

    private function forceProxyStatus($proxied_on, $operation_name)
    {
        $log = [];
        $module = Module::getInstanceByName('cffootballbypass');

        if (!$module) {
            die(json_encode([
                'success' => false,
                'message' => 'Módulo no encontrado',
                'log' => []
            ]));
        }

        try {
            $log[] = $operation_name . ': iniciando...';
            
            $selected = $this->persistSelectedFromAjax();
            if (empty($selected)) {
                die(json_encode([
                    'success' => false,
                    'message' => 'No hay registros seleccionados',
                    'log' => $log
                ]));
            }

            $settings = $this->getSettings();
            
            // Refresh DNS cache
            if (method_exists($module, 'fetchDnsRecords')) {
                $records = $module->fetchDnsRecords(['A', 'AAAA', 'CNAME']);
            } else {
                $records = $this->fetchDnsRecordsLocal($settings, ['A', 'AAAA', 'CNAME'], $log);
            }
            
            if (!empty($records)) {
                $settings['dns_records_cache'] = json_encode($records);
                $settings['dns_cache_last_sync'] = date('Y-m-d H:i:s');
                $this->saveSettings($settings);
            }

            $ok = 0;
            $fail = 0;
            $lines = [];

            foreach ($selected as $record_id) {
                if (method_exists($module, 'updateRecordProxyStatus')) {
                    $result = $module->updateRecordProxyStatus($record_id, $proxied_on);
                } else {
                    $result = false;
                }
                
                if ($result) {
                    $ok++;
                    $lines[] = 'OK: ' . $record_id . ' -> ' . ($proxied_on ? 'ON' : 'OFF');
                } else {
                    $fail++;
                    $lines[] = 'ERROR: ' . $record_id;
                }
            }

            // Get updated records for HTML
            $settings = $this->getSettings();
            $updated_records = json_decode($settings['dns_records_cache'], true) ?: [];
            $html = $this->renderDnsTable($updated_records, $selected);

            $message = ($proxied_on ? 'Proxy ON' : 'Proxy OFF') . " en $ok registros" . ($fail ? "; fallidos: $fail" : "") . ".";
            
            die(json_encode([
                'success' => true,
                'message' => $message,
                'report' => implode("\n", $lines),
                'html' => $html,
                'log' => array_merge($log, $lines, [$message])
            ]));
        } catch (Exception $e) {
            $log[] = 'Error: ' . $e->getMessage();
            die(json_encode([
                'success' => false,
                'message' => $e->getMessage(),
                'log' => $log
            ]));
        }
    }

    private function cronDiagnostics()
    {
        try {
            $settings = $this->getSettings();
            $mins = max(5, min(60, (int)$settings['check_interval']));
            
            $msg = "Intervalo configurado: {$mins} min\n";
            $msg .= "Última comprobación: " . ($settings['last_check'] ?: '—') . "\n";
            $msg .= "General (bloqueos IPs): " . ($settings['last_status_general'] ?: '—') . "\n";
            $msg .= "Dominio bloqueado: " . ($settings['last_status_domain'] ?: '—') . "\n";
            $msg .= "Última actualización (JSON de IPs): " . ($settings['last_update'] ?: '—') . "\n";
            $msg .= "Registros sincronizados: " . ($settings['dns_cache_last_sync'] ?: '—');
            
            die(json_encode([
                'success' => true,
                'msg' => $msg
            ]));
        } catch (Exception $e) {
            die(json_encode([
                'success' => false,
                'message' => $e->getMessage()
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
                'count' => count($selected)
            ]));
        } catch (Exception $e) {
            die(json_encode([
                'success' => false,
                'message' => $e->getMessage()
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

        // Clean and validate
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

    // Helper methods
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
        if (empty($str)) return '—';
        $len = strlen($str);
        if ($len <= $left + $right) {
            return str_repeat('*', max(0, $len));
        }
        return substr($str, 0, $left) . str_repeat('*', $len - $left - $right) . substr($str, -$right);
    }

    // Métodos de fallback si el módulo principal no tiene los métodos
    private function quickSettingsTestLocal($settings, &$trace)
    {
        // Implementación básica de test
        $module = Module::getInstanceByName('cffootballbypass');
        if ($module && method_exists($module, 'quickSettingsTest')) {
            return $module->quickSettingsTest($settings, $trace);
        }
        
        $trace[] = 'Advertencia: método quickSettingsTest no disponible';
        return false;
    }

    private function fetchDnsRecordsLocal($settings, $types, &$log)
    {
        // Implementación básica de fetchDnsRecords
        $module = Module::getInstanceByName('cffootballbypass');
        if ($module && method_exists($module, 'fetchDnsRecords')) {
            return $module->fetchDnsRecords($types);
        }
        
        $log[] = 'Advertencia: método fetchDnsRecords no disponible';
        return [];
    }
}