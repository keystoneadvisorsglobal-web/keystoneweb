<?php
/**
 * Keystone Advisors - Endpoint AJAX Público para Simulación de Red Teaming con IA
 * Invocado desde el modal en index.php
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/RedTeamingService.php';

$action = $_POST['action'] ?? $_GET['action'] ?? 'simulate';
$service = new RedTeamingService();

if ($action === 'simulate') {
    $company = trim($_POST['company_name'] ?? '');
    $industry = trim($_POST['industry'] ?? '');
    $vector = trim($_POST['attack_vector'] ?? 'BEC_CEO_FRAUD');
    $contactName = trim($_POST['contact_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    $simulation = $service->generatePublicSimulation($company, $industry, $vector, $contactName, $email, $phone);
    echo json_encode([
        'success' => true,
        'data' => $simulation
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'capture_lead') {
    $simId = (int)($_POST['simulation_id'] ?? 0);
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    if (empty($email) || strpos($email, '@') === false) {
        echo json_encode(['success' => false, 'error' => 'Por favor ingrese un correo válido.']);
        exit;
    }

    $ok = $service->captureSimulationLead($simId, $email, $phone);
    echo json_encode(['success' => $ok]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Acción no válida']);
