<?php
/**
 * Keystone Advisors - Script Cron de Despacho Automático Mensual de Dosieres DMARC
 * Ejecución mensual (ej. el 1 de cada mes) para enviar el reporte PDF a los CFOs de todas las empresas activas.
 * Puede invocarse vía CLI (php dispatch_monthly_dmarc_dossier.php) o vía HTTP seguro con token:
 * GET /cron/dispatch_monthly_dmarc_dossier.php?key=KEYSTONE_SECURE_CRON_2026
 */

$isCli = (php_sapi_name() === 'cli');
$cronKey = 'KEYSTONE_SECURE_CRON_2026';

if (!$isCli) {
    $providedKey = $_GET['key'] ?? '';
    if ($providedKey !== $cronKey) {
        http_response_code(403);
        die("Acceso denegado: Token de automatización inválido.");
    }
}

require_once __DIR__ . '/../api/Database.php';
require_once __DIR__ . '/../api/DmarcWatchdogService.php';
require_once __DIR__ . '/../api/DmarcMonthlyDossierPdfGenerator.php';
require_once __DIR__ . '/../api/LeadNotificationService.php';
require_once __DIR__ . '/../api/KeystoneSmtpClient.php';

$db = Database::getInstance();
$watchdog = new DmarcWatchdogService($db);
$tenants = $watchdog->listTenants();

$period = date('m/Y', strtotime('-1 month'));
$results = [];

$smtpConfig = LeadNotificationService::getSmtpConfig();
$client = new KeystoneSmtpClient($smtpConfig);

foreach ($tenants as $ten) {
    if ($ten['status'] !== 'ACTIVE') {
        continue;
    }

    $domain = $ten['domain'];
    $metrics = $watchdog->getMetrics($domain);

    try {
        $pdfGen = new DmarcMonthlyDossierPdfGenerator($ten, $metrics, $period);
        $pdfContent = $pdfGen->generate();
        $filename = 'Dosier_DMARC_' . preg_replace('/[^A-Za-z0-9]/', '_', $domain) . '_' . date('Y_m') . '.pdf';

        $subject = "🛡️ Dosier Mensual de Blindaje de Identidad DMARC ($period) - " . $ten['company_name'];
        $html = "<div style='font-family:Arial, sans-serif; color:#1e293b; max-width:600px; margin:0 auto; padding:20px; border:1px solid #e2e8f0; border-radius:8px;'>
            <div style='background:#003366; padding:15px; border-radius:6px; color:#fff; text-align:center;'>
                <h2 style='margin:0; font-size:18px;'>KEYSTONE ADVISORS</h2>
                <span style='font-size:12px; color:#38bdf8;'>División de Ciberseguridad & Gobernanza de Correo</span>
            </div>
            <p style='margin-top:20px; font-size:14px;'>Estimado/a <strong>" . htmlspecialchars($ten['contact_name']) . "</strong>,</p>
            <p style='font-size:13px; line-height:1.6;'>Adjuntamos el <strong>Dosier Oficial de Blindaje de Identidad & Reputación DMARC</strong> correspondiente al período <strong>$period</strong> para su dominio <code>@" . htmlspecialchars($domain) . "</code>.</p>
            <div style='background:#f8fafc; border-left:4px solid #0284c7; padding:12px; margin:15px 0; font-size:13px;'>
                <strong>Resumen Ejecutivo del Mes:</strong><br>
                • <strong>Volumen Evaluado:</strong> " . number_format($metrics['total_volume']) . " correos.<br>
                • <strong>Tasa de Alineación Legítima:</strong> " . $metrics['pass_rate'] . "% de correos válidos.<br>
                • <strong>Suplantaciones Bloqueadas:</strong> " . number_format($metrics['total_fail']) . " intentos de fraude BEC neutralizados por política p=reject.<br>
            </div>
            <p style='font-size:12px; color:#64748b;'>Este informe cuenta con sello criptográfico SHA-256 de conformidad pericial y cadena de custodia emitida por Keystone Advisors.</p>
        </div>";

        $sent = $client->sendWithAttachment($ten['contact_email'], $subject, $html, $pdfContent, $filename);
        if ($sent) {
            $watchdog->markDossierSent($ten['id']);
            $results[] = ["domain" => $domain, "status" => "ENVIADO", "email" => $ten['contact_email']];
        } else {
            $rawLog = $client->getLog();
            $logStr = is_array($rawLog) ? implode(' | ', $rawLog) : (string)$rawLog;
            $results[] = ["domain" => $domain, "status" => "FALLO_SMTP", "error" => $logStr];
        }
    } catch (Exception $e) {
        $results[] = ["domain" => $domain, "status" => "ERROR", "msg" => $e->getMessage()];
    }
}

if ($isCli) {
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    echo "\n";
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(["success" => true, "timestamp" => date('Y-m-d H:i:s'), "dispatched" => $results]);
}
