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
    unset($_SESSION['treasury_tenant']);
    header("Location: portal.php");
    exit;
}

$tenant = $_SESSION['treasury_tenant'] ?? null;
$activeTab = $_GET['tab'] ?? 'single'; // single, batch, whitelist
$loginError = null;
$orderCreated = null;
$batchResult = null;
$feedbackMsg = null;

// Proceso de Login de Tesorería
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $slug = trim($_POST['tenant_slug'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    $authedTenant = $tenantService->authenticateOperator($slug, $email, $password);
    if ($authedTenant) {
        $_SESSION['treasury_tenant'] = $authedTenant;
        header("Location: portal.php");
        exit;
    } else {
        $loginError = "Credenciales incorrectas o la empresa se encuentra suspendida.";
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
                             <p><a href='{$res['verification_url']}' style='background:#10b981; color:#fff; padding:10px 18px; text-decoration:none; border-radius:6px; font-weight:bold;'>Autorizar en Smartphone ➜</a></p>";
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

            $period = date('m/Y');
            $tenantForPdf = $dmarcTenant;
            if ($reqDom && $reqDom !== 'all') {
                $tenantForPdf['domain'] = $reqDom;
            }
            $pdfGen = new DmarcMonthlyDossierPdfGenerator($tenantForPdf, $metrics, $period);
            $pdfBinary = $pdfGen->generate();
            $filename = 'Dosier_DMARC_' . preg_replace('/[^A-Za-z0-9]/', '_', $targetDomainForPdf) . '_' . date('Ym') . '.pdf';

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
  <title>Portal de Tesorería Segura | Keystone DeepShield</title>
  <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
  <link rel="icon" type="image/png" href="../assets/images/favicon.png">
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
    body { background: #0b1329; color: #f8fafc; min-height: 100vh; padding: 2rem 1.5rem; }
    .container { max-width: 1200px; width: 100%; margin: 0 auto; }
    .header { display: flex; justify-content: space-between; align-items: center; padding-bottom: 1.5rem; border-bottom: 1px solid #1e293b; margin-bottom: 1.5rem; }
    .brand { font-size: 1.35rem; font-weight: 800; color: #38bdf8; display: flex; align-items: center; gap: 0.6rem; }
    .tabs-nav { display: flex; gap: 0.5rem; margin-bottom: 1.5rem; border-bottom: 1px solid #1e293b; padding-bottom: 0.75rem; flex-wrap: wrap; }
    .tab-btn { background: #131f37; border: 1px solid #1e3a5f; color: #94a3b8; padding: 0.65rem 1.25rem; border-radius: 8px; font-weight: 700; font-size: 0.9rem; text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem; transition: all 0.2s; }
    .tab-btn:hover, .tab-btn.active { background: #0284c7; color: #fff; border-color: #38bdf8; }
    .card { background: #131f37; border: 1px solid #1e3a5f; border-radius: 14px; padding: 2rem; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.4); margin-bottom: 2rem; }
    .form-group { margin-bottom: 1.25rem; }
    .form-label { display: block; font-size: 0.85rem; font-weight: 700; color: #cbd5e1; margin-bottom: 0.4rem; }
    .form-input, .form-select { width: 100%; padding: 0.85rem; background: #0b1329; border: 1px solid #334e68; border-radius: 8px; color: #fff; font-size: 0.95rem; }
    .form-input:focus, .form-select:focus { border-color: #38bdf8; outline: none; box-shadow: 0 0 0 2px rgba(56,189,248,0.2); }
    .btn { display: inline-flex; align-items: center; justify-content: center; width: 100%; padding: 0.85rem 1.5rem; border-radius: 8px; font-weight: 700; cursor: pointer; border: none; font-size: 0.95rem; transition: all 0.2s ease; text-decoration: none; }
    .btn-primary { background: linear-gradient(135deg, #059669 0%, #10b981 100%); color: #fff; box-shadow: 0 4px 14px rgba(16,185,129,0.3); }
    .btn-primary:hover { filter: brightness(1.1); transform: translateY(-1px); }
    .btn-blue { background: #0284c7; color: #fff; }
    .btn-blue:hover { background: #0369a1; }
    .btn-danger { background: #dc2626; color: #fff; }
    .alert-success { background: rgba(16,185,129,0.15); border: 1px solid #10b981; padding: 1.25rem; border-radius: 8px; color: #a7f3d0; margin-bottom: 1.5rem; }
    .alert-error { background: rgba(239,68,68,0.15); border: 1px solid #ef4444; padding: 1rem; border-radius: 8px; color: #fca5a5; margin-bottom: 1.5rem; }
    .badge { display: inline-block; padding: 0.2rem 0.55rem; border-radius: 9999px; font-size: 0.72rem; font-weight: bold; }
    .badge-red { background: rgba(239,68,68,0.2); color: #f87171; border: 1px solid #ef4444; }
    .badge-green { background: rgba(16,185,129,0.2); color: #34d399; border: 1px solid #10b981; }
    .badge-amber { background: rgba(245,158,11,0.2); color: #fbbf24; border: 1px solid #f59e0b; }
    .badge-blue { background: rgba(2,132,199,0.2); color: #38bdf8; border: 1px solid #0284c7; }
    .dropzone { border: 2px dashed #334e68; border-radius: 12px; padding: 2.5rem 1.5rem; text-align: center; background: #0b1329; cursor: pointer; transition: border-color 0.2s; }
    .dropzone:hover { border-color: #38bdf8; }
  </style>
</head>
<body>

  <div class="container">
    
    <header class="header">
      <div class="brand">
        <span>🛡️ Keystone DeepShield</span>
        <span style="font-size:0.8rem; background:#0284c7; color:#fff; padding:0.2rem 0.6rem; border-radius:4px; text-transform:uppercase;">Portal Tesorería</span>
      </div>
      <?php if ($tenant): ?>
        <div style="font-size:0.85rem; color:#94a3b8; display:flex; align-items:center; gap:1rem;">
          <span>🏢 <strong><?php echo htmlspecialchars($tenant['company_name']); ?></strong> (<?php echo htmlspecialchars($tenant['treasury_operator_name']); ?>)</span>
          <a href="portal.php?action=logout" style="color:#ef4444; text-decoration:none; font-weight:700;">Cerrar Sesión</a>
        </div>
      <?php endif; ?>
    </header>

    <?php if (!$tenant): ?>
      <!-- Formulario de Login para Operador de Tesorería -->
      <div style="max-width: 480px; margin: 3rem auto;">
        <div class="card">
          <h2 style="font-size:1.4rem; color:#fff; margin-bottom:0.5rem; text-align:center;">Acceso a Tesorería Segura</h2>
          <p style="font-size:0.85rem; color:#94a3b8; margin-bottom:1.5rem; text-align:center;">
            Inicie sesión con las credenciales de operador asignadas a su empresa por Keystone Advisors.
          </p>

          <?php if ($loginError): ?>
            <div class="alert-error"><?php echo htmlspecialchars($loginError); ?></div>
          <?php endif; ?>

          <form method="POST">
            <input type="hidden" name="action" value="login">
            <div class="form-group">
              <label class="form-label">Identificador de la Empresa (Slug)</label>
              <input type="text" name="tenant_slug" required placeholder="ej: keystone" class="form-input">
            </div>
            <div class="form-group">
              <label class="form-label">Correo del Operador de Tesorería</label>
              <input type="email" name="email" required placeholder="tesoreria@suempresa.cl" class="form-input">
            </div>
            <div class="form-group">
              <label class="form-label">Contraseña</label>
              <input type="password" name="password" required placeholder="••••••••••••" class="form-input">
            </div>
            <button type="submit" class="btn btn-primary" style="margin-top:1rem;">
              Ingresar al Portal Seguro ➜
            </button>
          </form>
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
              <a href="portal.php?tab=single" class="btn" style="width:auto; background:#1e293b; color:#cbd5e1; padding:0.5rem 1rem; font-size:0.85rem;">
                + Cargar Otra Orden
              </a>
            </div>
          </div>
        <?php endif; ?>

        <div class="card">
          <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:1.5rem; flex-wrap:wrap; gap:1rem;">
            <div>
              <h2 style="font-size:1.35rem; color:#fff;">Emitir Pago Crítico o Cambio de Cuenta</h2>
              <p style="font-size:0.85rem; color:#94a3b8; margin-top:0.25rem;">
                Toda transferencia sobre <strong><?php echo $tenant['threshold_currency']; ?> $<?php echo number_format($tenant['threshold_amount'], 0, ',', '.'); ?></strong> o con cuenta modificada requerirá autorización biométrica obligatoria.
              </p>
            </div>
            <div style="background:#0f172a; border:1px solid #334e68; border-radius:8px; padding:0.6rem 1rem; font-size:0.8rem; color:#94a3b8;">
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

            <div style="background:#0f172a; border:1px solid #334155; padding:1.25rem; border-radius:8px; margin-bottom:1.5rem;">
              <label class="form-label" style="color:#38bdf8; font-size:0.9rem; margin-bottom:0.5rem; display:block;">
                📲 Seleccionar Director Firmante (Aprobador OOB) *
              </label>
              <?php
              $approversList = $tenantService->listApprovers($freshTenant['tenant_slug']);
              ?>
              <select name="approver_id" class="form-select" style="background:#131f37; border-color:#0284c7; color:#fff; font-weight:bold;">
                <?php foreach ($approversList as $appr): ?>
                  <option value="<?php echo $appr['id']; ?>" <?php echo !empty($appr['is_primary']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($appr['full_name']); ?> (<?php echo htmlspecialchars($appr['role_title']); ?>) - Celular: <?php echo htmlspecialchars($appr['phone_whatsapp']); ?> <?php echo !empty($appr['is_primary']) ? '⭐ [Principal]' : ''; ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div style="font-size:0.75rem; color:#94a3b8; margin-top:0.4rem;">
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
            <h2 style="font-size:1.35rem; color:#fff;">Ingesta Masiva de Nóminas Bancarias (Batch)</h2>
            <p style="font-size:0.85rem; color:#94a3b8; margin-top:0.25rem;">
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
                  <h3 style="font-size:1.15rem; color:#fff;">
                    🔍 Análisis de Nómina: <?php echo $batchResult['total_rows']; ?> Registros Identificados
                  </h3>
                  <p style="font-size:0.8rem; color:#94a3b8;">
                    Marque las transferencias que desea someter a validación OOB con el Gerente/Firmante.
                  </p>
                </div>
                <div style="display:flex; gap:0.5rem;">
                  <button type="button" onclick="toggleSelectAll(true)" class="btn btn-blue" style="width:auto; padding:0.4rem 0.8rem; font-size:0.8rem;">Marcar Todas</button>
                  <button type="button" onclick="toggleSelectAll(false)" class="btn" style="width:auto; background:#334155; color:#fff; padding:0.4rem 0.8rem; font-size:0.8rem;">Desmarcar</button>
                </div>
              </div>

              <form method="POST">
                <input type="hidden" name="action" value="confirm_batch_ingest">

                <div style="overflow-x:auto; margin-bottom:1.5rem;">
                  <table style="width:100%; border-collapse:collapse; min-width:850px; font-size:0.85rem;">
                    <thead>
                      <tr style="background:#0f172a; border-bottom:1px solid #334155; text-align:left; color:#94a3b8; font-size:0.78rem; text-transform:uppercase;">
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
                        <tr style="border-bottom:1px solid rgba(255,255,255,0.05); <?php echo $r['risk']['status'] === 'ACCOUNT_MISMATCH' ? 'background:rgba(239,68,68,0.1);' : ''; ?>">
                          <td style="padding:0.75rem; text-align:center;">
                            <input type="checkbox" name="selected_rows[]" value="<?php echo $idx; ?>" class="batch-checkbox" <?php echo $r['requires_oob'] ? 'checked' : ''; ?> style="width:18px; height:18px; accent-color:#0284c7;">
                          </td>
                          <td style="padding:0.75rem;">
                            <strong style="color:#fff;"><?php echo htmlspecialchars($r['beneficiary_name']); ?></strong><br>
                            <code style="color:#94a3b8; font-size:0.75rem;"><?php echo htmlspecialchars($r['clean_rut']); ?></code>
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

                <div style="background:#0f172a; border:1px solid #1e3a5f; padding:1.25rem; border-radius:8px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem;">
                  <div style="flex:1; min-width:280px;">
                    <h4 style="color:#fff; font-size:0.95rem; margin-bottom:0.35rem;">Director Firmante Asignado para este Lote:</h4>
                    <select name="batch_approver_id" class="form-select" style="background:#131f37; border-color:#0284c7; color:#fff; font-weight:bold; max-width:480px;">
                      <?php
                      $batchApprovers = $tenantService->listApprovers($freshTenant['tenant_slug']);
                      foreach ($batchApprovers as $ba):
                      ?>
                        <option value="<?php echo $ba['id']; ?>" <?php echo !empty($ba['is_primary']) ? 'selected' : ''; ?>>
                          <?php echo htmlspecialchars($ba['full_name']); ?> (<?php echo htmlspecialchars($ba['role_title']); ?>) - <?php echo htmlspecialchars($ba['phone_whatsapp']); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <span style="color:#94a3b8; font-size:0.75rem; display:block; margin-top:0.25rem;">Se despacharán las solicitudes de aprobación fuera de banda a este directivo.</span>
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
              <h2 style="font-size:1.35rem; color:#fff;">Padrón de Cuentas Bancarias Verificadas (Whitelist)</h2>
              <p style="font-size:0.85rem; color:#94a3b8; margin-top:0.25rem;">
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
              <a href="portal.php?tab=whitelist" class="btn" style="width:auto; background:#334155; color:#fff; padding:0.5rem 1rem; font-size:0.85rem;">Limpiar</a>
            <?php endif; ?>
          </form>

          <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; min-width:850px; font-size:0.85rem;">
              <thead>
                <tr style="background:#0f172a; border-bottom:1px solid #334155; text-align:left; color:#94a3b8; font-size:0.78rem; text-transform:uppercase;">
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
                    <tr style="border-bottom:1px solid rgba(255,255,255,0.05);">
                      <td style="padding:0.75rem; font-weight:bold; color:#38bdf8;"><code><?php echo htmlspecialchars($b['rut_dni']); ?></code></td>
                      <td style="padding:0.75rem; font-weight:bold; color:#fff;"><?php echo htmlspecialchars($b['business_name']); ?></td>
                      <td style="padding:0.75rem; color:#cbd5e1;"><?php echo htmlspecialchars($b['bank_name']); ?></td>
                      <td style="padding:0.75rem; font-family:monospace; color:#34d399;"><?php echo htmlspecialchars($b['account_number']); ?></td>
                      <td style="padding:0.75rem; color:#94a3b8; font-size:0.78rem;">
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
          <div style="background:#131f37; border:1px solid #1e3a5f; border-radius:14px; padding:2rem; max-width:540px; width:100%; box-shadow:0 20px 40px rgba(0,0,0,0.6);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.25rem;">
              <h3 style="color:#fff; font-size:1.2rem;">🏛️ Registrar Proveedor en Whitelist</h3>
              <button type="button" onclick="document.getElementById('modalAddBeneficiary').style.display='none'" style="background:none; border:none; color:#94a3b8; font-size:1.5rem; cursor:pointer;">&times;</button>
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
                <button type="button" onclick="document.getElementById('modalAddBeneficiary').style.display='none'" class="btn" style="background:#334155; color:#fff;">Cancelar</button>
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

        <div class="card" style="background: linear-gradient(180deg, #131f37 0%, #0d1527 100%);">
          <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem; margin-bottom:1.75rem; border-bottom:1px solid #1e3a5f; padding-bottom:1.25rem;">
            <div>
              <div style="display:inline-flex; align-items:center; gap:0.5rem; background:rgba(56,189,248,0.1); border:1px solid #0284c7; padding:0.25rem 0.75rem; border-radius:9999px; margin-bottom:0.5rem;">
                <span style="font-size:0.75rem; font-weight:800; color:#38bdf8; text-transform:uppercase;">Vista de Gobierno & CFO</span>
              </div>
              <h2 style="font-size:1.45rem; color:#fff; font-weight:800;">Tablero de Ciberseguridad Financiera & ROI de Fraude</h2>
              <p style="font-size:0.85rem; color:#94a3b8; margin-top:0.25rem;">
                Monitoreo consolidado de capital protegido, tasa de desvío mitigada y auditoría de incidentes BEC para <strong><?php echo htmlspecialchars($freshTenant['company_name']); ?></strong>.
              </p>
            </div>
            <div style="text-align:right;">
              <span style="font-size:0.75rem; color:#64748b; display:block;">Director / CFO Titular:</span>
              <strong style="color:#f8fafc; font-size:0.95rem;"><?php echo htmlspecialchars($freshTenant['primary_approver_name']); ?></strong>
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
            <div style="background:#0f172a; border:1px solid #1e3a5f; border-radius:12px; padding:1.25rem;">
              <div style="font-size:0.75rem; font-weight:700; color:#38bdf8; text-transform:uppercase; margin-bottom:0.4rem; display:flex; justify-content:space-between;">
                <span>💼 Volumen Total Evaluado</span>
                <span class="badge badge-blue"><?php echo $stats['total_orders']; ?> Órdenes</span>
              </div>
              <div style="font-size:1.6rem; font-weight:900; color:#f8fafc; letter-spacing:-0.5px;">
                <?php echo $curr; ?> $<?php echo number_format($stats['total_volume'], 0, ',', '.'); ?>
              </div>
              <div style="font-size:0.75rem; color:#94a3b8; margin-top:0.35rem;">
                Umbral de corte: <?php echo $curr; ?> $<?php echo number_format($freshTenant['threshold_amount'], 0, ',', '.'); ?>
              </div>
            </div>

            <!-- KPI 4: Whitelist Activa -->
            <div style="background:#0f172a; border:1px solid #1e3a5f; border-radius:12px; padding:1.25rem;">
              <div style="font-size:0.75rem; font-weight:700; color:#cbd5e1; text-transform:uppercase; margin-bottom:0.4rem;">
                <span>🏛️ Proveedores Verificados</span>
              </div>
              <div style="font-size:1.6rem; font-weight:900; color:#38bdf8; letter-spacing:-0.5px;">
                <?php echo $trustedCount; ?> Empresas
              </div>
              <div style="font-size:0.75rem; color:#94a3b8; margin-top:0.35rem;">
                Cuentas blindadas contra alteración no autorizada.
              </div>
            </div>

          </div>

          <!-- Bitácora Ejecutiva de Intentos de Fraude Interceptados -->
          <div style="background:#0f172a; border:1px solid #1e3a5f; border-radius:12px; padding:1.5rem;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; flex-wrap:wrap; gap:0.5rem;">
              <h3 style="color:#fff; font-size:1.1rem; display:flex; align-items:center; gap:0.5rem;">
                <span>🚨 Registro de Incidentes de Fraude Interceptados (Cadena Pericial)</span>
              </h3>
              <span style="font-size:0.75rem; color:#94a3b8;">Documentos periciales con validez jurídica según Ley N° 21.459</span>
            </div>

            <?php if (empty($recentFrauds)): ?>
              <div style="padding:2rem; text-align:center; color:#64748b; font-size:0.9rem;">
                🛡️ Cero incidentes de fraude registrados. Sus operaciones de tesorería se encuentran limpias y protegidas.
              </div>
            <?php else: ?>
              <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; font-size:0.85rem;">
                  <thead>
                    <tr style="background:#0b1329; border-bottom:1px solid #334155; text-align:left; color:#94a3b8; font-size:0.75rem; text-transform:uppercase;">
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
                      <tr style="border-bottom:1px solid rgba(255,255,255,0.05); background:rgba(239,68,68,0.04);">
                        <td style="padding:0.75rem;">
                          <strong style="color:#f87171;"><code><?php echo htmlspecialchars($f['order_code']); ?></code></strong><br>
                          <span style="font-size:0.72rem; color:#94a3b8;"><?php echo htmlspecialchars(substr($f['created_at'], 0, 16)); ?></span>
                        </td>
                        <td style="padding:0.75rem; font-weight:bold; color:#ef4444; font-size:0.95rem;">
                          <?php echo htmlspecialchars($f['currency']); ?> $<?php echo number_format($f['amount'], 0, ',', '.'); ?>
                        </td>
                        <td style="padding:0.75rem;">
                          <strong style="color:#fff;"><?php echo htmlspecialchars($f['beneficiary_name']); ?></strong><br>
                          <code style="color:#94a3b8; font-size:0.72rem;"><?php echo htmlspecialchars($f['beneficiary_rut_dni']); ?></code>
                        </td>
                        <td style="padding:0.75rem;">
                          <?php echo htmlspecialchars($f['bank_name']); ?><br>
                          <code style="color:#f87171; font-size:0.72rem;"><?php echo htmlspecialchars($f['account_number']); ?></code>
                        </td>
                        <td style="padding:0.75rem; font-size:0.75rem; color:#cbd5e1;">
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
              <p style="font-size:0.8rem; color:#94a3b8;">
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
          <div style="background:#0f172a; border:1px solid #1e3a5f; border-radius:10px; padding:0.75rem 1rem; margin-bottom:1.5rem; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:0.75rem;">
            <div style="display:flex; align-items:center; gap:0.5rem; font-size:0.85rem; color:#94a3b8;">
              <span style="font-size:1.1rem;">🌐</span>
              <strong style="color:#fff;">Dominios de <?php echo htmlspecialchars($freshTenant['company_name']); ?>:</strong>
            </div>
            <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
              <a href="portal.php?tab=dmarc&domain=all" style="padding:0.4rem 0.85rem; border-radius:6px; font-size:0.8rem; font-weight:700; text-decoration:none; transition:all 0.2s; <?php echo $isConsolidated ? 'background:#0284c7; color:#fff; box-shadow:0 2px 8px rgba(2,132,199,0.4);' : 'background:#131f37; color:#94a3b8; border:1px solid #334e68;'; ?>">
                📊 Consolidado Global (<?php echo count($myDomains); ?>)
              </a>
              <?php foreach ($myDomains as $d): ?>
                <?php $isThisActive = (!$isConsolidated && strtolower($activeDomainName) === strtolower($d['domain'])); ?>
                <a href="portal.php?tab=dmarc&domain=<?php echo urlencode($d['domain']); ?>" style="padding:0.4rem 0.85rem; border-radius:6px; font-size:0.8rem; font-weight:700; text-decoration:none; transition:all 0.2s; <?php echo $isThisActive ? 'background:#10b981; color:#fff; box-shadow:0 2px 8px rgba(16,185,129,0.4);' : 'background:#131f37; color:#94a3b8; border:1px solid #334e68;'; ?>">
                  @<?php echo htmlspecialchars($d['domain']); ?>
                  <?php if (!empty($d['is_primary'])): ?><span style="font-size:0.65rem; opacity:0.8;">★</span><?php endif; ?>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <div class="card" style="background: linear-gradient(180deg, #0d1e38 0%, #081326 100%); border-color:#0284c7;">
          <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem; margin-bottom:1.75rem; border-bottom:1px solid #1e3a5f; padding-bottom:1.25rem;">
            <div>
              <div style="display:inline-flex; align-items:center; gap:0.5rem; background:rgba(16,185,129,0.12); border:1px solid #10b981; padding:0.25rem 0.75rem; border-radius:9999px; margin-bottom:0.5rem;">
                <span style="font-size:0.75rem; font-weight:800; color:#34d399; text-transform:uppercase;">🛰️ Telemetría DMARC / RFC 7489 en Vivo</span>
              </div>
              <h2 style="font-size:1.45rem; color:#fff; font-weight:800;">Blindaje Anti-Suplantación de Identidad y Dominio</h2>
              <p style="font-size:0.85rem; color:#94a3b8; margin-top:0.25rem;">
                Monitoreo activo de servidores mundiales que emiten correos en nombre de <strong><?php echo htmlspecialchars($displayTitle); ?></strong>.
              </p>
            </div>
            
            <div style="display:flex; gap:0.6rem; align-items:center; flex-wrap:wrap;">
              <?php if ($dmarcTenant): ?>
                <a href="portal.php?tab=dmarc&action=download_my_dossier&domain=<?php echo urlencode($isConsolidated ? "all" : $activeDomainName); ?>" target="_blank" class="btn" style="width:auto; background:linear-gradient(135deg, #0284c7 0%, #0369a1 100%); color:#fff; padding:0.6rem 1.15rem; font-size:0.85rem; box-shadow:0 4px 12px rgba(2,132,199,0.3);">
                  📥 Descargar Dosier Mensual PDF (<?php echo date('m/Y'); ?>)
                </a>
              <?php endif; ?>
              <div style="background:#0f172a; border:1px solid #334e68; border-radius:8px; padding:0.4rem 0.8rem; text-align:right;">
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
            <div style="background:#0f172a; border:1px solid #1e3a5f; border-radius:12px; padding:1.25rem;">
              <div style="font-size:0.75rem; font-weight:700; color:#38bdf8; text-transform:uppercase; margin-bottom:0.4rem; display:flex; justify-content:space-between;">
                <span>📨 Tráfico Evaluado</span>
                <span class="badge badge-blue">Global</span>
              </div>
              <div style="font-size:1.7rem; font-weight:900; color:#f8fafc; letter-spacing:-0.5px;">
                <?php echo number_format($totalVol); ?>
              </div>
              <div style="font-size:0.75rem; color:#94a3b8; margin-top:0.35rem;">
                Mensajes auditados vía telemetría Google y Microsoft.
              </div>
            </div>

            <!-- KPI 4: Estado del Blindaje -->
            <div style="background:#0f172a; border:1px solid #1e3a5f; border-radius:12px; padding:1.25rem;">
              <div style="font-size:0.75rem; font-weight:700; color:#cbd5e1; text-transform:uppercase; margin-bottom:0.4rem;">
                <span>🛡️ Estado de Seguridad</span>
              </div>
              <div style="font-size:1.35rem; font-weight:900; color:#38bdf8; letter-spacing:-0.5px;">
                Dominio Blindado
              </div>
              <div style="font-size:0.75rem; color:#94a3b8; margin-top:0.35rem;">
                Vigilancia 24/7 y respuesta activa por Keystone SOC.
              </div>
            </div>

          </div>

          <!-- Tabla de Atacantes e Intentos de Suplantación Interceptados -->
          <div style="background:#0f172a; border:1px solid #1e3a5f; border-radius:12px; padding:1.5rem; margin-bottom:1.5rem;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; flex-wrap:wrap; gap:0.5rem;">
              <h3 style="color:#fff; font-size:1.1rem; display:flex; align-items:center; gap:0.5rem;">
                <span>🚨 Servidores Agresores Interceptados (Suplantación @<?php echo htmlspecialchars($myDomain); ?>)</span>
              </h3>
              <span style="font-size:0.75rem; color:#94a3b8;">Los correos emitidos por estos IPs fueron destruidos o rechazados por DMARC</span>
            </div>

            <?php if (empty($topSpoofers)): ?>
              <div style="padding:2.5rem; text-align:center; color:#64748b; font-size:0.9rem;">
                🛡️ Felicitaciones. No se registran servidores agresores intentando suplantar a <strong>@<?php echo htmlspecialchars($myDomain); ?></strong> en este periodo.
              </div>
            <?php else: ?>
              <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; font-size:0.85rem;">
                  <thead>
                    <tr style="background:#0b1329; border-bottom:1px solid #334155; text-align:left; color:#94a3b8; font-size:0.75rem; text-transform:uppercase;">
                      <th style="padding:0.75rem;">IP Remitente & Datacenter</th>
                      <th style="padding:0.75rem;">País de Origen</th>
                      <th style="padding:0.75rem; text-align:center;">Volumen Bloqueado</th>
                      <th style="padding:0.75rem; text-align:center;">Autenticación SPF / DKIM</th>
                      <th style="padding:0.75rem; text-align:center;">Acción Pericial</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($topSpoofers as $sp): ?>
                      <tr style="border-bottom:1px solid rgba(255,255,255,0.05); background:rgba(239,68,68,0.04);">
                        <td style="padding:0.75rem;">
                          <code style="color:#f87171; font-weight:bold; font-size:0.95rem; background:#18181b; padding:0.15rem 0.4rem; border-radius:4px;"><?php echo htmlspecialchars($sp['source_ip']); ?></code><br>
                          <span style="font-size:0.75rem; color:#94a3b8;"><?php echo htmlspecialchars($sp['org'] ?? 'Proveedor / ISP no catalogado'); ?></span>
                        </td>
                        <td style="padding:0.75rem;">
                          <strong style="color:#f8fafc; font-size:0.85rem;">🌍 <?php echo htmlspecialchars($sp['country'] ?? 'Desconocido'); ?></strong>
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
          <div style="background:#0f172a; border:1px solid #1e3a5f; border-radius:12px; padding:1.5rem; margin-bottom:1.5rem;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; flex-wrap:wrap; gap:0.5rem;">
              <h3 style="color:#fff; font-size:1.1rem; display:flex; align-items:center; gap:0.5rem;">
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
                    <tr style="background:#0b1329; border-bottom:1px solid #334155; text-align:left; color:#94a3b8; font-size:0.75rem; text-transform:uppercase;">
                      <th style="padding:0.65rem 0.75rem;">IP Autorizada</th>
                      <th style="padding:0.65rem 0.75rem;">Organización / Infraestructura</th>
                      <th style="padding:0.65rem 0.75rem; text-align:center;">Volumen Válido</th>
                      <th style="padding:0.65rem 0.75rem; text-align:center;">Estado</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($topLegit as $lg): ?>
                      <tr style="border-bottom:1px solid rgba(255,255,255,0.05); background:rgba(16,185,129,0.03);">
                        <td style="padding:0.65rem 0.75rem;">
                          <code style="color:#34d399; font-weight:bold; font-size:0.9rem;"><?php echo htmlspecialchars($lg['source_ip']); ?></code>
                        </td>
                        <td style="padding:0.65rem 0.75rem; color:#cbd5e1;">
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

          <!-- Registro DNS TXT Asignado al Dominio -->
          <div style="background:#0b1329; border:1px solid #1e3a5f; border-radius:10px; padding:1.25rem;">
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
            <h3 style="font-size:1.15rem; color:#fff;">📋 Historial de Órdenes de Tesorería (<?php echo $totalRows; ?>)</h3>
            <span style="font-size:0.8rem; color:#94a3b8;">Monitoreo en vivo de autorizaciones y bloqueos preventivos</span>
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
              <tr style="background:#0f172a; border-bottom:1px solid #334155; text-align:left; color:#94a3b8; font-size:0.78rem; text-transform:uppercase;">
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
                  <tr style="border-bottom:1px solid rgba(255,255,255,0.05);">
                    <td style="padding:0.75rem 0.85rem; font-weight:bold; color:#38bdf8;"><code><?php echo htmlspecialchars($ord['order_code']); ?></code></td>
                    <td style="padding:0.75rem 0.85rem; color:#94a3b8; font-size:0.78rem;"><?php echo htmlspecialchars(substr($ord['created_at'], 0, 16)); ?></td>
                    <td style="padding:0.75rem 0.85rem; font-weight:bold; color:#fff;">
                      <?php echo htmlspecialchars($ord['currency']); ?> $<?php echo number_format($ord['amount'], 0, ',', '.'); ?>
                    </td>
                    <td style="padding:0.75rem 0.85rem;">
                      <strong style="color:#f1f5f9;"><?php echo htmlspecialchars($ord['beneficiary_name']); ?></strong><br>
                      <span style="color:#64748b; font-size:0.75rem;"><?php echo htmlspecialchars($ord['beneficiary_rut_dni']); ?></span>
                    </td>
                    <td style="padding:0.75rem 0.85rem;">
                      <?php echo htmlspecialchars($ord['bank_name']); ?><br>
                      <span style="font-family:monospace; color:#94a3b8; font-size:0.75rem;"><?php echo htmlspecialchars($ord['account_number']); ?></span>
                    </td>
                    <td style="padding:0.75rem 0.85rem;">
                      <code style="background:#0f172a; padding:0.2rem 0.4rem; border-radius:4px; color:#38bdf8; font-weight:bold;"><?php echo htmlspecialchars($ord['security_challenge']); ?></code>
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
                      <a href="/shield/verify.php?token=<?php echo htmlspecialchars($ord['token_hash']); ?>" target="_blank" style="background:#0284c7; color:#fff; padding:0.25rem 0.55rem; border-radius:4px; font-size:0.75rem; text-decoration:none; font-weight:bold;">
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
                 style="padding:0.35rem 0.75rem; border-radius:6px; font-size:0.85rem; text-decoration:none; font-weight:bold; <?php echo $i === $currentPage ? 'background:#38bdf8; color:#0f172a;' : 'background:#0f172a; color:#94a3b8; border:1px solid #334155;'; ?>">
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
    function toggleSelectAll(checked) {
      document.querySelectorAll('.batch-checkbox').forEach(cb => cb.checked = checked);
    }
  </script>

</body>
</html>
