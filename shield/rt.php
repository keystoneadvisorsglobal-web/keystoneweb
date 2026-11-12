<?php
/**
 * Keystone Red Teaming - Endpoint Público de Telemetría & Just-in-Time Education
 * Ruta: /shield/rt.php?t=<unique_token>
 * Inocuo: Registra la interacción del colaborador y despliega de inmediato la cápsula educativa.
 */

$token = trim($_GET['t'] ?? '');
if (empty($token)) {
    die("Acceso no válido.");
}

$dbFile = __DIR__ . '/../data/leads_keystone.sqlite';
try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $db->prepare("SELECT t.*, c.company_name, c.simulated_vectors FROM redteam_targets t JOIN redteam_campaigns c ON t.campaign_id = c.id WHERE t.unique_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$target) {
        die("Parámetro de verificación no encontrado o expirado.");
    }

    // Actualizar estado del colaborador a CLICKED
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

    $upd = $db->prepare("UPDATE redteam_targets SET status = 'CLICKED', last_interaction = CURRENT_TIMESTAMP WHERE id = ?");
    $upd->execute([$target['id']]);

    // Registrar evento en la bitácora
    $evt = $db->prepare("INSERT INTO redteam_events (target_id, campaign_id, event_type, ip_address, user_agent, details) VALUES (?, ?, 'CLICK', ?, ?, 'El colaborador hizo clic en el enlace del señuelo')");
    $evt->execute([$target['id'], $target['campaign_id'], $ip, $ua]);

} catch (Exception $e) {
    // Silencioso para el usuario
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cápsula Educativa de Seguridad | Keystone Advisors</title>
  <link rel="icon" type="image/png" href="../assets/images/favicon.png">
  <style>
    * { margin:0; padding:0; box-sizing:border-box; font-family:-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; }
    body { background:#0b1329; color:#f1f5f9; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:1.5rem; }
    .card { background:#131f37; border:1px solid #1e3a5f; border-radius:16px; max-width:640px; width:100%; padding:2.5rem 2rem; box-shadow:0 25px 50px -12px rgba(0,0,0,0.7); text-align:center; position:relative; overflow:hidden; }
    .card::before { content:''; position:absolute; top:0; left:0; right:0; height:6px; background:linear-gradient(90deg, #f59e0b, #ef4444); }
    .icon { font-size:3rem; margin-bottom:1rem; }
    .badge { display:inline-block; background:rgba(245, 158, 11, 0.15); color:#f59e0b; border:1px solid #d97706; padding:0.25rem 0.75rem; border-radius:99px; font-size:0.75rem; font-weight:800; text-transform:uppercase; margin-bottom:1rem; }
    h1 { font-size:1.6rem; font-weight:800; color:#ffffff; margin-bottom:0.75rem; }
    p { font-size:0.92rem; color:#94a3b8; line-height:1.6; margin-bottom:1.5rem; }
    .learning-box { background:#0f172a; border:1px solid #334e68; border-radius:12px; padding:1.25rem; text-align:left; margin-bottom:1.75rem; }
    .learning-box h3 { font-size:0.95rem; color:#38bdf8; margin-bottom:0.6rem; display:flex; align-items:center; gap:0.4rem; }
    .learning-box ul { list-style:none; padding:0; font-size:0.85rem; color:#cbd5e1; display:flex; flex-direction:column; gap:0.5rem; }
    .learning-box li span { color:#10b981; font-weight:bold; }
    .footer-note { font-size:0.75rem; color:#64748b; }
  </style>
</head>
<body>
  <div class="card">
    <div class="icon">🛡️⚠️</div>
    <span class="badge">Ejercicio Controlado de Seguridad Digital</span>
    <h1>¡Alto! Este fue un entrenamiento ético</h1>
    <p>
      Estimado/a <strong><?php echo htmlspecialchars($target['full_name'] ?? 'Colaborador'); ?></strong>, el correo con el que acabas de interactuar formó parte de un programa oficial de <strong>Red Teaming y Simulación de Ingeniería Social</strong> autorizado por <strong><?php echo htmlspecialchars($target['company_name'] ?? 'su empresa'); ?></strong>.
    </p>

    <div class="learning-box">
      <h3>🔍 ¿Cómo identificar este ciberataque en el futuro?</h3>
      <ul>
        <li><span>1. Urgencia y Confidencialidad Extrema:</span> Los atacantes presionan para actuar rápido (ej. <em>"transfiere ahora o perderemos el contrato"</em>) para inhibir los canales formales de consulta.</li>
        <li><span>2. Cambios de Cuenta de Proveedor:</span> Si un proveedor pide transferir a una cuenta bancaria distinta, <strong>nunca confíes en un correo</strong>; valida siempre por teléfono con tu contacto habitual.</li>
        <li><span>3. Remitente Falso o Parecido:</span> Revisa detenidamente el dominio del remitente (ej. @keyst0neadvisors.cl con un cero).</li>
      </ul>
    </div>

    <p style="font-size:0.85rem; color:#cbd5e1; margin-bottom:1rem;">
      <strong>Tu equipo está seguro:</strong> No se ha instalado ningún programa malicioso en tu equipo ni se han extraído datos de tu cuenta. Este ejercicio busca fortalecer nuestros reflejos de ciberdefensa corporativa.
    </p>

    <div class="footer-note">
      Programa de Ciberdefensa Asistido por Inteligencia Artificial &bull; Keystone Advisors SpA
    </div>
  </div>
</body>
</html>
