<?php
/**
 * Keystone Advisors - Cron Desatendido de Ingesta Autónoma IMAP para DMARC RUA
 * Ejecutable vía CLI: php sync_dmarc_imap_cron.php
 * O vía HTTP seguro con token: GET /cron/sync_dmarc_imap_cron.php?key=KEYSTONE_SECURE_CRON_2026
 */

$isCli = (php_sapi_name() === 'cli');
$cronKey = 'KEYSTONE_SECURE_CRON_2026';

if (!$isCli) {
    $providedKey = $_GET['key'] ?? '';
    if ($providedKey !== $cronKey) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Acceso no autorizado. Clave cron inválida.']);
        exit;
    }
}

require_once __DIR__ . '/../api/Database.php';
require_once __DIR__ . '/../api/DmarcWatchdogService.php';

$db = Database::getInstance();
$watchdog = new DmarcWatchdogService($db);

$startTime = microtime(true);
$res = $watchdog->syncFromImap();
$duration = round(microtime(true) - $startTime, 2);

// Verificar alertas críticas de ataque masivo si se procesaron nuevos reportes
$alertsTriggered = [];
if (!empty($res['success']) && ($res['processed_count'] ?? 0) > 0) {
    $alertsTriggered = $watchdog->checkAndDispatchSpoofingAlerts();
}

$response = [
    'success' => $res['success'],
    'processed_count' => $res['processed_count'] ?? 0,
    'failed_count' => $res['failed_count'] ?? 0,
    'alerts_triggered' => count($alertsTriggered),
    'duration_seconds' => $duration,
    'timestamp' => date('Y-m-d H:i:s')
];

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response, JSON_PRETTY_PRINT);
} else {
    echo "[" . date('Y-m-d H:i:s') . "] IMAP Sync Finalizado: {$response['processed_count']} procesados, {$response['failed_count']} fallidos, {$response['alerts_triggered']} alertas en {$duration}s\n";
}
