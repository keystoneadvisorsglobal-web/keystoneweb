<?php
/**
 * Keystone Command Console v4.2 — Descarga Ejecutiva de PDFs Periciales & Exportación CSV (SOL-0336)
 */
require_once __DIR__ . '/_core.php';
require_once __DIR__ . '/_pdf_generator.php';

AdminCore::startSecureSession();
if (empty($_SESSION['ks_admin_user'])) {
    http_response_code(401);
    die('Sesión no autenticada.');
}

$currentUser = $_SESSION['ks_admin_user'];
$userRole = $currentUser['role'] ?? 'comercial';
$db = AdminCore::db();
$type = trim((string)($_GET['type'] ?? ''));
$id = (int)($_GET['id'] ?? 0);

// Segregación RBAC por tipo de documento confidencial
if (in_array($type, ['leads_csv', 'scans_csv'], true) && !in_array($userRole, ['superadmin', 'comercial'], true)) {
    http_response_code(403);
    die('Su rol no tiene permisos para exportar bases comerciales.');
}
if (in_array($type, ['treasury_pdf', 'dmarc_pdf', 'redteam_pdf'], true) && !in_array($userRole, ['superadmin', 'secops'], true)) {
    http_response_code(403);
    die('Su rol requiere privilegios SecOps o Superadmin para descargar actas periciales.');
}

// 1. Exportación CSV de Leads B2B (con neutralización anti-CSV Formula Injection)
if ($type === 'leads_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="Keystone_Pipeline_B2B_' . date('Ymd_Hi') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM para Excel
    fputcsv($out, ['Referencia', 'Fecha', 'Correo', 'Dominio', 'Pilar', 'Servicio', 'Precio', 'Estado', 'Prioridad', 'Tarjeta KeyHub', 'Resumen BEC', 'Notas']);
    $rows = $db->query("SELECT order_ref, created_at, email, domain, pillar, service_name, price_info, status, priority, keyhub_card_code, scan_summary, notes FROM micro_leads ORDER BY id DESC")->fetchAll();
    foreach ($rows as $r) {
        fputcsv($out, array_map([AdminCore::class, 'sanitizeCsvCell'], array_values($r)));
    }
    fclose($out);
    exit;
}

// 2. Exportación CSV de Escaneos BEC
if ($type === 'scans_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="Keystone_Radar_BEC_' . date('Ymd_Hi') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Referencia', 'Fecha', 'Dominio', 'Score', 'Nivel Riesgo', 'DMARC', 'SPF', 'MX', 'TLS/HSTS']);
    $rows = $db->query("SELECT scan_ref, created_at, domain, score, risk_level, dmarc_label, spf_status, mx_status, tls_status FROM bec_scans ORDER BY id DESC")->fetchAll();
    foreach ($rows as $r) {
        fputcsv($out, array_map([AdminCore::class, 'sanitizeCsvCell'], array_values($r)));
    }
    fclose($out);
    exit;
}

// 3. PDF Dossier BEC
if ($type === 'bec_pdf') {
    $stmt = $db->prepare("SELECT * FROM bec_scans WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $scan = $stmt->fetch();
    if (!$scan) {
        http_response_code(404);
        die('Escaneo no encontrado.');
    }
    // Enriquecer hallazgos en vivo si aplica
    try {
        $live = AdminCore::runPassiveDomainCheck($scan['domain']);
        $live['scan_ref'] = $scan['scan_ref'];
        $bytes = KeystoneExecutivePdf::buildBecDossierBytes($live);
    } catch (Exception $e) {
        $bytes = KeystoneExecutivePdf::buildBecDossierBytes($scan);
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="Dosier_BEC_' . preg_replace('/[^a-zA-Z0-9.-]/', '_', $scan['domain']) . '.pdf"');
    echo $bytes;
    exit;
}

// 4. PDF Certificado Orden Treasury
if ($type === 'treasury_pdf') {
    $stmt = $db->prepare("SELECT * FROM deepshield_orders WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $ord = $stmt->fetch();
    if (!$ord) {
        http_response_code(404);
        die('Orden no encontrada.');
    }
    $bytes = KeystoneExecutivePdf::buildTreasuryCertificateBytes($ord);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="Certificado_DeepShield_' . $ord['order_code'] . '.pdf"');
    echo $bytes;
    exit;
}

// 5. PDF Acta Forense DMARC RUA
if ($type === 'dmarc_pdf') {
    $stmt = $db->prepare("SELECT * FROM dmarc_reports WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $rep = $stmt->fetch();
    if (!$rep) {
        http_response_code(404);
        die('Reporte DMARC no encontrado.');
    }
    $bytes = KeystoneExecutivePdf::buildDmarcReportBytes($rep);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="Acta_Forense_DMARC_' . $rep['domain'] . '.pdf"');
    echo $bytes;
    exit;
}

// 6. PDF Dossier Red Teaming (con telemetría real de redteam_targets)
if ($type === 'redteam_pdf') {
    $stmt = $db->prepare("SELECT * FROM redteam_campaigns WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $camp = $stmt->fetch();
    if (!$camp) {
        http_response_code(404);
        die('Campaña no encontrada.');
    }
    $tStmt = $db->prepare("SELECT * FROM redteam_targets WHERE campaign_id = ? ORDER BY id ASC");
    $tStmt->execute([$id]);
    $targets = $tStmt->fetchAll();

    $bytes = KeystoneExecutivePdf::buildRedTeamBytes($camp, $targets);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="Dosier_RedTeam_' . preg_replace('/[^a-zA-Z0-9]/', '_', $camp['company_name']) . '.pdf"');
    echo $bytes;
    exit;
}

http_response_code(400);
echo 'Tipo de documento no válido.';
