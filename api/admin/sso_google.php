<?php
/**
 * Keystone Command Console v4.1 — Google Workspace Corporate SSO (OpenID Connect) (SOL-0335)
 * Permite ingreso corporativo (@keystoneadvisors.cl o usuarios registrados en admin_users)
 */
require_once __DIR__ . '/_core.php';

AdminCore::startSecureSession();
$db = AdminCore::db();
$cfg = AdminCore::getNotificationSettings();

$clientId = trim((string)($cfg['google_client_id'] ?? ''));
$clientSecret = trim((string)($cfg['google_client_secret'] ?? ''));
$allowedDomain = strtolower(trim((string)($cfg['google_allowed_domain'] ?? 'keystoneadvisors.cl')));

$proto = 'https';
$host = AdminCore::safeHost();
$redirectUri = "{$proto}://{$host}/api/admin/sso_google.php";

if (empty($clientId) || empty($clientSecret)) {
    header("Location: /admin/?sso_error=" . urlencode('SSO Google no tiene Client ID configurado.'));
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'start') {
    $state = bin2hex(random_bytes(16));
    $_SESSION['ks_oauth_state'] = $state;
    $params = http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'hd' => $allowedDomain,
        'state' => $state,
        'prompt' => 'select_account',
    ]);
    header("Location: https://accounts.google.com/o/oauth2/v2/auth?{$params}");
    exit;
}

if (isset($_GET['error'])) {
    header("Location: /admin/?sso_error=" . urlencode('Acceso cancelado o URI no autorizada en Google Cloud Console.'));
    exit;
}

if (isset($_GET['code'])) {
    $state = (string)($_GET['state'] ?? '');
    if (empty($_SESSION['ks_oauth_state']) || !hash_equals($_SESSION['ks_oauth_state'], $state)) {
        header("Location: /admin/?sso_error=" . urlencode('Estado OAuth inválido. Intente nuevamente.'));
        exit;
    }
    unset($_SESSION['ks_oauth_state']);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 12);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'code' => trim((string)$_GET['code']),
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri' => $redirectUri,
        'grant_type' => 'authorization_code',
    ]));
    $resp = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$resp) {
        header("Location: /admin/?sso_error=" . urlencode('No se pudo validar el token OAuth con Google. Verifique Redirect URI en Google Cloud.'));
        exit;
    }

    $tok = json_decode($resp, true);
    $accessToken = $tok['access_token'] ?? '';
    if (!$accessToken) {
        header("Location: /admin/?sso_error=" . urlencode('Respuesta de Google sin access_token.'));
        exit;
    }

    $uCh = curl_init('https://openidconnect.googleapis.com/v1/userinfo');
    curl_setopt($uCh, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($uCh, CURLOPT_TIMEOUT, 10);
    curl_setopt($uCh, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken]);
    $uResp = curl_exec($uCh);
    curl_close($uCh);

    $profile = $uResp ? json_decode($uResp, true) : null;
    $email = strtolower(trim((string)($profile['email'] ?? '')));
    $verified = !empty($profile['email_verified']);
    $fullName = trim((string)($profile['name'] ?? 'Ejecutivo Keystone'));
    $sub = trim((string)($profile['sub'] ?? ''));

    if (!$email || !$verified) {
        header("Location: /admin/?sso_error=" . urlencode('La cuenta de Google no cuenta con correo verificado.'));
        exit;
    }

    $domainPart = explode('@', $email)[1] ?? '';

    // Política Zero-Trust: Solo cuentas explícitamente registradas y activas en admin_users (sin auto-aprovisionamiento abierto)
    $stmt = $db->prepare("SELECT * FROM admin_users WHERE LOWER(email) = ? OR (google_sub != '' AND google_sub = ?) LIMIT 1");
    $stmt->execute([$email, $sub]);
    $userRow = $stmt->fetch();

    if (!$userRow || (int)$userRow['is_active'] !== 1) {
        AdminCore::logAudit($email, 'SSO_DENIED', $email, 'Cuenta fuera de nómina autorizada Zero-Trust');
        header("Location: /admin/?sso_error=" . urlencode("La cuenta {$email} no está habilitada en la nómina de operadores de Keystone Console."));
        exit;
    }

    $db->prepare("UPDATE admin_users SET google_sub = ? WHERE id = ?")->execute([$sub, $userRow['id']]);

    // Si el operador tiene 2FA TOTP habilitado, exigir desafío TOTP también tras Google SSO
    if ((int)($userRow['totp_enabled'] ?? 0) === 1 && !empty($userRow['totp_secret'])) {
        @session_regenerate_id(true);
        $_SESSION['ks_pending_2fa_uid'] = (int)$userRow['id'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
        AdminCore::logAudit($userRow['username'], 'AUTH_SSO_PENDING_2FA', $email, 'Google SSO validado; esperando 2do factor TOTP');
        header("Location: /admin/");
        exit;
    }

    @session_regenerate_id(true);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
    $db->prepare("UPDATE admin_users SET last_login = CURRENT_TIMESTAMP WHERE id = ?")->execute([$userRow['id']]);

    $_SESSION['ks_admin_user'] = [
        'id' => (int)$userRow['id'],
        'username' => $userRow['username'],
        'email' => $email,
        'full_name' => $userRow['full_name'] ?: $fullName,
        'role' => $userRow['role'] ?: 'comercial',
        'totp_enabled' => (int)($userRow['totp_enabled'] ?? 0) === 1,
    ];

    AdminCore::logAudit($userRow['username'], 'AUTH_SSO_GOOGLE', $email, 'Ingreso exitoso vía Google Workspace SSO');
    header("Location: /admin/");
    exit;
}

header("Location: /admin/");
exit;
