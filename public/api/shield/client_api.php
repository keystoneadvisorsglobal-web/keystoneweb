<?php
/**
 * Keystone DeepShield — API Pública para los 3 Portales Externos de Clientes (/shield/portal, /shield/verify, /shield/rt) (SOL-0336 / SOL-0340 / SOL-0341)
 * Incluye:
 * - Detector Automático de Discrepancia contra Padrón Verificado (Whitelist Anti-BEC)
 * - Quórum Multi-Firmante Mancomunado (1 o 2 firmas con tokens criptográficos independientes)
 * - Carga Masiva CSV (Nómina de Órdenes OOB y Padrón de Proveedores) + Descarga Certificado PDF SHA-256
 * - Autoatención QR WhatsApp Corporativo Propio (Evolution API v2 + Failover a keystone-general)
 */
require_once dirname(__DIR__) . '/admin/_core.php';

AdminCore::sendHeaders();
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    @session_start([
        'name' => 'KS_CLIENT_SHIELD_SID',
        'cookie_httponly' => true,
        'cookie_secure' => $isHttps,
        'cookie_samesite' => 'Lax',
        'gc_maxlifetime' => 7200,
    ]);
}

$db = AdminCore::db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'session';

    // 0. Descarga de Certificado Pericial PDF SHA-256 desde /shield/portal/
    if ($action === 'treasury_pdf') {
        if (empty($_SESSION['ks_shield_tenant']['id'])) {
            http_response_code(401);
            die('Sesión de tesorería requerida.');
        }
        $ordId = (int)($_GET['id'] ?? 0);
        $slug = $_SESSION['ks_shield_tenant']['tenant_slug'];
        $comp = $_SESSION['ks_shield_tenant']['company_name'];
        $stmt = $db->prepare("SELECT * FROM deepshield_orders WHERE id = ? AND (tenant_slug = ? OR company_name = ?) LIMIT 1");
        $stmt->execute([$ordId, $slug, $comp]);
        $ord = $stmt->fetch();
        if (!$ord) {
            http_response_code(404);
            die('Orden no encontrada.');
        }
        require_once dirname(__DIR__) . '/admin/_pdf_generator.php';
        $bytes = KeystoneExecutivePdf::buildTreasuryCertificateBytes($ord);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="Certificado_DeepShield_' . $ord['order_code'] . '.pdf"');
        echo $bytes;
        exit;
    }

    // 1. Sesión actual del Portal Cliente (verifica estado activo en vivo y sincroniza firmantes)
    if ($action === 'session') {
        if (!empty($_SESSION['ks_shield_tenant']['id'])) {
            $liveStmt = $db->prepare("SELECT * FROM deepshield_tenants WHERE id = ? AND status = 'ACTIVE' LIMIT 1");
            $liveStmt->execute([(int)$_SESSION['ks_shield_tenant']['id']]);
            $liveTen = $liveStmt->fetch();
            if (!$liveTen) {
                $_SESSION = [];
                @session_destroy();
                AdminCore::jsonOk(['authenticated' => false]);
            }

            $_SESSION['ks_shield_tenant'] = [
                'id' => (int)$liveTen['id'],
                'tenant_slug' => $liveTen['tenant_slug'],
                'company_name' => $liveTen['company_name'],
                'rut_dni' => $liveTen['rut_dni'],
                'threshold_amount' => (float)$liveTen['threshold_amount'],
                'threshold_currency' => $liveTen['threshold_currency'],
                'primary_approver_name' => $liveTen['primary_approver_name'],
                'primary_approver_email' => $liveTen['primary_approver_email'],
                'primary_approver_phone' => $liveTen['primary_approver_phone'],
                'secondary_approver_name' => (string)($liveTen['secondary_approver_name'] ?? ''),
                'secondary_approver_email' => (string)($liveTen['secondary_approver_email'] ?? ''),
                'secondary_approver_phone' => (string)($liveTen['secondary_approver_phone'] ?? ''),
                'dual_approval_threshold' => (float)($liveTen['dual_approval_threshold'] ?? 0),
                'whatsapp_instance' => $liveTen['whatsapp_instance'],
            ];

            $slug = $_SESSION['ks_shield_tenant']['tenant_slug'];
            $ordStmt = $db->prepare("
                SELECT id, order_code, company_name, amount, currency, beneficiary_name, beneficiary_rut_dni,
                       bank_name, account_number, approver_name, secondary_approver_name, status,
                       security_challenge, cryptographic_signature, whitelist_status, whitelist_detail,
                       required_approvals, approval_count, approvals_log_json, created_at
                FROM deepshield_orders
                WHERE tenant_slug = ? OR company_name = ?
                ORDER BY id DESC LIMIT 60
            ");
            $ordStmt->execute([$slug, $_SESSION['ks_shield_tenant']['company_name']]);

            $wlStmt = $db->prepare("SELECT * FROM deepshield_whitelist WHERE tenant_slug = ? ORDER BY id DESC");
            $wlStmt->execute([$slug]);

            AdminCore::jsonOk([
                'authenticated' => true,
                'tenant' => $_SESSION['ks_shield_tenant'],
                'orders' => $ordStmt->fetchAll(),
                'whitelist' => $wlStmt->fetchAll(),
            ]);
        }
        AdminCore::jsonOk(['authenticated' => false]);
    }

    // 2. Consultar Orden por Token Criptográfico para Firma Móvil OOB (/shield/verify/?token=...) — 192-bit entropy
    if ($action === 'order_by_token') {
        AdminCore::checkRateLimit('oob_token_lookup', 30, 900);
        $rawToken = trim((string)($_GET['token'] ?? ''));
        if (strlen($rawToken) < 32 || !preg_match('/^[a-f0-9]+$/i', $rawToken)) {
            AdminCore::jsonError('Enlace criptográfico inválido o incompleto.', 422);
        }
        $hash = (strlen($rawToken) === 64) ? strtolower($rawToken) : hash('sha256', $rawToken);
        $stmt = $db->prepare("
            SELECT id, order_code, tenant_slug, company_name, amount, currency, beneficiary_name, beneficiary_rut_dni,
                   bank_name, account_number, approver_name, secondary_approver_name, token_hash, secondary_token_hash,
                   status, security_challenge, cryptographic_signature, whitelist_status, whitelist_detail,
                   required_approvals, approval_count, approvals_log_json, approved_at, expires_at, created_at
            FROM deepshield_orders
            WHERE token_hash = ? OR (secondary_token_hash != '' AND secondary_token_hash = ?)
            LIMIT 1
        ");
        $stmt->execute([$hash, $hash]);
        $ord = $stmt->fetch();
        if (!$ord) {
            AdminCore::jsonError('Enlace criptográfico inválido o expirado.', 404);
        }

        // Expiración estricta en tiempo real si superó ventana de 48h
        if (in_array($ord['status'], ['PENDING_APPROVAL', 'PARTIAL_APPROVAL'], true) && !empty($ord['expires_at']) && strtotime((string)$ord['expires_at']) < time()) {
            $db->prepare("UPDATE deepshield_orders SET status = 'EXPIRED' WHERE id = ?")->execute([$ord['id']]);
            $ord['status'] = 'EXPIRED';
        }

        // Si es una orden antigua sin whitelist_status calculado, evaluarla en caliente
        if (empty($ord['whitelist_status']) || $ord['whitelist_status'] === 'UNVERIFIED') {
            $wlEval = AdminCore::evaluateWhitelistMatch((string)$ord['tenant_slug'], (string)$ord['beneficiary_rut_dni'], (string)$ord['bank_name'], (string)$ord['account_number'], (string)$ord['company_name']);
            $ord['whitelist_status'] = $wlEval['status'];
            $ord['whitelist_detail'] = $wlEval['detail'];
        }

        $isSecondary = (!empty($ord['secondary_token_hash']) && $hash === $ord['secondary_token_hash']);
        $signerSlot = $isSecondary ? 'SECONDARY' : 'PRIMARY';
        $signerName = $isSecondary
            ? ($ord['secondary_approver_name'] ?: 'Segundo Firmante Mancomunado')
            : ($ord['approver_name'] ?: 'Firmante Primario');

        unset($ord['token_hash'], $ord['secondary_token_hash']);
        $ord['signer_slot'] = $signerSlot;
        $ord['signer_name'] = $signerName;

        AdminCore::jsonOk(['order' => $ord]);
    }

    // 3. Telemetría inocua de ejercicio ético de concientización (/shield/rt/?t=...) y píxel 1x1
    if ($action === 'rt_pixel') {
        $tok = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_GET['t'] ?? ''));
        if ($tok !== '' && $tok !== 'demo') {
            $upd = $db->prepare("
                UPDATE redteam_targets
                SET status = CASE WHEN status IN ('PENDING', 'SENT') THEN 'OPENED' ELSE status END,
                    opened_at = COALESCE(opened_at, CURRENT_TIMESTAMP)
                WHERE tracking_token = ?
            ");
            $upd->execute([$tok]);
        }
        header('Content-Type: image/gif');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        exit;
    }

    if ($action === 'rt_telemetry') {
        $tok = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_GET['t'] ?? 'demo'));
        $companyName = '';
        $alreadyReported = false;
        if ($tok !== '' && $tok !== 'demo') {
            $upd = $db->prepare("
                UPDATE redteam_targets
                SET status = CASE WHEN status != 'REPORTED' THEN 'CLICKED' ELSE status END,
                    opened_at = COALESCE(opened_at, CURRENT_TIMESTAMP),
                    clicked_at = COALESCE(clicked_at, CURRENT_TIMESTAMP)
                WHERE tracking_token = ?
            ");
            $upd->execute([$tok]);

            $q = $db->prepare("
                SELECT t.status, c.company_name
                FROM redteam_targets t
                JOIN redteam_campaigns c ON c.id = t.campaign_id
                WHERE t.tracking_token = ?
                LIMIT 1
            ");
            $q->execute([$tok]);
            $row = $q->fetch();
            if ($row) {
                $companyName = (string)$row['company_name'];
                $alreadyReported = ($row['status'] === 'REPORTED');
            }
        }
        AdminCore::jsonOk([
            'recorded' => true,
            'token_ref' => substr($tok, 0, 24),
            'company_name' => $companyName,
            'already_reported' => $alreadyReported,
            'evaluated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    AdminCore::jsonError('Acción GET desconocida.', 400);
}

if ($method === 'POST') {
    $body = AdminCore::readJsonBody();
    $action = trim((string)($body['action'] ?? ''));

    // Login Operador de Tesorería del Cliente (con protección anti-fuerza bruta)
    if ($action === 'login') {
        AdminCore::enforceSameOrigin();
        AdminCore::checkRateLimit('shield_portal_login', 8, 900);
        $slug = strtolower(trim((string)($body['tenant_slug'] ?? '')));
        $email = strtolower(trim((string)($body['email'] ?? '')));
        $pass = (string)($body['password'] ?? '');

        $stmt = $db->prepare("SELECT * FROM deepshield_tenants WHERE LOWER(tenant_slug) = ? AND status = 'ACTIVE' LIMIT 1");
        $stmt->execute([$slug]);
        $t = $stmt->fetch();

        if (!$t || strtolower($t['treasury_operator_email']) !== $email || !password_verify($pass, $t['treasury_operator_password'])) {
            AdminCore::logAudit($email ?: 'unknown', 'SHIELD_LOGIN_FAIL', $slug, 'Credenciales de tesorería rechazadas');
            AdminCore::jsonError('Credenciales de tesorería inválidas.', 401);
        }

        @session_regenerate_id(true);
        $_SESSION['ks_shield_tenant'] = [
            'id' => (int)$t['id'],
            'tenant_slug' => $t['tenant_slug'],
            'company_name' => $t['company_name'],
            'rut_dni' => $t['rut_dni'],
            'threshold_amount' => (float)$t['threshold_amount'],
            'threshold_currency' => $t['threshold_currency'],
            'primary_approver_name' => $t['primary_approver_name'],
            'primary_approver_email' => $t['primary_approver_email'],
            'primary_approver_phone' => $t['primary_approver_phone'],
            'secondary_approver_name' => (string)($t['secondary_approver_name'] ?? ''),
            'secondary_approver_email' => (string)($t['secondary_approver_email'] ?? ''),
            'secondary_approver_phone' => (string)($t['secondary_approver_phone'] ?? ''),
            'dual_approval_threshold' => (float)($t['dual_approval_threshold'] ?? 0),
            'whatsapp_instance' => $t['whatsapp_instance'],
        ];

        AdminCore::jsonOk([
            'authenticated' => true,
            'tenant' => $_SESSION['ks_shield_tenant'],
        ]);
    }

    if ($action === 'logout') {
        $_SESSION = [];
        @session_destroy();
        AdminCore::jsonOk(['authenticated' => false]);
    }

    // Confirmación positiva de cápsula de concientización / reporte al SOC desde /shield/rt/
    if ($action === 'rt_report_drill') {
        $tok = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($body['token'] ?? ''));
        if ($tok !== '' && $tok !== 'demo') {
            $upd = $db->prepare("
                UPDATE redteam_targets
                SET status = 'REPORTED',
                    opened_at = COALESCE(opened_at, CURRENT_TIMESTAMP),
                    reported_at = CURRENT_TIMESTAMP
                WHERE tracking_token = ?
            ");
            $upd->execute([$tok]);
        }
        AdminCore::jsonOk(['reported' => true]);
    }

    // Resolver Orden desde Móvil del Director Firmante (/shield/verify) — Exige Token Criptográfico 192-bit + Vigencia Activa
    if ($action === 'resolve_oob') {
        AdminCore::enforceSameOrigin();
        AdminCore::checkRateLimit('oob_resolve_sign', 15, 900);
        $rawToken = trim((string)($body['token'] ?? ''));
        $decision = ($body['decision'] ?? '') === 'APPROVE' ? 'APPROVED' : 'REJECTED';
        $notes = trim((string)($body['notes'] ?? ''));

        if (strlen($rawToken) < 32 || !preg_match('/^[a-f0-9]+$/i', $rawToken)) {
            AdminCore::jsonError('Token criptográfico de autorización inválido.', 403);
        }

        $hash = (strlen($rawToken) === 64) ? strtolower($rawToken) : hash('sha256', $rawToken);
        $stmt = $db->prepare("
            SELECT * FROM deepshield_orders
            WHERE token_hash = ? OR (secondary_token_hash != '' AND secondary_token_hash = ?)
            LIMIT 1
        ");
        $stmt->execute([$hash, $hash]);
        $ord = $stmt->fetch();
        if (!$ord) {
            AdminCore::jsonError('Orden no encontrada o token inválido.', 404);
        }
        if (!empty($ord['expires_at']) && strtotime((string)$ord['expires_at']) < time()) {
            $db->prepare("UPDATE deepshield_orders SET status = 'EXPIRED' WHERE id = ? AND status IN ('PENDING_APPROVAL', 'PARTIAL_APPROVAL')")->execute([$ord['id']]);
            AdminCore::jsonError('Esta orden de pago expiró por ventana de seguridad (48h). Emita una nueva orden.', 410);
        }
        if (!in_array($ord['status'], ['PENDING_APPROVAL', 'PARTIAL_APPROVAL'], true)) {
            AdminCore::jsonError('Esta instrucción ya fue cerrada (' . $ord['status'] . ').', 409);
        }

        $isSecondary = (!empty($ord['secondary_token_hash']) && $hash === $ord['secondary_token_hash']);
        $signerSlot = $isSecondary ? 'SECONDARY' : 'PRIMARY';
        $signerName = $isSecondary
            ? ($ord['secondary_approver_name'] ?: 'Segundo Firmante Mancomunado')
            : ($ord['approver_name'] ?: 'Firmante Primario');

        $reqApprovals = max(1, (int)($ord['required_approvals'] ?? 1));
        $approvalsLog = json_decode((string)($ord['approvals_log_json'] ?? '[]'), true);
        if (!is_array($approvalsLog)) {
            $approvalsLog = [];
        }

        $ipAddr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $partialSig = hash('sha256', implode('|', [
            $ord['order_code'],
            $signerSlot,
            $signerName,
            $ord['amount'],
            $ord['beneficiary_rut_dni'],
            $decision,
            $ipAddr,
            date('c'),
        ]));

        // Si cualquiera de los firmantes bloquea por sospecha de fraude, veto inmediato
        if ($decision === 'REJECTED') {
            $approvalsLog[] = [
                'slot' => $signerSlot,
                'name' => $signerName,
                'decision' => 'REJECTED',
                'at' => date('Y-m-d H:i:s'),
                'ip' => $ipAddr,
                'sig' => substr($partialSig, 0, 24),
            ];
            $upd = $db->prepare("
                UPDATE deepshield_orders
                SET status = 'REJECTED', approved_at = CURRENT_TIMESTAMP, approved_by_ip = ?,
                    cryptographic_signature = ?, audit_notes = ?, approvals_log_json = ?
                WHERE id = ?
            ");
            $upd->execute([$ipAddr, $partialSig, $notes, json_encode($approvalsLog, JSON_UNESCAPED_UNICODE), $ord['id']]);
            AdminCore::jsonOk([
                'status' => 'REJECTED',
                'approval_count' => (int)($ord['approval_count'] ?? 0),
                'required_approvals' => $reqApprovals,
                'signature' => $partialSig,
                'approvals_log' => $approvalsLog,
                'order_code' => $ord['order_code'],
            ]);
        }

        // Evitar que el mismo token firme dos veces una orden mancomunada
        foreach ($approvalsLog as $entry) {
            if (($entry['slot'] ?? '') === $signerSlot && ($entry['decision'] ?? '') === 'APPROVED') {
                AdminCore::jsonError("La firma de {$signerName} ya fue estampada. Falta el segundo apoderado mancomunado.", 409);
            }
        }

        $approvalsLog[] = [
            'slot' => $signerSlot,
            'name' => $signerName,
            'decision' => 'APPROVED',
            'at' => date('Y-m-d H:i:s'),
            'ip' => $ipAddr,
            'sig' => substr($partialSig, 0, 24),
        ];
        $newCount = count(array_filter($approvalsLog, fn($e) => ($e['decision'] ?? '') === 'APPROVED'));
        $finalStatus = ($newCount >= $reqApprovals) ? 'APPROVED' : 'PARTIAL_APPROVAL';

        $combinedSig = hash('sha256', implode('|', array_map(fn($e) => $e['sig'] ?? '', $approvalsLog)) . '|' . $ord['order_code']);

        $upd = $db->prepare("
            UPDATE deepshield_orders
            SET status = ?, approval_count = ?, approved_at = CURRENT_TIMESTAMP, approved_by_ip = ?,
                cryptographic_signature = ?, audit_notes = ?, approvals_log_json = ?
            WHERE id = ?
        ");
        $upd->execute([
            $finalStatus,
            $newCount,
            $ipAddr,
            $combinedSig,
            $notes,
            json_encode($approvalsLog, JSON_UNESCAPED_UNICODE),
            $ord['id'],
        ]);

        AdminCore::jsonOk([
            'status' => $finalStatus,
            'approval_count' => $newCount,
            'required_approvals' => $reqApprovals,
            'signature' => $combinedSig,
            'approvals_log' => $approvalsLog,
            'order_code' => $ord['order_code'],
        ]);
    }

    // Acciones autenticadas del Operador de Tesorería en /shield/portal (con blindaje Same-Origin anti-CSRF)
    AdminCore::enforceSameOrigin();
    if (empty($_SESSION['ks_shield_tenant']['id'])) {
        AdminCore::jsonError('Sesión de tesorería expirada.', 401);
    }
    $chkStmt = $db->prepare("SELECT * FROM deepshield_tenants WHERE id = ? AND status = 'ACTIVE' LIMIT 1");
    $chkStmt->execute([(int)$_SESSION['ks_shield_tenant']['id']]);
    $liveTen = $chkStmt->fetch();
    if (!$liveTen) {
        $_SESSION = [];
        @session_destroy();
        AdminCore::jsonError('El servicio para esta empresa se encuentra suspendido o inactivo.', 403);
    }
    $_SESSION['ks_shield_tenant']['whatsapp_instance'] = $liveTen['whatsapp_instance'];
    $_SESSION['ks_shield_tenant']['primary_approver_name'] = $liveTen['primary_approver_name'];
    $_SESSION['ks_shield_tenant']['primary_approver_phone'] = $liveTen['primary_approver_phone'];
    $_SESSION['ks_shield_tenant']['secondary_approver_name'] = (string)($liveTen['secondary_approver_name'] ?? '');
    $_SESSION['ks_shield_tenant']['secondary_approver_phone'] = (string)($liveTen['secondary_approver_phone'] ?? '');
    $_SESSION['ks_shield_tenant']['dual_approval_threshold'] = (float)($liveTen['dual_approval_threshold'] ?? 0);
    $ten = $_SESSION['ks_shield_tenant'];

    // Configurar Firmante Primario + Segundo Firmante Mancomunado + Umbral Doble Firma desde /shield/portal/
    if ($action === 'update_signers_config') {
        $pName = trim((string)($body['primary_approver_name'] ?? $ten['primary_approver_name']));
        $pPhone = preg_replace('/[^0-9]/', '', (string)($body['primary_approver_phone'] ?? $ten['primary_approver_phone']));
        $sName = trim((string)($body['secondary_approver_name'] ?? ''));
        $sPhone = preg_replace('/[^0-9]/', '', (string)($body['secondary_approver_phone'] ?? ''));
        $dualThreshold = max(0, (float)($body['dual_approval_threshold'] ?? 0));

        if ($pName === '') {
            AdminCore::jsonError('El nombre del firmante primario es obligatorio.', 422);
        }

        $upd = $db->prepare("
            UPDATE deepshield_tenants
            SET primary_approver_name = ?, primary_approver_phone = ?,
                secondary_approver_name = ?, secondary_approver_phone = ?, dual_approval_threshold = ?
            WHERE id = ?
        ");
        $upd->execute([$pName, $pPhone, $sName, $sPhone, $dualThreshold, (int)$ten['id']]);
        AdminCore::jsonOk([
            'message' => 'Matriz de firmantes y quórum mancomunado actualizados.',
        ]);
    }

    // Emisión Individual OOB con Detector Automático Whitelist + Soporte Multi-Firmante (1 o 2 firmas)
    if ($action === 'emit_order') {
        $amount = (float)($body['amount'] ?? 0);
        $beneficiary = trim((string)($body['beneficiary_name'] ?? ''));
        $rut = trim((string)($body['beneficiary_rut_dni'] ?? ''));
        $bank = trim((string)($body['bank_name'] ?? ''));
        $account = trim((string)($body['account_number'] ?? ''));
        $requestedSigners = (int)($body['required_approvals'] ?? 0);

        if ($amount <= 0 || $beneficiary === '' || $bank === '' || $account === '') {
            AdminCore::jsonError('Complete todos los datos bancarios de la transferencia.', 422);
        }

        // Evaluación automática contra Padrón Verificado (Whitelist Anti-BEC)
        $wlEval = AdminCore::evaluateWhitelistMatch($ten['tenant_slug'], $rut, $bank, $account, $ten['company_name']);

        // Determinar si aplica firma simple (1) o firma mancomunada doble (2)
        $autoDual = ($ten['dual_approval_threshold'] > 0 && $amount >= $ten['dual_approval_threshold'] && $ten['secondary_approver_name'] !== '');
        $reqApprovals = ($requestedSigners === 2 || ($requestedSigners === 0 && $autoDual)) ? 2 : 1;
        if ($reqApprovals === 2 && $ten['secondary_approver_name'] === '') {
            $reqApprovals = 1; // Si no hay 2do firmante configurado aún, operar con 1 firma
        }

        $orderCode = 'DST-' . strtoupper(bin2hex(random_bytes(4)));
        $rawToken1 = bin2hex(random_bytes(24));
        $tokenHash1 = hash('sha256', $rawToken1);

        $rawToken2 = $reqApprovals === 2 ? bin2hex(random_bytes(24)) : '';
        $tokenHash2 = $rawToken2 !== '' ? hash('sha256', $rawToken2) : '';

        $challenge = strtoupper(substr(md5(random_bytes(16)), 0, 6));
        $expiresAt = date('Y-m-d H:i:s', time() + 48 * 3600);

        $ins = $db->prepare("
            INSERT INTO deepshield_orders (
                order_code, tenant_slug, company_name, amount, currency,
                beneficiary_name, beneficiary_rut_dni, bank_name, account_number,
                approver_name, approver_email, approver_phone,
                secondary_approver_name, secondary_approver_phone,
                token_hash, secondary_token_hash, status, security_challenge,
                whitelist_status, whitelist_detail, required_approvals, approval_count,
                approvals_log_json, expires_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING_APPROVAL', ?, ?, ?, ?, 0, '[]', ?)
        ");
        $ins->execute([
            $orderCode,
            $ten['tenant_slug'],
            $ten['company_name'],
            $amount,
            $ten['threshold_currency'],
            $beneficiary,
            $rut,
            $bank,
            $account,
            $ten['primary_approver_name'],
            $ten['primary_approver_email'],
            $ten['primary_approver_phone'],
            $reqApprovals === 2 ? $ten['secondary_approver_name'] : '',
            $reqApprovals === 2 ? $ten['secondary_approver_phone'] : '',
            $tokenHash1,
            $tokenHash2,
            $challenge,
            $wlEval['status'],
            $wlEval['detail'],
            $reqApprovals,
            $expiresAt,
        ]);

        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = AdminCore::safeHost();
        $verifyUrl1 = "{$proto}://{$host}/shield/verify/?token={$rawToken1}";
        $verifyUrl2 = $rawToken2 !== '' ? "{$proto}://{$host}/shield/verify/?token={$rawToken2}" : null;

        $wlBadge = $wlEval['status'] === 'VERIFIED'
            ? 'CUENTA CERTIFICADA EN PADRON'
            : ($wlEval['status'] === 'ACCOUNT_MISMATCH'
                ? 'ALERTA CRITICA ANTI-BEC: CUENTA DISTINTA AL PADRON OFICIAL'
                : 'PROVEEDOR NO EMPADRONADO (VERIFICAR)');

        $amtFmt = number_format($amount, 0, ',', '.');
        $quorumTxt = $reqApprovals === 2 ? "Quórum: *2 Firmas Mancomunadas*\n" : '';

        if (!empty($ten['primary_approver_phone'])) {
            $waMsg1 = "*DEEPSHIELD TREASURY OOB*\n"
                . "Empresa: *{$ten['company_name']}*\n"
                . "Orden: `{$orderCode}`\n"
                . "Monto: *{$ten['threshold_currency']} \${$amtFmt}*\n"
                . "Beneficiario: {$beneficiary} ({$rut})\n"
                . "Banco/Cta: {$bank} · {$account}\n"
                . "Padrón: *{$wlBadge}*\n"
                . $quorumTxt
                . "Desafío Anti-Voz: `{$challenge}`\n\n"
                . "Firmar / Bloquear ({$ten['primary_approver_name']}):\n{$verifyUrl1}";
            @AdminCore::sendWhatsAppAlert($ten['primary_approver_phone'], $waMsg1, null, $ten['whatsapp_instance'] ?? null);
        }

        if ($reqApprovals === 2 && !empty($ten['secondary_approver_phone']) && $verifyUrl2) {
            $waMsg2 = "*DEEPSHIELD FIRMA MANCOMUNADA (2/2)*\n"
                . "Empresa: *{$ten['company_name']}*\n"
                . "Orden: `{$orderCode}`\n"
                . "Monto: *{$ten['threshold_currency']} \${$amtFmt}*\n"
                . "Beneficiario: {$beneficiary} ({$rut})\n"
                . "Padrón: *{$wlBadge}*\n"
                . "Desafío Anti-Voz: `{$challenge}`\n\n"
                . "Enlace exclusivo ({$ten['secondary_approver_name']}):\n{$verifyUrl2}";
            @AdminCore::sendWhatsAppAlert($ten['secondary_approver_phone'], $waMsg2, null, $ten['whatsapp_instance'] ?? null);
        }

        AdminCore::jsonOk([
            'order_code' => $orderCode,
            'security_challenge' => $challenge,
            'verify_url' => $verifyUrl1,
            'secondary_verify_url' => $verifyUrl2,
            'whitelist_status' => $wlEval['status'],
            'whitelist_detail' => $wlEval['detail'],
            'required_approvals' => $reqApprovals,
            'message' => "Orden {$orderCode} emitida ({$reqApprovals} firma(s) requerida(s)).",
        ]);
    }

    // Carga Masiva CSV de Nómina de Pagos OOB (audita cada fila contra Whitelist y despacha resumen ejecutivo)
    if ($action === 'batch_emit_orders') {
        $rows = $body['rows'] ?? [];
        if (!is_array($rows) || count($rows) === 0) {
            AdminCore::jsonError('El archivo CSV no contiene filas válidas de transferencia.', 422);
        }
        if (count($rows) > 100) {
            AdminCore::jsonError('Máximo 100 transferencias por lote CSV.', 422);
        }

        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = AdminCore::safeHost();
        $expiresAt = date('Y-m-d H:i:s', time() + 48 * 3600);

        $ins = $db->prepare("
            INSERT INTO deepshield_orders (
                order_code, tenant_slug, company_name, amount, currency,
                beneficiary_name, beneficiary_rut_dni, bank_name, account_number,
                approver_name, approver_email, approver_phone,
                secondary_approver_name, secondary_approver_phone,
                token_hash, secondary_token_hash, status, security_challenge,
                whitelist_status, whitelist_detail, required_approvals, approval_count,
                approvals_log_json, expires_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING_APPROVAL', ?, ?, ?, ?, 0, '[]', ?)
        ");

        $createdCount = 0;
        $totalAmount = 0.0;
        $mismatchCount = 0;
        $verifiedCount = 0;
        $summaryLines1 = [];
        $summaryLines2 = [];

        foreach ($rows as $r) {
            $amount = (float)($r['amount'] ?? 0);
            $beneficiary = trim((string)($r['beneficiary_name'] ?? ''));
            $rut = trim((string)($r['beneficiary_rut_dni'] ?? ''));
            $bank = trim((string)($r['bank_name'] ?? ''));
            $account = trim((string)($r['account_number'] ?? ''));
            $reqSignersRow = (int)($r['required_approvals'] ?? 0);

            if ($amount <= 0 || $beneficiary === '' || $bank === '' || $account === '') {
                continue;
            }

            $wlEval = AdminCore::evaluateWhitelistMatch($ten['tenant_slug'], $rut, $bank, $account, $ten['company_name']);
            if ($wlEval['status'] === 'ACCOUNT_MISMATCH') {
                $mismatchCount++;
            } elseif ($wlEval['status'] === 'VERIFIED') {
                $verifiedCount++;
            }

            $autoDual = ($ten['dual_approval_threshold'] > 0 && $amount >= $ten['dual_approval_threshold'] && $ten['secondary_approver_name'] !== '');
            $reqApprovals = ($reqSignersRow === 2 || ($reqSignersRow === 0 && $autoDual)) ? 2 : 1;
            if ($reqApprovals === 2 && $ten['secondary_approver_name'] === '') {
                $reqApprovals = 1;
            }

            $orderCode = 'DST-' . strtoupper(bin2hex(random_bytes(4)));
            $rawToken1 = bin2hex(random_bytes(24));
            $rawToken2 = $reqApprovals === 2 ? bin2hex(random_bytes(24)) : '';
            $challenge = strtoupper(substr(md5(random_bytes(16)), 0, 6));

            $ins->execute([
                $orderCode,
                $ten['tenant_slug'],
                $ten['company_name'],
                $amount,
                $ten['threshold_currency'],
                $beneficiary,
                $rut,
                $bank,
                $account,
                $ten['primary_approver_name'],
                $ten['primary_approver_email'],
                $ten['primary_approver_phone'],
                $reqApprovals === 2 ? $ten['secondary_approver_name'] : '',
                $reqApprovals === 2 ? $ten['secondary_approver_phone'] : '',
                hash('sha256', $rawToken1),
                $rawToken2 !== '' ? hash('sha256', $rawToken2) : '',
                $challenge,
                $wlEval['status'],
                $wlEval['detail'],
                $reqApprovals,
                $expiresAt,
            ]);

            $createdCount++;
            $totalAmount += $amount;
            if (count($summaryLines1) < 8) {
                $flag = $wlEval['status'] === 'ACCOUNT_MISMATCH' ? '[ALERTA CTA] ' : ($wlEval['status'] === 'VERIFIED' ? '[OK] ' : '[NUEVO] ');
                $amtFmt = number_format($amount, 0, ',', '.');
                $summaryLines1[] = "• {$flag}{$beneficiary} ({$ten['threshold_currency']} \${$amtFmt}) -> {$proto}://{$host}/shield/verify/?token={$rawToken1}";
                if ($rawToken2 !== '') {
                    $summaryLines2[] = "• {$flag}{$beneficiary} ({$ten['threshold_currency']} \${$amtFmt}) -> {$proto}://{$host}/shield/verify/?token={$rawToken2}";
                }
            }
        }

        if ($createdCount === 0) {
            AdminCore::jsonError('Ninguna fila del CSV contenía monto, beneficiario, banco y cuenta válidos.', 422);
        }

        $totFmt = number_format($totalAmount, 0, ',', '.');
        if (!empty($ten['primary_approver_phone'])) {
            $batchMsg1 = "*NOMINA DEEPSHIELD TREASURY ({$createdCount} PAGOS)*\n"
                . "Empresa: *{$ten['company_name']}*\n"
                . "Total Lote: *{$ten['threshold_currency']} \${$totFmt}*\n"
                . "Padrón: {$verifiedCount} verificados · *{$mismatchCount} alertas de discrepancia*\n\n"
                . implode("\n", $summaryLines1);
            @AdminCore::sendWhatsAppAlert($ten['primary_approver_phone'], $batchMsg1, null, $ten['whatsapp_instance'] ?? null);
        }
        if (!empty($summaryLines2) && !empty($ten['secondary_approver_phone'])) {
            $batchMsg2 = "*NOMINA MANCOMUNADA 2DA FIRMA (" . count($summaryLines2) . " PAGOS)*\n"
                . "Empresa: *{$ten['company_name']}*\n\n"
                . implode("\n", $summaryLines2);
            @AdminCore::sendWhatsAppAlert($ten['secondary_approver_phone'], $batchMsg2, null, $ten['whatsapp_instance'] ?? null);
        }

        AdminCore::jsonOk([
            'created_count' => $createdCount,
            'verified_count' => $verifiedCount,
            'mismatch_count' => $mismatchCount,
            'message' => "Nómina procesada: {$createdCount} órdenes creadas ({$verifiedCount} verificadas en Padrón, {$mismatchCount} con alerta de discrepancia).",
        ]);
    }

    if ($action === 'add_whitelist') {
        $sName = trim((string)($body['supplier_name'] ?? ''));
        $sRut = trim((string)($body['supplier_rut'] ?? ''));
        $sBank = trim((string)($body['bank_name'] ?? ''));
        $sAcc = trim((string)($body['account_number'] ?? ''));
        if ($sName === '' || $sRut === '' || $sAcc === '') {
            AdminCore::jsonError('Complete razón social, RUT y cuenta.', 422);
        }
        $ins = $db->prepare("INSERT INTO deepshield_whitelist (tenant_slug, supplier_name, supplier_rut, bank_name, account_number, verified_by) VALUES (?, ?, ?, ?, ?, ?)");
        $ins->execute([$ten['tenant_slug'], $sName, $sRut, $sBank, $sAcc, $ten['company_name']]);
        AdminCore::jsonOk(['message' => 'Proveedor incorporado al padrón verificado.']);
    }

    // Importación Masiva CSV de Proveedores al Padrón Verificado
    if ($action === 'batch_add_whitelist') {
        $rows = $body['rows'] ?? [];
        if (!is_array($rows) || count($rows) === 0) {
            AdminCore::jsonError('El archivo CSV de proveedores está vacío.', 422);
        }

        $existingStmt = $db->prepare("SELECT supplier_rut, account_number FROM deepshield_whitelist WHERE tenant_slug = ?");
        $existingStmt->execute([$ten['tenant_slug']]);
        $existingSet = [];
        foreach ($existingStmt->fetchAll() as $ex) {
            $k = strtoupper(preg_replace('/[^0-9kK]/', '', (string)$ex['supplier_rut'])) . '|' . strtolower(preg_replace('/[^a-zA-Z0-9]/', '', (string)$ex['account_number']));
            $existingSet[$k] = true;
        }

        $ins = $db->prepare("INSERT INTO deepshield_whitelist (tenant_slug, supplier_name, supplier_rut, bank_name, account_number, verified_by) VALUES (?, ?, ?, ?, ?, ?)");
        $added = 0;
        $skipped = 0;
        foreach (array_slice($rows, 0, 500) as $r) {
            $sName = trim((string)($r['supplier_name'] ?? ''));
            $sRut = trim((string)($r['supplier_rut'] ?? ''));
            $sBank = trim((string)($r['bank_name'] ?? ''));
            $sAcc = trim((string)($r['account_number'] ?? ''));
            if ($sName === '' || $sRut === '' || $sAcc === '') {
                continue;
            }
            $k = strtoupper(preg_replace('/[^0-9kK]/', '', $sRut)) . '|' . strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $sAcc));
            if (isset($existingSet[$k])) {
                $skipped++;
                continue;
            }
            $ins->execute([$ten['tenant_slug'], $sName, $sRut, $sBank ?: 'Banco Comercial', $sAcc, $ten['company_name'] . ' (CSV)']);
            $existingSet[$k] = true;
            $added++;
        }

        AdminCore::jsonOk([
            'added' => $added,
            'skipped' => $skipped,
            'message' => "Padrón actualizado: {$added} cuentas certificadas importadas" . ($skipped > 0 ? " ({$skipped} duplicadas omitidas)." : "."),
        ]);
    }

    if ($action === 'delete_whitelist') {
        $wlId = (int)($body['id'] ?? 0);
        $db->prepare("DELETE FROM deepshield_whitelist WHERE id = ? AND tenant_slug = ?")->execute([$wlId, $ten['tenant_slug']]);
        AdminCore::jsonOk(['message' => 'Cuenta retirada del padrón verificado.']);
    }

    // Autoatención WhatsApp QR Propio del Cliente en /shield/portal/
    if ($action === 'whatsapp_enable_dedicated') {
        $cleanSlug = preg_replace('/[^a-zA-Z0-9_-]/', '', $ten['tenant_slug']);
        $dedicatedInst = 'dst_' . $cleanSlug;
        $db->prepare("UPDATE deepshield_tenants SET whatsapp_instance = ? WHERE id = ?")->execute([$dedicatedInst, (int)$ten['id']]);
        $_SESSION['ks_shield_tenant']['whatsapp_instance'] = $dedicatedInst;

        $qrRes = AdminCore::getOrConnectWhatsAppInstance($dedicatedInst);
        if (empty($qrRes['success'])) {
            AdminCore::jsonError($qrRes['error'] ?? 'No fue posible inicializar la instancia dedicada.', 422);
        }
        AdminCore::jsonOk([
            'mode' => 'dedicated',
            'whatsapp_instance' => $dedicatedInst,
            'connected' => !empty($qrRes['connected']),
            'state' => $qrRes['state'] ?? 'connecting',
            'qr_base64' => $qrRes['qr_base64'] ?? null,
            'message' => $qrRes['message'] ?? '',
        ]);
    }

    if ($action === 'whatsapp_check_qr') {
        $inst = trim((string)($ten['whatsapp_instance'] ?? 'keystone-general'));
        if ($inst === '' || $inst === 'keystone-general') {
            AdminCore::jsonOk([
                'mode' => 'shared',
                'whatsapp_instance' => 'keystone-general',
                'connected' => true,
                'state' => 'open',
                'qr_base64' => null,
                'message' => 'Operando mediante el canal oficial certificado de Keystone Advisors (keystone-general).',
            ]);
        }
        $qrRes = AdminCore::getOrConnectWhatsAppInstance($inst);
        if (empty($qrRes['success'])) {
            AdminCore::jsonError($qrRes['error'] ?? 'Error verificando estado WhatsApp.', 422);
        }
        AdminCore::jsonOk([
            'mode' => 'dedicated',
            'whatsapp_instance' => $inst,
            'connected' => !empty($qrRes['connected']),
            'state' => $qrRes['state'] ?? 'connecting',
            'qr_base64' => $qrRes['qr_base64'] ?? null,
            'message' => $qrRes['message'] ?? '',
        ]);
    }

    if ($action === 'whatsapp_use_shared') {
        $prevInst = trim((string)($ten['whatsapp_instance'] ?? ''));
        if ($prevInst !== '' && $prevInst !== 'keystone-general') {
            @AdminCore::logoutWhatsAppInstance($prevInst);
        }
        $db->prepare("UPDATE deepshield_tenants SET whatsapp_instance = 'keystone-general' WHERE id = ?")->execute([(int)$ten['id']]);
        $_SESSION['ks_shield_tenant']['whatsapp_instance'] = 'keystone-general';
        AdminCore::jsonOk([
            'mode' => 'shared',
            'whatsapp_instance' => 'keystone-general',
            'connected' => true,
            'message' => 'Su empresa volvió al canal oficial compartido de Keystone Advisors (keystone-general).',
        ]);
    }

    AdminCore::jsonError('Acción desconocida.', 400);
}
