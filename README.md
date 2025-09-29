![PrestaShop](https://badgen.net/badge/PrestaShop/8.x/blue)
![PHP](https://badgen.net/badge/PHP/8.0%20%7C%208.1/8892BF)
![Último Release](https://badgen.net/github/release/JoseManuelPedraja/cf-football-bypass-prestashop?include_prereleases)
![Licencia](https://badgen.net/github/license/JoseManuelPedraja/cf-football-bypass-prestashop)
![Descargas](https://badgen.net/github/assets-dl/JoseManuelPedraja/cf-football-bypass-prestashop)
![Estrellas](https://badgen.net/github/stars/JoseManuelPedraja/cf-football-bypass-prestashop)
![Forks](https://badgen.net/github/forks/JoseManuelPedraja/cf-football-bypass-prestashop)


# CF Football Bypass

PrestaShop plugin that automates switching between **Proxied** and **DNS Only** mode in Cloudflare when massive blocks are enforced during football matches.  

It fetches the feed from [hayahora.futbol](https://hayahora.futbol/) and enables/disables the selected records to keep your legitimate site accessible, with a configurable cooldown period before re-enabling Cloudflare.

---

## 🚀 Quick Installation

1. Download the ZIP from [GitHub](https://github.com/dcarrero/cf-football-bypass).  
2. Upload the `cf-football-bypass` folder to `wp-content/plugins/` (it will end up as `plugins/cf-football-bypass/`).  
3. Activate the plugin from **Plugins > Installed Plugins**.  
4. Configure your Cloudflare credentials under **Settings > CF Football Bypass**, adjust:
   - The check interval  
   - The cooldown period after disabling Cloudflare  
   - The DNS records to manage  

---

## 👨‍💻 Original Author & WordPress Support

- **Author:** David Carrero ([@carrero](https://x.com/carrero))  
- **Website:** [carrero.es](https://carrero.es)  
- **Quick contact:** [carrero.es/contacto](https://carrero.es/contacto/)  

---

## 📖 Documentation

More details, FAQs, and extended guide can be found in the [readme.txt](readme.txt) file.
