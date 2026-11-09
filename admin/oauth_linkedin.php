<?php
/**
 * OAuth 2.0 Handler para LinkedIn Community Management
 * Recibe el callback de LinkedIn y almacena tokens de forma segura en mkt_settings.
 */
if (session_status() === PHP_SESSION_NONE) {
    @session_start([
        'cookie_httponly' => true,
        'cookie_secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'cookie_samesite' => 'Lax'
    ]);
}

// Permitir que el callback OAuth (?code= o ?error=) se procese incluso tras redirección externa cross-site
if (!isset($_SESSION['admin_logged']) && !isset($_GET['code']) && !isset($_GET['error'])) {
    header("Location: index.php");
    exit;
}

require_once __DIR__ . '/../api/KeystoneGrowthService.php';

$db = KeystoneGrowthService::getDb();

// Leer credenciales guardadas
$clientId = (string)$db->query("SELECT setting_value FROM mkt_settings WHERE setting_key = 'linkedin_client_id'")->fetchColumn();
$clientSecret = (string)$db->query("SELECT setting_value FROM mkt_settings WHERE setting_key = 'linkedin_client_secret'")->fetchColumn();
$redirectUri = 'https://www.keystoneadvisors.cl/admin/oauth_linkedin.php';

// Si se envían credenciales desde el modal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_linkedin_credentials') {
    $cId = trim($_POST['linkedin_client_id'] ?? '');
    $cSec = trim($_POST['linkedin_client_secret'] ?? '');
    $orgId = trim($_POST['linkedin_org_id'] ?? '');

    $stmt = $db->prepare("INSERT INTO mkt_settings (setting_key, setting_value, updated_at) 
                          VALUES (?, ?, CURRENT_TIMESTAMP)
                          ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value, updated_at = CURRENT_TIMESTAMP");
    
    if (!empty($cId)) $stmt->execute(['linkedin_client_id', $cId]);
    if (!empty($cSec)) $stmt->execute(['linkedin_client_secret', $cSec]);
    if (!empty($orgId)) {
        // Formatear URN si solo pusieron los dígitos
        $urn = strpos($orgId, 'urn:li:organization:') === 0 ? $orgId : 'urn:li:organization:' . preg_replace('/[^0-9]/', '', $orgId);
        $stmt->execute(['linkedin_org_id', preg_replace('/[^0-9]/', '', $orgId)]);
        $stmt->execute(['linkedin_org_urn', $urn]);
    }

    header("Location: index.php?tab=marketing&linkedin_saved=1");
    exit;
}

// Iniciar Flujo OAuth hacia LinkedIn
if (isset($_GET['action']) && $_GET['action'] === 'connect') {
    if (empty($clientId)) {
        die('Error: Primero debe guardar su Client ID y Client Secret de LinkedIn en la consola.');
    }

    // Permisos para publicar en la Página de Empresa y perfil
    $scopes = 'w_member_social openid profile email';
    $state = bin2hex(random_bytes(16));
    $_SESSION['linkedin_oauth_state'] = $state;

    $authUrl = "https://www.linkedin.com/oauth/v2/authorization?" .
               "response_type=code&" .
               "client_id=" . urlencode($clientId) . "&" .
               "redirect_uri=" . urlencode($redirectUri) . "&" .
               "state=" . $state . "&" .
               "scope=" . urlencode($scopes);

    header("Location: $authUrl");
    exit;
}

// Callback de LinkedIn con el código de autorización
if (isset($_GET['code'])) {
    $code = trim($_GET['code']);
    
    $tokenUrl = 'https://www.linkedin.com/oauth/v2/accessToken';
    $postFields = [
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => $redirectUri,
        'client_id' => $clientId,
        'client_secret' => $clientSecret
    ];

    $ch = curl_init($tokenUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && !empty($response)) {
        $tokenData = json_decode($response, true);
        $accessToken = $tokenData['access_token'] ?? '';
        $expiresIn = $tokenData['expires_in'] ?? 5184000; // 60 días aprox

        $stmt = $db->prepare("INSERT INTO mkt_settings (setting_key, setting_value, updated_at) 
                              VALUES (?, ?, CURRENT_TIMESTAMP)
                              ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value, updated_at = CURRENT_TIMESTAMP");
        
        $stmt->execute(['linkedin_access_token', $accessToken]);
        $stmt->execute(['linkedin_token_expires_at', (string)(time() + (int)$expiresIn)]);

        header("Location: index.php?tab=marketing&linkedin_connected=1");
        exit;
    } else {
        $_SESSION['linkedin_error'] = "Error al intercambiar token con LinkedIn: $response";
        header("Location: index.php?tab=marketing&linkedin_error=1");
        exit;
    }
}

if (isset($_GET['error'])) {
    $_SESSION['linkedin_error'] = "Autorización rechazada: " . htmlspecialchars($_GET['error_description'] ?? $_GET['error']);
    header("Location: index.php?tab=marketing&linkedin_error=1");
    exit;
}

header("Location: index.php?tab=marketing");
exit;
