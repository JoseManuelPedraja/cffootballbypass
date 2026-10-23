{**
 * CF Football Bypass — Operation Panel
 *
 * @author    Jose Manuel Pedraja
 * @copyright 2025 SurMod
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 *}

<div class="cfb-wrap">

    {* --- Info Banner --- *}
    <div class="panel cfb-panel">
        <div class="panel-heading">
            <i class="icon-shield"></i> CF Football Bypass — {l s='Operation' mod='cffootballbypass'}
        </div>
        <div class="panel-body">
            <div class="alert alert-info cfb-info-banner">
                <p>
                    <strong>CF Football Bypass</strong> {l s='is a free module created by' mod='cffootballbypass'}
                    <a href="https://colorvivo.com" target="_blank" rel="noopener">Color Vivo</a> {l s='and' mod='cffootballbypass'}
                    <a href="https://carrero.es" target="_blank" rel="noopener">David Carrero</a>
                    {l s='to help when your PrestaShop uses Cloudflare and is affected by indiscriminate La Liga blocks.' mod='cffootballbypass'}
                </p>
                <p class="cfb-links-row">
                    <a href="https://hayahora.futbol/" target="_blank" rel="noopener">hayahora.futbol</a> &middot;
                    <a href="https://laligagate.com/" target="_blank" rel="noopener">LaLigaGate</a> &middot;
                    <a href="https://x.com/laligagate" target="_blank" rel="noopener">@LaLigaGate</a> &middot;
                    <a href="https://revistacloud.com" target="_blank" rel="noopener">Revista Cloud</a> &middot;
                    <a href="https://opensecurity.es" target="_blank" rel="noopener">OpenSecurity</a>
                </p>
            </div>
        </div>
    </div>

    {* --- Status Panel --- *}
    <div class="row">
        <div class="col-md-6">
            <div class="panel cfb-panel cfb-status-card">
                <div class="panel-heading"><i class="icon-dashboard"></i> {l s='Current Status' mod='cffootballbypass'}</div>
                <div class="panel-body">
                    <table class="table cfb-status-table">
                        <tr>
                            <td><strong>{l s='Domain' mod='cffootballbypass'}</strong></td>
                            <td>{$domain|escape:'htmlall':'UTF-8'}</td>
                        </tr>
                        <tr>
                            <td><strong>{l s='Cloudflare Zone' mod='cffootballbypass'}</strong></td>
                            <td>{if $settings.cloudflare_zone_id}{$settings.cloudflare_zone_id|escape:'html':'UTF-8'|truncate:8:'***'}{else}<span class="cfb-badge cfb-badge-warning">{l s='Not configured' mod='cffootballbypass'}</span>{/if}</td>
                        </tr>
                        <tr>
                            <td><strong>{l s='Auth Type' mod='cffootballbypass'}</strong></td>
                            <td>
                                {if $settings.auth_type == 'token'}API Token
                                {elseif $settings.auth_type == 'account_token'}Account Token
                                {else}Global API Key{/if}
                            </td>
                        </tr>
                        <tr>
                            <td><strong>{l s='General blocks?' mod='cffootballbypass'}</strong></td>
                            <td>
                                <span id="cfb-summary-general" class="cfb-badge {if $status.general == 'YES'}cfb-badge-danger{else}cfb-badge-success{/if}">
                                    {if $status.general == 'YES'}{l s='YES' mod='cffootballbypass'}{else}{l s='NO' mod='cffootballbypass'}{/if}
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>{l s='Domain blocked?' mod='cffootballbypass'}</strong></td>
                            <td>
                                <span id="cfb-summary-domain" class="cfb-badge {if $status.domain == 'YES'}cfb-badge-danger{else}cfb-badge-success{/if}">
                                    {if $status.domain == 'YES'}{l s='YES' mod='cffootballbypass'}{else}{l s='NO' mod='cffootballbypass'}{/if}
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>{l s='Domain IPs' mod='cffootballbypass'}</strong></td>
                            <td>
                                <span id="cfb-summary-ips">{if $status.domain_ips}{', '|implode:$status.domain_ips|escape:'htmlall':'UTF-8'}{else}&mdash;{/if}</span>
                                <button type="button" class="btn btn-xs btn-default" id="cfb-refresh-ips" title="{l s='Refresh' mod='cffootballbypass'}"><i class="icon-refresh"></i></button>
                            </td>
                        </tr>
                        {if isset($status.isps_blocked) && $status.isps_blocked}
                        <tr>
                            <td><strong>{l s='ISPs blocked' mod='cffootballbypass'}</strong></td>
                            <td><span id="cfb-summary-isps">{', '|implode:$status.isps_blocked|escape:'htmlall':'UTF-8'}</span></td>
                        </tr>
                        {/if}
                        <tr>
                            <td><strong>{l s='Last update' mod='cffootballbypass'}</strong></td>
                            <td><span id="cfb-summary-lastupdate">{if $status.last_update}{$status.last_update|escape:'htmlall':'UTF-8'}{else}&mdash;{/if}</span></td>
                        </tr>
                        {if $server_ips}
                        <tr>
                            <td><strong>{l s='Server outgoing IPs' mod='cffootballbypass'}</strong></td>
                            <td><span id="cfb-server-ips">{', '|implode:$server_ips|escape:'htmlall':'UTF-8'}</span></td>
                        </tr>
                        {/if}
                    </table>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="panel cfb-panel cfb-status-card">
                <div class="panel-heading"><i class="icon-time"></i> {l s='Cron Job' mod='cffootballbypass'}</div>
                <div class="panel-body">
                    <p>{l s='Configure your cron job or external service (e.g. EasyCron) with this URL:' mod='cffootballbypass'}</p>
                    <div class="input-group">
                        <input type="text" id="cfb-cron-url" class="form-control" readonly value="{$cron_url|escape:'htmlall':'UTF-8'}" onclick="this.select();" style="font-family:monospace;font-size:12px;">
                        <span class="input-group-btn">
                            <button type="button" class="btn btn-default" id="cfb-copy-cron" title="{l s='Copy' mod='cffootballbypass'}"><i class="icon-copy"></i></button>
                        </span>
                    </div>
                    <p class="help-block">{l s='Recommended: Every 15 minutes' mod='cffootballbypass'}</p>
                </div>
            </div>

            <div class="panel cfb-panel cfb-status-card">
                <div class="panel-heading"><i class="icon-link"></i> {l s='Links' mod='cffootballbypass'}</div>
                <div class="panel-body">
                    <ul class="cfb-link-list">
                        <li><a href="https://hayahora.futbol/" target="_blank" rel="noopener">{l s='Is there football now?' mod='cffootballbypass'}</a></li>
                        <li><a href="https://laligagate.com/" target="_blank" rel="noopener">LaLigaGate</a></li>
                        <li><a href="https://revistacloud.com" target="_blank" rel="noopener">Revista Cloud</a></li>
                        <li><a href="https://opensecurity.es" target="_blank" rel="noopener">OpenSecurity</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    {* --- DNS Records --- *}
    <div class="panel cfb-panel">
        <div class="panel-heading">
            <i class="icon-list"></i> {l s='DNS Records' mod='cffootballbypass'}
            <span class="badge cfb-badge-default" id="cfb-record-count">{if $dns_records.cache}{$dns_records.cache|count}{else}0{/if}</span>
        </div>
        <div class="panel-body">
            <div class="cfb-table-toolbar">
                <div class="cfb-filter-group">
                    <span class="cfb-filter-label">{l s='Filter' mod='cffootballbypass'}:</span>
                    <button type="button" class="btn btn-xs cfb-type-filter active" data-type="all">{l s='All' mod='cffootballbypass'}</button>
                    <button type="button" class="btn btn-xs cfb-type-filter" data-type="A">A</button>
                    <button type="button" class="btn btn-xs cfb-type-filter" data-type="AAAA">AAAA</button>
                    <button type="button" class="btn btn-xs cfb-type-filter" data-type="CNAME">CNAME</button>
                </div>
                <div class="cfb-select-group">
                    <button type="button" class="btn btn-xs btn-default" id="cfb-select-all"><i class="icon-check"></i> {l s='Select all' mod='cffootballbypass'}</button>
                    <button type="button" class="btn btn-xs btn-default" id="cfb-select-none"><i class="icon-ban-circle"></i> {l s='Select none' mod='cffootballbypass'}</button>
                </div>
            </div>

            <div id="cfb-dns-list">
                {if $dns_records.cache}
                    <table class="table table-striped cfb-dns-table" id="cfb-dns-table">
                        <thead>
                            <tr>
                                <th style="width:40px;"></th>
                                <th>{l s='Name' mod='cffootballbypass'}</th>
                                <th>{l s='Type' mod='cffootballbypass'}</th>
                                <th>{l s='Content' mod='cffootballbypass'}</th>
                                <th>{l s='Proxy' mod='cffootballbypass'}</th>
                                <th>TTL</th>
                            </tr>
                        </thead>
                        <tbody>
                            {foreach from=$dns_records.cache item=record}
                                <tr data-type="{$record.type|escape:'htmlall':'UTF-8'}">
                                    <td>
                                        <input type="checkbox"
                                               class="cfb-record-check"
                                               name="selected_records[]"
                                               value="{$record.id|escape:'htmlall':'UTF-8'}"
                                               {if in_array($record.id, $dns_records.selected)}checked{/if}>
                                    </td>
                                    <td>{$record.name|escape:'htmlall':'UTF-8'}</td>
                                    <td><span class="cfb-badge cfb-badge-default">{$record.type|escape:'htmlall':'UTF-8'}</span></td>
                                    <td class="cfb-content-cell">{$record.content|escape:'htmlall':'UTF-8'}</td>
                                    <td>
                                        {if isset($record.proxied)}
                                            {if $record.proxied}
                                                <span class="cfb-badge cfb-badge-success">ON</span>
                                            {else}
                                                <span class="cfb-badge cfb-badge-default">OFF</span>
                                            {/if}
                                        {else}
                                            <span class="cfb-badge cfb-badge-warning">&mdash;</span>
                                        {/if}
                                    </td>
                                    <td>{$record.ttl|escape:'htmlall':'UTF-8'}</td>
                                </tr>
                            {/foreach}
                        </tbody>
                    </table>
                {else}
                    <div class="alert alert-warning">
                        <i class="icon-warning-sign"></i>
                        {l s='No DNS records in cache. Click "Test connection" to load them.' mod='cffootballbypass'}
                    </div>
                {/if}
            </div>
        </div>
    </div>

    {* --- Control Buttons --- *}
    <div class="panel cfb-panel">
        <div class="panel-heading"><i class="icon-cogs"></i> {l s='Controls' mod='cffootballbypass'}</div>
        <div class="panel-body">
            <div class="cfb-btn-toolbar">
                <div class="cfb-btn-group">
                    <button type="button" class="btn btn-primary" id="cfb-test">
                        <i class="icon-refresh"></i> {l s='Test connection' mod='cffootballbypass'}
                    </button>
                    <button type="button" class="btn btn-default" id="cfb-check">
                        <i class="icon-play"></i> {l s='Manual check' mod='cffootballbypass'}
                    </button>
                </div>
                <div class="cfb-btn-group">
                    <button type="button" class="btn btn-warning" id="cfb-off">
                        <i class="icon-off"></i> {l s='Force Proxy OFF' mod='cffootballbypass'}
                    </button>
                    <button type="button" class="btn btn-success" id="cfb-on">
                        <i class="icon-ok"></i> {l s='Force Proxy ON' mod='cffootballbypass'}
                    </button>
                </div>
                <div class="cfb-btn-group">
                    <button type="button" class="btn btn-info" id="cfb-feed-diag">
                        <i class="icon-signal"></i> {l s='Feed diagnostics' mod='cffootballbypass'}
                    </button>
                    <button type="button" class="btn btn-default" id="cfb-clear-cache">
                        <i class="icon-trash"></i> {l s='Clear data cache' mod='cffootballbypass'}
                    </button>
                    <button type="button" class="btn btn-info" id="cfb-diag">
                        <i class="icon-wrench"></i> {l s='Diagnostics' mod='cffootballbypass'}
                    </button>
                </div>
            </div>
        </div>
    </div>

    {* --- Console --- *}
    <div class="panel cfb-panel">
        <div class="panel-heading">
            <i class="icon-terminal"></i> {l s='Console' mod='cffootballbypass'}
            <button type="button" class="btn btn-xs btn-default pull-right" id="cfb-clear-console"><i class="icon-eraser"></i> {l s='Clear' mod='cffootballbypass'}</button>
        </div>
        <div class="panel-body cfb-console-wrap">
            <pre id="cfb-console" class="cfb-console"></pre>
        </div>
    </div>

    {* --- Footer --- *}
    <div class="cfb-footer text-center">
        <small>
            Based on <a href="https://github.com/dcarrero/cf-football-bypass" target="_blank" rel="noopener">ES Football Bypass</a>
            by <a href="https://carrero.es" target="_blank" rel="noopener">David Carrero</a> &middot;
            Adapted for PrestaShop by <a href="https://github.com/JoseManuelPedraja" target="_blank" rel="noopener">Jose Manuel Pedraja</a> &middot;
            Version {$module_version|escape:'htmlall':'UTF-8'}
        </small>
    </div>
</div>

<script>
var cfbAjaxUrl = '{$ajax_url|escape:'javascript':'UTF-8'}';
var cfbAdminToken = '{$admin_token|escape:'javascript':'UTF-8'}';
</script>
