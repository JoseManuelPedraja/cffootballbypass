/**
 * CF Football Bypass v2.0.0 — Admin JavaScript
 *
 * @author    Jose Manuel Pedraja
 * @copyright 2007-2026 PrestaShop SA
 * @license   https://opensource.org/licenses/AFL-3.0
 */
(function () {
    'use strict';

    var CFB = {
        consolePre: null,
        warnDiv: null,
        simpleDiv: null,
        simpleWrap: null,
        ajaxUrl: '',
        ajaxToken: '',
        isAdvanced: false,
        dirty: false,

        init: function () {
            var op = document.getElementById('cfb-operation');
            if (!op) return;

            this.ajaxUrl = op.getAttribute('data-ajax-url') || '';
            this.ajaxToken = op.getAttribute('data-ajax-token') || '';
            this.isAdvanced = op.getAttribute('data-advanced') === '1';

            this.consolePre = document.getElementById('cfb-console-pre');
            this.warnDiv = document.getElementById('cfb-warn');
            this.simpleDiv = document.getElementById('cfb-console-simple');
            this.simpleWrap = document.getElementById('cfb-console-simple-wrap');

            this.bindEvents();
            this.updateCounts();
            if (this.isAdvanced) {
                this.println('CF Football Bypass ready.');
            }
        },

        bindEvents: function () {
            var self = this;

            this.bindBtn('cfb-test', function () { self.testConnection(); });
            this.bindBtn('cfb-check', function () { self.manualCheck(); });
            this.bindBtn('cfb-off', function () { self.forceDeactivate(); });
            this.bindBtn('cfb-on', function () { self.forceActivate(); });
            this.bindBtn('cfb-save-selection', function () { self.saveSelection(); });
            this.bindBtn('cfb-diag', function () { self.cronDiagnostics(); });
            this.bindBtn('cfb-refresh-diag', function () { self.feedDiagnostics(); });
            this.bindBtn('cfb-clear-cache', function () { self.clearCache(); });
            this.bindBtn('cfb-refresh-ips', function (e) { e.preventDefault(); self.refreshStatus(); });

            // Filter buttons
            var filters = document.querySelectorAll('.cfb-filter');
            filters.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    filters.forEach(function (b) { b.classList.remove('active'); });
                    btn.classList.add('active');
                    self.filterRows(btn.getAttribute('data-filter'));
                });
            });

            // Quick select buttons
            document.querySelectorAll('[data-select]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    self.quickSelect(btn.getAttribute('data-select'));
                });
            });

            // Check-all header checkbox
            var checkAll = document.getElementById('cfb-check-all');
            if (checkAll) {
                checkAll.addEventListener('change', function () {
                    var rows = document.querySelectorAll('#cfb-dns-table tbody tr:not(.cfb-hidden)');
                    rows.forEach(function (row) {
                        var cb = row.querySelector('.cfb-record-cb');
                        if (cb) cb.checked = checkAll.checked;
                    });
                    self.markDirty();
                    self.updateCounts();
                });
            }

            // Individual checkbox changes
            document.addEventListener('change', function (e) {
                if (e.target && e.target.classList.contains('cfb-record-cb')) {
                    self.markDirty();
                    self.updateCounts();
                }
            });
        },

        bindBtn: function (id, handler) {
            var btn = document.getElementById(id);
            if (btn) {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    handler(e);
                });
            }
        },

        /* --- UI helpers --- */

        println: function (msg) {
            if (!this.consolePre) return;
            var ts = new Date().toLocaleTimeString();
            this.consolePre.textContent += '[' + ts + '] ' + msg + '\n';
            this.consolePre.scrollTop = this.consolePre.scrollHeight;
        },

        clearConsole: function () {
            if (this.consolePre) this.consolePre.textContent = '';
        },

        showWait: function (on) {
            if (this.warnDiv) this.warnDiv.style.display = on ? 'block' : 'none';
            if (this.simpleWrap) this.simpleWrap.style.display = on ? 'block' : 'none';
        },

        showSimple: function (msg) {
            if (this.simpleDiv) {
                this.simpleDiv.textContent = msg;
            }
            if (this.simpleWrap) this.simpleWrap.style.display = 'block';
        },

        filterRows: function (type) {
            var rows = document.querySelectorAll('#cfb-dns-table tbody tr');
            rows.forEach(function (row) {
                if (type === 'all' || row.getAttribute('data-type') === type) {
                    row.classList.remove('cfb-hidden');
                } else {
                    row.classList.add('cfb-hidden');
                }
            });
        },

        quickSelect: function (mode) {
            var rows = document.querySelectorAll('#cfb-dns-table tbody tr');
            var types = mode.split(',');
            rows.forEach(function (row) {
                var cb = row.querySelector('.cfb-record-cb');
                if (!cb) return;
                if (mode === 'all') {
                    if (!row.classList.contains('cfb-hidden')) cb.checked = true;
                } else if (mode === 'none') {
                    if (!row.classList.contains('cfb-hidden')) cb.checked = false;
                } else {
                    cb.checked = types.indexOf(row.getAttribute('data-type')) !== -1;
                }
            });
            this.markDirty();
            this.updateCounts();
        },

        getSelectedRecords: function () {
            var selected = [];
            document.querySelectorAll('.cfb-record-cb:checked').forEach(function (cb) {
                selected.push(cb.value);
            });
            return selected;
        },

        updateCounts: function () {
            var total = document.querySelectorAll('.cfb-record-cb').length;
            var sel = document.querySelectorAll('.cfb-record-cb:checked').length;
            var countEl = document.getElementById('cfb-sel-count');
            var totalEl = document.getElementById('cfb-total-count');
            if (countEl) countEl.textContent = sel;
            if (totalEl) totalEl.textContent = total;
        },

        markDirty: function () {
            this.dirty = true;
            var el = document.getElementById('cfb-dirty');
            if (el) el.style.display = 'inline';
        },

        clearDirty: function () {
            this.dirty = false;
            var el = document.getElementById('cfb-dirty');
            if (el) el.style.display = 'none';
        },

        /* --- AJAX --- */

        ajax: function (action, extra, callback) {
            var self = this;
            var formData = new FormData();
            formData.append('action', action);
            formData.append('cfb_token', this.ajaxToken);
            formData.append('selected', JSON.stringify(this.getSelectedRecords()));

            if (extra) {
                for (var k in extra) {
                    if (extra.hasOwnProperty(k)) formData.append(k, extra[k]);
                }
            }

            fetch(this.ajaxUrl, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (r) { return r.text(); })
            .then(function (text) {
                try { return JSON.parse(text); }
                catch (e) { return { success: false, message: 'Invalid server response', raw: text.substring(0, 500) }; }
            })
            .then(function (resp) {
                self.showWait(false);
                if (resp.log && Array.isArray(resp.log)) {
                    resp.log.forEach(function (line) { self.println(line); });
                }
                if (typeof callback === 'function') callback(resp);
            })
            .catch(function (err) {
                self.showWait(false);
                self.println('Network error: ' + err.message);
                if (typeof callback === 'function') callback({ success: false, message: err.message });
            });
        },

        /* --- Actions --- */

        testConnection: function () {
            var self = this;
            this.clearConsole();
            this.showWait(true);
            this.println('Testing Cloudflare connection...');

            this.ajax('test_connection', null, function (resp) {
                if (resp.success) {
                    self.println('Connection OK. Records: ' + (resp.count || 0));
                    if (resp.records) self.rebuildDnsTable(resp.records, resp.selected || []);
                    self.showSimple('Connection OK — ' + (resp.count || 0) + ' DNS records loaded.');
                } else {
                    self.println('Error: ' + (resp.message || 'Unknown'));
                    self.showSimple('Error: ' + (resp.message || 'Unknown'));
                }
            });
        },

        manualCheck: function () {
            var self = this;
            this.clearConsole();
            this.showWait(true);
            this.println('Running manual check...');

            this.ajax('manual_check', null, function (resp) {
                if (resp.success) {
                    self.println('Check completed.');
                    self.updateSummary(resp);
                    self.showSimple('Check completed. General: ' + (resp.general || '—') + ', Domain: ' + (resp.domain || '—'));
                } else {
                    self.println('Error: ' + (resp.message || 'Unknown'));
                }
            });
        },

        forceActivate: function () {
            var self = this;
            this.clearConsole();
            this.showWait(true);
            this.println('Forcing Proxy ON...');

            this.ajax('force_activate', null, function (resp) {
                if (resp.success) {
                    self.println(resp.message || 'Done');
                    if (resp.records) self.rebuildDnsTable(resp.records, resp.selected || []);
                    self.refreshStatus();
                    self.showSimple(resp.message || 'Proxy ON applied.');
                } else {
                    self.println('Error: ' + (resp.message || 'Unknown'));
                }
            });
        },

        forceDeactivate: function () {
            var self = this;
            this.clearConsole();
            this.showWait(true);
            this.println('Forcing Proxy OFF...');

            this.ajax('force_deactivate', null, function (resp) {
                if (resp.success) {
                    self.println(resp.message || 'Done');
                    if (resp.records) self.rebuildDnsTable(resp.records, resp.selected || []);
                    self.refreshStatus();
                    self.showSimple(resp.message || 'Proxy OFF applied.');
                } else {
                    self.println('Error: ' + (resp.message || 'Unknown'));
                }
            });
        },

        saveSelection: function () {
            var self = this;
            this.clearConsole();
            this.showWait(true);
            this.println('Saving selection...');

            this.ajax('save_selection', null, function (resp) {
                if (resp.success) {
                    self.println(resp.message || 'Saved');
                    self.clearDirty();
                    self.showSimple(resp.message || 'Selection saved.');
                } else {
                    self.println('Error: ' + (resp.message || 'Unknown'));
                }
            });
        },

        refreshStatus: function () {
            var self = this;
            this.ajax('get_status', null, function (resp) {
                if (resp.success) self.updateSummary(resp);
            });
        },

        cronDiagnostics: function () {
            var self = this;
            this.clearConsole();
            this.showWait(true);
            this.println('Running diagnostics...');

            this.ajax('cron_diagnostics', null, function (resp) {
                if (resp.success && resp.msg) {
                    self.println('Diagnostics:\n' + resp.msg);
                } else {
                    self.println('Error: ' + (resp.message || 'Unknown'));
                }
            });
        },

        feedDiagnostics: function () {
            var self = this;
            this.clearConsole();
            this.showWait(true);
            this.println('Loading feed diagnostics...');

            this.ajax('feed_diagnostics', null, function (resp) {
                if (resp.success && resp.diag) {
                    var d = resp.diag;
                    self.println('Local: ' + (d.local_exists ? d.local_size + ' bytes' : 'not found'));
                    self.println('lastUpdate: ' + (d.local_last_update || '—'));
                    self.println('Blocked IPs: ' + d.blocked_count + '/' + d.total_count);
                    if (d.isps_blocked && d.isps_blocked.length) {
                        self.println('ISPs: ' + d.isps_blocked.join(', ') + ' (min: ' + d.min_isps + ')');
                    }
                    var remote = d.remote_last || {};
                    self.println('Remote: ' + (remote.ok ? 'OK' : 'FAIL') + ' HTTP ' + (remote.code || '?'));
                } else {
                    self.println('Error: ' + (resp.message || 'Unknown'));
                }
            });
        },

        clearCache: function () {
            var self = this;
            if (!confirm('Clear local data cache?')) return;
            this.clearConsole();
            this.showWait(true);
            this.println('Clearing cache...');

            this.ajax('clear_cache', null, function (resp) {
                if (resp.success) {
                    self.println(resp.message || 'Cache cleared');
                    self.showSimple(resp.message || 'Cache cleared.');
                } else {
                    self.println('Error: ' + (resp.message || 'Unknown'));
                }
            });
        },

        /* --- DOM updates --- */

        updateSummary: function (data) {
            var g = document.getElementById('cfb-summary-general');
            if (g && data.general) {
                var isYes = (data.general === 'SÍ' || data.general === 'SI');
                g.textContent = isYes ? 'SI' : 'NO';
                g.className = 'cfb-badge ' + (isYes ? 'cfb-badge-danger' : 'cfb-badge-ok');
            }
            var d = document.getElementById('cfb-summary-domain');
            if (d && data.domain) {
                var isDom = (data.domain === 'SÍ' || data.domain === 'SI');
                d.textContent = isDom ? 'SI' : 'NO';
                d.className = 'cfb-badge ' + (isDom ? 'cfb-badge-danger' : 'cfb-badge-ok');
            }
            var ips = document.getElementById('cfb-summary-ips');
            if (ips && data.ips) {
                ips.textContent = data.ips.length ? data.ips.join(', ') : '—';
            }
            var lu = document.getElementById('cfb-summary-lastupdate');
            if (lu && data.last_update !== undefined) {
                lu.textContent = data.last_update || '—';
            }
        },

        rebuildDnsTable: function (records, selected) {
            var container = document.getElementById('cfb-dns-list');
            if (!container || !records || !records.length) return;

            var domain = document.querySelector('.cfb-operation')
                ? (document.querySelector('.cfb-meta strong') || {}).textContent || ''
                : '';

            var html = '<table class="table table-striped cfb-dns-table" id="cfb-dns-table">';
            html += '<thead><tr>';
            html += '<th style="width:40px;"><input type="checkbox" id="cfb-check-all"></th>';
            html += '<th>Name</th><th>Type</th><th>Content</th><th>Proxy</th><th>TTL</th>';
            html += '</tr></thead><tbody>';

            records.forEach(function (r) {
                var isSelected = selected.indexOf(r.id) !== -1;
                var proxyLabel = '';
                if (r.proxied === true) proxyLabel = '<span class="label label-success">Proxied</span>';
                else if (r.proxied === false) proxyLabel = '<span class="label label-default">DNS Only</span>';
                else proxyLabel = '<span class="label label-warning">—</span>';

                html += '<tr data-type="' + CFB.esc(r.type) + '">';
                html += '<td><input type="checkbox" class="cfb-record-cb" name="selected_records[]" value="' + CFB.esc(r.id) + '"' + (isSelected ? ' checked' : '') + '></td>';
                html += '<td><strong>' + CFB.esc(r.name) + '</strong></td>';
                html += '<td><code>' + CFB.esc(r.type) + '</code></td>';
                html += '<td class="cfb-content-cell">' + CFB.esc(r.content) + '</td>';
                html += '<td>' + proxyLabel + '</td>';
                html += '<td>' + (r.ttl === 1 ? 'Auto' : r.ttl) + '</td>';
                html += '</tr>';
            });

            html += '</tbody></table>';
            container.innerHTML = html;

            this.updateCounts();
            this.clearDirty();

            // Rebind check-all
            var checkAll = document.getElementById('cfb-check-all');
            if (checkAll) {
                var self = this;
                checkAll.addEventListener('change', function () {
                    document.querySelectorAll('#cfb-dns-table tbody tr:not(.cfb-hidden) .cfb-record-cb').forEach(function (cb) {
                        cb.checked = checkAll.checked;
                    });
                    self.markDirty();
                    self.updateCounts();
                });
            }
        },

        esc: function (s) {
            if (!s) return '';
            var d = document.createElement('div');
            d.appendChild(document.createTextNode(s));
            return d.innerHTML;
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { CFB.init(); });
    } else {
        CFB.init();
    }

    window.CFB = CFB;
})();
