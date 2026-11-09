<?php
require_once __DIR__ . '/api/KeystoneGrowthService.php';

$db = KeystoneGrowthService::getDb();

$s = $db->prepare('INSERT INTO mkt_settings (setting_key, setting_value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP) ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value, updated_at = CURRENT_TIMESTAMP');

// El caracter correcto era 'f' y no 't': 77gaerf3okng9m
$correctClientId = '77gaerf3okng9m';
$s->execute(['linkedin_client_id', $correctClientId]);

echo "LinkedIn Client ID updated to: {$correctClientId}\n";

