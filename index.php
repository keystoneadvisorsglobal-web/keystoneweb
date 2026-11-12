<?php
/**
 * Keystone Advisors - Sitio Corporativo Principal (Producción)
 * Acceso protegido por contraseña única para proceso de validación directiva
 */
session_start([
    'cookie_httponly' => true,
    'cookie_secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
    'cookie_samesite' => 'Strict',
    'gc_maxlifetime' => 86400
]);

// Control de logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    $_SESSION['ks_site_auth'] = false;
    unset($_SESSION['ks_site_auth']);
    session_destroy();
    header("Location: /");
    exit;
}

// Procesar autenticación con contraseña única
$auth_error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'validate_access') {
    $entered_pass = trim($_POST['site_password'] ?? '');
    
    // Contraseñas autorizadas para el equipo / dirección / cliente
    $valid_passwords = ['Keystone2026!', 'KeystoneVIP2026', 'Keystone2026!SecOps'];

    if (in_array($entered_pass, $valid_passwords, true)) {
        $_SESSION['ks_site_auth'] = true;
        session_regenerate_id(true);
        header("Location: /");
        exit;
    } else {
        $auth_error = "Contraseña de acceso incorrecta. Por favor intente nuevamente.";
    }
}

// Si NO está autenticado, renderizar la pantalla de validación corporativa
// Permitir bypass automático si el visitante proviene de una campaña legítima con UTMs o Click ID
$hasCampaignParams = !empty($_GET['utm_source']) || !empty($_GET['gclid']) || !empty($_GET['li_fat_id']) || !empty($_GET['fbclid']);

if ((!isset($_SESSION['ks_site_auth']) || $_SESSION['ks_site_auth'] !== true) && !$hasCampaignParams):
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Keystone Advisors | Portal en Validación</title>
  <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
  <link rel="icon" type="image/png" href="assets/images/favicon.png?v=20261107_01">
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; font-family: Arial, sans-serif; }
    body {
      background: #001730;
      color: #333333;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 1.5rem;
      position: relative;
      overflow-x: hidden;
    }

    body::before {
      content: '';
      position: absolute;
      top: -20%;
      right: -10%;
      width: 600px;
      height: 600px;
      background: radial-gradient(circle, rgba(0, 180, 216, 0.15) 0%, rgba(0, 23, 48, 0) 70%);
      pointer-events: none;
    }

    body::after {
      content: '';
      position: absolute;
      bottom: -20%;
      left: -10%;
      width: 500px;
      height: 500px;
      background: radial-gradient(circle, rgba(16, 185, 129, 0.12) 0%, rgba(0, 23, 48, 0) 70%);
      pointer-events: none;
    }

    .auth-card {
      background: #ffffff;
      border-radius: 16px;
      width: 100%;
      max-width: 480px;
      padding: 3rem 2.25rem;
      box-shadow: 0 25px 50px rgba(0, 0, 0, 0.4);
      position: relative;
      z-index: 10;
      text-align: center;
    }

    .brand-logo {
      margin-bottom: 1.75rem;
    }

    .brand-logo img {
      height: 54px;
      max-width: 220px;
      object-fit: contain;
    }

    .badge-status {
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
      background: #e0f2fe;
      color: #0284c7;
      font-size: 0.78rem;
      font-weight: 800;
      padding: 0.35rem 0.85rem;
      border-radius: 99px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      margin-bottom: 1.25rem;
    }

    .pulse-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: #0284c7;
      box-shadow: 0 0 0 0 rgba(2, 132, 199, 0.7);
      animation: pulse 1.8s infinite;
    }

    @keyframes pulse {
      0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(2, 132, 199, 0.7); }
      70% { transform: scale(1); box-shadow: 0 0 0 8px rgba(2, 132, 199, 0); }
      100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(2, 132, 199, 0); }
    }

    h1 {
      font-size: 1.5rem;
      font-weight: 800;
      color: #003366;
      line-height: 1.3;
      margin-bottom: 0.75rem;
    }

    p.description {
      font-size: 0.92rem;
      color: #64748b;
      line-height: 1.5;
      margin-bottom: 1.75rem;
    }

    .form-group {
      margin-bottom: 1.25rem;
      text-align: left;
    }

    label {
      display: block;
      font-size: 0.85rem;
      font-weight: 700;
      color: #003366;
      margin-bottom: 0.4rem;
    }

    input[type="password"] {
      width: 100%;
      padding: 0.85rem 1rem;
      border: 1px solid #cbd5e1;
      border-radius: 8px;
      font-size: 1rem;
      color: #1e293b;
      transition: all 0.2s ease;
      text-align: center;
      letter-spacing: 2px;
    }

    input:focus {
      outline: none;
      border-color: #003366;
      box-shadow: 0 0 0 3px rgba(0, 51, 102, 0.15);
    }

    .btn-submit {
      width: 100%;
      padding: 0.85rem;
      background: linear-gradient(135deg, #003366 0%, #006699 100%);
      color: #ffffff;
      border: none;
      border-radius: 8px;
      font-size: 0.95rem;
      font-weight: 700;
      cursor: pointer;
      transition: all 0.2s ease;
    }

    .btn-submit:hover {
      transform: translateY(-1px);
      box-shadow: 0 6px 16px rgba(0, 51, 102, 0.3);
    }

    .alert {
      background: #fef2f2;
      color: #991b1b;
      border: 1px solid #fecaca;
      padding: 0.75rem 1rem;
      border-radius: 8px;
      font-size: 0.85rem;
      margin-bottom: 1.25rem;
      text-align: left;
    }

    .footer-copy {
      margin-top: 1.75rem;
      font-size: 0.78rem;
      color: #94a3b8;
      z-index: 10;
    }
  </style>
</head>
<body>

  <div class="auth-card">
    <div class="brand-logo">
      <img src="assets/images/logo.png?v=20261107_01" alt="Keystone Advisors Logo">
    </div>

    <div>
      <span class="badge-status">
        <span class="pulse-dot"></span>
        Nueva Propuesta en Validación
      </span>
    </div>

    <h1>Acceso Protegido al Nuevo Sitio Web</h1>
    <p class="description">
      Estamos preparando la nueva experiencia digital de <strong>Keystone Advisors</strong>. Ingrese la contraseña de validación para explorar la plataforma completa bajo esta misma dirección.
    </p>

    <?php if ($auth_error): ?>
      <div class="alert"><?php echo htmlspecialchars($auth_error); ?></div>
    <?php endif; ?>

    <form method="POST" action="/">
      <input type="hidden" name="action" value="validate_access">
      <div class="form-group">
        <label for="site_password">Contraseña de Validación</label>
        <input type="password" id="site_password" name="site_password" placeholder="••••••••••••" required autofocus autocomplete="current-password">
      </div>
      <button type="submit" class="btn-submit">
        Desbloquear & Explorar Sitio ➜
      </button>
    </form>
  </div>

  <div class="footer-copy">
    &copy; <?php echo date('Y'); ?> Keystone Advisors SpA. Todos los derechos reservados.
  </div>



</body>
</html>
<?php
exit;
endif;

// =========================================================================
// USUARIO AUTENTICADO: SE RENDERIZA EL NUEVO SITIO DIRECTO EN LA RAÍZ (URL LIMPIA)
// =========================================================================
$page_title = "Keystone Advisors | Transformación Digital, Salesforce, SAP & Soluciones Cloud";
$page_desc = "Consultoría de élite en Salesforce CRM, Agentforce, SAP y arquitecturas Cloud con presencia en Chile, Perú, Panamá, República Dominicana y Canadá.";
?><!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($page_title); ?></title>
  <meta name="description" content="<?php echo htmlspecialchars($page_desc); ?>">
  <meta name="keywords" content="ciberseguridad chile, consultoria salesforce santiago, escaner dmarc gratuito, keyhub saas, blindaje tesoreria bec, auditoria forense horas ti, partner salesforce fsc">
  <meta name="author" content="Keystone Advisors SpA">
  <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
  <link rel="canonical" href="https://www.keystoneadvisors.cl/">

  <!-- Open Graph Protocol (LinkedIn, WhatsApp, Facebook) -->
  <meta property="og:locale" content="es_CL">
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="Keystone Advisors">
  <meta property="og:title" content="Keystone Advisors | Ciberseguridad Ofensiva, Salesforce FSC & KeyHub SaaS">
  <meta property="og:description" content="Protegemos su operación con IA: auditoría de exposición digital, consultoría de élite Salesforce FSC y la suite de control de horas y contratos KeyHub.">
  <meta property="og:url" content="https://www.keystoneadvisors.cl/">
  <meta property="og:image" content="https://www.keystoneadvisors.cl/assets/images/logo.png?v=20261107_01">
  <meta property="og:image:alt" content="Keystone Advisors - Consultoría Tecnológica & Ciberseguridad">

  <!-- Twitter Cards -->
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="Keystone Advisors | Ciberseguridad, Salesforce & KeyHub">
  <meta name="twitter:description" content="Evaluación de vulnerabilidades DMARC y SPF gratuita en 30 segundos. Consultoría especializada en Salesforce y arquitectura cloud.">
  <meta name="twitter:image" content="https://www.keystoneadvisors.cl/assets/images/logo.png?v=20261107_01">

  <!-- Metadatos Estructurados JSON-LD (Schema.org) -->
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@graph": [
      {
        "@type": "Organization",
        "@id": "https://www.keystoneadvisors.cl/#organization",
        "name": "Keystone Advisors SpA",
        "url": "https://www.keystoneadvisors.cl/",
        "logo": {
          "@type": "ImageObject",
          "url": "https://www.keystoneadvisors.cl/assets/images/logo.png",
          "caption": "Keystone Advisors Logo"
        },
        "description": "Firma internacional de consultoría especializada en Ciberseguridad IA, Consultoría Salesforce FSC, Transformación Cloud y la Suite SaaS KeyHub.",
        "telephone": "+56 9 7971 9857",
        "email": "contacto@keystoneadvisors.cl",
        "address": {
          "@type": "PostalAddress",
          "addressLocality": "Santiago",
          "addressCountry": "CL"
        },
        "sameAs": [
          "https://www.linkedin.com/company/keystone-advisors/"
        ]
      },
      {
        "@type": "ProfessionalService",
        "@id": "https://www.keystoneadvisors.cl/#service",
        "name": "Keystone Advisors - Consultoría TI & Ciberseguridad",
        "url": "https://www.keystoneadvisors.cl/",
        "parentOrganization": {
          "@id": "https://www.keystoneadvisors.cl/#organization"
        },
        "priceRange": "$$$$",
        "areaServed": [
          { "@type": "Country", "name": "Chile" },
          { "@type": "Country", "name": "Perú" },
          { "@type": "Country", "name": "Panamá" },
          { "@type": "Country", "name": "República Dominicana" },
          { "@type": "Country", "name": "Canadá" }
        ],
        "hasOfferCatalog": {
          "@type": "OfferCatalog",
          "name": "Portafolio de Servicios Corporativos",
          "itemListElement": [
            {
              "@type": "Offer",
              "itemOffered": {
                "@type": "Service",
                "name": "Ciberseguridad IA & Resiliencia Operativa",
                "description": "Diagnóstico DMARC pasivo, radiografía forense 72h y blindaje contra fraude del CEO (BEC)."
              }
            },
            {
              "@type": "Offer",
              "itemOffered": {
                "@type": "Service",
                "name": "Consultoría Salesforce & Arquitectura CRM",
                "description": "Implementación de Financial Services Cloud (FSC), Sales Cloud, Service Cloud y Agentforce."
              }
            },
            {
              "@type": "Offer",
              "itemOffered": {
                "@type": "SoftwareApplication",
                "name": "KeyHub Suite SaaS",
                "applicationCategory": "BusinessApplication",
                "operatingSystem": "Web",
                "offers": {
                  "@type": "Offer",
                  "price": "8.00",
                  "priceCurrency": "USD",
                  "description": "Desde USD $8 / Usuario / Mes"
                }
              }
            }
          ]
        }
      },
      {
        "@type": "WebSite",
        "@id": "https://www.keystoneadvisors.cl/#website",
        "url": "https://www.keystoneadvisors.cl/",
        "name": "Keystone Advisors",
        "publisher": {
          "@id": "https://www.keystoneadvisors.cl/#organization"
        }
      }
    ]
  }
  </script>

  <link rel="stylesheet" href="assets/css/style.css?v=20261107_10">
  <link rel="icon" type="image/png" href="assets/images/favicon.png?v=20261107_01">
  <style>
    .alert-box {
      padding: 0.85rem 1rem;
      border-radius: 8px;
      margin-bottom: 1.25rem;
      font-size: 0.9rem;
      display: none;
    }
    .alert-success {
      background: #ecfdf5;
      color: #065f46;
      border: 1px solid #a7f3d0;
    }
    .alert-error {
      background: #fef2f2;
      color: #991b1b;
      border: 1px solid #fecaca;
    }
  
    /* Barra sutil de modo validación directiva */
    .validation-bar {
      background: #001730;
      color: #94a3b8;
      font-size: 0.78rem;
      padding: 0.4rem 1.5rem;
      display: flex;
      justify-content: space-between;
      align-items: center;
      border-bottom: 1px solid rgba(255,255,255,0.1);
      position: relative;
      z-index: 1001;
    }
    .validation-bar a {
      color: #38bdf8;
      text-decoration: none;
      font-weight: 700;
      padding: 0.2rem 0.5rem;
      border-radius: 4px;
      transition: background 0.2s;
    }
    .validation-bar a:hover {
      background: rgba(56, 189, 248, 0.1);
      text-decoration: underline;
    }
  </style>
