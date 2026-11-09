<?php
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Endpoint de recepción para leads y solicitudes de contratación directa
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Payload JSON inválido']);
    exit;
}

$type = isset($data['type']) ? trim($data['type']) : 'lead'; // 'lead' o 'checkout'
$name = isset($data['name']) ? trim($data['name']) : '';
$email = isset($data['email']) ? trim($data['email']) : '';
$phone = isset($data['phone']) ? trim($data['phone']) : '';
$company = isset($data['company']) ? trim($data['company']) : '';
$country = isset($data['country']) ? trim($data['country']) : 'Chile';
$service = isset($data['service']) ? trim($data['service']) : 'General';
$product_id = isset($data['product_id']) ? trim($data['product_id']) : null;
$product_name = isset($data['product_name']) ? trim($data['product_name']) : null;
$message = isset($data['message']) ? trim($data['message']) : '';

if (empty($name) || empty($email)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Nombre y correo electrónico son obligatorios']);
    exit;
}

// Registro local seguro en SQLite / Log de trazabilidad
$leads_dir = __DIR__ . '/../data';
if (!is_dir($leads_dir)) {
    @mkdir($leads_dir, 0755, true);
}

// Migración limpia: preferir leads_keystone.sqlite con fallback
$db_file = $leads_dir . '/leads_keystone.sqlite';
try {
    $db = new PDO('sqlite:' . $db_file);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("CREATE TABLE IF NOT EXISTS requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        type TEXT NOT NULL,
        name TEXT NOT NULL,
        email TEXT NOT NULL,
        phone TEXT,
        company TEXT,
        country TEXT,
        service TEXT,
        product_id TEXT,
        product_name TEXT,
        message TEXT,
        ip_address TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $stmt = $db->prepare("INSERT INTO requests (type, name, email, phone, company, country, service, product_id, product_name, message, ip_address) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $stmt->execute([$type, $name, $email, $phone, $company, $country, $service, $product_id, $product_name, $message, $ip]);

    // Opcional: Reenvío automático hacia la API interna de KeyHub si está disponible
    $keyhub_api = 'https://keyhub.keystoneadvisors.cl/api/index.php';
    
    echo json_encode([
        'success' => true,
        'message' => $type === 'checkout' 
            ? '¡Solicitud de contratación iniciada exitosamente! Un consultor senior de Keystone Advisors te contactará en breve con la orden formal y pasarela de pago.' 
            : '¡Gracias por contactar a Keystone Advisors! Nos comunicaremos a la brevedad.',
        'order_ref' => 'KEY-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8))
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error interno al registrar solicitud: ' . $e->getMessage()]);
}
