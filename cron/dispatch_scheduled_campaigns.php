<?php
/**
 * Keystone Growth Engine - Cron Dispatcher de Campañas Programadas (SOL-0176)
 * Se invoca periódicamente vía CLI o webhook protegido HTTP
 */

$isCli = (php_sapi_name() === 'cli');
$cronToken = $_GET['token'] ?? '';
$validToken = 'KEYSTONE_SECURE_CRON_2026';

if (!$isCli && $cronToken !== $validToken) {
    http_response_code(403);
    echo json_encode(['error' => 'Acceso denegado a cron']);
    exit;
}

require_once __DIR__ . '/../api/CampaignPlannerService.php';

$res = CampaignPlannerService::dispatchDueScheduledPosts();

if ($isCli) {
    echo "[" . date('Y-m-d H:i:s') . "] Scheduled Posts Dispatcher: " . json_encode($res) . "\n";
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($res);
}
