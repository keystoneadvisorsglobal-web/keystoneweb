<?php
/**
 * Keystone Command Console v4.1 — Autenticación, Sesión, 2FA TOTP (RFC 6238), SSO Google & RBAC (SOL-0335)
 */
require_once __DIR__ . '/_core.php';

AdminCore::sendHeaders();
AdminCore::startSecureSession();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$db = AdminCore::db();

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'session';

    if ($action === 'session') {
        $notifCfg = AdminCore::getNotificationSettings();
        $ssoEnabled = !empty($notifCfg['google_sso_enabled']) && !empty($notifCfg['google_client_id']);

        if (!empty($_SESSION['ks_admin_user'])) {
            $uid = (int)$_SESSION['ks_admin_user']['id'];
            $uStmt = $db->prepare("SELECT totp_enabled, email FROM admin_users WHERE id = ? LIMIT 1");
            $uStmt->execute([$uid]);
            $uMeta = $uStmt->fetch() ?: ['totp_enabled' => 0, 'email' => ''];
            $_SESSION['ks_admin_user']['totp_enabled'] = (int)($uMeta['totp_enabled'] ?? 0) === 1;
            $_SESSION['ks_admin_user']['email'] = $uMeta['email'] ?? '';

            AdminCore::jsonOk([
                'authenticated' => true,
                'user' => $_SESSION['ks_admin_user'],
                'csrf_token' => $_SESSION['csrf_token'],
                'google_sso_enabled' => $ssoEnabled,
            ]);
        }

        AdminCore::jsonOk([
            'authenticated' => false,
            'pending_2fa' => !empty($_SESSION['ks_pending_2fa_uid']),
            'csrf_token' => $_SESSION['csrf_token'],
            'google_sso_enabled' => $ssoEnabled,
        ]);
    }

    if ($action === 'users') {
        AdminCore::requireAuth(['superadmin']);
        $users = $db->query("SELECT id, username, email, full_name, role, totp_enabled, is_active, last_login, created_at FROM admin_users ORDER BY id ASC")->fetchAll();
        $logs = $db->query("SELECT id, actor, action_code, target_ref, detail, ip_address, created_at FROM admin_audit_log ORDER BY id DESC LIMIT 40")->fetchAll();
        AdminCore::jsonOk([
            'users' => $users,
            'audit_logs' => $logs,
        ]);
    }

    AdminCore::jsonError('Acción GET desconocida.', 400);
}

