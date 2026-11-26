<?php
/**
 * Keystone Command Console v4.4 — Configuración y Pruebas en Vivo de SMTP Titan, DMARC RUA IMAP, WhatsApp Evolution API, Google SSO & LinkedIn API (SOL-0342)
 */
require_once __DIR__ . '/_core.php';

AdminCore::sendHeaders();
$currentUser = AdminCore::requireAuth(['superadmin']);
$db = AdminCore::db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $cfg = AdminCore::getNotificationSettings();
    $maskedCfg = $cfg;
    $maskedCfg['has_smtp_pass'] = !empty($cfg['smtp_pass']);
    $maskedCfg['smtp_pass'] = '';
    $maskedCfg['has_rua_pass'] = !empty($cfg['dmarc_rua_pass']);
    $maskedCfg['dmarc_rua_pass'] = '';
    $maskedCfg['has_wa_key'] = !empty($cfg['whatsapp_api_key']);
    $maskedCfg['whatsapp_api_key'] = '';
    $maskedCfg['has_google_secret'] = !empty($cfg['google_client_secret']);
    $maskedCfg['google_client_secret'] = '';
    $maskedCfg['has_linkedin_token'] = !empty($cfg['linkedin_access_token']);
    $maskedCfg['linkedin_access_token'] = '';

    $logs = $db->query("SELECT id, channel, recipient, subject_or_ref, status, detail, created_at FROM dispatch_logs ORDER BY id DESC LIMIT 30")->fetchAll();

    AdminCore::jsonOk([
        'settings' => $maskedCfg,
        'dispatch_logs' => $logs,
    ]);
}

