<?php
/**
 * Keystone DeepShield Treasury — API REST / Webhook Machine-to-Machine para ERPs Corporativos (SOL-0342)
 * Permite que SAP, NetSuite, Softland, Defontana o Microsoft Dynamics disparen órdenes OOB
 * y consulten el sello criptográfico SHA-256 antes de liberar archivos bancarios TEF / Host-to-Host.
 *
 * Autenticación obligatoria por cabecera:
 *   X-DeepShield-Key: dst_<32_hex_chars>
 *   (o Authorization: Bearer dst_<32_hex_chars>)
 */
require_once dirname(__DIR__) . '/admin/_core.php';

AdminCore::sendHeaders();
$db = AdminCore::db();

// Extraer API Key del Tenant desde cabeceras HTTP
$providedKey = trim((string)($_SERVER['HTTP_X_DEEPSHIELD_KEY'] ?? ''));
if ($providedKey === '' && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
    if (preg_match('/^Bearer\s+(dst_[a-zA-Z0-9_-]+)$/i', trim((string)$_SERVER['HTTP_AUTHORIZATION']), $m)) {
        $providedKey = $m[1];
    }
}

if ($providedKey === '') {
    AdminCore::jsonError('Cabecera X-DeepShield-Key requerida para integración ERP.', 401);
}

$tStmt = $db->prepare("SELECT * FROM deepshield_tenants WHERE client_api_key = ? LIMIT 1");
$tStmt->execute([$providedKey]);
$ten = $tStmt->fetch();

