/**
 * CF Football Bypass — Admin JS
 *
 * @author    Jose Manuel Pedraja
 * @copyright 2025 SurMod
 * @license   https://opensource.org/licenses/AFL-3.0
 */

var CFB = (function () {
    'use strict';

    var consoleEl = null;
    var activeFilter = 'all';

    function init() {
        consoleEl = document.getElementById('cfb-console');

        bind('cfb-test', 'click', testConnection);
        bind('cfb-check', 'click', manualCheck);
        bind('cfb-off', 'click', forceOff);
        bind('cfb-on', 'click', forceOn);
        bind('cfb-diag', 'click', cronDiagnostics);
        bind('cfb-feed-diag', 'click', feedDiagnostics);
        bind('cfb-clear-cache', 'click', clearDataCache);
        bind('cfb-clear-console', 'click', clearConsole);
        bind('cfb-refresh-ips', 'click', refreshStatus);
        bind('cfb-copy-cron', 'click', copyCronUrl);
        bind('cfb-select-all', 'click', selectAll);
        bind('cfb-select-none', 'click', selectNone);

        var filters = document.querySelectorAll('.cfb-type-filter');
        for (var i = 0; i < filters.length; i++) {
            filters[i].addEventListener('click', handleFilterClick);
        }

        var checks = document.querySelectorAll('.cfb-record-check');
        for (var j = 0; j < checks.length; j++) {
            checks[j].addEventListener('change', updateSelectedRecords);
        }

        refreshStatus();
    }

    function bind(id, event, fn) {
        var el = document.getElementById(id);
        if (el) el.addEventListener(event, fn);
    }

    function timestamp() {
        var d = new Date();
        return d.toLocaleTimeString();
    }

    function addToConsole(msg) {
        if (!consoleEl) return;
        consoleEl.textContent += '[' + timestamp() + '] ' + msg + '\n';
        consoleEl.scrollTop = consoleEl.scrollHeight;
    }

    function clearConsole() {
        if (consoleEl) consoleEl.textContent = '';
    }

    function getSelectedRecords() {
        var checks = document.querySelectorAll('.cfb-record-check:checked');
        var ids = [];
        for (var i = 0; i < checks.length; i++) {
            ids.push(checks[i].value);
        }
        return ids;
    }

    function ajaxCall(action, extra, callback) {
        var selected = getSelectedRecords();
        var body = 'action=' + encodeURIComponent(action)
            + '&ajax=1'
            + '&token=' + encodeURIComponent(typeof cfbAdminToken !== 'undefined' ? cfbAdminToken : '')
            + '&selected=' + encodeURIComponent(JSON.stringify(selected));

        if (extra) {
            for (var k in extra) {
                if (extra.hasOwnProperty(k)) {
                    body += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(extra[k]);
                }
            }
        }

        var url = typeof cfbAjaxUrl !== 'undefined' ? cfbAjaxUrl : '';
        if (!url) {
            addToConsole('ERROR: AJAX URL not configured');
            return;
        }

        fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        })
        .then(function (resp) {
            if (!resp.ok) addToConsole('HTTP ' + resp.status);
            return resp.text();
        })
        .then(function (text) {
            if (!text || !text.trim()) {
                addToConsole('ERROR: Empty server response');
                return;
            }
            try {
                var data = JSON.parse(text);
                if (callback) callback(data);
            } catch (e) {
                addToConsole('ERROR parsing JSON: ' + e.message);
                addToConsole('Raw: ' + text.substring(0, 200));
            }
        })
        .catch(function (err) {
            addToConsole('Network error: ' + err);
        });
    }

    function logResponse(data) {
        if (data.log && data.log.length) {
            for (var i = 0; i < data.log.length; i++) {
                addToConsole(data.log[i]);
            }
        }
    }

    function updateDnsHtml(data) {
        if (data.html) {
            var container = document.getElementById('cfb-dns-list');
            if (container) {
                container.innerHTML = data.html;
                rebindChecks();
                applyFilter();
            }
        }
    }

    function rebindChecks() {
        var checks = document.querySelectorAll('.cfb-record-check');
        for (var i = 0; i < checks.length; i++) {
            checks[i].addEventListener('change', updateSelectedRecords);
        }
        updateRecordCount();
    }

    function updateRecordCount() {
        var rows = document.querySelectorAll('#cfb-dns-table tbody tr');
        var el = document.getElementById('cfb-record-count');
        if (el) el.textContent = rows ? rows.length : '0';
    }

    function testConnection() {
        clearConsole();
        addToConsole('Testing connection...');
        ajaxCall('test_connection', null, function (data) {
            if (data.success) {
                addToConsole('Connection OK');
                logResponse(data);
                updateDnsHtml(data);
            } else {
                addToConsole('Error: ' + (data.message || 'Unknown'));
                logResponse(data);
            }
        });
    }

    function manualCheck() {
        clearConsole();
        addToConsole('Running manual check...');
        ajaxCall('manual_check', null, function (data) {
            if (data.success) {
                addToConsole('Check completed');
                logResponse(data);
                if (data.general) addToConsole('General: ' + data.general);
                if (data.domain) addToConsole('Domain: ' + data.domain);
                updateStatusBadges(data);
            } else {
                addToConsole('Error: ' + (data.message || 'Unknown'));
                logResponse(data);
            }
        });
    }

    function forceOff() {
        clearConsole();
        var selected = getSelectedRecords();
        if (!selected.length) {
            addToConsole('ERROR: No records selected');
            return;
        }
        addToConsole('Forcing Proxy OFF...');
        ajaxCall('force_deactivate', null, function (data) {
            if (data.success) {
                addToConsole('Proxy OFF applied');
                if (data.message) addToConsole(data.message);
                logResponse(data);
                updateDnsHtml(data);
            } else {
                addToConsole('Error: ' + (data.message || 'Unknown'));
                logResponse(data);
            }
        });
    }

    function forceOn() {
        clearConsole();
        var selected = getSelectedRecords();
        if (!selected.length) {
            addToConsole('ERROR: No records selected');
            return;
        }
        addToConsole('Forcing Proxy ON...');
        ajaxCall('force_activate', null, function (data) {
            if (data.success) {
                addToConsole('Proxy ON applied');
                if (data.message) addToConsole(data.message);
                logResponse(data);
                updateDnsHtml(data);
            } else {
                addToConsole('Error: ' + (data.message || 'Unknown'));
                logResponse(data);
            }
        });
    }

    function cronDiagnostics() {
        clearConsole();
        addToConsole('Running diagnostics...');
        ajaxCall('cron_diagnostics', null, function (data) {
            if (data.success) {
                if (data.msg) addToConsole(data.msg);
                logResponse(data);
            } else {
                addToConsole('Error: ' + (data.message || 'Unknown'));
            }
        });
    }

    function feedDiagnostics() {
        clearConsole();
        addToConsole('Running feed diagnostics...');
        ajaxCall('feed_diagnostics', null, function (data) {
            if (data.success) {
                if (data.msg) addToConsole(data.msg);
                logResponse(data);
            } else {
                addToConsole('Error: ' + (data.message || 'Unknown'));
            }
        });
    }

    function clearDataCache() {
        clearConsole();
        addToConsole('Clearing data cache...');
        ajaxCall('clear_data_cache', null, function (data) {
            if (data.success) {
                addToConsole('Cache cleared');
                if (data.message) addToConsole(data.message);
                logResponse(data);
            } else {
                addToConsole('Error: ' + (data.message || 'Unknown'));
            }
        });
    }

    function updateSelectedRecords() {
        ajaxCall('update_selected_records', null, function (data) {
            if (!data.success) {
                addToConsole('Error saving selection: ' + (data.message || ''));
            }
        });
    }

    function refreshStatus() {
        ajaxCall('get_status', null, function (data) {
            if (data.success) {
                updateStatusBadges(data);
            }
        });
    }

    function updateStatusBadges(data) {
        var elGen = document.getElementById('cfb-summary-general');
        var elDom = document.getElementById('cfb-summary-domain');
        var elIps = document.getElementById('cfb-summary-ips');
        var elUpd = document.getElementById('cfb-summary-lastupdate');

        if (elGen && data.general !== undefined) {
            var isBlocked = data.general === 'YES' || data.general === 'SI';
            elGen.textContent = isBlocked ? 'YES' : 'NO';
            elGen.className = 'cfb-badge ' + (isBlocked ? 'cfb-badge-danger' : 'cfb-badge-success');
        }
        if (elDom && data.domain !== undefined) {
            var isDomBlocked = data.domain === 'YES' || data.domain === 'SI';
            elDom.textContent = isDomBlocked ? 'YES' : 'NO';
            elDom.className = 'cfb-badge ' + (isDomBlocked ? 'cfb-badge-danger' : 'cfb-badge-success');
        }
        if (elIps && data.ips) {
            elIps.textContent = data.ips.length ? data.ips.join(', ') : '—';
        }
        if (elUpd && data.last_update) {
            elUpd.textContent = data.last_update;
        }
    }

    function handleFilterClick(e) {
        var btn = e.currentTarget;
        var type = btn.getAttribute('data-type');

        var filters = document.querySelectorAll('.cfb-type-filter');
        for (var i = 0; i < filters.length; i++) {
            filters[i].classList.remove('active');
        }
        btn.classList.add('active');
        activeFilter = type;
        applyFilter();
    }

    function applyFilter() {
        var rows = document.querySelectorAll('#cfb-dns-table tbody tr');
        if (!rows) return;
        for (var i = 0; i < rows.length; i++) {
            var rowType = rows[i].getAttribute('data-type');
            if (activeFilter === 'all' || rowType === activeFilter) {
                rows[i].style.display = '';
            } else {
                rows[i].style.display = 'none';
            }
        }
    }

    function selectAll() {
        var rows = document.querySelectorAll('#cfb-dns-table tbody tr');
        if (!rows) return;
        for (var i = 0; i < rows.length; i++) {
            if (rows[i].style.display !== 'none') {
                var cb = rows[i].querySelector('.cfb-record-check');
                if (cb) cb.checked = true;
            }
        }
        updateSelectedRecords();
    }

    function selectNone() {
        var rows = document.querySelectorAll('#cfb-dns-table tbody tr');
        if (!rows) return;
        for (var i = 0; i < rows.length; i++) {
            if (rows[i].style.display !== 'none') {
                var cb = rows[i].querySelector('.cfb-record-check');
                if (cb) cb.checked = false;
            }
        }
        updateSelectedRecords();
    }

    function copyCronUrl() {
        var input = document.getElementById('cfb-cron-url');
        if (!input) return;
        input.select();
        input.setSelectionRange(0, 99999);
        try {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(input.value);
            } else {
                document.execCommand('copy');
            }
            addToConsole('Cron URL copied to clipboard');
        } catch (e) {
            addToConsole('Copy failed: ' + e.message);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    return {
        addToConsole: addToConsole,
        clearConsole: clearConsole,
        getSelectedRecords: getSelectedRecords,
        testConnection: testConnection,
        manualCheck: manualCheck,
        forceOff: forceOff,
        forceOn: forceOn,
        cronDiagnostics: cronDiagnostics,
        feedDiagnostics: feedDiagnostics,
        clearDataCache: clearDataCache,
        updateSelectedRecords: updateSelectedRecords,
        refreshStatus: refreshStatus
    };
})();
