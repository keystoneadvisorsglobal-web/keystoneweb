<?php
/**
 * Keystone Growth B2B — Endpoint de Telemetría de Apertura y Clic Outbound (SOL-0342)
 * Registra métricas B2B de campañas comerciales sin capturar datos sensibles.
 */
require_once __DIR__ . '/admin/_core.php';

$db = AdminCore::db();

// 1. Píxel transparente 1x1 GIF para apertura de correo B2B
if (!empty($_GET['o'])) {
    $tok = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$_GET['o']);
    if ($tok !== '') {
        $stmt = $db->prepare("
            UPDATE outbound_dispatches
            SET status = CASE WHEN status = 'SENT' THEN 'OPENED' ELSE status END,
                opened_at = COALESCE(opened_at, CURRENT_TIMESTAMP)
            WHERE track_token = ?
        ");
        $stmt->execute([$tok]);
    }
    header('Content-Type: image/gif');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    exit;
}

// 2. Redirección segura de clic hacia el sitio corporativo de Keystone
if (!empty($_GET['c'])) {
    $tok = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$_GET['c']);
    if ($tok !== '') {
        $stmt = $db->prepare("
            UPDATE outbound_dispatches
            SET status = 'CLICKED',
                opened_at = COALESCE(opened_at, CURRENT_TIMESTAMP),
                clicked_at = COALESCE(clicked_at, CURRENT_TIMESTAMP)
            WHERE track_token = ?
        ");
        $stmt->execute([$tok]);
    }
    // Solo redirigir a rutas internas o dominios oficiales de Keystone (prevención Open Redirect OWASP)
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'dev.keystoneadvisors.cl';
    $dest = "{$proto}://{$host}/";
    if (!empty($_GET['p']) && in_array($_GET['p'], ['trust', 'shield/portal'], true)) {
        $dest .= $_GET['p'] . '/';
    }
    header('Location: ' . $dest, true, 302);
    exit;
}

http_response_code(204);
exit;
