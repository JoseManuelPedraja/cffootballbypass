/**
 * CF Football Bypass Admin JavaScript
 * Location: views/js/admin.js
 */

(function() {
    'use strict';
    
    var CFB = {
        consolePre: null,
        warnDiv: null,
        adminToken: '',
        
        init: function() {
            this.consolePre = document.getElementById('cfb-console-pre');
            this.warnDiv = document.getElementById('cfb-warn');
            this.adminToken = window.cfbAdminToken || '';
            
            this.bindEvents();
            this.println('Sistema CF Football Bypass listo.');
        },
        
        bindEvents: function() {
            var self = this;
            
            // Test connection button
            var testBtn = document.getElementById('cfb-test');
            if (testBtn) {
                testBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    self.testConnection();
                });
            }
            
            // Manual check button
            var checkBtn = document.getElementById('cfb-check');
            if (checkBtn) {
                checkBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    self.manualCheck();
                });
            }
            
            // Force OFF button
            var offBtn = document.getElementById('cfb-off');
            if (offBtn) {
                offBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    self.forceDeactivate();
                });
            }
            
            // Force ON button
            var onBtn = document.getElementById('cfb-on');
            if (onBtn) {
                onBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    self.forceActivate();
                });
            }
            
            // Diagnostics button
            var diagBtn = document.getElementById('cfb-diag');
            if (diagBtn) {
                diagBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    self.cronDiagnostics();
                });
            }
            
            // Refresh IPs link
            var refreshIpsBtn = document.getElementById('cfb-refresh-ips');
            if (refreshIpsBtn) {
                refreshIpsBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    self.refreshStatus();
                });
            }
            
            // Handle checkbox changes
            document.addEventListener('change', function(e) {
                if (e.target && e.target.name === 'selected_records[]') {
                    self.updateSelectedRecords();
                }
            });
        },
        
        println: function(message) {
            if (!this.consolePre) return;
            var timestamp = new Date().toLocaleTimeString();
            this.consolePre.textContent += '[' + timestamp + '] ' + message + '\n';
            this.consolePre.scrollTop = this.consolePre.scrollHeight;
        },
        
        clearConsole: function() {
            if (this.consolePre) {
                this.consolePre.textContent = '';
            }
        },
        
        showWait: function(show) {
            if (this.warnDiv) {
                this.warnDiv.style.display = show ? 'block' : 'none';
            }
        },
        
        getSelectedRecords: function() {
            var selected = [];
            var checkboxes = document.querySelectorAll('input[name="selected_records[]"]:checked');
            for (var i = 0; i < checkboxes.length; i++) {
                selected.push(checkboxes[i].value);
            }
            return selected;
        },
        
        makeAjaxCall: function(action, extraData, callback) {
            var self = this;
            
            // URL para controlador ADMIN
            var ajaxUrl = 'index.php?controller=AdminCfFootballBypassAjax&token=' + this.adminToken + '&ajax=1';
            
            var formData = new FormData();
            formData.append('action', action);
            
            var selected = this.getSelectedRecords();
            selected.forEach(function(id) {
                formData.append('selected[]', id);
            });
            
            if (extraData) {
                for (var key in extraData) {
                    if (extraData.hasOwnProperty(key)) {
                        formData.append(key, extraData[key]);
                    }
                }
            }
            
            fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(function(response) {
                return response.text();
            })
            .then(function(text) {
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('Error parsing JSON:', text);
                    return {
                        success: false,
                        message: 'Respuesta no válida del servidor',
                        raw: text.substring(0, 1000)
                    };
                }
            })
            .then(function(response) {
                self.showWait(false);
                
                if (response.log && Array.isArray(response.log)) {
                    response.log.forEach(function(line) {
                        self.println(line);
                    });
                }
                
                if (typeof callback === 'function') {
                    callback(response);
                }
            })
            .catch(function(error) {
                self.showWait(false);
                self.println('Error de red: ' + error.message);
                
                if (typeof callback === 'function') {
                    callback({
                        success: false,
                        message: 'Error de red: ' + error.message
                    });
                }
            });
        },
        
        testConnection: function() {
            var self = this;
            this.clearConsole();
            this.showWait(true);
            this.println('Probando conexión con Cloudflare...');
            
            this.makeAjaxCall('test_connection', null, function(response) {
                if (response.success) {
                    self.println('Conexión exitosa.');
                    if (response.html) {
                        self.refreshDnsTable(response.html);
                    }
                } else {
                    self.println('Error: ' + (response.message || 'Error desconocido'));
                    if (response.raw) {
                        self.println('Respuesta del servidor: ' + response.raw.substring(0, 500));
                    }
                }
            });
        },
        
        manualCheck: function() {
            var self = this;
            this.clearConsole();
            this.showWait(true);
            this.println('Ejecutando comprobación manual...');
            
            this.makeAjaxCall('manual_check', null, function(response) {
                if (response.success) {
                    self.println('Comprobación completada.');
                    self.println('Última comprobación: ' + (response.last || '—'));
                    self.println('Estado general: ' + (response.general || '—'));
                    self.println('Dominio bloqueado: ' + (response.domain || '—'));
                    self.println('Última actualización: ' + (response.last_update || '—'));
                    self.refreshSummary();
                } else {
                    self.println('Error: ' + (response.message || 'Error desconocido'));
                }
            });
        },
        
        forceActivate: function() {
            var self = this;
            this.clearConsole();
            this.showWait(true);
            this.println('Forzando Proxy ON (CDN)...');
            
            this.makeAjaxCall('force_activate', null, function(response) {
                if (response.success) {
                    self.println('Proxy ON aplicado.');
                    if (response.message) {
                        self.println(response.message);
                    }
                    if (response.report) {
                        self.println(response.report);
                    }
                    if (response.html) {
                        self.refreshDnsTable(response.html);
                    }
                    self.refreshSummary();
                } else {
                    self.println('Error: ' + (response.message || 'Error desconocido'));
                }
            });
        },
        
        forceDeactivate: function() {
            var self = this;
            this.clearConsole();
            this.showWait(true);
            this.println('Forzando Proxy OFF (DNS Only)...');
            
            this.makeAjaxCall('force_deactivate', null, function(response) {
                if (response.success) {
                    self.println('Proxy OFF aplicado.');
                    if (response.message) {
                        self.println(response.message);
                    }
                    if (response.report) {
                        self.println(response.report);
                    }
                    if (response.html) {
                        self.refreshDnsTable(response.html);
                    }
                    self.refreshSummary();
                } else {
                    self.println('Error: ' + (response.message || 'Error desconocido'));
                }
            });
        },
        
        cronDiagnostics: function() {
            var self = this;
            this.clearConsole();
            this.showWait(true);
            this.println('Ejecutando diagnóstico del sistema...');
            
            this.makeAjaxCall('cron_diagnostics', null, function(response) {
                if (response.success && response.msg) {
                    self.println('Diagnóstico completado:');
                    self.println(response.msg);
                } else {
                    self.println('Error: ' + (response.message || 'Error desconocido'));
                }
            });
        },
        
        refreshStatus: function() {
            var self = this;
            this.clearConsole();
            this.showWait(true);
            this.println('Actualizando estado...');
            
            this.makeAjaxCall('get_status', null, function(response) {
                if (response.success) {
                    self.println('Estado actualizado.');
                    self.updateSummaryElements(response);
                } else {
                    self.println('Error: ' + (response.message || 'Error desconocido'));
                }
            });
        },
        
        updateSelectedRecords: function() {
            var selected = this.getSelectedRecords();
            this.makeAjaxCall('update_selected_records', {
                'selected': JSON.stringify(selected)
            }, null);
        },
        
        refreshDnsTable: function(html) {
            var dnsListDiv = document.getElementById('cfb-dns-list');
            if (dnsListDiv && html) {
                dnsListDiv.innerHTML = html;
            }
        },
        
        refreshSummary: function() {
            this.refreshStatus();
        },
        
        updateSummaryElements: function(data) {
            // Update general status
            var generalSpan = document.getElementById('cfb-summary-general');
            if (generalSpan && data.general) {
                var generalText = (data.general === 'SÍ') ? 'SI' : 'NO';
                var generalClass = (data.general === 'SÍ') ? 'badge-danger' : 'badge-success';
                generalSpan.textContent = generalText;
                generalSpan.className = 'badge ' + generalClass;
            }
            
            // Update domain status
            var domainSpan = document.getElementById('cfb-summary-domain');
            if (domainSpan && data.domain) {
                var domainText = (data.domain === 'SÍ') ? 'SI' : 'NO';
                var domainClass = (data.domain === 'SÍ') ? 'badge-danger' : 'badge-success';
                domainSpan.textContent = domainText;
                domainSpan.className = 'badge ' + domainClass;
            }
            
            // Update IPs
            var ipsSpan = document.getElementById('cfb-summary-ips');
            if (ipsSpan && data.ips) {
                ipsSpan.textContent = (data.ips && data.ips.length) ? data.ips.join(', ') : '—';
            }
            
            // Update last update
            var lastUpdateSpan = document.getElementById('cfb-summary-lastupdate');
            if (lastUpdateSpan && data.last_update !== undefined) {
                lastUpdateSpan.textContent = data.last_update || '—';
            }
        }
    };
    
    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            CFB.init();
        });
    } else {
        CFB.init();
    }
    
    // Export to global scope for debugging
    window.CFB = CFB;
    
})();