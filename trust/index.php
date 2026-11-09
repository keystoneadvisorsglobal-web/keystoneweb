<?php
/**
 * Trust Center Público / White-Label - Keystone Advisors
 * Ruta: /trust/ (o https://www.keystoneadvisors.cl/trust/)
 */

require_once __DIR__ . '/../api/trust/TrustZeroTelemetryService.php';

$domain = isset($_GET['domain']) ? trim($_GET['domain']) : 'keystoneadvisors.cl';
$data = TrustZeroTelemetryService::getSecurityPosture($domain);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Trust Center & Postura de Seguridad | <?php echo htmlspecialchars($data['domain']); ?></title>
  <meta name="description" content="Portal de transparencia, telemetría de ciberseguridad en tiempo real y cumplimiento normativo certificado por Keystone KeyGuard.">
  <link rel="icon" type="image/png" href="../assets/images/favicon.png">
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; font-family: Arial, sans-serif; }
    body { background: #070d19; color: #e2e8f0; line-height: 1.6; }
    
    .trust-header {
      background: linear-gradient(180deg, #0a1426 0%, #070d19 100%);
      border-bottom: 1px solid #1e293b;
      padding: 2.5rem 1.5rem;
      text-align: center;
    }
    .trust-container { max-width: 1100px; margin: 0 auto; padding: 2rem 1.5rem; }
    .brand-bar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 2rem; }
    .brand-logo { height: 38px; }
    .btn-back { color: #94a3b8; text-decoration: none; font-size: 0.88rem; font-weight: 700; transition: 0.2s; }
    .btn-back:hover { color: #ffffff; }

    .hero-title { font-size: 2.2rem; font-weight: 800; color: #ffffff; margin-bottom: 0.5rem; letter-spacing: -0.5px; }
    .hero-title span { color: #00b4d8; }
    .hero-sub { color: #94a3b8; font-size: 1.05rem; max-width: 700px; margin: 0 auto 1.5rem auto; }

    /* Summary Card */
    .summary-card {
      background: #0f172a;
      border: 1px solid #1e293b;
      border-radius: 14px;
      padding: 2rem;
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 1.5rem;
      margin-bottom: 2.5rem;
      box-shadow: 0 10px 25px rgba(0,0,0,0.3);
    }
    .summary-metric { border-right: 1px solid #1e293b; padding-right: 1rem; }
    .summary-metric:last-child { border-right: none; }
    .metric-label { font-size: 0.82rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 0.35rem; }
    .metric-val { font-size: 1.8rem; font-weight: 800; color: #ffffff; }
    .metric-status { display: inline-flex; align-items: center; gap: 0.4rem; font-size: 0.95rem; font-weight: 800; color: <?php echo $data['status_color']; ?>; }

    /* Controls Grid */
    .section-title { font-size: 1.35rem; font-weight: 800; color: #ffffff; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.6rem; }
    .controls-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.25rem; margin-bottom: 3rem; }
    .control-card {
      background: #0f172a;
      border: 1px solid #1e293b;
      border-radius: 10px;
      padding: 1.25rem 1.4rem;
      transition: transform 0.2s, border-color 0.2s;
    }
    .control-card:hover { border-color: #334155; transform: translateY(-2px); }
    .control-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.6rem; }
    .control-cat { font-size: 0.72rem; font-weight: 800; text-transform: uppercase; color: #00b4d8; letter-spacing: 0.4px; }
    .badge-pass { background: rgba(16, 185, 129, 0.15); color: #34d399; font-size: 0.75rem; font-weight: 800; padding: 0.2rem 0.6rem; border-radius: 4px; border: 1px solid rgba(16, 185, 129, 0.3); }
    .badge-warn { background: rgba(245, 158, 11, 0.15); color: #fbbf24; font-size: 0.75rem; font-weight: 800; padding: 0.2rem 0.6rem; border-radius: 4px; border: 1px solid rgba(245, 158, 11, 0.3); }
    
    .control-name { font-size: 1.02rem; font-weight: 700; color: #ffffff; margin-bottom: 0.4rem; }
    .control-desc { font-size: 0.85rem; color: #94a3b8; line-height: 1.5; margin-bottom: 0.85rem; }
    .compliance-tags { display: flex; gap: 0.4rem; flex-wrap: wrap; }
    .tag { background: #1e293b; color: #cbd5e1; font-size: 0.7rem; font-weight: 600; padding: 0.15rem 0.45rem; border-radius: 3px; }

    /* Embed Widget Box */
    .embed-box {
      background: #0b1329;
      border: 1px solid #1e3a5f;
      border-radius: 12px;
      padding: 1.75rem;
      margin-bottom: 3rem;
    }
    .embed-title { font-size: 1.15rem; font-weight: 800; color: #ffffff; margin-bottom: 0.35rem; }
    .embed-code {
      background: #030712;
      border: 1px solid #1f2937;
      color: #38bdf8;
      font-family: monospace;
      padding: 0.85rem 1rem;
      border-radius: 6px;
      font-size: 0.82rem;
      overflow-x: auto;
      margin: 1rem 0;
    }

    footer { text-align: center; padding: 2rem 0; border-top: 1px solid #1e293b; color: #64748b; font-size: 0.82rem; }
  </style>
</head>
<body>

  <header class="trust-header">
    <div class="trust-container" style="padding-top:0; padding-bottom:0;">
      <div class="brand-bar">
        <a href="../">
          <img src="../assets/images/logo.png" alt="Keystone Advisors" class="brand-logo">
        </a>
        <a href="../" class="btn-back">← Volver al Sitio Corporativo</a>
      </div>

      <h1 class="hero-title">Trust Center & <span>Postura de Seguridad</span></h1>
      <p class="hero-sub">
        Telemetría y verificación en tiempo real de los controles de ciberseguridad, cifrado y blindaje perimetral para <strong><?php echo htmlspecialchars($data['domain']); ?></strong>.
      </p>

      <!-- Badge SVG en vivo -->
      <div style="margin: 1.5rem 0;">
        <img src="../api/trust/badge.php?domain=<?php echo urlencode($data['domain']); ?>" alt="TrustZero Verified Badge">
      </div>
    </div>
  </header>

  <main class="trust-container">

    <!-- Tarjeta Resumen -->
    <div class="summary-card">
      <div class="summary-metric">
        <div class="metric-label">Puntuación de Confianza</div>
        <div class="metric-val" style="color:#00b4d8;"><?php echo $data['trust_score']; ?> / 100</div>
      </div>
      <div class="summary-metric">
        <div class="metric-label">Estado Perimetral</div>
        <div class="metric-status">
          <span style="font-size:1.3rem;">●</span>
          <span><?php echo $data['status_label']; ?></span>
        </div>
      </div>
      <div class="summary-metric">
        <div class="metric-label">Controles Cumplidos</div>
        <div class="metric-val"><?php echo $data['controls_passed']; ?> / <?php echo $data['controls_total']; ?></div>
      </div>
      <div class="summary-metric">
        <div class="metric-label">Última Inspección</div>
        <div style="font-size:0.95rem; color:#cbd5e1; font-weight:700; margin-top:0.35rem;">
          <?php echo date('d/m/Y H:i', strtotime($data['last_evaluated_at'])); ?> hrs
        </div>
      </div>
    </div>

    <!-- Lista de Controles en Tiempo Real -->
    <h2 class="section-title">🛡️ Matriz de Controles de Ciberdefensa Auditados</h2>
    
    <div class="controls-grid">
      <?php foreach ($data['controls'] as $key => $ctrl): ?>
        <div class="control-card">
          <div class="control-top">
            <span class="control-cat"><?php echo htmlspecialchars($ctrl['category']); ?></span>
            <span class="<?php echo $ctrl['status'] === 'PASS' ? 'badge-pass' : 'badge-warn'; ?>">
              <?php echo $ctrl['status'] === 'PASS' ? '✓ CUMPLIDO' : '⚠️ ADVERTENCIA'; ?>
            </span>
          </div>
          <div class="control-name"><?php echo htmlspecialchars($ctrl['name']); ?></div>
          <p class="control-desc"><?php echo htmlspecialchars($ctrl['desc']); ?></p>
          <div class="compliance-tags">
            <?php foreach ($ctrl['compliance'] as $std): ?>
              <span class="tag"><?php echo htmlspecialchars($std); ?></span>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Widget Embebible para Clientes B2B -->
    <div class="embed-box">
      <h3 class="embed-title">🏷️ Sello Dinámico de Seguridad (Embed Widget)</h3>
      <p style="font-size:0.88rem; color:#94a3b8;">
        Transmita solvencia ante clientes y aseguradoras insertando este sello dinámico en el footer de su sitio web o intranet:
      </p>
      
      <div class="embed-code">
        &lt;a href="https://www.keystoneadvisors.cl/trust/?domain=<?php echo htmlspecialchars($data['domain']); ?>" target="_blank"&gt;<br>
        &nbsp;&nbsp;&lt;img src="https://www.keystoneadvisors.cl/api/trust/badge.php?domain=<?php echo htmlspecialchars($data['domain']); ?>" alt="TrustZero Verified"&gt;<br>
        &lt;/a&gt;
      </div>

      <div style="font-size:0.8rem; color:#64748b;">
        * El sello se actualiza automáticamente cada 15 minutos reflejando el estado de sus certificados SSL, DMARC y cabeceras de endurecimiento sin necesidad de intervención manual.
      </div>
    </div>

  </main>

  <footer>
    &copy; <?php echo date('Y'); ?> Keystone Advisors SpA • KeyGuard TrustZero Framework • Transparencia & Ciberdefensa Corporativa.
  </footer>

</body>
</html>

