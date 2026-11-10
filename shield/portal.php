<?php
/**
 * Keystone DeepShield Treasury - Portal de Autoservicio para Operadores de Tesorería del Cliente
 * Incluye: Emisión Individual, Ingesta Masiva de Nóminas (Batch CSV/TXT) y Padrón de Proveedores Verificados (Whitelist)
 */

if (session_status() === PHP_SESSION_NONE) {
    @session_start([
        'cookie_httponly' => true,
        'cookie_secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'cookie_samesite' => 'Strict',
        'gc_maxlifetime' => 7200
    ]);
}

require_once __DIR__ . '/../api/Database.php';
require_once __DIR__ . '/../api/DeepShieldService.php';
require_once __DIR__ . '/../api/DeepShieldTenantService.php';
require_once __DIR__ . '/../api/DeepShieldWhatsAppService.php';
require_once __DIR__ . '/../api/LeadNotificationService.php';
require_once __DIR__ . '/../api/DeepShieldBatchService.php';
require_once __DIR__ . '/../api/KeystoneSmtpClient.php';
require_once __DIR__ . '/../api/DmarcWatchdogService.php';
require_once __DIR__ . '/../api/DmarcMonthlyDossierPdfGenerator.php';
require_once __DIR__ . '/../api/DmarcForensicEvidencePdfGenerator.php';

$db = Database::getInstance();
$tenantService = new DeepShieldTenantService($db);
$deepShield = new DeepShieldService($db);
$batchService = new DeepShieldBatchService($db);
$waService = new DeepShieldWhatsAppService();

// Manejo de Logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    unset($_SESSION['treasury_tenant'], $_SESSION['ds_2fa_pending']);
    header("Location: portal.php");
    exit;
}

// Cancelar 2FA y regresar al login de credenciales
if (isset($_GET['cancel_2fa'])) {
    unset($_SESSION['ds_2fa_pending']);
    header("Location: portal.php");
    exit;
}

$tenant = $_SESSION['treasury_tenant'] ?? null;
$activeTab = $_GET['tab'] ?? 'single'; // single, batch, whitelist
$loginError = null;
$loginInfo = null;
$orderCreated = null;
$batchResult = null;
$feedbackMsg = null;
$is2faStep = !empty($_SESSION['ds_2fa_pending']);

// Reenviar OTP de DeepShield
if (isset($_GET['resend_2fa']) && !empty($_SESSION['ds_2fa_pending'])) {
    $pData = $_SESSION['ds_2fa_pending'];
    $forceCh = $_GET['channel'] ?? null;
    $otp = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    $otpHash = password_hash($otp, PASSWORD_BCRYPT);

    // Guardar OTP en la tabla correspondiente
    if ($pData['type'] === 'OPERATOR') {
        $st = $db->prepare("UPDATE deepshield_operators SET otp_code = ?, otp_expires_at = datetime('now', '+5 minutes') WHERE id = ?");
        $st->execute([$otpHash, $pData['operator_id']]);
    } else {
        $st = $db->prepare("UPDATE deepshield_tenants SET otp_code = ?, otp_expires_at = datetime('now', '+5 minutes') WHERE tenant_slug = ?");
        $st->execute([$otpHash, $pData['slug']]);
    }

    $destPhone = $pData['phone'];
    $destEmail = $pData['email'];
    $opName = $pData['name'];

    if ($forceCh === 'email' || empty($destPhone)) {
        $smtp = new KeystoneSmtpClient();
        $sub = "🛡️ Código de Verificación 2FA: {$otp} - Keystone DeepShield";
        $html = "<div style='background:#0B0F19; color:#fff; padding:20px; font-family:Arial;'>
                    <h2 style='color:#38BDF8;'>Keystone DeepShield</h2>
                    <p>Hola <strong>{$opName}</strong>,</p>
                    <p>Tu nuevo código de verificación para ingresar al Portal de Tesorería es:</p>
                    <h1 style='color:#10B981; letter-spacing:6px; font-family:monospace;'>{$otp}</h1>
                    <p style='color:#94A3B8; font-size:12px;'>Expira en 5 minutos.</p>
                 </div>";
        $smtp->send($destEmail, $sub, $html);
        $loginInfo = "Nuevo código enviado a tu correo ({$destEmail})";
    } else {
        try {
            $msg = "🛡️ *Keystone DeepShield (2FA)*\n\nHola {$opName},\nTu nuevo código de verificación es: *{$otp}*\n\nExpira en 5 minutos.";
            $waService->sendRawMessage('keystone-general', $destPhone, $msg);
            $cleanPh = substr($destPhone, 0, 4) . '***' . substr($destPhone, -4);
            $loginInfo = "Nuevo código enviado a tu WhatsApp ({$cleanPh})";
        } catch (\Throwable $e) {
            $smtp = new KeystoneSmtpClient();
            $sub = "🛡️ Código de Verificación 2FA: {$otp} - Keystone DeepShield";
            $html = "<div style='background:#0B0F19; color:#fff; padding:20px; font-family:Arial;'>
                        <h2 style='color:#38BDF8;'>Keystone DeepShield</h2>
                        <p>Hola <strong>{$opName}</strong>,</p>
                        <p>Tu nuevo código de verificación para ingresar al Portal de Tesorería es:</p>
                        <h1 style='color:#10B981; letter-spacing:6px; font-family:monospace;'>{$otp}</h1>
                        <p style='color:#94A3B8; font-size:12px;'>Expira en 5 minutos.</p>
                     </div>";
            $smtp->send($destEmail, $sub, $html);
            $loginInfo = "Nuevo código enviado a tu correo ({$destEmail})";
        }
    }
}

// Paso 1: Verificación de Credenciales
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $slug = trim($_POST['tenant_slug'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    $authedTenant = $tenantService->authenticateOperator($slug, $email, $password);
    if ($authedTenant) {
        $realSlug = $authedTenant['tenant_slug'];
        $operatorPhone = $authedTenant['treasury_operator_phone'] ?? '';
        $operatorEmail = $authedTenant['treasury_operator_email'] ?? $email;
        $operatorName = $authedTenant['treasury_operator_name'] ?? 'Operador';
        $operatorId = $authedTenant['current_operator_id'] ?? 0;

        $otp = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        $otpHash = password_hash($otp, PASSWORD_BCRYPT);

        if ($operatorId > 0) {
            $st = $db->prepare("UPDATE deepshield_operators SET otp_code = ?, otp_expires_at = datetime('now', '+5 minutes') WHERE id = ?");
            $st->execute([$otpHash, $operatorId]);
            $pendingType = 'OPERATOR';
        } else {
            $st = $db->prepare("UPDATE deepshield_tenants SET otp_code = ?, otp_expires_at = datetime('now', '+5 minutes') WHERE tenant_slug = ?");
            $st->execute([$otpHash, $realSlug]);
            $pendingType = 'TENANT';
        }

        // Cascada: WhatsApp prioritario con fallback a Email
        $channel = 'whatsapp';
        $maskedDest = '';

        if (!empty($operatorPhone)) {
            try {
                $msg = "🛡️ *Keystone DeepShield (2FA)*\n\nHola {$operatorName},\nTu código de verificación para ingresar al Portal de Tesorería es:\n\n👉 *{$otp}*\n\nExpira en 5 minutos. No lo compartas.";
                $waService->sendRawMessage('keystone-general', $operatorPhone, $msg);
                $maskedDest = substr($operatorPhone, 0, 4) . '***' . substr($operatorPhone, -4);
                $channel = 'whatsapp';
            } catch (\Throwable $eWa) {
                $smtp = new KeystoneSmtpClient();
                $sub = "🛡️ Código de Verificación 2FA: {$otp} - Keystone DeepShield";
                $html = "<div style='background:#0B0F19; color:#fff; padding:20px; font-family:Arial;'>
                            <h2 style='color:#38BDF8;'>Keystone DeepShield</h2>
                            <p>Hola <strong>{$operatorName}</strong>,</p>
                            <p>Tu código de verificación para ingresar al Portal de Tesorería es:</p>
                            <h1 style='color:#10B981; letter-spacing:6px; font-family:monospace;'>{$otp}</h1>
                            <p style='color:#94A3B8; font-size:12px;'>Expira en 5 minutos.</p>
                         </div>";
                $smtp->send($operatorEmail, $sub, $html);
                $parts = explode('@', $operatorEmail);
                $maskedDest = substr($parts[0], 0, 3) . '***@' . ($parts[1] ?? '');
                $channel = 'email';
            }
        } else {
            $smtp = new KeystoneSmtpClient();
            $sub = "🛡️ Código de Verificación 2FA: {$otp} - Keystone DeepShield";
            $html = "<div style='background:#0B0F19; color:#fff; padding:20px; font-family:Arial;'>
                        <h2 style='color:#38BDF8;'>Keystone DeepShield</h2>
                        <p>Hola <strong>{$operatorName}</strong>,</p>
                        <p>Tu código de verificación para ingresar al Portal de Tesorería es:</p>
                        <h1 style='color:#10B981; letter-spacing:6px; font-family:monospace;'>{$otp}</h1>
                        <p style='color:#94A3B8; font-size:12px;'>Expira en 5 minutos.</p>
                     </div>";
            $smtp->send($operatorEmail, $sub, $html);
            $parts = explode('@', $operatorEmail);
            $maskedDest = substr($parts[0], 0, 3) . '***@' . ($parts[1] ?? '');
            $channel = 'email';
        }

        $_SESSION['ds_2fa_pending'] = [
            'authed_tenant' => $authedTenant,
            'slug' => $slug,
            'email' => $operatorEmail,
            'phone' => $operatorPhone,
            'name' => $operatorName,
            'operator_id' => $operatorId,
            'type' => $pendingType,
            'channel' => $channel,
            'masked_destination' => $maskedDest
        ];
        $is2faStep = true;
    } else {
        $loginError = "Credenciales incorrectas o la empresa se encuentra suspendida.";
    }
}

// Paso 2: Verificación de Código 2FA OTP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_2fa') {
    $enteredOtp = trim($_POST['otp_code'] ?? '');
    $pData = $_SESSION['ds_2fa_pending'] ?? null;

    if (!$pData || empty($enteredOtp)) {
        $loginError = "Sesión de verificación expirada. Por favor inicie sesión nuevamente.";
        unset($_SESSION['ds_2fa_pending']);
        $is2faStep = false;
    } else {
        // Consultar hash y expiración
        if ($pData['type'] === 'OPERATOR') {
            $st = $db->prepare("SELECT otp_code, otp_expires_at FROM deepshield_operators WHERE id = ?");
            $st->execute([$pData['operator_id']]);
        } else {
            $st = $db->prepare("SELECT otp_code, otp_expires_at FROM deepshield_tenants WHERE tenant_slug = ?");
            $st->execute([$pData['slug']]);
        }
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row || empty($row['otp_code'])) {
            $loginError = "Código no encontrado o expirado. Solicite un nuevo reenvío.";
        } else {
            $stmtExp = $db->prepare("SELECT datetime('now') > ?");
            $stmtExp->execute([$row['otp_expires_at']]);
            $isExp = (int)$stmtExp->fetchColumn();

            if ($isExp === 1) {
                $loginError = "El código ha expirado (validez de 5 minutos). Solicite uno nuevo.";
            } elseif (!password_verify($enteredOtp, $row['otp_code'])) {
                $loginError = "Código de verificación incorrecto. Revise el mensaje recibido.";
            } else {
                // Éxito: limpiar OTP y conceder sesión
                if ($pData['type'] === 'OPERATOR') {
                    $cl = $db->prepare("UPDATE deepshield_operators SET otp_code = NULL, otp_expires_at = NULL WHERE id = ?");
                    $cl->execute([$pData['operator_id']]);
                } else {
                    $cl = $db->prepare("UPDATE deepshield_tenants SET otp_code = NULL, otp_expires_at = NULL WHERE tenant_slug = ?");
                    $cl->execute([$pData['slug']]);
                }

                $_SESSION['treasury_tenant'] = $pData['authed_tenant'];
                unset($_SESSION['ds_2fa_pending']);
                header("Location: portal.php");
                exit;
            }
        }
    }
}

