# 📦 Handoff Técnico Frontend + Backend — Keystone Portal v4.4 (`SOL-0348`)

Este paquete contiene el código fuente completo y actualizado de la versión certificada en `DEV` y `QA` (`bdc708f`).

## 1. Estructura del Proyecto
- `src/` — Código fuente Frontend Next.js 14 (App Router, TypeScript, Tailwind CSS, motor WebGL 4K `KeystoneDigitalTunnelEngine`, Command Console `/admin`, Trust Center `/trust` y portales DeepShield `/shield/*`).
- `public/` — Assets estáticos, `.htaccess` perimetral y endpoints Backend PHP 8.2:
  - `public/api/lead.php` — Captura de leads B2B y escáner BEC en vivo.
  - `public/api/site_config.php` — Configuración pública y catálogo dinámico.
  - `public/api/track.php` — Telemetría de aperturas/clics.
  - `public/api/admin/*` — Núcleo `AdminCore` (`_core.php` con autodetección `DEV`/`QA`/`PROD`, RBAC, Rate Limiting, Anti-SSRF, CSRF Same-Origin), autenticación 2FA TOTP, Google SSO, SecOps, Growth Inbox y reportes PDF/CSV.
  - `public/api/shield/*` — API de custodia financiera OOB (192-bit token hash), webhook ERP y Red Teaming.
  - `public/api/trust/*` — Telemetría criptográfica TrustZero e insignia SVG dinámica.
  - `public/lib/fpdf/` — Librería base FPDF para generación de dosieres periciales.

## 2. Levantar el Frontend en Local
Requisitos: **Node.js 18+ / 20+**

```bash
npm install
npm run dev
```

Para generar el export estático de producción (`output: 'export'` -> carpeta `out/`):
```bash
npm run build
```
*(Al ejecutar `npm run build`, Next.js empaqueta automáticamente todo el frontend estático junto con `public/api/` y `public/.htaccess` dentro de `out/`).*

## 3. Conectar Frontend con Backend PHP en Local
Como Next.js compila a export estático (`out/`) y consume rutas relativas `/api/*.php`:
1. Puedes levantar un servidor PHP 8.2 local apuntando a `public/` (o a `out/` tras compilar):
   ```bash
   php -S localhost:8080 -t out
   ```
2. En entorno local, `AdminCore::detectEnvTier()` (`public/api/admin/_core.php`) detecta automáticamente modo desarrollo y crea/inicializa en caliente el esquema SQLite completo (tablas, índices y semillas base) sin requerir dumps manuales.
3. Las cabeceras de seguridad (`AdminCore::enforceSameOrigin()` y `AdminCore::safeHost()`) ya contemplan `localhost` y `127.0.0.1` para pruebas locales.
