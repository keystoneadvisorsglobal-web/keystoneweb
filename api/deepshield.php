<?php
/**
 * API REST Endpoint para Keystone DeepShield Treasury
 * Permite a sistemas ERP, plataformas internas o la consola administrativa:
 * 1. Crear órdenes críticas de tesorería y generar el enlace de validación efímero.
 * 2. Consultar el estado y la firma criptográfica inmutable.
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/DeepShieldService.php';

$db = Database::getInstance();
$deepShield = new DeepShieldService($db);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'create_order':
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        if (!$data) {
            $data = $_POST;
        }

        if (empty($data['amount']) || empty($data['beneficiary_name']) || empty($data['bank_name']) || empty($data['account_number'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Faltan parámetros obligatorios (monto, beneficiario, banco, cuenta)']);
            exit;
        }

        $res = $deepShield->createOrder($data);
        echo json_encode($res);
        break;

    case 'get_order':
        $token = trim($_GET['token'] ?? '');
        if (empty($token)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Token requerido']);
            exit;
        }
        $order = $deepShield->getOrderByToken($token);
        if (!$order) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Orden no encontrada']);
            exit;
        }
        echo json_encode(['success' => true, 'data' => $order]);
        break;

    case 'metrics':
        $metrics = $deepShield->getMetrics();
        echo json_encode(['success' => true, 'data' => $metrics]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Acción no especificada']);
        break;
}
