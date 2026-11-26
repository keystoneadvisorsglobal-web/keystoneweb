<?php
/**
 * API REST de Telemetría TrustCenter - JSON Endpoint
 * Ruta: /api/trust/telemetry.php?domain=keystoneadvisors.cl
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/TrustZeroTelemetryService.php';

$domain = isset($_GET['domain']) ? trim($_GET['domain']) : 'keystoneadvisors.cl';
$domain = preg_replace('#^https?://#i', '', $domain);
$domain = explode('/', $domain)[0];
$domain = preg_replace('/^www\./i', '', $domain);

if (empty($domain)) {
    $domain = 'keystoneadvisors.cl';
}

try {
    $data = TrustZeroTelemetryService::getSecurityPosture($domain);
    echo json_encode([
        'success' => true,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
