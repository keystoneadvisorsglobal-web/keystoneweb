<?php
// Endpoint ligero y asíncrono para telemetría forense y embudo de navegación
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
$section = isset($data['section']) ? htmlspecialchars(substr(trim($data['section']), 0, 100)) : null;
$product_id = isset($data['product_id']) ? htmlspecialchars(substr(trim($data['product_id']), 0, 50)) : null;
$product_name = isset($data['product_name']) ? htmlspecialchars(substr(trim($data['product_name']), 0, 100)) : null;
$session_id = isset($data['session_id']) ? preg_replace('/[^a-zA-Z0-9_\-]/', '', substr(trim($data['session_id']), 0, 64)) : null;
$referrer = isset($data['referrer']) ? htmlspecialchars(substr(trim($data['referrer']), 0, 255)) : null;
$page_url = isset($data['page_url']) ? htmlspecialchars(substr(trim($data['page_url']), 0, 255)) : $section;
$page_title = isset($data['page_title']) ? htmlspecialchars(substr(trim($data['page_title']), 0, 150)) : null;
$cta_type = isset($data['cta_type']) ? htmlspecialchars(substr(trim($data['cta_type']), 0, 50)) : null;
$cta_text = isset($data['cta_text']) ? htmlspecialchars(substr(trim($data['cta_text']), 0, 100)) : null;
$duration = isset($data['duration_seconds']) ? (int)$data['duration_seconds'] : null;

$user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : 'Unknown';

// Detección de Dispositivo y SO
$device = 'Escritorio';
$ua_lower = strtolower($user_agent);
if (strpos($ua_lower, 'mobile') !== false || strpos($ua_lower, 'android') !== false || strpos($ua_lower, 'iphone') !== false) {
    $device = 'Móvil';
} elseif (strpos($ua_lower, 'ipad') !== false || strpos($ua_lower, 'tablet') !== false) {
    $device = 'Tablet';
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
}
$country = $_SERVER['HTTP_CF_IPCOUNTRY'] ?? 'CL';

$leads_dir = __DIR__ . '/../data';
if (!is_dir($leads_dir)) {
    @mkdir($leads_dir, 0750, true);
}

$db_file = $leads_dir . '/leads_keystone.sqlite';

try {
    $db = new PDO('sqlite:' . $db_file);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode = WAL;");

    // Esquema robusto de telemetría forense
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
        country TEXT DEFAULT 'CL',
        device TEXT DEFAULT 'Escritorio',
        page_url TEXT,
        page_title TEXT,
        cta_type TEXT,
        cta_text TEXT,
        duration_seconds INTEGER,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Migración idempotente en caliente de columnas adicionales si ya existía la tabla previa
    $cols = $db->query("PRAGMA table_info(site_events)")->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_column($cols, 'name');
    if (!in_array('country', $colNames)) $db->exec("ALTER TABLE site_events ADD COLUMN country TEXT DEFAULT 'CL'");
    if (!in_array('device', $colNames)) $db->exec("ALTER TABLE site_events ADD COLUMN device TEXT DEFAULT 'Escritorio'");
    if (!in_array('page_url', $colNames)) $db->exec("ALTER TABLE site_events ADD COLUMN page_url TEXT");
    if (!in_array('page_title', $colNames)) $db->exec("ALTER TABLE site_events ADD COLUMN page_title TEXT");
    if (!in_array('cta_type', $colNames)) $db->exec("ALTER TABLE site_events ADD COLUMN cta_type TEXT");
    if (!in_array('cta_text', $colNames)) $db->exec("ALTER TABLE site_events ADD COLUMN cta_text TEXT");
    if (!in_array('duration_seconds', $colNames)) $db->exec("ALTER TABLE site_events ADD COLUMN duration_seconds INTEGER");

    $stmt = $db->prepare("INSERT INTO site_events (
        session_id, event_name, section, product_id, product_name, 
        referrer, user_agent, ip_address, country, device, page_url, page_title, cta_type, cta_text, duration_seconds
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $stmt->execute([
        $session_id, $event, $section, $product_id, $product_name,
        $referrer, $user_agent, $ip, $country, $device, $page_url, $page_title, $cta_type, $cta_text, $duration
    ]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Error interno']);
}
