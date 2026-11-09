# 🚀 DISEÑO FUNCIONAL Y ARQUITECTURA DE DATOS
## KEYSTONE GROWTH ENGINE: MOTOR CENTRALIZADO DE MARKETING, TELEMETRÍA UX E INFERENCIA DE INTENCIÓN DE COMPRA B2B

- **Identificador de Solución:** `SOL-0166` (Arquitectura Inicial) / `SOL-0172` (Growth Engine & IA Costo $0) / `SOL-0173` (Publicador Orgánico LinkedIn Empresa & Google Ads OAuth)
- **Ambiente Central:** `https://www.keystoneadvisors.cl/admin/?tab=marketing`
- **Sitios Soportados (Multi-Tenant):** `keystoneadvisors.cl`, `sinnotario.cl`, `keyhub.keystoneadvisors.cl` y futuros portales corporativos.
- **Autor:** Equipo de Arquitectura de Software & Ciberseguridad Keystone Advisors
- **Fecha:** Noviembre 2026
- **Estado:** Implementado y Operativo en Producción (Hostinger Cloud `156.67.72.209`)

---

### 1. OBJETIVO Y VISIÓN DE LA SOLUCIÓN

Construir un ecosistema propio de **Marketing Intelligence & Multi-Site Attribution** alojado centralmente en la infraestructura de **Keystone Advisors**, capaz de:
1. **Emitir y Administrar Campañas Multicanal:** Generación estandarizada de enlaces publicitarios para Google Ads, LinkedIn Campaign Manager, Meta Ads y canales directos para cualquiera de nuestras unidades de negocio.
2. **Atribución Universal y Persistencia First/Last Touch:** Reconocer con precisión milimétrica de qué anuncio, palabra clave y red publicitaria provino cada cliente, manteniendo la trazabilidad durante ventanas de decisión de 30 a 90 días.
3. **Fusión con Telemetría UX en Tiempo Real:** Unificar la telemetría ya desarrollada en *SinNotario.cl* y *Keystone Advisors* para registrar micro-interacciones dentro de cada sesión (tiempo en tablas de precios, términos buscados, selección de textos legales, clics de fricción y simuladores).
4. **Motor de Inferencia de Intención Pre-Compra (*Pre-Purchase Intent Scoring*):** Calcular un puntaje dinámico (0 a 100) que califique qué busca el usuario antes de comprar, detectando objeciones latentes (duda de precio, duda de validez jurídica o duda de escala técnica).
5. **Panel Central de Rendimiento de Inversión (ROAS / CAC):** Proporcionar a la Dirección de Keystone un panel ejecutivo único que compare el retorno financiero real de cada anuncio y recomiende algorítmicamente dónde aumentar o reasignar presupuesto publicitario.

---

### 2. ARQUITECTURA GENERAL DEL SISTEMA

