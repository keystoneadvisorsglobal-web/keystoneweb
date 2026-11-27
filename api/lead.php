<?php
/**
 * Keystone Spatial Website v4.1 — Public Lead Capture, Live BEC Scanner + Transactional SMTP & WhatsApp Alerts (SOL-0335)
 */
require_once __DIR__ . '/admin/_core.php';
require_once __DIR__ . '/admin/_pdf_generator.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

$data = AdminCore::readJsonBody();
if (empty($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Solicitud inválida']);
    exit;
}

$action = isset($data['action']) ? trim((string)$data['action']) : 'lead';
$email = isset($data['email']) ? strtolower(trim((string)$data['email'])) : '';
$pillar = isset($data['pillar']) ? trim((string)$data['pillar']) : '';
$serviceName = isset($data['service_name']) ? trim((string)$data['service_name']) : '';
$priceInfo = isset($data['price_info']) ? trim((string)$data['price_info']) : '';
$domain = isset($data['domain']) ? strtolower(trim((string)$data['domain'])) : '';

$db = AdminCore::db();
AdminCore::enforceSameOrigin();
$anonIp = AdminCore::anonymizeIp();

// =========================================================================
// ACCIÓN 1: ESCANEO INTERACTIVO DE DOMINIO BEC EN TIEMPO REAL (30s PASIVO)
// =========================================================================
if ($action === 'bec_scan') {
    AdminCore::checkRateLimit('public_bec_scan', 15, 900);
    try {
        $scan = AdminCore::runPassiveDomainCheck($domain);
    } catch (InvalidArgumentException $e) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Ingresa un dominio válido (ejemplo: tuempresa.cl)']);
        exit;
    }

    $scanId = 'BEC-' . strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 7));

    try {
        $stmt = $db->prepare("
            INSERT INTO bec_scans (scan_ref, domain, email, score, risk_level, dmarc_label, spf_status, mx_status, tls_status, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $scanId,
            $scan['domain'],
            $email ?: null,
            $scan['score'],
            $scan['risk_level'],
            $scan['dmarc'],
            $scan['spf'],
            $scan['mx'],
            $scan['https_hsts'],
            $anonIp,
        ]);
    } catch (Exception $e) {
        // continuar
    }

    echo json_encode([
        'success' => true,
        'scan_ref' => $scanId,
        'domain' => $scan['domain'],
        'score' => $scan['score'],
        'risk_level' => $scan['risk_level'],
        'spf' => $scan['spf'],
        'dmarc' => $scan['dmarc'],
        'mx' => $scan['mx'],
        'https_hsts' => $scan['https_hsts'],
        'findings' => $scan['findings'],
        'scanned_at' => date('d/m/Y H:i:s'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// =========================================================================
// ACCIÓN 2: REGISTRO DE MICRO-CONVERSIÓN + DESPACHO AUTOMÁTICO SMTP & WHATSAPP
// =========================================================================
AdminCore::checkRateLimit('public_lead_submit', 6, 900);
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Ingresa un correo corporativo válido']);
    exit;
}

$pillar = mb_substr($pillar, 0, 80);
$serviceName = mb_substr($serviceName, 0, 120);
$priceInfo = mb_substr($priceInfo, 0, 80);

$emailParts = explode('@', $email);
$emailDomain = $emailParts[1] ?? '';
$effectiveDomain = $domain ?: $emailDomain;
$orderRef = 'KS-' . strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 7));
$scanScoreText = isset($data['scan_score']) ? ('Score BEC: ' . (int)$data['scan_score'] . '/100') : null;

