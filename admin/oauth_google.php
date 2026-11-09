<?php
/**
 * OAuth 2.0 Handler para Google Ads
 * Recibe el callback de Google y almacena tokens de forma segura en app_settings / marketing_keystone.sqlite
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

$clientId = '36671885786-e2lok6cv5a8rpakvudrko9u0rsh78248.apps.googleusercontent.com';
$clientSecret = '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';
$redirectUri = 'https://www.keystoneadvisors.cl/admin/oauth_google.php';

// Si el usuario hace clic en "Conectar Google Ads", redirigir a Google
if (isset($_GET['action']) && $_GET['action'] === 'connect') {
    $scope = urlencode('https://www.googleapis.com/auth/adwords');
    $authUrl = "https://accounts.google.com/o/oauth2/v2/auth?" .
               "client_id={$clientId}&" .
               "redirect_uri=" . urlencode($redirectUri) . "&" .
               "response_type=code&" .
               "scope={$scope}&" .
               "access_type=offline&" .
               "prompt=consent";
    header("Location: $authUrl");
    exit;
}

// Callback de Google con el código de autorización
if (isset($_GET['code'])) {
    $code = trim($_GET['code']);
    
    // Intercambiar código por Access Token y Refresh Token
    $tokenUrl = 'https://oauth2.googleapis.com/token';
    $postFields = [
        'code' => $code,
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri' => $redirectUri,
        'grant_type' => 'authorization_code'
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
        $refreshToken = $tokenData['refresh_token'] ?? '';
        $accessToken = $tokenData['access_token'] ?? '';
        $expiresIn = $tokenData['expires_in'] ?? 3600;

        // Persistir tokens de Google Ads en app_settings
        $db = KeystoneGrowthService::getDb();
        
        // Crear tabla de settings de marketing si no existe
        $db->exec("CREATE TABLE IF NOT EXISTS mkt_settings (
            setting_key TEXT PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $stmt = $db->prepare("INSERT INTO mkt_settings (setting_key, setting_value, updated_at) 
                              VALUES (?, ?, CURRENT_TIMESTAMP)
                              ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value, updated_at = CURRENT_TIMESTAMP");
        
        $stmt->execute(['google_ads_client_id', $clientId]);
        $stmt->execute(['google_ads_client_secret', $clientSecret]);
        $stmt->execute(['google_ads_access_token', $accessToken]);
        if (!empty($refreshToken)) {
            $stmt->execute(['google_ads_refresh_token', $refreshToken]);
        }
        $stmt->execute(['google_ads_token_expires_at', (string)(time() + (int)$expiresIn)]);

        header("Location: index.php?tab=marketing&google_connected=1");
        exit;
    } else {
        $_SESSION['google_ads_error'] = "Error al intercambiar token con Google: $response";
        header("Location: index.php?tab=marketing&google_error=1");
        exit;
    }
}

if (isset($_GET['error'])) {
    $_SESSION['google_ads_error'] = "Autorización rechazada: " . htmlspecialchars($_GET['error']);
    header("Location: index.php?tab=marketing&google_error=1");
    exit;
}

header("Location: index.php?tab=marketing");
exit;