```
+--------------------------------------------------------------------------------------------------+
|                                    REDES PUBLICITARIAS EXTERNAS                                   |
|   [Google Ads Search/Display]     [LinkedIn Campaign Manager B2B]     [Meta / Instagram Ads]     |
|          (gclid / UTM)                     (li_fat_id / UTM)                    (fbclid / UTM)    |
+-------------------------------------------------+------------------------------------------------+
                                                  | Clic con Enlace Parametrizado
                                                  v
+--------------------------------------------------------------------------------------------------+
|                                  RED DE SITIOS WEB (MULTI-TENANT)                                 |
|                                                                                                  |
|    +---------------------------+  +---------------------------+  +---------------------------+   |
|    |    keystoneadvisors.cl    |  |       sinnotario.cl       |  |  keyhub.keystoneadvisors  |   |
|    | (DeepShield, SF, Cloud)   |  | (Trámites, Firma FEA)     |  | (SaaS Control Horas/Kanban)|  |
|    +-------------+-------------+  +-------------+-------------+  +-------------+-------------+   |
|                  |                              |                              |                 |
|                  +------------------------------+------------------------------+                 |
|                                                 | Inyección Script Ligero                        |
|                                                 v                                                |
|                                   [keystone-growth-tracker.js]                                   |
|                            (Captura UTM, Click IDs, Dwell-Time, Scroll,                          |
|                             Selección de Texto, Búsquedas y Rage Clicks)                         |
+-------------------------------------------------+------------------------------------------------+
                                                  | Beacon Asíncrono / JSON POST
                                                  v
+--------------------------------------------------------------------------------------------------+
|                       ENDPOINT CENTRAL: keystoneadvisors.cl/api/marketing/track.php              |
|                             - Validación de Orígenes CORS Habilitados                            |
|                             - Asignación de Cookie Firme Multi-Dominio (ks_growth_uid)           |
|                             - Extracción y Normalización de Parámetros Publicitarios             |
+-------------------------------------------------+------------------------------------------------+
                                                  | Ingesta ACID WAL
                                                  v
+--------------------------------------------------------------------------------------------------+
|                         BASE DE DATOS CENTRALIZADA: data/marketing_keystone.sqlite                |
|       [mkt_tenants] -> [mkt_campaigns] -> [mkt_touchpoints] -> [mkt_ux_intent] -> [mkt_conversions]
+-------------------------------------------------+------------------------------------------------+
                                                  | Análisis e Inferencia
                                                  v
+--------------------------------------------------------------------------------------------------+
|                   CONSOLA CENTRAL EJECUTIVA: keystoneadvisors.cl/admin/?tab=marketing            |
|       - 1. Generador de Enlaces Publicitarios (Link Builder con UTMs Estandarizadas)            |
|       - 2. Matriz de Retorno ROAS & Costo de Adquisición (Google vs LinkedIn vs Meta)            |
|       - 3. Radar de Inferencia Pre-Compra (Micro-Señales de Clientes Potenciales)               |
|       - 4. Recomendador Algorítmico de Inversión ("Dónde poner la pauta")                        |
+--------------------------------------------------------------------------------------------------+
```

---

### 3. ARQUITECTURA DE DATOS (ESQUEMA SQLITE ACID)

La base de datos operará en modo **WAL (Write-Ahead Logging)** bajo `data/marketing_keystone.sqlite` en el servidor central de Keystone:

