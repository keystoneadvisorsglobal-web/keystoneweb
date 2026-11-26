<?php
/**
 * Keystone Command Console v4.0 — Estudio de Gobierno del Sitio & Oferta en Vivo (SOL-0334)
 * Permite administrar atmósfera 3D por defecto, conmutador de paleta, canales de contacto y las 12 tarjetas de los 3 pilares.
 */
require_once __DIR__ . '/_core.php';

AdminCore::sendHeaders();
$currentUser = AdminCore::requireAuth(['superadmin', 'comercial']);
$db = AdminCore::db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $stmt = $db->prepare("SELECT setting_value, updated_by, updated_at FROM site_settings WHERE setting_key = 'public_site_config' LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch();

    $config = null;
    if ($row && !empty($row['setting_value'])) {
        $decoded = json_decode($row['setting_value'], true);
        if (is_array($decoded)) {
            $config = $decoded;
        }
    }

    AdminCore::jsonOk([
        'has_custom_config' => $config !== null,
        'config' => $config,
        'updated_by' => $row['updated_by'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
    ]);
}

if ($method === 'POST') {
    $body = AdminCore::readJsonBody();
    $action = trim((string)($body['action'] ?? 'save_config'));

    if ($action === 'reset_defaults') {
        $del = $db->prepare("DELETE FROM site_settings WHERE setting_key = 'public_site_config'");
        $del->execute();
        AdminCore::logAudit($currentUser['username'], 'SITE_CONFIG_RESET', 'public_site_config', 'Restaurado catálogo y atmósfera base de fábrica');
        AdminCore::jsonOk(['message' => 'Configuración restaurada a los valores base del código.']);
    }

    if ($action === 'save_config') {
        $cfg = $body['config'] ?? null;
        if (!is_array($cfg)) {
            AdminCore::jsonError('Estructura de configuración inválida.', 422);
        }

        $allowedThemes = ['amethyst_slate', 'graphite_mist', 'glaciar_prev'];
        $defaultTheme = in_array($cfg['defaultTheme'] ?? '', $allowedThemes, true)
            ? $cfg['defaultTheme']
            : 'amethyst_slate';

        $cleanConfig = [
            'defaultTheme' => $defaultTheme,
            'allowPublicThemeSwitcher' => (bool)($cfg['allowPublicThemeSwitcher'] ?? true),
            'contactEmail' => filter_var($cfg['contactEmail'] ?? '', FILTER_VALIDATE_EMAIL)
                ? trim((string)$cfg['contactEmail'])
                : 'contacto@keystoneadvisors.cl',
            'whatsappNumber' => preg_replace('/[^0-9]/', '', (string)($cfg['whatsappNumber'] ?? '56930640431')),
            'whatsappDisplay' => trim((string)($cfg['whatsappDisplay'] ?? '+56 9 3064 0431')),
            'chapters' => is_array($cfg['chapters'] ?? null) ? $cfg['chapters'] : [],
            'partnersMarquee' => is_array($cfg['partnersMarquee'] ?? null) ? $cfg['partnersMarquee'] : [],
            'updatedAt' => date('Y-m-d H:i:s'),
        ];

        $jsonStr = json_encode($cleanConfig, JSON_UNESCAPED_UNICODE);
        $upsert = $db->prepare("
            INSERT INTO site_settings (setting_key, setting_value, updated_by, updated_at)
            VALUES ('public_site_config', ?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT(setting_key) DO UPDATE SET
                setting_value = excluded.setting_value,
                updated_by = excluded.updated_by,
                updated_at = CURRENT_TIMESTAMP
        ");
        $upsert->execute([$jsonStr, $currentUser['username']]);

        $hostLabel = $_SERVER['HTTP_HOST'] ?? 'keystoneadvisors.cl';
        AdminCore::logAudit($currentUser['username'], 'SITE_CONFIG_SAVE', 'public_site_config', "Tema activo: {$defaultTheme}");
        AdminCore::jsonOk([
            'message' => "Cambios publicados en vivo para {$hostLabel}.",
            'config' => $cleanConfig,
        ]);
    }

    AdminCore::jsonError('Acción de catálogo desconocida.', 400);
}

AdminCore::jsonError('Método no permitido.', 405);
