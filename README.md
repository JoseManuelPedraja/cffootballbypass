![PrestaShop](https://badgen.net/badge/PrestaShop/8.1%2B/blue)
![PHP](https://badgen.net/badge/PHP/8.1%2B/8892BF)
![Latest Release](https://badgen.net/github/release/SurMod/cffootballbypass)
![License](https://badgen.net/github/license/SurMod/cffootballbypass)
![GitHub Downloads](https://badgen.net/github/assets-dl/SurMod/cffootballbypass)
![Cloudflare](https://badgen.net/badge/Cloudflare/API%20v4/F38020)
![Made in Spain](https://badgen.net/badge/made%20in/Spain%20🇪🇸/red)
![La Liga](https://badgen.net/badge/LaLiga/Bypass/orange)

# CF Football Bypass for PrestaShop

PrestaShop 8 module that automatically toggles Cloudflare DNS proxy during La Liga IP blocks. When the feed at [hayahora.futbol](https://hayahora.futbol/) detects active blocking, the module switches selected DNS records to **DNS Only** so your store stays reachable, then restores **Proxied** mode once the block lifts.

## What's new in v2.1.0

- **Three Cloudflare auth methods** — Global API Key, API Token (scoped), or Account Token
- **Feed staleness detection** — ignores data older than a configurable threshold (default 6 h)
- **Min ISPs blocked** — only triggers bypass when multiple ISPs report blocks (default 2)
- **Force proxy-off override** — optionally disable proxy for all domains when any general block is detected
- **Email notifications** — sends plain-text alerts on state changes (bypass activated/deactivated)
- **Simple / Advanced UI** — toggle between a clean overview and full technical controls
- **DNS record type filters** — filter the DNS table by A, AAAA, or CNAME
- **Server outgoing IPs** — shows your server's public IPs directly in the panel
- **Feed diagnostics** — inspect raw feed data, staleness, and ISP extraction
- **Data cache management** — clear local feed cache from the admin panel
- **CSRF protection** — all AJAX calls validated with PrestaShop admin tokens
- **IP anonymization** — GDPR-compliant logging (IPs masked in all log entries)
- **Unguessable log filenames** — log files use a random secret in the filename
- **Clean uninstall** — option to delete all module data on uninstall
- **Upgrade migration** — automatic migration from v1.x with zero manual steps

## Installation

1. Download the latest release from [GitHub Releases](https://github.com/SurMod/cffootballbypass/releases)
2. In the Back Office, go to **Modules > Module Manager > Upload a module** and upload the ZIP
3. Activate **CF Football Bypass** and configure it under **Modules > CF Football Bypass**

## Requirements

- PrestaShop 8.1 or higher
- PHP 8.1 or higher
- Active Cloudflare account with DNS management permissions

## Cloudflare API configuration

The module supports three authentication methods:

| Method | Headers sent | Scope |
|---|---|---|
| **Global API Key** | `X-Auth-Email` + `X-Auth-Key` | Full account access |
| **API Token** | `Authorization: Bearer <token>` | Scoped to selected permissions |
| **Account Token** | `Authorization: Bearer <token>` | Account-level token |

For security, an **API Token** scoped to `Zone > DNS > Edit` is recommended.

## External cron

The module includes a cron endpoint for automated checks. Add to your crontab:

```
*/5 * * * * curl -s "https://yourdomain.com/modules/cffootballbypass/cron.php?token=YOUR_CRON_TOKEN" > /dev/null
```

The cron URL and token are shown in the module's Operation Panel.

If your server blocks direct access to module files, add this to `.htaccess` in the modules directory:

```apache
<Files "cron.php">
    <If "%{REQUEST_URI} =~ m#^/modules/cffootballbypass/cron\.php#">
        Require all granted
    </If>
</Files>
```

## Docker version

A standalone Docker version (no PrestaShop required) is available at [SurMod/cffootballbypass-docker](https://github.com/SurMod/cffootballbypass-docker).

## Credits

- **Original WordPress plugin:** David Carrero ([@carrero](https://x.com/carrero)) — [carrero.es](https://carrero.es)
- **PrestaShop adaptation:** Jose Manuel Pedraja — [SurMod](https://surmod.es)
- **Feed data:** [hayahora.futbol](https://hayahora.futbol/)

> This module was adapted from the original WordPress version with the author's permission and is completely free.

## License

GPL-2.0-or-later — see [LICENSE](LICENSE).