// Si no venía scan_score y el dominio no es genérico (gmail/hotmail), ejecutar chequeo rápido pasivo para enriquecer la alerta comercial
$autoBecSummary = $scanScoreText;
$quickScan = null;
if (!$autoBecSummary && !in_array($effectiveDomain, ['gmail.com', 'outlook.com', 'hotmail.com', 'yahoo.com', 'icloud.com'], true)) {
    try {
        $quickScan = AdminCore::runPassiveDomainCheck($effectiveDomain);
        $autoBecSummary = "Score {$quickScan['score']}/100 ({$quickScan['dmarc']})";
        $insB = $db->prepare("
            INSERT INTO bec_scans (scan_ref, domain, email, score, risk_level, dmarc_label, spf_status, mx_status, tls_status, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insB->execute([
            'BEC-' . strtoupper(substr(md5(uniqid()), 0, 7)),
            $quickScan['domain'],
            $email,
            $quickScan['score'],
            $quickScan['risk_level'],
            $quickScan['dmarc'],
            $quickScan['spf'],
            $quickScan['mx'],
            $quickScan['https_hsts'],
            $anonIp,
        ]);
    } catch (Exception $e) {
        // continuar sin bloquear
    }
}

try {
    $stmt = $db->prepare("
        INSERT INTO micro_leads (order_ref, action, email, domain, pillar, service_name, price_info, scan_summary, status, priority, ip_address)
        VALUES (:ref, :action, :email, :domain, :pillar, :service, :price, :scan, 'NUEVO', 'ALTA', :ip)
    ");
    $stmt->execute([
        ':ref' => $orderRef,
        ':action' => $action,
        ':email' => $email,
        ':domain' => $effectiveDomain,
        ':pillar' => $pillar ?: 'Keystone Global',
        ':service' => $serviceName ?: 'Consulta Ejecutiva',
        ':price' => $priceInfo,
        ':scan' => $autoBecSummary,
        ':ip' => $anonIp,
    ]);
} catch (Exception $e) {
    // continuar
}

// Notificaciones asíncronas / inmediatas según configuración activa en Command Console
$notifCfg = AdminCore::getNotificationSettings();

$currentHost = AdminCore::safeHost();
$consoleUrl = 'https://' . $currentHost . '/admin/';

// Escapar estrictamente valores de usuario para prevenir inyección HTML en clientes de correo
$safeService = htmlspecialchars($serviceName ?: 'Consulta Ejecutiva', ENT_QUOTES, 'UTF-8');
$safePillar = htmlspecialchars($pillar ?: 'Keystone Global', ENT_QUOTES, 'UTF-8');
$safePrice = htmlspecialchars($priceInfo, ENT_QUOTES, 'UTF-8');
$safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
$safeDomain = htmlspecialchars($effectiveDomain, ENT_QUOTES, 'UTF-8');
$safeBec = htmlspecialchars((string)($autoBecSummary ?: 'N/A'), ENT_QUOTES, 'UTF-8');

// 1. Alerta WhatsApp al Socio Comercial
if (!empty($notifCfg['whatsapp_enabled']) && !empty($notifCfg['whatsapp_alert_phone'])) {
    $waMsg = "*NUEVA SOLICITUD B2B KEYSTONE*\n"
        . "Ref: `{$orderRef}`\n"
        . "Contacto: *{$email}*\n"
        . "Organización: `{$effectiveDomain}`\n"
        . "Pilar: *{$pillar}*\n"
        . "Solución: {$serviceName}" . ($priceInfo ? " ({$priceInfo})" : '') . "\n"
        . ($autoBecSummary ? "Postura DNS/BEC: {$autoBecSummary}\n" : '')
        . "Consola: {$consoleUrl}";
    @AdminCore::sendWhatsAppAlert((string)$notifCfg['whatsapp_alert_phone'], $waMsg, $notifCfg);
}

// 2. Correo SMTP interno + Auto-respuesta ejecutiva limpia al prospecto
if (!empty($notifCfg['smtp_enabled']) && !empty($notifCfg['smtp_pass'])) {
    $salesTo = !empty($notifCfg['sales_alert_email']) ? $notifCfg['sales_alert_email'] : 'contacto@keystoneadvisors.cl';
    $internalHtml = "
    <div style='font-family:Arial,sans-serif;background:#080B11;color:#ffffff;padding:28px;border-radius:14px;max-width:600px;'>
      <div style='font-size:11px;letter-spacing:2px;text-transform:uppercase;color:#a78bfa;font-weight:bold;'>Keystone Command Console · Nuevo Lead B2B</div>
      <h2 style='margin:8px 0 18px 0;font-size:20px;color:#ffffff;'>{$safeService} ({$orderRef})</h2>
      <table style='width:100%;border-collapse:collapse;font-size:13px;color:#e2e8f0;'>
        <tr><td style='padding:8px 0;color:#94a3b8;'>Correo Corporativo:</td><td style='padding:8px 0;font-weight:bold;color:#ffffff;'>{$safeEmail}</td></tr>
        <tr><td style='padding:8px 0;color:#94a3b8;'>Dominio:</td><td style='padding:8px 0;'>{$safeDomain}</td></tr>
        <tr><td style='padding:8px 0;color:#94a3b8;'>Pilar:</td><td style='padding:8px 0;'>{$safePillar}</td></tr>
        <tr><td style='padding:8px 0;color:#94a3b8;'>Referencia Precio:</td><td style='padding:8px 0;'>{$safePrice}</td></tr>
        <tr><td style='padding:8px 0;color:#94a3b8;'>Radar DNS / BEC:</td><td style='padding:8px 0;color:#6ee7b7;'>{$safeBec}</td></tr>
      </table>
      <div style='margin-top:22px;'>
        <a href='{$consoleUrl}' style='display:inline-block;padding:10px 18px;background:#ffffff;color:#080B11;text-decoration:none;border-radius:8px;font-weight:bold;font-size:12px;'>Gestionar en Command Console</a>
      </div>
    </div>";
    @AdminCore::sendSmtpEmail($salesTo, "[Keystone B2B {$orderRef}] {$email} · " . ($serviceName ?: 'Consulta'), $internalHtml, $notifCfg);

    if (!empty($notifCfg['send_prospect_autoresponder'])) {
        $clientHtml = "
        <div style='font-family:Arial,sans-serif;background:#0A0E15;color:#f8fafc;padding:32px;border-radius:14px;max-width:600px;'>
          <div style='font-size:11px;letter-spacing:2px;text-transform:uppercase;color:#94a3b8;font-weight:bold;'>Keystone Advisors Global</div>
          <h2 style='margin:10px 0 14px 0;font-size:20px;color:#ffffff;'>Hemos recibido tu solicitud ejecutiva ({$orderRef})</h2>
          <p style='font-size:14px;line-height:1.6;color:#cbd5e1;'>
            Gracias por contactar a <strong>Keystone Advisors</strong> respecto a <strong>{$safeService}</strong> ({$safePillar}).
          </p>
          " . ($autoBecSummary ? "<div style='margin:16px 0;padding:14px;background:#111827;border-left:3px solid #34d399;border-radius:6px;font-size:13px;color:#e2e8f0;'><strong>Resumen preliminar de superficie ({$safeDomain}):</strong> {$safeBec}</div>" : "") . "
          <p style='font-size:14px;line-height:1.6;color:#cbd5e1;'>
            Un socio director revisará los antecedentes técnicos de su organización y le contactará durante el día para coordinar los siguientes pasos sin fricción burocrática.
          </p>
          <p style='margin-top:24px;font-size:12px;color:#64748b;'>
            Keystone Advisors Global · Ingeniería IA-Gobernada, Salesforce FSC & Ciberseguridad Zero Trust
          </p>
        </div>";
        $pdfBytes = null;
        $pdfName = 'Dosier_BEC_Keystone.pdf';
        if ($action === 'bec_report_request' || stripos($serviceName, 'BEC') !== false || is_array($quickScan)) {
            try {
                $scanPayload = is_array($quickScan) ? $quickScan : AdminCore::runPassiveDomainCheck($effectiveDomain);
                $scanPayload['scan_ref'] = $orderRef;
                $pdfBytes = KeystoneExecutivePdf::buildBecDossierBytes($scanPayload);
                $pdfName = 'Dosier_BEC_' . preg_replace('/[^a-zA-Z0-9.-]/', '_', $effectiveDomain) . '.pdf';
            } catch (Exception $e) {
                $pdfBytes = null;
            }
        }
        @AdminCore::sendSmtpEmail($email, "Keystone Advisors | Solicitud {$orderRef} · {$serviceName}", $clientHtml, $notifCfg, $pdfBytes, $pdfName);
    }
}

echo json_encode([
    'success' => true,
    'order_ref' => $orderRef,
    'message' => "Recibido. Te enviaremos el detalle hoy a {$email}.",
], JSON_UNESCAPED_UNICODE);
