<?php
/**
 * Keystone Growth Engine - Ingestion Endpoint
 * Recibe beacons y eventos de telemetría de sitios satélites y landing pages.
 * Multi-Tenant: keystone, sinnotario, keyhub.
 */

// Cabeceras CORS abiertas para permitir balizas desde cualquier tenant oficial
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header("Access-Control-Allow-Origin: $origin");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../KeystoneGrowthService.php';

try {
    // Leer payload JSON o Form-Data
    $rawInput = file_get_contents('php://input');
    $data = [];
    if (!empty($rawInput)) {
        $data = json_decode($rawInput, true) ?? [];
    }
    if (empty($data)) {
        $data = $_POST;
    }
    
    $action = $_GET['action'] ?? $data['action'] ?? 'touchpoint';

    switch ($action) {
        case 'touchpoint':
            // Mapear campos normalizados para KeystoneGrowthService
            $platform = 'direct';
            $src = strtolower($data['utm_source'] ?? '');
            if (!empty($data['gclid']) || strpos($src, 'google') !== false) {
                $platform = 'GOOGLE_ADS';
            } elseif (!empty($data['li_fat_id']) || strpos($src, 'linkedin') !== false) {
                $platform = 'LINKEDIN_ADS';
            } elseif (!empty($data['fbclid']) || strpos($src, 'meta') !== false || strpos($src, 'facebook') !== false) {
                $platform = 'META_ADS';
            } elseif (!empty($src)) {
                $platform = strtoupper($src);
            }

            $payload = [
                'visitor_uid' => $data['anon_id'] ?? $data['visitor_uid'] ?? '',
                'tenant_slug' => $data['tenant_id'] ?? $data['tenant_slug'] ?? 'keystone',
                'session_id' => $data['session_id'] ?? null,
                'platform' => $platform,
                'campaign_name' => $data['utm_campaign'] ?? '',
                'utm_medium' => $data['utm_medium'] ?? '',
                'utm_term' => $data['utm_term'] ?? '',
                'utm_content' => $data['utm_content'] ?? '',
                'gclid' => $data['gclid'] ?? '',
                'li_fat_id' => $data['li_fat_id'] ?? '',
                'fbclid' => $data['fbclid'] ?? '',
                'landing_url' => $data['landing_url'] ?? '/',
                'referrer_url' => $data['referrer'] ?? $data['referrer_url'] ?? ''
            ];

            $res = KeystoneGrowthService::recordTouchpoint($payload);
            echo json_encode(['success' => true, 'touchpoint' => $res]);
            break;

        case 'intent':
            $payload = [
                'visitor_uid' => $data['anon_id'] ?? $data['visitor_uid'] ?? '',
                'session_id' => $data['session_id'] ?? '',
                'tenant_slug' => $data['tenant_id'] ?? $data['tenant_slug'] ?? 'keystone',
                'page_url' => $data['landing_url'] ?? $data['page_url'] ?? '/',
                'dwell_time_pricing_sec' => (int)($data['dwell_pricing_seconds'] ?? $data['dwell_time_pricing_sec'] ?? 0),
                'dwell_time_total_sec' => (int)($data['dwell_total_seconds'] ?? $data['dwell_time_total_sec'] ?? 0),
                'max_scroll_depth_pct' => (int)($data['scroll_depth_pct'] ?? $data['max_scroll_depth_pct'] ?? 0),
                'search_queries' => $data['search_queries'] ?? [],
                'faq_questions' => $data['faq_questions'] ?? [],
                'copied_texts' => $data['copied_texts'] ?? null,
                'simulator_interactions' => (int)($data['simulator_interactions'] ?? $data['cta_clicks_count'] ?? 0),
                'rage_clicks_detected' => (int)($data['rage_clicks_detected'] ?? 0)
            ];

            $res = KeystoneGrowthService::recordUxIntent($payload);
            echo json_encode(['success' => true, 'intent' => $res]);
            break;

        case 'conversion':
            $payload = [
                'visitor_uid' => $data['anon_id'] ?? $data['visitor_uid'] ?? '',
                'tenant_slug' => $data['tenant_id'] ?? $data['tenant_slug'] ?? 'keystone',
                'conversion_type' => $data['conversion_type'] ?? 'LEAD',
                'lead_id' => !empty($data['lead_id']) ? (int)$data['lead_id'] : null,
                'customer_email' => $data['contact_email'] ?? $data['customer_email'] ?? '',
                'customer_rut' => $data['customer_rut'] ?? '',
                'revenue_amount' => (float)($data['conversion_value'] ?? $data['revenue_amount'] ?? 0.0),
                'currency' => $data['currency'] ?? 'CLP'
            ];

            $res = KeystoneGrowthService::recordConversion($payload);
            echo json_encode(['success' => true, 'conversion' => $res]);
            break;

        case 'health':
            echo json_encode(['success' => true, 'status' => 'operational', 'timestamp' => time()]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => "Acción desconocida: '$action'"]);
            break;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
