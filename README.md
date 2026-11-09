# 🌐 Keystone Advisors Global · Portal Corporativo & Growth Engine

Repositorio oficial del portal corporativo de **Keystone Advisors** (`https://www.keystoneadvisors.cl`).

---

## 🚀 Arquitectura y Componentes Principales

1. **Portal Web Corporativo & Consultoría:**
   - Servicios de Consultoría en **Salesforce (Financial Services Cloud, Core CRM, LWC)** para Banca, Seguros y Fintech.
   - Solución perimetral **DeepShield** (Prevención de Fraude BEC, DMARC Enforcement y Ley N° 21.459).
   - Arquitectura Cloud Empresarial y Modernización Core.

2. **Keystone Growth Engine (`SOL-0172` / `SOL-0175`):**
   - Telemetría en tiempo real con `assets/js/growth-tracker.js` (< 4 KB).
   - Inferencia de intención de compra (*Pre-Purchase Buyer Intent Scoring* de 0 a 100).
   - Base de datos dedicada SQLite ACID (WAL) en `data/marketing_keystone.sqlite`.
   - Motor creativo con IA multimodal a costo $0 USD:
     - **Groq Cloud LPU (`qwen/qwen3.8-27b`):** Inferencia ultra rápida (< 800ms) para artículos B2B y copys de alta conversión.
     - **Google Gemini Flash:** Fallback de alta resiliencia.
     - **Cloudflare Workers AI (`@cf/black-forest-labs/flux-1-schnell`):** Banners corporativos 1024x1024 px.

3. **Publicador Oficial Directo en LinkedIn Empresa (`SOL-0173` / `SOL-0175`):**
   - Integración oficial con LinkedIn Community Management API v2.
   - Despacho en 1 clic a la Página de Empresa: **Keystone Advisors Global** (ID `105863140`).
   - Autoconstrucción y prefijado de enlaces con parámetros UTM de rastreo hacia cualquier landing page.
   - Editor de contenido en vivo antes del despacho a la red.

4. **KeyGuard & DMARC Watchdog:**
   - Ingesta y análisis automatizado de reportes agregados DMARC RUA (Google, Microsoft 365, Yahoo).
   - Generación de Dosieres Periciales Criptográficos en PDF con sellado SHA-256 para PDI / Fiscalía.
   - Detección de ataques de suplantación de identidad y spoofing.

---

## 📂 Estructura del Proyecto

```
├── admin/                     # Consola ejecutiva con control de acceso (RBAC) y pestaña Marketing
│   ├── index.php              # Panel de administración, métricas y modales IA / LinkedIn
│   ├── oauth_linkedin.php     # Conector y receptor OAuth 2.0 LinkedIn Company
│   └── oauth_google.php       # Conector OAuth 2.0 Google Ads API
├── api/                       # Endpoints REST y servicios centrales backend
│   ├── KeystoneGrowthService.php    # Motor de telemetría y llamadas a Groq/Gemini/FLUX
│   ├── KeystoneLinkedInPublisher.php# Cliente oficial de publicación en LinkedIn API
│   ├── DmarcWatchdogService.php     # Procesamiento forense de correos y DMARC
│   ├── Database.php                 # Conexión PDO SQLite
│   └── marketing/track.php          # Receptor de touchpoints y telemetría UX
├── assets/                    # Assets estáticos de diseño y frontend
│   ├── css/style.css          # Estilos corporativos y componentes responsivos
│   └── js/growth-tracker.js   # Script cliente de tracking de micro-interacciones
├── docs/                      # Documentación de arquitectura y diseño funcional
│   └── DISENO_FUNCIONAL_KEYSTONE_GROWTH_ENGINE.md
├── index.php                  # Página principal de cara al cliente (Home Keystone Advisors)
└── privacidad.html            # Política de Privacidad pública (Ley N° 19.628 / GDPR)
```

---

## 🔒 Gobierno y Seguridad
- Todos los despliegues a producción se auditan y correlacionan formalmente bajo la nomenclatura `SOL-XXXX` en `CONTROL_CORRELATIVOS.md` y `BITACORA_PRODUCCION.md`.
- Conforme a la Ley N° 19.628 de Protección de Datos Personales, las direcciones IP se anonimizan mediante hashing SHA-256 con salt corporativo.