```sql
-- 1. Tenants / Unidades de Negocio
CREATE TABLE IF NOT EXISTS mkt_tenants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT UNIQUE NOT NULL,               -- 'keystone', 'sinnotario', 'keyhub'
    name TEXT NOT NULL,                      -- 'Keystone Advisors', 'SinNotario.cl', 'KeyHub Suite'
    primary_domain TEXT NOT NULL,            -- 'https://www.keystoneadvisors.cl'
    target_currency TEXT DEFAULT 'USD',      -- 'USD' o 'CLP'
    is_active INTEGER DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 2. Registro Central de Campañas y Anuncios
CREATE TABLE IF NOT EXISTS mkt_campaigns (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_slug TEXT NOT NULL,               -- FK a mkt_tenants
    platform TEXT NOT NULL,                  -- 'google_ads', 'linkedin_ads', 'meta_ads', 'newsletter'
    campaign_name TEXT NOT NULL,             -- 'bec_fraud_cfo_q4'
    ad_group TEXT,                           -- 'cfo_finanzas_chile'
    ad_id TEXT,                              -- 'ad_video_deepfake_01'
    ad_headline TEXT,                        -- Título del anuncio pautado
    budget_allocated REAL DEFAULT 0,         -- Presupuesto asignado (USD o CLP)
    status TEXT DEFAULT 'ACTIVE',            -- 'ACTIVE', 'PAUSED', 'COMPLETED'
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 3. Bitácora de Clics y Touchpoints (Primer Impacto & Último Clic)
CREATE TABLE IF NOT EXISTS mkt_touchpoints (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    visitor_uid TEXT NOT NULL,               -- UUID persistente en cookie/localStorage (ks_growth_uid)
    tenant_slug TEXT NOT NULL,               -- 'keystone', 'sinnotario', 'keyhub'
    session_id TEXT NOT NULL,                -- Identificador único de sesión web
    platform TEXT NOT NULL,                  -- 'google_ads', 'linkedin_ads', etc.
    campaign_name TEXT,
    utm_medium TEXT,                         -- 'cpc', 'cpm', 'social_paid'
    utm_term TEXT,                           -- Palabra clave de búsqueda
    utm_content TEXT,                        -- Identificador creativo de anuncio
    gclid TEXT,                              -- Google Click ID
    li_fat_id TEXT,                          -- LinkedIn First-Party Tracking ID
    fbclid TEXT,                             -- Facebook Click ID
    landing_url TEXT NOT NULL,               -- URL exacta de entrada
    referrer_url TEXT,                       -- Referencia previa del navegador
    ip_hash TEXT NOT NULL,                   -- Hash SHA-256 de IP + Salt (GDPR/Habeas Data compatible)
    user_country TEXT,                       -- 'CL', 'PE', 'PA', 'DO', 'CA'
    device_type TEXT,                        -- 'MOBILE', 'DESKTOP', 'TABLET'
    browser TEXT,
    is_first_touch INTEGER DEFAULT 0,        -- 1 si fue la primera vez en la historia del usuario
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_mkt_touch_visitor ON mkt_touchpoints(visitor_uid);
CREATE INDEX IF NOT EXISTS idx_mkt_touch_campaign ON mkt_touchpoints(tenant_slug, campaign_name);

-- 4. Telemetría UX & Inferencia de Intención de Compra (In-Session Micro-Behaviors)
CREATE TABLE IF NOT EXISTS mkt_ux_intent (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id TEXT NOT NULL,
    visitor_uid TEXT NOT NULL,
    tenant_slug TEXT NOT NULL,
    page_url TEXT NOT NULL,
    dwell_time_pricing_sec INTEGER DEFAULT 0,-- Segundos acumulados observando tablas de precios
    dwell_time_total_sec INTEGER DEFAULT 0,  -- Tiempo total de permanencia en la página
    max_scroll_depth_pct INTEGER DEFAULT 0,  -- Porcentaje máximo de scroll vertical (0-100)
    search_queries_entered TEXT,             -- Términos tipeados en buscadores internos (JSON Array)
    faq_questions_clicked TEXT,              -- Preguntas frecuentes expandidas (JSON Array)
    copied_texts TEXT,                       -- Fragmentos copiados al portapapeles (RUT, Cláusulas, etc.)
    simulator_interactions INTEGER DEFAULT 0,-- Veces que interactuó con calculadoras o sliders
    rage_clicks_detected INTEGER DEFAULT 0,  -- Clics repetitivos de frustración en zonas muertas
    inferred_intent_category TEXT,           -- 'PRICE_SENSITIVE', 'LEGAL_VALIDITY', 'SECURITY_URGENCY'
    intent_score INTEGER DEFAULT 0,          -- Puntuación algorítmica de intención (0 a 100)
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_mkt_intent_visitor ON mkt_ux_intent(visitor_uid);

-- 5. Conversiones Reales y Retorno Financiero (MQL, SQL, Closed-Won)
CREATE TABLE IF NOT EXISTS mkt_conversions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    visitor_uid TEXT NOT NULL,
    session_id TEXT NOT NULL,
    tenant_slug TEXT NOT NULL,
    conversion_type TEXT NOT NULL,           -- 'MICRO_SCAN' (Diagnóstico), 'LEAD_FORM', 'CHECKOUT_WON'
    lead_email TEXT,                         -- Correo registrado si hubo formulario
    order_code TEXT,                         -- Código de orden si aplica (ej. 'DS-7034')
    revenue_amount REAL DEFAULT 0,           -- Dinero real ingresado (USD o CLP)
    currency TEXT DEFAULT 'USD',
    attributed_first_campaign TEXT,          -- Primera campaña de origen (First-Touch)
    attributed_first_platform TEXT,          -- Primera red publicitaria
    attributed_last_campaign TEXT,           -- Última campaña de origen (Last-Touch)
    attributed_last_platform TEXT,           -- Última red publicitaria
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_mkt_conv_tenant ON mkt_conversions(tenant_slug);

-- 6. Configuración de Conectores y Plataformas Externas
CREATE TABLE IF NOT EXISTS mkt_settings (
    setting_key TEXT PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 7. Histórico de Piezas Generadas y Publicaciones Despachadas
CREATE TABLE IF NOT EXISTS mkt_ai_creatives (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_slug TEXT NOT NULL,
    campaign_goal TEXT NOT NULL,
    target_audience TEXT NOT NULL,
    headline TEXT NOT NULL,
    ad_copy TEXT NOT NULL,
    image_url TEXT,
    visual_prompt TEXT,
    linkedin_post_urn TEXT,                  -- 'urn:li:share:...' al publicarse
    linkedin_status TEXT DEFAULT 'DRAFT',    -- 'DRAFT', 'PUBLISHED', 'FAILED'
    linkedin_published_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

---

### 4. ESPECIFICACIÓN DE EVENTOS DE TELEMETRÍA UX A CAPTURAR

El script cliente `keystone-growth-tracker.js` se ejecuta de forma asíncrona sin bloquear el renderizado ni afectar métricas Web Vitals (LCP/FID). Captura de forma transparente:

| Evento UX | Selector / Disparador Técnico | Inferencia de Negocio |
| :--- | :--- | :--- |
| **`PRICING_TABLE_DWELL`** | Sensor de intersección (`IntersectionObserver`) sobre `#planes-deepshield`, `#planes-keyhub` o tarifas SinNotario > 20s. | **Objeción Presupuestaria / Comparativa:** El visitante evalúa capacidad de pago y escalabilidad. |
| **`LEGAL_FAQ_OPEN`** | Clic en acordeón de validez legal, Ley 21.459 o fe pública notarial. | **Objeción Jurídica:** Busca garantías de respaldo institucional antes de comprometerse. |
| **`SEARCH_QUERY_LOG`** | Input debounce en buscadores de trámites o servicios de consultoría. | **Necesidad Específica:** Revela exactamente el dolor no resuelto que motivó su clic en el anuncio. |
| **`CLIPBOARD_COPY`** | Evento `copy` sobre textos con datos técnicos, teléfonos de contacto o cuentas bancarias. | **Intención de Validación / Derivación Interna:** El usuario está consultando con un socio o jefatura. |
| **`SIMULATOR_TWEAK`** | Modificación de sliders de usuarios en KeyHub o firmas en SinNotario. | **Dimensionamiento Comercial:** Revela el tamaño de la empresa antes de solicitar cotización. |
| **`RAGE_CLICK`** | > 3 clics en menos de 1 segundo sobre elementos estáticos o botones lentos. | **Fricción Pre-Abandono:** Señal de frustración que puede mitigarse con asistencia reactiva. |

