# 🛡️ REGLAS DE WORKSPACE: SINNOTARIO (KEYSTONE ADVISORS)

> Aplica a cualquier tarea, modificación de código o despliegue en `Keystone Advisors/SinNotario/` o en el servidor productivo `sinnotario.cl` (`156.67.72.209`).

---

### 1. 🚨 Blindaje Anti-500 y Smoke Test Integral Obligatorio Post-Despliegue
- **Cero Tolerancia a Error 500 (Internal Server Error)**: Antes de dar por finalizada cualquier intervención o comunicar que una tarea está completada, es **ESTRICTAMENTE OBLIGATORIO** ejecutar una batería automatizada de smoke tests HTTP contra los endpoints clave de producción:
  ```bash
  # Batería obligatoria de verificación 200 OK:
  curl -s -I "https://sinnotario.cl/" | grep -E "HTTP/2 200|HTTP/1.1 200"
  curl -s -I "https://sinnotario.cl/redactar.php" | grep -E "HTTP/2 200|HTTP/1.1 200"
  curl -s -I "https://sinnotario.cl/subir-documento.php" | grep -E "HTTP/2 200|HTTP/1.1 200"
  curl -s -I "https://sinnotario.cl/firmar.php" | grep -E "HTTP/2 200|HTTP/1.1 200"
  curl -s -I "https://sinnotario.cl/admin.php" | grep -E "HTTP/2 200|HTTP/1.1 200"
  curl -s -I "https://sinnotario.cl/verificar.php" | grep -E "HTTP/2 200|HTTP/1.1 200"
  curl -s -I "https://sinnotario.cl/empresas.php" | grep -E "HTTP/2 200|HTTP/1.1 200"
  curl -s "https://sinnotario.cl/api/index.php?action=get_pricing_settings" | grep '"success": true'
  ```
- **Si CUALQUIERA de estos tests no responde HTTP 200 OK**, la tarea NO puede considerarse cerrada. Se debe investigar, corregir y validar de inmediato.

---

### 2. 📂 Aislamiento Estricto de Rutas SCP / SSH (Anti-Sobrescritura Accidental)
- **Separación de Archivos Raíz vs Subdirectorios**:
  - `api/index.php` es el enrutador de la API y DEBE copiarse exclusivamente a `/public_html/api/index.php`.
  - `index.php` es la portada principal (Landing Page) y reside en `/public_html/index.php`.
  - **Queda estrictamente prohibido** ejecutar comandos SCP globales con múltiples destinos mezclados (ej. `scp file1 api/index.php host:/public_html/`) que puedan provocar que el archivo de la API sobrescriba accidentalmente la portada raíz.
  - Cada archivo de subdirectorio (`api/`, `src/`, `config/`, `database/`) debe transferirse con su ruta de destino absoluta explícita.

---

### 3. 🔍 Verificación Sintáctica (PHP Lint) Pre-Despliegue
- Todo archivo PHP modificado debe verificarse sintácticamente en el servidor remoto antes y después del traspaso:
  ```bash
  ssh -p 65002 u705937345@156.67.72.209 "php -l /ruta/archivo.php"
  ```
- No se permite dejar archivos con errores de sintaxis en el servidor.

---

### 4. 🔢 Control de Correlativos y Trazabilidad (`SOL-XXXX`)
- Toda intervención, mejora o corrección debe quedar documentada con su correlativo consecutivo en:
  - `Keystone Advisors/SinNotario/CONTROL_CORRELATIVOS.md`
  - `Keystone Advisors/SinNotario/BITACORA_PRODUCCION.md`
- Mantener la sincronización con el repositorio oficial Git (`main -> origin/main`).