</head>
<body>

  <!-- Barra de estado de validación directiva -->
  <div class="validation-bar">
    <span>🔒 Modo Validación Activo • Visualizando nueva plataforma en <strong>https://www.keystoneadvisors.cl/</strong></span>
    <a href="/?action=logout">Bloquear / Salir</a>
  </div>


  <!-- Navigation Header -->
  <header class="header">
    <div class="header-container">
      <a href="#" class="brand">
        <img src="assets/images/logo.png?v=20261107_01" alt="Keystone Advisors Logo">
      </a>
      <!-- Boton hamburguesa para móviles -->
      <button class="nav-toggle-btn" id="navToggleBtn" aria-label="Abrir Menú de Navegación">
        ☰
      </button>

      <div class="nav-menu-wrapper" id="navMenuWrapper">
        <nav>
          <ul class="nav-links">
            <li><a href="#inicio" class="nav-link">Inicio</a></li>
            <li><a href="#servicios" class="nav-link">Servicios & IA</a></li>
            <li><a href="#keyhub" class="nav-link">KeyHub SaaS</a></li>
            <li><a href="#productos" class="nav-link">Planes B2B</a></li>
            <li><a href="/trust/" class="nav-link" style="color:#10b981; font-weight:700;">Trust Center 🛡️</a></li>
            <li><a href="#contacto" class="nav-link">Contacto</a></li>
          </ul>
        </nav>
        <div class="nav-actions">
          <!-- Switcher Moneda USD / Chile UF (SOL-0190) -->
          <div class="currency-toggle" style="display:inline-flex; align-items:center; background:#f1f5f9; border:1px solid #cbd5e1; border-radius:30px; padding:2px; font-size:0.78rem; font-weight:700;">
            <button type="button" class="btn-cur-switch" data-cur="USD" onclick="setSiteCurrency('USD')" style="border:none; background:transparent; padding:0.35rem 0.65rem; border-radius:20px; cursor:pointer; color:#475569; transition:all 0.2s; font-weight:700;">🇺🇸 USD</button>
            <button type="button" class="btn-cur-switch active" data-cur="UF" onclick="setSiteCurrency('UF')" style="border:none; background:#003366; color:#ffffff; padding:0.35rem 0.65rem; border-radius:20px; cursor:pointer; transition:all 0.2s; font-weight:700; box-shadow:0 2px 4px rgba(0,0,0,0.15);">🇨🇱 UF</button>
          </div>

          <!-- Dropdown Acceso Clientes (SOL-0200) -->
          <div class="nav-portal-dropdown" style="position:relative; display:inline-block;">
            <button type="button" id="btnPortalDropdown" class="btn" style="background:#f8fafc; color:#003366; border:1px solid #cbd5e1; font-size:0.85rem; padding:0.45rem 0.9rem; font-weight:700; display:flex; align-items:center; gap:0.4rem; cursor:pointer;" onclick="togglePortalDropdown(event)">
              <span>🛡️ Portales</span> <span style="font-size:0.65rem;">▼</span>
            </button>
            <div id="portalDropdownMenu" style="display:none; position:absolute; right:0; top:calc(100% + 8px); background:#ffffff; border:1px solid #e2e8f0; border-radius:10px; box-shadow:0 10px 25px rgba(0,0,0,0.12); min-width:260px; padding:0.5rem; z-index:1100;">
              <a href="/shield/portal.php" style="display:flex; align-items:flex-start; gap:0.6rem; padding:0.65rem 0.75rem; border-radius:6px; text-decoration:none; color:#1e293b; transition:background 0.2s;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='transparent'">
                <span style="font-size:1.2rem;">🏦</span>
                <div>
                  <div style="font-size:0.85rem; font-weight:700; color:#003366;">Portal DeepShield Treasury</div>
                  <div style="font-size:0.75rem; color:#64748b;">Custodia OOB y órdenes de pago</div>
                </div>
              </a>
              <a href="/shield/portal.php?tab=dmarc" style="display:flex; align-items:flex-start; gap:0.6rem; padding:0.65rem 0.75rem; border-radius:6px; text-decoration:none; color:#1e293b; transition:background 0.2s;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='transparent'">
                <span style="font-size:1.2rem;">📡</span>
                <div>
                  <div style="font-size:0.85rem; font-weight:700; color:#059669;">DMARC Watchdog & Anti-Spoofing</div>
                  <div style="font-size:0.75rem; color:#64748b;">Dosieres mensuales y reportes PDI</div>
                </div>
              </a>
              <div style="border-top:1px solid #e2e8f0; margin:0.35rem 0;"></div>
              <a href="/trust/" style="display:flex; align-items:flex-start; gap:0.6rem; padding:0.65rem 0.75rem; border-radius:6px; text-decoration:none; color:#1e293b; transition:background 0.2s;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='transparent'">
                <span style="font-size:1.2rem;">🔒</span>
                <div>
                  <div style="font-size:0.85rem; font-weight:700; color:#0284c7;">Trust Center & Telemetría</div>
                  <div style="font-size:0.75rem; color:#64748b;">Postura de seguridad en tiempo real</div>
                </div>
              </a>
            </div>
          </div>

          <a href="#escaner" class="btn btn-primary" style="background: linear-gradient(135deg, #059669 0%, #10b981 100%); font-size:0.88rem;">🛡️ Escáner Gratuito</a>
        </div>
      </div>
    </div>
  </header>

  <!-- Hero Section -->
  <section class="hero" id="inicio">
    <div class="hero-container">
      <div class="hero-content">
        <span class="badge-dev">Nueva Generación Web • Keystone Advisors</span>
        <h1 class="hero-title">Aceleramos el Futuro Digital de su Empresa con <span>Salesforce & Cloud</span></h1>
        <p class="hero-subtitle">
          Especialistas de élite en Salesforce CRM, Salesforce Agentforce, modernización ERP y arquitecturas multinube (AWS, Azure, GCP). Modelos de consultoría y servicios offshore desde Chile, Perú, Panamá, Canadá y República Dominicana.
        </p>
        <div class="hero-cta">
          <a href="#escaner" class="btn btn-primary" style="background: linear-gradient(135deg, #059669 0%, #10b981 100%); padding:0.85rem 1.75rem; font-size:1rem; box-shadow:0 4px 14px rgba(16,185,129,0.35);">🛡️ Escanear Mi Empresa Gratis (30s)</a>
          <a href="#keyhub" class="btn btn-secondary" style="padding:0.85rem 1.5rem; font-size:1rem;">Ver KeyHub Suite ➜</a>
        </div>
        <div class="hero-stats">
          <div class="stat-item">
            <h4>+12 Años</h4>
            <p>Experiencia Regional</p>
          </div>
          <div class="stat-item">
            <h4>100%</h4>
            <p>Especialistas Certificados</p>
          </div>
          <div class="stat-item">
            <h4>5 Países</h4>
            <p>Presencia Internacional</p>
          </div>
        </div>
      </div>
      <div class="hero-card">
        <h3>Innovación Instantánea</h3>
        <p>Habilite su ecosistema de ventas, soporte con agentes autónomos de IA y soporte continuo con nuestros paquetes Quick Starter de puesta en marcha rápida.</p>
        <div style="background: rgba(255,255,255,0.08); padding: 1.25rem; border-radius: 12px; margin-bottom: 1.5rem; border: 1px solid rgba(255,255,255,0.1);">
          <div style="font-size:0.85rem; color:#94a3b8; font-weight:700; text-transform:uppercase;">Oferta Destacada</div>
          <div style="font-size:1.25rem; font-weight:800; color:#ffffff; margin: 0.25rem 0;">Salesforce Quick Starter</div>
          <div style="font-size:0.9rem; color:#e0f2fe;">Service o Sales Cloud configurado y operativo en tan solo 4 semanas.</div>
        </div>
        <a href="#keyhub" class="btn btn-accent" style="width: 100%;">Conocer KeyHub Enterprise</a>
      </div>
    </div>
  </section>

  <!-- Free Cyber Exposure Scanner (B2B Lead Acquisition Motor) -->
  <section class="scanner-section" id="escaner">
    <div class="scanner-container">
      <span class="scanner-badge">⚡ Diagnóstico No Intrusivo en 30 Segundos</span>
      <h2 class="scanner-title">Escáner de Exposición a Fraudes Asistidos por IA & <span>Suplantación de Identidad</span></h2>
      <p class="scanner-desc">
        Evalúe pasivamente y sin riesgo la superficie de ataque de su empresa: verifique si su dominio permite suplantación de correo (estafas de cambio de cuenta corriente bancaria / BEC), el estado de sus certificados SSL y la exposición pública de su infraestructura.
      </p>

      <div class="scanner-box">
        <form id="cyberScannerForm">
          <div class="scanner-form-grid">
            <div>
              <label style="display:block; font-size:0.82rem; font-weight:700; color:#cbd5e1; margin-bottom:0.35rem;">Dominio Corporativo *</label>
              <input type="text" id="scanDomain" class="scanner-input" placeholder="ejemplo: empresa.cl" required>
            </div>
            <div>
              <label style="display:block; font-size:0.82rem; font-weight:700; color:#cbd5e1; margin-bottom:0.35rem;">Correo Corporativo (Para envío de Reporte) *</label>
              <input type="email" id="scanEmail" class="scanner-input" placeholder="gerencia@empresa.cl" required>
            </div>
            <div style="align-self: flex-end;">
              <button type="submit" id="btnRunScan" class="btn btn-primary" style="background: linear-gradient(135deg, #059669 0%, #10b981 100%); padding:0.85rem 1.4rem; white-space:nowrap; width:100%; box-shadow:0 4px 14px rgba(16,185,129,0.35);">
                <span>Iniciar Escaneo Gratuito</span> 🔍
              </button>
            </div>
          </div>
        </form>

        <div class="scanner-notice">
          <span>🔒 <strong>100% Pasivo y Legal:</strong> Solo consulta registros públicos DNS (DMARC, SPF, MX) y cabeceras web. No interactúa con sus servidores privados.</span>
          <span>⚡ Entrega inmediata en pantalla</span>
        </div>

        <!-- Scanner Live Results Container -->
        <div class="scanner-results" id="scannerResults">
          <div class="scanner-score-header">
            <div>
              <span style="font-size:0.8rem; color:#94a3b8; text-transform:uppercase; font-weight:700;">Diagnóstico para:</span>
              <h3 id="scannerDomainDisplay" style="color:#ffffff; font-size:1.4rem; margin-top:0.2rem;">empresa.cl</h3>
            </div>
            <div class="scanner-score-circle">
              <span class="big-score" id="scannerScoreVal">--</span>
              <span style="font-size:1.1rem; color:#94a3b8; font-weight:700;">/ 100</span>
            </div>
            <div>
              <div id="scannerScoreText" style="font-size:1.1rem; font-weight:800;">Calculando nivel de riesgo...</div>
              <div style="font-size:0.8rem; color:#cbd5e1; margin-top:0.25rem;">Radiografía de exposición financiera ante ciberdelincuencia</div>
            </div>
          </div>

          <h4 style="font-size:1rem; color:#ffffff; margin-bottom:0.85rem; font-weight:700;">Brechas Superficiales Detectadas:</h4>
          <div class="scanner-findings-list" id="scannerFindingsList"></div>

          <div style="background: rgba(0, 180, 216, 0.1); border: 1px solid rgba(0, 180, 216, 0.25); border-radius: 12px; padding: 1.25rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
            <div>
              <h5 style="color:#ffffff; font-size:1rem; margin-bottom:0.25rem;">¿Desea cerrar estas brechas antes de sufrir un incidente?</h5>
              <p style="color:#cbd5e1; font-size:0.85rem;">Nuestros especialistas pueden implementar DMARC en modo reject y blindar sus pagos críticos en 72 horas.</p>
            </div>
            <button class="btn btn-primary btn-contact-open" style="background:#00b4d8; color:#ffffff;">
              Agendar Sesión de Remediación (20 min) ➜
            </button>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- KeyHub Dedicated Section (SaaS & Enterprise Operations) -->
  <section class="keyhub-section" id="keyhub">
    <div class="section-container">
      
      <!-- KeyHub Hero Showcase -->
      <div class="keyhub-hero-grid">
        <div class="keyhub-hero-left">
          <span class="keyhub-badge">🚀 Plataforma Propietaria Keystone</span>
          <h2 class="keyhub-title">KeyHub: La Suite Operativa Integral para <span>Empresas de Consultoría & TI</span></h2>
          <p class="keyhub-desc">
            Diseñado y validado en proyectos de misión crítica en banca y retail internacional. KeyHub unifica en una sola plataforma la gestión ágil de proyectos (Kanban con cronómetro forense), contratos legales con firma digital certificada, liquidaciones de honorarios multidivisa y sincronización bidireccional en tiempo real con <strong>Salesforce</strong>, <strong>Jira</strong> y <strong>Microsoft Teams</strong>.
          </p>
          <div class="keyhub-cta-group">
            <a href="#planes-keyhub" class="btn btn-primary" style="background: linear-gradient(135deg, #00b4d8 0%, #0077b6 100%); padding:0.8rem 1.6rem;">Ver Planes de Licenciamiento</a>
            <button class="btn btn-secondary btn-buy-product" 
                    data-product-id="PROD-KEYHUB-DEMO" 
                    data-product-name="KeyHub Enterprise Demo & Trial" 
                    data-product-price="Prueba Asistida (14 Días)"
                    style="background:rgba(255,255,255,0.08); color:#ffffff; border-color:rgba(255,255,255,0.25);">
              Solicitar Demo Guiada ➜
            </button>
          </div>
        </div>

        <div class="keyhub-hero-right">
          <div class="keyhub-visual-card">
            <div class="keyhub-visual-header">
              <div class="keyhub-visual-logo">
                <div class="keyhub-logo-badge">KH</div>
                <span>KeyHub Operations Suite</span>
              </div>
              <div class="keyhub-pulse-status">
                <span class="keyhub-pulse-dot"></span>
                <span>Active Cloud 2026</span>
              </div>
            </div>
            
            <div class="keyhub-metric-row">
              <div class="keyhub-metric-box">
                <div class="metric-num">100%</div>
                <div class="metric-label">Auditoría HH</div>
              </div>
              <div class="keyhub-metric-box">
                <div class="metric-num">&lt; 60s</div>
                <div class="metric-label">SLA Alertas</div>
              </div>
              <div class="keyhub-metric-box">
                <div class="metric-num">B2B</div>
                <div class="metric-label">Firma Legal</div>
              </div>
            </div>

            <div class="keyhub-feature-pills">
              <div class="keyhub-feature-pill">
                <span>⚡</span>
                <span><strong>Kanban Operativo:</strong> Cronómetro forense & SLA semafórico dinámico</span>
              </div>
              <div class="keyhub-feature-pill">
                <span>📑</span>
                <span><strong>Contratos & Firma:</strong> Sello criptográfico SHA-256 multinacional</span>
              </div>
              <div class="keyhub-feature-pill">
                <span>🔄</span>
                <span><strong>Integración Nativa:</strong> Conectores con Salesforce, Jira & MS Teams</span>
              </div>
              <div class="keyhub-feature-pill">
                <span>📲</span>
                <span><strong>Alertas Proactivas:</strong> Enrutamiento inteligente WhatsApp AMS</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- KeyHub Modules Grid -->
      <div class="keyhub-modules-grid">
        <div class="keyhub-module-card">
          <div class="keyhub-module-icon">📊</div>
          <h3 class="keyhub-module-title">Tableros Kanban & SLAs Inteligentes</h3>
          <p class="keyhub-module-desc">Organización de squads por fases, sub-estados, imputación de HH al minuto con cronómetro forense e indicadores visuales de SLA para cumplimiento estricto.</p>
        </div>

        <div class="keyhub-module-card">
          <div class="keyhub-module-icon">🖋️</div>
          <h3 class="keyhub-module-title">Contratos & Firma Digital Legal</h3>
          <p class="keyhub-module-desc">Generación automática de contratos marco (MSA), adendas y acuerdos NDA con estampado criptográfico SHA-256 con validez en Chile, Perú, Panamá, RD y Canadá.</p>
        </div>

        <div class="keyhub-module-card">
          <div class="keyhub-module-icon">💵</div>
          <h3 class="keyhub-module-title">Gestión de Honorarios & Nómina Multidivisa</h3>
          <p class="keyhub-module-desc">Liquidación automatizada de consultores en USD y CLP, auditoría de horas devengadas, conciliación fiscal y conexión internacional para pagos transfronterizos.</p>
        </div>

        <div class="keyhub-module-card">
          <div class="keyhub-module-icon">🔗</div>
          <h3 class="keyhub-module-title">Conectores Salesforce, Jira & Teams</h3>
          <p class="keyhub-module-desc">Sincronización desatendida de casos e incidencias. Permite resolver y actualizar tickets sin consumir licencias adicionales en los entornos destino.</p>
        </div>
      </div>

      <!-- KeyHub Commercial Licensing Plans -->
      <div id="planes-keyhub">
        <div class="keyhub-plans-header">
          <span class="keyhub-badge">Modelo de Licenciamiento SaaS</span>
          <h3 style="font-size:2rem; font-weight:800; color:#ffffff; margin-bottom:0.5rem;">Planes Comerciales KeyHub Suite</h3>
          <p style="color:#cbd5e1; font-size:0.95rem;">Tarifas transparentes por usuario y escalabilidad modular para consultoras y operaciones bancarias de misión crítica.</p>
        </div>

        <div class="keyhub-plans-grid">
          
          <!-- Plan 1: KeyHub Professional -->
          <div class="keyhub-plan-card">
            <div class="keyhub-plan-name">KeyHub Professional</div>
            <div class="keyhub-plan-target">Para consultoras y firmas de servicios profesionales que requieren orden operativo, control de horas y contratos digitales.</div>
            
            <div class="keyhub-plan-price">
              <span class="plan-prefix">Desde</span>
              <span class="plan-cur price-cur" data-price-cur>USD</span>
              <span class="plan-amt price-amt" data-price-usd="8" data-price-uf="0,20">$8</span>
              <span class="plan-period">/ Usuario / Mes</span>
            </div>
            <div class="keyhub-plan-sub price-sub" data-sub-usd="Base mínima: 20 usuarios (Desde USD $160 / mes) • Facturación flexible" data-sub-uf="Base mínima: 20 usuarios (Desde UF 4 / mes) • Facturación flexible">Base mínima: 20 usuarios (Desde USD $160 / mes) • Facturación flexible</div>

            <ul class="keyhub-plan-features">
              <li>Módulo Kanban colaborativo con cronómetro e imputación de HH</li>
              <li>Repositorio de Contratos Marco (MSA), Adendas y acuerdos NDA</li>
              <li>Firma Digital con estampado criptográfico SHA-256</li>
              <li>Liquidaciones mensuales y por hora en divisa dual (USD / CLP)</li>
              <li>Alertas y notificaciones desatendidas vía WhatsApp & Correo</li>
              <li>Integración estándar con Jira y exportación de reportes Excel</li>
            </ul>
            <button class="btn btn-secondary btn-buy-product" 
                    data-product-id="PROD-KEYHUB-PRO" 
                    data-product-name="KeyHub Professional (SaaS)" 
                    data-product-price="Desde USD $8 / Usuario / Mes (Mín. 20 usuarios)"
                    data-price-usd-label="Desde USD $8 / Usuario / Mes (Mín. 20 usuarios)"
                    data-price-uf-label="Desde UF 0,20 / Usuario / Mes (Mín. 20 usuarios / UF 4 mes)">
              Contratar KeyHub Pro
            </button>
          </div>

          <!-- Plan 2: KeyHub Enterprise (Featured) -->
          <div class="keyhub-plan-card highlight">
            <div class="keyhub-plan-badge">Corporativo</div>
            <div class="keyhub-plan-name">KeyHub Enterprise</div>
            <div class="keyhub-plan-target">Para grandes operaciones corporativas, banca y squads multi-cliente con alta exigencia de integración y gobierno.</div>
            
            <div class="keyhub-plan-price">
              <span class="plan-prefix">Desde</span>
              <span class="plan-cur price-cur" data-price-cur>USD</span>
              <span class="plan-amt price-amt" data-price-usd="14" data-price-uf="0,35">$14</span>
              <span class="plan-period">/ Usuario / Mes</span>
            </div>
            <div class="keyhub-plan-sub price-sub" data-sub-usd="Piso mínimo: 20 usuarios (Desde USD $280 / mes) • Instancia dedicada o Cloud privada • SLA 99.9%" data-sub-uf="Piso mínimo: 20 usuarios (Desde UF 7 / mes) • Instancia dedicada o Cloud privada • SLA 99.9%">Piso mínimo: 20 usuarios • Instancia dedicada o Cloud privada • SLA 99.9%</div>

            <ul class="keyhub-plan-features">
              <li><strong>Todo lo incluido en KeyHub Professional</strong></li>
              <li><strong>Conector Bidireccional Salesforce:</strong> Sincronización de Casos y Diagnóstico Técnico en tiempo real</li>
              <li><strong>Conector Microsoft Teams Multi-Tenant:</strong> Canales de soporte directo con usuarios del cliente</li>
              <li>SLA multivariable combinando Estado de Tarjeta y Tipo de Servicio</li>
              <li>Módulo de Gestión de Proveedores y Conciliación Pasarelas (OnTop)</li>
              <li>Soporte técnico prioritario 24/7 y personalización de marca (White-Label)</li>
            </ul>
            <button class="btn btn-primary btn-buy-product" 
                    data-product-id="PROD-KEYHUB-ENTERPRISE" 
                    data-product-name="KeyHub Enterprise Suite" 
                    data-product-price="Desde USD $14 / Usuario / Mes (Mín. 20 usuarios)"
                    data-price-usd-label="Desde USD $14 / Usuario / Mes (Mín. 20 usuarios)"
                    data-price-uf-label="Desde UF 0,35 / Usuario / Mes (Mín. 20 usuarios / UF 7 mes)"
                    style="margin-top:auto; background: linear-gradient(135deg, #00b4d8 0%, #0077b6 100%);">
              Solicitar Propuesta Enterprise ➜
            </button>
          </div>

          <!-- Plan 3: Add-on Forense Premium -->
          <div class="keyhub-plan-card addon-card">
            <div class="keyhub-addon-badge">Add-on Premium Especial</div>
            <div class="keyhub-plan-name" style="color:#38bdf8;">Motor Pericial & Auditoría Forense de Horas</div>
            <div class="keyhub-plan-target">Módulo analítico avanzado de auditoría matemática, antifraude de HH y cumplimiento pericial para auditorías bancarias y SOX.</div>
            
            <div class="keyhub-plan-price">
              <span class="plan-prefix">Desde</span>
              <span class="plan-cur price-cur" data-price-cur>USD</span>
              <span class="plan-amt price-amt" data-price-usd="4" data-price-uf="0,10">$4</span>
              <span class="plan-period">/ Usuario / Mes</span>
            </div>
            <div class="keyhub-plan-sub price-sub" data-sub-usd="Adicional sobre Plan Professional o Enterprise • Sin límite de proyectos" data-sub-uf="Adicional sobre Plan Professional o Enterprise (Desde UF 0,10 / usuario) • Sin límite de proyectos">Adicional sobre Plan Professional o Enterprise • Sin límite de proyectos</div>

            <ul class="keyhub-plan-features">
              <li><strong>Auditoría Matemática Anti-Falsificación:</strong> Detección de solapamiento y alteración de tiempos</li>
              <li><strong>Motor IA de Desvíos de Imputación:</strong> Alertas automáticas ante variaciones estadísticas anómalas de esfuerzo</li>
              <li><strong>Cadena de Custodia Inmutable:</strong> Hash SHA-256 e IP por cada edición o movimiento de HH</li>
              <li><strong>Dosieres Periciales Descargables:</strong> Generación ejecutiva de informes forenses en PDF y Word</li>
              <li><strong>Cuadratura Directiva:</strong> Cruce automático de horas estimadas vs reales por fase evolutiva</li>
            </ul>
            <button class="btn btn-secondary btn-buy-product" 
                    data-product-id="PROD-KEYHUB-FORENSIC" 
                    data-product-name="Add-on Premium: Motor Pericial & Auditoría Forense de Horas" 
                    data-product-price="Desde USD $4 / Usuario / Mes (Add-on)"
                    data-price-usd-label="Desde USD $4 / Usuario / Mes (Add-on)"
                    data-price-uf-label="Desde UF 0,10 / Usuario / Mes (Add-on)"
                    style="margin-top:auto; border-color:#38bdf8; color:#38bdf8;">
              Agregar Motor Forense
            </button>
          </div>

        </div>
      </div>

    </div>
  </section>

  <!-- Services & Solutions Section with Interactive Category Tabs -->
  <section class="section" id="servicios">
    <div class="section-container">
      <div class="section-header" id="ciberseguridad">
        <span class="badge-dev">Portafolio Estratégico</span>
        <h2 class="section-title">Ecosistema de Soluciones Tecnológicas & Ciberseguridad</h2>
        <p class="section-desc">Explore nuestros pilares de consultoría corporativa: explore por área de especialidad para conocer cómo aceleramos y protegemos sus operaciones.</p>
      </div>

      <!-- Interactive Tabs Navigation -->
      <div class="tabs-nav">
        <button class="tab-btn tab-cyber active" data-tab="tab-cyber">
          <span>🛡️ Ciberseguridad & Anti-Fraude IA</span>
        </button>
        <button class="tab-btn" data-tab="tab-salesforce">
          <span>⚡ Salesforce CRM & Agentforce</span>
        </button>
        <button class="tab-btn" data-tab="tab-cloud">
          <span>☁️ Arquitectura Cloud & FinOps</span>
        </button>
        <button class="tab-btn" data-tab="tab-offshore">
          <span>🌐 Squads TI Offshore</span>
        </button>
      </div>

      <!-- Tab Content 1: Ciberseguridad Avanzada (Nuevo Pilar) -->
      <div class="tab-pane active" id="tab-cyber">
        <div class="services-grid">
          
          <div class="service-card">
            <div class="service-icon" style="background:#fef2f2; color:#ef4444;">🏦</div>
            <h3 class="service-title">Blindaje Operativo de Tesorería & Pagos</h3>
            <p class="service-desc">Protección contra desvíos de fondos y estafas de cambio de cuenta bancaria vía IA (Deepfake / Vishing). Protocolos de validación Fuera de Banda (OOB), control dual bancario y conciliación criptográfica de facturas.</p>
            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:1rem;">
              <a href="#como-opera-deepshield" class="service-link" style="color:#0284c7; font-weight:700;">¿Cómo opera? ➜</a>
              <a href="#contacto" class="service-link btn-contact-open" style="font-weight:700;">Contratar ➜</a>
            </div>
          </div>

          <div class="service-card">
            <div class="service-icon" style="background:#fef3c7; color:#d97706;">🎭</div>
            <h3 class="service-title">Red Teaming Asistido por IA</h3>
            <p class="service-desc">Simulaciones de ingeniería social hiperpersonalizadas con modelos LLM contextuales (noticias reales, directores, contratos públicos) y pruebas controladas de voz sintética para entrenar a directivos y finanzas.</p>
            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:1rem; flex-wrap:wrap; gap:0.5rem;">
              <a href="#como-opera-redteaming" class="service-link" style="color:#0284c7; font-weight:700;">¿Cómo opera? ➜</a>
              <button type="button" onclick="openRedTeamSimulatorModal()" class="service-link" style="background:none; border:none; padding:0; font-family:inherit; font-size:inherit; font-weight:700; cursor:pointer; color:#d97706; text-align:left;">Simular con IA ➜</button>
            </div>
          </div>

          <div class="service-card">
            <div class="service-icon" style="background:#e0f2fe; color:#0284c7;">🔑</div>
            <h3 class="service-title">Identidad Robusta Zero Trust (FIDO2)</h3>
            <p class="service-desc">Erradicación de contraseñas vulnerables y bypass de 2FA por SMS. Despliegue de llaves físicas YubiKey/Passkeys criptográficas phishing-resistant, PAM y acceso condicional por geolocalización.</p>
            <a href="#contacto" class="service-link btn-contact-open">Implementar Zero Trust ➜</a>
          </div>

          <div class="service-card">
            <div class="service-icon" style="background:#ecfdf5; color:#059669;">✉️</div>
            <h3 class="service-title">Protección de Marca & DMARC (p=reject)</h3>
            <p class="service-desc">Gobernanza integral de correos corporativos: alineación estricta de DMARC, SPF y DKIM para impedir que terceros emitan emails suplantando su dominio ante bancos o proveedores internacionales.</p>
            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:1rem; flex-wrap:wrap; gap:0.5rem;">
              <a href="#escaner" class="service-link" style="color:#059669; font-weight:700;">Auditar mi Dominio Gratis ➜</a>
              <a href="/shield/portal.php?tab=dmarc" class="service-link" style="color:#0284c7; font-weight:700;">Portal DMARC ↗</a>
            </div>
          </div>

        </div>
      </div>

      <!-- Tab Content 2: Salesforce & Agentforce -->
      <div class="tab-pane" id="tab-salesforce">
        <div class="services-grid">
          <div class="service-card">
            <div class="service-icon">⚡</div>
            <h3 class="service-title">Salesforce CRM & Core</h3>
            <p class="service-desc">Implementación y optimización integral de Sales Cloud, Service Cloud, Financial Services Cloud (FSC) y Experience Cloud con los estándares más rigurosos de la industria.</p>
            <a href="#contacto" class="service-link btn-contact-open">Consultar con especialista ➜</a>
          </div>
          <div class="service-card">
            <div class="service-icon">🤖</div>
            <h3 class="service-title">Salesforce Agentforce & IA</h3>
            <p class="service-desc">Creación y orquestación de agentes de IA autónomos para resolver casos transaccionales complejos vía WhatsApp, Web y canales bancarios sin fricción.</p>
            <a href="#contacto" class="service-link btn-contact-open">Ver casos de uso ➜</a>
          </div>
        </div>
      </div>

      <!-- Tab Content 3: Cloud & FinOps -->
      <div class="tab-pane" id="tab-cloud">
        <div class="services-grid">
          <div class="service-card">
            <div class="service-icon">☁️</div>
            <h3 class="service-title">Arquitecturas Cloud & FinOps</h3>
            <p class="service-desc">Diseño de microservicios, migraciones seguras y control exhaustivo de costos de facturación en plataformas AWS, Google Cloud y Microsoft Azure.</p>
            <a href="#contacto" class="service-link btn-contact-open">Optimizar infraestructura ➜</a>
          </div>
          <div class="service-card">
            <div class="service-icon">⚙️</div>
            <h3 class="service-title">Integraciones Empresariales & MuleSoft</h3>
            <p class="service-desc">Conexión resiliente de arquitecturas legadas y ERPs hacia la nube mediante microservicios, APIs y buses empresariales con alta disponibilidad.</p>
            <a href="#contacto" class="service-link btn-contact-open">Planificar integración ➜</a>
          </div>
        </div>
      </div>

      <!-- Tab Content 4: Squads TI Offshore -->
      <div class="tab-pane" id="tab-offshore">
        <div class="services-grid">
          <div class="service-card">
            <div class="service-icon">🌐</div>
            <h3 class="service-title">Servicios TI Offshore & BPO</h3>
            <p class="service-desc">Células de desarrollo ágiles y consultores dedicados de alto seniority para proyectos corporativos con gestión centralizada y gobierno transparente.</p>
            <a href="#contacto" class="service-link btn-contact-open">Solicitar squad técnico ➜</a>
          </div>
          <div class="service-card">
            <div class="service-icon">🛡️</div>
            <h3 class="service-title">vCISO & Cumplimiento Normativo</h3>
            <p class="service-desc">Liderazgo de seguridad fraccional para cumplir con la nueva Ley Marco de Ciberseguridad, normativas CMF bancarias e ISO 27001 sin el costo de un CISO a tiempo completo.</p>
            <a href="#contacto" class="service-link btn-contact-open">Consultar vCISO ➜</a>
          </div>
        </div>
      </div>

    </div>
  </section>

  <!-- TrustZero Credibility Strip (Dogfooding Banner) -->
  <section class="trustzero-strip">
    <div class="trustzero-container">
      <div class="trustzero-left">
        <div class="trustzero-icon">🔒</div>
        <div>
          <h4 style="color:#ffffff; font-size:1.15rem; margin-bottom:0.25rem;">Programa TrustZero: Auto-Monitoreo y Casa de Cristal</h4>
          <p style="color:#94a3b8; font-size:0.88rem; max-width:600px;">Para proteger a terceros, en Keystone Advisors operamos bajo las mismas normas que implementamos: 100% llaves físicas FIDO2, DMARC reject y auditoría continua.</p>
        </div>
      </div>
      <div class="trustzero-badges">
        <div class="trustzero-pill">
          <span>✓</span> <strong>DMARC:</strong> p=reject
        </div>
        <div class="trustzero-pill">
          <span>✓</span> <strong>Acceso:</strong> FIDO2 Hardware
        </div>
        <div class="trustzero-pill">
          <span>✓</span> <strong>DevSecOps:</strong> Commits Firmados GPG
        </div>
      </div>
    </div>
  </section>

  <!-- Products & Store Section (New B2B eCommerce Module) -->
  <section class="section products-section" id="productos">
    <div class="section-container">
      <div class="section-header">
        <span class="badge-dev">Venta Directa Online</span>
        <h2 class="section-title">Soluciones y Paquetes de Consultoría Empaquetados</h2>
        <p class="section-desc">Contrate directamente en línea con alcance definido, tiempos garantizados y puesta en marcha inmediata.</p>
      </div>
      <div class="products-grid">
        
        <!-- Product 1 -->
        <div class="product-card">
          <div class="product-category">Acelerador Salesforce</div>
          <h3 class="product-name">Salesforce Starter Suite</h3>
          <p style="color:var(--text-muted); font-size:0.9rem;">Ideal para empresas en crecimiento que requieren activar su primer CRM con mejores prácticas comerciales.</p>
          <div class="product-price">
            <span class="prefix">Desde</span>
            <span class="currency price-cur" data-price-cur>USD</span>
            <span class="amount price-amt" data-price-usd="2.490" data-price-uf="60">$2.490</span>
            <span class="period">/ Pago único</span>
          </div>
          <ul class="product-features">
            <li>Configuración inicial estándar de Leads y Oportunidades</li>
            <li>Carga masiva inicial de hasta 1.000 contactos</li>
            <li>Capacitación ejecutiva para administradores (4 hrs)</li>
            <li>Entrega y salida a producción en 2 semanas</li>
          </ul>
          <button class="btn btn-secondary btn-buy-product" 
                  data-product-id="PROD-SF-STARTER" 
                  data-product-name="Salesforce Starter Suite" 
                  data-product-price="Desde USD $2.490"
                  data-price-usd-label="Desde USD $2.490"
                  data-price-uf-label="Desde UF 60 (Pago único)">
            Contratar Paquete
          </button>
        </div>

        <!-- Product 2 (Featured) -->
        <div class="product-card featured">
          <div class="product-ribbon">Recomendado</div>
          <div class="product-category">Implementación Rápida</div>
          <h3 class="product-name">Salesforce Quick Starter</h3>
          <p style="color:var(--text-muted); font-size:0.9rem;">Habilite Service Cloud o Sales Cloud con automatizaciones y reportes directivos listos en 4 semanas.</p>
          <div class="product-price">
            <span class="prefix">Desde</span>
            <span class="currency price-cur" data-price-cur>USD</span>
            <span class="amount price-amt" data-price-usd="8.000" data-price-uf="190">$8.000</span>
            <span class="period">/ Pago único</span>
          </div>
          <ul class="product-features">
            <li>Parametrización completa de Service Cloud / Casos / SLA</li>
            <li>Flujos de trabajo automáticos (Flows) y reglas de asignación</li>
            <li>Tablero directivo de gestión y reportes KPIs en vivo</li>
            <li>Acompañamiento post-go live y soporte hipercare</li>
            <li>Soporte de integración con KeyHub incluido</li>
          </ul>
          <button class="btn btn-primary btn-buy-product" 
                  data-product-id="PROD-SF-QUICKSTARTER" 
                  data-product-name="Salesforce Quick Starter (4 Semanas)" 
                  data-product-price="Desde USD $8.000"
                  data-price-usd-label="Desde USD $8.000"
                  data-price-uf-label="Desde UF 190 (Pago único)">
            Contratar Ahora ➜
          </button>
        </div>

        <!-- Product 3 -->
        <div class="product-card">
          <div class="product-category">Soporte Continuo & Horas</div>
          <h3 class="product-name">Bolsa Soporte IT Flex</h3>
          <p style="color:var(--text-muted); font-size:0.9rem;">Soporte experto continuo multi-tecnología (Salesforce, Apex, LWC, Cloud, Integraciones) en horario de oficina.</p>
          <div class="product-price">
            <span class="prefix">Desde</span>
            <span class="currency price-cur" data-price-cur>USD</span>
            <span class="amount price-amt" data-price-usd="2.750" data-price-uf="68">$2.750</span>
            <span class="period">/ Mes</span>
          </div>
          <div class="product-price-sub price-sub" data-sub-usd="Período mínimo de contratación: 3 meses" data-sub-uf="Período mínimo de contratación: 3 meses (Desde UF 68 / mes)">Período mínimo de contratación: 3 meses</div>
          <ul class="product-features">
            <li><span class="item-label">Modalidad Mensual:</span> Compromiso mínimo por períodos de 3 meses</li>
            <li><span class="item-label">Horario de Oficina:</span> Lunes a Viernes de 09:00 a 18:00 hrs</li>
            <li><span class="item-label">Sobregiro Flexible:</span> Hasta 10% del período siguiente sin costo adicional</li>
            <li><span class="item-label">Recuperación de Horas:</span> Hasta 20% no consumido para usar en 60 días <span class="feature-benefit-tag">Exclusivo &gt;6M</span></li>
            <li>Acceso directo al Tablero Kanban de soporte y gestión en KeyHub</li>
            <li>Trazabilidad al minuto con cronómetro y bitácora técnica</li>
            <li>Respuesta garantizada bajo acuerdo de nivel de servicio (SLA)</li>
          </ul>
          <button class="btn btn-secondary btn-buy-product" 
                  data-product-id="PROD-HOURS-FLEX" 
                  data-product-name="Bolsa Soporte IT Flex Mensual (Mín. 3 meses)" 
                  data-product-price="Desde USD $2.750 / Mes"
                  data-price-usd-label="Desde USD $2.750 / Mes (Mín. 3 meses)"
                  data-price-uf-label="Desde UF 68 / Mes (Mín. 3 meses)">
            Adquirir Plan Soporte
          </button>
        </div>

        <!-- Product 4: Executive Cyber Risk Assessment (Gancho Quick-Win) -->
        <div class="product-card" style="border: 2px solid #10b981;">
          <div class="product-ribbon" style="background:#10b981;">Diagnóstico Rápido</div>
          <div class="product-category" style="color:#059669;">Ciberseguridad Directiva</div>
          <h3 class="product-name">Executive Cyber Risk Assessment</h3>
          <p style="color:var(--text-muted); font-size:0.9rem;">Radiografía forense pasiva en 72 horas para CFOs, Gerentes TI y Comités de Auditoría. Cero fricción de instalación.</p>
          <div class="product-price">
            <span class="prefix">Desde</span>
            <span class="currency price-cur" data-price-cur>USD</span>
            <span class="amount price-amt" data-price-usd="950" data-price-uf="23">$950</span>
            <span class="period">/ Entrega 72 hrs</span>
          </div>
          <div class="product-price-sub price-sub" data-sub-usd="Informe Ejecutivo de 3 Páginas + Sesión Consultiva" data-sub-uf="Informe Ejecutivo de 3 Páginas + Sesión Consultiva (UF 23)">Informe Ejecutivo de 3 Páginas + Sesión Consultiva</div>
          <ul class="product-features">
            <li><span class="item-label">Auditoría DMARC / SPF:</span> Medición de vulnerabilidad a estafas BEC</li>
            <li><span class="item-label">Superficie de Exposición:</span> Detección de puertos y subdominios expuestos</li>
            <li><span class="item-label">Filtro Ley Marco:</span> Análisis de brechas normativas e impacto legal</li>
            <li><span class="item-label">Entregable Ejecutivo:</span> PDF directivo con matriz de riesgos y semáforo</li>
            <li><span class="item-label">Sesión de Remediación:</span> 45 min con especialista senior de Keystone</li>
          </ul>
          <button class="btn btn-primary btn-buy-product" 
                  data-product-id="PROD-CYBER-ASSESS" 
                  data-product-name="Executive Cyber Risk Assessment (72h)" 
                  data-product-price="Desde USD $950"
                  data-price-usd-label="Desde USD $950"
                  data-price-uf-label="Desde UF 23 (Entrega 72 hrs)"
                  style="background: linear-gradient(135deg, #059669 0%, #10b981 100%);">
            Solicitar Assessment ➜
          </button>
        </div>

        <!-- Product 5: Blindaje de Tesorería DeepShield -->
        <div class="product-card" style="border: 1.5px solid #0284c7; background: linear-gradient(180deg, #ffffff 0%, #f0f9ff 100%);">
          <div class="product-category" style="color:#0284c7; font-weight:800;">Protección Financiera & Anti-Fraude IA</div>
          <h3 class="product-name">DeepShield Treasury</h3>
          <p style="color:var(--text-muted); font-size:0.9rem;">Custodia perimetral y validación fuera de banda (OOB) contra desvío de pagos y clonación de voz en tesorería.</p>
          <div class="product-price">
            <span class="prefix">Desde</span>
            <span class="currency price-cur" data-price-cur>USD</span>
            <span class="amount price-amt" data-price-usd="490" data-price-uf="12">$490</span>
            <span class="period">/ Mes</span>
          </div>
          <div class="product-price-sub price-sub" data-sub-usd="3 planes según volumen de transferencias y directores" data-sub-uf="3 planes desde UF 12 / mes según volumen">3 planes según volumen de transferencias y directores</div>
          <ul class="product-features">
            <li><span class="item-label">Validación OOB:</span> Aprobación biométrica en smartphones</li>
            <li><span class="item-label">Anti-Deepfake:</span> Desafíos criptográficos de control en vivo</li>
            <li><span class="item-label">Integración Multicanal:</span> Notificaciones WhatsApp & Correo</li>
            <li><span class="item-label">Sello SHA-256:</span> Evidencia inmutable ante auditorías y bancos</li>
          </ul>
          <div style="display:flex; flex-direction:column; gap:0.5rem; margin-top:1rem;">
            <a href="#planes-deepshield" class="btn btn-primary" style="text-align:center; text-decoration:none; background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);">
              Ver Planes DeepShield ➜
            </a>
            <a href="#como-opera-deepshield" style="font-size:0.82rem; color:#0284c7; text-decoration:none; font-weight:700; text-align:center; padding:0.25rem;">
              ¿Cómo opera el protocolo OOB? ➜
            </a>
          </div>
        </div>

        <!-- Product 6: Red Teaming Asistido por IA -->
        <div class="product-card" style="border: 1.5px solid #d97706; background: linear-gradient(180deg, #ffffff 0%, #fffbeb 100%);">
          <div class="product-category" style="color:#d97706; font-weight:800;">Ingeniería Social & Red Teaming</div>
          <h3 class="product-name">Red Teaming con IA</h3>
          <p style="color:var(--text-muted); font-size:0.9rem;">Simulaciones contextuales con LLM, clonación de voz sintética (Vishing) y remediación humana ante la Ley N° 21.459.</p>
          <div class="product-price">
            <span class="prefix">Desde</span>
            <span class="currency price-cur" data-price-cur>USD</span>
            <span class="amount price-amt" data-price-usd="650" data-price-uf="16">$650</span>
            <span class="period">/ Mes</span>
          </div>
          <div class="product-price-sub price-sub" data-sub-usd="Campaña táctica o programa anual continuo" data-sub-uf="Planes desde UF 16 / mes o UF 28 por campaña">Campaña táctica o programa anual continuo</div>
          <ul class="product-features">
            <li><span class="item-label">Señuelos con LLM:</span> Correos contextuales hiperpersonalizados</li>
            <li><span class="item-label">Vishing Telefónico:</span> Pruebas éticas de voz sintética</li>
            <li><span class="item-label">Simulador Online:</span> Prueba ciega inmediata en vivo</li>
            <li><span class="item-label">Informe Forense:</span> Cumplimiento pericial Ley N° 21.459</li>
          </ul>
          <div style="display:flex; flex-direction:column; gap:0.5rem; margin-top:1rem;">
            <a href="#planes-redteaming" class="btn btn-primary" style="text-align:center; text-decoration:none; background: linear-gradient(135deg, #d97706 0%, #b45309 100%);">
              Ver Planes Red Teaming ➜
            </a>
            <a href="#como-opera-redteaming" style="font-size:0.82rem; color:#d97706; text-decoration:none; font-weight:700; text-align:center; padding:0.25rem;">
              ¿Cómo opera el Red Teaming? ➜
            </a>
          </div>
        </div>

      </div>
    </div>
  </section>

  <!-- Sección Arquitectura Operativa DeepShield Treasury -->
  <section class="section" id="como-opera-deepshield" style="background:#0b1329; color:#f1f5f9; padding: 4.5rem 0; border-top: 1px solid #1e293b; border-bottom: 1px solid #1e293b;">
    <div class="section-container">
      <div class="section-header" style="text-align:center; margin-bottom: 3.5rem;">
        <span class="badge-dev" style="background:rgba(56, 189, 248, 0.15); color:#38bdf8; border:1px solid #0284c7;">Metodología Propietaria Keystone</span>
        <h2 class="section-title" style="color:#ffffff;">Cadena de Custodia & Gobernanza de Pagos</h2>
        <p class="section-desc" style="color:#94a3b8; max-width: 720px; margin: 0 auto;">
          Conozca cómo nuestro protocolo institucional protege el patrimonio corporativo neutralizando vectores de clonación de voz y suplantación de proveedores sin alterar sus plataformas contables existentes.
        </p>
      </div>

      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.5rem; margin-bottom: 3rem;">
        
        <!-- Fase 1 -->
        <div style="background:#131f37; border:1px solid #1e3a5f; border-radius:12px; padding:1.75rem; position:relative;">
          <div style="font-size:0.75rem; font-weight:800; color:#38bdf8; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:0.5rem;">01. Gobernanza & Umbrales</div>
          <h4 style="color:#ffffff; font-size:1.1rem; margin-bottom:0.75rem;">Detección Perimetral</h4>
          <p style="font-size:0.85rem; color:#94a3b8; line-height:1.5;">
            Parámetros institucionales preacordados activan la custodia ante desembolsos críticos o cualquier solicitud de actualización de cuentas corrientes de proveedores.
          </p>
        </div>

        <!-- Fase 2 -->
        <div style="background:#131f37; border:1px solid #1e3a5f; border-radius:12px; padding:1.75rem; position:relative;">
          <div style="font-size:0.75rem; font-weight:800; color:#10b981; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:0.5rem;">02. Blindaje Anti-Voz IA</div>
          <h4 style="color:#ffffff; font-size:1.1rem; margin-bottom:0.75rem;">Desafío Fuera de Banda</h4>
          <p style="font-size:0.85rem; color:#94a3b8; line-height:1.5;">
            Canal de confrontación independiente que desacopla la instrucción telefónica o por correo, invalidando vectores generativos de voz sintética o correos comprometidos.
          </p>
        </div>

        <!-- Fase 3 -->
        <div style="background:#131f37; border:1px solid #1e3a5f; border-radius:12px; padding:1.75rem; position:relative;">
          <div style="font-size:0.75rem; font-weight:800; color:#f59e0b; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:0.5rem;">03. Aprobación Ejecutiva</div>
          <h4 style="color:#ffffff; font-size:1.1rem; margin-bottom:0.75rem;">Visado en Segundos</h4>
          <p style="font-size:0.85rem; color:#94a3b8; line-height:1.5;">
            El directivo habilitado examina en su dispositivo personal los 3 atributos cardinales (beneficiario, cuenta e importe) y emite su aprobación segura en un solo toque.
          </p>
        </div>

        <!-- Fase 4 -->
        <div style="background:#131f37; border:1px solid #1e3a5f; border-radius:12px; padding:1.75rem; position:relative;">
          <div style="font-size:0.75rem; font-weight:800; color:#a855f7; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:0.5rem;">04. Fe Pública Digital</div>
          <h4 style="color:#ffffff; font-size:1.1rem; margin-bottom:0.75rem;">Sello Criptográfico</h4>
          <p style="font-size:0.85rem; color:#94a3b8; line-height:1.5;">
            Emisión de una firma matemática inmutable anexada al comprobante de egreso, proporcionando trazabilidad irrebatible ante aseguradoras, auditorías y directorios.
          </p>
        </div>

      </div>

      <!-- Matriz de Planes Comerciales DeepShield Treasury -->
      <div id="planes-deepshield" style="margin-top: 4rem; padding-top: 2rem; border-top: 1px solid rgba(255,255,255,0.1);">
        <div style="text-align:center; margin-bottom: 3rem;">
          <span class="badge-dev" style="background:rgba(16, 185, 129, 0.15); color:#10b981; border:1px solid #059669;">Licenciamiento & Planes de Custodia</span>
          <h3 style="font-size:2rem; font-weight:800; color:#ffffff; margin-top:0.5rem; margin-bottom:0.5rem;">Planes de Blindaje Operativo DeepShield</h3>
          <p style="color:#94a3b8; font-size:0.95rem; max-width:680px; margin:0 auto;">Seleccione el nivel de protección de tesorería adaptado al flujo de desembolsos y la estructura de firmas de su compañía.</p>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.75rem;">
          
          <!-- Plan 1: DeepShield Starter -->
          <div style="background:#131f37; border: 1px solid #1e3a5f; border-radius: 14px; padding: 2.25rem 1.75rem; display:flex; flex-direction:column; position:relative;">
            <div style="font-size:0.8rem; font-weight:800; color:#38bdf8; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:0.35rem;">Plan PyME & Medianas</div>
            <h4 style="font-size:1.4rem; font-weight:800; color:#ffffff; margin-bottom:0.5rem;">DeepShield Starter</h4>
            <p style="font-size:0.85rem; color:#94a3b8; margin-bottom:1.25rem; min-height:40px;">Para empresas en crecimiento que requieren blindar pagos críticos y cambios de cuentas bancarias de proveedores.</p>
            
            <div style="margin-bottom:1.25rem; padding-bottom:1.25rem; border-bottom:1px solid #1e3a5f;">
              <span style="font-size:0.85rem; color:#94a3b8;">Desde</span>
              <span class="price-cur" data-price-cur style="font-size:1.1rem; font-weight:700; color:#38bdf8;">USD</span>
              <span class="price-amt" data-price-usd="490" data-price-uf="12" style="font-size:2.2rem; font-weight:800; color:#ffffff;">$490</span>
              <span style="font-size:0.85rem; color:#94a3b8;">/ Mes</span>
              <div class="price-sub" data-sub-usd="Hasta 30 transferencias custodiadas / mes" data-sub-uf="Hasta 30 transferencias custodiadas / mes (Desde UF 12 / mes)" style="font-size:0.75rem; color:#64748b; margin-top:0.25rem;">Hasta 30 transferencias custodiadas / mes</div>
            </div>

            <ul style="list-style:none; padding:0; margin:0 0 1.75rem 0; font-size:0.85rem; color:#cbd5e1; display:flex; flex-direction:column; gap:0.65rem;">
              <li style="display:flex; align-items:flex-start; gap:0.5rem;"><span style="color:#10b981;">✓</span> <span><strong>1 Operador de Tesorería</strong> + 1 Director Aprobador</span></li>
              <li style="display:flex; align-items:flex-start; gap:0.5rem;"><span style="color:#10b981;">✓</span> <span>Portal de Autoservicio de Carga de Pagos</span></li>
              <li style="display:flex; align-items:flex-start; gap:0.5rem;"><span style="color:#10b981;">✓</span> <span>Validación Fuera de Banda (OOB) en Smartphone</span></li>
              <li style="display:flex; align-items:flex-start; gap:0.5rem;"><span style="color:#10b981;">✓</span> <span>Notificaciones vía <strong>Correo Electrónico Seguro</strong></span></li>
              <li style="display:flex; align-items:flex-start; gap:0.5rem;"><span style="color:#10b981;">✓</span> <span>Desafío de Palabra de Control Anti-Vishing</span></li>
              <li style="display:flex; align-items:flex-start; gap:0.5rem;"><span style="color:#10b981;">✓</span> <span>Sello Criptográfico SHA-256 por cada pago</span></li>
            </ul>

            <button class="btn btn-secondary btn-buy-product" 
                    data-product-id="PROD-DEEPSHIELD-STARTER" 
                    data-product-name="DeepShield Starter" 
                    data-product-price="Desde USD $490 / Mes"
                    data-price-usd-label="Desde USD $490 / Mes"
                    data-price-uf-label="Desde UF 12 / Mes"
                    style="margin-top:auto; width:100%; justify-content:center; background:#1e293b; color:#ffffff; border-color:#334155;">
              Contratar Starter
            </button>
          </div>

          <!-- Plan 2: DeepShield Professional (Destacado) -->
          <div style="background:#132544; border: 2px solid #0284c7; border-radius: 14px; padding: 2.25rem 1.75rem; display:flex; flex-direction:column; position:relative; box-shadow:0 15px 35px -5px rgba(2,132,199,0.3);">
            <div style="position:absolute; top:-12px; left:50%; transform:translateX(-50%); background:#0284c7; color:#ffffff; font-size:0.72rem; font-weight:800; padding:0.25rem 0.85rem; border-radius:99px; text-transform:uppercase; letter-spacing:0.5px;">Recomendado Corporativo</div>
            <div style="font-size:0.8rem; font-weight:800; color:#38bdf8; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:0.35rem;">Grandes Empresas</div>
            <h4 style="font-size:1.4rem; font-weight:800; color:#ffffff; margin-bottom:0.5rem;">DeepShield Professional</h4>
            <p style="font-size:0.85rem; color:#cbd5e1; margin-bottom:1.25rem; min-height:40px;">Para empresas con alto flujo de pagos semanales, múltiples firmantes y alertas instantáneas a WhatsApp.</p>
            
            <div style="margin-bottom:1.25rem; padding-bottom:1.25rem; border-bottom:1px solid #1e3a5f;">
              <span style="font-size:0.85rem; color:#94a3b8;">Desde</span>
              <span class="price-cur" data-price-cur style="font-size:1.1rem; font-weight:700; color:#38bdf8;">USD</span>
              <span class="price-amt" data-price-usd="1.250" data-price-uf="30" style="font-size:2.2rem; font-weight:800; color:#ffffff;">$1.250</span>
              <span style="font-size:0.85rem; color:#94a3b8;">/ Mes</span>
              <div class="price-sub" data-sub-usd="Hasta 150 transferencias custodiadas / mes" data-sub-uf="Hasta 150 transferencias custodiadas / mes (Desde UF 30 / mes)" style="font-size:0.75rem; color:#38bdf8; margin-top:0.25rem; font-weight:700;">Hasta 150 transferencias custodiadas / mes</div>
            </div>

            <ul style="list-style:none; padding:0; margin:0 0 1.75rem 0; font-size:0.85rem; color:#f1f5f9; display:flex; flex-direction:column; gap:0.65rem;">
              <li style="display:flex; align-items:flex-start; gap:0.5rem;"><span style="color:#10b981;">✓</span> <span><strong>Todo lo del Plan Starter</strong></span></li>
              <li style="display:flex; align-items:flex-start; gap:0.5rem;"><span style="color:#10b981;">✓</span> <span><strong>Hasta 3 Operadores de Tesorería</strong> + 3 Directores Firmantes</span></li>
              <li style="display:flex; align-items:flex-start; gap:0.5rem;"><span style="color:#10b981;">✓</span> <span><strong>Alertas Inmediatas vía WhatsApp Bot & SMS</strong></span></li>
              <li style="display:flex; align-items:flex-start; gap:0.5rem;"><span style="color:#10b981;">✓</span> <span>Validación de doble factor con biometría móvil</span></li>
              <li style="display:flex; align-items:flex-start; gap:0.5rem;"><span style="color:#10b981;">✓</span> <span>Historial de auditoría descargable para Compliance</span></li>
              <li style="display:flex; align-items:flex-start; gap:0.5rem;"><span style="color:#10b981;">✓</span> <span>Soporte prioritario 24/7 ante sospechas de fraude</span></li>
            </ul>

            <button class="btn btn-primary btn-buy-product" 
                    data-product-id="PROD-DEEPSHIELD-PRO" 
                    data-product-name="DeepShield Professional" 
                    data-product-price="Desde USD $1.250 / Mes"
                    data-price-usd-label="Desde USD $1.250 / Mes"
                    data-price-uf-label="Desde UF 30 / Mes"
                    style="margin-top:auto; width:100%; justify-content:center; background:linear-gradient(135deg, #0284c7 0%, #0369a1 100%); font-weight:700;">
              Contratar Plan Professional ➜
            </button>
          </div>

          <!-- Plan 3: DeepShield Enterprise / Corporate -->
          <div style="background:#131f37; border: 1px solid #1e3a5f; border-radius: 14px; padding: 2.25rem 1.75rem; display:flex; flex-direction:column; position:relative;">
            <div style="font-size:0.8rem; font-weight:800; color:#a855f7; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:0.35rem;">Banca & Corporativos</div>
            <h4 style="font-size:1.4rem; font-weight:800; color:#ffffff; margin-bottom:0.5rem;">DeepShield Enterprise</h4>
            <p style="font-size:0.85rem; color:#94a3b8; margin-bottom:1.25rem; min-height:40px;">Custodia ilimitada para holdings financieros, tesorerías complejas, integración API y despliegue dedicado.</p>
            
            <div style="margin-bottom:1.25rem; padding-bottom:1.25rem; border-bottom:1px solid #1e3a5f;">
              <span style="font-size:0.85rem; color:#94a3b8;">Desde</span>
              <span class="price-cur" data-price-cur style="font-size:1.1rem; font-weight:700; color:#a855f7;">USD</span>
              <span class="price-amt" data-price-usd="2.800" data-price-uf="68" style="font-size:2.2rem; font-weight:800; color:#ffffff;">$2.800</span>
              <span style="font-size:0.85rem; color:#94a3b8;">/ Mes</span>
              <div class="price-sub" data-sub-usd="Transferencias y pagos ilimitados" data-sub-uf="Transferencias y pagos ilimitados (Desde UF 68 / mes)" style="font-size:0.75rem; color:#64748b; margin-top:0.25rem;">Transferencias y pagos ilimitados</div>
            </div>

            <ul style="list-style:none; padding:0; margin:0 0 1.75rem 0; font-size:0.85rem; color:#cbd5e1; display:flex; flex-direction:column; gap:0.65rem;">
              <li style="display:flex; align-items:flex-start; gap:0.5rem;"><span style="color:#10b981;">✓</span> <span><strong>Todo lo del Plan Professional</strong></span></li>
              <li style="display:flex; align-items:flex-start; gap:0.5rem;"><span style="color:#10b981;">✓</span> <span><strong>Operadores y Firmantes Ilimitados</strong> (Multi-Razón Social)</span></li>
              <li style="display:flex; align-items:flex-start; gap:0.5rem;"><span style="color:#10b981;">✓</span> <span><strong>Conexión API REST directa con SAP / Oracle / ERP</strong></span></li>
              <li style="display:flex; align-items:flex-start; gap:0.5rem;"><span style="color:#10b981;">✓</span> <span>Instancia dedicada o despliegue On-Premise / Cloud privada</span></li>
              <li style="display:flex; align-items:flex-start; gap:0.5rem;"><span style="color:#10b981;">✓</span> <span>Asesor de Ciberfraude asignado y SLA de 15 minutos</span></li>
              <li style="display:flex; align-items:flex-start; gap:0.5rem;"><span style="color:#10b981;">✓</span> <span>Auditoría pericial ante pólizas de ciberriesgo bancario</span></li>
            </ul>

            <button class="btn btn-secondary btn-buy-product" 
                    data-product-id="PROD-DEEPSHIELD-ENTERPRISE" 
                    data-product-name="DeepShield Enterprise" 
                    data-product-price="Desde USD $2.800 / Mes"
                    data-price-usd-label="Desde USD $2.800 / Mes"
                    data-price-uf-label="Desde UF 68 / Mes"
                    style="margin-top:auto; width:100%; justify-content:center; background:#1e293b; color:#ffffff; border-color:#a855f7;">
              Cotizar Plan Enterprise
            </button>
          </div>

        </div>
      </div>

      <!-- Protocol Box -->
      <div style="background:linear-gradient(135deg, #002244 0%, #001730 100%); border:1px solid #00558f; border-radius:12px; padding:1.75rem 2rem; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1.5rem; margin-top:3.5rem;">
        <div>
          <h4 style="color:#ffffff; font-size:1.15rem; margin-bottom:0.35rem;">¿Desea habilitar la custodia de DeepShield en su empresa?</h4>
          <p style="color:#cbd5e1; font-size:0.88rem;">Puesta en marcha en 48 horas sin requerir credenciales bancarias ni alterar su ERP.</p>
        </div>
        <button class="btn btn-primary btn-buy-product" 
                data-product-id="PROD-DEEPSHIELD-PRO" 
                data-product-name="DeepShield Professional" 
                data-product-price="Desde USD $1.250 / Mes"
                style="background:linear-gradient(135deg, #059669 0%, #10b981 100%); padding:0.8rem 1.6rem; font-weight:700;">
          Agendar Sesión de Habilitación ➜
        </button>
      </div>

    </div>
  </section>

  <!-- Sección Arquitectura Operativa Red Teaming Asistido por IA -->
  <section class="section" id="como-opera-redteaming" style="background:#080e1e; color:#f1f5f9; padding: 4.5rem 0; border-bottom: 1px solid #1e293b;">
    <div class="section-container">
      <div class="section-header" style="text-align:center; margin-bottom: 3.5rem;">
        <span class="badge-dev" style="background:rgba(217, 119, 6, 0.15); color:#f59e0b; border:1px solid #d97706;">Ingeniería Social Ofensiva & Blindaje Humano</span>
        <h2 class="section-title" style="color:#ffffff; font-size:2.3rem; font-weight:800; margin-top:0.75rem;">¿Cómo Opera el Red Teaming Asistido por IA?</h2>
        <p class="section-desc" style="color:#94a3b8; max-width:760px; margin:0 auto; font-size:1.05rem;">
          Anticipe el fraude corporativo antes de que ocurra. Emulamos ataques avanzados de ciberdelincuentes utilizando modelos LLM contextuales y clonación de voz sintética para evaluar y blindar su primera línea de defensa: las personas.
        </p>
      </div>

      <!-- Grid de 4 Pasos Metodológicos -->
      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
        
        <!-- Fase 1 -->
        <div style="background:#111b2f; border:1px solid #1e293b; border-radius:12px; padding:1.75rem; position:relative;">
          <div style="font-size:0.75rem; font-weight:800; color:#f59e0b; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:0.5rem;">01. Reconocimiento OSINT</div>
          <h4 style="color:#ffffff; font-size:1.1rem; margin-bottom:0.75rem;">Mapeo de Huella Digital con IA</h4>
          <p style="font-size:0.85rem; color:#94a3b8; line-height:1.5;">
            Recolección ética y automatizada de fuentes públicas (LinkedIn, licitaciones de Mercado Público, notas de prensa y contratos) para perfilar jerarquías ejecutivas, proveedores recurrentes y jerga corporativa real.
          </p>
        </div>

        <!-- Fase 2 -->
        <div style="background:#111b2f; border:1px solid #1e293b; border-radius:12px; padding:1.75rem; position:relative;">
          <div style="font-size:0.75rem; font-weight:800; color:#38bdf8; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:0.5rem;">02. Armado de Vector</div>
          <h4 style="color:#ffffff; font-size:1.1rem; margin-bottom:0.75rem;">Hiperpersonalización LLM & Voz</h4>
          <p style="font-size:0.85rem; color:#94a3b8; line-height:1.5;">
            Generación de señuelos indetectables: correos con tono idéntico al del Director General (BEC), notificaciones tributarias verosímiles y llamadas controladas de voz sintética (Vishing) con números locales autorizados.
          </p>
        </div>

        <!-- Fase 3 -->
        <div style="background:#111b2f; border:1px solid #1e293b; border-radius:12px; padding:1.75rem; position:relative;">
          <div style="font-size:0.75rem; font-weight:800; color:#ef4444; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:0.5rem;">03. Ejecución Controlada</div>
          <h4 style="color:#ffffff; font-size:1.1rem; margin-bottom:0.75rem;">Simulación Ciega a Tesorería</h4>
          <p style="font-size:0.85rem; color:#94a3b8; line-height:1.5;">
            Despliegue sin preaviso a colaboradores clave para medir en tiempo real el porcentaje de apertura, clics en enlaces, descarga de archivos o disposición a autorizar pagos fuera de los canales oficiales.
          </p>
        </div>

        <!-- Fase 4 -->
        <div style="background:#111b2f; border:1px solid #1e293b; border-radius:12px; padding:1.75rem; position:relative;">
          <div style="font-size:0.75rem; font-weight:800; color:#10b981; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:0.5rem;">04. Remediación & Cumplimiento</div>
          <h4 style="color:#ffffff; font-size:1.1rem; margin-bottom:0.75rem;">Informe Forense & Capacitación</h4>
          <p style="font-size:0.85rem; color:#94a3b8; line-height:1.5;">
            Entrega de informe pericial con índice de susceptibilidad, brechas humanas identificadas, cumplimiento de la Ley N° 21.459 y micro-cápsulas de concienciación en el instante del clic («Teachable Moment»).
          </p>
        </div>

      </div>

      <!-- Botón de Disparo Rápido de Simulador -->
      <div style="text-align:center; margin-bottom:4rem;">
        <button type="button" onclick="openRedTeamSimulatorModal()" class="btn btn-secondary" style="background:#1e293b; border-color:#d97706; color:#f59e0b; padding:0.75rem 1.75rem; font-weight:700;">
          🎭 Probar Simulador de Ataques con IA en Vivo ➜
        </button>
      </div>

      <!-- Matriz de Planes Comerciales Red Teaming Asistido por IA -->
      <div id="planes-redteaming" style="margin-top: 3rem; padding-top: 2rem; border-top: 1px solid rgba(255,255,255,0.1);">
        <div style="text-align:center; margin-bottom: 3rem;">
          <span class="badge-dev" style="background:rgba(217, 119, 6, 0.15); color:#f59e0b; border:1px solid #d97706;">Programas Corporativos de Simulación</span>
          <h3 style="font-size:2rem; font-weight:800; color:#ffffff; margin-top:0.5rem; margin-bottom:0.5rem;">Planes de Simulación & Red Teaming</h3>
          <p style="color:#94a3b8; font-size:0.95rem; max-width:680px; margin:0 auto;">Entrene y certifique a sus equipos contra ataques de ingeniería social de última generación asistidos por Inteligencia Artificial.</p>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.75rem;">
          
          <!-- Plan 1: Campaña Táctica Diagnóstica (One-Off) -->
          <div style="background:#111b2f; border: 1px solid #1e293b; border-radius: 14px; padding: 2.25rem 1.75rem; display:flex; flex-direction:column; position:relative;">
            <div style="font-size:0.8rem; font-weight:800; color:#f59e0b; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:0.35rem;">Diagnóstico Inicial</div>
            <h4 style="font-size:1.4rem; font-weight:800; color:#ffffff; margin-bottom:0.5rem;">Campaña Táctica One-Off</h4>
            <p style="font-size:0.85rem; color:#94a3b8; margin-bottom:1.25rem; min-height:40px;">Evaluación puntual para medir la postura de ciberseguridad humana y detectar vulnerabilidades críticas de tesorería.</p>
            
            <div style="margin-bottom:1.25rem; padding-bottom:1.25rem; border-bottom:1px solid #1e293b;">
              <span style="font-size:0.85rem; color:#94a3b8;">Desde</span>
              <span class="price-cur" data-price-cur style="font-size:1.1rem; font-weight:700; color:#f59e0b;">USD</span>
              <span class="price-amt" data-price-usd="1.150" data-price-uf="28" style="font-size:2.2rem; font-weight:800; color:#ffffff;">$1.150</span>
              <span style="font-size:0.85rem; color:#94a3b8;">/ Proyecto</span>
              <div class="price-sub" data-sub-usd="1 ejercicio completo (Hasta 50 colaboradores)" data-sub-uf="1 ejercicio completo (Desde UF 28 por campaña)" style="font-size:0.75rem; color:#64748b; margin-top:0.25rem;">1 ejercicio completo (Hasta 50 colaboradores)</div>
            </div>

            <ul style="list-style:none; padding:0; margin:0 0 1.75rem 0; font-size:0.85rem; color:#cbd5e1; display:flex; flex-direction:column; gap:0.65rem;">
              <li style="display:flex; align-items:flex-start; gap:0.5rem;"><span style="color:#10b981;">✓</span> <span><strong>1 Escenario Contextual Hiperpersonalizado</strong> con LLM</span></li>
              <li style="display:flex; align-items:flex-start; gap:0.5rem;"><span style="color:#10b981;">✓</span> <span>Evaluación de hasta <strong>50 colaboradores</strong> clave</span></li>
              <li style="display:flex; align-items:flex-start; gap:0.5rem;"><span style="color:#10b981;">✓</span> <span>Simulación de <strong>Fraude del CEO (BEC) o Cambio de Cuenta</strong></span></li>
              <li style="display:flex; align-items:flex-start; gap:0.5rem;"><span style="color:#10b981;">✓</span> <span>Retroalimentación inmediata interactiva al hacer clic</span></li>
              <li style="display:flex; align-items:flex-start; gap:0.5rem;"><span style="color:#10b981;">✓</span> <span>Informe pericial ejecutivo en PDF con recomendaciones</span></li>
              <li style="display:flex; align-items:flex-start; gap:0.5rem;"><span style="color:#10b981;">✓</span> <span>Sesión ejecutiva de 45 min con especialista Keystone</span></li>
            </ul>

            <button class="btn btn-secondary btn-buy-product" 
                    data-product-id="PROD-REDTEAM-TACTIC" 
                    data-product-name="Red Teaming Campaña Táctica (One-Off)" 
                    data-product-price="Desde USD $1.150"
                    data-price-usd-label="Desde USD $1.150"
                    data-price-uf-label="Desde UF 28 (Campaña Táctica)"
                    style="margin-top:auto; width:100%; justify-content:center; background:#1e293b; color:#ffffff; border-color:#d97706;">
              Contratar Campaña Táctica
            </button>
          </div>

          <!-- Plan 2: Continuo Trimestral (Corporate Defense - Destacado) -->
          <div style="background:#14223d; border: 2px solid #d97706; border-radius: 14px; padding: 2.25rem 1.75rem; display:flex; flex-direction:column; position:relative; box-shadow:0 15px 35px -5px rgba(217,119,6,0.25);">
            <div style="position:absolute; top:-12px; left:50%; transform:translateX(-50%); background:#d97706; color:#ffffff; font-size:0.72rem; font-weight:800; padding:0.25rem 0.85rem; border-radius:99px; text-transform:uppercase; letter-spacing:0.5px;">Recomendado Anual</div>
            <div style="font-size:0.8rem; font-weight:800; color:#fbbf24; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:0.35rem;">Programa Trimestral</div>
            <h4 style="font-size:1.4rem; font-weight:800; color:#ffffff; margin-bottom:0.5rem;">Corporate Defense Anual</h4>
            <p style="font-size:0.85rem; color:#cbd5e1; margin-bottom:1.25rem; min-height:40px;">Entrenamiento y evaluación continua con 4 simulaciones al año, rotación de vectores y prueba de Vishing con voz sintética.</p>
            
            <div style="margin-bottom:1.25rem; padding-bottom:1.25rem; border-bottom:1px solid #1e3a5f;">
              <span style="font-size:0.85rem; color:#94a3b8;">Desde</span>
              <span class="price-cur" data-price-cur style="font-size:1.1rem; font-weight:700; color:#fbbf24;">USD</span>
              <span class="price-amt" data-price-usd="650" data-price-uf="16" style="font-size:2.2rem; font-weight:800; color:#ffffff;">$650</span>
              <span style="font-size:0.85rem; color:#94a3b8;">/ Mes</span>
              <div class="price-sub" data-sub-usd="Facturación anual (4 campañas al año / hasta 150 usuarios)" data-sub-uf="Facturación anual (Desde UF 16 / mes)" style="font-size:0.75rem; color:#fbbf24; margin-top:0.25rem; font-weight:700;">Facturación anual (4 campañas al año / hasta 150 usuarios)</div>
            </div>

            <ul style="list-style:none; padding:0; margin:0 0 1.75rem 0; font-size:0.85rem; color:#f1f5f9; display:flex; flex-direction:column; gap:0.65rem;">
              <li style="display:flex; align-items:flex-start; gap:0.5rem;"><span style="color:#10b981;">✓</span> <span><strong>4 Simulaciones al Año</strong> (1 por trimestre programado)</span></li>
              <li style="display:flex; align-items:flex-start; gap:0.5rem;"><span style="color:#10b981;">✓</span> <span>Hasta <strong>150 colaboradores</strong> evaluados de forma continua</span></li>
              <li style="display:flex; align-items:flex-start; gap:0.5rem;"><span style="color:#10b981;">✓</span> <span><strong>Rotación de Vectores:</strong> BEC, Vishing Telefónico y Quishing QR</span></li>
              <li style="display:flex; align-items:flex-start; gap:0.5rem;"><span style="color:#10b981;">✓</span> <span><strong>1 Prueba de Vishing Telefónico</strong> con voz sintética controlada</span></li>
              <li style="display:flex; align-items:flex-start; gap:0.5rem;"><span style="color:#10b981;">✓</span> <span>Métricas de evolución del índice de riesgo humano</span></li>
              <li style="display:flex; align-items:flex-start; gap:0.5rem;"><span style="color:#10b981;">✓</span> <span>Certificado pericial de cumplimiento Ley 21.459 para auditorías</span></li>
            </ul>

            <button class="btn btn-primary btn-buy-product" 
                    data-product-id="PROD-REDTEAM-ANNUAL" 
                    data-product-name="Red Teaming Corporate Defense Anual" 
                    data-product-price="Desde USD $650 / Mes"
                    data-price-usd-label="Desde USD $650 / Mes"
                    data-price-uf-label="Desde UF 16 / Mes (Anual)"
                    style="margin-top:auto; width:100%; justify-content:center; background:linear-gradient(135deg, #d97706 0%, #b45309 100%); font-weight:700;">
              Contratar Programa Anual ➜
            </button>
          </div>

          <!-- Plan 3: Enterprise Continuous Threat (Holdings & Banca) -->
          <div style="background:#111b2f; border: 1px solid #1e293b; border-radius: 14px; padding: 2.25rem 1.75rem; display:flex; flex-direction:column; position:relative;">
            <div style="font-size:0.8rem; font-weight:800; color:#a855f7; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:0.35rem;">Corporaciones & Holdings</div>
            <h4 style="font-size:1.4rem; font-weight:800; color:#ffffff; margin-bottom:0.5rem;">Enterprise Threat Intel</h4>
            <p style="font-size:0.85rem; color:#94a3b8; margin-bottom:1.25rem; min-height:40px;">Simulaciones permanentes ilimitadas, monitoreo de filtraciones en Dark Web y protección ejecutiva personalizada C-Suite.</p>
            
            <div style="margin-bottom:1.25rem; padding-bottom:1.25rem; border-bottom:1px solid #1e293b;">
              <span style="font-size:0.85rem; color:#94a3b8;">Desde</span>
              <span class="price-cur" data-price-cur style="font-size:1.1rem; font-weight:700; color:#a855f7;">USD</span>
              <span class="price-amt" data-price-usd="1.450" data-price-uf="35" style="font-size:2.2rem; font-weight:800; color:#ffffff;">$1.450</span>
              <span style="font-size:0.85rem; color:#94a3b8;">/ Mes</span>
              <div class="price-sub" data-sub-usd="Simulaciones ilimitadas / multi-empresa" data-sub-uf="Simulaciones ilimitadas / multi-empresa (Desde UF 35 / mes)" style="font-size:0.75rem; color:#64748b; margin-top:0.25rem;">Simulaciones ilimitadas / multi-empresa</div>
            </div>

            <ul style="list-style:none; padding:0; margin:0 0 1.75rem 0; font-size:0.85rem; color:#cbd5e1; display:flex; flex-direction:column; gap:0.65rem;">
              <li style="display:flex; align-items:flex-start; gap:0.5rem;"><span style="color:#10b981;">✓</span> <span><strong>Todo lo del Plan Corporate Defense</strong></span></li>
              <li style="display:flex; align-items:flex-start; gap:0.5rem;"><span style="color:#10b981;">✓</span> <span><strong>Colaboradores Ilimitados</strong> en múltiples razones sociales</span></li>
              <li style="display:flex; align-items:flex-start; gap:0.5rem;"><span style="color:#10b981;">✓</span> <span>Monitoreo 24/7 de <strong>Credenciales Filtradas en Dark Web</strong></span></li>
              <li style="display:flex; align-items:flex-start; gap:0.5rem;"><span style="color:#10b981;">✓</span> <span>Simulaciones de <strong>Deepfake de Audio y Video en tiempo real</strong></span></li>
              <li style="display:flex; align-items:flex-start; gap:0.5rem;"><span style="color:#10b981;">✓</span> <span>Integración API SIEM / SOC y canal prioritario Slack / Teams</span></li>
              <li style="display:flex; align-items:flex-start; gap:0.5rem;"><span style="color:#10b981;">✓</span> <span>Oficial de Seguridad Asignado y soporte forense en vivo</span></li>
            </ul>

            <button class="btn btn-secondary btn-buy-product" 
                    data-product-id="PROD-REDTEAM-ENTERPRISE" 
                    data-product-name="Red Teaming Enterprise Threat Intel" 
                    data-product-price="Desde USD $1.450 / Mes"
                    data-price-usd-label="Desde USD $1.450 / Mes"
                    data-price-uf-label="Desde UF 35 / Mes"
                    style="margin-top:auto; width:100%; justify-content:center; background:#1e293b; color:#ffffff; border-color:#a855f7;">
              Cotizar Plan Enterprise
            </button>
          </div>

        </div>
      </div>

      <!-- Protocol Box Red Teaming -->
      <div style="background:linear-gradient(135deg, #1c1917 0%, #0c0a09 100%); border:1px solid #78350f; border-radius:12px; padding:1.75rem 2rem; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1.5rem; margin-top:3.5rem;">
        <div>
          <h4 style="color:#ffffff; font-size:1.15rem; margin-bottom:0.35rem;">¿Desea medir la vulnerabilidad de su organización ante ataques con IA?</h4>
          <p style="color:#cbd5e1; font-size:0.88rem;">Planificación en 24 horas bajo estricto acuerdo de confidencialidad y autorización previa.</p>
        </div>
        <button class="btn btn-primary btn-buy-product" 
                data-product-id="PROD-REDTEAM-ANNUAL" 
                data-product-name="Red Teaming Corporate Defense Anual" 
                data-product-price="Desde USD $650 / Mes"
                style="background:linear-gradient(135deg, #d97706 0%, #b45309 100%); padding:0.8rem 1.6rem; font-weight:700;">
          Agendar Ejercicio de Red Teaming ➜
        </button>
      </div>

    </div>
  </section>

  <!-- Partnerships & Alliances -->
  <section class="section" id="alianzas" style="background:#ffffff;">
    <div class="section-container" style="text-align:center;">
      <span class="badge-dev">Ecosistema Global & Alianzas</span>
      <h2 class="section-title">Alianzas Estratégicas y Certificaciones Oficiales</h2>
      <p class="section-desc" style="margin-bottom:2.5rem;">Colaboramos estrechamente con líderes de la industria y socios estratégicos en América y el Caribe para maximizar el valor entregado a nuestros clientes.</p>
      
      <!-- Grid de Alianzas y Partners -->
      <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:2rem; align-items:center; justify-items:center; max-width:1100px; margin:0 auto 3rem;">
        
        <!-- Salesforce Partner -->
        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:1.5rem; width:100%; display:flex; flex-direction:column; align-items:center; justify-content:center; min-height:120px; transition:var(--transition); box-shadow:var(--shadow-sm);">
          <img src="assets/images/salesforce-partner.png" alt="Salesforce Partner" style="height:55px; max-width:180px; object-fit:contain;">
          <span style="font-size:0.78rem; font-weight:700; color:#006699; margin-top:0.75rem; text-transform:uppercase; letter-spacing:0.5px;">Socio Certificado Oficial</span>
        </div>

        <!-- Inmotion República Dominicana -->
        <a href="https://inmotion.com.do/" target="_blank" rel="noopener noreferrer" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:1.5rem; width:100%; display:flex; flex-direction:column; align-items:center; justify-content:center; min-height:120px; transition:var(--transition); box-shadow:var(--shadow-sm);" class="partner-card-link">
          <img src="assets/images/logo-inmotion.png" alt="Inmotion República Dominicana" style="height:48px; max-width:180px; object-fit:contain;">
          <span style="font-size:0.78rem; font-weight:700; color:#003366; margin-top:0.75rem; text-transform:uppercase; letter-spacing:0.5px;">Alianza Caribe & Rep. Dominicana ↗</span>
        </a>

        <!-- Surges Capacitaciones Chile -->
        <a href="https://surgescapacitaciones.cl/" target="_blank" rel="noopener noreferrer" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:1.5rem; width:100%; display:flex; flex-direction:column; align-items:center; justify-content:center; min-height:120px; transition:var(--transition); box-shadow:var(--shadow-sm);" class="partner-card-link">
          <img src="assets/images/logo-surges.png" alt="Surges Capacitaciones" style="height:48px; max-width:190px; object-fit:contain;">
          <span style="font-size:0.78rem; font-weight:700; color:#003366; margin-top:0.75rem; text-transform:uppercase; letter-spacing:0.5px;">Alianza Formación & SENCE ↗</span>
        </a>

        <!-- SAP & Cloud Ecosystem -->
        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:1.5rem; width:100%; display:flex; flex-direction:column; align-items:center; justify-content:center; min-height:120px; transition:var(--transition); box-shadow:var(--shadow-sm);">
          <div style="font-size:1.25rem; font-weight:800; color:#003366; letter-spacing:-0.5px;">SAP & MultiCloud</div>
          <span style="font-size:0.78rem; font-weight:700; color:#64748b; margin-top:0.75rem; text-transform:uppercase; letter-spacing:0.5px;">AWS • Azure • Google Cloud</span>
        </div>

      </div>
    </div>
  </section>

  <!-- Contact & Global Offices -->
  <section class="section" id="contacto" style="background:#f8fafc;">
    <div class="section-container">
      <div class="section-header">
        <span class="badge-dev">Presencia Regional</span>
        <h2 class="section-title">Hablemos sobre su Próximo Desafío</h2>
        <p class="section-desc">Atendemos clientes en toda América y el Caribe con soporte en sitio y remoto.</p>
      </div>
      <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:1.5rem; text-align:center;">
        <div style="background:#fff; padding:1.75rem; border-radius:12px; border:1px solid #e2e8f0;">
          <h4 style="color:#003366; margin-bottom:0.5rem;">🇨🇱 Chile</h4>
          <p style="font-size:0.88rem; color:#64748b;">Av. Apoquindo 6410, Of. 605, Las Condes, Santiago</p>
          <p style="font-weight:700; color:#006699; margin-top:0.5rem;">+56 9 7971 9857</p>
        </div>
        <div style="background:#fff; padding:1.75rem; border-radius:12px; border:1px solid #e2e8f0;">
          <h4 style="color:#003366; margin-bottom:0.5rem;">🇵🇪 Perú</h4>
          <p style="font-size:0.88rem; color:#64748b;">Av. Principal, Surquillo, Lima</p>
          <p style="font-weight:700; color:#006699; margin-top:0.5rem;">+51 9 5012 3182</p>
        </div>
        <div style="background:#fff; padding:1.75rem; border-radius:12px; border:1px solid #e2e8f0;">
          <h4 style="color:#003366; margin-bottom:0.5rem;">🇵🇦 Panamá</h4>
          <p style="font-size:0.88rem; color:#64748b;">Marbella, Panamá City</p>
          <p style="font-weight:700; color:#006699; margin-top:0.5rem;">+507 6378 6682</p>
        </div>
        <div style="background:#fff; padding:1.75rem; border-radius:12px; border:1px solid #e2e8f0;">
          <h4 style="color:#003366; margin-bottom:0.5rem;">🇨🇦 Canadá</h4>
          <p style="font-size:0.88rem; color:#64748b;">1150 Windermere Way, Edmonton, AB</p>
          <p style="font-weight:700; color:#006699; margin-top:0.5rem;">+56 9 7971 9857</p>
        </div>
      </div>
    </div>
  </section>

  <!-- Footer -->
  <footer class="footer">
    <div class="footer-container">
      <div class="footer-brand">
        <img src="assets/images/logo-keystone-white.png?v=20261107_01" alt="Keystone Advisors" style="height:48px; max-width:200px; object-fit:contain; margin-bottom:1rem;">
        <p>Firma líder de consultoría estratégica y desarrollo tecnológico especializada en Salesforce CRM, Salesforce Agentforce, ERPs y soluciones Cloud avanzadas.</p>
      </div>
      <div class="footer-col">
        <h4>Soluciones</h4>
        <ul class="footer-links">
          <li><a href="#keyhub">KeyHub Operations Suite</a></li>
          <li><a href="#productos">Salesforce Quick Starter</a></li>
          <li><a href="#servicios">Agentforce & IA</a></li>
          <li><a href="#servicios">Arquitectura Cloud</a></li>
          <li><a href="#servicios">Servicios TI Offshore</a></li>
        </ul>
      </div>
      <div class="footer-col">
        <h4>Plataformas & Seguridad</h4>
        <ul class="footer-links">
          <li><a href="/shield/portal.php">Portal DeepShield Clientes</a></li>
          <li><a href="/shield/portal.php?tab=dmarc">DMARC Watchdog & Anti-Spoofing</a></li>
          <li><a href="/trust/">Trust Center (TrustZero)</a></li>
          <li><a href="https://cursos.surgescapacitaciones.cl" target="_blank">Surges Academy</a></li>
          <li><a href="#contacto">Canal de Denuncias</a></li>
          <li><a href="#contacto">Términos de Servicio</a></li>
        </ul>
      </div>
      <div class="footer-col">
        <h4>Contacto Directo</h4>
        <ul class="footer-links">
          <li><a href="https://wa.me/56979719857" target="_blank">WhatsApp Chile</a></li>
          <li><a href="https://wa.me/51950123182" target="_blank">WhatsApp Perú</a></li>
          <li><a href="mailto:contacto@keystoneadvisors.cl">contacto@keystoneadvisors.cl</a></li>
        </ul>
      </div>
    </div>
    <div class="footer-bottom">
      <div>&copy; <?php echo date('Y'); ?> Keystone Advisors SpA. Todos los derechos reservados.</div>
      <div style="display:flex; align-items:center; gap:0.75rem;">
        <a href="/trust/" title="Ver Telemetría en Tiempo Real de TrustZero">
          <img src="/api/trust/badge.php?domain=keystoneadvisors.cl" alt="TrustZero Verified" style="height:26px; border-radius:4px; vertical-align:middle;">
        </a>
        <span>Plataforma Global Corporativa & Ciberdefensa</span>
      </div>
    </div>
  </footer>

  <!-- Modal Interactivo: Contratación & Formulario de Diagnóstico -->
  <div class="modal-overlay" id="checkoutModal">
    <div class="modal-content">
      <button class="modal-close" id="modalClose">&times;</button>
      <div class="modal-header" style="margin-bottom:1.5rem;">
        <h3 id="modalTitle" style="color:var(--primary); font-size:1.4rem; margin-bottom:0.35rem;">Solicitud de Solución</h3>
        <p id="modalSubtitle" style="color:var(--text-muted); font-size:0.9rem;">Complete los datos para generar la orden y asignarle un consultor.</p>
      </div>

      <div class="alert-box" id="formAlert"></div>

      <form id="requestForm">
        <input type="hidden" id="formType" value="lead">
        <input type="hidden" id="productId" value="">
        <input type="hidden" id="productName" value="">

        <div class="form-group">
          <label class="form-label" for="clientName">Nombre Completo *</label>
          <input type="text" id="clientName" class="form-input" placeholder="Ej: Roberto González" required>
        </div>

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1rem;">
          <div class="form-group">
            <label class="form-label" for="clientEmail">Correo Corporativo *</label>
            <input type="email" id="clientEmail" class="form-input" placeholder="rgonzalez@empresa.com" required>
          </div>
          <div class="form-group">
            <label class="form-label" for="clientPhone">Teléfono / WhatsApp</label>
            <input type="tel" id="clientPhone" class="form-input" placeholder="+56 9 1234 5678">
          </div>
        </div>

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1rem;">
          <div class="form-group">
            <label class="form-label" for="clientCompany">Empresa u Organización</label>
            <input type="text" id="clientCompany" class="form-input" placeholder="Nombre de su Empresa">
          </div>
          <div class="form-group">
            <label class="form-label" for="clientCountry">País</label>
            <select id="clientCountry" class="form-select">
              <option value="Chile">Chile</option>
              <option value="Perú">Perú</option>
              <option value="Panamá">Panamá</option>
              <option value="República Dominicana">República Dominicana</option>
              <option value="Canadá">Canadá</option>
              <option value="Estados Unidos">Estados Unidos</option>
              <option value="Otro">Otro País</option>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="clientService">Área de Interés Principal</label>
          <select id="clientService" class="form-select">
            <option value="Executive Cyber Risk Assessment">Executive Cyber Risk Assessment (Diagnóstico 72 hrs)</option>
            <option value="Blindaje de Tesorería DeepShield">Blindaje Operativo de Tesorería (Anti-Fraude IA)</option>
            <option value="KeyHub Professional SaaS">KeyHub Professional (SaaS)</option>
            <option value="KeyHub Enterprise SaaS">KeyHub Enterprise (SaaS)</option>
            <option value="KeyHub Add-on Motor Forense">KeyHub Add-on: Motor Pericial & Auditoría Forense de Horas</option>
            <option value="Salesforce Starter Suite">Salesforce Starter Suite (2 semanas)</option>
            <option value="Salesforce Quick Starter">Salesforce Quick Starter (4 semanas)</option>
            <option value="Salesforce Agentforce & IA">Salesforce Agentforce & Inteligencia Artificial</option>
            <option value="Bolsa Soporte IT Flex">Bolsa de Horas Soporte IT Flex</option>
            <option value="Arquitectura Cloud & FinOps">Arquitectura Cloud & Reducción de Factura</option>
            <option value="Servicios TI Offshore">Células y Squads TI Offshore</option>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label" for="clientMessage">Detalles Adicionales o Requerimiento</label>
          <textarea id="clientMessage" class="form-textarea" rows="3" placeholder="Indique brevemente el objetivo de su empresa o requerimiento específico..."></textarea>
        </div>

        <button type="submit" id="btnSubmit" class="btn btn-primary" style="width:100%; padding:0.85rem; font-size:1rem;">
          <span>Continuar</span> ➜
        </button>
      </form>
    </div>
  </div>

  <script src="assets/js/main.js?v=20261110_01"></script>

  <!-- Script Menú Móvil Hamburguesa -->
  <script>
    (function() {
      const toggleBtn = document.getElementById('navToggleBtn');
      const menuWrapper = document.getElementById('navMenuWrapper');
      if (toggleBtn && menuWrapper) {
        toggleBtn.addEventListener('click', function(e) {
          e.stopPropagation();
          const isOpen = menuWrapper.classList.toggle('is-open');
          toggleBtn.innerHTML = isOpen ? '✕' : '☰';
        });

        // Cerrar menú al hacer clic en un enlace de navegación
        menuWrapper.querySelectorAll('a').forEach(function(link) {
          link.addEventListener('click', function() {
            menuWrapper.classList.remove('is-open');
            toggleBtn.innerHTML = '☰';
          });
        });

        // Cerrar al hacer clic fuera del menú
        document.addEventListener('click', function(e) {
          if (!menuWrapper.contains(e.target) && !toggleBtn.contains(e.target)) {
            menuWrapper.classList.remove('is-open');
            toggleBtn.innerHTML = '☰';
          }
        });
      }
    })();
  </script>

  <!-- ======================================================== -->
  <!-- MODAL INTERACTIVO: DEMOSTRADOR RED TEAMING ASISTIDO POR IA -->
  <!-- ======================================================== -->
  <div id="modalRedTeamSimulator" style="display:none; position:fixed; inset:0; background:rgba(11,19,41,0.85); backdrop-filter:blur(6px); z-index:99999; align-items:center; justify-content:center; padding:1rem; overflow-y:auto;">
    <div style="background:#131f37; border:1px solid #1e3a5f; border-radius:16px; max-width:680px; width:100%; box-shadow:0 25px 50px -12px rgba(0,0,0,0.7); overflow:hidden; margin:auto;">
      
      <!-- Cabecera del Modal -->
      <div style="background:linear-gradient(135deg, #0b1329 0%, #1e293b 100%); padding:1.25rem 1.75rem; border-bottom:1px solid #1e3a5f; display:flex; justify-content:space-between; align-items:center;">
        <div style="display:flex; align-items:center; gap:0.6rem;">
          <span style="font-size:1.4rem;">🎭</span>
          <div>
            <h3 style="color:#ffffff; font-size:1.15rem; font-weight:800; margin:0;">Simulador Red Teaming Asistido por IA</h3>
            <span style="color:#38bdf8; font-size:0.75rem; text-transform:uppercase; font-weight:700; letter-spacing:0.5px;">Demostración Forense de Ingeniería Social Contextual</span>
          </div>
        </div>
        <button type="button" onclick="closeRedTeamSimulatorModal()" style="background:none; border:none; color:#94a3b8; font-size:1.6rem; cursor:pointer; line-height:1;">&times;</button>
      </div>

      <!-- Cuerpo del Modal: Paso 1 Formulario -->
      <div id="rtStepForm" style="padding:1.75rem;">
        <p style="color:#cbd5e1; font-size:0.88rem; line-height:1.5; margin-bottom:1.25rem;">
          Vea en tiempo real cómo un cibercriminal utiliza modelos de Inteligencia Artificial para diseñar un ataque <strong>BEC / Fraude del CEO</strong> hiperpersonalizado contra su compañía.
        </p>

        <form id="formRedTeamSim" onsubmit="runRedTeamSimulation(event)">
          <div style="display:grid; grid-template-columns:1.2fr 1fr; gap:1rem; margin-bottom:1rem;">
            <div>
              <label style="display:block; font-size:0.78rem; font-weight:700; color:#94a3b8; margin-bottom:0.35rem;">Nombre de su Empresa / Organización *</label>
              <input type="text" id="rtCompany" required placeholder="Ej: Inversiones del Sur SpA" style="width:100%; padding:0.65rem 0.85rem; background:#0b1329; border:1px solid #334e68; border-radius:8px; color:#fff; font-size:0.9rem;">
            </div>
            <div>
              <label style="display:block; font-size:0.78rem; font-weight:700; color:#94a3b8; margin-bottom:0.35rem;">Industria / Rubro *</label>
              <select id="rtIndustry" style="width:100%; padding:0.65rem 0.85rem; background:#0b1329; border:1px solid #334e68; border-radius:8px; color:#fff; font-size:0.9rem;">
                <option value="Construcción e Inmobiliaria">Construcción e Inmobiliaria</option>
                <option value="Servicios Financieros & Inversiones">Servicios Financieros & Inversiones</option>
                <option value="Retail & Comercio Mayorista">Retail & Comercio Mayorista</option>
                <option value="Minería & Energía">Minería & Energía</option>
                <option value="Agroindustria & Exportación">Agroindustria & Exportación</option>
                <option value="Tecnología & Telecomunicaciones">Tecnología & Telecomunicaciones</option>
                <option value="Salud & Clínicas">Salud & Clínicas</option>
                <option value="Transporte & Logística">Transporte & Logística</option>
              </select>
            </div>
          </div>

          <!-- Campos de Contactabilidad Directa (Estilo DeepShield) -->
          <div style="background:rgba(2,132,199,0.08); border:1px solid #1e3a5f; border-radius:10px; padding:1rem; margin-bottom:1rem;">
            <div style="display:flex; align-items:center; gap:0.4rem; font-size:0.78rem; font-weight:800; color:#38bdf8; text-transform:uppercase; margin-bottom:0.6rem;">
              <span>👤 Datos de Contacto del Directivo / Solicitante</span>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem; margin-bottom:0.6rem;">
              <div>
                <label style="display:block; font-size:0.75rem; color:#cbd5e1; margin-bottom:0.25rem;">Nombre y Cargo *</label>
                <input type="text" id="rtContactName" required placeholder="Ej: Patricia Morales (CFO)" style="width:100%; padding:0.55rem 0.75rem; background:#0b1329; border:1px solid #334e68; border-radius:6px; color:#fff; font-size:0.85rem;">
              </div>
              <div>
                <label style="display:block; font-size:0.75rem; color:#cbd5e1; margin-bottom:0.25rem;">Teléfono / WhatsApp *</label>
                <input type="tel" id="rtPhone" required placeholder="+56 9 1234 5678" style="width:100%; padding:0.55rem 0.75rem; background:#0b1329; border:1px solid #334e68; border-radius:6px; color:#fff; font-size:0.85rem;">
              </div>
            </div>
            <div>
              <label style="display:block; font-size:0.75rem; color:#cbd5e1; margin-bottom:0.25rem;">Correo Corporativo Oficial *</label>
              <input type="email" id="rtEmail" required placeholder="directorio@suempresa.cl" style="width:100%; padding:0.55rem 0.75rem; background:#0b1329; border:1px solid #334e68; border-radius:6px; color:#fff; font-size:0.85rem;">
              <small style="color:#64748b; font-size:0.72rem; margin-top:0.25rem; display:block;">Le enviaremos el reporte pericial del ejercicio a esta casilla.</small>
            </div>
          </div>

          <div style="margin-bottom:1.5rem;">
            <label style="display:block; font-size:0.78rem; font-weight:700; color:#94a3b8; margin-bottom:0.35rem;">Vector de Ataque a Simular *</label>
            <select id="rtVector" style="width:100%; padding:0.65rem 0.85rem; background:#0b1329; border:1px solid #334e68; border-radius:8px; color:#fff; font-size:0.9rem;">
              <option value="BEC_CEO_FRAUD">💼 Fraude del CEO (Transferencia urgente fuera de nómina)</option>
              <option value="SUPPLIER_ACCOUNT_CHANGE">🏦 Cambio de Cuenta Bancaria de Proveedor Crítico</option>
              <option value="TAX_INSPECTION_FRAUD">⚖️ Spear-Phishing Tributario / Notificación Falsa SII</option>
            </select>
          </div>

          <button type="submit" id="btnRunRtSim" class="btn btn-primary" style="width:100%; padding:0.85rem; font-size:0.95rem; font-weight:800; background:linear-gradient(135deg, #0284c7 0%, #0369a1 100%);">
            ⚡ Iniciar Simulación Forense con IA ➜
          </button>
        </form>
      </div>

      <!-- Estado Cargando -->
      <div id="rtStepLoading" style="display:none; padding:3rem 1.75rem; text-align:center;">
        <div style="font-size:2.5rem; margin-bottom:1rem; animation:spin 1s linear infinite;">⚙️</div>
        <h4 style="color:#ffffff; font-size:1.1rem; margin-bottom:0.5rem;">Analizando Contexto Corporativo & Generando Ataque Sintético...</h4>
        <p style="color:#94a3b8; font-size:0.85rem;">Conectando con motor de Red Teaming Cloudflare Workers AI...</p>
      </div>

      <!-- Paso 2: Resultado Forense de la Simulación -->
      <div id="rtStepResult" style="display:none; padding:1.75rem; max-height:75vh; overflow-y:auto;">
        
        <!-- Ficha del Correo de Phishing Generado -->
        <div style="background:#0b1329; border:1px solid #7f1d1d; border-radius:10px; padding:1.25rem; margin-bottom:1.25rem; box-shadow:0 4px 15px rgba(220,38,38,0.15);">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.75rem; border-bottom:1px solid #1e293b; padding-bottom:0.5rem;">
            <div style="font-size:0.75rem; font-weight:800; color:#ef4444; text-transform:uppercase;">🚨 Correo Generado por el Atacante (Simulación)</div>
            <span style="font-size:0.7rem; background:#2a1215; color:#f87171; border:1px solid #7f1d1d; padding:0.15rem 0.4rem; border-radius:4px;">Vector: Simulado</span>
          </div>

          <div style="font-size:0.82rem; color:#cbd5e1; margin-bottom:0.5rem;">
            <strong>Suplantando a:</strong> <span id="rtResImpersonated" style="color:#f87171; font-weight:bold;"></span><br>
            <strong>Dirigido a:</strong> <span id="rtResTarget" style="color:#fff;"></span><br>
            <strong>Asunto:</strong> <span id="rtResSubject" style="color:#38bdf8; font-weight:bold;"></span>
          </div>

          <div id="rtResBody" style="background:#131f37; border-left:3px solid #ef4444; padding:0.85rem; border-radius:6px; color:#f8fafc; font-size:0.85rem; line-height:1.5; white-space:pre-wrap; font-family:sans-serif;"></div>
        </div>

        <!-- Análisis Forense de Gatillos Psicológicos -->
        <div style="background:#0b1329; border:1px solid #1e3a5f; border-radius:10px; padding:1rem; margin-bottom:1.25rem;">
          <h4 style="font-size:0.82rem; font-weight:800; color:#38bdf8; text-transform:uppercase; margin-bottom:0.5rem;">🧠 Gatillos Psicológicos de Explotación</h4>
          <div id="rtResTriggers" style="display:flex; gap:0.4rem; flex-wrap:wrap; margin-bottom:0.6rem;"></div>
          <p style="font-size:0.8rem; color:#94a3b8; margin:0;">
            <strong style="color:#cbd5e1;">Consejo Forense de Detección:</strong> <span id="rtResTip"></span>
          </p>
        </div>

        <!-- Blindaje DeepShield -->
        <div style="background:rgba(5,150,105,0.1); border:1px solid #059669; border-radius:10px; padding:1rem; margin-bottom:1.5rem;">
          <div style="font-size:0.82rem; font-weight:800; color:#34d399; text-transform:uppercase; margin-bottom:0.25rem;">🛡️ Cómo Neutraliza Keystone DeepShield este Ataque:</div>
          <p id="rtResDefense" style="font-size:0.83rem; color:#a7f3d0; line-height:1.4; margin:0;"></p>
        </div>

        <!-- Call to Action B2B: ¿Quiere entrenar a su equipo con un plan completo? -->
        <div style="background:linear-gradient(135deg, #1e293b 0%, #0f172a 100%); border:1px solid #0284c7; border-radius:10px; padding:1.25rem; text-align:center;">
          <h4 style="color:#ffffff; font-size:1.05rem; font-weight:800; margin-bottom:0.35rem;">¿Desea medir la vulnerabilidad real de su equipo?</h4>
          <p style="color:#94a3b8; font-size:0.82rem; margin-bottom:1rem;">
            Ejecutamos campañas controladas de Red Teaming, Vishing con voz sintética y entrenamiento continuo para directivos y tesorería con informe pericial bajo la Ley N° 21.459.
          </p>

          <form id="formRtLead" onsubmit="submitRtLead(event)" style="display:flex; gap:0.5rem; justify-content:center; flex-wrap:wrap;">
            <input type="hidden" id="rtSimId" value="0">
            <input type="email" id="rtLeadEmail" required placeholder="correo.corporativo@empresa.cl" style="padding:0.6rem 0.85rem; background:#0b1329; border:1px solid #334e68; border-radius:6px; color:#fff; font-size:0.85rem; width:260px;">
            <button type="submit" id="btnSubmitRtLead" class="btn btn-primary" style="width:auto; padding:0.6rem 1.25rem; font-size:0.85rem; font-weight:800;">
              Solicitar Plan de Red Teaming ➜
            </button>
          </form>
          <div id="rtLeadFeedback" style="display:none; color:#34d399; font-size:0.85rem; margin-top:0.75rem; font-weight:bold;">
            ✓ Solicitud recibida. Un asesor de ciberseguridad se pondrá en contacto a la brevedad.
          </div>
        </div>

      </div>

    </div>
  </div>

  <script>
    let currentRtSimId = 0;

    function openRedTeamSimulatorModal() {
      document.getElementById('modalRedTeamSimulator').style.display = 'flex';
      document.getElementById('rtStepForm').style.display = 'block';
      document.getElementById('rtStepLoading').style.display = 'none';
      document.getElementById('rtStepResult').style.display = 'none';
    }

    function closeRedTeamSimulatorModal() {
      document.getElementById('modalRedTeamSimulator').style.display = 'none';
    }

    async function runRedTeamSimulation(e) {
      e.preventDefault();
      const company = document.getElementById('rtCompany').value.trim();
      const industry = document.getElementById('rtIndustry').value;
      const vector = document.getElementById('rtVector').value;
      const contactName = document.getElementById('rtContactName').value.trim();
      const phone = document.getElementById('rtPhone').value.trim();
      const email = document.getElementById('rtEmail').value.trim();

      document.getElementById('rtStepForm').style.display = 'none';
      document.getElementById('rtStepLoading').style.display = 'block';

      try {
        const formData = new FormData();
        formData.append('action', 'simulate');
        formData.append('company_name', company);
        formData.append('industry', industry);
        formData.append('attack_vector', vector);
        formData.append('contact_name', contactName);
        formData.append('phone', phone);
        formData.append('email', email);

        const res = await fetch('api/simulate_attack.php', {
          method: 'POST',
          body: formData
        });

        const data = await res.json();
        if (data.success && data.data) {
          const sim = data.data;
          currentRtSimId = sim.simulation_id || 0;
          document.getElementById('rtSimId').value = currentRtSimId;
          const leadEmailField = document.getElementById('rtLeadEmail');
          if (leadEmailField && email) {
            leadEmailField.value = email;
          }

          document.getElementById('rtResImpersonated').innerText = sim.attacker_impersonated || 'Atacante Avanzado';
          document.getElementById('rtResTarget').innerText = sim.target_profile || 'Personal de Finanzas';
          document.getElementById('rtResSubject').innerText = sim.subject || 'Notificación Urgente';
          document.getElementById('rtResBody').innerText = sim.email_body || '';

          // Triggers
          const triggersContainer = document.getElementById('rtResTriggers');
          triggersContainer.innerHTML = '';
          const triggers = sim.psychological_triggers || ['Urgencia', 'Autoridad', 'Confidencialidad'];
          triggers.forEach(tr => {
            const badge = document.createElement('span');
            badge.style.cssText = 'background:#1e293b; color:#38bdf8; border:1px solid #0284c7; padding:0.15rem 0.5rem; border-radius:4px; font-size:0.75rem; font-weight:700;';
            badge.innerText = '⚡ ' + tr;
            triggersContainer.appendChild(badge);
          });

          document.getElementById('rtResTip').innerText = sim.detection_tip || '';
          document.getElementById('rtResDefense').innerText = sim.deepshield_defense || '';

          document.getElementById('rtStepLoading').style.display = 'none';
          document.getElementById('rtStepResult').style.display = 'block';
        } else {
          alert('Hubo un inconveniente al generar la simulación. Por favor intente nuevamente.');
          document.getElementById('rtStepLoading').style.display = 'none';
          document.getElementById('rtStepForm').style.display = 'block';
        }
      } catch (err) {
        console.error(err);
        alert('Error de conexión con el simulador.');
        document.getElementById('rtStepLoading').style.display = 'none';
        document.getElementById('rtStepForm').style.display = 'block';
      }
    }

    async function submitRtLead(e) {
      e.preventDefault();
      const email = document.getElementById('rtLeadEmail').value.trim();
      const btn = document.getElementById('btnSubmitRtLead');
      btn.disabled = true;
      btn.innerText = 'Enviando...';

      try {
        const formData = new FormData();
        formData.append('action', 'capture_lead');
        formData.append('simulation_id', currentRtSimId);
        formData.append('email', email);

        const res = await fetch('api/simulate_attack.php', {
          method: 'POST',
          body: formData
        });

        const data = await res.json();
        if (data.success) {
          document.getElementById('formRtLead').style.display = 'none';
          document.getElementById('rtLeadFeedback').style.display = 'block';
        } else {
          alert(data.error || 'No se pudo registrar la solicitud.');
          btn.disabled = false;
          btn.innerText = 'Solicitar Plan de Red Teaming ➜';
        }
      } catch (err) {
        alert('Error al enviar la solicitud.');
        btn.disabled = false;
        btn.innerText = 'Solicitar Plan de Red Teaming ➜';
      }
    }
    // Control Dropdown de Portales de Clientes (SOL-0200)
    function togglePortalDropdown(e) {
      if (e) e.stopPropagation();
      const menu = document.getElementById('portalDropdownMenu');
      if (menu) {
        menu.style.display = (menu.style.display === 'block') ? 'none' : 'block';
      }
    }

    document.addEventListener('click', function(e) {
      const dropdown = document.querySelector('.nav-portal-dropdown');
      const menu = document.getElementById('portalDropdownMenu');
      if (dropdown && menu && !dropdown.contains(e.target)) {
        menu.style.display = 'none';
      }
    });
  </script>

</body>
</html>