---

### 5. ALGORITMO DE INFERENCIA PRE-COMPRA (*BUYER INTENT SCORE*)

El backend evalúa la sesión y calcula un índice entre **0 y 100 puntos**:

$$\text{Intent Score} = (\text{Pricing Dwell} \times 0.35) + (\text{Scroll Depth} \times 0.20) + (\text{FAQ/Legal} \times 0.20) + (\text{Simulator/Copy} \times 0.25) - (\text{Rage Factor} \times 0.15)$$

- **0 - 30 Puntos (Curioso / Tráfico Frío):** Sesión superficial. No justificar inversión en retargeting agresivo.
- **31 - 70 Puntos (Prospecto en Evaluación):** Alta lectura y búsqueda técnica. Alimentar audiencias de retargeting con casos de éxito y garantías.
- **71 - 100 Puntos (Comprador Inminente):** Permanencia prolongada en precios, copiado de datos de contacto o inicio de formulario. El anuncio que generó este usuario es clasificado como **"High-Performing Ad"**.

---

### 6. MÓDULO CREATIVO MULTIMODAL CON IA: GENERADOR DE AVISOS, IMÁGENES & VIDEOS (KEYSTONE AD STUDIO)

Para acelerar la salida a mercado y responder de inmediato a los hallazgos de la telemetría, el sistema incorpora el **Keystone Ad Studio**, un orquestador de Inteligencia Artificial que redacta los copys, genera las piezas gráficas y produce videos promocionales listos para pauta en redes:

#### 6.1 Motores de IA Seleccionados & Justificación Técnica

