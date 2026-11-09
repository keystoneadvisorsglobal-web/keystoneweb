<?php
/**
 * Endpoint de gestión de WhatsApp y Códigos QR para DeepShield
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/DeepShieldWhatsAppService.php';

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

if (!isset($_SESSION['admin_logged'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

$instance = trim($_GET['instance'] ?? $_POST['instance'] ?? 'keystone-general');
$action = trim($_GET['action'] ?? $_POST['action'] ?? 'get_qr');

$waService = new DeepShieldWhatsAppService();

if ($action === 'get_qr') {
    $res = $waService->getOrConnectInstance($instance);
    echo json_encode($res);
    exit;
} elseif ($action === 'check_state') {
    $state = $waService->getConnectionState($instance);
    echo json_encode(['success' => true, 'instance' => $instance, 'state' => $state]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Acción no válida']);
