# 🛡️ DISEÑO FUNCIONAL Y ARQUITECTURA DE CIBERSEGURIDAD
## KEYGUARD DEFENSE SUITE: RED TEAMING B2B, TELEMETRÍA DE CLICS, SIMULACIÓN INSTITUCIONAL & EVALUACIÓN EJECUTIVA DMARC

- **Identificadores de Solución:** `SOL-0186` (Planes & Landing), `SOL-0190` (Suite Operativa & Dosier PDF), `SOL-0191` (Clonación SII), `SOL-0192` (Clonación Bancaria Santander/LarrainVial), `SOL-0193` (Insumos del Cliente), `SOL-0194` (Manual SOP Quick Win), `SOL-0195` (CRUD Integrantes & Edición)
- **Ambiente Central:** `https://www.keystoneadvisors.cl/admin/?tab=redteaming` & `admin/?tab=dmarc`
- **Módulos Soportados:** Red Teaming Asistido por IA, DMARC Watchdog Multi-Tenant, Generador de Dosieres Ley 21.459 y Repositorio de Insumos Contextuales.
- **Autor:** Equipo de Ciberdefensa & Arquitectura Keystone Advisors
- **Fecha:** Noviembre 2026
- **Estado:** Implementado, Validado y Operativo en Producción

---

### 1. OBJETIVO DEL ECOSISTEMA

El subsistema **KeyGuard Cyber Defense Suite** provee una plataforma integral de ciberseguridad ofensiva inocua y de diagnóstico institucional para empresas clientes:
1. **Red Teaming Asistido por IA:** Ejecución de campañas de ingeniería social simulada (BEC, facturas falsas, notificaciones oficiales) con hiper-contexto empresarial.
2. **Repositorio de Insumos Contextuales del Cliente:** Almacenamiento seguro de comunicaciones oficiales reales (PDFs de bancos, facturas, resoluciones tributarias) para evitar señuelos genéricos y alcanzar un 90%+ de credibilidad operativa.
3. **Telemetría y Landing Educativa Just-in-Time:** Detección instantánea de clics por colaborador, marcado de eventos en SQLite y redirección automática a una microcápsula formativa de 45 segundos en pantalla.
4. **Dosier Pericial Ejecutivo con Sellado Criptográfico:** Emisión de reportes directivos en PDF con hash SHA-256 para auditoría de cumplimiento bajo la Ley Marco de Ciberseguridad (Ley 21.663) y Ley de Delitos Informáticos (Ley 21.459).
5. **Servicio Quick Win (DMARC Strict & Surface Scanner):** Mapeo de identidad de correo saliente mediante ingesta RUA (Google/Microsoft) y cierre de políticas DNS a `p=reject`.

---

### 2. ARQUITECTURA DE DATOS Y COMPONENTES TÉCNICOS

#### A. Modelo Relacional SQLite (`data/leads_keystone.sqlite`)
- `redteam_campaigns`: Cabecera de campañas contratadas por empresa cliente (plan, dominio objetivo, vectores habilitados, estado, puntaje de vulnerabilidad).
- `redteam_targets`: Nómina de colaboradores evaluados (nombre, correo corporativo, cargo, departamento, token único SHA-256 de simulación, estado: `PENDING`, `SENT`, `CLICKED`).
- `redteam_events`: Telemetría forense de clics (IP, User-Agent, fecha/hora exacta del clic, payload simulado).
- `redteam_client_artifacts`: Insumos y correos tipo cargados por el cliente (PDF, PNG, EML, notas tácticas y categoría: bancaria, tributaria, proveedores).

#### B. Componentes Backend y Motor de Simulación
- `api/RedTeamingService.php`: Orquestador de campañas, importador de nóminas CSV y calculador de métricas.
- `api/BankLureTemplates.php`: Motor de renderizado visual réplica Banco Santander (OfficeBanking USD, Cartola Cifrada) y LarraínVial ($15.9M).
- `api/SiiLureTemplates.php`: Motor de renderizado visual réplica SII (Carpeta Tributaria, Resolución Exenta RAV, F1955 Inconsistencias Bancarias).
- `api/RedTeamDossierPdfGenerator.php`: Generador del dosier pericial en PDF con FPDF y hash SHA-256.
- `shield/rt.php`: Endpoint público de telemetría y Just-in-Time Learning.
- `admin/api/redteam_api.php`: API REST para previsualización, edición rápida de colaboradores, carga/eliminación de insumos y despacho SMTP.

---

### 3. MANUALES OPERATIVOS Y DOCUMENTACIÓN FORMAL
- `docs/MANUAL_OPERATIVO_SOP_DMARC_QUICKWIN.docx`: Manual estándar de procedimiento (SOP) para analistas e ingenieros Keystone bajo norma corporativa Arial y flujo de 7 días.

