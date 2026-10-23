![PrestaShop](https://badgen.net/badge/PrestaShop/8.1%2B/blue)
![PHP](https://badgen.net/badge/PHP/8.1%2B/8892BF)
![Ultimo Release](https://badgen.net/github/release/SurMod/cffootballbypass)
![Licencia](https://badgen.net/github/license/SurMod/cffootballbypass)

# CF Football Bypass para PrestaShop

Modulo de PrestaShop 8 que automatiza el cambio del proxy DNS de Cloudflare durante los bloqueos de IP de LaLiga. Cuando el feed de [hayahora.futbol](https://hayahora.futbol/) detecta bloqueo activo, el modulo cambia los registros DNS seleccionados a **DNS Only** para que tu tienda siga accesible, y restaura el modo **Proxied** cuando el bloqueo termina.

## Novedades en v2.0.0

- **Tres metodos de autenticacion Cloudflare** — Global API Key, API Token (con scope) o Account Token
- **Deteccion de datos obsoletos** — ignora datos del feed mas antiguos que un umbral configurable (por defecto 6 h)
- **Minimo de ISPs bloqueados** — solo activa el bypass cuando varios ISPs reportan bloqueo (por defecto 2)
- **Override forzar proxy off** — opcion para desactivar el proxy en todos los dominios cuando hay bloqueo general
- **Notificaciones por email** — envia alertas en texto plano cuando cambia el estado (bypass activado/desactivado)
- **UI Simple / Avanzada** — alterna entre una vista limpia y controles tecnicos completos
- **Filtros por tipo de registro DNS** — filtra la tabla DNS por A, AAAA o CNAME
- **IPs publicas del servidor** — muestra las IPs de salida de tu servidor directamente en el panel
- **Diagnostico del feed** — inspecciona los datos brutos del feed, obsolescencia y extraccion de ISPs
- **Gestion de cache de datos** — limpia la cache local del feed desde el panel de administracion
- **Proteccion CSRF** — todas las llamadas AJAX validadas con tokens de administracion de PrestaShop
- **Anonimizacion de IPs** — logging compatible con GDPR (IPs enmascaradas en todas las entradas del log)
- **Nombres de log indescifrables** — los archivos de log usan un secreto aleatorio en el nombre
- **Desinstalacion limpia** — opcion para eliminar todos los datos del modulo al desinstalar
- **Migracion automatica** — migracion automatica desde v1.x sin pasos manuales

## Instalacion

1. Descarga la ultima version desde [GitHub Releases](https://github.com/SurMod/cffootballbypass/releases)
2. En el Back Office, ve a **Modulos > Gestor de modulos > Subir un modulo** y sube el ZIP
3. Activa **CF Football Bypass** y configuralo en **Modulos > CF Football Bypass**

## Requisitos

- PrestaShop 8.1 o superior
- PHP 8.1 o superior
- Cuenta activa en Cloudflare con permisos de gestion DNS

## Configuracion de API Cloudflare

El modulo soporta tres metodos de autenticacion:

| Metodo | Cabeceras enviadas | Alcance |
|---|---|---|
| **Global API Key** | `X-Auth-Email` + `X-Auth-Key` | Acceso completo a la cuenta |
| **API Token** | `Authorization: Bearer <token>` | Limitado a permisos seleccionados |
| **Account Token** | `Authorization: Bearer <token>` | Token a nivel de cuenta |

Por seguridad, se recomienda un **API Token** con scope `Zone > DNS > Edit`.

## Cron externo

El modulo incluye un endpoint cron para comprobaciones automaticas. Anade a tu crontab:

```
*/5 * * * * curl -s "https://tudominio.com/modules/cffootballbypass/cron.php?token=TU_TOKEN_CRON" > /dev/null
```

La URL del cron y el token se muestran en el Panel de Operaciones del modulo.

Si tu servidor bloquea el acceso directo a archivos del modulo, anade esto al `.htaccess` del directorio de modulos:

```apache
<Files "cron.php">
    <If "%{REQUEST_URI} =~ m#^/modules/cffootballbypass/cron\.php#">
        Require all granted
    </If>
</Files>
```

## Version Docker

Hay una version standalone en Docker (sin necesidad de PrestaShop) en [SurMod/cffootballbypass-docker](https://github.com/SurMod/cffootballbypass-docker).

## Creditos

- **Plugin original WordPress:** David Carrero ([@carrero](https://x.com/carrero)) — [carrero.es](https://carrero.es)
- **Adaptacion PrestaShop:** Jose Manuel Pedraja — [SurMod](https://surmod.es)
- **Datos del feed:** [hayahora.futbol](https://hayahora.futbol/)

> Este modulo ha sido adaptado de la version original para WordPress con permiso del autor y es completamente gratuito.

## Licencia

GPL-2.0-or-later — ver [LICENSE](LICENSE).
