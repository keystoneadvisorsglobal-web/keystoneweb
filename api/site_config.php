<?php
/**
 * Keystone Spatial Website v4.0 — Public Live Site Configuration Endpoint (SOL-0334)
 * Sirve en lectura ultrarrápida (GET) los overrides publicados desde Keystone Command Console (/admin).
 */
require_once __DIR__ . '/admin/_core.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-cache, must-revalidate');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

try {
    $db = AdminCore::db();
    $stmt = $db->prepare("SELECT setting_value, updated_at FROM site_settings WHERE setting_key = 'public_site_config' LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch();

    if ($row && !empty($row['setting_value'])) {
        $decoded = json_decode($row['setting_value'], true);
        if (is_array($decoded)) {
            echo json_encode([
                'success' => true,
                'customized' => true,
                'config' => $decoded,
                'updated_at' => $row['updated_at'],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
} catch (Exception $e) {
    // Fallback silencioso a defaults estáticos del cliente
}

echo json_encode([
    'success' => true,
    'customized' => false,
    'config' => null,
], JSON_UNESCAPED_UNICODE);
