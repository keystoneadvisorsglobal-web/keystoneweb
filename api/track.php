<?php
// Endpoint ligero y asíncrono para telemetría y embudo de conversión
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Payload inválido']);
    exit;
}

$event = isset($data['event']) ? preg_replace('/[^a-zA-Z0-9_\-\.]/', '', substr(trim($data['event']), 0, 50)) : 'page_view';
$section = isset($data['section']) ? htmlspecialchars(substr(trim($data['section']), 0, 50)) : null;
$product_id = isset($data['product_id']) ? htmlspecialchars(substr(trim($data['product_id']), 0, 50)) : null;
$product_name = isset($data['product_name']) ? htmlspecialchars(substr(trim($data['product_name']), 0, 100)) : null;
$session_id = isset($data['session_id']) ? preg_replace('/[^a-zA-Z0-9_\-]/', '', substr(trim($data['session_id']), 0, 64)) : null;
$referrer = isset($data['referrer']) ? htmlspecialchars(substr(trim($data['referrer']), 0, 255)) : null;
$user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : 'Unknown';
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

$leads_dir = __DIR__ . '/../data';
if (!is_dir($leads_dir)) {
    @mkdir($leads_dir, 0750, true);
}

// Migración limpia: preferir leads_keystone.sqlite con fallback
$db_file = $leads_dir . '/leads_keystone.sqlite';

try {
    $db = new PDO('sqlite:' . $db_file);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode = WAL;");

    $db->exec("CREATE TABLE IF NOT EXISTS site_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id TEXT,
        event_name TEXT NOT NULL,
        section TEXT,
        product_id TEXT,
        product_name TEXT,
        referrer TEXT,
        user_agent TEXT,
        ip_address TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $stmt = $db->prepare("INSERT INTO site_events (session_id, event_name, section, product_id, product_name, referrer, user_agent, ip_address)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$session_id, $event, $section, $product_id, $product_name, $referrer, $user_agent, $ip]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Error interno']);
}