if ($method === 'POST') {
    $body = AdminCore::readJsonBody();
    $action = trim((string)($body['action'] ?? 'login'));
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // -------------------------------------------------------------------------
    // PASO 1: LOGIN PRIMARIO (USUARIO / EMAIL + CLAVE)
    // -------------------------------------------------------------------------
    if ($action === 'login') {
        $db->exec("DELETE FROM login_attempts WHERE attempted_at < datetime('now', '-15 minutes')");
        $chk = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip_address = ?");
        $chk->execute([$ip]);
        if ((int)$chk->fetchColumn() >= 8) {
            AdminCore::jsonError('Demasiados intentos fallidos. Espere 15 minutos por protección Zero Trust.', 429);
        }

        $username = trim((string)($body['username'] ?? ''));
        $password = (string)($body['password'] ?? '');

        if ($username === '' || $password === '') {
            AdminCore::jsonError('Ingrese usuario y contraseña ejecutiva.', 422);
        }

        $stmt = $db->prepare("
            SELECT id, username, email, password_hash, full_name, role, totp_secret, totp_enabled, is_active
            FROM admin_users
            WHERE username = ? OR (email != '' AND LOWER(email) = LOWER(?))
            LIMIT 1
        ");
        $stmt->execute([$username, $username]);
        $row = $stmt->fetch();

        if (!$row || (int)$row['is_active'] !== 1 || !password_verify($password, $row['password_hash'])) {
            $insAtt = $db->prepare("INSERT INTO login_attempts (ip_address, username) VALUES (?, ?)");
            $insAtt->execute([$ip, $username]);
            AdminCore::logAudit($username ?: 'unknown', 'AUTH_FAIL', $username, 'Credenciales rechazadas');
            AdminCore::jsonError('Credenciales inválidas o cuenta deshabilitada.', 401);
        }

        $clr = $db->prepare("DELETE FROM login_attempts WHERE ip_address = ?");
        $clr->execute([$ip]);

        // Si el operador tiene 2FA TOTP activado, exigir segundo factor antes de abrir sesión completa
        if ((int)$row['totp_enabled'] === 1 && !empty($row['totp_secret'])) {
            $totpCode = trim((string)($body['totp_code'] ?? ''));
            if ($totpCode === '') {
                $_SESSION['ks_pending_2fa_uid'] = (int)$row['id'];
                AdminCore::jsonOk([
                    'authenticated' => false,
                    'requires_2fa' => true,
                    'username' => $row['username'],
                    'message' => 'Ingrese el código de 6 dígitos de su autenticador TOTP.',
                ]);
            }
            if (!AdminCore::verifyTotpCode($row['totp_secret'], $totpCode)) {
                AdminCore::logAudit($row['username'], 'AUTH_2FA_FAIL', $row['username'], 'Código TOTP inválido');
                AdminCore::jsonError('Código 2FA TOTP incorrecto o expirado.', 401);
            }
        }

        unset($_SESSION['ks_pending_2fa_uid']);
        @session_regenerate_id(true);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(24));

        $upd = $db->prepare("UPDATE admin_users SET last_login = CURRENT_TIMESTAMP WHERE id = ?");
        $upd->execute([$row['id']]);

        $sessionUser = [
            'id' => (int)$row['id'],
            'username' => $row['username'],
            'email' => $row['email'] ?: '',
            'full_name' => $row['full_name'] ?: $row['username'],
            'role' => $row['role'] ?: 'comercial',
            'totp_enabled' => (int)$row['totp_enabled'] === 1,
        ];
        $_SESSION['ks_admin_user'] = $sessionUser;

        AdminCore::logAudit($sessionUser['username'], 'AUTH_LOGIN', 'console', 'Inicio de sesión exitoso');

        AdminCore::jsonOk([
            'authenticated' => true,
            'user' => $sessionUser,
            'csrf_token' => $_SESSION['csrf_token'],
        ]);
    }

    // -------------------------------------------------------------------------
    // PASO 2: VERIFICACIÓN DE DESAFÍO 2FA PENDIENTE
    // -------------------------------------------------------------------------
    if ($action === 'verify_2fa_login') {
        $pendingUid = (int)($_SESSION['ks_pending_2fa_uid'] ?? 0);
        if ($pendingUid <= 0) {
            AdminCore::jsonError('No existe un desafío 2FA pendiente. Inicie sesión nuevamente.', 400);
        }
        $code = trim((string)($body['totp_code'] ?? ''));
        $stmt = $db->prepare("SELECT id, username, email, full_name, role, totp_secret, totp_enabled, is_active FROM admin_users WHERE id = ? LIMIT 1");
        $stmt->execute([$pendingUid]);
        $row = $stmt->fetch();

        if (!$row || (int)$row['is_active'] !== 1 || !AdminCore::verifyTotpCode((string)$row['totp_secret'], $code)) {
            AdminCore::logAudit($row['username'] ?? 'uid:' . $pendingUid, 'AUTH_2FA_FAIL', '2fa', 'Código TOTP rechazado');
            AdminCore::jsonError('Código de verificación 2FA inválido.', 401);
        }

        unset($_SESSION['ks_pending_2fa_uid']);
        @session_regenerate_id(true);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(24));

        $db->prepare("UPDATE admin_users SET last_login = CURRENT_TIMESTAMP WHERE id = ?")->execute([$row['id']]);

        $sessionUser = [
            'id' => (int)$row['id'],
            'username' => $row['username'],
            'email' => $row['email'] ?: '',
            'full_name' => $row['full_name'] ?: $row['username'],
            'role' => $row['role'] ?: 'comercial',
            'totp_enabled' => true,
        ];
        $_SESSION['ks_admin_user'] = $sessionUser;
        AdminCore::logAudit($sessionUser['username'], 'AUTH_LOGIN_2FA', 'console', 'Acceso verificado con segundo factor TOTP');

        AdminCore::jsonOk([
            'authenticated' => true,
            'user' => $sessionUser,
            'csrf_token' => $_SESSION['csrf_token'],
        ]);
    }

    if ($action === 'logout') {
        $actor = $_SESSION['ks_admin_user']['username'] ?? 'session';
        AdminCore::logAudit($actor, 'AUTH_LOGOUT', 'console', 'Cierre de sesión');
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        @session_destroy();
        AdminCore::jsonOk(['authenticated' => false]);
    }

    // -------------------------------------------------------------------------
    // GESTIÓN DE 2FA TOTP DEL PROPIO OPERADOR AUTENTICADO
    // -------------------------------------------------------------------------
    if (in_array($action, ['setup_2fa', 'confirm_2fa', 'disable_2fa'], true)) {
        $me = AdminCore::requireAuth(['superadmin', 'comercial', 'secops']);
        $uid = (int)$me['id'];

        if ($action === 'setup_2fa') {
            $secret = AdminCore::generateTotpSecret(16);
            $upd = $db->prepare("UPDATE admin_users SET totp_secret = ? WHERE id = ?");
            $upd->execute([$secret, $uid]);
            $issuer = rawurlencode('Keystone Console');
            $account = rawurlencode($me['username'] . '@keystoneadvisors.cl');
            $otpauth = "otpauth://totp/{$issuer}:{$account}?secret={$secret}&issuer={$issuer}&digits=6&period=30";

            AdminCore::jsonOk([
                'secret' => $secret,
                'otpauth_url' => $otpauth,
            ]);
        }

        if ($action === 'confirm_2fa') {
            $code = trim((string)($body['totp_code'] ?? ''));
            $sStmt = $db->prepare("SELECT totp_secret FROM admin_users WHERE id = ?");
            $sStmt->execute([$uid]);
            $secret = (string)$sStmt->fetchColumn();

            if (!AdminCore::verifyTotpCode($secret, $code)) {
                AdminCore::jsonError('El código de 6 dígitos no coincide. Verifique la hora de su móvil.', 422);
            }
            $db->prepare("UPDATE admin_users SET totp_enabled = 1 WHERE id = ?")->execute([$uid]);
            $_SESSION['ks_admin_user']['totp_enabled'] = true;
            AdminCore::logAudit($me['username'], '2FA_ENABLED', $me['username'], ' Segundo factor TOTP activado');
            AdminCore::jsonOk(['message' => 'Autenticación 2FA TOTP activada en su cuenta.']);
        }

        if ($action === 'disable_2fa') {
            $db->prepare("UPDATE admin_users SET totp_enabled = 0, totp_secret = '' WHERE id = ?")->execute([$uid]);
            $_SESSION['ks_admin_user']['totp_enabled'] = false;
            AdminCore::logAudit($me['username'], '2FA_DISABLED', $me['username'], 'Segundo factor TOTP desactivado');
            AdminCore::jsonOk(['message' => '2FA desactivado para su cuenta.']);
        }
    }

    // -------------------------------------------------------------------------
    // GESTIÓN RBAC SUPERADMIN DE OPERADORES
    // -------------------------------------------------------------------------
    $currentUser = AdminCore::requireAuth(['superadmin']);

    if ($action === 'create_user') {
        $username = preg_replace('/[^a-zA-Z0-9_.-]/', '', trim((string)($body['username'] ?? '')));
        $email = strtolower(trim((string)($body['email'] ?? '')));
        $fullName = trim((string)($body['full_name'] ?? ''));
        $role = in_array($body['role'] ?? '', ['superadmin', 'comercial', 'secops'], true) ? $body['role'] : 'comercial';
        $password = (string)($body['password'] ?? '');

        if (strlen($username) < 3 || strlen($password) < 8) {
            AdminCore::jsonError('El usuario requiere mín. 3 caracteres y la clave mín. 8 caracteres.', 422);
        }

        $exists = $db->prepare("SELECT COUNT(*) FROM admin_users WHERE username = ?");
        $exists->execute([$username]);
        if ((int)$exists->fetchColumn() > 0) {
            AdminCore::jsonError('El identificador de usuario ya está registrado.', 409);
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $ins = $db->prepare("INSERT INTO admin_users (username, email, password_hash, full_name, role, is_active) VALUES (?, ?, ?, ?, ?, 1)");
        $ins->execute([$username, $email, $hash, $fullName, $role]);

        AdminCore::logAudit($currentUser['username'], 'USER_CREATE', $username, "Rol: {$role} · {$email}");
        AdminCore::jsonOk(['message' => 'Operador creado correctamente.']);
    }

    if ($action === 'toggle_user') {
        $targetId = (int)($body['user_id'] ?? 0);
        if ($targetId === (int)$currentUser['id']) {
            AdminCore::jsonError('No puede desactivar su propia sesión activa.', 422);
        }
        $upd = $db->prepare("UPDATE admin_users SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE id = ?");
        $upd->execute([$targetId]);
        AdminCore::logAudit($currentUser['username'], 'USER_TOGGLE', (string)$targetId, 'Cambio de estado activo/inactivo');
        AdminCore::jsonOk(['message' => 'Estado actualizado.']);
    }

    if ($action === 'reset_password') {
        $targetId = (int)($body['user_id'] ?? 0);
        $newPass = (string)($body['new_password'] ?? '');
        if ($targetId <= 0 || strlen($newPass) < 8) {
            AdminCore::jsonError('La nueva contraseña debe tener al menos 8 caracteres.', 422);
        }
        $hash = password_hash($newPass, PASSWORD_BCRYPT);
        $upd = $db->prepare("UPDATE admin_users SET password_hash = ? WHERE id = ?");
        $upd->execute([$hash, $targetId]);
        AdminCore::logAudit($currentUser['username'], 'USER_PASS_RESET', (string)$targetId, 'Rotación de credencial');
        AdminCore::jsonOk(['message' => 'Credencial actualizada correctamente.']);
    }

    if ($action === 'reset_user_2fa') {
        $targetId = (int)($body['user_id'] ?? 0);
        $db->prepare("UPDATE admin_users SET totp_enabled = 0, totp_secret = '' WHERE id = ?")->execute([$targetId]);
        AdminCore::logAudit($currentUser['username'], 'USER_2FA_RESET', (string)$targetId, 'Reinicio administrativo de 2FA');
        AdminCore::jsonOk(['message' => '2FA reiniciado para el operador.']);
    }

    AdminCore::jsonError('Acción POST desconocida.', 400);
}

AdminCore::jsonError('Método HTTP no soportado.', 405);