// Acciones exclusivas para operador autenticado
if ($tenant) {
    $freshTenant = $tenantService->getTenantBySlug($tenant['tenant_slug']);
    if (!$freshTenant || $freshTenant['status'] !== 'ACTIVE') {
        die("<h1>Servicio Suspendido</h1><p>Su empresa se encuentra inactiva o suspendida. Contacte a Keystone Advisors.</p>");
    }

    // 1. Emisión de Orden Individual
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_payment') {
        $activeTab = 'single';
        
        // Resolver approver seleccionado o primario por defecto
        $approverId = (int)($_POST['approver_id'] ?? 0);
        $approverName = $freshTenant['primary_approver_name'];
        $approverEmail = $freshTenant['primary_approver_email'];
        $approverPhone = $freshTenant['primary_approver_phone'];

        if ($approverId > 0) {
            $stmtAppr = $db->prepare("SELECT * FROM deepshield_approvers WHERE id = ? AND tenant_slug = ? AND status = 'ACTIVE' LIMIT 1");
            $stmtAppr->execute([$approverId, $freshTenant['tenant_slug']]);
            $selAppr = $stmtAppr->fetch(PDO::FETCH_ASSOC);
            if ($selAppr) {
                $approverName = $selAppr['full_name'];
                $approverEmail = $selAppr['email'];
                $approverPhone = $selAppr['phone_whatsapp'];
            }
        }

        $operatorSignature = (!empty($tenant['treasury_operator_name']) ? $tenant['treasury_operator_name'] : 'Operador') . 
                             (!empty($tenant['treasury_operator_email']) ? " ({$tenant['treasury_operator_email']})" : '');

        $payload = [
            'tenant_slug' => $freshTenant['tenant_slug'],
            'company_name' => $freshTenant['company_name'],
            'request_type' => $_POST['request_type'] ?? 'PAYMENT_TRANSFER',
            'amount' => (float)($_POST['amount'] ?? 0),
            'currency' => $_POST['currency'] ?? $freshTenant['threshold_currency'],
            'beneficiary_name' => trim($_POST['beneficiary_name'] ?? ''),
            'beneficiary_rut_dni' => trim($_POST['beneficiary_rut_dni'] ?? ''),
            'bank_name' => trim($_POST['bank_name'] ?? ''),
            'account_number' => trim($_POST['account_number'] ?? ''),
            'account_type' => trim($_POST['account_type'] ?? 'Cuenta Corriente'),
            'invoice_folio' => trim($_POST['invoice_folio'] ?? ''),
            'approver_name' => $approverName,
            'approver_email' => $approverEmail,
            'approver_phone' => $approverPhone,
            'audit_notes' => "Emitida por operador: {$operatorSignature}"
        ];

        // Verificar riesgo contra Whitelist
        $risk = $batchService->verifyTransferRisk($freshTenant['tenant_slug'], $payload['beneficiary_rut_dni'], $payload['account_number']);
        if ($risk['status'] === 'ACCOUNT_MISMATCH') {
            $payload['audit_notes'] .= ' | 🚨 ALERTA ROJA WHITELIST: Intento de pago con cuenta bancaria distinta a la habitual del proveedor (' . $risk['expected_bank'] . ' N° ' . $risk['expected_account'] . ').';
        }

        $res = $deepShield->createOrder($payload);

        if (!empty($res['success'])) {
            $orderCreated = $res;
            
            // Disparar Alerta Instantánea WhatsApp al Director seleccionado
            $waInstance = !empty($freshTenant['whatsapp_instance']) ? $freshTenant['whatsapp_instance'] : 'keystone-general';
            $orderMeta = array_merge($payload, [
                'security_challenge' => $res['security_challenge'],
                'order_code' => $res['order_code']
            ]);

            if (!empty($approverPhone)) {
                $waService->sendTreasuryAlert(
                    $waInstance,
                    $approverPhone,
                    $orderMeta,
                    $res['verification_url']
                );
            }

            // Respaldo por Correo Electrónico
            try {
                $smtpConfig = LeadNotificationService::getSmtpConfig();
                if (!empty($smtpConfig['smtp_pass']) && !empty($approverEmail)) {
                    $client = new KeystoneSmtpClient($smtpConfig);
                    $subject = "🛡️ Alerta DeepShield: Aprobación de Pago Crítica [{$res['order_code']}]";
                    $body = "<h3>Keystone DeepShield Treasury - Solicitud de Aprobación</h3>
                             <p>Se ha registrado una orden de pago crítica para <strong>{$freshTenant['company_name']}</strong> por un monto de <strong>{$payload['currency']} \${$payload['amount']}</strong>.</p>
                             <p><strong>Beneficiario:</strong> {$payload['beneficiary_name']} (RUT: {$payload['beneficiary_rut_dni']})<br>
                             <strong>Banco / Cuenta:</strong> {$payload['bank_name']} - {$payload['account_number']}<br>
                             <strong>Operador Solicitante:</strong> {$operatorSignature}<br>
                             <strong>Desafío Anti-Voz IA:</strong> <span style='font-size:1.2rem; font-weight:bold; color:#0284c7;'>{$res['security_challenge']}</span></p>
                             <p><a href='{$res['verification_url']}' style='background:#10b981; color:var(--text-main); padding:10px 18px; text-decoration:none; border-radius:6px; font-weight:bold;'>Autorizar en Smartphone ➜</a></p>";
                    $client->send($approverEmail, $subject, $body);
                }
            } catch (Exception $e) {}
        }
    }

    // 2. Procesamiento de Ingesta Masiva de Nómina Bancaria (Batch)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'parse_batch_file') {
        $activeTab = 'batch';
        if (!empty($_FILES['batch_file']['tmp_name']) && is_uploaded_file($_FILES['batch_file']['tmp_name'])) {
            $parsed = $batchService->parseBatchFile($_FILES['batch_file']['tmp_name'], $freshTenant['tenant_slug'], (float)$freshTenant['threshold_amount']);
            if ($parsed['success']) {
                $batchResult = $parsed;
                $_SESSION['pending_batch_rows'] = $parsed['rows'];
            } else {
                $feedbackMsg = ['type' => 'error', 'text' => $parsed['error']];
            }
        } else {
            $feedbackMsg = ['type' => 'error', 'text' => 'Por favor seleccione un archivo CSV o TXT válido.'];
        }
    }

    // 3. Confirmación e Ingesta de Órdenes Batch Seleccionadas
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'confirm_batch_ingest') {
        $activeTab = 'batch';
        $selectedIndices = $_POST['selected_rows'] ?? [];
        $rows = $_SESSION['pending_batch_rows'] ?? [];

        // Resolver Director Firmante para el batch
        $batchApproverId = (int)($_POST['batch_approver_id'] ?? 0);
        $approverName = $freshTenant['primary_approver_name'];
        $approverEmail = $freshTenant['primary_approver_email'];
        $approverPhone = $freshTenant['primary_approver_phone'];

        if ($batchApproverId > 0) {
            $stmtAppr = $db->prepare("SELECT * FROM deepshield_approvers WHERE id = ? AND tenant_slug = ? AND status = 'ACTIVE' LIMIT 1");
            $stmtAppr->execute([$batchApproverId, $freshTenant['tenant_slug']]);
            $selAppr = $stmtAppr->fetch(PDO::FETCH_ASSOC);
            if ($selAppr) {
                $approverName = $selAppr['full_name'];
                $approverEmail = $selAppr['email'];
                $approverPhone = $selAppr['phone_whatsapp'];
            }
        }

        $operatorSignature = (!empty($tenant['treasury_operator_name']) ? $tenant['treasury_operator_name'] : 'Operador') . 
                             (!empty($tenant['treasury_operator_email']) ? " ({$tenant['treasury_operator_email']})" : '');

        if (empty($selectedIndices) || empty($rows)) {
            $feedbackMsg = ['type' => 'error', 'text' => 'No seleccionó transferencias para procesar.'];
        } else {
            $createdCount = 0;
            $waInstance = !empty($freshTenant['whatsapp_instance']) ? $freshTenant['whatsapp_instance'] : 'keystone-general';

            foreach ($selectedIndices as $idx) {
                if (isset($rows[$idx])) {
                    $r = $rows[$idx];
                    $payload = [
                        'tenant_slug' => $freshTenant['tenant_slug'],
                        'company_name' => $freshTenant['company_name'],
                        'request_type' => 'PAYMENT_TRANSFER',
                        'amount' => (float)$r['amount'],
                        'currency' => $freshTenant['threshold_currency'],
                        'beneficiary_name' => $r['beneficiary_name'],
                        'beneficiary_rut_dni' => $r['clean_rut'],
                        'bank_name' => $r['bank_name'],
                        'account_number' => $r['clean_account'],
                        'account_type' => $r['account_type'],
                        'invoice_folio' => $r['invoice_folio'],
                        'approver_name' => $approverName,
                        'approver_email' => $approverEmail,
                        'approver_phone' => $approverPhone,
                        'audit_notes' => "Ingesta masiva batch por {$operatorSignature}." . ($r['risk']['status'] === 'ACCOUNT_MISMATCH' ? ' [🚨 ALERTA CAMBIO DE CUENTA]' : '')
                    ];

                    $res = $deepShield->createOrder($payload);
                    if (!empty($res['success'])) {
                        $createdCount++;
                        // Si requiere OOB, disparar WhatsApp
                        if (!empty($approverPhone)) {
                            $orderMeta = array_merge($payload, [
                                'security_challenge' => $res['security_challenge'],
                                'order_code' => $res['order_code']
                            ]);
                            $waService->sendTreasuryAlert(
                                $waInstance,
                                $approverPhone,
                                $orderMeta,
                                $res['verification_url']
                            );
                        }
                    }
                }
            }

            unset($_SESSION['pending_batch_rows']);
            $feedbackMsg = ['type' => 'success', 'text' => "✓ Se emitieron exitosamente {$createdCount} órdenes críticas con validación OOB para el firmante ({$approverName})."];
        }
    }

    // 4. Gestión de Whitelist (Guardar Proveedor)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_whitelist_beneficiary') {
        $activeTab = 'whitelist';
        $res = $batchService->saveBeneficiary($freshTenant['tenant_slug'], [
            'rut_dni' => $_POST['rut_dni'] ?? '',
            'business_name' => $_POST['business_name'] ?? '',
            'bank_name' => $_POST['bank_name'] ?? '',
            'account_number' => $_POST['account_number'] ?? '',
            'account_type' => $_POST['account_type'] ?? 'Cuenta Corriente',
            'contact_email' => $_POST['contact_email'] ?? ''
        ], $tenant['treasury_operator_name'] ?? 'Operador Tesorería');

        if ($res['success']) {
            $feedbackMsg = ['type' => 'success', 'text' => "✓ Proveedor {$res['action']} exitosamente en el padrón verificado."];
        } else {
            $feedbackMsg = ['type' => 'error', 'text' => $res['error']];
        }
    }

    // 5. Eliminar de Whitelist
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_whitelist_beneficiary') {
        $activeTab = 'whitelist';
        $id = (int)($_POST['beneficiary_id'] ?? 0);
        $batchService->deleteBeneficiary($freshTenant['tenant_slug'], $id);
        $feedbackMsg = ['type' => 'success', 'text' => '✓ Proveedor eliminado del padrón.'];
    }

    // Resolver Tenant DMARC vinculado a la empresa (Arquitectura Multi-Dominio)
    $dmarcWatchdog = new DmarcWatchdogService($db);
    // Intentar vincular por dominio configurado o deducido del correo corporativo
    $domainCandidate = '';
    if (!empty($freshTenant['treasury_operator_email']) && strpos($freshTenant['treasury_operator_email'], '@') !== false) {
        $domainCandidate = strtolower(explode('@', $freshTenant['treasury_operator_email'])[1]);
    }
    // Buscar tenant DMARC por cualquiera de sus dominios o nombre de empresa
    $dmarcTenant = null;
    if ($domainCandidate) {
        $dmarcTenant = $dmarcWatchdog->getTenantByAnyDomain($domainCandidate);
    }
    if (!$dmarcTenant) {
        $stmtD = $db->prepare("SELECT * FROM dmarc_tenants WHERE lower(company_name) LIKE lower(?) LIMIT 1");
        $stmtD->execute(['%' . substr($freshTenant['company_name'], 0, 8) . '%']);
        $dmarcTenant = $stmtD->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // Obtener todos los dominios registrados para este cliente
    $myDomains = [];
    if ($dmarcTenant) {
        $myDomains = $dmarcWatchdog->getDomainsByTenant($dmarcTenant['id']);
    }

    // Dominio seleccionado para el portal: 'all' (Consolidado) o un dominio específico
    $selectedDomainParam = trim($_GET['domain'] ?? '');
    $selectedDomainObj = null;

    if ($selectedDomainParam && $selectedDomainParam !== 'all') {
        foreach ($myDomains as $dm) {
            if (strtolower($dm['domain']) === strtolower($selectedDomainParam)) {
                $selectedDomainObj = $dm;
                break;
            }
        }
    }

    // Si no se seleccionó o es 'all'
    $isConsolidated = ($selectedDomainParam === 'all' || (empty($selectedDomainParam) && count($myDomains) > 1));
    if (!$isConsolidated && empty($selectedDomainObj) && !empty($myDomains)) {
        $selectedDomainObj = $myDomains[0]; // Tomar el primario
    }

    $activeDomainName = $selectedDomainObj ? $selectedDomainObj['domain'] : ($dmarcTenant['domain'] ?? ($domainCandidate ?: 'empresa.cl'));

    // Acción: Descarga de Dosier DMARC Mensual Oficial
    if (isset($_GET['action']) && $_GET['action'] === 'download_my_dossier') {
        if ($dmarcTenant) {
            $reqDom = trim($_GET['domain'] ?? '');
            $targetDomainForPdf = $dmarcTenant['domain'];
            
            if ($reqDom && $reqDom !== 'all') {
                $targetDomainForPdf = $reqDom;
                $metrics = $dmarcWatchdog->getMetrics($targetDomainForPdf);
            } elseif ($reqDom === 'all' || count($myDomains) > 1) {
                // Consolidado de todos los dominios de la empresa
                $domainList = !empty($myDomains) ? array_column($myDomains, 'domain') : [$dmarcTenant['domain']];
                $metrics = $dmarcWatchdog->getMetrics($domainList);
                $targetDomainForPdf = 'CONSOLIDADO_' . preg_replace('/[^A-Za-z0-9]/', '_', $dmarcTenant['company_name']);
            } else {
                $metrics = $dmarcWatchdog->getMetrics($targetDomainForPdf);
            }

            $requestedPeriod = trim($_GET['period'] ?? '');
            if (!empty($requestedPeriod) && preg_match('/^\d{4}-\d{2}$/', $requestedPeriod)) {
                $parts = explode('-', $requestedPeriod);
                $period = $parts[1] . '/' . $parts[0];
                $filePeriodSuffix = $parts[0] . $parts[1];
            } else {
                $period = date('m/Y');
                $filePeriodSuffix = date('Ym');
            }

            $tenantForPdf = $dmarcTenant;
            if ($reqDom && $reqDom !== 'all') {
                $tenantForPdf['domain'] = $reqDom;
            }
            $pdfGen = new DmarcMonthlyDossierPdfGenerator($tenantForPdf, $metrics, $period);
            $pdfBinary = $pdfGen->generate();
            $filename = 'Dosier_DMARC_' . preg_replace('/[^A-Za-z0-9]/', '_', $targetDomainForPdf) . '_' . $filePeriodSuffix . '.pdf';

            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . $filename . '"');
            header('Cache-Control: private, max-age=0, must-revalidate');
            echo $pdfBinary;
            exit;
        } else {
            $feedbackMsg = ['type' => 'error', 'text' => 'Su dominio no registra un módulo DMARC activo aún.'];
        }
    }

    // Acción: Descarga de Certificado Pericial Ley 21.459 (PDI / Fiscalía)
    if (isset($_GET['action']) && $_GET['action'] === 'download_my_pdi_cert') {
        $ip = trim($_GET['ip'] ?? '');
        if ($dmarcTenant && !empty($ip)) {
            $intel = $dmarcWatchdog->resolveIpIntelligence($ip);
            $incident = [
                'source_ip' => $ip,
                'country' => $intel['country'],
                'city' => $intel['city'],
                'org' => $intel['org'],
                'total_attempts' => (int)($_GET['attempts'] ?? 1)
            ];
            $pdfGen = new DmarcForensicEvidencePdfGenerator($dmarcTenant, $incident);
            $pdfBinary = $pdfGen->generate();
            $filename = 'Certificado_Pericial_PDI_' . preg_replace('/[^A-Za-z0-9]/', '_', $ip) . '_' . date('Ymd') . '.pdf';

            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . $filename . '"');
            header('Cache-Control: private, max-age=0, must-revalidate');
            echo $pdfBinary;
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Portal de Ciberseguridad & Gobierno Financiero | Keystone DeepShield</title>
  <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
  <script>
    (function() {
      var savedTheme = localStorage.getItem('deepshield_theme') || 'dark';
      document.documentElement.setAttribute('data-theme', savedTheme);
    })();
  </script>
  <link rel="icon" type="image/png" href="../assets/images/favicon.png">
  <style>
    /* ==============================================================================
       SISTEMA MULTI-TEMA: DESIGN TOKENS DINÁMICOS
       ============================================================================== */
    :root {
      --bg-body: #0b1329;
      --bg-card: #131f37;
      --bg-card-gradient: linear-gradient(180deg, #131f37 0%, #0d1527 100%);
      --bg-card-dmarc: linear-gradient(180deg, #0d1e38 0%, #081326 100%);
      --bg-input: #0b1329;
      --bg-subtle: #0f172a;
      --border-color: #1e3a5f;
      --border-subtle: #1e293b;
      --border-input: #334e68;
      --text-main: #f8fafc;
      --text-muted: #94a3b8;
      --text-label: #cbd5e1;
      --brand-accent: #38bdf8;
      --brand-accent-hover: #0284c7;
      --table-header-bg: #0b1329;
      --table-row-border: rgba(255,255,255,0.06);
      --box-shadow: 0 10px 25px -5px rgba(0,0,0,0.4);
      --select-theme-bg: #131f37;
      --select-theme-border: #334e68;
      --select-theme-color: var(--text-main);
      color-scheme: dark;
    }

    /* TEMA 1: DARK SLATE (POR DEFECTO) */
    [data-theme="dark"] {
      --bg-body: #0b1329;
      --bg-card: #131f37;
      --bg-card-gradient: linear-gradient(180deg, #131f37 0%, #0d1527 100%);
      --bg-card-dmarc: linear-gradient(180deg, #0d1e38 0%, #081326 100%);
      --bg-input: #0b1329;
      --bg-subtle: #0f172a;
      --border-color: #1e3a5f;
      --border-subtle: #1e293b;
      --border-input: #334e68;
      --text-main: #f8fafc;
      --text-muted: #94a3b8;
      --text-label: #cbd5e1;
      --brand-accent: #38bdf8;
      --brand-accent-hover: #0284c7;
      --table-header-bg: #0b1329;
      --table-row-border: rgba(255,255,255,0.06);
      --box-shadow: 0 10px 25px -5px rgba(0,0,0,0.4);
      --select-theme-bg: #131f37;
      --select-theme-border: #334e68;
      --select-theme-color: var(--text-main);
      color-scheme: dark;
    }

    /* TEMA 2: LIGHT CORPORATE (PROFESIONAL, BLANCO Y GRIS PERLA ANTI-FATIGA) */
    [data-theme="light"] {
      --bg-body: #f1f5f9;
      --bg-card: #ffffff;
      --bg-card-gradient: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
      --bg-card-dmarc: linear-gradient(180deg, #f0fdf4 0%, #ffffff 100%);
      --bg-input: #ffffff;
      --bg-subtle: #f8fafc;
      --border-color: var(--text-label);
      --border-subtle: #e2e8f0;
      --border-input: #94a3b8;
      --text-main: #0f172a;
      --text-muted: #475569;
      --text-label: #1e293b;
      --brand-accent: #0284c7;
      --brand-accent-hover: #0369a1;
      --table-header-bg: #e2e8f0;
      --table-row-border: #e2e8f0;
      --box-shadow: 0 4px 15px rgba(0,0,0,0.08);
      --select-theme-bg: #ffffff;
      --select-theme-border: #cbd5e1;
      --select-theme-color: #0f172a;
      color-scheme: light;
    }

    /* TEMA 3: NORDIC BLUE (ESTILO ATLASSIAN / AZUL COBALTO Y GRIS SUAVE) */
    [data-theme="nordic"] {
      --bg-body: #e6edf5;
      --bg-card: #ffffff;
      --bg-card-gradient: linear-gradient(180deg, #ffffff 0%, #f4f7fb 100%);
      --bg-card-dmarc: linear-gradient(180deg, #f0f7ff 0%, #ffffff 100%);
      --bg-input: #ffffff;
      --bg-subtle: #edf3fa;
      --border-color: #bcd0e4;
      --border-subtle: #cfdbe8;
      --border-input: #8faec9;
      --text-main: #0c2340;
      --text-muted: #3d5674;
      --text-label: #172b4d;
      --brand-accent: #0052cc;
      --brand-accent-hover: #0747a6;
      --table-header-bg: #dce7f3;
      --table-row-border: #dce7f3;
      --box-shadow: 0 6px 18px rgba(9,30,66,0.1);
      --select-theme-bg: #ffffff;
      --select-theme-border: #bcd0e4;
      --select-theme-color: #0c2340;
      color-scheme: light;
    }

    /* TEMA 4: HIGH-CONTRAST GRAPHITE (CARBON OSCURO INTERMEDIO) */
    [data-theme="graphite"] {
      --bg-body: #141518;
      --bg-card: #1f2024;
      --bg-card-gradient: linear-gradient(180deg, #1f2024 0%, #17181b 100%);
      --bg-card-dmarc: linear-gradient(180deg, #1b222a 0%, #151619 100%);
      --bg-input: #141518;
      --bg-subtle: #25272c;
      --border-color: #353840;
      --border-subtle: #2c2e35;
      --border-input: #464a54;
      --text-main: #ffffff;
      --text-muted: #a3a6b0;
      --text-label: #d1d4dc;
      --brand-accent: #60a5fa;
      --brand-accent-hover: #3b82f6;
      --table-header-bg: #141518;
      --table-row-border: #2a2c33;
      --box-shadow: 0 10px 30px rgba(0,0,0,0.6);
      --select-theme-bg: #1f2024;
      --select-theme-border: #464a54;
      --select-theme-color: #ffffff;
      color-scheme: dark;
    }

    * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
    body { background: var(--bg-body); color: var(--text-main); min-height: 100vh; padding: 2rem 1.5rem; transition: background-color 0.25s ease, color 0.25s ease; }
    .container { max-width: 1200px; width: 100%; margin: 0 auto; }
    .header { display: flex; justify-content: space-between; align-items: center; padding-bottom: 1.5rem; border-bottom: 1px solid var(--border-subtle); margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem; }
    .brand { font-size: 1.35rem; font-weight: 800; color: var(--brand-accent); display: flex; align-items: center; gap: 0.6rem; }
    .tabs-nav { display: flex; gap: 0.5rem; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-subtle); padding-bottom: 0.75rem; flex-wrap: wrap; }
    .tab-btn { background: var(--bg-card); border: 1px solid var(--border-color); color: var(--text-muted); padding: 0.65rem 1.25rem; border-radius: 8px; font-weight: 700; font-size: 0.9rem; text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem; transition: all 0.2s; }
    .tab-btn:hover, .tab-btn.active { background: var(--brand-accent-hover); color: var(--text-main); border-color: var(--brand-accent); }
    .card { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 14px; padding: 2rem; box-shadow: var(--box-shadow); margin-bottom: 2rem; transition: all 0.25s ease; }
    .form-group { margin-bottom: 1.25rem; }
    .form-label { display: block; font-size: 0.85rem; font-weight: 700; color: var(--text-label); margin-bottom: 0.4rem; }
    .form-input, .form-select { width: 100%; padding: 0.85rem; background: var(--bg-input); border: 1px solid var(--border-input); border-radius: 8px; color: var(--text-main); font-size: 0.95rem; transition: border-color 0.2s, background-color 0.2s; }
    .form-input:focus, .form-select:focus { border-color: var(--brand-accent); outline: none; box-shadow: 0 0 0 2px rgba(56,189,248,0.2); }
    .btn { display: inline-flex; align-items: center; justify-content: center; width: 100%; padding: 0.85rem 1.5rem; border-radius: 8px; font-weight: 700; cursor: pointer; border: none; font-size: 0.95rem; transition: all 0.2s ease; text-decoration: none; }
    .btn-primary { background: linear-gradient(135deg, #059669 0%, #10b981 100%); color: var(--text-main); box-shadow: 0 4px 14px rgba(16,185,129,0.3); }
    .btn-primary:hover { filter: brightness(1.1); transform: translateY(-1px); }
    .btn-blue { background: var(--brand-accent-hover); color: var(--text-main); }
    .btn-blue:hover { filter: brightness(1.1); }
    .btn-danger { background: #dc2626; color: var(--text-main); }
    .alert-success { background: rgba(16,185,129,0.15); border: 1px solid #10b981; padding: 1.25rem; border-radius: 8px; color: #10b981; margin-bottom: 1.5rem; }
    [data-theme="light"] .alert-success, [data-theme="nordic"] .alert-success { color: #065f46; background: #ecfdf5; border-color: #6ee7b7; }
    .alert-error { background: rgba(239,68,68,0.15); border: 1px solid #ef4444; padding: 1rem; border-radius: 8px; color: #ef4444; margin-bottom: 1.5rem; }
    [data-theme="light"] .alert-error, [data-theme="nordic"] .alert-error { color: #991b1b; background: #fef2f2; border-color: #fca5a5; }
    .badge { display: inline-block; padding: 0.2rem 0.55rem; border-radius: 9999px; font-size: 0.72rem; font-weight: bold; }
    .badge-red { background: rgba(239,68,68,0.2); color: #ef4444; border: 1px solid #ef4444; }
    .badge-green { background: rgba(16,185,129,0.2); color: #10b981; border: 1px solid #10b981; }
    .badge-amber { background: rgba(245,158,11,0.2); color: #f59e0b; border: 1px solid #f59e0b; }
    .badge-blue { background: rgba(2,132,199,0.2); color: #0284c7; border: 1px solid #0284c7; }
    .dropzone { border: 2px dashed var(--border-input); border-radius: 12px; padding: 2.5rem 1.5rem; text-align: center; background: var(--bg-subtle); cursor: pointer; transition: border-color 0.2s; }
    .dropzone:hover { border-color: var(--brand-accent); }

    /* Selector de Tema en Header */
    .theme-selector-box {
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
      background: var(--select-theme-bg);
      border: 1px solid var(--select-theme-border);
      border-radius: 8px;
      padding: 0.35rem 0.65rem;
      box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    .theme-selector-box select {
      background: transparent;
      border: none;
      color: var(--select-theme-color);
      font-size: 0.8rem;
      font-weight: 700;
      cursor: pointer;
      outline: none;
    }
    .theme-selector-box select option {
      background: var(--select-theme-bg);
      color: var(--select-theme-color);
    }
  </style>
</head>
<body>

  <div class="container">
    
    <header class="header">
      <div class="brand">
        <span>🛡️ Keystone DeepShield</span>
        <span style="font-size:0.75rem; background:linear-gradient(135deg, #0284c7 0%, #0f766e 100%); color:var(--text-main); padding:0.25rem 0.65rem; border-radius:4px; text-transform:uppercase; font-weight:700; letter-spacing:0.5px;">Ciberseguridad & Gobierno Financiero</span>
      </div>
      <div style="display:flex; align-items:center; gap:0.85rem; flex-wrap:wrap;">
        <!-- Selector Reactivo de 4 Temas Visuales (KeyHub Standard) -->
        <div class="theme-selector-box" title="Seleccionar tema visual de la plataforma">
          <span style="font-size:0.85rem;">🎨</span>
          <select id="deepshieldThemeSelector" onchange="changeDeepShieldTheme(this.value)">
            <option value="dark">🌙 Dark Slate</option>
            <option value="light">☀️ Light Corporate</option>
            <option value="nordic">🌊 Nordic Blue</option>
            <option value="graphite">🌿 Graphite</option>
          </select>
        </div>

        <?php if ($tenant): ?>
          <div style="font-size:0.85rem; color:var(--text-muted); display:flex; align-items:center; gap:0.85rem;">
            <span>🏢 <strong><?php echo htmlspecialchars($tenant['company_name']); ?></strong> (<?php echo htmlspecialchars($tenant['treasury_operator_name']); ?>)</span>
            <a href="portal.php?action=logout" style="color:#ef4444; text-decoration:none; font-weight:700;">Cerrar Sesión</a>
          </div>
        <?php endif; ?>
      </div>
    </header>

    <?php if (!$tenant): ?>
      <!-- Formulario de Login para Operador de Tesorería con 2FA Dual -->
      <div style="max-width: 480px; margin: 3rem auto;">
        <div class="card">
          <h2 style="font-size:1.4rem; color:var(--text-main); margin-bottom:0.5rem; text-align:center;">Portal de Ciberseguridad & Gobierno Financiero</h2>
          <p style="font-size:0.85rem; color:var(--text-muted); margin-bottom:1.5rem; text-align:center;">
            Acceda al entorno seguro de emisión y autorización de pagos, padrón de cuentas y monitoreo anti-suplantación de dominio.
          </p>

          <?php if ($loginError): ?>
            <div class="alert-error"><?php echo htmlspecialchars($loginError); ?></div>
          <?php endif; ?>

          <?php if (!empty($loginInfo)): ?>
            <div class="alert-success"><?php echo htmlspecialchars($loginInfo); ?></div>
          <?php endif; ?>

          <?php if ($is2faStep && !empty($_SESSION['ds_2fa_pending'])): ?>
            <?php 
              $pInfo = $_SESSION['ds_2fa_pending'];
              $isEmail = $pInfo['channel'] === 'email';
            ?>
            <!-- PASO 2: DESAFÍO 2FA DUAL WHATSAPP / EMAIL -->
            <div style="background: rgba(16, 185, 129, 0.1); border: 1px solid #10b981; border-radius: 8px; padding: 1rem; margin-bottom: 1.5rem; text-align: center;">
              <span style="display: inline-block; background: #059669; color: white; font-size: 0.72rem; font-weight: 800; padding: 2px 8px; border-radius: 4px; text-transform: uppercase; margin-bottom: 6px;">
                🛡️ Verificación en Dos Pasos (2FA)
              </span>
              <div style="font-size: 0.88rem; color: var(--text-main); font-weight: 600;">
                <?php echo $isEmail ? 'Código enviado a tu correo corporativo' : 'Código enviado a tu WhatsApp'; ?>
              </div>
              <div style="font-size: 0.82rem; color: #38bdf8; margin-top: 3px; font-family: monospace;">
                (<?php echo htmlspecialchars($pInfo['masked_destination']); ?>)
              </div>
            </div>

            <form method="POST">
              <input type="hidden" name="action" value="verify_2fa">
              <div class="form-group" style="text-align: center;">
                <label class="form-label" style="text-align: center;">Ingrese el Código de 6 Dígitos</label>
                <input type="text" name="otp_code" maxlength="6" pattern="[0-9]{6}" required autofocus autocomplete="one-time-code" placeholder="••••••" class="form-input" style="font-size: 1.8rem; letter-spacing: 0.5rem; text-align: center; font-weight: 800; font-family: monospace; color: var(--brand-accent);">
              </div>
              <button type="submit" class="btn btn-primary" style="margin-top: 1rem;">
                Verificar & Acceder al Portal ➜
              </button>
            </form>

            <!-- Acciones Alternativas -->
            <div style="margin-top: 1.25rem; text-align: center; padding: 0.6rem; background: var(--bg-subtle); border-radius: 8px; font-size: 0.82rem;">
              <?php if ($isEmail): ?>
                <?php if (!empty($pInfo['phone'])): ?>
                  <a href="portal.php?resend_2fa=1&channel=whatsapp" style="color: #10b981; text-decoration: none; font-weight: 600;">
                    📲 ¿Prefieres recibirlo en WhatsApp? Enviar al celular
                  </a>
                <?php endif; ?>
              <?php else: ?>
                <a href="portal.php?resend_2fa=1&channel=email" style="color: #38bdf8; text-decoration: none; font-weight: 600;">
                  ✉️ ¿No tienes acceso a tu WhatsApp? Recibir por correo
                </a>
              <?php endif; ?>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 1.25rem; font-size: 0.82rem;">
              <a href="portal.php?resend_2fa=1" style="color: #38bdf8; text-decoration: none; font-weight: 600;">
                🔄 Reenviar Código
              </a>
              <a href="portal.php?cancel_2fa=1" style="color: #ef4444; text-decoration: none; font-weight: 600;">
                ✕ Cancelar
              </a>
            </div>

          <?php else: ?>
            <!-- PASO 1: CREDENCIALES DE TESORERÍA -->
            <form method="POST">
              <input type="hidden" name="action" value="login">
              <div class="form-group">
                <label class="form-label">Identificador de la Empresa (Slug)</label>
                <input type="text" name="tenant_slug" required placeholder="ej: keystone" value="keystone" class="form-input">
              </div>
              <div class="form-group">
                <label class="form-label">Correo Corporativo o Usuario Autorizado</label>
                <input type="text" name="email" required placeholder="contacto@keystoneadvisors.cl o keystone_admin" class="form-input">
              </div>
              <div class="form-group">
                <label class="form-label">Contraseña</label>
                <input type="password" name="password" required placeholder="••••••••••••" class="form-input">
              </div>
              <button type="submit" class="btn btn-primary" style="margin-top:1rem;">
                Ingresar al Portal Seguro ➜
              </button>
            </form>
          <?php endif; ?>
        </div>
      </div>

    <?php else: ?>

      <!-- Pestañas de Navegación del Portal de Tesorería -->
      <div class="tabs-nav">
        <a href="portal.php?tab=single" class="tab-btn <?php echo $activeTab === 'single' ? 'active' : ''; ?>">
          💳 1. Emitir Pago Individual
        </a>
        <a href="portal.php?tab=batch" class="tab-btn <?php echo $activeTab === 'batch' ? 'active' : ''; ?>">
          📥 2. Ingesta Masiva de Nóminas (Batch CSV/TXT)
        </a>
        <a href="portal.php?tab=whitelist" class="tab-btn <?php echo $activeTab === 'whitelist' ? 'active' : ''; ?>">
          🏛️ 3. Padrón de Cuentas Verificadas (Whitelist)
        </a>
        <a href="portal.php?tab=cfo" class="tab-btn <?php echo $activeTab === 'cfo' ? 'active' : ''; ?>" style="border-color:#38bdf8; color:<?php echo $activeTab === 'cfo' ? '#fff' : '#38bdf8'; ?>;">
          📊 4. Panel Ejecutivo CFO & ROI de Fraude
        </a>
        <a href="portal.php?tab=dmarc" class="tab-btn <?php echo $activeTab === 'dmarc' ? 'active' : ''; ?>" style="border-color:#10b981; color:<?php echo $activeTab === 'dmarc' ? '#fff' : '#34d399'; ?>;">
          📡 5. Blindaje de Dominio & DMARC Watchdog
        </a>
      </div>

      <?php if ($feedbackMsg): ?>
        <div class="<?php echo $feedbackMsg['type'] === 'success' ? 'alert-success' : 'alert-error'; ?>">
          <?php echo htmlspecialchars($feedbackMsg['text']); ?>
        </div>
      <?php endif; ?>

      <!-- ========================================== -->
      <!-- TAB 1: EMISIÓN INDIVIDUAL DE PAGO          -->
      <!-- ========================================== -->
      <?php if ($activeTab === 'single'): ?>
        
        <?php if ($orderCreated): ?>
          <div class="alert-success">
            <h3 style="font-size:1.15rem; font-weight:800; margin-bottom:0.5rem; display:flex; align-items:center; gap:0.5rem;">
              <span>✓ Orden Registrada Exitosamente</span>
              <code style="background:#064e3b; padding:0.2rem 0.6rem; border-radius:4px;"><?php echo htmlspecialchars($orderCreated['order_code']); ?></code>
            </h3>
            <p style="font-size:0.9rem; margin-bottom:0.75rem;">
              Se ha emitido el token criptográfico y notificado al directivo por WhatsApp. 
              Desafío Anti-Voz IA: <strong><?php echo htmlspecialchars($orderCreated['security_challenge']); ?></strong>
            </p>
            <div style="display:flex; gap:0.75rem; flex-wrap:wrap;">
              <a href="<?php echo htmlspecialchars($orderCreated['verification_url']); ?>" target="_blank" class="btn btn-primary" style="width:auto; padding:0.5rem 1rem; font-size:0.85rem;">
                👁️ Abrir Pantalla de Autorización Directa ➜
              </a>
              <a href="portal.php?tab=single" class="btn" style="width:auto; background:#1e293b; color:var(--text-label); padding:0.5rem 1rem; font-size:0.85rem;">
                + Cargar Otra Orden
              </a>
            </div>
          </div>
        <?php endif; ?>

        <div class="card">
          <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:1.5rem; flex-wrap:wrap; gap:1rem;">
            <div>
              <h2 style="font-size:1.35rem; color:var(--text-main);">Emitir Pago Crítico o Cambio de Cuenta</h2>
              <p style="font-size:0.85rem; color:var(--text-muted); margin-top:0.25rem;">
                Toda transferencia sobre <strong><?php echo $tenant['threshold_currency']; ?> $<?php echo number_format($tenant['threshold_amount'], 0, ',', '.'); ?></strong> o con cuenta modificada requerirá autorización biométrica obligatoria.
              </p>
            </div>
            <div style="background:var(--bg-subtle); border:1px solid #334e68; border-radius:8px; padding:0.6rem 1rem; font-size:0.8rem; color:var(--text-muted);">
              Umbral Corporativo: <strong style="color:#38bdf8;"><?php echo $tenant['threshold_currency']; ?> $<?php echo number_format($tenant['threshold_amount'], 0, ',', '.'); ?></strong>
            </div>
          </div>

          <form method="POST">
            <input type="hidden" name="action" value="submit_payment">

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem;">
              <div class="form-group">
                <label class="form-label">Tipo de Requerimiento</label>
                <select name="request_type" class="form-select">
                  <option value="PAYMENT_TRANSFER">Transferencia / Pago a Proveedor</option>
                  <option value="BANK_ACCOUNT_CHANGE">Solicitud de Cambio de Cuenta Corriente (Alto Riesgo)</option>
                </select>
              </div>
              <div class="form-group">
                <label class="form-label">Monto de la Transferencia *</label>
                <input type="number" name="amount" required step="1" placeholder="Ej: 3500000" class="form-input">
              </div>
            </div>

            <div style="display:grid; grid-template-columns:1.5fr 1fr; gap:1rem;">
              <div class="form-group">
                <label class="form-label">Beneficiario / Razón Social *</label>
                <input type="text" name="beneficiary_name" required placeholder="Nombre proveedor o empresa" class="form-input">
              </div>
              <div class="form-group">
                <label class="form-label">RUT / Tax ID *</label>
                <input type="text" name="beneficiary_rut_dni" required placeholder="76.123.456-7" class="form-input">
              </div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:1rem;">
              <div class="form-group">
                <label class="form-label">Banco Destino *</label>
                <input type="text" name="bank_name" list="bancosChile" required placeholder="Seleccione o escriba el banco..." class="form-input" autocomplete="off">
              </div>
              <div class="form-group">
                <label class="form-label">Número de Cuenta *</label>
                <input type="text" name="account_number" required placeholder="00-12345-67" class="form-input">
              </div>
              <div class="form-group">
                <label class="form-label">Folio Factura (Opcional)</label>
                <input type="text" name="invoice_folio" placeholder="F-20261" class="form-input">
              </div>
            </div>

            <div style="background:var(--bg-subtle); border:1px solid #334155; padding:1.25rem; border-radius:8px; margin-bottom:1.5rem;">
              <label class="form-label" style="color:#38bdf8; font-size:0.9rem; margin-bottom:0.5rem; display:block;">
                📲 Seleccionar Director Firmante (Aprobador OOB) *
              </label>
              <?php
              $approversList = $tenantService->listApprovers($freshTenant['tenant_slug']);
              ?>
              <select name="approver_id" class="form-select" style="background:var(--bg-card); border-color:#0284c7; color:var(--text-main); font-weight:bold;">
                <?php foreach ($approversList as $appr): ?>
                  <option value="<?php echo $appr['id']; ?>" <?php echo !empty($appr['is_primary']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($appr['full_name']); ?> (<?php echo htmlspecialchars($appr['role_title']); ?>) - Celular: <?php echo htmlspecialchars($appr['phone_whatsapp']); ?> <?php echo !empty($appr['is_primary']) ? '⭐ [Principal]' : ''; ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div style="font-size:0.75rem; color:var(--text-muted); margin-top:0.4rem;">
                El sistema despachará el mensaje interactivo con desafío Anti-Voz IA exclusivamente al celular seleccionado.
              </div>
            </div>

            <button type="submit" class="btn btn-primary">
              🛡️ Emitir Orden y Solicitar Aprobación Fuera de Banda ➜
            </button>
          </form>
        </div>

      <!-- ========================================== -->
      <!-- TAB 2: INGESTA MASIVA DE NÓMINAS (BATCH)  -->
      <!-- ========================================== -->
      <?php elseif ($activeTab === 'batch'): ?>
        
        <div class="card">
          <div style="margin-bottom:1.5rem;">
            <h2 style="font-size:1.35rem; color:var(--text-main);">Ingesta Masiva de Nóminas Bancarias (Batch)</h2>
            <p style="font-size:0.85rem; color:var(--text-muted); margin-top:0.25rem;">
              Cargue el archivo de transferencias descargado de su ERP o portal bancario (Banco Santander, Banco de Chile, BCI, etc.). El motor filtrará automáticamente las transferencias según el umbral corporativo de <strong><?php echo $tenant['threshold_currency']; ?> $<?php echo number_format($tenant['threshold_amount'], 0, ',', '.'); ?></strong> y alertará instantáneamente si detecta cambios de cuentas bancarias respecto al padrón verificado.
            </p>
          </div>

          <!-- Formulario de Subida de Archivo -->
          <form method="POST" enctype="multipart/form-data" style="margin-bottom:2rem;">
            <input type="hidden" name="action" value="parse_batch_file">
            
            <label for="batchFileInput" class="dropzone" style="display:block;">
              <div style="font-size:2.5rem; margin-bottom:0.5rem;">📂</div>
              <div style="font-weight:700; color:#38bdf8; font-size:1rem; margin-bottom:0.35rem;">
                Haga clic aquí para seleccionar el archivo de nómina (.csv / .txt)
              </div>
              <div style="font-size:0.8rem; color:#64748b;">
                Soporta archivos delimitados por coma, punto y coma o tabulador (Columnas: RUT, Proveedor, Banco, Cuenta, Monto, Folio)
              </div>
              <input type="file" id="batchFileInput" name="batch_file" accept=".csv,.txt,.tsv" style="display:none;" onchange="this.form.submit()">
            </label>
          </form>

          <!-- Resultados del Análisis Previo del Archivo -->
          <?php if (!empty($batchResult) && !empty($batchResult['rows'])): ?>
            <div style="border-top:1px solid #1e3a5f; padding-top:1.5rem;">
              <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; flex-wrap:wrap; gap:1rem;">
                <div>
                  <h3 style="font-size:1.15rem; color:var(--text-main);">
                    🔍 Análisis de Nómina: <?php echo $batchResult['total_rows']; ?> Registros Identificados
                  </h3>
                  <p style="font-size:0.8rem; color:var(--text-muted);">
                    Marque las transferencias que desea someter a validación OOB con el Gerente/Firmante.
                  </p>
                </div>
                <div style="display:flex; gap:0.5rem;">
                  <button type="button" onclick="toggleSelectAll(true)" class="btn btn-blue" style="width:auto; padding:0.4rem 0.8rem; font-size:0.8rem;">Marcar Todas</button>
                  <button type="button" onclick="toggleSelectAll(false)" class="btn" style="width:auto; background:#334155; color:var(--text-main); padding:0.4rem 0.8rem; font-size:0.8rem;">Desmarcar</button>
                </div>
              </div>

              <form method="POST">
                <input type="hidden" name="action" value="confirm_batch_ingest">

                <div style="overflow-x:auto; margin-bottom:1.5rem;">
                  <table style="width:100%; border-collapse:collapse; min-width:850px; font-size:0.85rem;">
                    <thead>
                      <tr style="background:var(--bg-subtle); border-bottom:1px solid var(--border-color); text-align:left; color:var(--text-muted); font-size:0.78rem; text-transform:uppercase;">
                        <th style="padding:0.75rem; width:40px; text-align:center;">Sel.</th>
                        <th style="padding:0.75rem;">Beneficiario & RUT</th>
                        <th style="padding:0.75rem;">Monto</th>
                        <th style="padding:0.75rem;">Banco & N° Cuenta</th>
                        <th style="padding:0.75rem;">Cruce Whitelist & Nivel de Riesgo</th>
                        <th style="padding:0.75rem;">Criterio DeepShield</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($batchResult['rows'] as $idx => $r): ?>
                        <tr style="border-bottom:1px solid var(--table-row-border); <?php echo $r['risk']['status'] === 'ACCOUNT_MISMATCH' ? 'background:rgba(239,68,68,0.1);' : ''; ?>">
                          <td style="padding:0.75rem; text-align:center;">
                            <input type="checkbox" name="selected_rows[]" value="<?php echo $idx; ?>" class="batch-checkbox" <?php echo $r['requires_oob'] ? 'checked' : ''; ?> style="width:18px; height:18px; accent-color:#0284c7;">
                          </td>
                          <td style="padding:0.75rem;">
                            <strong style="color:var(--text-main);"><?php echo htmlspecialchars($r['beneficiary_name']); ?></strong><br>
                            <code style="color:var(--text-muted); font-size:0.75rem;"><?php echo htmlspecialchars($r['clean_rut']); ?></code>
                            <?php if (!empty($r['invoice_folio'])): ?>
                              <span style="color:#64748b; font-size:0.75rem;">(Fac: <?php echo htmlspecialchars($r['invoice_folio']); ?>)</span>
                            <?php endif; ?>
                          </td>
                          <td style="padding:0.75rem; font-weight:bold; color:<?php echo $r['is_above_threshold'] ? '#f87171' : '#34d399'; ?>;">
                            $<?php echo number_format($r['amount'], 0, ',', '.'); ?>
                          </td>
                          <td style="padding:0.75rem;">
                            <?php echo htmlspecialchars($r['bank_name']); ?><br>
                            <code style="color:#38bdf8; font-size:0.75rem;"><?php echo htmlspecialchars($r['clean_account']); ?></code>
                          </td>
                          <td style="padding:0.75rem;">
                            <?php if ($r['risk']['status'] === 'MATCH'): ?>
                              <span class="badge badge-green"><?php echo htmlspecialchars($r['risk']['label']); ?></span>
                            <?php elseif ($r['risk']['status'] === 'ACCOUNT_MISMATCH'): ?>
                              <span class="badge badge-red"><?php echo htmlspecialchars($r['risk']['label']); ?></span><br>
                              <span style="color:#fca5a5; font-size:0.72rem;"><?php echo htmlspecialchars($r['risk']['reason']); ?></span>
                            <?php else: ?>
                              <span class="badge badge-amber"><?php echo htmlspecialchars($r['risk']['label']); ?></span>
                            <?php endif; ?>
                          </td>
                          <td style="padding:0.75rem;">
                            <?php if ($r['risk']['status'] === 'ACCOUNT_MISMATCH'): ?>
                              <strong style="color:#ef4444; font-size:0.75rem;">🚨 REQUIERE OOB (ALERTA BEC)</strong>
                            <?php elseif ($r['is_above_threshold']): ?>
                              <strong style="color:#f59e0b; font-size:0.75rem;">⏳ SUPERA UMBRAL (&gt; $<?php echo number_format($batchResult['threshold'], 0, ',', '.'); ?>)</strong>
                            <?php elseif ($r['risk']['status'] === 'NEW_BENEFICIARY'): ?>
                              <span style="color:#38bdf8; font-size:0.75rem;">ℹ️ Proveedor No Registrado</span>
                            <?php else: ?>
                              <span style="color:#10b981; font-size:0.75rem;">✓ Bajo Umbral & Confiable</span>
                            <?php endif; ?>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>

                <div style="background:var(--bg-subtle); border:1px solid var(--border-color); padding:1.25rem; border-radius:8px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem;">
                  <div style="flex:1; min-width:280px;">
                    <h4 style="color:var(--text-main); font-size:0.95rem; margin-bottom:0.35rem;">Director Firmante Asignado para este Lote:</h4>
                    <select name="batch_approver_id" class="form-select" style="background:var(--bg-card); border-color:#0284c7; color:var(--text-main); font-weight:bold; max-width:480px;">
                      <?php
                      $batchApprovers = $tenantService->listApprovers($freshTenant['tenant_slug']);
                      foreach ($batchApprovers as $ba):
                      ?>
                        <option value="<?php echo $ba['id']; ?>" <?php echo !empty($ba['is_primary']) ? 'selected' : ''; ?>>
                          <?php echo htmlspecialchars($ba['full_name']); ?> (<?php echo htmlspecialchars($ba['role_title']); ?>) - <?php echo htmlspecialchars($ba['phone_whatsapp']); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <span style="color:var(--text-muted); font-size:0.75rem; display:block; margin-top:0.25rem;">Se despacharán las solicitudes de aprobación fuera de banda a este directivo.</span>
                  </div>
                  <button type="submit" class="btn btn-primary" style="width:auto; padding:0.75rem 1.75rem;">
                    🚀 Procesar Órdenes Seleccionadas en DeepShield ➜
                  </button>
                </div>
              </form>
            </div>
          <?php endif; ?>

        </div>

      <!-- ========================================== -->
      <!-- TAB 3: PADRÓN DE CUENTAS VERIFICADAS       -->
      <!-- ========================================== -->
      <?php elseif ($activeTab === 'whitelist'): ?>
        
        <?php
        $searchWl = trim($_GET['q_wl'] ?? '');
        $trustedList = $batchService->listBeneficiaries($freshTenant['tenant_slug'], $searchWl);
        ?>

        <div class="card">
          <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem; margin-bottom:1.5rem;">
            <div>
              <h2 style="font-size:1.35rem; color:var(--text-main);">Padrón de Cuentas Bancarias Verificadas (Whitelist)</h2>
              <p style="font-size:0.85rem; color:var(--text-muted); margin-top:0.25rem;">
                Cuentas bancarias oficiales de proveedores recurrentes. Toda nómina o pago que intente transferir a un RUT existente pero con una cuenta distinta disparará la <strong>Alerta Roja BEC</strong>.
              </p>
            </div>
            <button type="button" onclick="document.getElementById('modalAddBeneficiary').style.display='flex'" class="btn btn-blue" style="width:auto; padding:0.6rem 1.25rem;">
              + Registrar Proveedor Confiable
            </button>
          </div>

          <!-- Buscador de Whitelist -->
          <form method="GET" style="display:flex; gap:0.5rem; margin-bottom:1.25rem;">
            <input type="hidden" name="tab" value="whitelist">
            <input type="text" name="q_wl" value="<?php echo htmlspecialchars($searchWl); ?>" placeholder="Buscar por RUT, Nombre de Proveedor, Banco o Cuenta..." class="form-input" style="max-width:400px; padding:0.5rem 0.85rem; font-size:0.85rem;">
            <button type="submit" class="btn btn-primary" style="width:auto; padding:0.5rem 1rem; font-size:0.85rem;">Buscar 🔍</button>
            <?php if (!empty($searchWl)): ?>
              <a href="portal.php?tab=whitelist" class="btn" style="width:auto; background:#334155; color:var(--text-main); padding:0.5rem 1rem; font-size:0.85rem;">Limpiar</a>
            <?php endif; ?>
          </form>

          <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; min-width:850px; font-size:0.85rem;">
              <thead>
                <tr style="background:var(--bg-subtle); border-bottom:1px solid var(--border-color); text-align:left; color:var(--text-muted); font-size:0.78rem; text-transform:uppercase;">
                  <th style="padding:0.75rem;">RUT / Tax ID</th>
                  <th style="padding:0.75rem;">Razón Social / Proveedor</th>
                  <th style="padding:0.75rem;">Banco Oficial</th>
                  <th style="padding:0.75rem;">N° Cuenta Corriente</th>
                  <th style="padding:0.75rem;">Verificado Por</th>
                  <th style="padding:0.75rem; text-align:center;">Acción</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($trustedList)): ?>
                  <tr>
                    <td colspan="6" style="padding:2rem; text-align:center; color:#64748b;">
                      No hay proveedores registrados en el padrón verificado aún. Agregue el primero haciendo clic en <strong>+ Registrar Proveedor Confiable</strong>.
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($trustedList as $b): ?>
                    <tr style="border-bottom:1px solid var(--table-row-border);">
                      <td style="padding:0.75rem; font-weight:bold; color:#38bdf8;"><code><?php echo htmlspecialchars($b['rut_dni']); ?></code></td>
                      <td style="padding:0.75rem; font-weight:bold; color:var(--text-main);"><?php echo htmlspecialchars($b['business_name']); ?></td>
                      <td style="padding:0.75rem; color:var(--text-label);"><?php echo htmlspecialchars($b['bank_name']); ?></td>
                      <td style="padding:0.75rem; font-family:monospace; color:#34d399;"><?php echo htmlspecialchars($b['account_number']); ?></td>
                      <td style="padding:0.75rem; color:var(--text-muted); font-size:0.78rem;">
                        <?php echo htmlspecialchars($b['verified_by']); ?><br>
                        <span style="font-size:0.7rem; color:#64748b;"><?php echo htmlspecialchars(substr($b['created_at'], 0, 10)); ?></span>
                      </td>
                      <td style="padding:0.75rem; text-align:center;">
                        <form method="POST" onsubmit="return confirm('¿Está seguro de eliminar este proveedor de la lista de confianza?');" style="display:inline;">
                          <input type="hidden" name="action" value="delete_whitelist_beneficiary">
                          <input type="hidden" name="beneficiary_id" value="<?php echo $b['id']; ?>">
                          <button type="submit" class="btn btn-danger" style="width:auto; padding:0.25rem 0.6rem; font-size:0.75rem;">🗑️ Quitar</button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

        </div>

        <!-- Modal para Registrar Nuevo Proveedor Verificado -->
        <div id="modalAddBeneficiary" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:9999; align-items:center; justify-content:center; padding:1.5rem;">
          <div style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:14px; padding:2rem; max-width:540px; width:100%; box-shadow:0 20px 40px rgba(0,0,0,0.6);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.25rem;">
              <h3 style="color:var(--text-main); font-size:1.2rem;">🏛️ Registrar Proveedor en Whitelist</h3>
              <button type="button" onclick="document.getElementById('modalAddBeneficiary').style.display='none'" style="background:none; border:none; color:var(--text-muted); font-size:1.5rem; cursor:pointer;">&times;</button>
            </div>
            
            <form method="POST">
              <input type="hidden" name="action" value="save_whitelist_beneficiary">
              
              <div class="form-group">
                <label class="form-label">RUT del Proveedor *</label>
                <input type="text" name="rut_dni" required placeholder="76.938.743-9" class="form-input">
              </div>

              <div class="form-group">
                <label class="form-label">Razón Social Oficial *</label>
                <input type="text" name="business_name" required placeholder="Ej: Servicios Tecnológicos SpA" class="form-input">
              </div>

              <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem;">
                <div class="form-group">
                  <label class="form-label">Banco Oficial *</label>
                  <input type="text" name="bank_name" list="bancosChile" required placeholder="Banco Santander, BCI..." class="form-input">
                </div>
                <div class="form-group">
                  <label class="form-label">N° de Cuenta Oficial *</label>
                  <input type="text" name="account_number" required placeholder="11223344" class="form-input">
                </div>
              </div>

              <div class="form-group">
                <label class="form-label">Correo de Notificación del Proveedor</label>
                <input type="email" name="contact_email" placeholder="facturacion@proveedor.cl" class="form-input">
              </div>

              <div style="display:flex; gap:0.75rem; margin-top:1.5rem;">
                <button type="button" onclick="document.getElementById('modalAddBeneficiary').style.display='none'" class="btn" style="background:#334155; color:var(--text-main);">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar en Whitelist ➜</button>
              </div>
            </form>
          </div>
        </div>

      <!-- ========================================== -->
      <!-- TAB 4: PANEL EJECUTIVO CFO & ROI DE FRAUDE -->
      <!-- ========================================== -->
      <?php elseif ($activeTab === 'cfo'): ?>
        
        <?php
        $cfoData = $tenantService->getExecutiveMetrics($freshTenant['tenant_slug']);
        $stats = $cfoData['stats'];
        $recentFrauds = $cfoData['recent_frauds'];
        $trustedCount = $cfoData['trusted_beneficiaries_count'];
        $curr = $freshTenant['threshold_currency'] ?? 'CLP';
        ?>

        <div class="card" style="background: var(--bg-card-gradient); border-color: var(--border-color);">
          <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem; margin-bottom:1.75rem; border-bottom:1px solid #1e3a5f; padding-bottom:1.25rem;">
            <div>
              <div style="display:inline-flex; align-items:center; gap:0.5rem; background:rgba(56,189,248,0.1); border:1px solid #0284c7; padding:0.25rem 0.75rem; border-radius:9999px; margin-bottom:0.5rem;">
                <span style="font-size:0.75rem; font-weight:800; color:#38bdf8; text-transform:uppercase;">Vista de Gobierno & CFO</span>
              </div>
              <h2 style="font-size:1.45rem; color:var(--text-main); font-weight:800;">Tablero de Ciberseguridad Financiera & ROI de Fraude</h2>
              <p style="font-size:0.85rem; color:var(--text-muted); margin-top:0.25rem;">
                Monitoreo consolidado de capital protegido, tasa de desvío mitigada y auditoría de incidentes BEC para <strong><?php echo htmlspecialchars($freshTenant['company_name']); ?></strong>.
              </p>
            </div>
            <div style="text-align:right;">
              <span style="font-size:0.75rem; color:#64748b; display:block;">Director / CFO Titular:</span>
              <strong style="color:var(--text-main); font-size:0.95rem;"><?php echo htmlspecialchars($freshTenant['primary_approver_name']); ?></strong>
              <span style="font-size:0.75rem; color:#38bdf8; display:block;"><?php echo htmlspecialchars($freshTenant['primary_approver_phone']); ?></span>
            </div>
          </div>

          <!-- Cuadrícula de KPIs Financieros -->
          <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap:1.25rem; margin-bottom:2rem;">
            
            <!-- KPI 1: Capital en Fraude Interceptado -->
            <div style="background:#1e1b2e; border:1px solid #7f1d1d; border-radius:12px; padding:1.25rem; box-shadow:0 4px 15px rgba(220,38,38,0.15);">
              <div style="font-size:0.75rem; font-weight:700; color:#f87171; text-transform:uppercase; margin-bottom:0.4rem; display:flex; justify-content:space-between;">
                <span>🛡️ Fraude Interceptado (ROI)</span>
                <span class="badge badge-red"><?php echo $stats['rejected_orders']; ?> Casos</span>
              </div>
              <div style="font-size:1.6rem; font-weight:900; color:#ef4444; letter-spacing:-0.5px;">
                <?php echo $curr; ?> $<?php echo number_format($stats['fraud_prevented_amount'], 0, ',', '.'); ?>
              </div>
              <div style="font-size:0.75rem; color:#fca5a5; margin-top:0.35rem;">
                Monto salvaguardado y bloqueado antes del desembolso bancario.
              </div>
            </div>

            <!-- KPI 2: Total Pagos Protegidos -->
            <div style="background:#09251e; border:1px solid #065f46; border-radius:12px; padding:1.25rem;">
              <div style="font-size:0.75rem; font-weight:700; color:#34d399; text-transform:uppercase; margin-bottom:0.4rem; display:flex; justify-content:space-between;">
                <span>✓ Pagos Autorizados OOB</span>
                <span class="badge badge-green"><?php echo $stats['approved_orders']; ?> Aprobados</span>
              </div>
              <div style="font-size:1.6rem; font-weight:900; color:#10b981; letter-spacing:-0.5px;">
                <?php echo $curr; ?> $<?php echo number_format($stats['approved_volume'], 0, ',', '.'); ?>
              </div>
              <div style="font-size:0.75rem; color:#6ee7b7; margin-top:0.35rem;">
                Transferencias firmadas con sello SHA-256 inmutable.
              </div>
            </div>

            <!-- KPI 3: Volumen Total Monitoreado -->
            <div style="background:var(--bg-subtle); border:1px solid var(--border-color); border-radius:12px; padding:1.25rem;">
              <div style="font-size:0.75rem; font-weight:700; color:#38bdf8; text-transform:uppercase; margin-bottom:0.4rem; display:flex; justify-content:space-between;">
                <span>💼 Volumen Total Evaluado</span>
                <span class="badge badge-blue"><?php echo $stats['total_orders']; ?> Órdenes</span>
              </div>
              <div style="font-size:1.6rem; font-weight:900; color:var(--text-main); letter-spacing:-0.5px;">
                <?php echo $curr; ?> $<?php echo number_format($stats['total_volume'], 0, ',', '.'); ?>
              </div>
              <div style="font-size:0.75rem; color:var(--text-muted); margin-top:0.35rem;">
                Umbral de corte: <?php echo $curr; ?> $<?php echo number_format($freshTenant['threshold_amount'], 0, ',', '.'); ?>
              </div>
            </div>

            <!-- KPI 4: Whitelist Activa -->
            <div style="background:var(--bg-subtle); border:1px solid var(--border-color); border-radius:12px; padding:1.25rem;">
              <div style="font-size:0.75rem; font-weight:700; color:var(--text-label); text-transform:uppercase; margin-bottom:0.4rem;">
                <span>🏛️ Proveedores Verificados</span>
              </div>
              <div style="font-size:1.6rem; font-weight:900; color:#38bdf8; letter-spacing:-0.5px;">
                <?php echo $trustedCount; ?> Empresas
              </div>
              <div style="font-size:0.75rem; color:var(--text-muted); margin-top:0.35rem;">
                Cuentas blindadas contra alteración no autorizada.
              </div>
            </div>

          </div>

          <!-- Bitácora Ejecutiva de Intentos de Fraude Interceptados -->
          <div style="background:var(--bg-subtle); border:1px solid var(--border-color); border-radius:12px; padding:1.5rem;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; flex-wrap:wrap; gap:0.5rem;">
              <h3 style="color:var(--text-main); font-size:1.1rem; display:flex; align-items:center; gap:0.5rem;">
                <span>🚨 Registro de Incidentes de Fraude Interceptados (Cadena Pericial)</span>
              </h3>
              <span style="font-size:0.75rem; color:var(--text-muted);">Documentos periciales con validez jurídica según Ley N° 21.459</span>
            </div>

            <?php if (empty($recentFrauds)): ?>
              <div style="padding:2rem; text-align:center; color:#64748b; font-size:0.9rem;">
                🛡️ Cero incidentes de fraude registrados. Sus operaciones de tesorería se encuentran limpias y protegidas.
              </div>
            <?php else: ?>
              <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; font-size:0.85rem;">
                  <thead>
                    <tr style="background:var(--bg-input); border-bottom:1px solid var(--border-color); text-align:left; color:var(--text-muted); font-size:0.75rem; text-transform:uppercase;">
                      <th style="padding:0.75rem;">Código & Fecha</th>
                      <th style="padding:0.75rem;">Monto Resguardado</th>
                      <th style="padding:0.75rem;">Beneficiario Detectado</th>
                      <th style="padding:0.75rem;">Cuenta Destino Anómala</th>
                      <th style="padding:0.75rem;">Motivo del Bloqueo</th>
                      <th style="padding:0.75rem; text-align:center;">Dosier Pericial</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($recentFrauds as $f): ?>
                      <tr style="border-bottom:1px solid var(--table-row-border); background:rgba(239,68,68,0.04);">
                        <td style="padding:0.75rem;">
                          <strong style="color:#f87171;"><code><?php echo htmlspecialchars($f['order_code']); ?></code></strong><br>
                          <span style="font-size:0.72rem; color:var(--text-muted);"><?php echo htmlspecialchars(substr($f['created_at'], 0, 16)); ?></span>
                        </td>
                        <td style="padding:0.75rem; font-weight:bold; color:#ef4444; font-size:0.95rem;">
                          <?php echo htmlspecialchars($f['currency']); ?> $<?php echo number_format($f['amount'], 0, ',', '.'); ?>
                        </td>
                        <td style="padding:0.75rem;">
                          <strong style="color:var(--text-main);"><?php echo htmlspecialchars($f['beneficiary_name']); ?></strong><br>
                          <code style="color:var(--text-muted); font-size:0.72rem;"><?php echo htmlspecialchars($f['beneficiary_rut_dni']); ?></code>
                        </td>
                        <td style="padding:0.75rem;">
                          <?php echo htmlspecialchars($f['bank_name']); ?><br>
                          <code style="color:#f87171; font-size:0.72rem;"><?php echo htmlspecialchars($f['account_number']); ?></code>
                        </td>
                        <td style="padding:0.75rem; font-size:0.75rem; color:var(--text-label);">
                          <?php echo htmlspecialchars($f['audit_notes'] ?: 'Bloqueo ejecutado por directivo tras desconocer la instrucción de pago.'); ?>
                        </td>
                        <td style="padding:0.75rem; text-align:center;">
                          <a href="/shield/verify.php?token=<?php echo htmlspecialchars($f['token_hash']); ?>" target="_blank" class="btn btn-blue" style="width:auto; padding:0.3rem 0.75rem; font-size:0.75rem; font-weight:bold;">
                            📄 Ver Evidencia SHA-256
                          </a>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>

          </div>

          <!-- Certificación y Cumplimiento Zero-Trust -->
          <div style="margin-top:1.5rem; background:rgba(2,132,199,0.06); border:1px solid #0284c7; border-radius:10px; padding:1.25rem; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:1rem;">
            <div>
              <h4 style="color:#38bdf8; font-size:0.95rem; margin-bottom:0.25rem;">Certificado de Gobernanza y Separación de Funciones</h4>
              <p style="font-size:0.8rem; color:var(--text-muted);">
                DeepShield opera bajo arquitectura Zero-Trust, garantizando la inmutabilidad de la cadena de custodia criptográfica exigida por auditores externos, la Ley N° 21.459 y estándares ISO 27001.
              </p>
            </div>
            <a href="portal.php?tab=batch" class="btn btn-primary" style="width:auto; padding:0.6rem 1.25rem; font-size:0.85rem;">
              + Procesar Nueva Nómina Segura
            </a>
          </div>

        </div>

      <!-- ======================================================== -->
      <!-- TAB 5: BLINDAJE DE DOMINIO & DMARC WATCHDOG (AUTOSERVICIO) -->
      <!-- ======================================================== -->
      <?php elseif ($activeTab === 'dmarc'): ?>

        <?php
        if ($isConsolidated) {
            $filterTarget = !empty($myDomains) ? array_column($myDomains, 'domain') : [$dmarcTenant['domain']];
            $displayTitle = "Consolidado de Dominios Protegidos (" . count($myDomains) . ")";
            $policyMode = 'reject';
        } else {
            $filterTarget = $activeDomainName;
            $displayTitle = "@" . $activeDomainName;
            $policyMode = $selectedDomainObj['policy_mode'] ?? ($dmarcTenant['policy_mode'] ?? 'reject');
        }

        $myMetrics = $dmarcWatchdog->getMetrics($filterTarget);
        $totalVol = (int)($myMetrics['total_volume'] ?? 0);
        $totalPass = (int)($myMetrics['total_pass'] ?? 0);
        $totalFail = (int)($myMetrics['total_fail'] ?? 0);
        $passRate = (float)($myMetrics['pass_rate'] ?? 100.0);
        $topSpoofers = $myMetrics['top_spoofers'] ?? [];
        $topLegit = $myMetrics['top_legit'] ?? [];
        $centralRua = $dmarcWatchdog->getCentralRuaEmail();
        $txtRecord = "v=DMARC1; p={$policyMode}; pct=100; rua=mailto:{$centralRua}; aspf=r; adkim=r;";
        ?>

        <!-- BARRA SELECTORA DE DOMINIOS (MULTI-DOMINIO) -->
        <?php if (count($myDomains) > 1): ?>
          <div style="background:var(--bg-subtle); border:1px solid var(--border-color); border-radius:10px; padding:0.75rem 1rem; margin-bottom:1.5rem; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:0.75rem;">
            <div style="display:flex; align-items:center; gap:0.5rem; font-size:0.85rem; color:var(--text-muted);">
              <span style="font-size:1.1rem;">🌐</span>
              <strong style="color:var(--text-main);">Dominios de <?php echo htmlspecialchars($freshTenant['company_name']); ?>:</strong>
            </div>
            <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
              <a href="portal.php?tab=dmarc&domain=all" style="padding:0.4rem 0.85rem; border-radius:6px; font-size:0.8rem; font-weight:700; text-decoration:none; transition:all 0.2s; <?php echo $isConsolidated ? 'background:#0284c7; color:var(--text-main); box-shadow:0 2px 8px rgba(2,132,199,0.4);' : 'background:var(--bg-card); color:var(--text-muted); border:1px solid #334e68;'; ?>">
                📊 Consolidado Global (<?php echo count($myDomains); ?>)
              </a>
              <?php foreach ($myDomains as $d): ?>
                <?php $isThisActive = (!$isConsolidated && strtolower($activeDomainName) === strtolower($d['domain'])); ?>
                <a href="portal.php?tab=dmarc&domain=<?php echo urlencode($d['domain']); ?>" style="padding:0.4rem 0.85rem; border-radius:6px; font-size:0.8rem; font-weight:700; text-decoration:none; transition:all 0.2s; <?php echo $isThisActive ? 'background:#10b981; color:var(--text-main); box-shadow:0 2px 8px rgba(16,185,129,0.4);' : 'background:var(--bg-card); color:var(--text-muted); border:1px solid #334e68;'; ?>">
                  @<?php echo htmlspecialchars($d['domain']); ?>
                  <?php if (!empty($d['is_primary'])): ?><span style="font-size:0.65rem; opacity:0.8;">★</span><?php endif; ?>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <div class="card" style="background: var(--bg-card-dmarc); border-color: var(--border-color);">
          <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem; margin-bottom:1.75rem; border-bottom:1px solid #1e3a5f; padding-bottom:1.25rem;">
            <div>
              <div style="display:inline-flex; align-items:center; gap:0.5rem; background:rgba(16,185,129,0.12); border:1px solid #10b981; padding:0.25rem 0.75rem; border-radius:9999px; margin-bottom:0.5rem;">
                <span style="font-size:0.75rem; font-weight:800; color:#34d399; text-transform:uppercase;">🛰️ Telemetría DMARC / RFC 7489 en Vivo</span>
              </div>
              <h2 style="font-size:1.45rem; color:var(--text-main); font-weight:800;">Blindaje Anti-Suplantación de Identidad y Dominio</h2>
              <p style="font-size:0.85rem; color:var(--text-muted); margin-top:0.25rem;">
                Monitoreo activo de servidores mundiales que emiten correos en nombre de <strong><?php echo htmlspecialchars($displayTitle); ?></strong>.
              </p>
            </div>
            
            <div style="display:flex; gap:0.6rem; align-items:center; flex-wrap:wrap;">
              <?php if ($dmarcTenant): ?>
                <a href="portal.php?tab=dmarc&action=download_my_dossier&domain=<?php echo urlencode($isConsolidated ? "all" : $activeDomainName); ?>" target="_blank" class="btn" style="width:auto; background:linear-gradient(135deg, #0284c7 0%, #0369a1 100%); color:var(--text-main); padding:0.6rem 1.15rem; font-size:0.85rem; box-shadow:0 4px 12px rgba(2,132,199,0.3);">
                  📥 Descargar Dosier Mensual PDF (<?php echo date('m/Y'); ?>)
                </a>
              <?php endif; ?>
              <div style="background:var(--bg-subtle); border:1px solid #334e68; border-radius:8px; padding:0.4rem 0.8rem; text-align:right;">
                <span style="font-size:0.7rem; color:#64748b; display:block;">Política Activa:</span>
                <strong style="color:<?php echo $policyMode === 'reject' ? '#34d399' : '#fbbf24'; ?>; font-size:0.85rem; text-transform:uppercase;">
                  🛡️ p=<?php echo htmlspecialchars($policyMode); ?> (<?php echo $policyMode === 'reject' ? 'Bloqueo Total' : 'Cuarentena'; ?>)
                </strong>
              </div>
            </div>
          </div>

          <!-- Cuadrícula de KPIs de Salud del Dominio -->
          <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:1.25rem; margin-bottom:2rem;">
            
            <!-- KPI 1: Tasa de Alineación Legítima -->
            <div style="background:#09251e; border:1px solid #065f46; border-radius:12px; padding:1.25rem;">
              <div style="font-size:0.75rem; font-weight:700; color:#34d399; text-transform:uppercase; margin-bottom:0.4rem; display:flex; justify-content:space-between;">
                <span>✓ Alineación DMARC</span>
                <span class="badge badge-green"><?php echo $passRate; ?>%</span>
              </div>
              <div style="font-size:1.7rem; font-weight:900; color:#10b981; letter-spacing:-0.5px;">
                <?php echo number_format($totalPass); ?>
              </div>
              <div style="font-size:0.75rem; color:#6ee7b7; margin-top:0.35rem;">
                Correos corporativos 100% auténticos y entregados.
              </div>
            </div>

            <!-- KPI 2: Ataques de Suplantación Interceptados -->
            <div style="background:#1e1b2e; border:1px solid #7f1d1d; border-radius:12px; padding:1.25rem; box-shadow:0 4px 15px rgba(220,38,38,0.15);">
              <div style="font-size:0.75rem; font-weight:700; color:#f87171; text-transform:uppercase; margin-bottom:0.4rem; display:flex; justify-content:space-between;">
                <span>🔴 Suplantaciones Interceptadas</span>
                <span class="badge badge-red"><?php echo count($topSpoofers); ?> Fuentes</span>
              </div>
              <div style="font-size:1.7rem; font-weight:900; color:#ef4444; letter-spacing:-0.5px;">
                <?php echo number_format($totalFail); ?>
              </div>
              <div style="font-size:0.75rem; color:#fca5a5; margin-top:0.35rem;">
                Intentos de phishing y BEC rechazados en servidores de destino.
              </div>
            </div>

            <!-- KPI 3: Volumen Evaluado -->
            <div style="background:var(--bg-subtle); border:1px solid var(--border-color); border-radius:12px; padding:1.25rem;">
              <div style="font-size:0.75rem; font-weight:700; color:#38bdf8; text-transform:uppercase; margin-bottom:0.4rem; display:flex; justify-content:space-between;">
                <span>📨 Tráfico Evaluado</span>
                <span class="badge badge-blue">Global</span>
              </div>
              <div style="font-size:1.7rem; font-weight:900; color:var(--text-main); letter-spacing:-0.5px;">
                <?php echo number_format($totalVol); ?>
              </div>
              <div style="font-size:0.75rem; color:var(--text-muted); margin-top:0.35rem;">
                Mensajes auditados vía telemetría Google y Microsoft.
              </div>
            </div>

            <!-- KPI 4: Estado del Blindaje -->
            <div style="background:var(--bg-subtle); border:1px solid var(--border-color); border-radius:12px; padding:1.25rem;">
              <div style="font-size:0.75rem; font-weight:700; color:var(--text-label); text-transform:uppercase; margin-bottom:0.4rem;">
                <span>🛡️ Estado de Seguridad</span>
              </div>
              <div style="font-size:1.35rem; font-weight:900; color:#38bdf8; letter-spacing:-0.5px;">
                Dominio Blindado
              </div>
              <div style="font-size:0.75rem; color:var(--text-muted); margin-top:0.35rem;">
                Vigilancia 24/7 y respuesta activa por Keystone SOC.
              </div>
            </div>

          </div>

          <!-- Tabla de Atacantes e Intentos de Suplantación Interceptados -->
          <div style="background:var(--bg-subtle); border:1px solid var(--border-color); border-radius:12px; padding:1.5rem; margin-bottom:1.5rem;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; flex-wrap:wrap; gap:0.5rem;">
              <h3 style="color:var(--text-main); font-size:1.1rem; display:flex; align-items:center; gap:0.5rem;">
                <span>🚨 Servidores Agresores Interceptados (Suplantación @<?php echo htmlspecialchars($myDomain); ?>)</span>
              </h3>
              <span style="font-size:0.75rem; color:var(--text-muted);">Los correos emitidos por estos IPs fueron destruidos o rechazados por DMARC</span>
            </div>

            <?php if (empty($topSpoofers)): ?>
              <div style="padding:2.5rem; text-align:center; color:#64748b; font-size:0.9rem;">
                🛡️ Felicitaciones. No se registran servidores agresores intentando suplantar a <strong>@<?php echo htmlspecialchars($myDomain); ?></strong> en este periodo.
              </div>
            <?php else: ?>
              <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; font-size:0.85rem;">
                  <thead>
                    <tr style="background:var(--bg-input); border-bottom:1px solid var(--border-color); text-align:left; color:var(--text-muted); font-size:0.75rem; text-transform:uppercase;">
                      <th style="padding:0.75rem;">IP Remitente & Datacenter</th>
                      <th style="padding:0.75rem;">País de Origen</th>
                      <th style="padding:0.75rem; text-align:center;">Volumen Bloqueado</th>
                      <th style="padding:0.75rem; text-align:center;">Autenticación SPF / DKIM</th>
                      <th style="padding:0.75rem; text-align:center;">Acción Pericial</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($topSpoofers as $sp): ?>
                      <tr style="border-bottom:1px solid var(--table-row-border); background:rgba(239,68,68,0.04);">
                        <td style="padding:0.75rem;">
                          <code style="color:#f87171; font-weight:bold; font-size:0.95rem; background:#18181b; padding:0.15rem 0.4rem; border-radius:4px;"><?php echo htmlspecialchars($sp['source_ip']); ?></code><br>
                          <span style="font-size:0.75rem; color:var(--text-muted);"><?php echo htmlspecialchars($sp['org'] ?? 'Proveedor / ISP no catalogado'); ?></span>
                        </td>
                        <td style="padding:0.75rem;">
                          <strong style="color:var(--text-main); font-size:0.85rem;">🌍 <?php echo htmlspecialchars($sp['country'] ?? 'Desconocido'); ?></strong>
                          <?php if (!empty($sp['country_code'])): ?>
                            <span style="color:#64748b; font-size:0.75rem;">(<?php echo htmlspecialchars($sp['country_code']); ?>)</span>
                          <?php endif; ?>
                        </td>
                        <td style="padding:0.75rem; text-align:center; font-weight:900; color:#ef4444; font-size:1rem;">
                          <?php echo number_format($sp['total_attempts']); ?> msgs
                        </td>
                        <td style="padding:0.75rem; text-align:center;">
                          <span class="badge badge-red" style="font-size:0.68rem; margin-bottom:0.2rem; display:inline-block;">SPF: <?php echo strtoupper($sp['spf_res'] ?? 'FAIL'); ?></span><br>
                          <span class="badge badge-red" style="font-size:0.68rem;">DKIM: <?php echo strtoupper($sp['dkim_res'] ?? 'FAIL'); ?></span>
                        </td>
                        <td style="padding:0.75rem; text-align:center;">
                          <a href="portal.php?tab=dmarc&action=download_my_pdi_cert&ip=<?php echo urlencode($sp['source_ip']); ?>&attempts=<?php echo (int)$sp['total_attempts']; ?>" target="_blank" class="btn" style="width:auto; background:#1e293b; color:#38bdf8; border:1px solid #0284c7; padding:0.35rem 0.75rem; font-size:0.75rem; font-weight:700;">
                            ⚖️ Certificado PDI / Fiscalía
                          </a>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>

          </div>

          <!-- Fuentes Legítimas Autorizadas -->
          <div style="background:var(--bg-subtle); border:1px solid var(--border-color); border-radius:12px; padding:1.5rem; margin-bottom:1.5rem;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; flex-wrap:wrap; gap:0.5rem;">
              <h3 style="color:var(--text-main); font-size:1.1rem; display:flex; align-items:center; gap:0.5rem;">
                <span>✓ Servidores Legítimos Autorizados (@<?php echo htmlspecialchars($myDomain); ?>)</span>
              </h3>
              <span style="font-size:0.75rem; color:#6ee7b7;">IPs que pasaron exitosamente los filtros SPF y DKIM</span>
            </div>

            <?php if (empty($topLegit)): ?>
              <div style="padding:2rem; text-align:center; color:#64748b; font-size:0.85rem;">
                Esperando primera telemetría legítima agregada de Google Workspace o Microsoft 365.
              </div>
            <?php else: ?>
              <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; font-size:0.85rem;">
                  <thead>
                    <tr style="background:var(--bg-input); border-bottom:1px solid var(--border-color); text-align:left; color:var(--text-muted); font-size:0.75rem; text-transform:uppercase;">
                      <th style="padding:0.65rem 0.75rem;">IP Autorizada</th>
                      <th style="padding:0.65rem 0.75rem;">Organización / Infraestructura</th>
                      <th style="padding:0.65rem 0.75rem; text-align:center;">Volumen Válido</th>
                      <th style="padding:0.65rem 0.75rem; text-align:center;">Estado</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($topLegit as $lg): ?>
                      <tr style="border-bottom:1px solid var(--table-row-border); background:rgba(16,185,129,0.03);">
                        <td style="padding:0.65rem 0.75rem;">
                          <code style="color:#34d399; font-weight:bold; font-size:0.9rem;"><?php echo htmlspecialchars($lg['source_ip']); ?></code>
                        </td>
                        <td style="padding:0.65rem 0.75rem; color:var(--text-label);">
                          <strong><?php echo htmlspecialchars($lg['org'] ?? 'Proveedor Corporativo'); ?></strong> (<?php echo htmlspecialchars($lg['country'] ?? 'Global'); ?>)
                        </td>
                        <td style="padding:0.65rem 0.75rem; text-align:center; font-weight:bold; color:#10b981;">
                          <?php echo number_format($lg['total_legit']); ?> entregados
                        </td>
                        <td style="padding:0.65rem 0.75rem; text-align:center;">
                          <span class="badge badge-green">100% PASS</span>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>

          </div>

          <!-- REPOSITORIO DE DOSIERES MENSUALES HISTÓRICOS (AUDITORÍA & COMPLIANCE) -->
          <?php
          $histPeriods = $dmarcWatchdog->getHistoricalPeriods($filterTarget);
          ?>
          <div style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:12px; padding:1.5rem; margin-bottom:1.5rem;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; flex-wrap:wrap; gap:0.5rem;">
              <div>
                <h3 style="color:var(--text-main); font-size:1.1rem; display:flex; align-items:center; gap:0.5rem;">
                  <span>🗄️ Repositorio de Dosieres Históricos Mensuales</span>
                </h3>
                <span style="font-size:0.75rem; color:var(--text-muted);">Informes periciales cerrados por periodo para auditorías financieras y de gobierno corporativo</span>
              </div>
            </div>

            <div style="overflow-x:auto;">
              <table style="width:100%; border-collapse:collapse; font-size:0.85rem;">
                <thead>
                  <tr style="background:var(--table-header-bg); border-bottom:1px solid var(--border-color); text-align:left; color:var(--text-muted); font-size:0.75rem; text-transform:uppercase;">
                    <th style="padding:0.65rem 0.75rem;">Periodo Mensual</th>
                    <th style="padding:0.65rem 0.75rem;">Volumen Auditado</th>
                    <th style="padding:0.65rem 0.75rem; text-align:center;">Intentos Mitigados</th>
                    <th style="padding:0.65rem 0.75rem; text-align:center;">Estado</th>
                    <th style="padding:0.65rem 0.75rem; text-align:center;">Acción de Descarga</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($histPeriods as $hp): ?>
                    <tr style="border-bottom:1px solid var(--table-row-border);">
                      <td style="padding:0.65rem 0.75rem; font-weight:bold; color:var(--text-main);">
                        📅 Mes de <?php echo htmlspecialchars($hp['period_label']); ?>
                        <?php if ($hp['period_key'] === date('Y-m')): ?>
                          <span class="badge badge-blue" style="font-size:0.65rem; margin-left:0.35rem;">En Curso</span>
                        <?php else: ?>
                          <span class="badge badge-green" style="font-size:0.65rem; margin-left:0.35rem;">Auditado</span>
                        <?php endif; ?>
                      </td>
                      <td style="padding:0.65rem 0.75rem; color:var(--text-muted);">
                        <?php echo number_format($hp['total_messages']); ?> mensajes evaluados
                      </td>
                      <td style="padding:0.65rem 0.75rem; text-align:center; font-weight:bold; color:<?php echo $hp['total_fails'] > 0 ? '#ef4444' : '#10b981'; ?>;">
                        <?php echo number_format($hp['total_fails']); ?> bloqueos
                      </td>
                      <td style="padding:0.65rem 0.75rem; text-align:center;">
                        <span class="badge badge-green">FIRMADO SHA-256</span>
                      </td>
                      <td style="padding:0.65rem 0.75rem; text-align:center;">
                        <a href="portal.php?tab=dmarc&action=download_my_dossier&domain=<?php echo urlencode($isConsolidated ? 'all' : $activeDomainName); ?>&period=<?php echo urlencode($hp['period_key']); ?>" target="_blank" class="btn btn-blue" style="width:auto; padding:0.3rem 0.75rem; font-size:0.75rem; font-weight:bold;">
                          📥 Descargar PDF
                        </a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Registro DNS TXT Asignado al Dominio -->
          <div style="background:var(--bg-input); border:1px solid var(--border-color); border-radius:10px; padding:1.25rem;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.75rem; flex-wrap:wrap; gap:0.5rem;">
              <strong style="color:#38bdf8; font-size:0.9rem;">🔑 Registro DNS TXT de Blindaje Asignado a su Dominio</strong>
              <span style="font-size:0.75rem; color:#64748b;">Subdominio DNS: <code>_dmarc.<?php echo htmlspecialchars($activeDomainName); ?></code></span>
            </div>
            <div style="display:flex; gap:0.75rem; align-items:center;">
              <input type="text" readonly id="myDnsTxt" value="<?php echo htmlspecialchars($txtRecord); ?>" class="form-input" style="font-family:monospace; background:#080f1e; color:#a7f3d0; border-color:#059669; font-size:0.85rem; cursor:pointer;" onclick="this.select();">
              <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('myDnsTxt').value); alert('✓ Registro DNS TXT copiado al portapapeles');" class="btn btn-blue" style="width:auto; padding:0.85rem 1.25rem; font-size:0.85rem; white-space:nowrap;">
                📋 Copiar Registro
              </button>
            </div>
            <p style="font-size:0.75rem; color:#64748b; margin-top:0.5rem;">
              Este registro ordena a todos los servidores de correo del planeta rechazar cualquier suplantación no autorizada y reportar telemetría forense cifrada a la central de Keystone Advisors.
            </p>
          </div>

        </div>

      <?php endif; ?>

      <!-- ========================================== -->
      <!-- TABLA DE HISTORIAL DE ÓRDENES Y AUDITORÍA -->
      <!-- ========================================== -->
      <?php
      $searchQuery = trim($_GET['q'] ?? '');
      $filterStatus = trim($_GET['status'] ?? '');
      $currentPage = max(1, (int)($_GET['p'] ?? 1));
      $pagedResult = $deepShield->listOrdersPaged($tenant['tenant_slug'], $searchQuery, $filterStatus, $currentPage, 8);
      $recentOrders = $pagedResult['orders'];
      $totalPages = $pagedResult['total_pages'];
      $totalRows = $pagedResult['total_rows'];
      ?>

      <div class="card" style="margin-top:2rem;">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem; margin-bottom:1.25rem;">
          <div>
            <h3 style="font-size:1.15rem; color:var(--text-main);">📋 Historial de Órdenes de Tesorería (<?php echo $totalRows; ?>)</h3>
            <span style="font-size:0.8rem; color:var(--text-muted);">Monitoreo en vivo de autorizaciones y bloqueos preventivos</span>
          </div>
          
          <!-- Filtros de Búsqueda -->
          <form method="GET" style="display:flex; gap:0.5rem; flex-wrap:wrap;">
            <input type="hidden" name="tab" value="<?php echo htmlspecialchars($activeTab); ?>">
            <input type="text" name="q" value="<?php echo htmlspecialchars($searchQuery); ?>" placeholder="Buscar por código, RUT o proveedor..." class="form-input" style="width:230px; padding:0.45rem 0.75rem; font-size:0.85rem;">
            <select name="status" class="form-select" style="width:160px; padding:0.45rem 0.75rem; font-size:0.85rem;">
              <option value="">Todos los Estados</option>
              <option value="PENDING_APPROVAL" <?php echo $filterStatus === 'PENDING_APPROVAL' ? 'selected' : ''; ?>>⏳ Pendientes</option>
              <option value="APPROVED" <?php echo $filterStatus === 'APPROVED' ? 'selected' : ''; ?>>🟢 Autorizadas</option>
              <option value="REJECTED" <?php echo $filterStatus === 'REJECTED' ? 'selected' : ''; ?>>🔴 Bloqueadas</option>
            </select>
            <button type="submit" class="btn btn-primary" style="width:auto; padding:0.45rem 0.9rem; font-size:0.85rem;">Filtrar 🔍</button>
          </form>
        </div>

        <div style="overflow-x:auto;">
          <table style="width:100%; border-collapse:collapse; min-width:850px; font-size:0.85rem;">
            <thead>
              <tr style="background:var(--bg-subtle); border-bottom:1px solid var(--border-color); text-align:left; color:var(--text-muted); font-size:0.78rem; text-transform:uppercase;">
                <th style="padding:0.75rem 0.85rem;">Código</th>
                <th style="padding:0.75rem 0.85rem;">Fecha</th>
                <th style="padding:0.75rem 0.85rem;">Monto</th>
                <th style="padding:0.75rem 0.85rem;">Beneficiario & RUT</th>
                <th style="padding:0.75rem 0.85rem;">Banco & Cuenta</th>
                <th style="padding:0.75rem 0.85rem;">Desafío IA</th>
                <th style="padding:0.75rem 0.85rem;">Estado</th>
                <th style="padding:0.75rem 0.85rem;">Acción</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($recentOrders)): ?>
                <tr>
                  <td colspan="8" style="padding:2rem; text-align:center; color:#64748b;">No se encontraron órdenes registradas con los criterios seleccionados.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($recentOrders as $ord): ?>
                  <tr style="border-bottom:1px solid var(--table-row-border);">
                    <td style="padding:0.75rem 0.85rem; font-weight:bold; color:#38bdf8;"><code><?php echo htmlspecialchars($ord['order_code']); ?></code></td>
                    <td style="padding:0.75rem 0.85rem; color:var(--text-muted); font-size:0.78rem;"><?php echo htmlspecialchars(substr($ord['created_at'], 0, 16)); ?></td>
                    <td style="padding:0.75rem 0.85rem; font-weight:bold; color:var(--text-main);">
                      <?php echo htmlspecialchars($ord['currency']); ?> $<?php echo number_format($ord['amount'], 0, ',', '.'); ?>
                    </td>
                    <td style="padding:0.75rem 0.85rem;">
                      <strong style="color:#f1f5f9;"><?php echo htmlspecialchars($ord['beneficiary_name']); ?></strong><br>
                      <span style="color:#64748b; font-size:0.75rem;"><?php echo htmlspecialchars($ord['beneficiary_rut_dni']); ?></span>
                    </td>
                    <td style="padding:0.75rem 0.85rem;">
                      <?php echo htmlspecialchars($ord['bank_name']); ?><br>
                      <span style="font-family:monospace; color:var(--text-muted); font-size:0.75rem;"><?php echo htmlspecialchars($ord['account_number']); ?></span>
                    </td>
                    <td style="padding:0.75rem 0.85rem;">
                      <code style="background:var(--bg-subtle); padding:0.2rem 0.4rem; border-radius:4px; color:#38bdf8; font-weight:bold;"><?php echo htmlspecialchars($ord['security_challenge']); ?></code>
                    </td>
                    <td style="padding:0.75rem 0.85rem;">
                      <?php if ($ord['status'] === 'APPROVED'): ?>
                        <span style="background:rgba(16,185,129,0.2); color:#34d399; border:1px solid #10b981; padding:0.2rem 0.5rem; border-radius:9999px; font-size:0.72rem; font-weight:bold;">🟢 AUTORIZADA</span>
                        <?php if (strpos($ord['approval_method'] ?? '', 'WEBAUTHN') !== false): ?>
                          <div style="font-size:0.68rem; color:#6ee7b7; margin-top:0.25rem; font-weight:bold; display:flex; align-items:center; gap:0.2rem;">
                            <span>🔐</span> FaceID / Biometría
                          </div>
                        <?php endif; ?>
                      <?php elseif ($ord['status'] === 'REJECTED'): ?>
                        <span style="background:rgba(239,68,68,0.2); color:#f87171; border:1px solid #ef4444; padding:0.2rem 0.5rem; border-radius:9999px; font-size:0.72rem; font-weight:bold;">🔴 BLOQUEADA (FRAUDE)</span>
                      <?php else: ?>
                        <span style="background:rgba(245,158,11,0.2); color:#fbbf24; border:1px solid #f59e0b; padding:0.2rem 0.5rem; border-radius:9999px; font-size:0.72rem; font-weight:bold;">⏳ PENDIENTE OOB</span>
                      <?php endif; ?>
                    </td>
                    <td style="padding:0.75rem 0.85rem;">
                      <a href="/shield/verify.php?token=<?php echo htmlspecialchars($ord['token_hash']); ?>" target="_blank" style="background:#0284c7; color:var(--text-main); padding:0.25rem 0.55rem; border-radius:4px; font-size:0.75rem; text-decoration:none; font-weight:bold;">
                        👁️ Ver
                      </a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <!-- Paginación -->
        <?php if ($totalPages > 1): ?>
          <div style="display:flex; justify-content:center; align-items:center; gap:0.5rem; margin-top:1.5rem;">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
              <a href="portal.php?p=<?php echo $i; ?>&tab=<?php echo htmlspecialchars($activeTab); ?>&q=<?php echo urlencode($searchQuery); ?>&status=<?php echo urlencode($filterStatus); ?>" 
                 style="padding:0.35rem 0.75rem; border-radius:6px; font-size:0.85rem; text-decoration:none; font-weight:bold; <?php echo $i === $currentPage ? 'background:#38bdf8; color:#0f172a;' : 'background:var(--bg-subtle); color:var(--text-muted); border:1px solid #334155;'; ?>">
                <?php echo $i; ?>
              </a>
            <?php endfor; ?>
          </div>
        <?php endif; ?>

      </div>

    <?php endif; ?>

  </div>

  <!-- Datalist de Bancos de Chile -->
  <datalist id="bancosChile">
    <option value="Banco de Chile / Edwards">
    <option value="Banco Santander Chile">
    <option value="Banco Estado (Banco del Estado de Chile)">
    <option value="Banco BCI (Banco de Crédito e Inversiones)">
    <option value="Scotiabank Chile">
    <option value="Itaú Corpbanca">
    <option value="Banco BICE">
    <option value="Banco Security">
    <option value="Banco Falabella">
    <option value="Banco Ripley">
    <option value="Banco Consorcio">
    <option value="Banco Internacional">
    <option value="HSBC Bank Chile">
    <option value="JPMorgan Chase Bank">
    <option value="BTG Pactual Chile">
    <option value="Tenpo Prepago / Cuenta">
    <option value="Mercado Pago">
    <option value="Coopeuch">
    <option value="Otro Banco Nacional / Internacional">
  </datalist>

  <script>
    function changeDeepShieldTheme(theme) {
      document.documentElement.setAttribute('data-theme', theme);
      localStorage.setItem('deepshield_theme', theme);
      const sel = document.getElementById('deepshieldThemeSelector');
      if (sel) sel.value = theme;
    }

    // Sincronizar select con tema guardado al cargar
    document.addEventListener('DOMContentLoaded', function() {
      const current = localStorage.getItem('deepshield_theme') || 'dark';
      const sel = document.getElementById('deepshieldThemeSelector');
      if (sel) sel.value = current;
    });

    function toggleSelectAll(checked) {
      document.querySelectorAll('.batch-checkbox').forEach(cb => cb.checked = checked);
    }
  </script>

</body>
</html>