| Componente | Motor / API Seleccionada | Justificación de Elección & Caso de Uso |
| :--- | :--- | :--- |
| **Copys & Titulares Publicitarios (Tier 1 Principal)** | **Groq Cloud LPU (`qwen/qwen3.8-27b`)** | Inferencia ultra veloz (< 800ms) sin costo operativo ($0 USD Free Tier con alta cuota diaria). Redacta titulares de Google Ads (30 car.), descripciones (90 car.) y publicaciones ejecutivas para LinkedIn con tono B2B impecable. |
| **Copys & Titulares (Tier 2 Fallback)** | **Google Gemini Flash (`gemini-2.5-flash`)** | Alta resiliencia automática si Groq alcanza rate limits o latencias puntuales. |
| **Generador de Banners Visuales (1024x1024)** | **Cloudflare Workers AI (`@cf/black-forest-labs/flux-1-schnell`)** | Generación de imágenes corporativas con IA de última generación (FLUX.1 Schnell) ejecutada en el borde de Cloudflare a costo $0 USD (10.000 neuronas diarias gratuitas). Banners persistidos en `assets/creatives/`. |
| **Videos: Avatares B2B & Vocero Ejecutivo** | **HeyGen API (Tier 1 Principal)** | Sincronización labial (lip-sync) y realismo de avatares en español para explicar la solución y derriba objeciones en 30-45 segundos. |
| **Videos: Motion Dinámico & B-Roll Cinemático** | **Kling AI API / Runway Gen-4 (Tier 2 vía Fal.ai Gateway)** | Física y realismo de producto insuperables para tomas dinámicas 3D de alta gama para Reels y Shorts. |

#### 6.3 Matriz de Líneas de Negocio y Enfoques de Campaña
El generador creativo dispone de perfiles especializados por unidad de negocio y vertical comercial:

| Línea de Negocio / Tenant | Verticales y Soluciones Incluidas | Buyer Persona Sugerido | Enfoque de Mensaje Predeterminado |
| :--- | :--- | :--- | :--- |
| **`keystone_salesforce`** | **Salesforce Core CRM, Financial Services Cloud (FSC), LWC, Apex & Integraciones Bancarias.** | CIOs, Gerentes de TI, Directores de Canales Digitales y Líderes Salesforce. | Arquitecturas escalables, eliminación de silos, time-to-market y modernización de banca y seguros. |
| **`keystone_deepshield`** | **Ciberseguridad Perimetral, DMARC Enforcement, Prevención de Fraude BEC y Cumplimiento Ley N° 21.459.** | Gerentes de Finanzas, CFOs, Tesoreros, CISOs y Gerentes de Riesgo Operacional. | Blindaje contra desvío de transferencias, suplantación de proveedores y responsabilidad penal ejecutiva. |
| **`keystone_cloud`** | **Arquitectura Cloud Empresarial, Kubernetes, Microservicios y Modernización Core.** | CTOs, Gerentes de Infraestructura y Jefes de Operaciones TI. | Alta disponibilidad, resiliencia operativa y reducción de deuda técnica. |
| **`sinnotario`** | **Trámites Legales Online, Verificación de Identidad y Firma Electrónica Avanzada (FEA).** | Corredores de propiedades, Pymes, fundadores y personas naturales. | 100% validez legal y certificación ministerial sin desplazarse a notarías tradicionales. |
| **`keyhub`** | **SaaS Corporativo para Consultoras: Control de Rentabilidad, Tracking de Horas y Tableros Kanban.** | Socios de firmas profesionales, directores de agencias y gerentes de proyectos. | Trazabilidad de costos, control de márgenes por proyecto y gobierno de asignaciones. |
| **`custom`** | **Línea de Negocio o Producto Ad-Hoc.** | Configurable libremente por el administrador. | Permite redactar cualquier objetivo comercial imprevisto con sugerencias de 1 clic. |

