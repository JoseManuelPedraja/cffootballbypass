{*
* Operation template for CF Football Bypass
* Location: views/templates/admin/operation.tpl
*}

<div class="row">
    <div class="col-lg-12">
        <div class="panel">
            <div class="panel-heading">
                <h3 class="panel-title">
                    <i class="icon-shield"></i>
                    CF Football Bypass — Operación
                </h3>
            </div>
            <div class="panel-body">
                
                {* Information Section *}
                <div class="alert alert-info">
                    <p><strong>CF Football Bypass</strong> es un módulo gratuito creado por 
                    <a href="https://colorvivo.com" target="_blank">Color Vivo</a> y 
                    <a href="https://carrero.es" target="_blank">David Carrero</a> 
                    para ayudar cuando tu PrestaShop use Cloudflare y se vea afectado por los bloqueos indiscriminados de la liga.
                    </p>
                </div>

                {* Status Information *}
                <div class="row">
                    <div class="col-md-6">
                        <div class="panel panel-default">
                            <div class="panel-heading">
                                <h4>Estado Actual</h4>
                            </div>
                            <div class="panel-body">
                                <p><strong>Dominio:</strong> {$domain|escape:'htmlall':'UTF-8'}</p>
                                <p><strong>Zona Cloudflare:</strong> {if $settings.cloudflare_zone_id}{$settings.cloudflare_zone_id|truncate:8:'***'}{else}No configurada{/if}</p>
                                <p><strong>Tipo Auth:</strong> {if $settings.auth_type == 'token'}API Token{else}Global API Key{/if}</p>
                                <p>
                                    <strong>¿Hay bloqueos generales?</strong> 
                                    <span id="cfb-summary-general" class="badge {if $status.general == 'SÍ'}badge-danger{else}badge-success{/if}">
                                        {if $status.general == 'SÍ'}SI{else}NO{/if}
                                    </span>
                                </p>
                                <p>
                                    <strong>¿Está este dominio bloqueado?</strong> 
                                    <span id="cfb-summary-domain" class="badge {if $status.domain == 'SÍ'}badge-danger{else}badge-success{/if}">
                                        {if $status.domain == 'SÍ'}SI{else}NO{/if}
                                    </span>
                                </p>
                                <p>
                                    <strong>IPs del dominio:</strong> 
                                    <span id="cfb-summary-ips">
                                        {if $status.domain_ips}{$status.domain_ips|implode:', '|escape:'htmlall':'UTF-8'}{else}—{/if}
                                    </span>
                                    <a href="#" id="cfb-refresh-ips" class="btn btn-xs btn-default">Actualizar</a>
                                </p>
                                <p>
                                    <strong>Última actualización:</strong> 
                                    <span id="cfb-summary-lastupdate">{if $status.last_update}{$status.last_update|escape:'htmlall':'UTF-8'}{else}—{/if}</span>
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="panel panel-default">
                            <div class="panel-heading">
                                <h4>Enlaces de Interés</h4>
                            </div>
                            <div class="panel-body">
                                <h5>#LaLigaGate</h5>
                                <ul>
                                    <li><a href="https://hayahora.futbol/" target="_blank">Hay ahora fútbol</a></li>
                                    <li><a href="https://laligagate.com/" target="_blank">Web La Liga Gate</a></li>
                                    <li><a href="https://x.com/laligagate" target="_blank">Sigue en X @LaLigaGate</a></li>
                                </ul>
                                
                                <h5>Noticias y Actualidad</h5>
                                <ul>
                                    <li><a href="https://revistacloud.com" target="_blank">Revista Cloud</a></li>
                                    <li><a href="https://redes-sociales.com" target="_blank">Redes Sociales</a></li>
                                    <li><a href="https://opensecurity.es" target="_blank">OpenSecurity</a></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>

                {* DNS Records Section *}
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <h4>Registros DNS en Caché</h4>
                        <p class="help-block">Selecciona los registros que el módulo debe controlar automáticamente</p>
                    </div>
                    <div class="panel-body">
                        <div id="cfb-dns-list">
                            {if $dns_records.cache}
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th style="width:40px;"></th>
                                            <th>Nombre</th>
                                            <th>Tipo</th>
                                            <th>Contenido</th>
                                            <th>Proxy</th>
                                            <th>TTL</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {foreach from=$dns_records.cache item=record}
                                            <tr>
                                                <td>
                                                    <input type="checkbox" 
                                                           name="selected_records[]" 
                                                           value="{$record.id|escape:'htmlall':'UTF-8'}"
                                                           {if in_array($record.id, $dns_records.selected)}checked{/if}>
                                                </td>
                                                <td>{$record.name|escape:'htmlall':'UTF-8'}</td>
                                                <td>{$record.type|escape:'htmlall':'UTF-8'}</td>
                                                <td>{$record.content|escape:'htmlall':'UTF-8'}</td>
                                                <td>
                                                    {if isset($record.proxied)}
                                                        {if $record.proxied}
                                                            <span class="badge badge-success">ON</span>
                                                        {else}
                                                            <span class="badge badge-default">OFF</span>
                                                        {/if}
                                                    {else}
                                                        <span class="badge badge-warning">—</span>
                                                    {/if}
                                                </td>
                                                <td>{$record.ttl|escape:'htmlall':'UTF-8'}</td>
                                            </tr>
                                        {/foreach}
                                    </tbody>
                                </table>
                            {else}
                                <p class="alert alert-warning">No hay registros en caché. Pulsa "Probar conexión y cargar DNS" para actualizar.</p>
                            {/if}
                        </div>
                    </div>
                </div>

                {* Control Buttons *}
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <h4>Controles de Operación</h4>
                    </div>
                    <div class="panel-body">
                        <div class="btn-toolbar" role="toolbar">
                            <div class="btn-group" role="group">
                                <button type="button" class="btn btn-primary" id="cfb-test">
                                    <i class="icon-refresh"></i> Probar conexión y cargar DNS
                                </button>
                            </div>
                            <div class="btn-group" role="group">
                                <button type="button" class="btn btn-default" id="cfb-check">
                                    <i class="icon-play"></i> Comprobación manual
                                </button>
                            </div>
                            <div class="btn-group" role="group">
                                <button type="button" class="btn btn-warning" id="cfb-off">
                                    <i class="icon-off"></i> Forzar Proxy OFF
                                </button>
                                <button type="button" class="btn btn-success" id="cfb-on">
                                    <i class="icon-on"></i> Forzar Proxy ON
                                </button>
                            </div>
                            <div class="btn-group" role="group">
                                <button type="button" class="btn btn-info" id="cfb-diag">
                                    <i class="icon-wrench"></i> Diagnóstico
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                {* Console Output *}
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <h4>Consola de Salida</h4>
                    </div>
                    <div class="panel-body">
                        <div id="cfb-console" class="alert alert-info">
                            <div id="cfb-warn" class="alert alert-warning" style="display:none;">
                                <i class="icon-time"></i> Espera unos segundos para que se complete la operación...
                            </div>
                            <pre id="cfb-console-pre" style="margin-top:10px; white-space:pre-wrap; max-height:300px; overflow-y:auto;"></pre>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

{* Developer Info Footer *}
<div class="row">
    <div class="col-lg-12">
        <div class="alert alert-info text-center">
            <small>
                Desarrollado por <a href="https://carrero.es" target="_blank">David Carrero</a> • 
                <a href="https://x.com/carrero" target="_blank">@carrero</a> • 
                Versión {$settings.version|default:'1.5.4'}
            </small>
        </div>
    </div>
</div>

<script>
var cfbAjaxUrl = '{$ajax_url|escape:'javascript':'UTF-8'}';
var cfbAdminToken = '{$admin_token|escape:'javascript':'UTF-8'}';
</script>