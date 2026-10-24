{**
 * CF Football Bypass — Operation Panel
 *
 * @author    Jose Manuel Pedraja <josemanuelpedraja@gmail.com>
 * @copyright 2007-2026 PrestaShop SA
 * @license   https://opensource.org/licenses/AFL-3.0
 *}

<div class="cfb-operation" id="cfb-operation"
     data-ajax-url="{$cfb_ajax_url|escape:'htmlall':'UTF-8'}"
     data-ajax-token="{$cfb_ajax_token|escape:'htmlall':'UTF-8'}"
     data-advanced="{if $cfb_is_advanced}1{else}0{/if}">

    {* Info banner *}
    <div class="alert alert-info">
        <strong>CF Football Bypass</strong> — {l s='Module by' mod='cffootballbypass'}
        <a href="https://surmod.es" target="_blank" rel="noopener">Jose Manuel Pedraja</a> &amp;
        <a href="https://surmod.es" target="_blank" rel="noopener">SurMod.es</a>.
        {l s='Automatically toggles Cloudflare proxy during La Liga IP blocks.' mod='cffootballbypass'}
    </div>

    {* Connection info bar *}
    <p class="cfb-meta">
        {l s='Zone:' mod='cffootballbypass'} <code>{$cfb_zone_masked|escape:'htmlall':'UTF-8'}</code> &middot;
        {l s='Auth:' mod='cffootballbypass'} <strong>{$cfb_auth_label|escape:'htmlall':'UTF-8'}</strong> &middot;
        {l s='Domain:' mod='cffootballbypass'} <strong>{$cfb_domain|escape:'htmlall':'UTF-8'}</strong> —
        <a href="{$cfb_check_url|escape:'htmlall':'UTF-8'}" target="_blank" rel="noopener">{l s='Open checker' mod='cffootballbypass'}</a>
    </p>

    <div class="row">
        {* Main column *}
        <div class="col-md-9">

            {* Status summary *}
            <div class="panel cfb-status-panel">
                <div class="panel-heading">
                    <i class="material-icons">cell_tower</i> {l s='Block Status' mod='cffootballbypass'}
                </div>
                <div class="form-wrapper">
                    <div class="form-group">
                        <p>
                            <strong>{l s='Active IP blocks now:' mod='cffootballbypass'}</strong>
                            <span id="cfb-summary-general" class="cfb-badge {if $cfb_status.general == 'SÍ'}cfb-badge-danger{else}cfb-badge-ok{/if}">
                                {if $cfb_status.general == 'SÍ'}SI{else}NO{/if}
                            </span>
                        </p>
                        <p>
                            <strong>{l s='Is this domain blocked?' mod='cffootballbypass'}</strong>
                            <span id="cfb-summary-domain" class="cfb-badge {if $cfb_status.domain == 'SÍ'}cfb-badge-danger{else}cfb-badge-ok{/if}">
                                {if $cfb_status.domain == 'SÍ'}SI{else}NO{/if}
                            </span>
                            <span class="cfb-status-ips">IPs: <span id="cfb-summary-ips">{if $cfb_status.domain_ips}{foreach from=$cfb_status.domain_ips item=dip name=diploop}{$dip|escape:'htmlall':'UTF-8'}{if !$smarty.foreach.diploop.last}, {/if}{/foreach}{else}—{/if}</span></span>
                            <a href="#" id="cfb-refresh-ips" class="btn btn-xs btn-default" style="margin-left:6px;"><i class="material-icons">refresh</i> {l s='Refresh' mod='cffootballbypass'}</a>
                        </p>
                        <p>
                            <strong>{l s='Last update:' mod='cffootballbypass'}</strong>
                            <span id="cfb-summary-lastupdate">{if $cfb_status.last_update}{$cfb_status.last_update|escape:'htmlall':'UTF-8'}{else}—{/if}</span>
                        </p>
                    </div>
                </div>
            </div>

            {* DNS Records *}
            <div class="panel">
                <div class="panel-heading">
                    <i class="material-icons">dns</i> {l s='DNS Records' mod='cffootballbypass'}
                </div>
                <div class="form-wrapper">
                    <div class="form-group">
                        <p class="help-block">{l s='Select the records the module should manage during blocks, then click Save Selection.' mod='cffootballbypass'}</p>

                        {* Filter toolbar *}
                        <div class="cfb-toolbar" id="cfb-toolbar">
                            <span class="cfb-toolbar-label">{l s='Filter:' mod='cffootballbypass'}</span>
                            <button type="button" class="btn btn-xs btn-default cfb-filter active" data-filter="all">{l s='All' mod='cffootballbypass'}</button>
                            <button type="button" class="btn btn-xs btn-default cfb-filter" data-filter="A">A</button>
                            <button type="button" class="btn btn-xs btn-default cfb-filter" data-filter="AAAA">AAAA</button>
                            <button type="button" class="btn btn-xs btn-default cfb-filter" data-filter="CNAME">CNAME</button>
                            <span class="cfb-toolbar-sep">|</span>
                            <span class="cfb-toolbar-label">{l s='Select:' mod='cffootballbypass'}</span>
                            <button type="button" class="btn btn-xs btn-default" data-select="all">{l s='All visible' mod='cffootballbypass'}</button>
                            <button type="button" class="btn btn-xs btn-default" data-select="A">{l s='Only A' mod='cffootballbypass'}</button>
                            <button type="button" class="btn btn-xs btn-default" data-select="A,AAAA">A+AAAA</button>
                            <button type="button" class="btn btn-xs btn-default" data-select="none">{l s='None' mod='cffootballbypass'}</button>
                        </div>

                        <div id="cfb-dns-list">
                            {if $cfb_dns_cache}
                                <table class="table table-striped cfb-dns-table" id="cfb-dns-table">
                                    <thead>
                                        <tr>
                                            <th style="width:40px;"><input type="checkbox" id="cfb-check-all" title="{l s='Select/deselect all' mod='cffootballbypass'}"></th>
                                            <th>{l s='Name' mod='cffootballbypass'}</th>
                                            <th>{l s='Type' mod='cffootballbypass'}</th>
                                            <th>{l s='Content' mod='cffootballbypass'}</th>
                                            <th>{l s='Proxy' mod='cffootballbypass'}</th>
                                            <th>TTL</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {foreach from=$cfb_dns_cache item=record}
                                            <tr data-type="{$record.type|escape:'htmlall':'UTF-8'}">
                                                <td>
                                                    <input type="checkbox"
                                                           name="selected_records[]"
                                                           value="{$record.id|escape:'htmlall':'UTF-8'}"
                                                           class="cfb-record-cb"
                                                           {if in_array($record.id, $cfb_selected)}checked{/if}>
                                                </td>
                                                <td>
                                                    <strong>{$record.name|escape:'htmlall':'UTF-8'}</strong>
                                                    {if $record.name == $cfb_domain || $record.name == "www.`$cfb_domain`"}
                                                        <span class="label label-info">{l s='your domain' mod='cffootballbypass'}</span>
                                                    {/if}
                                                </td>
                                                <td><code>{$record.type|escape:'htmlall':'UTF-8'}</code></td>
                                                <td class="cfb-content-cell">{$record.content|escape:'htmlall':'UTF-8'}</td>
                                                <td>
                                                    {if isset($record.proxied)}
                                                        {if $record.proxied}
                                                            <span class="label label-success">Proxied</span>
                                                        {else}
                                                            <span class="label label-default">DNS Only</span>
                                                        {/if}
                                                    {else}
                                                        <span class="label label-warning">—</span>
                                                    {/if}
                                                </td>
                                                <td>{if $record.ttl == 1}Auto{else}{$record.ttl|escape:'htmlall':'UTF-8'}{/if}</td>
                                            </tr>
                                        {/foreach}
                                    </tbody>
                                </table>
                            {else}
                                <div class="alert alert-warning">{l s='No DNS records cached. Click "Reload from Cloudflare" to load.' mod='cffootballbypass'}</div>
                            {/if}
                        </div>

                        <p class="cfb-sel-count">
                            <span id="cfb-sel-count">0</span> {l s='of' mod='cffootballbypass'} <span id="cfb-total-count">0</span> {l s='records selected.' mod='cffootballbypass'}
                            <span id="cfb-dirty" class="cfb-dirty" style="display:none;">{l s='Unsaved changes' mod='cffootballbypass'}</span>
                        </p>
                    </div>
                </div>
            </div>

            {* Action buttons *}
            <div class="panel">
                <div class="panel-heading">
                    <i class="material-icons">bolt</i> {l s='Actions' mod='cffootballbypass'}
                </div>
                <div class="form-wrapper">
                    <div class="form-group">
                        <div class="cfb-actions">
                            <div class="cfb-action-group">
                                <button type="button" class="btn btn-primary" id="cfb-save-selection"><i class="material-icons">save</i> {l s='Save Selection' mod='cffootballbypass'}</button>
                                <button type="button" class="btn btn-default" id="cfb-test"><i class="material-icons">refresh</i> {l s='Reload from Cloudflare' mod='cffootballbypass'}</button>
                            </div>
                            <div class="cfb-action-group">
                                <button type="button" class="btn btn-default" id="cfb-check"><i class="material-icons">play_arrow</i> {l s='Check blocks now' mod='cffootballbypass'}</button>
                                <button type="button" class="btn btn-warning" id="cfb-off"><i class="material-icons">block</i> {l s='Force Proxy OFF' mod='cffootballbypass'}</button>
                                <button type="button" class="btn btn-success" id="cfb-on"><i class="material-icons">check_circle</i> {l s='Force Proxy ON' mod='cffootballbypass'}</button>
                            </div>
                            {if $cfb_is_advanced}
                            <div class="cfb-action-group">
                                <button type="button" class="btn btn-info" id="cfb-diag"><i class="material-icons">build</i> {l s='Cron Diagnostics' mod='cffootballbypass'}</button>
                                <button type="button" class="btn btn-info" id="cfb-refresh-diag"><i class="material-icons">bar_chart</i> {l s='Feed Diagnostics' mod='cffootballbypass'}</button>
                                <button type="button" class="btn btn-danger" id="cfb-clear-cache"><i class="material-icons">delete</i> {l s='Clear data cache' mod='cffootballbypass'}</button>
                            </div>
                            {/if}
                        </div>
                    </div>
                </div>
            </div>

            {* Console *}
            {if $cfb_is_advanced}
            <div class="panel cfb-console-panel">
                <div class="panel-heading">
                    <i class="material-icons">terminal</i> {l s='Console' mod='cffootballbypass'}
                </div>
                <div class="form-wrapper">
                    <div class="form-group">
                        <div id="cfb-warn" class="alert alert-warning" style="display:none;">
                            <i class="material-icons">schedule</i> {l s='Wait for the operation to complete...' mod='cffootballbypass'}
                        </div>
                        <pre id="cfb-console-pre" class="cfb-console"></pre>
                    </div>
                </div>
            </div>
            {else}
            <div id="cfb-console-simple-wrap" class="alert alert-info" style="display:none;">
                <div id="cfb-warn" style="display:none;color:#b32d2e;font-weight:600;">{l s='Processing...' mod='cffootballbypass'}</div>
                <div id="cfb-console-simple"></div>
                <pre id="cfb-console-pre" style="display:none;"></pre>
            </div>
            {/if}

            {* Feed diagnostics (advanced only) *}
            {if $cfb_is_advanced && isset($cfb_feed_diag)}
            <div class="panel" id="cfb-feed-diag-panel">
                <div class="panel-heading">
                    <i class="material-icons">bar_chart</i> {l s='Feed Diagnostics' mod='cffootballbypass'}
                </div>
                <div class="form-wrapper" id="cfb-feed-diag">
                    <div class="form-group">
                        {if $cfb_feed_diag.local_exists}
                            <p><strong>data.json local:</strong> {$cfb_feed_diag.local_size|escape:'htmlall':'UTF-8'} bytes
                            — lastUpdate: <code>{if $cfb_feed_diag.local_last_update}{$cfb_feed_diag.local_last_update|escape:'htmlall':'UTF-8'}{else}—{/if}</code></p>
                        {else}
                            <p><strong>data.json local:</strong> {l s='not yet downloaded' mod='cffootballbypass'}</p>
                        {/if}
                        {if $cfb_feed_diag.remote_last.time}
                            <p><strong>{l s='Last remote fetch:' mod='cffootballbypass'}</strong>
                            {if $cfb_feed_diag.remote_last.ok}
                                <span style="color:#007017;">OK</span> (HTTP {$cfb_feed_diag.remote_last.code|escape:'htmlall':'UTF-8'})
                            {else}
                                <span style="color:#b32d2e;">FAILED</span> — {$cfb_feed_diag.remote_last.error|escape:'htmlall':'UTF-8'}
                            {/if}
                            </p>
                        {/if}
                        <p><strong>{l s='Blocked IPs:' mod='cffootballbypass'}</strong>
                            <span style="font-weight:600;{if $cfb_feed_diag.blocked_count > 0}color:#b32d2e;{else}color:#007017;{/if}">
                                {$cfb_feed_diag.blocked_count|escape:'htmlall':'UTF-8'}
                            </span> / {$cfb_feed_diag.total_count|escape:'htmlall':'UTF-8'}
                        </p>
                        {if $cfb_feed_diag.isps_blocked}
                            <p><strong>{l s='Affected ISPs:' mod='cffootballbypass'}</strong>
                                {foreach from=$cfb_feed_diag.isps_blocked item=isp name=isploop}{$isp|escape:'htmlall':'UTF-8'}{if !$smarty.foreach.isploop.last}, {/if}{/foreach}
                                ({l s='min required:' mod='cffootballbypass'} {$cfb_feed_diag.min_isps|escape:'htmlall':'UTF-8'})
                            </p>
                        {/if}
                    </div>
                </div>
            </div>
            {/if}

        </div>

        {* Sidebar *}
        <div class="col-md-3">
            <div class="panel">
                <div class="panel-heading">#LaLigaGate</div>
                <ul class="list-group">
                    <li class="list-group-item"><a href="https://hayahora.futbol/" target="_blank" rel="noopener">Hay ahora fútbol</a></li>
                    <li class="list-group-item"><a href="https://laligagate.com/" target="_blank" rel="noopener">La Liga Gate</a></li>
                    <li class="list-group-item"><a href="https://x.com/laligagate" target="_blank" rel="noopener">@LaLigaGate</a></li>
                </ul>
            </div>
            <div class="panel">
                <div class="panel-heading">{l s='VPN Servers' mod='cffootballbypass'}</div>
                <ul class="list-group">
                    <li class="list-group-item"><a href="https://revistacloud.com/protonvpn" target="_blank" rel="noopener">ProtonVPN</a></li>
                    <li class="list-group-item"><a href="https://revistacloud.com/nvpn" target="_blank" rel="noopener">NordVPN</a></li>
                </ul>
            </div>
            <div class="panel">
                <div class="panel-heading">{l s='News' mod='cffootballbypass'}</div>
                <ul class="list-group">
                    <li class="list-group-item"><a href="https://revistacloud.com" target="_blank" rel="noopener">Revista Cloud</a></li>
                    <li class="list-group-item"><a href="https://wpdirecto.com" target="_blank" rel="noopener">WordPress Directo</a></li>
                    <li class="list-group-item"><a href="https://opensecurity.es" target="_blank" rel="noopener">OpenSecurity</a></li>
                </ul>
            </div>
            <div class="cfb-sidebar-footer">
                {l s='Based on the WordPress plugin by' mod='cffootballbypass'} <a href="https://carrero.es" target="_blank" rel="noopener">David Carrero</a>
            </div>
        </div>
    </div>
</div>