#### 6.4 Canales y Plataformas de Destino Soportadas
El usuario puede dirigir la generación a un canal exclusivo o generar el kit multicanal completo:
1. **🌐 Multicanal Omnipresente (360):** Genera en un solo paso el pack completo (Google RSA + LinkedIn Empresa + Guión Video 30s + Banner FLUX.1).
2. **💼 LinkedIn Empresa (Publicación en Página Corporativa):** Redacción en tono de liderazgo B2B ejecutivo, con gancho reflexivo, argumentos de negocio de alto impacto y 3 hashtags del sector.
3. **🔍 Google Ads (Campañas de Búsqueda RSA):** 5 titulares estrictamente limitados a 30 caracteres y 3 descripciones de hasta 90 caracteres de alta intención comercial.
4. **📱 Meta Ads (Facebook & Instagram Feed/Stories):** Mensajes ágiles y visuales enfocados en dolor inmediato y conversión rápida.
5. **✉️ Email Outbound B2B (Prospección Fría):** Estructura concisa de 3 párrafos para primeros contactos con directores y tomadores de decisiones.

---

### 7. ESPECIFICACIÓN DE LA CONSOLA ADMINISTRATIVA CENTRAL (`admin/?tab=marketing`)

Ubicada centralmente en el backend de Keystone Advisors (`https://www.keystoneadvisors.cl/admin/?tab=marketing`), la pantalla presenta 5 módulos interactivos:

```
+-------------------------------------------------------------------------------------------------------+
|  📊 KEYSTONE GROWTH HUB: Panel Central de Marketing, Pauta & Atribución Multisitio                     |
|  [Selector de Empresa: Keystone Advisors | SinNotario.cl | KeyHub SaaS | Todas las Unidades]          |
+-------------------------------------------------------------------------------------------------------+
|  KPIs Clave: Inversión Total: $1.200 USD | Leads Generados: 84 | Ventas Cerradas: $6.450 USD | ROAS: 5.3x  |
+-------------------------------------------------------------------------------------------------------+
|                                                                                                       |
|  SECCIÓN 1: MATRIZ DE RENDIMIENTO POR RED PUBLICITARIA                                                 |
|  +--------------------+------------+----------+------------+----------+-----------+----------------+  |
|  | Red Publicitaria   | Inversión  | Clics    | CPL        | Clientes | Ingresos  | ROAS / Acción  |  |
|  +--------------------+------------+----------+------------+----------+-----------+----------------+  |
|  | 🔵 LinkedIn Ads    | $600 USD   | 185      | $18.2 USD  | 3 B2B    | $4.200 USD| 7.0x (Escalar) |  |
|  | 🔴 Google Search   | $450 USD   | 420      | $8.5 USD   | 2 B2B    | $2.250 USD| 5.0x (Mantener)|  |
|  | 🟠 Meta Ads (FB/IG)| $150 USD   | 610      | $25.0 USD  | 0 Ventas | $0 USD    | 0.0x (Pausar)  |  |
|  +--------------------+------------+----------+------------+----------+-----------+----------------+  |
|                                                                                                       |
|  SECCIÓN 2: RADAR DE INFERENCIA PRE-COMPRA (¿Qué buscaban antes de decidir?)                           |
|  * 42% Duda Principal: "¿Qué validez tiene la Firma Electrónica ante Notario?" (SinNotario)          |
|  * 38% Duda Principal: "¿Cómo se integra DeepShield con SAP y ERPs bancarios?" (Keystone)            |
|  * Términos Más Búsqueda Sin Conversión: "contrato arriendo local comercial", "auditoría ISO 27001"   |
|                                                                                                       |
|  SECCIÓN 3: GENERADOR DINÁMICO DE ENLACES CON UTMS (Link Builder)                                     |
|  Destino: [keystoneadvisors.cl v] Canal: [LinkedIn Ads v] Campaña: [bec_cfo_q4] Creativo: [video_01] |
|  -> URL Generada: https://www.keystoneadvisors.cl/?utm_source=linkedin&utm_medium=cpc... [Copiar 📋]  |
|                                                                                                       |
|  SECCIÓN 4: ESTUDIO CREATIVO IA (Ad Studio: Generación de Copys, Banners e HeyGen Videos)             |
|  [⚡ Generar Aviso con IA] -> Produce: 15 Titulares Google + Banner 1200x628 + Video HeyGen Avatar 30s |
|                                                                                                       |
|  SECCIÓN 5: RECOMENDADOR ALGORÍTMICO ("Dónde enfocar la inversión")                                  |
|  💡 ALERTA DE DECISIÓN: LinkedIn Ads para DeepShield tiene un costo por cliente de $200 USD y genera |
|     un valor de contrato anual de $5.880 USD. Se sugiere reasignar $150 USD de Meta Ads hacia esta     |
|     campaña de LinkedIn para capturar 2 contratos corporativos adicionales este mes.                   |
+-------------------------------------------------------------------------------------------------------+
```