if ($method === 'POST') {
    $body = AdminCore::readJsonBody();
    $action = trim((string)($body['action'] ?? 'save'));
    $currentCfg = AdminCore::getNotificationSettings();

    if ($action === 'save') {
        $in = is_array($body['settings'] ?? null) ? $body['settings'] : [];
        $updated = [
            'smtp_enabled' => (bool)($in['smtp_enabled'] ?? true),
            'smtp_host' => trim((string)($in['smtp_host'] ?? ($currentCfg['smtp_host'] ?? 'smtp.titan.email'))),
            'smtp_port' => (int)($in['smtp_port'] ?? ($currentCfg['smtp_port'] ?? 465)),
            'smtp_user' => trim((string)($in['smtp_user'] ?? ($currentCfg['smtp_user'] ?? ''))),
            'smtp_pass' => !empty($in['smtp_pass']) ? (string)$in['smtp_pass'] : ($currentCfg['smtp_pass'] ?? ''),
            'smtp_from' => trim((string)($in['smtp_from'] ?? ($currentCfg['smtp_from'] ?? ''))),
            'smtp_name' => trim((string)($in['smtp_name'] ?? ($currentCfg['smtp_name'] ?? 'Keystone Advisors Global'))),
            'sales_alert_email' => trim((string)($in['sales_alert_email'] ?? ($currentCfg['sales_alert_email'] ?? 'contacto@keystoneadvisors.cl'))),
            'send_prospect_autoresponder' => (bool)($in['send_prospect_autoresponder'] ?? true),
            'dmarc_rua_user' => trim((string)($in['dmarc_rua_user'] ?? ($currentCfg['dmarc_rua_user'] ?? 'dmarc-rua@keystoneadvisors.cl'))),
            'dmarc_rua_pass' => !empty($in['dmarc_rua_pass']) ? (string)$in['dmarc_rua_pass'] : ($currentCfg['dmarc_rua_pass'] ?? ''),
            'whatsapp_enabled' => (bool)($in['whatsapp_enabled'] ?? true),
            'whatsapp_gateway_url' => trim((string)($in['whatsapp_gateway_url'] ?? ($currentCfg['whatsapp_gateway_url'] ?? ''))),
            'whatsapp_api_key' => !empty($in['whatsapp_api_key']) ? trim((string)$in['whatsapp_api_key']) : ($currentCfg['whatsapp_api_key'] ?? ''),
            'whatsapp_instance' => trim((string)($in['whatsapp_instance'] ?? ($currentCfg['whatsapp_instance'] ?? 'keystone-general'))),
            'whatsapp_alert_phone' => preg_replace('/[^0-9]/', '', (string)($in['whatsapp_alert_phone'] ?? ($currentCfg['whatsapp_alert_phone'] ?? '56930640431'))),
            'google_sso_enabled' => (bool)($in['google_sso_enabled'] ?? true),
            'google_allowed_domain' => trim((string)($in['google_allowed_domain'] ?? 'keystoneadvisors.cl')),
            'google_client_id' => trim((string)($in['google_client_id'] ?? ($currentCfg['google_client_id'] ?? ''))),
            'google_client_secret' => !empty($in['google_client_secret']) ? trim((string)$in['google_client_secret']) : ($currentCfg['google_client_secret'] ?? ''),
            'linkedin_access_token' => !empty($in['linkedin_access_token']) ? trim((string)$in['linkedin_access_token']) : ($currentCfg['linkedin_access_token'] ?? ''),
            'linkedin_org_urn' => trim((string)($in['linkedin_org_urn'] ?? ($currentCfg['linkedin_org_urn'] ?? ''))),
        ];

        AdminCore::saveSetting('notification_settings', $updated, $currentUser['username']);
        AdminCore::logAudit($currentUser['username'], 'NOTIF_CONFIG_SAVE', 'channels_oauth', 'Parámetros SMTP, WhatsApp, RUA, SSO y LinkedIn actualizados');
        AdminCore::jsonOk(['message' => 'Canales SMTP, WhatsApp, RUA, Google SSO y LinkedIn API guardados.']);
    }

    if ($action === 'test_smtp') {
        $to = trim((string)($body['to_email'] ?? ($currentCfg['sales_alert_email'] ?? '')));
        $html = "<div style='font-family:Arial,sans-serif;background:#0A0E15;color:#fff;padding:24px;border-radius:12px;'>"
            . "<h3 style='margin-top:0;color:#a78bfa;'>Prueba Operativa SMTP · Keystone Command Console v4.4</h3>"
            . "<p style='color:#cbd5e1;font-size:13px;'>Este mensaje certifica que el despacho autenticado desde <strong>{$currentCfg['smtp_host']}:{$currentCfg['smtp_port']}</strong> está 100% operativo.</p>"
            . "</div>";
        $res = AdminCore::sendSmtpEmail($to, 'Keystone Console v4.4 | Prueba Operativa SMTP', $html, $currentCfg);
        if (!$res['success']) {
            AdminCore::jsonError($res['error'] ?? 'Fallo prueba SMTP', 500);
        }
        AdminCore::logAudit($currentUser['username'], 'SMTP_TEST', $to, 'Despacho de prueba exitoso');
        AdminCore::jsonOk(['message' => "Correo de prueba entregado a {$to}."]);
    }

    if ($action === 'test_whatsapp') {
        $phone = trim((string)($body['to_phone'] ?? ($currentCfg['whatsapp_alert_phone'] ?? '')));
        $msg = "*KEYSTONE COMMAND CONSOLE v4.4*\nPrueba operativa de enlace Evolution API verificada con éxito (" . date('H:i:s') . ").";
        $res = AdminCore::sendWhatsAppAlert($phone, $msg, $currentCfg);
        if (!$res['success']) {
            AdminCore::jsonError($res['error'] ?? 'Fallo prueba WhatsApp', 500);
        }
        AdminCore::logAudit($currentUser['username'], 'WHATSAPP_TEST', $phone, 'Despacho WhatsApp de prueba exitoso');
        AdminCore::jsonOk(['message' => "Mensaje WhatsApp despachado a +{$phone}."]);
    }

    AdminCore::jsonError('Acción desconocida.', 400);
}

AdminCore::jsonError('Método no permitido.', 405);
