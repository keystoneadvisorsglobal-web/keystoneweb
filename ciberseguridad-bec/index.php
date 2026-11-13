<?php
/**
 * Keystone Advisors - Landing Especializada: Ciberseguridad Ofensiva & Blindaje contra Fraude del CEO (BEC)
 * Ruta Oficial: /ciberseguridad-bec/ (https://keystoneadvisors.cl/ciberseguridad-bec/)
 * Solución SOL-0266 | Especialista SEO & SecOps
 */
$page_title = "Ciberseguridad Ofensiva & Blindaje Fraude del CEO (BEC) | DMARC Chile | Keystone Advisors";
$page_desc = "Protegemos a empresas y tesorerías contra el Fraude del CEO (BEC) y suplantación de identidad en Chile y Latam. Diagnóstico DMARC pasivo y Red Teaming con IA.";
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($page_title); ?></title>
  <meta name="description" content="<?php echo htmlspecialchars($page_desc); ?>">
  <meta name="keywords" content="fraude del ceo chile, ciberseguridad chile, auditoria dmarc santiago, proteccion bec tesoreria, red teaming ia, escaner dmarc gratuito, suplantacion correo corporativo">
  <meta name="author" content="Keystone Advisors SpA">
  <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
  <link rel="canonical" href="https://www.keystoneadvisors.cl/ciberseguridad-bec/">

  <!-- Open Graph -->
  <meta property="og:locale" content="es_CL">
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="Keystone Advisors">
  <meta property="og:title" content="<?php echo htmlspecialchars($page_title); ?>">
  <meta property="og:description" content="<?php echo htmlspecialchars($page_desc); ?>">
  <meta property="og:url" content="https://www.keystoneadvisors.cl/ciberseguridad-bec/">
  <meta property="og:image" content="https://www.keystoneadvisors.cl/assets/images/logo.png?v=20261107_01">

  <!-- Schema.org JSON-LD -->
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@graph": [
      {
        "@type": "Service",
        "@id": "https://www.keystoneadvisors.cl/ciberseguridad-bec/#service",
        "name": "Blindaje contra Fraude del CEO (BEC) & Auditoría DMARC",
        "serviceType": "Ciberseguridad Ofensiva y Protección de Tesorería",
        "provider": {
          "@type": "Organization",
          "name": "Keystone Advisors SpA",
          "url": "https://www.keystoneadvisors.cl/",
          "telephone": "+56 9 3064 0431",
          "address": {
            "@type": "PostalAddress",
            "streetAddress": "Av. Apoquindo 6410, Of. 605",
            "addressLocality": "Las Condes",
            "addressRegion": "Región Metropolitana",
            "addressCountry": "CL"
          }
        },
        "description": "Diagnóstico perimetral de vectores de suplantación, configuración de políticas DMARC reject y simulación de ataques BEC asistidos por IA."
      },
      {
        "@type": "FAQPage",
        "@id": "https://www.keystoneadvisors.cl/ciberseguridad-bec/#faq",
        "mainEntity": [
          {
            "@type": "Question",
            "name": "¿Qué es el Fraude del CEO (BEC) y por qué afecta a las empresas chilenas?",
            "acceptedAnswer": {
              "@type": "Answer",
              "text": "El Business Email Compromise (BEC) ocurre cuando ciberdelincuentes suplantan la identidad de directores ejecutivos o proveedores legítimos para ordenar desvíos de fondos y pagos urgentes a cuentas fraudulentas, evadiendo los antivirus tradicionales mediante ingeniería social avanzada."
            }
          },
          {
            "@type": "Question",
            "name": "¿Cómo detiene la política DMARC la suplantación de identidad?",
            "acceptedAnswer": {
              "@type": "Answer",
              "text": "DMARC en modo reject instruye a los servidores de correo de todo el mundo a bloquear y descartar cualquier mensaje que intente utilizar el dominio de su empresa sin las firmas criptográficas SPF y DKIM autorizadas."
            }
          }
        ]
      }
    ]
  }
  </script>

  <link rel="icon" type="image/png" href="../assets/images/favicon.png">
  <link rel="stylesheet" href="../assets/css/style.css?v=20261107_10">
  <style>
    .bec-hero {
      background: radial-gradient(circle at 50% 20%, #1e1b4b 0%, #090d16 90%);
      color: #ffffff;
      padding: 5rem 1.5rem 4rem;
      text-align: center;
      border-bottom: 1px solid rgba(255,255,255,0.1);
    }
    .badge-bec {
      background: rgba(239, 68, 68, 0.15);
      border: 1px solid #ef4444;
      color: #f87171;
      padding: 0.35rem 0.9rem;
      border-radius: 9999px;
      font-size: 0.82rem;
      font-weight: 700;
      letter-spacing: 0.5px;
      text-transform: uppercase;
      display: inline-block;
      margin-bottom: 1.25rem;
    }
    .bec-hero h1 {
      font-size: 2.6rem;
      font-weight: 800;
      line-height: 1.2;
      max-width: 900px;
      margin: 0 auto 1.25rem;
      letter-spacing: -0.5px;
    }
    .bec-hero p {
      font-size: 1.15rem;
      color: #94a3b8;
      max-width: 780px;
      margin: 0 auto 2rem;
      line-height: 1.6;
    }
    .btn-bec-primary {
      background: #ef4444;
      color: #ffffff;
      padding: 0.85rem 1.75rem;
      border-radius: 8px;
      font-weight: 800;
      text-decoration: none;
      transition: all 0.2s;
    }
    .btn-bec-primary:hover {
      background: #dc2626;
      transform: translateY(-2px);
    }
    .grid-security {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
      gap: 2rem;
      margin: 3.5rem 0;
    }
    .sec-card {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      padding: 2rem;
      box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
      transition: transform 0.2s;
    }
    .sec-card:hover { transform: translateY(-4px); }
  </style>
</head>
<body>

  <!-- Header -->
  <header class="header">
    <div class="header-container">
      <a href="../" class="brand">
        <img src="../assets/images/logo.png?v=20261107_01" alt="Keystone Advisors Logo" width="1024" height="288" fetchpriority="high">
      </a>
      <nav>
        <ul class="nav-links">
          <li><a href="../">Inicio</a></li>
          <li><a href="../salesforce-fsc/">Salesforce FSC</a></li>
          <li><a href="../ciberseguridad-bec/" style="color:#f87171; font-weight:800;">Ciberseguridad & DMARC</a></li>
          
          <li><a href="../trust/">Trust Center</a></li>
          <li><a href="#contacto" class="btn-primary" style="padding:0.45rem 1rem; border-radius:6px; font-size:0.85rem;">Diagnóstico Rápido</a></li>
        </ul>
      </nav>
    </div>
  </header>

  <!-- Hero Section -->
  <section class="bec-hero">
    <div style="max-width:1100px; margin:0 auto;">
      <span class="badge-bec">Protección de Tesorería & Pagos</span>
      <h1>Blindaje contra el Fraude del CEO (BEC) & Auditoría DMARC en Tiempo Real</h1>
      <p>Proteja sus cuentas bancarias y cadena de pagos contra ataques de suplantación de identidad asistidos por Inteligencia Artificial y robo de transferencias corporativas.</p>
      
      <div style="display:flex; justify-content:center; gap:1rem; flex-wrap:wrap;">
        <a href="../#escaner" class="btn-bec-primary">Ejecutar Escáner DMARC Gratuito</a>
        <a href="https://wa.me/56930640431?text=Hola,%20solicito%20diagnóstico%20de%20ciberseguridad%20DMARC" target="_blank" class="btn-fsc-secondary" style="background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.25); color:#fff; padding:0.85rem 1.75rem; border-radius:8px; text-decoration:none; font-weight:700;">WhatsApp Emergencias Ciberseguridad</a>
      </div>
    </div>
  </section>

  <!-- Pilares de Ciberdefensa -->
  <main style="max-width:1100px; margin:0 auto; padding:3rem 1.5rem;">
    
    <div style="text-align:center; max-width:750px; margin:0 auto;">
      <h2 style="font-size:2rem; font-weight:800; color:#003366; margin-bottom:0.75rem;">Capas de Seguridad Ofensiva y Preventiva</h2>
      <p style="color:#64748b; font-size:1.05rem;">El 91% de los fraudes corporativos inician con un correo suplantado que evade los filtros convencionales.</p>
    </div>

    <div class="grid-security">
      <div class="sec-card">
        <span style="font-size:2.2rem; margin-bottom:1rem; display:inline-block;">🛡️</span>
        <h3 style="font-size:1.25rem; font-weight:800; color:#003366; margin-bottom:0.75rem;">Protección DMARC p=reject</h3>
        <p style="font-size:0.95rem; color:#475569; line-height:1.6;">Transición asistida y sin interrupción de flujos legítimos hacia la política máxima de DMARC (`p=reject`), bloqueando a nivel mundial cualquier correo emitido a nombre de su dominio sin autorización.</p>
      </div>

      <div class="sec-card">
        <span style="font-size:2.2rem; margin-bottom:1rem; display:inline-block;">🕵️</span>
        <h3 style="font-size:1.25rem; font-weight:800; color:#003366; margin-bottom:0.75rem;">Red Teaming Asistido por IA</h3>
        <p style="font-size:0.95rem; color:#475569; line-height:1.6;">Simulación controlada de ataques dirigidos con clonación de voz, dominios similares (*typosquatting*) e ingeniería social para detectar brechas en el personal de finanzas y tesorería.</p>
      </div>

      <div class="sec-card">
        <span style="font-size:2.2rem; margin-bottom:1rem; display:inline-block;">⚡</span>
        <h3 style="font-size:1.25rem; font-weight:800; color:#003366; margin-bottom:0.75rem;">Radiografía Forense de 72 Horas</h3>
        <p style="font-size:0.95rem; color:#475569; line-height:1.6;">Auditoría no invasiva de exposición externa de credenciales en la Dark Web, fugas en repositorios de código y servidores expuestos sin necesidad de instalar agentes intrusivos.</p>
      </div>
    </div>

    <!-- Contacto -->
    <section id="contacto" style="background:#090d16; color:#ffffff; border-radius:16px; padding:3rem 2rem; text-align:center; margin-top:4rem;">
      <h2 style="font-size:2rem; font-weight:800; margin-bottom:0.75rem; color:#ffffff;">Blinde su Empresa contra Fraudes Corporativos</h2>
      <p style="color:#94a3b8; max-width:650px; margin:0 auto 2rem;">Un ataque BEC promedio genera pérdidas de USD $120.000. Detecte las vulnerabilidades antes de que los atacantes las exploten.</p>
      
      <div style="display:flex; justify-content:center; gap:1.25rem; flex-wrap:wrap;">
        <a href="mailto:contacto@keystoneadvisors.cl?subject=Solicitud%20Blindaje%20DMARC%20BEC" class="btn-bec-primary">Solicitar Auditoría Preventiva</a>
        <a href="https://wa.me/56930640431" target="_blank" style="background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.25); color:#fff; padding:0.85rem 1.75rem; border-radius:8px; text-decoration:none; font-weight:700;">WhatsApp: +56 9 3064 0431</a>
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