---

### 8. MATRIZ DE INTEGRACIÓN MULTISITIO

| Sitio Web | Rol en el Ecosistema | Archivos de Integración | Eventos Específicos a Medir |
| :--- | :--- | :--- | :--- |
| **`keystoneadvisors.cl`** | **Sitio Matriz / Backend Central** | `api/marketing/track.php`, `admin/?tab=marketing` | Escaneos DMARC, consultas de radiografía 72h, planes DeepShield y contratos Salesforce. |
| **`sinnotario.cl`** | **Unidad B2C / Pymes (Trámites)** | `assets/js/growth-tracker.js`, `api/track.php` | Inicio de redacción de contratos, verificación de firmantes, compra de firmas FEA. |
| **`keyhub...`** | **Unidad B2B SaaS (Operaciones)** | `assets/js/growth-tracker.js` | Solicitudes de demo, altas de tableros Kanban, contratación de planes por usuario. |

---

### 10. MÓDULO DE CONEXIONES EXTERNAS Y PUBLICADOR ORGÁNICO EN LINKEDIN (`SOL-0173`)

Para cerrar el ciclo entre la generación creativa y la distribución, se implementó el conector oficial con las APIs de las plataformas publicitarias y redes sociales corporativas:

#### 10.1 Conector Oficial LinkedIn Company Page (`Keystone Advisors Global`)
- **Organización Destino:** `Keystone Advisors Global` (`urn:li:organization:105863140`).
- **Protocolo de Autorización:** OAuth 2.0 con Scopes `openid`, `profile`, `email`, `w_member_social`.
- **Servicio Backend:** `api/KeystoneLinkedInPublisher.php` y receptor `admin/oauth_linkedin.php`.
- **Mapeo de Flujo:**
  1. *Subida de Imagen:* Se registra el asset multimedia mediante `POST /v2/assets?action=registerUpload` (tipo `urn:li:digitalmediaRecipe:feedshare-image`).
  2. *Upload Binario:* Transferencia segura del banner generado por Cloudflare FLUX.1 al URL de subida otorgado por LinkedIn.
  3. *Publicación del Post:* Creación del share en `POST /v2/shares` asociando texto formateado, hashtags y la imagen adjunta.
  4. *Almacenamiento y Trazabilidad:* Se actualiza `mkt_ai_creatives` con el `linkedin_post_urn` y se marca como `PUBLISHED`.
- **Blindaje de Sesión:** Directiva de cookies `SameSite=Lax` configurada en el servidor para evitar que el navegador cierre la sesión del administrador al retornar desde el flujo externo de LinkedIn.
- **Páginas de Soporte Legal y Consentimiento:** Desplegada la política de privacidad pública en `https://www.keystoneadvisors.cl/privacidad.html` (conforme a Ley N° 19.628 de Protección de Datos Personales).

#### 10.2 Conector Google Ads API
- **Cuenta Administradora:** Keystone Advisors (`396-771-8344`).
- **Autenticación:** OAuth 2.0 Web Client con receptor en `admin/oauth_google.php`.
- **Objetivo:** Ingesta de conversiones offline mejoradas y sincronización automática de campañas Search y Display.

---

### 11. TRAZABILIDAD Y REGISTRO EN BITÁCORAS DE GOBIERNO
- **`CONTROL_CORRELATIVOS.md`:** Registradas formalmente las soluciones consecutivas:
  - `SOL-0166`: Arquitectura y Diseño Funcional Keystone Growth Hub.
  - `SOL-0172`: Implementación del Growth Engine Multi-Site con IA Costo $0 (Groq LPU + FLUX.1).
  - `SOL-0173`: Integración del Publicador Orgánico LinkedIn Empresa & Google Ads OAuth.
- **`BITACORA_PRODUCCION.md`:** Asentados los despliegues de base de datos WAL, endpoints REST y conectores en el servidor de producción Hostinger Cloud (`156.67.72.209`).