if (!$ten) {
    AdminCore::jsonError('Credencial X-DeepShield-Key inválida.', 401);
}
if (($ten['status'] ?? '') !== 'ACTIVE') {
    AdminCore::jsonError('El servicio DeepShield para esta organización se encuentra suspendido.', 403);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// 1. GET: Consultar estado y sello SHA-256 de una orden emitida por el ERP
if ($method === 'GET') {
    $orderCode = strtoupper(trim((string)($_GET['order_code'] ?? '')));
    if ($orderCode === '') {
        // Retornar las últimas 20 órdenes del tenant para conciliación ERP
        $lStmt = $db->prepare("
            SELECT order_code, invoice_folio, amount, currency, beneficiary_name, beneficiary_rut_dni,
                   bank_name, account_number, status, whitelist_status, required_approvals,
                   approval_count, cryptographic_signature, approved_at, created_at
            FROM deepshield_orders
            WHERE tenant_slug = ?
            ORDER BY id DESC LIMIT 20
        ");
        $lStmt->execute([$ten['tenant_slug']]);
        AdminCore::jsonOk([
            'tenant_slug' => $ten['tenant_slug'],
            'company_name' => $ten['company_name'],
            'orders' => $lStmt->fetchAll(),
        ]);
    }

    $oStmt = $db->prepare("
        SELECT order_code, invoice_folio, amount, currency, beneficiary_name, beneficiary_rut_dni,
               bank_name, account_number, status, whitelist_status, whitelist_detail,
               required_approvals, approval_count, cryptographic_signature, approved_at, expires_at, created_at
        FROM deepshield_orders
        WHERE order_code = ? AND tenant_slug = ?
        LIMIT 1
    ");
    $oStmt->execute([$orderCode, $ten['tenant_slug']]);
    $ord = $oStmt->fetch();
    if (!$ord) {
        AdminCore::jsonError("Orden {$orderCode} no encontrada para este tenant.", 404);
    }

    AdminCore::jsonOk([
        'authorized_for_bank_release' => ($ord['status'] === 'APPROVED'),
        'order' => $ord,
    ]);
}

// 2. POST: Emitir nueva orden OOB directamente desde contabilización ERP
if ($method === 'POST') {
    $body = AdminCore::readJsonBody();
    $amount = (float)($body['amount'] ?? 0);
    $beneficiary = trim((string)($body['beneficiary_name'] ?? ''));
    $rut = trim((string)($body['beneficiary_rut'] ?? ($body['beneficiary_rut_dni'] ?? '')));
    $bank = trim((string)($body['bank_name'] ?? ''));
    $account = trim((string)($body['account_number'] ?? ''));
    $invoiceFolio = trim((string)($body['invoice_folio'] ?? ''));
    $requestedSigners = (int)($body['required_approvals'] ?? 0);

    if ($amount <= 0 || $beneficiary === '' || $rut === '' || $bank === '' || $account === '') {
        AdminCore::jsonError('Campos requeridos: amount, beneficiary_name, beneficiary_rut, bank_name, account_number.', 422);
    }

    $wlEval = AdminCore::evaluateWhitelistMatch($ten['tenant_slug'], $rut, $bank, $account, $ten['company_name']);

    $dualThreshold = (float)($ten['dual_approval_threshold'] ?? 0);
    $secName = trim((string)($ten['secondary_approver_name'] ?? ''));
    $autoDual = ($dualThreshold > 0 && $amount >= $dualThreshold && $secName !== '');
    $reqApprovals = ($requestedSigners === 2 || ($requestedSigners === 0 && $autoDual)) ? 2 : 1;
    if ($reqApprovals === 2 && $secName === '') {
        $reqApprovals = 1;
    }

    $orderCode = 'DST-' . strtoupper(bin2hex(random_bytes(4)));
    $rawToken1 = bin2hex(random_bytes(24));
    $rawToken2 = $reqApprovals === 2 ? bin2hex(random_bytes(24)) : '';
    $challenge = strtoupper(substr(md5(random_bytes(16)), 0, 6));
    $expiresAt = date('Y-m-d H:i:s', time() + 48 * 3600);

    $ins = $db->prepare("
        INSERT INTO deepshield_orders (
            order_code, tenant_slug, company_name, amount, currency,
            beneficiary_name, beneficiary_rut_dni, bank_name, account_number, invoice_folio,
            approver_name, approver_email, approver_phone,
            secondary_approver_name, secondary_approver_phone,
            token_hash, secondary_token_hash, status, security_challenge,
            whitelist_status, whitelist_detail, required_approvals, approval_count,
            approvals_log_json, operator_notes, expires_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING_APPROVAL', ?, ?, ?, ?, 0, '[]', 'ERP_API_WEBHOOK', ?)
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
        $invoiceFolio,
        $ten['primary_approver_name'],
        $ten['primary_approver_email'],
        $ten['primary_approver_phone'],
        $reqApprovals === 2 ? $secName : '',
        $reqApprovals === 2 ? ($ten['secondary_approver_phone'] ?? '') : '',
        hash('sha256', $rawToken1),
        $rawToken2 !== '' ? hash('sha256', $rawToken2) : '',
        $challenge,
        $wlEval['status'],
        $wlEval['detail'],
        $reqApprovals,
        $expiresAt,
    ]);

    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'dev.keystoneadvisors.cl';
    $verifyUrl1 = "{$proto}://{$host}/shield/verify/?token={$rawToken1}";
    $verifyUrl2 = $rawToken2 !== '' ? "{$proto}://{$host}/shield/verify/?token={$rawToken2}" : null;

    $wlLabel = $wlEval['status'] === 'VERIFIED'
        ? 'CUENTA CERTIFICADA EN PADRON'
        : ($wlEval['status'] === 'ACCOUNT_MISMATCH' ? 'ALERTA CRITICA: CUENTA DISTINTA AL PADRON' : 'PROVEEDOR NUEVO');
    $amtFmt = number_format($amount, 0, ',', '.');
    $folioLine = $invoiceFolio !== '' ? "Doc/Factura: `{$invoiceFolio}`\n" : '';

    if (!empty($ten['primary_approver_phone'])) {
        $msg1 = "*ERP -> DEEPSHIELD OOB*\n"
            . "Empresa: *{$ten['company_name']}*\n"
            . "Orden: `{$orderCode}`\n"
            . $folioLine
            . "Monto: *{$ten['threshold_currency']} \${$amtFmt}*\n"
            . "Beneficiario: {$beneficiary} ({$rut})\n"
            . "Padrón: *{$wlLabel}*\n"
            . "Desafío Anti-Voz: `{$challenge}`\n\n"
            . "Autorizar o Bloquear:\n{$verifyUrl1}";
        @AdminCore::sendWhatsAppAlert((string)$ten['primary_approver_phone'], $msg1, null, $ten['whatsapp_instance'] ?? null);
    }

    if ($reqApprovals === 2 && !empty($ten['secondary_approver_phone']) && $verifyUrl2) {
        $msg2 = "*ERP -> 2DA FIRMA MANCOMUNADA*\n"
            . "Empresa: *{$ten['company_name']}*\n"
            . "Orden: `{$orderCode}` ({$ten['threshold_currency']} \${$amtFmt})\n"
            . "Beneficiario: {$beneficiary}\n"
            . "Padrón: *{$wlLabel}*\n\n"
            . "Firmar (2/2):\n{$verifyUrl2}";
        @AdminCore::sendWhatsAppAlert((string)$ten['secondary_approver_phone'], $msg2, null, $ten['whatsapp_instance'] ?? null);
    }

    AdminCore::logAudit('erp_api:' . $ten['tenant_slug'], 'ERP_ORDER_EMIT', $orderCode, "{$beneficiary} · {$ten['threshold_currency']} {$amount}");
    AdminCore::jsonOk([
        'order_code' => $orderCode,
        'invoice_folio' => $invoiceFolio,
        'status' => 'PENDING_APPROVAL',
        'required_approvals' => $reqApprovals,
        'whitelist_status' => $wlEval['status'],
        'whitelist_detail' => $wlEval['detail'],
        'security_challenge' => $challenge,
        'expires_at' => $expiresAt,
    ]);
}

AdminCore::jsonError('Método HTTP no soportado.', 405);
