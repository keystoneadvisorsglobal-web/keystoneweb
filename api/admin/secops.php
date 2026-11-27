<?php
/**
 * Keystone Command Console v4.1 — Suite Integral SecOps:
 * 1) Escáner DNS/BEC en Vivo + Watchdog de Dominios
 * 2) Telemetría TrustZero
 * 3) Órdenes DeepShield Treasury OOB (Anti-Fraude & Anti-Voice Cloning + Alerta WhatsApp)
 * 4) Ingesta Forense RUA DMARC (.xml / .gz / .zip RFC 7489)
 * 5) Simulador IA & Campañas Corporativas Red Teaming
 */
require_once __DIR__ . '/_core.php';
require_once dirname(__DIR__) . '/trust/TrustZeroTelemetryService.php';

AdminCore::sendHeaders();
$currentUser = AdminCore::requireAuth(['superadmin', 'secops', 'comercial']);
$db = AdminCore::db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    if (($_GET['action'] ?? '') === 'download_vault_asset') {
        if (!in_array($currentUser['role'] ?? '', ['superadmin', 'secops'], true)) {
            http_response_code(403);
            die('Permiso insuficiente.');
        }
        $aid = (int)($_GET['id'] ?? 0);
        $aStmt = $db->prepare("SELECT * FROM redteam_assets WHERE id = ? LIMIT 1");
        $aStmt->execute([$aid]);
        $aRow = $aStmt->fetch();
        if (!$aRow) {
            http_response_code(404);
            die('Recurso no encontrado en bóveda.');
        }
        $filePath = AdminCore::getDataDir() . '/rt_assets/' . basename((string)$aRow['stored_filename']);
        if (!is_file($filePath)) {
            http_response_code(404);
            die('Archivo físico no encontrado.');
        }
        $ext = strtolower(pathinfo((string)$aRow['asset_name'], PATHINFO_EXTENSION));
        $mimeMap = [
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'txt' => 'text/plain; charset=utf-8',
            'csv' => 'text/csv; charset=utf-8',
            'html' => 'text/plain; charset=utf-8',
            'htm' => 'text/plain; charset=utf-8',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];
        $mime = $mimeMap[$ext] ?? 'application/octet-stream';
        $disp = in_array($ext, ['pdf', 'png', 'jpg', 'jpeg', 'txt'], true) ? 'inline' : 'attachment';
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', (string)$aRow['asset_name']);
        header('Content-Type: ' . $mime);
        header('Content-Disposition: ' . $disp . '; filename="' . $safeName . '"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }

    if (($_GET['action'] ?? '') === 'read_vault_text') {
        if (!in_array($currentUser['role'] ?? '', ['superadmin', 'secops'], true)) {
            AdminCore::jsonError('Permiso insuficiente.', 403);
        }
        $aid = (int)($_GET['id'] ?? 0);
        $aStmt = $db->prepare("SELECT * FROM redteam_assets WHERE id = ? LIMIT 1");
        $aStmt->execute([$aid]);
        $aRow = $aStmt->fetch();
        if (!$aRow) {
            AdminCore::jsonError('Recurso no encontrado.', 404);
        }
        $filePath = AdminCore::getDataDir() . '/rt_assets/' . basename((string)$aRow['stored_filename']);
        if (!is_file($filePath)) {
            AdminCore::jsonError('Archivo no disponible.', 404);
        }
        AdminCore::jsonOk([
            'asset_name' => $aRow['asset_name'],
            'asset_type' => $aRow['asset_type'],
            'content' => (string)file_get_contents($filePath),
        ]);
    }

    if (($_GET['action'] ?? '') === 'redteam_detail') {
        $cid = (int)($_GET['campaign_id'] ?? 0);
        $tStmt = $db->prepare("SELECT * FROM redteam_targets WHERE campaign_id = ? ORDER BY id DESC");
        $tStmt->execute([$cid]);
        $aStmt = $db->prepare("SELECT id, campaign_id, client_label, asset_name, asset_type, file_size, uploaded_by, created_at FROM redteam_assets WHERE campaign_id = ? ORDER BY id DESC");
        $aStmt->execute([$cid]);
        $tpls = $db->query("SELECT * FROM redteam_templates ORDER BY id DESC")->fetchAll();
        AdminCore::jsonOk([
            'targets' => $tStmt->fetchAll(),
            'assets' => $aStmt->fetchAll(),
            'templates' => $tpls,
        ]);
    }

    $watched = $db->query("SELECT * FROM watched_domains ORDER BY id ASC")->fetchAll();
    $trustPosture = TrustZeroTelemetryService::getSecurityPosture('keystoneadvisors.cl');
    $treasuryOrders = $db->query("SELECT * FROM deepshield_orders ORDER BY id DESC LIMIT 50")->fetchAll();
    $dmarcReports = $db->query("SELECT * FROM dmarc_reports ORDER BY id DESC LIMIT 50")->fetchAll();
    $redteamCampaigns = $db->query("
        SELECT c.*,
               (SELECT COUNT(*) FROM redteam_targets t WHERE t.campaign_id = c.id) AS targets_total,
               (SELECT COUNT(*) FROM redteam_targets t WHERE t.campaign_id = c.id AND t.status != 'PENDING') AS targets_sent,
               (SELECT COUNT(*) FROM redteam_targets t WHERE t.campaign_id = c.id AND (t.opened_at IS NOT NULL OR t.status IN ('OPENED','CLICKED','REPORTED'))) AS targets_opened,
               (SELECT COUNT(*) FROM redteam_targets t WHERE t.campaign_id = c.id AND t.clicked_at IS NOT NULL) AS targets_clicked,
               (SELECT COUNT(*) FROM redteam_targets t WHERE t.campaign_id = c.id AND t.status = 'REPORTED') AS targets_reported,
               (SELECT COUNT(*) FROM redteam_assets a WHERE a.campaign_id = c.id) AS assets_count
        FROM redteam_campaigns c
        ORDER BY c.id DESC LIMIT 50
    ")->fetchAll();
    $redteamTemplates = $db->query("SELECT * FROM redteam_templates ORDER BY id DESC")->fetchAll();
    $vaultAssets = $db->query("
        SELECT a.id, a.campaign_id, a.client_label, a.asset_name, a.asset_type, a.file_size, a.uploaded_by, a.created_at,
               COALESCE(c.company_name, '') AS campaign_company
        FROM redteam_assets a
        LEFT JOIN redteam_campaigns c ON c.id = a.campaign_id
        ORDER BY a.id DESC LIMIT 100
    ")->fetchAll();
    $tenants = $db->query("SELECT id, tenant_slug, company_name, rut_dni, threshold_amount, threshold_currency, primary_approver_name, primary_approver_email, primary_approver_phone, secondary_approver_name, secondary_approver_email, secondary_approver_phone, dual_approval_threshold, treasury_operator_name, treasury_operator_email, whatsapp_instance, client_api_key, status, created_at FROM deepshield_tenants ORDER BY id DESC")->fetchAll();
    if (!in_array($currentUser['role'] ?? '', ['superadmin', 'secops'], true)) {
        foreach ($tenants as &$tItem) {
            $rawKey = (string)($tItem['client_api_key'] ?? '');
            $tItem['client_api_key'] = $rawKey !== '' ? ('dst_••••••••••••' . substr($rawKey, -4)) : '';
        }
        unset($tItem);
    }
    $lastCron = AdminCore::getSetting('last_cron_run') ?: null;

    AdminCore::jsonOk([
        'watched_domains' => $watched,
        'trustzero' => $trustPosture,
        'treasury_orders' => $treasuryOrders,
        'dmarc_reports' => $dmarcReports,
        'redteam_campaigns' => $redteamCampaigns,
        'redteam_templates' => $redteamTemplates,
        'vault_assets' => $vaultAssets,
        'tenants' => $tenants,
        'last_cron_run' => $lastCron,
    ]);
}

if ($method === 'POST') {
    // Soporte multipart/form-data para Bóveda Central e Insumos por Campaña (SOL-0193 / SOL-0342 / SOL-0346)
    if (!empty($_POST['action']) && $_POST['action'] === 'upload_redteam_asset') {
        if (!in_array($currentUser['role'] ?? '', ['superadmin', 'secops'], true)) {
            AdminCore::jsonError('Se requiere rol SecOps o Superadmin.', 403);
        }
        $cid = max(0, (int)($_POST['campaign_id'] ?? 0));
        $clientLabel = trim((string)($_POST['client_label'] ?? 'General')) ?: 'General';
        if (empty($_FILES['asset_file']) || $_FILES['asset_file']['error'] !== UPLOAD_ERR_OK) {
            AdminCore::jsonError('Adjunte un archivo válido (máx. 8 MB).', 422);
        }
        $orig = basename((string)$_FILES['asset_file']['name']);
        $size = (int)$_FILES['asset_file']['size'];
        if ($size > 8 * 1024 * 1024) {
            AdminCore::jsonError('El archivo supera el límite de 8 MB.', 422);
        }
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf', 'png', 'jpg', 'jpeg', 'txt', 'csv', 'docx', 'html', 'htm'], true)) {
            AdminCore::jsonError('Extensión no permitida. Use PDF, DOCX, HTML, TXT, CSV o imagen.', 422);
        }

        $vaultDir = AdminCore::getDataDir() . '/rt_assets';
        if (!is_dir($vaultDir)) {
            @mkdir($vaultDir, 0750, true);
        }
        $stored = 'rt_' . $cid . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (!move_uploaded_file($_FILES['asset_file']['tmp_name'], $vaultDir . '/' . $stored)) {
            AdminCore::jsonError('No fue posible almacenar el insumo en la bóveda aislada.', 500);
        }

        $insA = $db->prepare("INSERT INTO redteam_assets (campaign_id, client_label, asset_name, asset_type, stored_filename, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $insA->execute([$cid, $clientLabel, $orig, strtoupper($ext), $stored, $size, $currentUser['username']]);
        AdminCore::logAudit($currentUser['username'], 'REDTEAM_ASSET_UPLOAD', (string)$cid, "{$clientLabel}: {$orig}");
        AdminCore::jsonOk(['message' => "Recurso '{$orig}' almacenado en la Bóveda."]);
    }

    // Soporte multipart/form-data para ingesta de archivos DMARC RUA (.xml, .gz, .zip)
    if (!empty($_POST['action']) && $_POST['action'] === 'ingest_dmarc_rua') {
        if (!in_array($currentUser['role'] ?? '', ['superadmin', 'secops'], true)) {
            AdminCore::jsonError('Se requiere rol SecOps o Superadmin para ingestar reportes RUA.', 403);
        }
        if (empty($_FILES['rua_file']) || $_FILES['rua_file']['error'] !== UPLOAD_ERR_OK) {
            AdminCore::jsonError('Adjunte un archivo DMARC RUA válido (.xml, .xml.gz o .zip).', 422);
        }

        $tmpPath = $_FILES['rua_file']['tmp_name'];
        $origName = strtolower(basename((string)$_FILES['rua_file']['name']));
        $xmlString = '';

        if (substr($origName, -3) === '.gz' && function_exists('gzopen')) {
            $gz = @gzopen($tmpPath, 'rb');
            if ($gz) {
                while (!gzeof($gz)) {
                    $xmlString .= gzread($gz, 8192);
                }
                gzclose($gz);
            }
        } elseif (substr($origName, -4) === '.zip' && class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($tmpPath) === true) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $en = $zip->getNameIndex($i);
                    if (substr(strtolower((string)$en), -4) === '.xml') {
                        $xmlString = (string)$zip->getFromIndex($i);
                        break;
                    }
                }
                $zip->close();
            }
        } else {
            $xmlString = (string)file_get_contents($tmpPath);
        }

        if (stripos($xmlString, '<feedback') === false) {
            AdminCore::jsonError('El archivo no contiene una estructura XML DMARC RFC 7489 (<feedback>).', 422);
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlString, 'SimpleXMLElement', LIBXML_NOCDATA);
        if (!$xml || !isset($xml->report_metadata) || !isset($xml->policy_published)) {
            AdminCore::jsonError('XML DMARC inválido o incompleto.', 422);
        }

        $reportId = (string)($xml->report_metadata->report_id ?? uniqid('rua_'));
        $orgName = (string)($xml->report_metadata->org_name ?? 'ISP Reporter');
        $email = (string)($xml->report_metadata->email ?? '');
        $domain = strtolower(trim((string)($xml->policy_published->domain ?? '')));
        $policyP = strtolower(trim((string)($xml->policy_published->p ?? 'none')));
        $policyPct = (int)($xml->policy_published->pct ?? 100);
        $dBegin = isset($xml->report_metadata->date_range->begin)
            ? date('Y-m-d H:i', (int)$xml->report_metadata->date_range->begin)
            : date('Y-m-d H:i');
        $dEnd = isset($xml->report_metadata->date_range->end)
            ? date('Y-m-d H:i', (int)$xml->report_metadata->date_range->end)
            : date('Y-m-d H:i');

        $totalMsgs = 0;
        $totalPass = 0;
        $totalFail = 0;
        $spoofIps = 0;

        foreach ($xml->record as $rec) {
            $cnt = (int)($rec->row->count ?? 1);
            $dkim = strtolower((string)($rec->row->policy_evaluated->dkim ?? 'fail'));
            $spf = strtolower((string)($rec->row->policy_evaluated->spf ?? 'fail'));
            $totalMsgs += $cnt;
            if ($dkim === 'pass' || $spf === 'pass') {
                $totalPass += $cnt;
            } else {
                $totalFail += $cnt;
                $spoofIps++;
            }
        }

        $insR = $db->prepare("
            INSERT INTO dmarc_reports (
                report_id, org_name, email, domain, policy_p, policy_pct,
                date_begin, date_end, total_messages, total_pass, total_fail, spoofing_ips, raw_filename
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(report_id) DO UPDATE SET
                total_messages = excluded.total_messages,
                total_pass = excluded.total_pass,
                total_fail = excluded.total_fail,
                spoofing_ips = excluded.spoofing_ips
        ");
        $insR->execute([
            $reportId, $orgName, $email, $domain, $policyP, $policyPct,
            $dBegin, $dEnd, $totalMsgs, $totalPass, $totalFail, $spoofIps, $origName,
        ]);

        AdminCore::logAudit($currentUser['username'], 'DMARC_RUA_INGEST', $domain, "Reporte {$orgName} ({$totalMsgs} msgs, {$totalFail} fallos)");
        AdminCore::jsonOk([
            'message' => "Reporte RUA de {$orgName} procesado: {$totalMsgs} mensajes ({$totalPass} autenticados / {$totalFail} bloqueados o fallidos).",
        ]);
    }

    // Rutas JSON estándar
    $body = AdminCore::readJsonBody();
    $action = trim((string)($body['action'] ?? ''));

    // 1. Escaneo BEC en vivo
    if ($action === 'scan_domain') {
        $rawDomain = (string)($body['domain'] ?? '');
        try {
            $res = AdminCore::runPassiveDomainCheck($rawDomain);
        } catch (InvalidArgumentException $e) {
            AdminCore::jsonError($e->getMessage(), 422);
        }

        $scanRef = 'BEC-' . strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 7));
        $ins = $db->prepare("
            INSERT INTO bec_scans (scan_ref, domain, email, score, risk_level, dmarc_label, spf_status, mx_status, tls_status, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([
            $scanRef, $res['domain'], 'secops@keystoneadvisors.cl',
            $res['score'], $res['risk_level'], $res['dmarc'], $res['spf'],
            $res['mx'], $res['https_hsts'], $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
        ]);

        $updW = $db->prepare("
            UPDATE watched_domains
            SET last_score = ?, dmarc_policy = ?, spf_status = ?, tls_status = ?, last_checked_at = CURRENT_TIMESTAMP
            WHERE LOWER(domain) = LOWER(?)
        ");
        $updW->execute([$res['score'], $res['dmarc'], $res['spf'], $res['https_hsts'], $res['domain']]);

        AdminCore::logAudit($currentUser['username'], 'SECOPS_BEC_SCAN', $res['domain'], "Score {$res['score']}/100 ({$res['dmarc']})");
        AdminCore::jsonOk(['scan_ref' => $scanRef, 'result' => $res]);
    }

    // 2. Watchdog Dominios
    if ($action === 'add_watched_domain') {
        if (!in_array($currentUser['role'] ?? '', ['superadmin', 'secops'], true)) {
            AdminCore::jsonError('Se requiere perfil SecOps o Superadmin.', 403);
        }
        $clientName = trim((string)($body['client_name'] ?? ''));
        $tier = trim((string)($body['tier'] ?? 'Enterprise'));
        $rawDomain = (string)($body['domain'] ?? '');
        if ($clientName === '') {
            AdminCore::jsonError('Indique el cliente.', 422);
        }
        try {
            $res = AdminCore::runPassiveDomainCheck($rawDomain);
        } catch (InvalidArgumentException $e) {
            AdminCore::jsonError($e->getMessage(), 422);
        }

        $ins = $db->prepare("
            INSERT INTO watched_domains (domain, client_name, tier, last_score, dmarc_policy, spf_status, tls_status, last_checked_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT(domain) DO UPDATE SET
                client_name = excluded.client_name,
                tier = excluded.tier,
                last_score = excluded.last_score,
                dmarc_policy = excluded.dmarc_policy,
                spf_status = excluded.spf_status,
                tls_status = excluded.tls_status,
                last_checked_at = CURRENT_TIMESTAMP
        ");
        $ins->execute([$res['domain'], $clientName, $tier, $res['score'], $res['dmarc'], $res['spf'], $res['https_hsts']]);
        AdminCore::logAudit($currentUser['username'], 'WATCHDOG_ADD', $res['domain'], "Cliente: {$clientName}");
        AdminCore::jsonOk(['message' => 'Dominio incorporado a vigilancia continua.', 'scan' => $res]);
    }

    if ($action === 'remove_watched_domain') {
        if (!in_array($currentUser['role'] ?? '', ['superadmin', 'secops'], true)) {
            AdminCore::jsonError('Permiso insuficiente.', 403);
        }
        $id = (int)($body['id'] ?? 0);
        $db->prepare("DELETE FROM watched_domains WHERE id = ?")->execute([$id]);
        AdminCore::logAudit($currentUser['username'], 'WATCHDOG_REMOVE', (string)$id, 'Dominio retirado');
        AdminCore::jsonOk(['message' => 'Dominio retirado.']);
    }

    if ($action === 'update_watched_tier') {
        if (!in_array($currentUser['role'] ?? '', ['superadmin', 'secops'], true)) {
            AdminCore::jsonError('Permiso insuficiente para cambiar el plan.', 403);
        }
        $id = (int)($body['id'] ?? 0);
        $tier = trim((string)($body['tier'] ?? 'Enterprise'));
        if (!in_array($tier, ['Starter', 'Pro', 'Enterprise', 'Internal Core'], true)) {
            AdminCore::jsonError('Plan no válido.', 422);
        }
        $upd = $db->prepare("UPDATE watched_domains SET tier = ? WHERE id = ?");
        $upd->execute([$tier, $id]);
        AdminCore::logAudit($currentUser['username'], 'WATCHDOG_TIER_UPDATE', (string)$id, "Nuevo plan: {$tier}");
        AdminCore::jsonOk(['message' => "Plan actualizado a {$tier} exitosamente."]);
    }

    // 3. Crear Orden Criptográfica DeepShield Treasury OOB + Despacho WhatsApp al Firmante
    if ($action === 'create_treasury_order') {
        if (!in_array($currentUser['role'] ?? '', ['superadmin', 'secops'], true)) {
            AdminCore::jsonError('Solo SecOps o Superadmin puede emitir órdenes Treasury OOB.', 403);
        }

        $company = trim((string)($body['company_name'] ?? ''));
        $amount = (float)($body['amount'] ?? 0);
        $currency = strtoupper(trim((string)($body['currency'] ?? 'CLP')));
        $beneficiary = trim((string)($body['beneficiary_name'] ?? ''));
        $rut = trim((string)($body['beneficiary_rut_dni'] ?? ''));
        $bank = trim((string)($body['bank_name'] ?? ''));
        $account = trim((string)($body['account_number'] ?? ''));
        $approverName = trim((string)($body['approver_name'] ?? 'Director Financiero'));
        $approverEmail = trim((string)($body['approver_email'] ?? ''));
        $approverPhone = preg_replace('/[^0-9]/', '', (string)($body['approver_phone'] ?? ''));

        if ($company === '' || $amount <= 0 || $beneficiary === '' || $bank === '' || $account === '') {
            AdminCore::jsonError('Complete empresa, monto, beneficiario, banco y cuenta.', 422);
        }

        $orderCode = 'DST-' . strtoupper(bin2hex(random_bytes(4)));
        $rawToken = bin2hex(random_bytes(24));
        $tokenHash = hash('sha256', $rawToken);
        $challenge = strtoupper(substr(md5(random_bytes(16)), 0, 6));
        $expiresAt = date('Y-m-d H:i:s', time() + 48 * 3600);

        $wlEval = AdminCore::evaluateWhitelistMatch('', $rut, $bank, $account, $company);

        $insO = $db->prepare("
            INSERT INTO deepshield_orders (
                order_code, company_name, amount, currency,
                beneficiary_name, beneficiary_rut_dni, bank_name, account_number,
                approver_name, approver_email, approver_phone,
                token_hash, status, security_challenge, whitelist_status, whitelist_detail,
                required_approvals, approval_count, assigned_to, expires_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING_APPROVAL', ?, ?, ?, 1, 0, ?, ?)
        ");
        $insO->execute([
            $orderCode, $company, $amount, $currency,
            $beneficiary, $rut, $bank, $account,
            $approverName, $approverEmail, $approverPhone,
            $tokenHash, $challenge, $wlEval['status'], $wlEval['detail'],
            $currentUser['username'], $expiresAt,
        ]);

        // Si indicó celular del firmante, despachar desafío Anti-Voice-Cloning por WhatsApp OOB
        $waSent = false;
        if (strlen($approverPhone) >= 8) {
            $amtFmt = number_format($amount, 0, ',', '.');
            $waText = "*ALERTA KEYSTONE DEEPSHIELD TREASURY*\n"
                . "Orden: `{$orderCode}`\n"
                . "Empresa: *{$company}*\n"
                . "Monto: *{$currency} \${$amtFmt}*\n"
                . "Beneficiario: {$beneficiary} ({$rut})\n"
                . "Banco/Cuenta: {$bank} · {$account}\n\n"
                . "*CLAVE ANTI-VOZ IA:* `{$challenge}`\n"
                . "Exija esta palabra clave antes de liberar fondos.";
            $waRes = AdminCore::sendWhatsAppAlert($approverPhone, $waText);
            $waSent = !empty($waRes['success']);
        }

        AdminCore::logAudit($currentUser['username'], 'TREASURY_CREATE', $orderCode, "{$company} · {$currency} {$amount}");
        AdminCore::jsonOk([
            'order_code' => $orderCode,
            'security_challenge' => $challenge,
            'whatsapp_dispatched' => $waSent,
            'message' => "Orden {$orderCode} emitida con desafío anti-clonación {$challenge}.",
        ]);
    }

    if ($action === 'resolve_treasury_order') {
        if (!in_array($currentUser['role'] ?? '', ['superadmin', 'secops'], true)) {
            AdminCore::jsonError('Permiso insuficiente.', 403);
        }
        $orderId = (int)($body['id'] ?? 0);
        $decision = ($body['decision'] ?? '') === 'APPROVE' ? 'APPROVED' : 'REJECTED';
        $sig = hash('sha256', $orderId . '|' . $decision . '|' . $currentUser['username'] . '|' . microtime(true));

        $upd = $db->prepare("
            UPDATE deepshield_orders
            SET status = ?, approved_at = CURRENT_TIMESTAMP, cryptographic_signature = ?, approved_by_ip = ?
            WHERE id = ?
        ");
        $upd->execute([$decision, $sig, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', $orderId]);
        AdminCore::logAudit($currentUser['username'], 'TREASURY_' . $decision, (string)$orderId, "Firma SHA-256: " . substr($sig, 0, 16));
        AdminCore::jsonOk(['message' => "Orden marcada como {$decision}."]);
    }

    // 4. Red Teaming IA: Generación de Señuelo Forense & Creación de Campaña Corporativa
    if ($action === 'create_redteam_campaign') {
        $company = trim((string)($body['company_name'] ?? ''));
        $contactName = trim((string)($body['contact_name'] ?? 'CISO / Gerencia TI'));
        $contactEmail = trim((string)($body['contact_email'] ?? ''));
        $targetDomains = trim((string)($body['target_domains'] ?? ''));
        $usersCount = max(1, (int)($body['target_users_count'] ?? 25));
        $vector = trim((string)($body['vector'] ?? 'BEC_CEO_FRAUD'));
        $planType = in_array($body['plan_type'] ?? '', ['STARTER', 'PROFESSIONAL', 'ENTERPRISE'], true)
            ? $body['plan_type']
            : 'PROFESSIONAL';

        if ($company === '' || $targetDomains === '') {
            AdminCore::jsonError('Indique empresa objetivo y dominio(s) alcance.', 422);
        }

        // Plantillas corporativas neutrales de concientización autorizada (cero suplantación de marcas externas / cero captura de credenciales)
        if ($vector === 'SUPPLIER_ACCOUNT_CHANGE') {
            $subject = "[Revisión Tesorería] Solicitud de Actualización de Cuenta Proveedor — {$company}";
            $lureBody = "Estimado equipo de Administración y Finanzas de {$company},\n\nAdjuntamos solicitud de modificación de cuenta receptora para la liquidación de facturas del período actual. Antes de cursar el pago, valide el cumplimiento del protocolo de padrón verificado y confirmación fuera de banda.";
        } elseif ($vector === 'VISHING_DEEPFAKE_CALLBACK') {
            $subject = "[Prioridad Comité] Validación Telefónica de Instrucción Extraordinaria — {$company}";
            $lureBody = "Requiero revisar una instrucción reservada previo al cierre de tesorería de las 14:00 hrs. Recibirás contacto telefónico directo para confirmar disponibilidad; recuerda aplicar el protocolo de verificación de desafío Anti-Voz vigente.";
        } elseif ($vector === 'CORPORATE_BILLING_DOC_DRILL') {
            $subject = "[Aviso Facturación] Emisión de Documento Tributario Pendiente de Visación — {$company}";
            $lureBody = "Se ha registrado un nuevo documento electrónico de cobro pendiente de conformidad técnica para {$company}. Ingrese al portal interno de revisión documental para validar folio, monto y orden de compra asociada.";
        } elseif ($vector === 'PAYROLL_POLICY_DRILL') {
            $subject = "[Recursos Humanos & Finanzas] Actualización de Procedimiento de Nómina — {$company}";
            $lureBody = "Informamos la actualización semestral del protocolo de resguardo de cuentas para abonos corporativos de {$company}. Revise el instructivo adjunto para confirmar recepción.";
        } else {
            $subject = "[Reservado Dirección] Solicitud de Gestión Financiera Prioritaria — {$company}";
            $lureBody = "Me encuentro en sesión de directorio con conectividad limitada. Necesito validar hoy la factibilidad de anticipo para asesores externos de {$company}. Revisa el detalle en el enlace interno de coordinación.";
        }

        $insC = $db->prepare("
            INSERT INTO redteam_campaigns (
                company_name, contact_name, contact_email, plan_type,
                target_domains, target_users_count, simulated_vectors,
                status, last_lure_subject, last_lure_body
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'ACTIVE', ?, ?)
        ");
        $insC->execute([
            $company, $contactName, $contactEmail, $planType,
            $targetDomains, $usersCount, $vector,
            $subject, $lureBody,
        ]);

        AdminCore::logAudit($currentUser['username'], 'REDTEAM_CREATE', $company, "Vector: {$vector} ({$usersCount} objetivos)");
        AdminCore::jsonOk([
            'message' => "Campaña de concientización creada para {$company}.",
            'lure_preview' => ['subject' => $subject, 'body' => $lureBody],
        ]);
    }

    if ($action === 'update_redteam_status') {
        $id = (int)($body['id'] ?? 0);
        $status = in_array($body['status'] ?? '', ['PLANNING', 'ACTIVE', 'COMPLETED', 'CANCELLED'], true)
            ? $body['status']
            : 'COMPLETED';
        $db->prepare("UPDATE redteam_campaigns SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$status, $id]);
        AdminCore::logAudit($currentUser['username'], 'REDTEAM_STATUS', (string)$id, "Estado -> {$status}");
        AdminCore::jsonOk(['message' => 'Estado de campaña actualizado.']);
    }

    if ($action === 'update_redteam_campaign') {
        if (!in_array($currentUser['role'] ?? '', ['superadmin', 'secops'], true)) {
            AdminCore::jsonError('Permiso insuficiente.', 403);
        }
        $id = (int)($body['id'] ?? 0);
        $subj = trim((string)($body['last_lure_subject'] ?? ''));
        $lBody = trim((string)($body['last_lure_body'] ?? ''));
        $ctaLabel = trim((string)($body['lure_cta_label'] ?? 'REVISAR SOLICITUD EN PORTAL INTERNO'));
        $accentColor = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($body['lure_accent_color'] ?? ''))
            ? (string)$body['lure_accent_color']
            : '#0F172A';
        $customHtml = trim((string)($body['custom_html_body'] ?? ''));
        $domains = trim((string)($body['target_domains'] ?? ''));
        if ($id <= 0 || $subj === '' || ($lBody === '' && $customHtml === '')) {
            AdminCore::jsonError('Complete asunto y contenido (texto o HTML) del ejercicio.', 422);
        }
        $db->prepare("
            UPDATE redteam_campaigns
            SET last_lure_subject = ?, last_lure_body = ?, lure_cta_label = ?, lure_accent_color = ?, custom_html_body = ?,
                target_domains = COALESCE(NULLIF(?, ''), target_domains), updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute([$subj, $lBody, $ctaLabel, $accentColor, $customHtml, $domains, $id]);
        AdminCore::logAudit($currentUser['username'], 'REDTEAM_EDIT', (string)$id, $subj);
        AdminCore::jsonOk(['message' => 'Plantilla aplicada y guardada en la campaña.']);
    }

    if ($action === 'save_redteam_template') {
        if (!in_array($currentUser['role'] ?? '', ['superadmin', 'secops'], true)) {
            AdminCore::jsonError('Permiso insuficiente.', 403);
        }
        $tplName = trim((string)($body['template_name'] ?? ''));
        $clientScope = trim((string)($body['client_scope'] ?? 'GLOBAL'));
        $category = trim((string)($body['category'] ?? 'Plantilla Cliente'));
        $subj = trim((string)($body['subject'] ?? ''));
        $bText = trim((string)($body['body_text'] ?? ''));
        $cHtml = trim((string)($body['custom_html'] ?? ''));
        $cta = trim((string)($body['cta_label'] ?? 'REVISAR DOCUMENTO EN LINEA'));
        $accent = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($body['accent_color'] ?? ''))
            ? (string)$body['accent_color']
            : '#0F172A';

        if ($tplName === '' || $subj === '' || ($bText === '' && $cHtml === '')) {
            AdminCore::jsonError('Indique nombre de plantilla, asunto y contenido.', 422);
        }

        $insTpl = $db->prepare("
            INSERT INTO redteam_templates (client_scope, template_name, category, subject, body_text, custom_html, cta_label, accent_color, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insTpl->execute([$clientScope ?: 'GLOBAL', $tplName, $category, $subj, $bText, $cHtml, $cta, $accent, $currentUser['username']]);
        AdminCore::logAudit($currentUser['username'], 'REDTEAM_TPL_SAVE', $tplName, $clientScope);
        AdminCore::jsonOk([
            'templates' => $db->query("SELECT * FROM redteam_templates ORDER BY id DESC")->fetchAll(),
            'message' => "Plantilla '{$tplName}' guardada en la biblioteca reutilizable.",
        ]);
    }

    if ($action === 'update_redteam_template') {
        if (!in_array($currentUser['role'] ?? '', ['superadmin', 'secops'], true)) {
            AdminCore::jsonError('Permiso insuficiente.', 403);
        }
        $tplId = (int)($body['id'] ?? 0);
        $tplName = trim((string)($body['template_name'] ?? ''));
        $clientScope = trim((string)($body['client_scope'] ?? 'GLOBAL'));
        $category = trim((string)($body['category'] ?? 'Plantilla Cliente'));
        $subj = trim((string)($body['subject'] ?? ''));
        $bText = trim((string)($body['body_text'] ?? ''));
        $cHtml = trim((string)($body['custom_html'] ?? ''));
        $cta = trim((string)($body['cta_label'] ?? 'REVISAR DOCUMENTO EN LINEA'));
        $accent = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($body['accent_color'] ?? ''))
            ? (string)$body['accent_color']
            : '#0F172A';

        if ($tplId <= 0 || $tplName === '' || $subj === '' || ($bText === '' && $cHtml === '')) {
            AdminCore::jsonError('Complete nombre, asunto y contenido de la plantilla.', 422);
        }

        $updTpl = $db->prepare("
            UPDATE redteam_templates
            SET client_scope = ?, template_name = ?, category = ?, subject = ?, body_text = ?, custom_html = ?, cta_label = ?, accent_color = ?
            WHERE id = ?
        ");
        $updTpl->execute([$clientScope ?: 'GLOBAL', $tplName, $category ?: 'Corporativo', $subj, $bText, $cHtml, $cta, $accent, $tplId]);
        AdminCore::logAudit($currentUser['username'], 'REDTEAM_TPL_UPDATE', (string)$tplId, $tplName);
        AdminCore::jsonOk([
            'templates' => $db->query("SELECT * FROM redteam_templates ORDER BY id DESC")->fetchAll(),
            'message' => "Plantilla '{$tplName}' actualizada.",
        ]);
    }

    if ($action === 'delete_redteam_template') {
        if (!in_array($currentUser['role'] ?? '', ['superadmin', 'secops'], true)) {
            AdminCore::jsonError('Permiso insuficiente.', 403);
        }
        $tplId = (int)($body['id'] ?? 0);
        $db->prepare("DELETE FROM redteam_templates WHERE id = ?")->execute([$tplId]);
        AdminCore::jsonOk([
            'templates' => $db->query("SELECT * FROM redteam_templates ORDER BY id DESC")->fetchAll(),
            'message' => 'Plantilla eliminada de la biblioteca.',
        ]);
    }

    if ($action === 'delete_redteam_campaign') {
        if (!in_array($currentUser['role'] ?? '', ['superadmin', 'secops'], true)) {
            AdminCore::jsonError('Permiso insuficiente.', 403);
        }
        $id = (int)($body['id'] ?? 0);
        $db->prepare("DELETE FROM redteam_targets WHERE campaign_id = ?")->execute([$id]);
        $db->prepare("DELETE FROM redteam_assets WHERE campaign_id = ?")->execute([$id]);
        $db->prepare("DELETE FROM redteam_campaigns WHERE id = ?")->execute([$id]);
        AdminCore::logAudit($currentUser['username'], 'REDTEAM_DELETE', (string)$id, 'Campaña eliminada');
        AdminCore::jsonOk(['message' => 'Campaña y registros asociados eliminados.']);
    }

    if ($action === 'add_redteam_target') {
        if (!in_array($currentUser['role'] ?? '', ['superadmin', 'secops'], true)) {
            AdminCore::jsonError('Permiso insuficiente.', 403);
        }
        $cid = (int)($body['campaign_id'] ?? 0);
        $name = trim((string)($body['full_name'] ?? ''));
        $email = strtolower(trim((string)($body['email'] ?? '')));
        $dept = trim((string)($body['department'] ?? 'General'));
        $roleTitle = trim((string)($body['role_title'] ?? ''));

        if ($cid <= 0 || $name === '' || $email === '') {
            AdminCore::jsonError('Indique nombre y correo del participante.', 422);
        }

        $cStmt = $db->prepare("SELECT target_domains FROM redteam_campaigns WHERE id = ? LIMIT 1");
        $cStmt->execute([$cid]);
        $campRow = $cStmt->fetch();
        if (!$campRow) {
            AdminCore::jsonError('Campaña no encontrada.', 404);
        }
        $allowedDomains = array_values(array_filter(array_map(
            fn($d) => preg_replace('/^https?:\/\/|^@/', '', strtolower(trim($d))),
            preg_split('/[,;\s]+/', (string)$campRow['target_domains'])
        )));

        // Si el operador escribió solo el usuario (ej: 'facturacion') y hay dominio autorizado, autocompletarlo
        if (strpos($email, '@') === false && !empty($allowedDomains)) {
            $email .= '@' . $allowedDomains[0];
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $hint = !empty($allowedDomains) ? (" (ej: usuario@{$allowedDomains[0]})") : '';
            AdminCore::jsonError("El correo '{$email}' está incompleto o no es válido{$hint}.", 422);
        }

        $emailDom = strtolower(substr(strrchr($email, '@'), 1));
        if (!empty($allowedDomains) && !in_array($emailDom, $allowedDomains, true)) {
            AdminCore::jsonError("Dominio no coincide ('{$emailDom}'). Complete el dominio autorizado: @" . implode(', @', $allowedDomains), 422);
        }

        // Evitar duplicado exacto SOLO dentro de esta misma campaña (permitiendo que participe en múltiples campañas del mismo cliente)
        $dupStmt = $db->prepare("SELECT COUNT(*) FROM redteam_targets WHERE campaign_id = ? AND LOWER(email) = ?");
        $dupStmt->execute([$cid, $email]);
        if ((int)$dupStmt->fetchColumn() > 0) {
            AdminCore::jsonError("El correo {$email} ya está inscrito en esta campaña específica.", 409);
        }

        $tok = 'rtt_' . bin2hex(random_bytes(12));
        $insT = $db->prepare("INSERT INTO redteam_targets (campaign_id, full_name, email, department, role_title, tracking_token) VALUES (?, ?, ?, ?, ?, ?)");
        $insT->execute([$cid, $name, $email, $dept ?: 'General', $roleTitle, $tok]);
        AdminCore::jsonOk(['message' => "Participante {$name} ({$email}) agregado a la campaña."]);
    }

    if ($action === 'clone_redteam_targets') {
        if (!in_array($currentUser['role'] ?? '', ['superadmin', 'secops'], true)) {
            AdminCore::jsonError('Permiso insuficiente.', 403);
        }
        $fromCid = (int)($body['source_campaign_id'] ?? 0);
        $toCid = (int)($body['target_campaign_id'] ?? 0);
        if ($fromCid <= 0 || $toCid <= 0 || $fromCid === $toCid) {
            AdminCore::jsonError('Seleccione una campaña origen distinta a la actual.', 422);
        }

        $cStmt = $db->prepare("SELECT target_domains FROM redteam_campaigns WHERE id = ? LIMIT 1");
        $cStmt->execute([$toCid]);
        $destCamp = $cStmt->fetch();
        if (!$destCamp) {
            AdminCore::jsonError('Campaña destino no encontrada.', 404);
        }
        $allowedDomains = array_values(array_filter(array_map(
            fn($d) => preg_replace('/^https?:\/\/|^@/', '', strtolower(trim($d))),
            preg_split('/[,;\s]+/', (string)$destCamp['target_domains'])
        )));

        $srcStmt = $db->prepare("SELECT full_name, email, department, role_title FROM redteam_targets WHERE campaign_id = ? ORDER BY id ASC");
        $srcStmt->execute([$fromCid]);
        $srcRows = $srcStmt->fetchAll();
        if (empty($srcRows)) {
            AdminCore::jsonError('La campaña seleccionada no tiene participantes para copiar.', 422);
        }

        $chkDup = $db->prepare("SELECT COUNT(*) FROM redteam_targets WHERE campaign_id = ? AND LOWER(email) = LOWER(?)");
        $insT = $db->prepare("INSERT INTO redteam_targets (campaign_id, full_name, email, department, role_title, tracking_token) VALUES (?, ?, ?, ?, ?, ?)");
        $copied = 0;
        foreach ($srcRows as $sr) {
            $em = strtolower(trim((string)$sr['email']));
            $emDom = strtolower(substr(strrchr($em, '@'), 1));
            if (!empty($allowedDomains) && !in_array($emDom, $allowedDomains, true)) {
                continue;
            }
            $chkDup->execute([$toCid, $em]);
            if ((int)$chkDup->fetchColumn() > 0) {
                continue;
            }
            $insT->execute([$toCid, $sr['full_name'], $em, $sr['department'], $sr['role_title'], 'rtt_' . bin2hex(random_bytes(12))]);
            $copied++;
        }

        AdminCore::logAudit($currentUser['username'], 'REDTEAM_CLONE_TARGETS', "{$fromCid}->{$toCid}", "Copiados: {$copied}");
        AdminCore::jsonOk([
            'copied' => $copied,
            'message' => "{$copied} participante(s) importados exitosamente a esta campaña con nuevos tokens de seguimiento.",
        ]);
    }

    if ($action === 'batch_redteam_targets') {
        if (!in_array($currentUser['role'] ?? '', ['superadmin', 'secops'], true)) {
            AdminCore::jsonError('Permiso insuficiente.', 403);
        }
        $cid = (int)($body['campaign_id'] ?? 0);
        $rows = is_array($body['rows'] ?? null) ? $body['rows'] : [];
        $cStmt = $db->prepare("SELECT target_domains FROM redteam_campaigns WHERE id = ? LIMIT 1");
        $cStmt->execute([$cid]);
        $campRow = $cStmt->fetch();
        if (!$campRow) {
            AdminCore::jsonError('Campaña no encontrada.', 404);
        }
        $allowedDomains = array_filter(array_map(fn($d) => strtolower(trim($d)), preg_split('/[,;\s]+/', (string)$campRow['target_domains'])));

        $insT = $db->prepare("INSERT INTO redteam_targets (campaign_id, full_name, email, department, role_title, tracking_token) VALUES (?, ?, ?, ?, ?, ?)");
        $added = 0;
        $rejected = 0;
        foreach (array_slice($rows, 0, 300) as $r) {
            $name = trim((string)($r['full_name'] ?? ''));
            $email = strtolower(trim((string)($r['email'] ?? '')));
            $dept = trim((string)($r['department'] ?? 'General'));
            $roleTitle = trim((string)($r['role_title'] ?? ''));
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
            $emailDom = strtolower(substr(strrchr($email, '@'), 1));
            if (!empty($allowedDomains) && !in_array($emailDom, $allowedDomains, true)) {
                $rejected++;
                continue;
            }
            $insT->execute([$cid, $name, $email, $dept ?: 'General', $roleTitle, 'rtt_' . bin2hex(random_bytes(12))]);
            $added++;
        }
        AdminCore::jsonOk([
            'added' => $added,
            'rejected_domain' => $rejected,
            'message' => "Nómina cargada: {$added} colaboradores inscritos" . ($rejected > 0 ? " ({$rejected} omitidos por estar fuera del dominio autorizado)." : "."),
        ]);
    }

    if ($action === 'delete_redteam_target') {
        if (!in_array($currentUser['role'] ?? '', ['superadmin', 'secops'], true)) {
            AdminCore::jsonError('Permiso insuficiente.', 403);
        }
        $tid = (int)($body['id'] ?? 0);
        $db->prepare("DELETE FROM redteam_targets WHERE id = ?")->execute([$tid]);
        AdminCore::jsonOk(['message' => 'Colaborador retirado.']);
    }

    if ($action === 'delete_redteam_asset') {
        if (!in_array($currentUser['role'] ?? '', ['superadmin', 'secops'], true)) {
            AdminCore::jsonError('Permiso insuficiente.', 403);
        }
        $aid = (int)($body['id'] ?? 0);
        $aStmt = $db->prepare("SELECT stored_filename FROM redteam_assets WHERE id = ? LIMIT 1");
        $aStmt->execute([$aid]);
        $aRow = $aStmt->fetch();
        if ($aRow && !empty($aRow['stored_filename'])) {
            @unlink(AdminCore::getDataDir() . '/rt_assets/' . basename((string)$aRow['stored_filename']));
        }
        $db->prepare("DELETE FROM redteam_assets WHERE id = ?")->execute([$aid]);
        AdminCore::jsonOk(['message' => 'Insumo eliminado de la bóveda.']);
    }

    // Despacho Ético Controlado de Cápsula de Concientización hacia Colaboradores de Dominios Autorizados
    if ($action === 'dispatch_redteam_drill') {
        if (!in_array($currentUser['role'] ?? '', ['superadmin', 'secops'], true)) {
            AdminCore::jsonError('Solo SecOps o Superadmin puede despachar ejercicios autorizados.', 403);
        }
        $cid = (int)($body['campaign_id'] ?? 0);
        $cStmt = $db->prepare("SELECT * FROM redteam_campaigns WHERE id = ? LIMIT 1");
        $cStmt->execute([$cid]);
        $camp = $cStmt->fetch();
        if (!$camp) {
            AdminCore::jsonError('Campaña no encontrada.', 404);
        }

        $allowedDomains = array_filter(array_map(fn($d) => strtolower(trim($d)), preg_split('/[,;\s]+/', (string)$camp['target_domains'])));
        $tStmt = $db->prepare("SELECT * FROM redteam_targets WHERE campaign_id = ? AND status = 'PENDING' LIMIT 50");
        $tStmt->execute([$cid]);
        $pendingTargets = $tStmt->fetchAll();

        if (empty($pendingTargets)) {
            AdminCore::jsonError('No hay colaboradores en estado PENDING dentro de esta campaña.', 422);
        }

        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = AdminCore::safeHost();
        $updSent = $db->prepare("UPDATE redteam_targets SET status = 'SENT', sent_at = CURRENT_TIMESTAMP WHERE id = ?");

        $sentOk = 0;
        $accentHex = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($camp['lure_accent_color'] ?? ''))
            ? (string)$camp['lure_accent_color']
            : '#0F172A';
        $ctaText = trim((string)($camp['lure_cta_label'] ?? '')) ?: 'REVISAR SOLICITUD EN PORTAL INTERNO';
        $rawCustomHtml = trim((string)($camp['custom_html_body'] ?? ''));

        foreach ($pendingTargets as $t) {
            $emailDom = strtolower(substr(strrchr((string)$t['email'], '@'), 1));
            if (!empty($allowedDomains) && !in_array($emailDom, $allowedDomains, true)) {
                continue;
            }
            $learnUrl = "{$proto}://{$host}/shield/rt/?t=" . rawurlencode((string)$t['tracking_token']);
            $pixelUrl = "{$proto}://{$host}/api/shield/client_api.php?action=rt_pixel&t=" . rawurlencode((string)$t['tracking_token']);

            $vars = [
                '{{NOMBRE}}' => htmlspecialchars((string)$t['full_name'], ENT_QUOTES, 'UTF-8'),
                '{{CORREO}}' => htmlspecialchars((string)$t['email'], ENT_QUOTES, 'UTF-8'),
                '{{DEPARTAMENTO}}' => htmlspecialchars((string)($t['department'] ?: 'General'), ENT_QUOTES, 'UTF-8'),
                '{{EMPRESA}}' => htmlspecialchars((string)$camp['company_name'], ENT_QUOTES, 'UTF-8'),
                '{{LINK_CAPSULA}}' => $learnUrl,
            ];

            $personalizedSubj = strtr((string)$camp['last_lure_subject'], [
                '{{NOMBRE}}' => (string)$t['full_name'],
                '{{EMPRESA}}' => (string)$camp['company_name'],
                '{{DEPARTAMENTO}}' => (string)($t['department'] ?: 'General'),
            ]);

            if ($rawCustomHtml !== '') {
                $html = strtr($rawCustomHtml, $vars);
                if (stripos($html, $learnUrl) === false) {
                    $html .= "<div style='text-align:center;margin:20px 0;'><a href='{$learnUrl}' style='display:inline-block;background:{$accentHex};color:#FFFFFF;text-decoration:none;padding:11px 22px;border-radius:6px;font-family:Arial,sans-serif;font-size:13px;font-weight:bold;'>" . htmlspecialchars($ctaText, ENT_QUOTES, 'UTF-8') . "</a></div>";
                }
                $html .= "<img src='{$pixelUrl}' width='1' height='1' alt='' style='display:none;' />";
            } else {
                $substitutedBody = strtr((string)$camp['last_lure_body'], [
                    '{{NOMBRE}}' => (string)$t['full_name'],
                    '{{CORREO}}' => (string)$t['email'],
                    '{{DEPARTAMENTO}}' => (string)($t['department'] ?: 'General'),
                    '{{EMPRESA}}' => (string)$camp['company_name'],
                ]);
                $bodyHtml = nl2br(htmlspecialchars($substitutedBody, ENT_QUOTES, 'UTF-8'));

                $html = "<div style='font-family:Arial,Helvetica,sans-serif;background:#F1F5F9;padding:24px 12px;'>"
                    . "<div style='max-width:620px;margin:0 auto;background:#FFFFFF;border:1px solid #CBD5E1;border-radius:8px;overflow:hidden;color:#1E293B;'>"
                    . "<div style='background:{$accentHex};color:#FFFFFF;padding:16px 24px;display:flex;justify-content:space-between;align-items:center;'>"
                    . "<strong style='font-size:14px;letter-spacing:0.4px;'>" . strtoupper(htmlspecialchars((string)$camp['company_name'], ENT_QUOTES, 'UTF-8')) . "</strong>"
                    . "<span style='font-size:11px;opacity:0.85;margin-left:12px;'>Notificación Electrónica</span>"
                    . "</div>"
                    . "<div style='padding:24px;'>"
                    . "<p style='font-size:13.5px;margin-top:0;'>Estimado(a) <strong>" . htmlspecialchars((string)$t['full_name'], ENT_QUOTES, 'UTF-8') . "</strong>,</p>"
                    . "<div style='font-size:13.5px;line-height:1.65;color:#334155;'>{$bodyHtml}</div>"
                    . "<div style='margin:24px 0;padding:16px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:6px;text-align:center;'>"
                    . "<a href='{$learnUrl}' style='display:inline-block;background:{$accentHex};color:#FFFFFF;text-decoration:none;font-size:13px;font-weight:bold;padding:11px 22px;border-radius:6px;'>" . htmlspecialchars($ctaText, ENT_QUOTES, 'UTF-8') . "</a>"
                    . "</div>"
                    . "<div style='font-size:10.5px;color:#94A3B8;border-top:1px solid #E2E8F0;padding-top:12px;'>Ref. Programa Autorizado de Resiliencia Digital · Cero solicitud de claves</div>"
                    . "</div>"
                    . "<img src='{$pixelUrl}' width='1' height='1' alt='' style='display:none;' />"
                    . "</div></div>";
            }

            $res = AdminCore::sendSmtpEmail((string)$t['email'], $personalizedSubj, $html);
            if (!empty($res['success'])) {
                $updSent->execute([$t['id']]);
                $sentOk++;
            }
        }

        AdminCore::logAudit($currentUser['username'], 'REDTEAM_DRILL_DISPATCH', (string)$cid, "Enviados: {$sentOk}");
        AdminCore::jsonOk([
            'sent_count' => $sentOk,
            'message' => "Ejercicio preventivo despachado a {$sentOk} colaborador(es) autorizados.",
        ]);
    }

    // 5. Aprovisionamiento Multi-Tenant DeepShield + Vinculación QR WhatsApp Evolution API
    if ($action === 'create_tenant') {
        if (!in_array($currentUser['role'] ?? '', ['superadmin', 'secops'], true)) {
            AdminCore::jsonError('Solo SecOps o Superadmin puede aprovisionar empresas clientes.', 403);
        }
        $company = trim((string)($body['company_name'] ?? ''));
        $rut = trim((string)($body['rut_dni'] ?? ''));
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($body['tenant_slug'] ?? '')));
        if ($slug === '') {
            $slug = 'cli_' . substr(md5(uniqid()), 0, 6);
        }
        $threshold = max(0, (float)($body['threshold_amount'] ?? 2000000));
        $currency = strtoupper(trim((string)($body['threshold_currency'] ?? 'CLP')));
        $apprName = trim((string)($body['primary_approver_name'] ?? 'CFO / Director'));
        $apprEmail = strtolower(trim((string)($body['primary_approver_email'] ?? '')));
        $apprPhone = preg_replace('/[^0-9]/', '', (string)($body['primary_approver_phone'] ?? ''));
        $secApprName = trim((string)($body['secondary_approver_name'] ?? ''));
        $secApprPhone = preg_replace('/[^0-9]/', '', (string)($body['secondary_approver_phone'] ?? ''));
        $dualThreshold = max(0, (float)($body['dual_approval_threshold'] ?? 0));
        $opEmail = strtolower(trim((string)($body['treasury_operator_email'] ?? '')));
        $opPassRaw = trim((string)($body['treasury_operator_password'] ?? 'Tesoreria2026!'));
        $waInst = trim((string)($body['whatsapp_instance'] ?? 'keystone-general'));

        if ($company === '' || $opEmail === '') {
            AdminCore::jsonError('Indique razón social y correo del operador de tesorería.', 422);
        }

        $apiKey = 'dst_' . bin2hex(random_bytes(16));
        $passHash = password_hash($opPassRaw, PASSWORD_BCRYPT);

        $insT = $db->prepare("
            INSERT INTO deepshield_tenants (
                tenant_slug, company_name, rut_dni, threshold_amount, threshold_currency,
                primary_approver_name, primary_approver_email, primary_approver_phone,
                secondary_approver_name, secondary_approver_phone, dual_approval_threshold,
                treasury_operator_email, treasury_operator_password, whatsapp_instance, client_api_key, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ACTIVE')
        ");
        $insT->execute([
            $slug, $company, $rut, $threshold, $currency,
            $apprName, $apprEmail, $apprPhone,
            $secApprName, $secApprPhone, $dualThreshold,
            $opEmail, $passHash, $waInst, $apiKey,
        ]);

        AdminCore::logAudit($currentUser['username'], 'TENANT_CREATE', $slug, $company);
        AdminCore::jsonOk([
            'tenant_slug' => $slug,
            'client_api_key' => $apiKey,
            'message' => "Tenant {$company} ({$slug}) aprovisionado para /shield/portal/.",
        ]);
    }

    if ($action === 'whatsapp_qr') {
        $inst = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($body['instance'] ?? 'keystone-general'));
        $qrRes = AdminCore::getOrConnectWhatsAppInstance($inst);
        if (empty($qrRes['success'])) {
            AdminCore::jsonError($qrRes['error'] ?? 'Error consultando instancia WhatsApp.', 422);
        }
        AdminCore::jsonOk($qrRes);
    }

    if ($action === 'switch_tenant_whatsapp') {
        if (!in_array($currentUser['role'] ?? '', ['superadmin', 'secops'], true)) {
            AdminCore::jsonError('Solo SecOps o Superadmin puede modificar instancias WhatsApp de clientes.', 403);
        }
        $id = (int)($body['id'] ?? 0);
        $mode = trim((string)($body['mode'] ?? 'dedicated'));
        $stmt = $db->prepare("SELECT * FROM deepshield_tenants WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $ten = $stmt->fetch();
        if (!$ten) {
            AdminCore::jsonError('Empresa cliente no encontrada.', 404);
        }

        if ($mode === 'shared') {
            $newInst = 'keystone-general';
            $db->prepare("UPDATE deepshield_tenants SET whatsapp_instance = ? WHERE id = ?")->execute([$newInst, $id]);
            AdminCore::logAudit($currentUser['username'], 'TENANT_WA_MODE', $ten['tenant_slug'], "Canal -> keystone-general");
            AdminCore::jsonOk([
                'whatsapp_instance' => $newInst,
                'message' => "{$ten['company_name']} ahora utiliza el canal compartido keystone-general.",
            ]);
        } else {
            $cleanSlug = preg_replace('/[^a-zA-Z0-9_-]/', '', $ten['tenant_slug']);
            $newInst = 'dst_' . $cleanSlug;
            $db->prepare("UPDATE deepshield_tenants SET whatsapp_instance = ? WHERE id = ?")->execute([$newInst, $id]);
            AdminCore::logAudit($currentUser['username'], 'TENANT_WA_MODE', $ten['tenant_slug'], "Canal propio -> {$newInst}");
            $qrRes = AdminCore::getOrConnectWhatsAppInstance($newInst);
            AdminCore::jsonOk([
                'whatsapp_instance' => $newInst,
                'qr' => $qrRes,
                'message' => "Instancia dedicada '{$newInst}' habilitada para {$ten['company_name']}. Puede escanear el QR aquí o desde /shield/portal/.",
            ]);
        }
    }

    if ($action === 'toggle_tenant_status') {
        if (!in_array($currentUser['role'] ?? '', ['superadmin', 'secops'], true)) {
            AdminCore::jsonError('Solo SecOps o Superadmin puede suspender o reactivar empresas.', 403);
        }
        $id = (int)($body['id'] ?? 0);
        $stmt = $db->prepare("SELECT id, tenant_slug, company_name, status FROM deepshield_tenants WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $ten = $stmt->fetch();
        if (!$ten) {
            AdminCore::jsonError('Empresa cliente no encontrada.', 404);
        }
        $nextStatus = ($ten['status'] === 'ACTIVE') ? 'SUSPENDED' : 'ACTIVE';
        $db->prepare("UPDATE deepshield_tenants SET status = ? WHERE id = ?")->execute([$nextStatus, $id]);
        AdminCore::logAudit($currentUser['username'], 'TENANT_STATUS', $ten['tenant_slug'], "Estado -> {$nextStatus}");
        AdminCore::jsonOk([
            'status' => $nextStatus,
            'message' => $nextStatus === 'SUSPENDED'
                ? "Servicio suspendido para {$ten['company_name']}. El acceso a /shield/portal/ quedó bloqueado preservando su historial."
                : "Servicio reactivado para {$ten['company_name']}.",
        ]);
    }

    if ($action === 'delete_tenant') {
        if (!in_array($currentUser['role'] ?? '', ['superadmin', 'secops'], true)) {
            AdminCore::jsonError('Solo SecOps o Superadmin puede eliminar empresas clientes.', 403);
        }
        $id = (int)($body['id'] ?? 0);
        $stmt = $db->prepare("SELECT id, tenant_slug, company_name, whatsapp_instance FROM deepshield_tenants WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $ten = $stmt->fetch();
        if (!$ten) {
            AdminCore::jsonError('Empresa cliente no encontrada.', 404);
        }
        if (!empty($ten['whatsapp_instance']) && $ten['whatsapp_instance'] !== 'keystone-general') {
            @AdminCore::logoutWhatsAppInstance($ten['whatsapp_instance']);
        }
        $db->prepare("DELETE FROM deepshield_tenants WHERE id = ?")->execute([$id]);
        AdminCore::logAudit($currentUser['username'], 'TENANT_DELETE', $ten['tenant_slug'], "Eliminado: {$ten['company_name']}");
        AdminCore::jsonOk([
            'message' => "Empresa {$ten['company_name']} [{$ten['tenant_slug']}] eliminada del padrón activo.",
        ]);
    }

    if ($action === 'rotate_tenant_api_key') {
        if (!in_array($currentUser['role'] ?? '', ['superadmin', 'secops'], true)) {
            AdminCore::jsonError('Solo SecOps o Superadmin puede rotar llaves ERP.', 403);
        }
        $id = (int)($body['id'] ?? 0);
        $newKey = 'dst_' . bin2hex(random_bytes(16));
        $db->prepare("UPDATE deepshield_tenants SET client_api_key = ? WHERE id = ?")->execute([$newKey, $id]);
        AdminCore::logAudit($currentUser['username'], 'TENANT_ROTATE_KEY', (string)$id, 'Nueva llave X-DeepShield-Key generada');
        AdminCore::jsonOk([
            'client_api_key' => $newKey,
            'message' => 'Nueva llave API ERP (X-DeepShield-Key) generada exitosamente.',
        ]);
    }

    AdminCore::jsonError('Acción SecOps no reconocida.', 400);
}

AdminCore::jsonError('Método no permitido.', 405);
