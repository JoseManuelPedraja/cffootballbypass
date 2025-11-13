![PrestaShop](https://badgen.net/badge/PrestaShop/8.x/blue)
![PHP](https://badgen.net/badge/PHP/8.0%20%7C%208.1/8892BF)
![Último Release](https://badgen.net/github/release/josemanuelpedraja/cf-football-bypass-prestashop?include_prereleases)
![Licencia](https://badgen.net/github/license/JoseManuelPedraja/cf-football-bypass-prestashop)
![Descargas](https://img.shields.io/github/downloads/JoseManuelPedraja/cf-football-bypass-prestashop/total)
![Estrellas](https://badgen.net/github/stars/JoseManuelPedraja/cf-football-bypass-prestashop)
![Forks](https://badgen.net/github/forks/JoseManuelPedraja/cf-football-bypass-prestashop)


# ⚽ CF Football Bypass para PrestaShop

Módulo de PrestaShop que automatiza el cambio entre los modos **Proxied** y **DNS Only** en Cloudflare cuando se aplican bloqueos masivos durante partidos de fútbol.  

El módulo obtiene el feed de [hayahora.futbol](https://hayahora.futbol/) y activa/desactiva los registros DNS seleccionados para mantener accesible tu tienda legítima, con un periodo de enfriamiento configurable antes de reactivar Cloudflare.  

---

## 🚀 Instalación rápida

1. Descarga el archivo ZIP desde [GitHub](https://github.com/JoseManuelPedraja/cf-football-bypass-prestashop/releases).  
2. Copia la carpeta `cffootballbypass` en el directorio de módulos de tu PrestaShop:  
3. Desde el **Back Office** de PrestaShop, ve a **Módulos > Gestor de módulos** y activa **CF Football Bypass**.  
4. Configura el módulo en **Parámetros Avanzados > CF Football Bypass**, donde podrás ajustar:  
- El intervalo de comprobación  
- El periodo de enfriamiento tras desactivar Cloudflare  
- Los registros DNS que quieres gestionar  

---

## ⚙️ Requisitos

- PrestaShop 8 o superior  
- Cuenta activa en Cloudflare  
- Acceso a API de Cloudflare con permisos para gestionar DNS  

---

## 👨‍💻 Autor original y soporte WordPress

- **Autor original:** David Carrero ([@carrero](https://x.com/carrero))  
- **Sitio web:** [carrero.es](https://carrero.es)  
- **Contacto rápido:** [carrero.es/contacto](https://carrero.es/contacto/)  

> ⚠️ Nota: Este módulo ha sido adaptado de su versión original para WordPress a PrestaShop con permiso del autor y es completamente gratuito.


---

## 📖 Documentación

Si quieres usar el **cron externo**, sigue estos pasos:

1. Añade el siguiente código a tu archivo `.htaccess` ubicado en la carpeta `var/www/public_html/modules/`:

```apache
<Files "cron.php">
    <If "%{REQUEST_URI} =~ m#^/modules/cffootballbypass/cron\.php#">
        Require all granted
    </If>
</Files>
