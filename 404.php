<?php
/**
 * Keystone Advisors - Página de Error 404 Institucional
 * Solución SOL-0307
 */
http_response_code(404);
$page_title = "Página No Encontrada | Keystone Advisors";
?><!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($page_title); ?></title>
  <meta name="robots" content="noindex, follow">
  <link rel="icon" type="image/png" href="/assets/images/favicon.png?v=20261107_01">
  <link rel="stylesheet" href="/assets/css/style.css?v=20261107_10">
  <style>
    .error-hero {
      min-height: 80vh;
      display: flex;
      align-items: center;
      justify-content: center;
      text-align: center;
      padding: 4rem 1.5rem;
      background: linear-gradient(135deg, #0f172a 0%, #003366 100%);
      color: #ffffff;
    }
    .error-card {
      max-width: 650px;
      background: rgba(255, 255, 255, 0.05);
      backdrop-filter: blur(12px);
      border: 1px solid rgba(255, 255, 255, 0.15);
      border-radius: 16px;
      padding: 3.5rem 2.5rem;
      box-shadow: 0 20px 40px rgba(0,0,0,0.3);
    }
    .error-code {
      font-size: 6rem;
      font-weight: 900;
      line-height: 1;
      margin-bottom: 1rem;
      background: linear-gradient(135deg, #00d2ff 0%, #3a7bd5 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
    }
    .error-title {
      font-size: 1.75rem;
      font-weight: 700;
      margin-bottom: 1rem;
      color: #ffffff;
    }
    .error-desc {
      font-size: 1.05rem;
      color: #cbd5e1;
      margin-bottom: 2.5rem;
      line-height: 1.6;
    }
    .error-actions {
      display: flex;
      gap: 1rem;
      justify-content: center;
      flex-wrap: wrap;
    }
    .btn-action {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.85rem 1.75rem;
      font-size: 0.95rem;
      font-weight: 600;
      border-radius: 8px;
      text-decoration: none;
      transition: all 0.2s ease;
    }
    .btn-action-primary {
      background: #00d2ff;
      color: #0f172a;
    }
    .btn-action-primary:hover {
      background: #38bdf8;
      transform: translateY(-2px);
    }
    .btn-action-secondary {
      background: rgba(255, 255, 255, 0.1);
      color: #ffffff;
      border: 1px solid rgba(255, 255, 255, 0.2);
    }
    .btn-action-secondary:hover {
      background: rgba(255, 255, 255, 0.2);
      transform: translateY(-2px);
    }
  </style>
</head>
<body>
  <div class="error-hero">
    <div class="error-card">
      <div class="error-code">404</div>
      <h1 class="error-title">Recurso o Página No Disponible</h1>
      <p class="error-desc">El enlace al que intenta acceder no existe o fue reestructurado en nuestra infraestructura digital. Puede regresar a nuestro sitio principal o explorar nuestras soluciones especializadas.</p>
      <div class="error-actions">
        <a href="/" class="btn-action btn-action-primary">← Ir al Inicio</a>
        <a href="/salesforce-fsc/" class="btn-action btn-action-secondary">Salesforce FSC</a>
        <a href="/ciberseguridad-bec/" class="btn-action btn-action-secondary">Ciberseguridad BEC</a>
      </div>
    </div>
  </div>
</body>
</html>
