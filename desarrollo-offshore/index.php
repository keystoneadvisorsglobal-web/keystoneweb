<?php
/**
 * Keystone Advisors - Landing Especializada: Servicios de Desarrollo de Software Offshore & Staff Augmentation
 * Ruta Oficial: /desarrollo-offshore/ (https://keystoneadvisors.cl/desarrollo-offshore/)
 * Solución SOL-0266 | Especialista SEO & Staff Augmentation
 */
$page_title = "Desarrollo de Software Offshore & Nearshore Chile | Apex, Cloud & TI | Keystone Advisors";
$page_desc = "Fábrica de software y células ágiles de desarrollo offshore / nearshore desde Chile. Especialistas sénior en Salesforce Apex, LWC, PHP, Python, DevOps y arquitecturas Cloud.";
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($page_title); ?></title>
  <meta name="description" content="<?php echo htmlspecialchars($page_desc); ?>">
  <meta name="keywords" content="desarrolladores apex salesforce offshore, servicios software nearshore chile, outsourcing ti santiago, celulas agiles desarrollo chile, staff augmentation salesforce fsc">
  <meta name="author" content="Keystone Advisors SpA">
  <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
  <link rel="canonical" href="https://www.keystoneadvisors.cl/desarrollo-offshore/">

  <!-- Open Graph -->
  <meta property="og:locale" content="es_CL">
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="Keystone Advisors">
  <meta property="og:title" content="<?php echo htmlspecialchars($page_title); ?>">
  <meta property="og:description" content="<?php echo htmlspecialchars($page_desc); ?>">
  <meta property="og:url" content="https://www.keystoneadvisors.cl/desarrollo-offshore/">
  <meta property="og:image" content="https://www.keystoneadvisors.cl/assets/images/logo.png?v=20261107_01">

  <!-- Schema.org JSON-LD -->
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "Service",
    "name": "Servicios de Desarrollo de Software Offshore y Nearshore",
    "serviceType": "Fábrica de Software y Células Ágiles de TI",
    "provider": {
      "@type": "Organization",
      "name": "Keystone Advisors SpA",
      "url": "https://www.keystoneadvisors.cl/",
      "telephone": "+56 9 3064 0431"
    },
    "description": "Células técnicas dedicadas con ingenieros sénior en Salesforce FSC, Apex, arquitectura Cloud y APIs de misión crítica para empresas en América y el Caribe."
  }
  </script>

  <link rel="icon" type="image/png" href="../assets/images/favicon.png">
  <link rel="stylesheet" href="../assets/css/style.css?v=20261107_10">
  <style>
    .offshore-hero {
      background: radial-gradient(circle at 50% 20%, #1e293b 0%, #0a0f1d 90%);
      color: #ffffff;
      padding: 5rem 1.5rem 4rem;
      text-align: center;
      border-bottom: 1px solid rgba(255,255,255,0.1);
    }
    .badge-off {
      background: rgba(56, 189, 248, 0.15);
      border: 1px solid #38bdf8;
      color: #38bdf8;
      padding: 0.35rem 0.9rem;
      border-radius: 9999px;
      font-size: 0.82rem;
      font-weight: 700;
      letter-spacing: 0.5px;
      text-transform: uppercase;
      display: inline-block;
      margin-bottom: 1.25rem;
    }
    .offshore-hero h1 {
      font-size: 2.6rem;
      font-weight: 800;
      line-height: 1.2;
      max-width: 900px;
      margin: 0 auto 1.25rem;
      letter-spacing: -0.5px;
    }
    .offshore-hero p {
      font-size: 1.15rem;
      color: #94a3b8;
      max-width: 780px;
      margin: 0 auto 2rem;
      line-height: 1.6;
    }
    .btn-off-primary {
      background: #38bdf8;
      color: #0f172a;
      padding: 0.85rem 1.75rem;
      border-radius: 8px;
      font-weight: 800;
      text-decoration: none;
      transition: all 0.2s;
    }
    .btn-off-primary:hover {
      background: #7dd3fc;
      transform: translateY(-2px);
    }
  </style>
</head>
<body>

    <!-- Navigation Header -->
  <header class="header">
    <div class="header-container">
      <a href="../" class="brand">
        <img src="../assets/images/logo.png?v=20261107_01" alt="Keystone Advisors Logo" width="1024" height="288" fetchpriority="high">
      </a>
      <!-- Boton hamburguesa para móviles -->
      <button class="nav-toggle-btn" id="navToggleBtn" aria-label="Abrir Menú de Navegación">
        ☰
      </button>

      <div class="nav-menu-wrapper" id="navMenuWrapper">
        <nav>
          <ul class="nav-links">
            <li><a href="../#inicio" class="nav-link">Inicio</a></li>
            <li><a href="../#servicios" class="nav-link">Servicios & IA</a></li>
            <li><a href="../#productos" class="nav-link">Planes B2B</a></li>
            <li><a href="../trust/" class="nav-link" style="color:#10b981; font-weight:700;">Trust Center 🛡️</a></li>
            <li><a href="../#contacto" class="nav-link">Contacto</a></li>
          </ul>
        </nav>
        <div class="nav-actions">
          <!-- Switcher Moneda USD / Chile UF -->
          <div class="currency-toggle" style="display:inline-flex; align-items:center; background:#f1f5f9; border:1px solid #cbd5e1; border-radius:30px; padding:2px; font-size:0.78rem; font-weight:700;">
            <button type="button" class="btn-cur-switch" data-cur="USD" onclick="setSiteCurrency('USD')" style="border:none; background:transparent; padding:0.35rem 0.65rem; border-radius:20px; cursor:pointer; color:#475569; transition:all 0.2s; font-weight:700;">🇺🇸 USD</button>
            <button type="button" class="btn-cur-switch active" data-cur="UF" onclick="setSiteCurrency('UF')" style="border:none; background:#003366; color:#ffffff; padding:0.35rem 0.65rem; border-radius:20px; cursor:pointer; transition:all 0.2s; font-weight:700; box-shadow:0 2px 4px rgba(0,0,0,0.15);">🇨🇱 UF</button>
          </div>

          <!-- Dropdown Acceso Clientes -->
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

          <a href="../#escaner" class="btn btn-primary" style="background: linear-gradient(135deg, #059669 0%, #10b981 100%); font-size:0.88rem;">🛡️ Escáner Gratuito</a>
        </div>
      </div>
    </div>
  </header>

  <!-- Hero Section -->
  <section class="offshore-hero">
    <div style="max-width:1100px; margin:0 auto;">
      <span class="badge-off">Nearshore & Staff Augmentation TI</span>
      <h1>Células de Desarrollo de Software Offshore de Alto Rendimiento</h1>
      <p>Acelere su entrega digital con desarrolladores sénior bilingües en la misma zona horaria. Especialistas en Salesforce Core, FSC, LWC, APIs bancarias, PHP moderno y arquitecturas Cloud.</p>
      
      <div style="display:flex; justify-content:center; gap:1rem; flex-wrap:wrap;">
        <a href="#contacto" class="btn-off-primary">Solicitar Perfiles Técnicos</a>
        <a href="https://wa.me/56930640431?text=Hola,%20busco%20desarrolladores%20para%20proyectos%20TI" target="_blank" style="background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.25); color:#fff; padding:0.85rem 1.75rem; border-radius:8px; text-decoration:none; font-weight:700;">WhatsApp: +56 9 3064 0431</a>
      </div>
    </div>
  </section>

  <!-- Ventajas del Modelo Keystone -->
  <main style="max-width:1100px; margin:0 auto; padding:3rem 1.5rem;">
    
    <div style="text-align:center; max-width:750px; margin:0 auto;">
      <h2 style="font-size:2rem; font-weight:800; color:#003366; margin-bottom:0.75rem;">Ingeniería de Software con Gobernanza Estricta</h2>
      <p style="color:#64748b; font-size:1.05rem;">No somos un intermediario de freelancers. Entregamos células gobernadas con control de calidad continuo, CI/CD y trazabilidad total de horas.</p>
    </div>

    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:2rem; margin:3.5rem 0;">
      <div style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:2rem; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05);">
        <span style="font-size:2.2rem; margin-bottom:1rem; display:inline-block;">⚡</span>
        <h3 style="font-size:1.25rem; font-weight:800; color:#003366; margin-bottom:0.75rem;">Zona Horaria Alineada (Timezone)</h3>
        <p style="font-size:0.95rem; color:#475569; line-height:1.6;">Operación en tiempo real sincronizada con EE.UU., Canadá, el Caribe y Sudamérica, erradicando los retrasos de 12 horas típicos del offshore asiático.</p>
      </div>

      <div style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:2rem; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05);">
        <span style="font-size:2.2rem; margin-bottom:1rem; display:inline-block;">🔄</span>
        <h3 style="font-size:1.25rem; font-weight:800; color:#003366; margin-bottom:0.75rem;">Cadena de Custodia GitFlow</h3>
        <p style="font-size:0.95rem; color:#475569; line-height:1.6;">Todo el código fuente es auditado y pasa por pipeline formal (DEV ➔ QA ➔ PROD) con Quality Gates automatizados, cobertura unitaria >85% y cero código fantasma.</p>
      </div>

      <div style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:2rem; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05);">
        <span style="font-size:2.2rem; margin-bottom:1rem; display:inline-block;">💼</span>
        <h3 style="font-size:1.25rem; font-weight:800; color:#003366; margin-bottom:0.75rem;">Eficiencia de Costos hasta 45%</h3>
        <p style="font-size:0.95rem; color:#475569; line-height:1.6;">Optimice su presupuesto de TI contratando talento de primer nivel en América Latina con contratos internacionales blindados y soporte administrativo integral.</p>
      </div>
    </div>

    <!-- Contacto -->
    <section id="contacto" style="background:#0a0f1d; color:#ffffff; border-radius:16px; padding:3rem 2rem; text-align:center;">
      <h2 style="font-size:2rem; font-weight:800; margin-bottom:0.75rem; color:#ffffff;">Escale su Equipo Técnico sin Fricciones</h2>
      <p style="color:#94a3b8; max-width:650px; margin:0 auto 2rem;">Cuéntenos las tecnologías y perfiles que necesita su proyecto para presentarle candidatos calificados en menos de 5 días hábiles.</p>
      
      <div style="display:flex; justify-content:center; gap:1.25rem; flex-wrap:wrap;">
        <a href="mailto:contacto@keystoneadvisors.cl?subject=Solicitud%20Células%20Offshore" class="btn-off-primary">Solicitar Célula Técnica</a>
        <a href="https://wa.me/56930640431" target="_blank" style="background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.25); color:#fff; padding:0.85rem 1.75rem; border-radius:8px; text-decoration:none; font-weight:700;">WhatsApp Directo: +56 9 3064 0431</a>
      </div>
    </section>

  </main>

  <!-- Footer -->
  <footer class="footer" style="margin-top:4rem;">
    <div class="footer-container">
      <div class="footer-brand">
        <img src="../assets/images/logo-keystone-white.png?v=20261107_01" alt="Keystone Advisors" width="1024" height="288" loading="lazy" style="height:48px; max-width:200px; object-fit:contain; margin-bottom:1rem;">
        <p>Consultoría de élite y arquitectura tecnológica de misión crítica en Salesforce FSC, Agentforce, Cloud y Ciberseguridad Ofensiva.</p>
      </div>
      <div class="footer-col">
        <h4>Especialidades</h4>
        <ul class="footer-links">
          <li><a href="../salesforce-fsc/">Salesforce FSC & Banca</a></li>
          <li><a href="../ciberseguridad-bec/">Ciberseguridad & DMARC</a></li>
          <li><a href="../#keyhub">KeyHub Suite</a></li>
          <li><a href="../desarrollo-offshore/">Desarrollo Offshore</a></li>
        </ul>
      </div>
      <div class="footer-col">
        <h4>Cumplimiento & Seguridad</h4>
        <ul class="footer-links">
          <li><a href="../trust/">Trust Center (TrustZero)</a></li>
          <li><a href="../privacidad.html">Política de Privacidad</a></li>
          <li><a href="../#contacto">Oficinas Internacionales</a></li>
        </ul>
      </div>
      <div class="footer-col">
        <h4>Contacto</h4>
        <ul class="footer-links">
          <li><a href="https://wa.me/56930640431" target="_blank">WhatsApp: +56 9 3064 0431</a></li>
          <li><a href="mailto:contacto@keystoneadvisors.cl">contacto@keystoneadvisors.cl</a></li>
        </ul>
      </div>
    </div>
    <div class="footer-bottom">
      <div>&copy; <?php echo date('Y'); ?> Keystone Advisors SpA. Todos los derechos reservados.</div>
      <div style="display:flex; align-items:center; gap:0.75rem;">
        <a href="../trust/"><img src="../api/trust/badge.php?domain=keystoneadvisors.cl" alt="TrustZero Verified" width="130" height="26" loading="lazy" style="height:26px; border-radius:4px; vertical-align:middle;"></a>
        <span>Plataforma Corporativa Certificada</span>
      </div>
    </div>
  </footer>

</body>
</html>

