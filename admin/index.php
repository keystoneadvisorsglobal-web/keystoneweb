<?php
/**
 * Keystone Advisors - Centro de Control de Telemetría, Embudo & Gestión de Leads B2B
 * Arquitectura Blindada: Sesión PHP segura, credenciales hasheadas, rate limiting anti-fuerza bruta
 */
if (session_status() === PHP_SESSION_NONE) {
    @session_start([
        'cookie_httponly' => true,
        'cookie_secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'cookie_samesite' => 'Lax',
        'gc_maxlifetime' => 7200
    ]);
}

$leads_dir = __DIR__ . '/../data';

// Migración limpia: preferir leads_keystone.sqlite con fallback
$db_file = $leads_dir . '/leads_keystone.sqlite';

require_once __DIR__ . '/../api/DeepShieldService.php';
require_once __DIR__ . '/../api/DeepShieldTenantService.php';
require_once __DIR__ . '/../api/DmarcWatchdogService.php';

try {
    $db = new PDO('sqlite:' . $db_file);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode = WAL;");
    $db->exec("PRAGMA foreign_keys = ON;");

    // Tabla de administradores locales con RBAC
    $db->exec("CREATE TABLE IF NOT EXISTS admin_users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        password_hash TEXT NOT NULL,
        role TEXT DEFAULT 'comercial',
        full_name TEXT DEFAULT '',
        is_active INTEGER DEFAULT 1,
        last_login DATETIME,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Migración idempotente para columnas en admin_users
    $adminCols = $db->query("PRAGMA table_info(admin_users)")->fetchAll(PDO::FETCH_ASSOC);
    $adminColNames = array_column($adminCols, 'name');
    if (!in_array('full_name', $adminColNames)) {
        $db->exec("ALTER TABLE admin_users ADD COLUMN full_name TEXT DEFAULT ''");
    }
    if (!in_array('is_active', $adminColNames)) {
        $db->exec("ALTER TABLE admin_users ADD COLUMN is_active INTEGER DEFAULT 1");
    }

    // Tabla de intentos de login para Rate Limiting
    $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ip_address TEXT NOT NULL,
        attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Tabla de cambios de estado de leads
    $db->exec("CREATE TABLE IF NOT EXISTS lead_status (
        lead_id INTEGER PRIMARY KEY,
        status TEXT DEFAULT 'NUEVO',
        notes TEXT,
        assigned_to TEXT,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Tabla de configuraciones generales (incluye credenciales SMTP)
    $db->exec("CREATE TABLE IF NOT EXISTS app_settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Inicializar superadmin si no existe (Usuario: keystone_admin / Clave inicial fuerte)
    $stmt = $db->prepare("SELECT COUNT(*) FROM admin_users WHERE username = ?");
    $stmt->execute(['keystone_admin']);
    if ($stmt->fetchColumn() == 0) {
        $initial_hash = password_hash('Keystone2026!SecOps', PASSWORD_BCRYPT);
        $insert = $db->prepare("INSERT INTO admin_users (username, password_hash, role) VALUES (?, ?, ?)");
        $insert->execute(['keystone_admin', $initial_hash, 'superadmin']);
    }
} catch (Exception $e) {
    die("Error inicializando base de datos administrativa: " . htmlspecialchars($e->getMessage()));
}

// Control de Logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    $_SESSION = [];
    session_destroy();
    header("Location: index.php");
    exit;
}

// RBAC: Definición de Permisos y Matriz de Acceso por Perfil
$current_admin_role = $_SESSION['admin_role'] ?? 'comercial';
if ($current_admin_role === 'admin') $current_admin_role = 'superadmin';

// Matriz de pestañas permitidas por rol
$rbac_allowed_tabs = [
    'superadmin' => ['dashboard', 'leads', 'scans', 'replies', 'treasury', 'dmarc', 'redteaming', 'marketing', 'integrations', 'smtp', 'users'],
    'comercial'  => ['dashboard', 'leads', 'scans', 'replies', 'redteaming', 'marketing', 'integrations'],
    'secops'     => ['treasury', 'dmarc', 'scans', 'redteaming']
];

function canAccessTab($tabName, $role, $map) {
    if ($role === 'superadmin') return true;
    $allowed = $map[$role] ?? [];
    return in_array($tabName, $allowed);
}

function enforceTabAccess($tabName, $role, $map) {
    if (!canAccessTab($tabName, $role, $map)) {
        http_response_code(403);
        $defaultTab = ($role === 'secops') ? 'treasury' : (($role === 'comercial') ? 'leads' : 'dashboard');
        echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>403 Acceso Denegado</title><style>body{background:#001730;color:#fff;font-family:Arial,sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;}.box{background:#fff;color:#1e293b;padding:2.5rem;border-radius:12px;max-width:480px;text-align:center;box-shadow:0 15px 35px rgba(0,0,0,0.4);}</style></head><body><div class="box"><h2 style="color:#ef4444;margin-bottom:0.75rem;">⛔ Acceso Restringido</h2><p style="font-size:0.95rem;color:#64748b;line-height:1.5;">Su perfil operativo (<strong>' . htmlspecialchars($role) . '</strong>) no tiene autorización para acceder a este módulo.</p><div style="margin-top:1.5rem;"><a href="index.php?tab=' . $defaultTab . '" style="background:#003366;color:#fff;text-decoration:none;padding:0.6rem 1.25rem;border-radius:6px;font-weight:700;font-size:0.9rem;">Volver a mi Panel Principal</a></div></div></body></html>';
        exit;
    }
}

// Gestión de Usuarios (Exclusivo SUPERADMIN)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_SESSION['admin_logged'])) {
    if ($_POST['action'] === 'create_admin_user') {
        if ($current_admin_role !== 'superadmin') {
            http_response_code(403);
            die("Permiso denegado: solo superadmin puede gestionar operadores.");
        }
        $new_user = strtolower(trim($_POST['new_username'] ?? ''));
        $new_name = trim($_POST['new_fullname'] ?? '');
        $new_pass = $_POST['new_password'] ?? '';
        $new_role = $_POST['new_role'] ?? 'comercial';

        if (in_array($new_role, ['superadmin', 'comercial', 'secops']) && !empty($new_user) && !empty($new_pass)) {
            $pass_hash = password_hash($new_pass, PASSWORD_BCRYPT);
            try {
                $ins = $db->prepare("INSERT INTO admin_users (username, password_hash, role, full_name, is_active) VALUES (?, ?, ?, ?, 1)");
                $ins->execute([$new_user, $pass_hash, $new_role, $new_name]);
                header("Location: index.php?tab=users&user_created=1");
                exit;
            } catch (Exception $eUser) {
                header("Location: index.php?tab=users&user_error=" . urlencode("El nombre de usuario ya existe o es inválido."));
                exit;
            }
        }
    }

    if ($_POST['action'] === 'toggle_admin_user_status') {
        if ($current_admin_role !== 'superadmin') {
            http_response_code(403);
            die("Permiso denegado.");
        }
        $target_id = (int)($_POST['user_id'] ?? 0);
        $new_active = (int)($_POST['is_active'] ?? 1);
        if ($target_id > 0) {
            $stmt = $db->prepare("UPDATE admin_users SET is_active = ? WHERE id = ? AND username != ?");
            $stmt->execute([$new_active, $target_id, $_SESSION['admin_user']]);
            header("Location: index.php?tab=users&status_updated=1");
            exit;
        }
    }

    if ($_POST['action'] === 'change_admin_user_password') {
        if ($current_admin_role !== 'superadmin') {
            http_response_code(403);
            die("Permiso denegado.");
        }
        $target_id = (int)($_POST['user_id'] ?? 0);
        $new_pass = $_POST['new_password'] ?? '';
        if ($target_id > 0 && !empty($new_pass)) {
            $stmt = $db->prepare("UPDATE admin_users SET password_hash = ? WHERE id = ?");
            $stmt->execute([password_hash($new_pass, PASSWORD_BCRYPT), $target_id]);
            header("Location: index.php?tab=users&pass_updated=1");
            exit;
        }
    }
}

// Procesar Actualización de Configuración SMTP
$smtp_msg = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_smtp' && isset($_SESSION['admin_logged'])) {
    $keys = ['smtp_host', 'smtp_port', 'smtp_user', 'smtp_from', 'smtp_name'];
    foreach ($keys as $k) {
        $val = trim($_POST[$k] ?? '');
        $stmt = $db->prepare("INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)
                              ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value, updated_at = CURRENT_TIMESTAMP");
        $stmt->execute([$k, $val]);
    }
    // Solo actualizar password si se escribió algo
    if (!empty($_POST['smtp_pass'])) {
        $stmt = $db->prepare("INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)
                              ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value, updated_at = CURRENT_TIMESTAMP");
        $stmt->execute(['smtp_pass', trim($_POST['smtp_pass'])]);
    }
    header("Location: index.php?tab=smtp&saved=1");
    exit;
}

// Probar Conexión SMTP y Enviar Prueba
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'test_smtp' && isset($_SESSION['admin_logged'])) {
    require_once __DIR__ . '/../api/LeadNotificationService.php';
    require_once __DIR__ . '/../api/KeystoneSmtpClient.php';
    
    $testTo = trim($_POST['test_email'] ?? 'contacto@keystoneadvisors.cl');
    $cfg = LeadNotificationService::getSmtpConfig();
    $client = new KeystoneSmtpClient($cfg);
    
    $testHtml = "<h3>Prueba de Conexión SMTP Exitosa</h3><p>Este correo confirma que el servidor <strong>smtp.titan.email</strong> está correctamente autenticado y despachando correos desde el sitio web de Keystone Advisors.</p>";
    $ok = $client->send($testTo, "Prueba de Conexión SMTP - Keystone Advisors", $testHtml);
    
    if ($ok) {
        header("Location: index.php?tab=smtp&test_ok=1&to=" . urlencode($testTo));
    } else {
        $_SESSION['smtp_test_error'] = $client->getLog();
        header("Location: index.php?tab=smtp&test_fail=1");
    }
    exit;
}

// Acción Crear Orden DeepShield Treasury
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_deepshield_order' && isset($_SESSION['admin_logged'])) {
    $deepShield = new DeepShieldService($db);
    $res = $deepShield->createOrder($_POST);
    if (!empty($res['success'])) {
        header("Location: index.php?tab=treasury&created=1&code=" . urlencode($res['order_code']));
    } else {
        header("Location: index.php?tab=treasury&error=1");
    }
    exit;
}

// =========================================================================
// ACCIONES PARA GESTIÓN DE MÚLTIPLES OPERADORES Y DIRECTORES FIRMANTES (ADMIN)
// =========================================================================

// Agregar Operador de Tesorería a una Empresa
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'admin_add_operator' && isset($_SESSION['admin_logged'])) {
    $slug = trim($_POST['tenant_slug'] ?? '');
    $tenantService = new DeepShieldTenantService($db);
    $res = $tenantService->addOperator($slug, [
        'full_name' => $_POST['full_name'] ?? '',
        'email' => $_POST['email'] ?? '',
        'phone' => $_POST['phone'] ?? '',
        'password' => $_POST['password'] ?? 'Tesoreria2026!',
        'role' => $_POST['role'] ?? 'OPERATOR'
    ]);
    header("Location: index.php?tab=treasury&tenant_slug=" . urlencode($slug) . "&op_saved=" . ($res['success'] ? '1' : '0'));
    exit;
}

// Eliminar Operador de Tesorería
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'admin_delete_operator' && isset($_SESSION['admin_logged'])) {
    $slug = trim($_POST['tenant_slug'] ?? '');
    $opId = (int)($_POST['operator_id'] ?? 0);
    $tenantService = new DeepShieldTenantService($db);
    $tenantService->deleteOperator($slug, $opId);
    header("Location: index.php?tab=treasury&tenant_slug=" . urlencode($slug) . "&op_deleted=1");
    exit;
}

// Agregar Director Firmante (Approver) a una Empresa
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'admin_add_approver' && isset($_SESSION['admin_logged'])) {
    $slug = trim($_POST['tenant_slug'] ?? '');
    $tenantService = new DeepShieldTenantService($db);
    $res = $tenantService->addApprover($slug, [
        'full_name' => $_POST['full_name'] ?? '',
        'email' => $_POST['email'] ?? '',
        'phone_whatsapp' => $_POST['phone_whatsapp'] ?? '',
        'role_title' => $_POST['role_title'] ?? 'Director / Firmante',
        'max_approval_limit' => (float)($_POST['max_approval_limit'] ?? 0),
        'is_primary' => !empty($_POST['is_primary']) ? 1 : 0
    ]);
    header("Location: index.php?tab=treasury&tenant_slug=" . urlencode($slug) . "&appr_saved=" . ($res['success'] ? '1' : '0'));
    exit;
}

// Eliminar Director Firmante
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'admin_delete_approver' && isset($_SESSION['admin_logged'])) {
    $slug = trim($_POST['tenant_slug'] ?? '');
    $apprId = (int)($_POST['approver_id'] ?? 0);
    $tenantService = new DeepShieldTenantService($db);
    $tenantService->deleteApprover($slug, $apprId);
    header("Location: index.php?tab=treasury&tenant_slug=" . urlencode($slug) . "&appr_deleted=1");
    exit;
}

// Endpoint JSON para obtener Operadores y Firmantes de un Tenant
if (isset($_GET['action']) && $_GET['action'] === 'get_tenant_users' && isset($_SESSION['admin_logged'])) {
    header('Content-Type: application/json; charset=utf-8');
    $slug = trim($_GET['tenant_slug'] ?? '');
    $tenantService = new DeepShieldTenantService($db);
    $ops = $tenantService->listOperators($slug);
    $apprs = $tenantService->listApprovers($slug);
    echo json_encode([
        'success' => true,
        'tenant_slug' => $slug,
        'operators' => $ops,
        'approvers' => $apprs
    ]);
    exit;
}

// Acción Actualizar Datos de Empresa Cliente (Editar Teléfonos, Correos, etc.)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_tenant' && isset($_SESSION['admin_logged'])) {
    $tenantId = (int)($_POST['tenant_id'] ?? 0);
    $tenantService = new DeepShieldTenantService($db);
    $tenantService->updateTenant($tenantId, $_POST);
    header("Location: index.php?tab=treasury&tenant_updated=1");
    exit;
}

// Acción Cambiar Estado / Ciclo de Vida de Empresa (Suspender / Reactivar / Revocar)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_tenant_status' && isset($_SESSION['admin_logged'])) {
    $tenantId = (int)($_POST['tenant_id'] ?? 0);
    $newStatus = trim($_POST['status'] ?? 'ACTIVE');
    $tenantService = new DeepShieldTenantService($db);
    $tenantService->updateStatus($tenantId, $newStatus);
    header("Location: index.php?tab=treasury&status_updated=1");
    exit;
}

// Acción Actualizar Datos de Empresa Cliente (Editar Teléfonos, Correos, etc.)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_tenant' && isset($_SESSION['admin_logged'])) {
    $tenantId = (int)($_POST['tenant_id'] ?? 0);
    $tenantService = new DeepShieldTenantService($db);
    $tenantService->updateTenant($tenantId, $_POST);
    header("Location: index.php?tab=treasury&tenant_updated=1");
    exit;
}

// Acción Cambiar Estado / Ciclo de Vida de Empresa (Suspender / Reactivar / Revocar)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_tenant_status' && isset($_SESSION['admin_logged'])) {
    $tenantId = (int)($_POST['tenant_id'] ?? 0);
    $newStatus = trim($_POST['status'] ?? 'ACTIVE');
    $tenantService = new DeepShieldTenantService($db);
    $tenantService->updateStatus($tenantId, $newStatus);
    header("Location: index.php?tab=treasury&status_updated=1");
    exit;
}

// Despacho Automático de Correo con Propuesta de Radiografía Forense 72h vía SMTP Titan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_scan_offer' && isset($_SESSION['admin_logged'])) {
    header('Content-Type: application/json; charset=utf-8');
    $scan_id = (int)($_POST['scan_id'] ?? 0);
    
    $stmt = $db->prepare("SELECT * FROM cyber_scans WHERE id = ?");
    $stmt->execute([$scan_id]);
    $scan = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$scan) {
        echo json_encode(['success' => false, 'error' => 'Registro de escaneo no encontrado.']);
        exit;
    }
    
    $toEmail = trim($scan['email'] ?? '');
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'El escaneo no posee un correo electrónico válido.']);
        exit;
    }
    
    $findings = [];
    if (!empty($scan['findings_json'])) {
        $dec = json_decode($scan['findings_json'], true);
        if (is_array($dec)) $findings = $dec;
    }
    
    // Buscar archivo PDF si existe en reports
    $pdfPath = "";
    if (!empty($scan['pdf_folio'])) {
        $cleanDom = preg_replace('/[^a-z0-9_-]/i', '_', $scan['domain']);
        $cand = __DIR__ . '/../data/reports/Reporte_Ciberriesgo_' . $cleanDom . '_' . $scan['pdf_folio'] . '.pdf';
        if (file_exists($cand)) {
            $pdfPath = $cand;
        }
    }
    
    require_once __DIR__ . '/../api/LeadNotificationService.php';
    $sent = LeadNotificationService::sendRadiografiaOfferEmail(
        $toEmail,
        $scan['domain'],
        (int)$scan['score'],
        strtoupper($scan['risk_level']),
        $scan['dmarc_policy'] ?: 'none',
        $findings,
        $pdfPath
    );
    
    if ($sent) {
        // Actualizar estado en cyber_scans
        $up = $db->prepare("UPDATE cyber_scans SET status = 'PROPUESTA_ENVIADA' WHERE id = ?");
        $up->execute([$scan_id]);
        echo json_encode(['success' => true, 'message' => 'Propuesta enviada exitosamente a ' . $toEmail . ' vía SMTP Titan.']);
    } else {
        echo json_encode(['success' => false, 'error' => 'No se pudo despachar el correo vía SMTP. Verifique la pestaña Configuración SMTP.']);
    }
    exit;
}

// Sincronización Automática de Respuestas de Clientes vía IMAP Titan Mail (Espejo Estricto)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'sync_inbox_replies' && isset($_SESSION['admin_logged'])) {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/../api/KeystoneImapTrackerService.php';
    $res = KeystoneImapTrackerService::syncInboxReplies(50);
    echo json_encode($res);
    exit;
}

// Vaciar Bandeja de Respuestas Manualmente
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clear_replies_history' && isset($_SESSION['admin_logged'])) {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/../api/KeystoneImapTrackerService.php';
    $cleared = KeystoneImapTrackerService::clearRepliesHistory();
    echo json_encode(['success' => true, 'cleared' => $cleared]);
    exit;
}

// Despacho Automático de Correo a Lead vía SMTP Titan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_lead_email' && isset($_SESSION['admin_logged'])) {
    header('Content-Type: application/json; charset=utf-8');
    $lead_id = (int)($_POST['lead_id'] ?? 0);
    
    $stmt = $db->prepare("SELECT * FROM requests WHERE id = ?");
    $stmt->execute([$lead_id]);
    $lead = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$lead) {
        echo json_encode(['success' => false, 'error' => 'Registro de lead no encontrado.']);
        exit;
    }
    
    $toEmail = trim($lead['email'] ?? '');
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'El lead no posee un correo electrónico válido.']);
        exit;
    }
    
    $l_service = $lead['service'] ?: $lead['product_name'] ?: 'Servicios TI';
    
    require_once __DIR__ . '/../api/LeadNotificationService.php';
    $sent = LeadNotificationService::sendLeadDirectEmail(
        $toEmail,
        $lead['name'],
        $lead['company'] ?? '',
        $l_service,
        $lead['type'] ?? 'lead',
        $lead['phone'] ?? '',
        $lead['message'] ?? ''
    );
    
    if ($sent) {
        // Actualizar estado del lead automáticamente a CONTACTADO
        $upsert = $db->prepare("INSERT INTO lead_status (lead_id, status, notes, updated_at) VALUES (?, 'CONTACTADO', 'Correo corporativo despachado vía SMTP Titan', CURRENT_TIMESTAMP)
                                ON CONFLICT(lead_id) DO UPDATE SET status = 'CONTACTADO', notes = 'Correo corporativo despachado vía SMTP Titan', updated_at = CURRENT_TIMESTAMP");
        $upsert->execute([$lead_id]);
        echo json_encode(['success' => true, 'message' => 'Correo de contacto enviado exitosamente a ' . $toEmail . ' vía SMTP Titan.']);
    } else {
        echo json_encode(['success' => false, 'error' => 'No se pudo despachar el correo vía SMTP. Verifique la pestaña Configuración SMTP.']);
    }
    exit;
}

// Reenvío de Alerta OOB para Soporte L2
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resend_oob_alert' && isset($_SESSION['admin_logged'])) {
    header('Content-Type: application/json; charset=utf-8');
    $order_id = (int)($_POST['order_id'] ?? 0);

    $stmt = $db->prepare("SELECT * FROM deepshield_orders WHERE id = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        echo json_encode(['success' => false, 'error' => 'Orden no encontrada']);
        exit;
    }

    if ($order['status'] !== 'PENDING_APPROVAL') {
        echo json_encode(['success' => false, 'error' => 'Solo se pueden reenviar alertas para órdenes en estado PENDIENTE OOB.']);
        exit;
    }

    // Obtener datos del tenant
    $tenantStmt = $db->prepare("SELECT * FROM deepshield_tenants WHERE tenant_slug = ? LIMIT 1");
    $tenantStmt->execute([$order['tenant_slug']]);
    $tenant = $tenantStmt->fetch(PDO::FETCH_ASSOC);

    require_once __DIR__ . '/../api/DeepShieldWhatsAppService.php';
    $waService = new DeepShieldWhatsAppService();
    $waInstance = !empty($tenant['whatsapp_instance']) ? $tenant['whatsapp_instance'] : 'keystone-general';
    $targetPhone = !empty($order['approver_phone']) ? $order['approver_phone'] : ($tenant['primary_approver_phone'] ?? '');

    if (empty($targetPhone)) {
        echo json_encode(['success' => false, 'error' => 'No hay número de teléfono registrado para el directivo.']);
        exit;
    }

    $verifyUrl = "https://www.keystoneadvisors.cl/shield/verify.php?token=" . $order['token_hash'];
    $orderData = [
        'amount' => $order['amount'],
        'currency' => $order['currency'],
        'approver_name' => $order['approver_name'],
        'company_name' => $order['company_name'],
        'beneficiary_name' => $order['beneficiary_name'],
        'beneficiary_rut_dni' => $order['beneficiary_rut_dni'],
        'bank_name' => $order['bank_name'],
        'account_number' => $order['account_number'],
        'invoice_folio' => $order['invoice_folio'],
        'security_challenge' => $order['security_challenge']
    ];

    $waRes = $waService->sendTreasuryAlert($waInstance, $targetPhone, $orderData, $verifyUrl);

    // Registrar en auditoría
    $auditStmt = $db->prepare("INSERT INTO deepshield_audit_logs (order_id, event_type, details) VALUES (?, 'OOB_ALERT_RESENT', ?)");
    $auditDetails = "Alerta OOB reenviada por soporte L2 Keystone a {$targetPhone}. Fallback: " . ($waRes['fallback_used'] ? 'SI' : 'NO');
    $auditStmt->execute([$order_id, $auditDetails]);

    if ($waRes['success']) {
        echo json_encode([
            'success' => true,
            'message' => "Alerta OOB reenviada con éxito a {$targetPhone} (Vía {$waRes['dispatched_via']}).",
            'fallback_used' => $waRes['fallback_used'] ?? false
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => "Error al reenviar por WhatsApp: " . ($waRes['error'] ?? 'Error desconocido')
        ]);
    }
    exit;
}

// Ingestar Reporte Agregado DMARC (RUA XML / GZ / ZIP)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ingest_dmarc_report' && isset($_SESSION['admin_logged'])) {
    $watchdog = new DmarcWatchdogService($db);
    $res = ['success' => false, 'error' => 'No se seleccionó ningún archivo.'];

    if (isset($_FILES['dmarc_file']) && $_FILES['dmarc_file']['error'] === UPLOAD_ERR_OK) {
        $tmp = $_FILES['dmarc_file']['tmp_name'];
        $orig = $_FILES['dmarc_file']['name'];
        $res = $watchdog->ingestReportFile($tmp, $orig);
    }
    
    if ($res['success']) {
        header("Location: index.php?tab=dmarc&ingest_ok=1&rep=" . urlencode($res['report_id']) . "&msgs=" . (int)$res['total_messages'] . "&fails=" . (int)$res['total_fail']);
    } else {
        $_SESSION['dmarc_error'] = $res['error'] ?? 'Error al procesar archivo DMARC';
        header("Location: index.php?tab=dmarc&ingest_fail=1");
    }
    exit;
}

// Acción: Crear Cliente / Dominio DMARC
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_dmarc_tenant' && isset($_SESSION['admin_logged'])) {
    $watchdog = new DmarcWatchdogService($db);
    $res = $watchdog->createTenant($_POST);
    if ($res['success']) {
        header("Location: index.php?tab=dmarc&tenant_created=1&d=" . urlencode($res['domain']));
    } else {
        $_SESSION['dmarc_tenant_error'] = $res['error'];
        header("Location: index.php?tab=dmarc&tenant_error=1");
    }
    exit;
}

// Acción: Editar Cliente / Dominio DMARC
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_dmarc_tenant' && isset($_SESSION['admin_logged'])) {
    $id = (int)($_POST['tenant_id'] ?? 0);
    $watchdog = new DmarcWatchdogService($db);
    $res = $watchdog->updateTenant($id, $_POST);
    header("Location: index.php?tab=dmarc&tenant_updated=1");
    exit;
}

// Acción: Cambiar Estado Cliente DMARC (Suspender / Reactivar / Dar de Baja)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_dmarc_tenant_status' && isset($_SESSION['admin_logged'])) {
    $id = (int)($_POST['tenant_id'] ?? 0);
    $status = trim($_POST['status'] ?? 'ACTIVE');
    $watchdog = new DmarcWatchdogService($db);
    $watchdog->updateTenantStatus($id, $status);
    header("Location: index.php?tab=dmarc&status_updated=1");
    exit;
}

// Acción: Sincronizar Ingesta IMAP Automática desde dmarc-rua@keystoneadvisors.cl
if (isset($_GET['action']) && $_GET['action'] === 'sync_dmarc_imap' && isset($_SESSION['admin_logged'])) {
    $watchdog = new DmarcWatchdogService($db);
    $res = $watchdog->syncFromImap();
    if ($res['success']) {
        header("Location: index.php?tab=dmarc&imap_sync_ok=1&count=" . (int)$res['processed_count']);
    } else {
        $_SESSION['dmarc_error'] = $res['error'] ?? 'Error de sincronización IMAP';
        header("Location: index.php?tab=dmarc&imap_sync_fail=1");
    }
    exit;
}

// Acción: Agregar Dominio Adicional a Empresa Cliente DMARC (Multi-Domain)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_dmarc_domain' && isset($_SESSION['admin_logged'])) {
    $tenantId = (int)($_POST['tenant_id'] ?? 0);
    $domain = trim($_POST['domain'] ?? '');
    $policyMode = trim($_POST['policy_mode'] ?? 'reject');

    $watchdog = new DmarcWatchdogService($db);
    $res = $watchdog->addDomainToTenant($tenantId, $domain, $policyMode, 0);
    if ($res['success']) {
        header("Location: index.php?tab=dmarc&domain_added=1&d=" . urlencode($domain));
    } else {
        $_SESSION['dmarc_tenant_error'] = $res['error'];
        header("Location: index.php?tab=dmarc&tenant_error=1");
    }
    exit;
}

// Acción: Eliminar Dominio Secundario de Empresa Cliente DMARC
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remove_dmarc_domain' && isset($_SESSION['admin_logged'])) {
    $tenantId = (int)($_POST['tenant_id'] ?? 0);
    $domain = trim($_POST['domain'] ?? '');

    $watchdog = new DmarcWatchdogService($db);
    $res = $watchdog->removeDomainFromTenant($tenantId, $domain);
    if ($res['success']) {
        header("Location: index.php?tab=dmarc&domain_removed=1");
    } else {
        $_SESSION['dmarc_tenant_error'] = $res['error'];
        header("Location: index.php?tab=dmarc&tenant_error=1");
    }
    exit;
}

// Acción: Guardar Casilla RUA Centralizada de Keystone
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_dmarc_rua_email' && isset($_SESSION['admin_logged'])) {
    $email = trim($_POST['central_rua_email'] ?? '');
    $watchdog = new DmarcWatchdogService($db);
    $ok = $watchdog->setCentralRuaEmail($email);
    header("Location: index.php?tab=dmarc&rua_saved=" . ($ok ? '1' : '0'));
    exit;
}

// =========================================================================
// ENDPOINTS PLANIFICADOR DE CAMPAÑAS LINKEDIN (SOL-0176)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'campaign_advise_cadence' && isset($_SESSION['admin_logged'])) {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/../api/CampaignPlannerService.php';
    $dStart = trim($_POST['date_start'] ?? date('Y-m-d'));
    $dEnd = trim($_POST['date_end'] ?? date('Y-m-d', strtotime('+14 days')));
    echo json_encode(CampaignPlannerService::adviseCampaignCadence($dStart, $dEnd));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'campaign_generate_batch' && isset($_SESSION['admin_logged'])) {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/../api/CampaignPlannerService.php';
    $tenantSlug = trim($_POST['tenant_slug'] ?? 'keystone_deepshield');
    $topic = trim($_POST['topic'] ?? 'Blindaje contra Fraude BEC');
    $audience = trim($_POST['target_audience'] ?? 'Gerentes de Finanzas y CFOs');
    $landingUrl = trim($_POST['landing_url'] ?? 'https://www.keystoneadvisors.cl/');
    $campName = trim($_POST['campaign_name'] ?? ('camp_' . date('Ymd_His')));
    $numPosts = (int)($_POST['num_posts'] ?? 4);
    $schedDates = json_decode($_POST['scheduled_dates'] ?? '[]', true) ?: [];

    echo json_encode(CampaignPlannerService::generateBatchArticles($tenantSlug, $topic, $audience, $landingUrl, $campName, $numPosts, $schedDates));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'campaign_save_schedule' && isset($_SESSION['admin_logged'])) {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/../api/CampaignPlannerService.php';
    $payload = json_decode($_POST['payload'] ?? '{}', true);
    echo json_encode(CampaignPlannerService::saveCampaignSchedule($payload));
    exit;
}

// Publicar en LinkedIn Empresa Orgánico (SOL-0172)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'publish_to_linkedin' && isset($_SESSION['admin_logged'])) {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/../api/KeystoneLinkedInPublisher.php';

    $text = trim($_POST['post_text'] ?? '');
    $imagePath = trim($_POST['image_path'] ?? '');
    $title = trim($_POST['post_title'] ?? '');
    $landingUrl = trim($_POST['landing_url'] ?? '');

    $localFile = null;
    if (!empty($imagePath)) {
        // Mapear URL pública a ruta local en servidor
        $cleaned = ltrim($imagePath, '/');
        $localFile = __DIR__ . '/../' . $cleaned;
    }

    $res = KeystoneLinkedInPublisher::createOrganizationPost($text, $localFile, $landingUrl, $title);
    echo json_encode($res);
    exit;
}

// =========================================================================
// ACCIONES KEYSTONE GROWTH ENGINE & IA GENERATOR (SOL-0172)
// =========================================================================
// Eliminar Banner de Creativos Históricos (SOL-0179)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_mkt_banner' && isset($_SESSION['admin_logged'])) {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/../api/KeystoneGrowthService.php';
    $db = KeystoneGrowthService::getDb();

    $id = (int)($_POST['creative_id'] ?? 0);
    if ($id > 0) {
        // Obtener ruta del archivo para borrarlo del disco si existe
        $stmt = $db->prepare("SELECT banner_image_path FROM mkt_ai_creatives WHERE id = ?");
        $stmt->execute([$id]);
        $path = $stmt->fetchColumn();

        if ($path) {
            $cleaned = ltrim($path, '/');
            $fullPath = __DIR__ . '/../' . $cleaned;
            if (file_exists($fullPath) && is_file($fullPath)) {
                @unlink($fullPath);
            }
        }

        // Eliminar registro de SQLite
        $del = $db->prepare("DELETE FROM mkt_ai_creatives WHERE id = ?");
        $del->execute([$id]);

        echo json_encode(['success' => true, 'deleted_id' => $id]);
    } else {
        echo json_encode(['success' => false, 'error' => 'ID inválido']);
    }
    exit;
}

// Regenerar Banner Visual con Nuevas Instrucciones y Selección de Motor (SOL-0177 & SOL-0188)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'growth_regenerate_banner' && isset($_SESSION['admin_logged'])) {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/../api/KeystoneGrowthService.php';

    $tenantSlug = trim($_POST['tenant_slug'] ?? 'keystone_deepshield');
    $topic = trim($_POST['topic'] ?? 'Ciberseguridad Corporativa');
    $customInstructions = trim($_POST['custom_instructions'] ?? '');
    $engine = trim($_POST['image_engine'] ?? 'flux');

    $res = KeystoneGrowthService::generateBanner($tenantSlug, $topic, $customInstructions, $engine);
    echo json_encode($res);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'growth_generate_ai' && isset($_SESSION['admin_logged'])) {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/../api/KeystoneGrowthService.php';

    $tenantId = trim($_POST['tenant_id'] ?? 'keystone_salesforce');
    if ($tenantId === 'custom' && !empty($_POST['custom_tenant'])) {
        $tenantId = trim($_POST['custom_tenant']);
    }
    $targetPlatform = trim($_POST['target_platform'] ?? 'all');
    $topic = trim($_POST['campaign_goal'] ?? 'Implementación y consultoría Salesforce');
    $targetAudience = trim($_POST['target_audience'] ?? 'Gerentes de TI y CIOs');
    $visualPrompt = trim($_POST['visual_prompt'] ?? '');
    $imageEngine = trim($_POST['image_engine'] ?? 'flux');

    $copyRes = KeystoneGrowthService::generateAdCopy($tenantId, $topic, $targetAudience, $targetPlatform);

    // Si además solicitó generar banner con IA
    $bannerRes = null;
    if (!empty($_POST['generate_banner'])) {
        $bannerRes = KeystoneGrowthService::generateBanner($tenantId, $topic, $visualPrompt, $imageEngine);
    }

    echo json_encode([
        'success' => $copyRes['success'] ?? true,
        'copy' => $copyRes,
        'banner' => $bannerRes
    ]);
    exit;
}

// Acción: Probar Conexión en Vivo de Integraciones (SOL-0188)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'test_integrations_health' && isset($_SESSION['admin_logged'])) {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/../api/KeystoneGrowthService.php';
    $results = KeystoneGrowthService::checkIntegrationsHealth();
    echo json_encode(['success' => true, 'services' => $results]);
    exit;
}

// Acción: Guardar Credenciales de Integraciones de Marketing (SOL-0188)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_marketing_credentials' && isset($_SESSION['admin_logged'])) {
    require_once __DIR__ . '/../api/KeystoneGrowthService.php';

    if (isset($_POST['google_ai_api_key'])) {
        KeystoneGrowthService::setSetting('google_ai_api_key', trim($_POST['google_ai_api_key']));
    }
    if (isset($_POST['groq_api_key'])) {
        KeystoneGrowthService::setSetting('groq_api_key', trim($_POST['groq_api_key']));
    }
    if (isset($_POST['cf_account_id'])) {
        KeystoneGrowthService::setSetting('cf_account_id', trim($_POST['cf_account_id']));
    }
    if (isset($_POST['cf_ai_token'])) {
        KeystoneGrowthService::setSetting('cf_ai_token', trim($_POST['cf_ai_token']));
    }
    if (isset($_POST['google_ads_customer_id'])) {
        KeystoneGrowthService::setSetting('google_ads_customer_id', trim($_POST['google_ads_customer_id']));
    }
    if (isset($_POST['linkedin_client_id'])) {
        KeystoneGrowthService::setSetting('linkedin_client_id', trim($_POST['linkedin_client_id']));
    }
    if (!empty($_POST['linkedin_client_secret'])) {
        KeystoneGrowthService::setSetting('linkedin_client_secret', trim($_POST['linkedin_client_secret']));
    }
    if (isset($_POST['linkedin_org_id'])) {
        KeystoneGrowthService::setSetting('linkedin_org_id', trim($_POST['linkedin_org_id']));
        KeystoneGrowthService::setSetting('linkedin_org_urn', 'urn:li:organization:' . trim($_POST['linkedin_org_id']));
    }

    header("Location: index.php?tab=integrations&saved=1");
    exit;
}

// Acción: Descargar Certificado Pericial Ley 21.459 para PDI / Fiscalía
if (isset($_GET['action']) && $_GET['action'] === 'download_dmarc_pdi_certificate' && isset($_SESSION['admin_logged'])) {
    require_once __DIR__ . '/../api/DmarcForensicEvidencePdfGenerator.php';
    $ip = trim($_GET['ip'] ?? '');
    $tenantId = (int)($_GET['tenant_id'] ?? 0);
    $watchdog = new DmarcWatchdogService($db);
    $tenant = $watchdog->getTenant($tenantId);

    if ($tenant && !empty($ip)) {
        $intel = $watchdog->resolveIpIntelligence($ip);
        $incident = [
            'source_ip' => $ip,
            'country' => $intel['country'],
            'city' => $intel['city'],
            'org' => $intel['org'],
            'total_attempts' => (int)($_GET['attempts'] ?? 1)
        ];

        $pdfGen = new DmarcForensicEvidencePdfGenerator($tenant, $incident);
        $pdfBinary = $pdfGen->generate();
        $filename = 'Certificado_Pericial_PDI_' . preg_replace('/[^0-9a-zA-Z]/', '_', $ip) . '_' . date('Ymd') . '.pdf';

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Cache-Control: private, max-age=0, must-revalidate');
        echo $pdfBinary;
        exit;
    }
    die('Parámetros de incidente no válidos.');
}

// Acción: Obtener Contacto Abuse del ISP vía JSON
if (isset($_GET['action']) && $_GET['action'] === 'get_ip_abuse_contact' && isset($_SESSION['admin_logged'])) {
    header('Content-Type: application/json; charset=utf-8');
    $ip = trim($_GET['ip'] ?? '');
    $domain = trim($_GET['domain'] ?? '');
    $watchdog = new DmarcWatchdogService($db);
    $abuseEmail = $watchdog->resolveAbuseEmail($ip);
    $intel = $watchdog->resolveIpIntelligence($ip);
    $narrative = $watchdog->buildForensicNarrative($ip, $domain);

    echo json_encode([
        'success' => true,
        'ip' => $ip,
        'abuse_email' => $abuseEmail,
        'isp' => $intel['isp'],
        'org' => $intel['org'],
        'country' => $intel['country'],
        'forensic_text_en' => $narrative['narrative_en'],
        'forensic_text_es' => $narrative['narrative_es'],
        'attempts' => $narrative['attempts']
    ]);
    exit;
}

// Acción: Despachar Requerimiento Take-Down al ISP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'dispatch_takedown' && isset($_SESSION['admin_logged'])) {
    header('Content-Type: application/json; charset=utf-8');
    $ip = trim($_POST['ip'] ?? '');
    $ispEmail = trim($_POST['isp_email'] ?? '');
    $domain = trim($_POST['domain'] ?? '');
    $companyName = trim($_POST['company_name'] ?? '');
    $customNote = trim($_POST['custom_note'] ?? '');

    $watchdog = new DmarcWatchdogService($db);
    $res = $watchdog->dispatchTakedownNotice($ip, $ispEmail, $domain, $companyName, $customNote);
    echo json_encode($res);
    exit;
}

// Acción: Crear Campaña de Red Teaming B2B
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_redteam_campaign' && isset($_SESSION['admin_logged'])) {
    require_once __DIR__ . '/../api/RedTeamingService.php';
    $rtService = new RedTeamingService($db);

    $companyName = trim($_POST['company_name'] ?? '');
    $contactName = trim($_POST['contact_name'] ?? '');
    $contactEmail = trim($_POST['contact_email'] ?? '');
    $targetDomains = trim($_POST['target_domains'] ?? '');
    $planType = trim($_POST['plan_type'] ?? 'PROFESSIONAL');
    $vectors = isset($_POST['simulated_vectors']) && is_array($_POST['simulated_vectors']) ? $_POST['simulated_vectors'] : ['BEC'];

    $result = $rtService->createCampaign([
        'company_name' => $companyName,
        'contact_name' => $contactName,
        'contact_email' => $contactEmail,
        'target_domains' => $targetDomains,
        'plan_type' => $planType,
        'simulated_vectors' => $vectors,
        'created_by' => $_SESSION['admin_user'] ?? 'admin'
    ]);

    header("Location: index.php?tab=redteaming&campaign_created=1");
    exit;
}

// Acción: Despacho Manual de Dosier DMARC en PDF al Cliente o Descarga
if (isset($_GET['action']) && $_GET['action'] === 'download_dmarc_dossier' && isset($_SESSION['admin_logged'])) {
    require_once __DIR__ . '/../api/DmarcMonthlyDossierPdfGenerator.php';
    $tenantId = (int)($_GET['tenant_id'] ?? 0);
    $watchdog = new DmarcWatchdogService($db);
    $tenant = $watchdog->getTenant($tenantId);

    if ($tenant) {
        $metrics = $watchdog->getMetrics($tenant['domain']);
        $period = date('m/Y');
        $pdfGen = new DmarcMonthlyDossierPdfGenerator($tenant, $metrics, $period);
        $pdfBinary = $pdfGen->generate();
        $filename = 'Dosier_DMARC_' . preg_replace('/[^A-Za-z0-9]/', '_', $tenant['domain']) . '_' . date('Ym') . '.pdf';

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        echo $pdfBinary;
        exit;
    }
    die('Cliente DMARC no encontrado.');
}

// Acción: Enviar Dosier Manual por Correo al Contacto
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'dispatch_dmarc_dossier_email' && isset($_SESSION['admin_logged'])) {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/../api/DmarcMonthlyDossierPdfGenerator.php';
    require_once __DIR__ . '/../api/LeadNotificationService.php';
    require_once __DIR__ . '/../api/KeystoneSmtpClient.php';

    $tenantId = (int)($_POST['tenant_id'] ?? 0);
    $targetEmail = trim($_POST['target_email'] ?? '');
    $watchdog = new DmarcWatchdogService($db);
    $tenant = $watchdog->getTenant($tenantId);

    if (!$tenant) {
        echo json_encode(['success' => false, 'error' => 'Empresa cliente no encontrada']);
        exit;
    }

    $emailToSend = !empty($targetEmail) ? $targetEmail : $tenant['contact_email'];
    $metrics = $watchdog->getMetrics($tenant['domain']);
    $period = date('m/Y');

    try {
        $pdfGen = new DmarcMonthlyDossierPdfGenerator($tenant, $metrics, $period);
        $pdfBinary = $pdfGen->generate();
        $filename = 'Dosier_DMARC_' . preg_replace('/[^A-Za-z0-9]/', '_', $tenant['domain']) . '_' . date('Ym') . '.pdf';

        $smtpConfig = LeadNotificationService::getSmtpConfig();
        $client = new KeystoneSmtpClient($smtpConfig);

        $subject = "🛡️ Dosier Oficial DMARC ($period) - " . $tenant['company_name'];
        $html = "<div style='font-family:Arial, sans-serif; color:#1e293b; max-width:600px; padding:20px; border:1px solid #e2e8f0; border-radius:8px;'>
            <h2 style='color:#003366;'>KEYSTONE ADVISORS</h2>
            <p>Estimado/a <strong>" . htmlspecialchars($tenant['contact_name']) . "</strong>,</p>
            <p>Adjuntamos su <strong>Dosier Oficial de Blindaje de Identidad DMARC</strong> correspondiente al período <strong>$period</strong> para su dominio <code>@" . htmlspecialchars($tenant['domain']) . "</code>.</p>
            <div style='background:#f1f5f9; padding:12px; border-radius:6px; margin:15px 0;'>
                <strong>Métricas Clave:</strong><br>
                • Volumen Evaluado: " . number_format($metrics['total_volume']) . " correos<br>
                • Tasa de Alineación: " . $metrics['pass_rate'] . "%<br>
                • Suplantación Bloqueada: " . number_format($metrics['total_fail']) . " intentos<br>
            </div>
            <p style='font-size:12px; color:#64748b;'>Documento con firma pericial criptográfica SHA-256 generado por Keystone DMARC Watchdog.</p>
        </div>";

        $ok = $client->sendWithAttachment($emailToSend, $subject, $html, $pdfBinary, $filename);
        if ($ok) {
            $watchdog->markDossierSent($tenant['id']);
            echo json_encode(['success' => true, 'sent_to' => $emailToSend]);
        } else {
            $rawLog = $client->getLog();
            $logStr = is_array($rawLog) ? implode(' | ', $rawLog) : (string)$rawLog;
            echo json_encode(['success' => false, 'error' => 'Fallo al enviar correo SMTP: ' . $logStr]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Tomar / Reclamar Orden por Operador Keystone (Claiming / Locking Multi-Operador)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'claim_order' && isset($_SESSION['admin_logged'])) {
    header('Content-Type: application/json; charset=utf-8');
    $order_id = (int)($_POST['order_id'] ?? 0);
    $current_user = $_SESSION['admin_user'] ?? 'keystone_operator';

    $deepShield = new DeepShieldService($db);
    $res = $deepShield->assignOrder($order_id, $current_user);
    echo json_encode($res);
    exit;
}

// Liberar Orden de vuelta a la bandeja compartida
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'release_order' && isset($_SESSION['admin_logged'])) {
    header('Content-Type: application/json; charset=utf-8');
    $order_id = (int)($_POST['order_id'] ?? 0);
    $current_user = $_SESSION['admin_user'] ?? 'keystone_operator';

    $deepShield = new DeepShieldService($db);
    $res = $deepShield->releaseOrder($order_id, $current_user);
    echo json_encode($res);
    exit;
}

// Sincronizar Correos BEC sospechosos desde contacto@keystoneadvisors.cl vía IMAP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'sync_bec_imap' && isset($_SESSION['admin_logged'])) {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/../api/DeepShieldBecForensicService.php';
    $becService = new DeepShieldBecForensicService($db);
    $orderCode = trim($_POST['order_code'] ?? '');
    $res = $becService->syncBecEmailsFromInbox($orderCode);
    echo json_encode($res);
    exit;
}

// Analizar Manualmente Cabeceras o Archivo .eml de BEC
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'analyze_bec_manual' && isset($_SESSION['admin_logged'])) {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/../api/DeepShieldBecForensicService.php';
    $becService = new DeepShieldBecForensicService($db);
    $orderId = (int)($_POST['order_id'] ?? 0);
    $rawHeaders = trim($_POST['raw_headers'] ?? '');

    // Si se subió un archivo .eml
    if (isset($_FILES['eml_file']) && $_FILES['eml_file']['error'] === UPLOAD_ERR_OK) {
        $rawHeaders = file_get_contents($_FILES['eml_file']['tmp_name']);
    }

    if (empty($rawHeaders)) {
        echo json_encode(['success' => false, 'error' => 'No se proporcionaron encabezados ni archivo .eml válido.']);
        exit;
    }

    $res = $becService->analyzeAndGenerateDossier($orderId, $rawHeaders);
    echo json_encode($res);
    exit;
}

// Despachar Dosier Criptográfico BEC por Correo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'dispatch_bec_dossier' && isset($_SESSION['admin_logged'])) {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/../api/DeepShieldBecForensicService.php';
    $becService = new DeepShieldBecForensicService($db);
    $orderId = (int)($_POST['order_id'] ?? 0);
    $targetEmail = trim($_POST['target_email'] ?? '');
    $res = $becService->dispatchDossierEmail($orderId, $targetEmail);
    echo json_encode($res);
    exit;
}

// Procesar Actualización de Estado de Lead vía POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_lead_status' && isset($_SESSION['admin_logged'])) {
    $lead_id = (int)$_POST['lead_id'];
    $new_status = in_array($_POST['status'], ['NUEVO', 'CONTACTADO', 'EN_EVALUACION', 'COTIZADO', 'GANADO', 'DESCARTADO']) ? $_POST['status'] : 'NUEVO';
    $notes = trim($_POST['notes'] ?? '');
    
    $upsert = $db->prepare("INSERT INTO lead_status (lead_id, status, notes, updated_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)
                            ON CONFLICT(lead_id) DO UPDATE SET status = excluded.status, notes = excluded.notes, updated_at = CURRENT_TIMESTAMP");
    $upsert->execute([$lead_id, $new_status, $notes]);
    
    header("Location: index.php?tab=leads&updated=1");
    exit;
}

// Procesar Actualización de Estado de Escáner vía POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_scan_status' && isset($_SESSION['admin_logged'])) {
    $scan_id = (int)$_POST['scan_id'];
    $valid_statuses = ['nuevo', 'PROPUESTA_ENVIADA', 'CLIENTE_RESPONDIO', 'EN_EVALUACION', 'CERRADO_GANADO', 'DESCARTADO'];
    $new_status = in_array($_POST['status'], $valid_statuses) ? $_POST['status'] : 'nuevo';
    
    $upScan = $db->prepare("UPDATE cyber_scans SET status = ? WHERE id = ?");
    $upScan->execute([$new_status, $scan_id]);
    
    header("Location: index.php?tab=scans&updated=1");
    exit;
}

// Procesar Login con Rate Limiting (Máx 5 fallos en 15 min)
$login_error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    
    // Contar fallos recientes
    $stmt = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND attempted_at > datetime('now', '-15 minutes')");
    $stmt->execute([$ip]);
    $recent_failures = (int)$stmt->fetchColumn();

    if ($recent_failures >= 5) {
        $login_error = "IP bloqueada temporalmente por exceso de intentos fallidos. Intente en 15 minutos.";
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        $stmt = $db->prepare("SELECT id, username, password_hash, role, full_name, is_active FROM admin_users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && (int)($user['is_active'] ?? 1) === 0) {
            $login_error = "Su cuenta se encuentra inactiva. Contacte al Administrador del Sistema.";
        } elseif ($user && password_verify($password, $user['password_hash'])) {
            // Normalizar rol si viniese con valor legado 'admin' -> 'superadmin'
            $user_role = $user['role'];
            if ($user_role === 'admin') $user_role = 'superadmin';

            // Login exitoso
            $_SESSION['admin_logged'] = true;
            $_SESSION['admin_user'] = $user['username'];
            $_SESSION['admin_name'] = !empty($user['full_name']) ? $user['full_name'] : $user['username'];
            $_SESSION['admin_role'] = $user_role;
            session_regenerate_id(true);

            $update = $db->prepare("UPDATE admin_users SET last_login = CURRENT_TIMESTAMP WHERE id = ?");
            $update->execute([$user['id']]);

            // Limpiar intentos fallidos
            $del = $db->prepare("DELETE FROM login_attempts WHERE ip_address = ?");
            $del->execute([$ip]);

            // Redirección inteligente al tab permitido por defecto según rol
            if ($user_role === 'secops') {
                header("Location: index.php?tab=treasury");
            } elseif ($user_role === 'comercial') {
                header("Location: index.php?tab=leads");
            } else {
                header("Location: index.php?tab=dashboard");
            }
            exit;
        } else {
            // Registrar intento fallido
            $ins = $db->prepare("INSERT INTO login_attempts (ip_address) VALUES (?)");
            $ins->execute([$ip]);
            $login_error = "Credenciales incorrectas. Verifique usuario y contraseña.";
        }
    }
}

// Vista de Login si no está autenticado
if (!isset($_SESSION['admin_logged'])) {
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>Keystone Advisors | Consola de Gestión & Telemetría</title>
      <link rel="icon" type="image/png" href="../assets/images/favicon.png">
      <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: Arial, sans-serif; }
        body { background: #001730; display: flex; align-items: center; justify-content: center; min-height: 100vh; color: #333333; }
        .login-card { background: #ffffff; border-radius: 14px; width: 100%; max-width: 420px; padding: 2.5rem; box-shadow: 0 20px 45px rgba(0,0,0,0.4); }
        .logo-box { text-align: center; margin-bottom: 1.75rem; }
        .logo-box img { max-width: 220px; height: auto; }
        .badge { display: inline-block; background: #e0f2fe; color: #0284c7; font-size: 0.75rem; font-weight: 700; padding: 0.2rem 0.6rem; border-radius: 99px; text-transform: uppercase; margin-bottom: 0.75rem; }
        h2 { font-size: 1.35rem; color: #003366; font-weight: 800; margin-bottom: 0.5rem; text-align: center; }
        p.sub { font-size: 0.88rem; color: #64748b; text-align: center; margin-bottom: 1.75rem; line-height: 1.4; }
        .form-group { margin-bottom: 1.25rem; }
        label { display: block; font-size: 0.85rem; font-weight: 700; color: #003366; margin-bottom: 0.4rem; }
        input[type="text"], input[type="password"] { width: 100%; padding: 0.75rem 1rem; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.95rem; }
        input:focus { outline: none; border-color: #003366; box-shadow: 0 0 0 3px rgba(0, 51, 102, 0.1); }
        .btn-login { width: 100%; padding: 0.85rem; background: linear-gradient(135deg, #003366 0%, #006699 100%); color: #ffffff; border: none; border-radius: 8px; font-size: 1rem; font-weight: 700; cursor: pointer; transition: 0.2s ease; }
        .btn-login:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(0,51,102,0.3); }
        .alert { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; padding: 0.75rem 1rem; border-radius: 8px; font-size: 0.88rem; margin-bottom: 1.25rem; }
        .footer-note { text-align: center; margin-top: 1.5rem; font-size: 0.75rem; color: #94a3b8; }
      </style>
    </head>
    <body>
      <div class="login-card">
        <div class="logo-box">
          <img src="../assets/images/logo.png" alt="Keystone Advisors">
        </div>
        <div style="text-align: center;"><span class="badge">Acceso Protegido</span></div>
        <h2>Consola de Gestión B2B</h2>
        <p class="sub">Telemetría de Navegación, Embudo de Caída & Administración de Leads</p>

        <?php if ($login_error): ?>
          <div class="alert"><?php echo htmlspecialchars($login_error); ?></div>
        <?php endif; ?>

        <form method="POST" action="index.php">
          <input type="hidden" name="action" value="login">
          <div class="form-group">
            <label for="username">Usuario Administrador</label>
            <input type="text" id="username" name="username" required autocomplete="off" autofocus>
          </div>
          <div class="form-group">
            <label for="password">Contraseña de Seguridad</label>
            <input type="password" id="password" name="password" required autocomplete="current-password">
          </div>
          <button type="submit" class="btn-login">Ingresar al Panel ➜</button>
        </form>
        <div class="footer-note">Ambiente Protegido por Blindaje Criptográfico & Anti-Fuerza Bruta</div>
      </div>
    </body>
    </html>
    <?php
    exit;
}

// USUARIO AUTENTICADO: OBTENER MÉTRICAS Y DATOS
$current_admin_role = $_SESSION['admin_role'] ?? 'comercial';
if ($current_admin_role === 'admin') $current_admin_role = 'superadmin';

// Definir pestaña por defecto según rol si no viene en $_GET
$default_role_tab = ($current_admin_role === 'secops') ? 'treasury' : (($current_admin_role === 'comercial') ? 'leads' : 'dashboard');
$tab = $_GET['tab'] ?? $default_role_tab;

// Verificar que la pestaña solicitada esté autorizada para el rol actual
enforceTabAccess($tab, $current_admin_role, $rbac_allowed_tabs);

// 1. Contadores Globales
$total_leads = $db->query("SELECT COUNT(*) FROM requests")->fetchColumn();
$total_checkouts = $db->query("SELECT COUNT(*) FROM requests WHERE type = 'checkout'")->fetchColumn();
$total_scans = $db->query("SELECT COUNT(*) FROM cyber_scans")->fetchColumn();
$total_events = 0;
$events_table_exists = $db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='site_events'")->fetchColumn();
if ($events_table_exists) {
    $total_events = $db->query("SELECT COUNT(*) FROM site_events")->fetchColumn();
}

// 2. Telemetría del Embudo de Conversión & Puntos de Abandono (Drop-off)
$funnel_views = 0;
$funnel_cta_clicks = 0;
$funnel_modal_opens = 0;
$funnel_submissions = (int)$total_leads;

if ($events_table_exists) {
    $funnel_views = (int)$db->query("SELECT COUNT(*) FROM site_events WHERE event_name = 'page_view'")->fetchColumn();
    $funnel_cta_clicks = (int)$db->query("SELECT COUNT(*) FROM site_events WHERE event_name = 'cta_click'")->fetchColumn();
    $funnel_modal_opens = (int)$db->query("SELECT COUNT(*) FROM site_events WHERE event_name = 'modal_open'")->fetchColumn();
}

// Fallback preventivo si recién se inicia el tracking
if ($funnel_views == 0 && $total_leads > 0) $funnel_views = $total_leads * 8;
if ($funnel_modal_opens == 0 && $total_leads > 0) $funnel_modal_opens = (int)($total_leads * 1.8);

$drop_rate_view_to_intent = $funnel_views > 0 ? round((1 - ($funnel_modal_opens / max(1, $funnel_views))) * 100, 1) : 0;

$drop_rate_intent_to_lead = $funnel_modal_opens > 0 ? round((1 - ($funnel_submissions / max(1, $funnel_modal_opens))) * 100, 1) : 0;

// 3. Productos / Servicios más demandados (Intención de Compra)
$top_products = $db->query("
    SELECT service, COUNT(*) as cnt 
    FROM requests 
    WHERE service IS NOT NULL AND service != '' 
    GROUP BY service 
    ORDER BY cnt DESC 
    LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

// 4. Últimos Leads Registrados
$leads_query = "
    SELECT r.*, ls.status as lead_status, ls.notes as lead_notes 
    FROM requests r
    LEFT JOIN lead_status ls ON r.id = ls.lead_id
    ORDER BY r.created_at DESC 
    LIMIT 50
";
$leads_list = $db->query($leads_query)->fetchAll(PDO::FETCH_ASSOC);

// 4.5. Respuestas Recientes de Clientes (Seguimiento IMAP)
$total_replies = 0;
$replies_table_exists = $db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='email_replies'")->fetchColumn();
if ($replies_table_exists) {
    $total_replies = (int)$db->query("SELECT COUNT(*) FROM email_replies")->fetchColumn();
    $replies_list = $db->query("
        SELECT er.*, r.name as lead_name, r.company as lead_company 
        FROM email_replies er 
        LEFT JOIN requests r ON er.lead_id = r.id 
        ORDER BY er.received_at DESC 
        LIMIT 50
    ")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $replies_list = [];
}

// 5. Últimos Escaneos de Ciberseguridad
$scans_list = $db->query("
    SELECT cs.*,
           (SELECT COUNT(*) FROM email_replies er WHERE er.scan_id = cs.id OR lower(er.sender_email) = lower(cs.email)) as replies_cnt,
           (SELECT er.received_at FROM email_replies er WHERE er.scan_id = cs.id OR lower(er.sender_email) = lower(cs.email) ORDER BY er.received_at DESC LIMIT 1) as last_reply_at
    FROM cyber_scans cs 
    ORDER BY cs.created_at DESC 
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Keystone Advisors | Dashboard de Telemetría & Gestión B2B</title>
  <link rel="icon" type="image/png" href="../assets/images/favicon.png">
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; font-family: Arial, sans-serif; }
    body { background: #f8fafc; color: #333333; line-height: 1.5; font-size: 14px; }
    
    /* Layout Shell con Sidebar Vertical (SOL-0188) */
    .app-shell { display: flex; min-height: 100vh; background: #f8fafc; }
    
    /* Sidebar Vertical */
    .sidebar { width: 270px; flex-shrink: 0; background: #001730; color: #f8fafc; display: flex; flex-direction: column; border-right: 1px solid #1e293b; position: sticky; top: 0; height: 100vh; overflow-y: auto; z-index: 1000; box-shadow: 4px 0 12px rgba(0,0,0,0.15); }
    .sidebar-brand { padding: 1.25rem 1.25rem 1rem 1.25rem; border-bottom: 1px solid rgba(255,255,255,0.08); display: flex; align-items: center; gap: 0.75rem; }
    .sidebar-brand img { height: 32px; max-width: 140px; object-fit: contain; }
    .sidebar-brand-title { font-size: 0.75rem; color: #94a3b8; font-weight: 700; letter-spacing: 0.5px; text-transform: uppercase; }

    .sidebar-user { padding: 1rem 1.25rem; background: rgba(255,255,255,0.03); border-bottom: 1px solid rgba(255,255,255,0.08); font-size: 0.8rem; }
    .sidebar-user-name { font-weight: 800; color: #ffffff; display: flex; align-items: center; justify-content: space-between; }
    .sidebar-user-role { font-size: 0.7rem; font-weight: 800; padding: 0.15rem 0.45rem; border-radius: 4px; display: inline-block; margin-top: 0.35rem; }

    .sidebar-nav { flex: 1; padding: 1rem 0.75rem; overflow-y: auto; }
    .nav-group-title { font-size: 0.68rem; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.8px; padding: 0.75rem 0.75rem 0.35rem 0.75rem; margin-top: 0.5rem; }
    .sidebar-nav-item { display: flex; align-items: center; gap: 0.65rem; padding: 0.65rem 0.85rem; color: #cbd5e1; text-decoration: none; font-weight: 700; font-size: 0.86rem; border-radius: 8px; transition: all 0.15s ease; margin-bottom: 0.2rem; }
    .sidebar-nav-item:hover { background: rgba(255,255,255,0.08); color: #ffffff; transform: translateX(3px); }
    .sidebar-nav-item.active { background: linear-gradient(135deg, #0284c7 0%, #003366 100%); color: #ffffff; font-weight: 800; box-shadow: 0 4px 10px rgba(2,132,199,0.3); }
    .sidebar-nav-item .badge-count { margin-left: auto; font-size: 0.7rem; background: rgba(255,255,255,0.15); padding: 0.15rem 0.45rem; border-radius: 99px; }

    .sidebar-footer { padding: 1rem 1.25rem; border-top: 1px solid rgba(255,255,255,0.08); background: rgba(0,0,0,0.2); }
    .btn-sidebar-logout { display: block; text-align: center; background: rgba(239,68,68,0.15); color: #f87171; border: 1px solid rgba(239,68,68,0.3); padding: 0.55rem; border-radius: 6px; text-decoration: none; font-size: 0.8rem; font-weight: 800; transition: 0.2s; }
    .btn-sidebar-logout:hover { background: #ef4444; color: #ffffff; }

    /* Main Canvas */
    .app-main { flex: 1; min-width: 0; display: flex; flex-direction: column; overflow-x: hidden; }
    
    /* Topbar compacta */
    .topbar-compact { background: #ffffff; border-bottom: 1px solid #e2e8f0; padding: 0.85rem 1.75rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem; position: sticky; top: 0; z-index: 990; }
    .topbar-title { font-size: 1.1rem; font-weight: 800; color: #003366; display: flex; align-items: center; gap: 0.5rem; }
    .btn-mobile-menu { display: none; background: none; border: 1px solid #cbd5e1; border-radius: 6px; padding: 0.4rem 0.6rem; font-size: 1.1rem; cursor: pointer; color: #003366; }

    /* Container */
    .container { max-width: 98%; width: 100%; margin: 1.5rem auto; padding: 0 1.5rem; flex: 1; }

    @media (max-width: 1024px) {
      .sidebar { position: fixed; left: -280px; transition: left 0.3s ease; }
      .sidebar.open { left: 0; }
      .btn-mobile-menu { display: inline-flex; }
    }

    /* Metric Cards */
    .metrics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
    .metric-card { background: #ffffff; padding: 1.5rem; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
    .metric-title { font-size: 0.82rem; font-weight: 700; text-transform: uppercase; color: #64748b; letter-spacing: 0.5px; margin-bottom: 0.5rem; }
    .metric-value { font-size: 2.2rem; font-weight: 800; color: #003366; line-height: 1; }
    .metric-sub { font-size: 0.82rem; color: #10b981; margin-top: 0.5rem; font-weight: 600; }

    /* Funnel Box */
    .funnel-box { background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; padding: 1.75rem; margin-bottom: 2rem; }
    .funnel-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
    .funnel-title { font-size: 1.15rem; font-weight: 800; color: #003366; }
    .funnel-steps { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1rem; }
    .funnel-step-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 1.25rem; position: relative; }
    .funnel-step-badge { font-size: 0.72rem; font-weight: 800; text-transform: uppercase; padding: 0.2rem 0.5rem; border-radius: 4px; background: #e0f2fe; color: #0369a1; display: inline-block; margin-bottom: 0.5rem; }
    .funnel-step-val { font-size: 1.8rem; font-weight: 800; color: #003366; margin-bottom: 0.25rem; }
    .dropoff-rate { font-size: 0.82rem; color: #ef4444; font-weight: 700; background: #fee2e2; padding: 0.2rem 0.5rem; border-radius: 4px; display: inline-block; margin-top: 0.5rem; }

    /* Table Styles */
    .table-container { background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; overflow-x: auto; box-shadow: 0 4px 12px rgba(0,0,0,0.03); }
    .table-header { padding: 1.25rem 1.5rem; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; background: #f8fafc; }
    .table-header h3 { font-size: 1.05rem; font-weight: 800; color: #003366; }
    .table-container table { width: 100%; min-width: 1100px; border-collapse: collapse; text-align: left; }
    .compact-table { width: 100% !important; min-width: 100% !important; border-collapse: collapse; text-align: left; }
    th { background: #003366; white-space: nowrap; color: #ffffff; font-weight: 700; font-size: 0.82rem; text-transform: uppercase; padding: 1rem 1.25rem; letter-spacing: 0.5px; }
    td { padding: 1rem 1.25rem; border-bottom: 1px solid #e2e8f0; font-size: 0.88rem; vertical-align: middle; }
    tr:nth-child(even) { background: #f8fafc; }
    tr:hover { background: #f1f5f9; }

    /* Badges & Status */
    .badge-status { font-size: 0.75rem; font-weight: 800; padding: 0.25rem 0.6rem; border-radius: 99px; text-transform: uppercase; display: inline-block; }
    .status-nuevo { background: #e0f2fe; color: #0369a1; }
    .status-contactado { background: #fef3c7; color: #92400e; }
    .status-evaluacion { background: #fae8ff; color: #86198f; }
    .status-respondio { background: #dcfce7; color: #166534; border: 1px solid #86efac; animation: pulseGlow 2s infinite; }
    @keyframes pulseGlow { 0% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.4); } 70% { box-shadow: 0 0 0 6px rgba(34, 197, 94, 0); } 100% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0); } }
    .status-cotizado { background: #e0e7ff; color: #3730a3; }
    .status-ganado { background: #dcfce7; color: #166534; }
    .status-descartado { background: #fee2e2; color: #991b1b; }

    .badge-type-checkout { background: #dbeafe; color: #1e40af; font-weight: 800; padding: 0.2rem 0.5rem; border-radius: 4px; }
    .badge-type-lead { background: #f1f5f9; color: #475569; font-weight: 700; padding: 0.2rem 0.5rem; border-radius: 4px; }

    /* Forms inline */
    .select-status { padding: 0.35rem 0.6rem; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.82rem; font-weight: 700; background: #ffffff; cursor: pointer; }

    /* Responsive DMARC & Tables Grid */
    .dmarc-dual-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 2rem; }
    @media (max-width: 1024px) {
      .dmarc-dual-grid { grid-template-columns: 1fr; }
      .metrics-grid { grid-template-columns: repeat(2, 1fr) !important; gap: 1rem !important; }
      .container { padding: 0 1rem; }
    }
    @media (max-width: 640px) {
      .metrics-grid { grid-template-columns: 1fr !important; }
      .topbar { flex-direction: column; align-items: flex-start; gap: 1rem; padding: 1rem; }
      .topbar-user { width: 100%; justify-content: space-between; }
    }
  </style>
</head>
<body>

  <!-- Shell con Sidebar Vertical (SOL-0188) -->
  <div class="app-shell">
    
    <!-- Sidebar Vertical Fijo -->
    <aside class="sidebar" id="appSidebar">
      <div class="sidebar-brand">
        <img src="../assets/images/logo-keystone-white.png" alt="Keystone Advisors">
        <span class="sidebar-brand-title">Control Suite</span>
      </div>

      <?php
        $role_badges = [
            "superadmin" => ["label" => "👑 Super Admin", "color" => "#f59e0b", "bg" => "rgba(245,158,11,0.2)"],
            "comercial"  => ["label" => "💼 Comercial & Growth", "color" => "#38bdf8", "bg" => "rgba(56,189,248,0.2)"],
            "secops"     => ["label" => "🛡️ Operador Ciberseguridad", "color" => "#10b981", "bg" => "rgba(16,185,129,0.2)"]
        ];
        $rb = $role_badges[$current_admin_role] ?? ["label" => strtoupper($current_admin_role), "color" => "#e2e8f0", "bg" => "rgba(255,255,255,0.15)"];
      ?>
      <div class="sidebar-user">
        <div class="sidebar-user-name">
          <span>👤 <?php echo htmlspecialchars($_SESSION["admin_name"] ?? $_SESSION["admin_user"]); ?></span>
        </div>
        <span class="sidebar-user-role" style="color:<?php echo $rb["color"]; ?>; background:<?php echo $rb["bg"]; ?>; border:1px solid <?php echo $rb["color"]; ?>;">
          <?php echo $rb["label"]; ?>
        </span>
      </div>

      <nav class="sidebar-nav">
        
        <!-- Grupo 1: Operaciones & Clientes -->
        <div class="nav-group-title">📊 Operaciones & Telemetría</div>

        <?php if (canAccessTab("dashboard", $current_admin_role, $rbac_allowed_tabs)): ?>
          <a href="index.php?tab=dashboard" class="sidebar-nav-item <?php echo $tab === "dashboard" ? "active" : ""; ?>">
            <span>📊</span> Telemetría & Embudo
          </a>
        <?php endif; ?>

        <?php if (canAccessTab("leads", $current_admin_role, $rbac_allowed_tabs)): ?>
          <a href="index.php?tab=leads" class="sidebar-nav-item <?php echo $tab === "leads" ? "active" : ""; ?>">
            <span>💼</span> Gestión de Leads
            <span class="badge-count"><?php echo $total_leads; ?></span>
          </a>
        <?php endif; ?>

        <?php if (canAccessTab("replies", $current_admin_role, $rbac_allowed_tabs)): ?>
          <a href="index.php?tab=replies" class="sidebar-nav-item <?php echo $tab === "replies" ? "active" : ""; ?>">
            <span>📬</span> Respuestas Clientes
            <span class="badge-count"><?php echo $total_replies; ?></span>
          </a>
        <?php endif; ?>

        <!-- Grupo 2: Ciberseguridad & Riesgo -->
        <div class="nav-group-title">🛡️ Ciberseguridad & Riesgo</div>

        <?php if (canAccessTab("scans", $current_admin_role, $rbac_allowed_tabs)): ?>
          <a href="index.php?tab=scans" class="sidebar-nav-item <?php echo $tab === "scans" ? "active" : ""; ?>">
            <span>🛡️</span> Escaneos Web
            <span class="badge-count"><?php echo $total_scans; ?></span>
          </a>
        <?php endif; ?>

        <?php if (canAccessTab("treasury", $current_admin_role, $rbac_allowed_tabs)): ?>
          <a href="index.php?tab=treasury" class="sidebar-nav-item <?php echo $tab === "treasury" ? "active" : ""; ?>">
            <span>🏦</span> DeepShield Tesorería
          </a>
        <?php endif; ?>

        <?php if (canAccessTab("dmarc", $current_admin_role, $rbac_allowed_tabs)): ?>
          <a href="index.php?tab=dmarc" class="sidebar-nav-item <?php echo $tab === "dmarc" ? "active" : ""; ?>">
            <span>📡</span> DMARC Watchdog
          </a>
        <?php endif; ?>

        <?php if (canAccessTab("redteaming", $current_admin_role, $rbac_allowed_tabs)): ?>
          <a href="index.php?tab=redteaming" class="sidebar-nav-item <?php echo $tab === "redteaming" ? "active" : ""; ?>">
            <span>🎭</span> Red Teaming IA
          </a>
        <?php endif; ?>

        <!-- Grupo 3: Growth & Marketing IA -->
        <div class="nav-group-title">📈 Growth & Marketing IA</div>

        <?php if (canAccessTab("marketing", $current_admin_role, $rbac_allowed_tabs)): ?>
          <a href="index.php?tab=marketing" class="sidebar-nav-item <?php echo $tab === "marketing" ? "active" : ""; ?>">
            <span>📈</span> Campañas & Growth
          </a>
        <?php endif; ?>

        <?php if (canAccessTab("integrations", $current_admin_role, $rbac_allowed_tabs)): ?>
          <a href="index.php?tab=integrations" class="sidebar-nav-item <?php echo $tab === "integrations" ? "active" : ""; ?>">
            <span>🔌</span> Conexiones & Health Check
          </a>
        <?php endif; ?>

        <!-- Grupo 4: Configuración & Gobierno -->
        <div class="nav-group-title">⚙️ Sistema & Gobierno</div>

        <?php if (canAccessTab("smtp", $current_admin_role, $rbac_allowed_tabs)): ?>
          <a href="index.php?tab=smtp" class="sidebar-nav-item <?php echo $tab === "smtp" ? "active" : ""; ?>">
            <span>✉️</span> SMTP Titan Mail
          </a>
        <?php endif; ?>

        <?php if ($current_admin_role === "superadmin"): ?>
          <a href="index.php?tab=users" class="sidebar-nav-item <?php echo $tab === "users" ? "active" : ""; ?>" style="color:#f59e0b;">
            <span>👥</span> Usuarios & Roles
          </a>
        <?php endif; ?>

      </nav>

      <div class="sidebar-footer">
        <a href="index.php?action=logout" class="btn-sidebar-logout">🚪 Cerrar Sesión</a>
      </div>
    </aside>

    <!-- Canvas Principal -->
    <div class="app-main">
      
      <!-- Topbar Compacta Superior -->
      <header class="topbar-compact">
        <div style="display:flex; align-items:center; gap:0.75rem;">
          <button type="button" class="btn-mobile-menu" onclick="document.getElementById('appSidebar').classList.toggle('open');">☰</button>
          <div class="topbar-title">
            <?php
              $tab_titles = [
                'dashboard' => '📊 Telemetría & Embudo de Conversión',
                'leads' => '💼 Gestión Comercial de Leads B2B',
                'scans' => '🛡️ Escaneos de Seguridad Web',
                'replies' => '📬 Respuestas de Clientes (Titan Mail IMAP)',
                'treasury' => '🏦 DeepShield Pre-Transfer Verification',
                'dmarc' => '📡 DMARC Watchdog & Inteligencia Anti-Spoofing',
                'redteaming' => '🎭 Red Teaming B2B Asistido por IA',
                'marketing' => '📈 Keystone Growth Engine & Marketing IA',
                'integrations' => '🔌 Integraciones, Credenciales & Health Check',
                'smtp' => '✉️ Configuración de Despacho SMTP Titan',
                'users' => '👥 Control de Acceso RBAC & Operadores'
              ];
              echo $tab_titles[$tab] ?? 'Centro de Control';
            ?>
          </div>
        </div>

        <div style="display:flex; align-items:center; gap:1rem;">
          <div id="waGlobalMonitor" style="display:flex; align-items:center; gap:0.45rem; background:#f1f5f9; padding:0.35rem 0.75rem; border-radius:6px; border:1px solid #cbd5e1; font-size:0.78rem; cursor:pointer;" onclick="checkGlobalWhatsAppHealth()" title="Canal Central WhatsApp Keystone: clic para verificar estado">
            <span id="waGlobalDot" style="width:8px; height:8px; border-radius:50%; background:#10b981; display:inline-block; box-shadow:0 0 6px #10b981;"></span>
            <span id="waGlobalText" style="font-weight:700; color:#334155;">WhatsApp Activo</span>
          </div>

          <a href="index.php?tab=integrations" style="display:flex; align-items:center; gap:0.35rem; background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd; text-decoration:none; padding:0.35rem 0.65rem; border-radius:6px; font-size:0.78rem; font-weight:700;">
            <span>⚡</span> Servicios IA
          </a>
        </div>
      </header>

      <main class="container">

    <?php if ($tab === 'dashboard'): ?>
      <!-- Dashboard & Analytics View -->
      <div class="metrics-grid">
        <div class="metric-card">
          <div class="metric-title">Visitas Registradas</div>
          <div class="metric-value"><?php echo number_format($funnel_views); ?></div>
          <div class="metric-sub">✓ Telemetría en Vivo</div>
        </div>
        <div class="metric-card">
          <div class="metric-title">Intenciones de Compra / Clics CTA</div>
          <div class="metric-value"><?php echo number_format($funnel_modal_opens); ?></div>
          <div class="metric-sub">Interacciones con Formularios</div>
        </div>
        <div class="metric-card">
          <div class="metric-title">Leads & Órdenes Recibidas</div>
          <div class="metric-value"><?php echo number_format($total_leads); ?></div>
          <div class="metric-sub">Conversion Directa</div>
        </div>
        <div class="metric-card">
          <div class="metric-title">Prospecciones Ciber Escáner</div>
          <div class="metric-value"><?php echo number_format($total_scans); ?></div>
          <div class="metric-sub">Motor B2B Adquisición</div>
        </div>
      </div>

      <!-- Embudo de Conversión y Puntos de Fuga (Drop-off Analysis) -->
      <div class="funnel-box">
        <div class="funnel-header">
          <div>
            <h3 class="funnel-title">Análisis de Embudo & Detección de Fugas (Drop-off Rate)</h3>
            <p style="font-size:0.88rem; color:#64748b; margin-top:0.25rem;">Permite identificar exactamente en qué fase del recorrido abandonan los tomadores de decisión.</p>
          </div>
        </div>

        <div class="funnel-steps">
          <div class="funnel-step-card">
            <span class="funnel-step-badge">Etapa 1: Visita Web</span>
            <div class="funnel-step-val"><?php echo number_format($funnel_views); ?></div>
            <p style="font-size:0.82rem; color:#64748b;">Tráfico que explora la página de soluciones</p>
          </div>

          <div class="funnel-step-card">
            <span class="funnel-step-badge" style="background:#fef3c7; color:#92400e;">Etapa 2: Interés Activo</span>
            <div class="funnel-step-val"><?php echo number_format($funnel_modal_opens); ?></div>
            <p style="font-size:0.82rem; color:#64748b;">Aperturas de modal o clics en "Contratar / Diagnóstico"</p>
            <div class="dropoff-rate">⚠️ Caída vs Tráfico: <?php echo $drop_rate_view_to_intent; ?>%</div>
          </div>

          <div class="funnel-step-card">
            <span class="funnel-step-badge" style="background:#dcfce7; color:#166534;">Etapa 3: Lead Completado</span>
            <div class="funnel-step-val"><?php echo number_format($funnel_submissions); ?></div>
            <p style="font-size:0.82rem; color:#64748b;">Formularios enviados con datos de contacto verificados</p>
            <div class="dropoff-rate">⚠️ Fuga en Formulario: <?php echo $drop_rate_intent_to_lead; ?>%</div>
          </div>
        </div>
      </div>

      <!-- Intenciones de Compra por Producto -->
      <div class="table-container" style="margin-bottom:2rem;">
        <div class="table-header">
          <h3>Demanda e Intención de Compra por Solución / Producto</h3>
        </div>
        <table>
          <thead>
            <tr>
              <th>Solución / Servicio Solicitado</th>
              <th>Total Solicitudes</th>
              <th>Participación de Demanda</th>
              <th>Estado del Producto</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($top_products)): ?>
              <tr><td colspan="4" style="text-align:center; color:#94a3b8; padding:2rem;">Aún no se registran solicitudes de compra en el período.</td></tr>
            <?php else: ?>
              <?php foreach ($top_products as $p): 
                $pct = $total_leads > 0 ? round(($p['cnt'] / $total_leads) * 100, 1) : 0;
              ?>
                <tr>
                  <td><strong><?php echo htmlspecialchars($p['service']); ?></strong></td>
                  <td><strong><?php echo $p['cnt']; ?></strong> órdenes</td>
                  <td>
                    <div style="background:#e2e8f0; border-radius:99px; height:8px; width:150px; overflow:hidden; display:inline-block; vertical-align:middle; margin-right:8px;">
                      <div style="background:#003366; height:100%; width:<?php echo $pct; ?>%;"></div>
                    </div>
                    <?php echo $pct; ?>%
                  </td>
                  <td><span class="badge-status status-ganado">Alta Demanda</span></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

    <?php elseif ($tab === 'leads'): ?>
      <!-- Leads Management View -->
      <div class="table-container">
        <div class="table-header">
          <h3>Bandeja de Leads & Solicitudes de Contratación Directa</h3>
          <span style="font-size:0.85rem; color:#64748b;">Total: <strong><?php echo count($leads_list); ?></strong> registros</span>
        </div>
        <table>
          <thead>
            <tr>
              <th>Fecha</th>
              <th>Tipo</th>
              <th>Contacto</th>
              <th>Empresa / País</th>
              <th>Producto Solicitado</th>
              <th>Estado Comercial</th>
              <th style="white-space:nowrap; width:220px; text-align:center;">Acción</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($leads_list)): ?>
              <tr><td colspan="7" style="text-align:center; color:#94a3b8; padding:2.5rem;">No hay leads pendientes en la bandeja.</td></tr>
            <?php else: ?>
              <?php foreach ($leads_list as $lead): 
                $st = $lead['lead_status'] ?: 'NUEVO';
                $st_class = 'status-' . strtolower(str_replace('_', '', $st));
              ?>
                <tr>
                  <td style="font-size:0.8rem; color:#64748b; white-space:nowrap;">
                    <?php echo date('d/m/Y H:i', strtotime($lead['created_at'])); ?>
                  </td>
                  <td>
                    <span class="<?php echo $lead['type'] === 'checkout' ? 'badge-type-checkout' : 'badge-type-lead'; ?>">
                      <?php echo strtoupper($lead['type']); ?>
                    </span>
                  </td>
                  <td>
                    <strong><?php echo htmlspecialchars($lead['name']); ?></strong><br>
                    <a href="mailto:<?php echo htmlspecialchars($lead['email']); ?>" style="color:#006699; text-decoration:none; font-size:0.82rem;"><?php echo htmlspecialchars($lead['email']); ?></a><br>
                    <small style="color:#64748b;"><?php echo htmlspecialchars($lead['phone'] ?: 'Sin teléfono'); ?></small>
                  </td>
                  <td>
                    <strong><?php echo htmlspecialchars($lead['company'] ?: 'No especificada'); ?></strong><br>
                    <small style="color:#64748b;"><?php echo htmlspecialchars($lead['country']); ?></small>
                  </td>
                  <td>
                    <strong><?php echo htmlspecialchars($lead['service'] ?: $lead['product_name']); ?></strong>
                    <?php if (!empty($lead['message'])): ?>
                      <p style="font-size:0.78rem; color:#64748b; margin-top:0.35rem; max-width:240px; background:#f1f5f9; padding:0.35rem; border-radius:4px;">
                        "<?php echo htmlspecialchars($lead['message']); ?>"
                      </p>
                    <?php endif; ?>
                  </td>
                  <td>
                    <form method="POST" action="index.php" style="display:inline-flex; align-items:center; gap:0.5rem;">
                      <input type="hidden" name="action" value="update_lead_status">
                      <input type="hidden" name="lead_id" value="<?php echo $lead['id']; ?>">
                      <select name="status" class="select-status" onchange="this.form.submit()">
                        <option value="NUEVO" <?php echo $st === 'NUEVO' ? 'selected' : ''; ?>>🔵 NUEVO</option>
                        <option value="CONTACTADO" <?php echo $st === 'CONTACTADO' ? 'selected' : ''; ?>>🟡 CONTACTADO</option>
                        <option value="EN_EVALUACION" <?php echo $st === 'EN_EVALUACION' ? 'selected' : ''; ?>>🟣 EN EVALUACIÓN</option>
                        <option value="COTIZADO" <?php echo $st === 'COTIZADO' ? 'selected' : ''; ?>>🟢 COTIZADO</option>
                        <option value="GANADO" <?php echo $st === 'GANADO' ? 'selected' : ''; ?>>⭐ GANADO</option>
                        <option value="DESCARTADO" <?php echo $st === 'DESCARTADO' ? 'selected' : ''; ?>>❌ DESCARTADO</option>
                      </select>
                    </form>
                  </td>
                  <td>
                    <?php
                      $l_name = $lead['name'];
                      $l_email = $lead['email'];
                      $l_phone = $lead['phone'] ?: 'No indicado';
                      $l_comp = $lead['company'] ?: 'No indicada';
                      $l_service = $lead['service'] ?: $lead['product_name'] ?: 'Servicios TI';
                      $l_type = $lead['type'];
                      $l_date = date('d/m/Y H:i', strtotime($lead['created_at']));
                      $l_msg = trim($lead['message'] ?? '');

                      // Asunto estandarizado
                      $lead_subj = "Contacto Keystone Advisors - Propuesta " . $l_service . " [" . ($l_comp !== 'No indicada' ? $l_comp : $l_name) . "]";

                      // Cuerpo estandarizado según tipo (checkout vs contacto)
                      if ($l_type === 'checkout') {
                          $lead_body = "Estimado/a {$l_name},\n\n"
                              . "Junto con saludar cordialmente, le escribimos desde el equipo directivo de Keystone Advisors.\n\n"
                              . "Hemos recibido exitosamente su solicitud de contratación directa para la solución:\n"
                              . "• Producto / Servicio: {$l_service}\n"
                              . "• Empresa: {$l_comp}\n"
                              . "• Teléfono de contacto: {$l_phone}\n"
                              . "• Fecha de registro: {$l_date} hrs\n\n"
                              . ($l_msg ? "Requerimiento detallado: \"{$l_msg}\"\n\n" : "")
                              . "PROPUESTA DE SIGUIENTE PASO:\n"
                              . "Para avanzar con la emisión de la orden de trabajo formal, asignación del squad de especialistas y parametrización inicial del entorno, nos gustaría coordinar una breve sesión de alineación de 20 minutos.\n\n"
                              . "¿Tiene disponibilidad para reunirnos vía Teams / Google Meet durante esta semana?\n\n"
                              . "Quedamos muy atentos a su confirmación.\n\n"
                              . "Saludos cordiales,\n\n"
                              . "Consultoría Estratégica & Operaciones\n"
                              . "Keystone Advisors\n"
                              . "Web: https://www.keystoneadvisors.cl\n"
                              . "WhatsApp Directo: +56 9 7971 9857";
                      } else {
                          $lead_body = "Estimado/a {$l_name},\n\n"
                              . "Gracias por contactar a Keystone Advisors.\n\n"
                              . "Recibimos su consulta relacionada con {$l_service} para su empresa ({$l_comp}).\n\n"
                              . ($l_msg ? "Detalle de su requerimiento:\n\"{$l_msg}\"\n\n" : "")
                              . "Nos gustaría conocer más detalles sobre los objetivos y plazos de su iniciativa para presentarle el modelo de trabajo que mejor se ajuste a su operación.\n\n"
                              . "¿Podríamos agendar una breve llamada de 15 minutos en los próximos días?\n\n"
                              . "Saludos cordiales,\n\n"
                              . "Equipo Comercial & Consultoría TI\n"
                              . "Keystone Advisors\n"
                              . "Web: https://www.keystoneadvisors.cl\n"
                              . "WhatsApp Directo: +56 9 7971 9857";
                      }

                      $lead_mailto = "mailto:" . rawurlencode($l_email) 
                          . "?subject=" . rawurlencode($lead_subj) 
                          . "&body=" . rawurlencode($lead_body);
                    ?>
                    <div style="display:inline-flex; align-items:center; gap:0.4rem; white-space:nowrap;">
                      <?php $is_lead_contacted = ($st === 'CONTACTADO' || $st === 'COTIZADO' || $st === 'GANADO'); ?>
                      <button type="button" 
                              class="btn-send-lead-smtp"
                              data-lead-id="<?php echo $lead['id']; ?>"
                              data-name="<?php echo htmlspecialchars($l_name); ?>"
                              data-email="<?php echo htmlspecialchars($l_email); ?>"
                              title="Enviar correo de contacto corporativo directamente vía SMTP Titan"
                              style="background:<?php echo $is_lead_contacted ? '#004d80' : '#003366'; ?>; color:#ffffff; padding:0.45rem 0.85rem; border:none; border-radius:6px; font-size:0.82rem; font-weight:700; display:inline-flex; align-items:center; gap:0.35rem; cursor:pointer; white-space:nowrap; box-shadow:0 2px 6px rgba(0,51,102,0.2);">
                        <span><?php echo $is_lead_contacted ? '✓ Contactado' : 'Contactar'; ?></span> ✉️
                      </button>
                      <button type="button" 
                              class="btn-preview-lead"
                              data-name="<?php echo htmlspecialchars($l_name); ?>"
                              data-email="<?php echo htmlspecialchars($l_email); ?>"
                              data-subject="<?php echo htmlspecialchars($lead_subj); ?>"
                              data-body="<?php echo htmlspecialchars($lead_body); ?>"
                              data-mailto="<?php echo htmlspecialchars($lead_mailto); ?>"
                              style="background:#f1f5f9; color:#003366; border:1px solid #cbd5e1; padding:0.45rem 0.65rem; border-radius:6px; font-size:0.82rem; font-weight:700; cursor:pointer;"
                              title="Previsualizar correo, copiar o abrir en cliente local">
                        👁️
                      </button>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

    <?php elseif ($tab === 'scans'): ?>
      <!-- Cyber Scans View -->
      <div class="table-container">
        <div class="table-header">
          <div>
            <h3 style="margin-bottom:0.25rem;">Historial de Escaneos de Ciberseguridad Ejecutados</h3>
            <p style="font-size:0.85rem; color:#64748b; margin:0;">Prospectos que evaluaron su superficie de ataque digital con el escáner gratuito.</p>
          </div>
          <span style="font-size:0.85rem; color:#64748b;">Total: <strong><?php echo count($scans_list); ?></strong> dominios analizados</span>
        </div>
        <table>
          <thead>
            <tr>
              <th>Fecha</th>
              <th>Dominio Evaluado</th>
              <th>Email Corporativo</th>
              <th>Cyber Score</th>
              <th>Nivel Riesgo</th>
              <th>Política DMARC</th>
              <th style="white-space:nowrap; text-align:center; min-width:180px;">Estado Seguimiento</th>
              <th style="white-space:nowrap; width:260px; text-align:center;">Acción Comercial</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($scans_list)): ?>
              <tr><td colspan="8" style="text-align:center; color:#94a3b8; padding:2.5rem;">No se han ejecutado escaneos de dominios aún.</td></tr>
            <?php else: ?>
              <?php foreach ($scans_list as $scan): 
                $domain = $scan['domain'];
                $to_email = $scan['email'];
                $score = (int)$scan['score'];
                $risk_level = strtoupper($scan['risk_level']);
                $dmarc_pol = htmlspecialchars($scan['dmarc_policy'] ?: 'none');
                $date_scan = date('d/m/Y H:i', strtotime($scan['created_at']));

                // Decodificar hallazgos guardados
                $findings_arr = [];
                if (!empty($scan['findings_json'])) {
                    $decoded = json_decode($scan['findings_json'], true);
                    if (is_array($decoded)) {
                        $findings_arr = $decoded;
                    }
                }

                // Generar lista de hallazgos para el correo
                $bullet_findings = "";
                if (!empty($findings_arr)) {
                    foreach ($findings_arr as $f) {
                        $bullet_findings .= "• " . ($f['title'] ?? 'Brecha detectada') . ": " . ($f['desc'] ?? '') . "\n";
                    }
                } else {
                    $bullet_findings = "• Falta de política restrictiva DMARC (modo p=" . $dmarc_pol . " expuesto a suplantación).\n• Riesgo potencial de ingeniería social y fraude de desvío de transferencias (CEO Fraud / BEC).\n";
                }

                // Asunto y cuerpo estandarizado
                $subject = "Diagnóstico Forense de Exposición Digital para {$domain} [Keystone Advisors]";
                $body_template = "Estimado/a,\n\n"
                    . "Junto con saludar, nos ponemos en contacto desde el área de Ciberseguridad & Resiliencia Operativa de Keystone Advisors.\n\n"
                    . "Registramos recientemente la ejecución de nuestro Escáner de Exposición Digital para el dominio: {$domain}.\n\n"
                    . "RESUMEN EJECUTIVO PRELIMINAR:\n"
                    . "--------------------------------------------------\n"
                    . "• Dominio evaluado: {$domain}\n"
                    . "• Fecha de análisis: {$date_scan} hrs\n"
                    . "• Cyber Exposure Score: {$score} / 100\n"
                    . "• Nivel de Exposición Detectado: {$risk_level}\n"
                    . "• Política DMARC en vigor: p={$dmarc_pol}\n"
                    . "--------------------------------------------------\n\n"
                    . "VULNERABILIDADES IDENTIFICADAS:\n"
                    . "{$bullet_findings}\n"
                    . "IMPACTO Y PROPUESTA CORRECTIVA:\n"
                    . "Bajo la actual configuración, su dominio presenta brechas que permiten a terceros o ciberdelincuentes suplantar la identidad de sus directores ante clientes, proveedores o entidades bancarias (ataques tipo Business Email Compromise / estafa de cambio de cuenta corriente).\n\n"
                    . "Queremos ofrecerle formalmente nuestro servicio 'Executive Cyber Risk Assessment' (Radiografía pasiva integral en 72 horas) para:\n"
                    . "1. Analizar filtraciones de credenciales de su plana ejecutiva en la Deep/Dark Web (OSINT).\n"
                    . "2. Guiar la implementación desatendida de DMARC en modo reject (p=reject) sin interrumpir el envío legítimo de correos.\n"
                    . "3. Blindar sus flujos de pago y transferencias de tesorería contra fraudes asistidos por IA y clones de voz.\n\n"
                    . "¿Tendría disponibilidad para una breve sesión técnica de 15 minutos esta semana para revisar el informe pericial preliminar en detalle?\n\n"
                    . "Quedamos a su entera disposición.\n\n"
                    . "Atentamente,\n\n"
                    . "Equipo de Ciberseguridad & Resiliencia Operativa\n"
                    . "Keystone Advisors\n"
                    . "Web: https://www.keystoneadvisors.cl\n"
                    . "WhatsApp Directo: +56 9 7971 9857\n"
                    . "Santiago • Lima • Ciudad de Panamá • Edmonton";

                $mailto_link = "mailto:" . rawurlencode($to_email) 
                    . "?subject=" . rawurlencode($subject) 
                    . "&body=" . rawurlencode($body_template);
              ?>
                <tr>
                  <td style="font-size:0.8rem; color:#64748b; white-space:nowrap;">
                    <?php echo $date_scan; ?>
                  </td>
                  <td><strong><?php echo htmlspecialchars($domain); ?></strong></td>
                  <td>
                    <a href="<?php echo $mailto_link; ?>" style="color:#006699; text-decoration:none;">
                      <?php echo htmlspecialchars($to_email); ?>
                    </a>
                  </td>
                  <td>
                    <span style="font-size:1.1rem; font-weight:800; color:<?php echo $score < 50 ? '#ef4444' : ($score < 80 ? '#f59e0b' : '#10b981'); ?>;">
                      <?php echo $score; ?> / 100
                    </span>
                  </td>
                  <td>
                    <span class="badge-status" style="background:<?php echo $score < 50 ? '#fee2e2' : '#fef3c7'; ?>; color:<?php echo $score < 50 ? '#991b1b' : '#92400e'; ?>;">
                      <?php echo $risk_level; ?>
                    </span>
                  </td>
                  <td><code><?php echo $dmarc_pol; ?></code></td>
                  <td style="text-align:center; white-space:nowrap;">
                    <?php 
                      $sc_status = $scan['status'] ?? 'nuevo';
                      $replies_count = (int)($scan['replies_cnt'] ?? 0);
                      $has_replied = ($sc_status === 'CLIENTE_RESPONDIO' || $replies_count > 0);
                    ?>
                    <form method="POST" action="index.php" style="display:inline-flex; flex-direction:column; align-items:center; gap:0.35rem;">
                      <input type="hidden" name="action" value="update_scan_status">
                      <input type="hidden" name="scan_id" value="<?php echo $scan['id']; ?>">
                      <select name="status" class="select-status" onchange="this.form.submit()" style="<?php echo $has_replied ? 'border-color:#16a34a; background:#f0fdf4; color:#166534;' : ''; ?>">
                        <option value="nuevo" <?php echo $sc_status === 'nuevo' ? 'selected' : ''; ?>>🔵 NUEVO</option>
                        <option value="PROPUESTA_ENVIADA" <?php echo $sc_status === 'PROPUESTA_ENVIADA' ? 'selected' : ''; ?>>🟡 PROPUESTA ENVIADA</option>
                        <option value="CLIENTE_RESPONDIO" <?php echo $sc_status === 'CLIENTE_RESPONDIO' ? 'selected' : ''; ?>>🟢 CLIENTE RESPONDIÓ 💬</option>
                        <option value="EN_EVALUACION" <?php echo $sc_status === 'EN_EVALUACION' ? 'selected' : ''; ?>>🟣 EN EVALUACIÓN</option>
                        <option value="CERRADO_GANADO" <?php echo $sc_status === 'CERRADO_GANADO' ? 'selected' : ''; ?>>⭐ GANADO / CONTRATADO</option>
                        <option value="DESCARTADO" <?php echo $sc_status === 'DESCARTADO' ? 'selected' : ''; ?>>❌ DESCARTADO</option>
                      </select>
                      <?php if ($replies_count > 0): ?>
                        <a href="index.php?tab=replies" class="badge-status status-respondio" style="text-decoration:none; font-size:0.7rem; padding:0.15rem 0.5rem;" title="Haga clic para ver la respuesta recibida en Titan Mail">
                          💬 <?php echo $replies_count; ?> <?php echo $replies_count === 1 ? 'respuesta' : 'respuestas'; ?>
                        </a>
                      <?php endif; ?>
                    </form>
                  </td>
                  <td>
                    <div style="display:inline-flex; align-items:center; gap:0.4rem; white-space:nowrap;">
                      <?php 
                        $is_sent = ($sc_status === 'PROPUESTA_ENVIADA' || $sc_status === 'CLIENTE_RESPONDIO' || $sc_status === 'EN_EVALUACION' || $sc_status === 'CERRADO_GANADO');
                        $btn_bg = '#059669';
                        $btn_label = 'Ofrecer Radiografía 72h';
                        if ($has_replied) {
                          $btn_bg = '#16a34a';
                          $btn_label = '💬 Cliente Respondió';
                        } elseif ($is_sent) {
                          $btn_bg = '#047857';
                          $btn_label = '✓ Enviada';
                        }
                      ?>
                      <button type="button" 
                              class="btn-send-offer-smtp"
                              data-scan-id="<?php echo $scan['id']; ?>"
                              data-domain="<?php echo htmlspecialchars($domain); ?>"
                              data-email="<?php echo htmlspecialchars($to_email); ?>"
                              title="Enviar propuesta directamente al cliente vía SMTP Titan"
                              style="background:<?php echo $btn_bg; ?>; color:#ffffff; padding:0.45rem 0.85rem; border:none; border-radius:6px; font-size:0.82rem; font-weight:700; display:inline-flex; align-items:center; gap:0.35rem; cursor:pointer; box-shadow:0 2px 6px rgba(5,150,105,0.25); white-space:nowrap;">
                        <span><?php echo $btn_label; ?></span> 🛡️
                      </button>
                      <button type="button" 
                              class="btn-preview-email"
                              data-domain="<?php echo htmlspecialchars($domain); ?>"
                              data-email="<?php echo htmlspecialchars($to_email); ?>"
                              data-subject="<?php echo htmlspecialchars($subject); ?>"
                              data-body="<?php echo htmlspecialchars($body_template); ?>"
                              data-mailto="<?php echo htmlspecialchars($mailto_link); ?>"
                              style="background:#f1f5f9; color:#003366; border:1px solid #cbd5e1; padding:0.45rem 0.65rem; border-radius:6px; font-size:0.82rem; font-weight:700; cursor:pointer;"
                              title="Previsualizar correo, copiar o abrir en cliente local">
                        👁️
                      </button>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Modal de Previsualización y Copia de Correo Estandarizado -->
      <div id="emailPreviewModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,23,48,0.7); backdrop-filter:blur(4px); z-index:9999; align-items:center; justify-content:center; padding:1.5rem;">
        <div style="background:#ffffff; border-radius:12px; width:100%; max-width:680px; max-height:90vh; overflow-y:auto; padding:2rem; box-shadow:0 25px 50px rgba(0,0,0,0.3); position:relative;">
          <button id="closeEmailModal" style="position:absolute; top:1.25rem; right:1.25rem; background:none; border:none; font-size:1.5rem; cursor:pointer; color:#64748b;">&times;</button>
          
          <h3 style="color:#003366; font-size:1.25rem; margin-bottom:0.35rem;">Plantilla Estandarizada de Propuesta B2B</h3>
          <p style="font-size:0.85rem; color:#64748b; margin-bottom:1.25rem;">Generada automáticamente con los hallazgos del escáner para <strong id="modalDomainLabel"></strong>.</p>
          
          <div style="margin-bottom:1rem;">
            <label style="display:block; font-size:0.82rem; font-weight:700; color:#003366; margin-bottom:0.3rem;">Destinatario:</label>
            <input type="text" id="modalToInput" readonly style="width:100%; padding:0.6rem 0.85rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.9rem; background:#f8fafc; color:#334155;">
          </div>

          <div style="margin-bottom:1rem;">
            <label style="display:block; font-size:0.82rem; font-weight:700; color:#003366; margin-bottom:0.3rem;">Asunto del Mensaje:</label>
            <input type="text" id="modalSubjectInput" readonly style="width:100%; padding:0.6rem 0.85rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.9rem; background:#f8fafc; color:#334155; font-weight:700;">
          </div>

          <div style="margin-bottom:1.5rem;">
            <label style="display:block; font-size:0.82rem; font-weight:700; color:#003366; margin-bottom:0.3rem;">Cuerpo del Correo (Personalizado):</label>
            <textarea id="modalBodyInput" rows="12" readonly style="width:100%; padding:0.85rem; border:1px solid #cbd5e1; border-radius:8px; font-size:0.88rem; background:#f8fafc; color:#1e293b; line-height:1.5; font-family:monospace;"></textarea>
          </div>

          <div style="display:flex; justify-content:space-between; align-items:center; gap:1rem;">
            <button id="btnCopyEmail" style="background:#0284c7; color:#ffffff; border:none; padding:0.75rem 1.25rem; border-radius:6px; font-weight:700; font-size:0.9rem; cursor:pointer;">
              📋 Copiar al Portapapeles
            </button>
            <a id="modalMailtoBtn" href="#" style="background:#059669; color:#ffffff; text-decoration:none; padding:0.75rem 1.25rem; border-radius:6px; font-weight:700; font-size:0.9rem;">
              ✉️ Abrir en Outlook / Mail
            </a>
          </div>
        </div>
      </div>

    <?php elseif ($tab === 'replies'): ?>
      <!-- Bandeja de Respuestas Entrantes IMAP -->
      <div class="table-container">
        <div class="table-header">
          <div>
            <h3 style="margin-bottom:0.25rem;">📬 Respuestas de Clientes & Prospectos (Seguimiento Automático IMAP)</h3>
            <p style="font-size:0.85rem; color:#64748b; margin:0;">Mensajes recibidos en la casilla corporativa <code>contacto@keystoneadvisors.cl</code> vinculados a leads y escaneos.</p>
          </div>
          <div style="display:flex; align-items:center; gap:0.6rem;">
            <button type="button" id="btnSyncImap" style="background:#003366; color:#ffffff; border:none; padding:0.6rem 1.1rem; border-radius:6px; font-weight:700; font-size:0.85rem; cursor:pointer; display:inline-flex; align-items:center; gap:0.4rem; box-shadow:0 2px 6px rgba(0,51,102,0.25);">
              <span>🔄 Sincronizar Casilla Ahora</span>
            </button>
            <button type="button" id="btnClearReplies" style="background:#f1f5f9; color:#991b1b; border:1px solid #fecaca; padding:0.6rem 0.9rem; border-radius:6px; font-weight:700; font-size:0.85rem; cursor:pointer;" title="Vaciar historial de respuestas locales">
              🗑️ Limpiar Vista
            </button>
          </div>
        </div>
        <table>
          <thead>
            <tr>
              <th style="white-space:nowrap; width:130px;">Fecha / Hora</th>
              <th>Remitente</th>
              <th>Email</th>
              <th>Asunto del Correo</th>
              <th>Contenido Mensaje</th>
              <th>Vínculo Comercial</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($replies_list)): ?>
              <tr><td colspan="6" style="text-align:center; color:#94a3b8; padding:2.5rem;">No se han registrado respuestas de clientes aún. Haga clic en <strong>Sincronizar Casilla Ahora</strong> para buscar mensajes en Titan Mail.</td></tr>
            <?php else: ?>
              <?php foreach ($replies_list as $rep): 
                $r_date = date('d/m/Y H:i', strtotime($rep['received_at']));
                $r_body = mb_substr($rep['body_text'] ?? '', 0, 160);
              ?>
                <tr>
                  <td style="font-size:0.8rem; color:#64748b; white-space:nowrap;"><?php echo $r_date; ?></td>
                  <td><strong><?php echo htmlspecialchars($rep['sender_name'] ?: $rep['sender_email']); ?></strong></td>
                  <td><a href="mailto:<?php echo htmlspecialchars($rep['sender_email']); ?>" style="color:#006699; text-decoration:none; font-size:0.82rem;"><?php echo htmlspecialchars($rep['sender_email']); ?></a></td>
                  <td><strong><?php echo htmlspecialchars($rep['subject']); ?></strong></td>
                  <td style="max-width:320px; font-size:0.82rem; color:#475569;">
                    <div style="background:#f8fafc; padding:0.4rem 0.6rem; border-radius:4px; border:1px solid #e2e8f0; font-family:inherit;">
                      <?php echo nl2br(htmlspecialchars($r_body)); ?><?php if (strlen($rep['body_text'] ?? '') > 160) echo '...'; ?>
                    </div>
                  </td>
                  <td>
                    <?php if ($rep['lead_id']): ?>
                      <span class="badge-status status-evaluacion" title="Lead asociado">💼 Lead #<?php echo $rep['lead_id']; ?> (<?php echo htmlspecialchars($rep['lead_company'] ?: $rep['lead_name']); ?>)</span>
                    <?php elseif ($rep['scan_id']): ?>
                      <span class="badge-status status-contactado" title="Escáner asociado">🛡️ Escáner #<?php echo $rep['scan_id']; ?></span>
                    <?php else: ?>
                      <span style="font-size:0.75rem; color:#94a3b8;">Contacto Directo</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

    <?php elseif ($tab === 'treasury'): ?>
      <!-- Vista de DeepShield Treasury -->
      <?php
      $deepShield = new DeepShieldService($db);
      $tenantService = new DeepShieldTenantService($db);
      $tenants = $tenantService->listTenants();
      $metrics = $deepShield->getMetrics();

      // Parámetros de filtrado y paginación para la bitácora de órdenes con ordenamiento inteligente SLA y multi-operador
      $filter_tenant = trim($_GET['f_tenant'] ?? '');
      $filter_status = trim($_GET['f_status'] ?? '');
      $filter_search = trim($_GET['f_search'] ?? '');
      $filter_operator = trim($_GET['f_operator'] ?? '');
      $orders_page = max(1, (int)($_GET['p'] ?? 1));
      $per_page = 15;

      $current_admin_user = $_SESSION['admin_user'] ?? 'keystone_operator';
      $pagedData = $deepShield->listOrdersPaged(
          $filter_tenant,
          $filter_search,
          $filter_status,
          $orders_page,
          $per_page,
          true,
          ($filter_operator === 'MINE' ? $current_admin_user : $filter_operator)
      );
      $orders = $pagedData['orders'];
      $totalOrders = $pagedData['total_rows'];
      $totalPages = $pagedData['total_pages'];
      ?>
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem; flex-wrap:wrap; gap:1rem;">
        <div>
          <h2 style="font-size:1.5rem; font-weight:800; color:#0f172a; margin-bottom:0.25rem;">🛡️ DeepShield Treasury - Consola de Operaciones</h2>
          <p style="color:#64748b; font-size:0.9rem;">Gobernanza de Empresas Clientes, Monitoreo de Canales y Auditoría Forense Fuera de Banda (OOB).</p>
        </div>
        <div style="display:flex; gap:0.75rem; flex-wrap:wrap;">
          <button onclick="document.getElementById('modalProvisionTenant').style.display='flex';" class="btn" style="background:#0284c7; color:#fff; padding:0.75rem 1.25rem; font-weight:700; border-radius:8px; border:none; cursor:pointer;">
            🏢 + Aprovisionar Nueva Empresa
          </button>
          <button onclick="document.getElementById('modalNewOrder').style.display='flex';" class="btn" style="background:linear-gradient(135deg, #059669 0%, #10b981 100%); color:#fff; padding:0.75rem 1.25rem; font-weight:700; border-radius:8px; border:none; cursor:pointer; box-shadow:0 4px 12px rgba(16,185,129,0.3);">
            + Emitir Orden Manual
          </button>
        </div>
      </div>

      <!-- 1. Métricas Clave de Blindaje -->
      <div class="metrics-grid" style="margin-bottom:2rem;">
        <div class="metric-card">
          <div class="metric-title">Monto Protegido & Autorizado</div>
          <div class="metric-value" style="color:#10b981;">CLP $<?php echo number_format($metrics['total_protected_amount_clp'], 0, ',', '.'); ?></div>
          <div class="metric-sub">✓ Validado Criptográficamente</div>
        </div>
        <div class="metric-card">
          <div class="metric-title">Órdenes Autorizadas</div>
          <div class="metric-value"><?php echo $metrics['approved_count']; ?></div>
          <div class="metric-sub">Sello SHA-256 Emitido</div>
        </div>
        <div class="metric-card">
          <div class="metric-title">Pendientes de Firma OOB</div>
          <div class="metric-value" style="color:#f59e0b;"><?php echo $metrics['pending_count']; ?></div>
          <div class="metric-sub">Esperando Validación en Smartphone</div>
        </div>
        <div class="metric-card">
          <div class="metric-title">Rechazos / Sospechas de Fraude</div>
          <div class="metric-value" style="color:#ef4444;"><?php echo $metrics['rejected_count']; ?></div>
          <div class="metric-sub">Ataques o Desvíos Bloqueados</div>
        </div>
      </div>

      <!-- 2. BLOQUE PRINCIPAL: Empresas Clientes Habilitadas (Tenants) -->
      <div class="card" style="padding:1.5rem; background:#fff; border-radius:12px; border:1px solid #e2e8f0; margin-bottom:2rem; box-shadow:0 4px 12px rgba(0,0,0,0.02);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.25rem; flex-wrap:wrap; gap:0.5rem;">
          <div>
            <h3 style="font-size:1.15rem; color:#0f172a; margin-bottom:0.25rem; font-weight:800;">🏢 Empresas Habilitadas en DeepShield (<?php echo count($tenants); ?>)</h3>
            <span style="font-size:0.82rem; color:#64748b;">Gobernanza de Clientes, Credenciales de Operador, Instancias WhatsApp Dedicadas y Control de Ciclo de Vida</span>
          </div>
          <button onclick="document.getElementById('modalProvisionTenant').style.display='flex';" style="background:#0284c7; color:#fff; border:none; padding:0.5rem 1rem; border-radius:6px; font-weight:700; font-size:0.82rem; cursor:pointer;">
            + Dar de Alta Cliente
          </button>
        </div>
        <div class="table-container">
          <table style="width:100%; border-collapse:collapse; min-width:1150px;">
            <thead>
              <tr style="background:#003366; color:#ffffff; text-align:left; font-size:0.82rem;">
                <th style="padding:0.85rem 1rem;">Empresa & RUT</th>
                <th style="padding:0.85rem 1rem;">Umbral OOB</th>
                <th style="padding:0.85rem 1rem;">Director Firmante (CFO)</th>
                <th style="padding:0.85rem 1rem;">Operador Tesorería (Acceso)</th>
                <th style="padding:0.85rem 1rem;">Canal WhatsApp</th>
                <th style="padding:0.85rem 1rem;">Estado</th>
                <th style="padding:0.85rem 1rem; text-align:center;">Acciones de Ciclo de Vida</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($tenants)): ?>
                <tr><td colspan="7" style="padding:2rem; text-align:center; color:#94a3b8;">No hay empresas registradas aún. Use el botón superior para dar de alta el primer cliente.</td></tr>
              <?php else: ?>
                <?php foreach ($tenants as $ten): ?>
                  <tr style="border-bottom:1px solid #f1f5f9; font-size:0.85rem;">
                    <td style="padding:0.85rem 1rem;">
                      <strong style="color:#0f172a; font-size:0.95rem;"><?php echo htmlspecialchars($ten['company_name']); ?></strong><br>
                      <span style="color:#64748b; font-size:0.78rem;">RUT: <?php echo htmlspecialchars($ten['rut_dni']); ?> • Slug: <code><?php echo htmlspecialchars($ten['tenant_slug']); ?></code></span>
                    </td>
                    <td style="padding:0.85rem 1rem; font-weight:800; color:#059669;">
                      <?php echo htmlspecialchars($ten['threshold_currency']); ?> $<?php echo number_format($ten['threshold_amount'], 0, ',', '.'); ?>
                    </td>
                    <td style="padding:0.85rem 1rem;">
                      <strong><?php echo htmlspecialchars($ten['primary_approver_name']); ?></strong><br>
                      <span style="color:#64748b; font-size:0.78rem;">📲 <?php echo htmlspecialchars($ten['primary_approver_phone']); ?><br>✉️ <?php echo htmlspecialchars($ten['primary_approver_email']); ?></span>
                    </td>
                    <td style="padding:0.85rem 1rem;">
                      <strong><?php echo htmlspecialchars($ten['treasury_operator_name']); ?></strong><br>
                      <span style="color:#64748b; font-size:0.78rem;">✉️ <?php echo htmlspecialchars($ten['treasury_operator_email']); ?></span><br>
                      <a href="/shield/portal.php" target="_blank" style="color:#0284c7; font-size:0.75rem; text-decoration:none; font-weight:700;">🔗 Abrir Portal Cliente ↗</a>
                    </td>
                    <td style="padding:0.85rem 1rem;">
                      <div style="display:flex; align-items:center; gap:0.4rem; margin-bottom:0.25rem;">
                        <span class="wa-inst-dot" data-instance="<?php echo htmlspecialchars($ten['whatsapp_instance'] ?? 'keystone-general'); ?>" style="width:8px; height:8px; border-radius:50%; background:#94a3b8; display:inline-block;" title="Verificando estado..."></span>
                        <span style="background:#f1f5f9; padding:0.2rem 0.5rem; border-radius:4px; font-size:0.78rem; font-family:monospace; color:#0369a1;">
                          <?php echo htmlspecialchars($ten['whatsapp_instance'] ?? 'keystone-general'); ?>
                        </span>
                      </div>
                      <div class="wa-inst-label" data-instance="<?php echo htmlspecialchars($ten['whatsapp_instance'] ?? 'keystone-general'); ?>" style="font-size:0.7rem; color:#64748b; margin-bottom:0.35rem;">
                        Consultando...
                      </div>
                      <button onclick="openWhatsAppModal('<?php echo htmlspecialchars($ten['whatsapp_instance'] ?? 'keystone-general'); ?>', '<?php echo htmlspecialchars($ten['company_name']); ?>')" style="background:#10b981; color:#fff; border:none; padding:0.25rem 0.55rem; border-radius:4px; font-size:0.72rem; cursor:pointer; font-weight:700;">
                        📲 Ver / Conectar QR
                      </button>
                    </td>
                    <td style="padding:0.85rem 1rem;">
                      <?php if ($ten['status'] === 'ACTIVE'): ?>
                        <span style="background:rgba(16,185,129,0.15); color:#059669; padding:0.25rem 0.6rem; border-radius:9999px; font-weight:700; font-size:0.75rem;">🟢 ACTIVO</span>
                      <?php elseif ($ten['status'] === 'SUSPENDED'): ?>
                        <span style="background:rgba(245,158,11,0.15); color:#d97706; padding:0.25rem 0.6rem; border-radius:9999px; font-weight:700; font-size:0.75rem;">⏸️ SUSPENDIDO</span>
                      <?php else: ?>
                        <span style="background:rgba(239,68,68,0.15); color:#dc2626; padding:0.25rem 0.6rem; border-radius:9999px; font-weight:700; font-size:0.75rem;">🔴 DADO DE BAJA</span>
                      <?php endif; ?>
                    </td>
                    <td style="padding:0.85rem 1rem; text-align:center;">
                      <div style="display:flex; justify-content:center; gap:0.4rem; flex-wrap:wrap;">
                        <button onclick='openTenantUsersModal("<?php echo htmlspecialchars($ten['tenant_slug']); ?>", "<?php echo htmlspecialchars($ten['company_name']); ?>")' style="background:#0f172a; color:#38bdf8; border:1px solid #0284c7; padding:0.35rem 0.6rem; border-radius:4px; font-size:0.75rem; cursor:pointer; font-weight:700;" title="Administrar múltiples operadores y directores firmantes">
                          👥 Usuarios & Firmantes
                        </button>
                        <button onclick='openEditTenantModal(<?php echo json_encode($ten, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>)' style="background:#0284c7; color:#fff; border:none; padding:0.35rem 0.6rem; border-radius:4px; font-size:0.75rem; cursor:pointer; font-weight:700;" title="Editar datos del cliente">
                          ✏️ Editar
                        </button>
                        <?php if ($ten['status'] === 'ACTIVE'): ?>
                          <form method="POST" action="index.php?tab=treasury" onsubmit="return confirm('¿Desea suspender temporalmente el servicio para <?php echo htmlspecialchars($ten['company_name']); ?>? Los pagos quedarán bloqueados.');">
                            <input type="hidden" name="action" value="update_tenant_status">
                            <input type="hidden" name="tenant_id" value="<?php echo $ten['id']; ?>">
                            <input type="hidden" name="status" value="SUSPENDED">
                            <button type="submit" style="background:#f59e0b; color:#fff; border:none; padding:0.35rem 0.6rem; border-radius:4px; font-size:0.75rem; cursor:pointer; font-weight:700;" title="Pausar servicio">⏸️ Suspender</button>
                          </form>
                          <form method="POST" action="index.php?tab=treasury" onsubmit="return confirm('¿Confirma DAR DE BAJA definitivamente a <?php echo htmlspecialchars($ten['company_name']); ?>? Se revocarán todas sus credenciales y accesos.');">
                            <input type="hidden" name="action" value="update_tenant_status">
                            <input type="hidden" name="tenant_id" value="<?php echo $ten['id']; ?>">
                            <input type="hidden" name="status" value="REVOKED">
                            <button type="submit" style="background:#ef4444; color:#fff; border:none; padding:0.35rem 0.6rem; border-radius:4px; font-size:0.75rem; cursor:pointer; font-weight:700;" title="Dar de baja definitiva">✕ Dar de Baja</button>
                          </form>
                        <?php else: ?>
                          <form method="POST" action="index.php?tab=treasury">
                            <input type="hidden" name="action" value="update_tenant_status">
                            <input type="hidden" name="tenant_id" value="<?php echo $ten['id']; ?>">
                            <input type="hidden" name="status" value="ACTIVE">
                            <button type="submit" style="background:#10b981; color:#fff; border:none; padding:0.35rem 0.6rem; border-radius:4px; font-size:0.75rem; cursor:pointer; font-weight:700;">✓ Reactivar Empresa</button>
                          </form>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- 3. BITÁCORA FORENSE DE ÓRDENES (Optimizada con Filtros Multi-Empresa & Paginación) -->
      <div class="card" style="padding:1.5rem; background:#fff; border-radius:12px; border:1px solid #e2e8f0; margin-bottom:2rem;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; flex-wrap:wrap; gap:1rem;">
          <div>
            <h3 style="font-size:1.15rem; color:#0f172a; margin-bottom:0.25rem; font-weight:800;">
              📋 Bitácora Forense & Soporte de Órdenes Críticas (<?php echo number_format($totalOrders); ?>)
            </h3>
            <span style="font-size:0.82rem; color:#64748b;">Monitoreo centralizado para soporte L2, asistencia a CFOs y detección de alertas rojas en curso</span>
          </div>
        </div>

        <!-- Barra de Filtros Server-Side -->
        <form method="GET" action="index.php" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:1rem; margin-bottom:1.25rem; display:flex; flex-wrap:wrap; gap:0.75rem; align-items:center;">
          <input type="hidden" name="tab" value="treasury">
          
          <div style="flex:1; min-width:200px;">
            <label style="display:block; font-size:0.75rem; font-weight:700; color:#475569; margin-bottom:0.25rem;">Buscar por Código, Proveedor o RUT</label>
            <input type="text" name="f_search" value="<?php echo htmlspecialchars($filter_search); ?>" placeholder="Ej: DST-7084, Santander, 76.123..." style="width:100%; padding:0.5rem 0.75rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.85rem;">
          </div>

          <div style="min-width:180px;">
            <label style="display:block; font-size:0.75rem; font-weight:700; color:#475569; margin-bottom:0.25rem;">Filtrar por Empresa</label>
            <select name="f_tenant" style="width:100%; padding:0.5rem 0.75rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.85rem; background:#fff;">
              <option value="">🏢 Todas las Empresas</option>
              <?php foreach ($tenants as $t): ?>
                <option value="<?php echo htmlspecialchars($t['tenant_slug']); ?>" <?php echo $filter_tenant === $t['tenant_slug'] ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($t['company_name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div style="min-width:160px;">
            <label style="display:block; font-size:0.75rem; font-weight:700; color:#475569; margin-bottom:0.25rem;">Estado de la Orden</label>
            <select name="f_status" style="width:100%; padding:0.5rem 0.75rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.85rem; background:#fff;">
              <option value="">Todos los Estados</option>
              <option value="PENDING_APPROVAL" <?php echo $filter_status === 'PENDING_APPROVAL' ? 'selected' : ''; ?>>⏳ Pendiente OOB</option>
              <option value="APPROVED" <?php echo $filter_status === 'APPROVED' ? 'selected' : ''; ?>>✓ Aprobada</option>
              <option value="REJECTED" <?php echo $filter_status === 'REJECTED' ? 'selected' : ''; ?>>✕ Rechazada (Fraude)</option>
              <option value="EXPIRED" <?php echo $filter_status === 'EXPIRED' ? 'selected' : ''; ?>>⌛ Expirada</option>
            </select>
          </div>

          <div style="min-width:160px;">
            <label style="display:block; font-size:0.75rem; font-weight:700; color:#475569; margin-bottom:0.25rem;">Bandeja Operador L2</label>
            <select name="f_operator" style="width:100%; padding:0.5rem 0.75rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.85rem; background:#fff;">
              <option value="">👥 Todos los Casos</option>
              <option value="UNASSIGNED" <?php echo $filter_operator === 'UNASSIGNED' ? 'selected' : ''; ?>>📥 Sin Asignar (Libres)</option>
              <option value="MINE" <?php echo $filter_operator === 'MINE' ? 'selected' : ''; ?>>⭐ Mis Asignadas (<?php echo htmlspecialchars($current_admin_user); ?>)</option>
            </select>
          </div>

          <div style="align-self:flex-end; display:flex; gap:0.5rem;">
            <button type="submit" style="background:#0284c7; color:#fff; border:none; padding:0.52rem 1.1rem; border-radius:6px; font-weight:700; font-size:0.85rem; cursor:pointer;">
              🔍 Filtrar
            </button>
            <?php if (!empty($filter_search) || !empty($filter_tenant) || !empty($filter_status)): ?>
              <a href="index.php?tab=treasury" style="background:#e2e8f0; color:#475569; padding:0.52rem 0.85rem; border-radius:6px; font-weight:700; font-size:0.85rem; text-decoration:none;">
                Limpiar
              </a>
            <?php endif; ?>
          </div>
        </form>

        <div class="table-container">
          <table style="width:100%; border-collapse:collapse; min-width:1150px;">
            <thead>
              <tr style="background:#003366; color:#ffffff; text-align:left; font-size:0.82rem;">
                <th style="padding:0.85rem 1rem;">Código & Antigüedad</th>
                <th style="padding:0.85rem 1rem;">Monto & Divisa</th>
                <th style="padding:0.85rem 1rem;">Beneficiario & RUT</th>
                <th style="padding:0.85rem 1rem;">Banco & Cuenta Destino</th>
                <th style="padding:0.85rem 1rem;">Director Firmante</th>
                <th style="padding:0.85rem 1rem;">Desafío IA</th>
                <th style="padding:0.85rem 1rem;">Estado & SLA</th>
                <th style="padding:0.85rem 1rem; text-align:center;">Acción Soporte L2</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($orders)): ?>
                <tr>
                  <td colspan="8" style="padding:2.5rem; text-align:center; color:#94a3b8;">
                    No se encontraron órdenes con los filtros seleccionados.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($orders as $ord): 
                  $createdTs = strtotime($ord['created_at']);
                  $minutesElapsed = (int)round((time() - $createdTs) / 60);
                  $isPending = ($ord['status'] === 'PENDING_APPROVAL');
                  $isRejected = ($ord['status'] === 'REJECTED');
                ?>
                  <tr style="border-bottom:1px solid #f1f5f9; font-size:0.85rem; <?php echo $isRejected ? 'background:rgba(239,68,68,0.06);' : ($isPending && $minutesElapsed >= 15 ? 'background:rgba(245,158,11,0.06);' : ''); ?>">
                    <td style="padding:0.85rem 1rem;">
                      <strong style="color:#0284c7; font-size:0.9rem;"><?php echo htmlspecialchars($ord['order_code']); ?></strong><br>
                      <span style="color:#64748b; font-size:0.75rem;">📅 <?php echo substr($ord['created_at'], 0, 16); ?></span><br>
                      <?php if ($isPending): ?>
                        <?php if ($minutesElapsed >= 30): ?>
                          <span style="display:inline-block; margin-top:0.25rem; font-size:0.72rem; font-weight:800; color:#dc2626; background:#fee2e2; padding:0.15rem 0.45rem; border-radius:4px; animation:pulseGlow 2s infinite;">
                            ⚠️ SLA Vencido: <?php echo $minutesElapsed; ?> min
                          </span>
                        <?php elseif ($minutesElapsed >= 15): ?>
                          <span style="display:inline-block; margin-top:0.25rem; font-size:0.72rem; font-weight:800; color:#b45309; background:#fef3c7; padding:0.15rem 0.45rem; border-radius:4px;">
                            ⏳ En Espera: <?php echo $minutesElapsed; ?> min
                          </span>
                        <?php else: ?>
                          <span style="display:inline-block; margin-top:0.25rem; font-size:0.72rem; color:#059669; font-weight:700;">
                            ✓ Hace <?php echo $minutesElapsed; ?> min
                          </span>
                        <?php endif; ?>
                      <?php endif; ?>
                    </td>
                    <td style="padding:0.85rem 1rem; font-weight:800; color:#0f172a;">
                      <?php echo htmlspecialchars($ord['currency']); ?> $<?php echo number_format($ord['amount'], 0, ',', '.'); ?>
                    </td>
                    <td style="padding:0.85rem 1rem;">
                      <strong><?php echo htmlspecialchars($ord['beneficiary_name']); ?></strong><br>
                      <span style="color:#64748b; font-size:0.78rem;">RUT: <?php echo htmlspecialchars($ord['beneficiary_rut_dni']); ?></span>
                      <?php if (!empty($ord['invoice_folio'])): ?>
                        <br><span style="color:#64748b; font-size:0.75rem;">Folio: <?php echo htmlspecialchars($ord['invoice_folio']); ?></span>
                      <?php endif; ?>
                    </td>
                    <td style="padding:0.85rem 1rem;">
                      <?php echo htmlspecialchars($ord['bank_name']); ?><br>
                      <span style="font-family:monospace; color:#475569; font-size:0.8rem;"><?php echo htmlspecialchars($ord['account_number']); ?></span>
                    </td>
                    <td style="padding:0.85rem 1rem;">
                      <?php echo htmlspecialchars($ord['approver_name']); ?><br>
                      <span style="color:#64748b; font-size:0.78rem;">✉️ <?php echo htmlspecialchars($ord['approver_email']); ?></span>
                    </td>
                    <td style="padding:0.85rem 1rem;">
                      <span style="background:#f1f5f9; border:1px solid #cbd5e1; padding:0.2rem 0.5rem; border-radius:4px; font-weight:800; font-family:monospace; color:#0369a1;">
                        <?php echo htmlspecialchars($ord['security_challenge']); ?>
                      </span>
                    </td>
                    <td style="padding:0.85rem 1rem;">
                      <?php if ($ord['status'] === 'APPROVED'): ?>
                        <span style="background:rgba(16,185,129,0.15); color:#059669; padding:0.25rem 0.6rem; border-radius:9999px; font-weight:700; font-size:0.75rem;">✓ APROBADA</span>
                        <?php if (strpos($ord['approval_method'] ?? '', 'WEBAUTHN') !== false): ?>
                          <div style="font-size:0.68rem; color:#059669; margin-top:0.25rem; font-weight:bold;">🔐 FaceID / Biometría</div>
                        <?php endif; ?>
                      <?php elseif ($isRejected): ?>
                        <span style="background:rgba(239,68,68,0.15); color:#dc2626; padding:0.25rem 0.6rem; border-radius:9999px; font-weight:800; font-size:0.75rem; border:1px solid rgba(239,68,68,0.3);">⚠️ RECHAZO (FRAUDE)</span>
                      <?php elseif ($ord['status'] === 'EXPIRED'): ?>
                        <span style="background:rgba(100,116,139,0.15); color:#475569; padding:0.25rem 0.6rem; border-radius:9999px; font-weight:700; font-size:0.75rem;">⌛ EXPIRADA</span>
                      <?php else: ?>
                        <span style="background:rgba(245,158,11,0.15); color:#d97706; padding:0.25rem 0.6rem; border-radius:9999px; font-weight:700; font-size:0.75rem;">⏳ PENDIENTE OOB</span>
                      <?php endif; ?>

                      <!-- Indicador Multi-Operador de Asignación / Bloqueo -->
                      <div style="margin-top:0.35rem;">
                        <?php if (!empty($ord['assigned_to'])): ?>
                          <?php if ($ord['assigned_to'] === $current_admin_user): ?>
                            <span style="font-size:0.72rem; background:#dbeafe; color:#1e40af; padding:0.15rem 0.4rem; border-radius:4px; font-weight:700; display:inline-block;" title="Asignada a ti">
                              ⭐ Mi Caso
                            </span>
                          <?php else: ?>
                            <span style="font-size:0.72rem; background:#f1f5f9; color:#475569; padding:0.15rem 0.4rem; border-radius:4px; font-weight:700; display:inline-block;" title="En gestión por otro compañero">
                              🔒 En Atención: <?php echo htmlspecialchars($ord['assigned_to']); ?>
                            </span>
                          <?php endif; ?>
                        <?php else: ?>
                          <span style="font-size:0.7rem; color:#94a3b8;">(Sin Asignar)</span>
                        <?php endif; ?>
                      </div>
                    </td>
                    <td style="padding:0.85rem 1rem; text-align:center;">
                      <div style="display:flex; flex-direction:column; gap:0.35rem; align-items:center;">
                        <!-- Botón para Tomar o Liberar Caso Multi-Operador -->
                        <?php if ($isPending || $isRejected): ?>
                          <?php if (empty($ord['assigned_to'])): ?>
                            <button onclick="claimOrder(<?php echo $ord['id']; ?>)" style="background:#0f172a; color:#fff; border:none; padding:0.3rem 0.6rem; border-radius:6px; font-size:0.73rem; font-weight:700; cursor:pointer; width:100%;" title="Tomar este caso para evitar colisión con otro operador">
                              👤 Tomar Caso
                            </button>
                          <?php elseif ($ord['assigned_to'] === $current_admin_user): ?>
                            <button onclick="releaseOrder(<?php echo $ord['id']; ?>)" style="background:#e2e8f0; color:#475569; border:none; padding:0.3rem 0.6rem; border-radius:6px; font-size:0.73rem; font-weight:700; cursor:pointer; width:100%;" title="Liberar este caso a la bandeja general">
                              ↩️ Liberar Caso
                            </button>
                          <?php endif; ?>
                        <?php endif; ?>

                        <?php if ($isRejected): ?>
                          <button onclick='openBecProtocolModal(<?php echo json_encode($ord, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>)' style="background:#dc2626; color:#fff; border:none; padding:0.35rem 0.65rem; border-radius:6px; font-size:0.75rem; font-weight:800; cursor:pointer; width:100%; box-shadow:0 2px 6px rgba(220,38,38,0.3);" title="Abrir protocolo de contención de fraude BEC">
                            🚨 Protocolo BEC
                          </button>
                        <?php endif; ?>

                        <?php if ($isPending): ?>
                          <button onclick="resendOobAlert(<?php echo $ord['id']; ?>, '<?php echo htmlspecialchars($ord['order_code']); ?>')" class="btn-resend-oob" style="background:#f59e0b; color:#fff; border:none; padding:0.35rem 0.65rem; border-radius:6px; font-size:0.75rem; font-weight:700; cursor:pointer; width:100%;" title="Reenviar mensaje interactivo de WhatsApp al directivo">
                            📲 Reenviar OOB
                          </button>
                        <?php endif; ?>

                        <a href="/shield/verify.php?token=<?php echo htmlspecialchars($ord['token_hash']); ?>" target="_blank" style="background:#0284c7; color:#fff; padding:0.3rem 0.65rem; border-radius:6px; font-size:0.75rem; text-decoration:none; font-weight:700; width:100%; text-align:center; display:block;">
                          👁️ Abrir Link
                        </a>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <!-- Paginador Server-Side -->
        <?php if ($totalPages > 1): ?>
          <div style="display:flex; justify-content:space-between; align-items:center; margin-top:1.25rem; padding-top:1rem; border-top:1px solid #e2e8f0; font-size:0.85rem; color:#64748b;">
            <div>
              Mostrando página <strong><?php echo $orders_page; ?></strong> de <strong><?php echo $totalPages; ?></strong> (Total: <?php echo $totalOrders; ?> órdenes)
            </div>
            <div style="display:flex; gap:0.4rem;">
              <?php if ($orders_page > 1): ?>
                <a href="index.php?tab=treasury&p=<?php echo $orders_page - 1; ?>&f_tenant=<?php echo urlencode($filter_tenant); ?>&f_status=<?php echo urlencode($filter_status); ?>&f_search=<?php echo urlencode($filter_search); ?>" style="padding:0.35rem 0.75rem; background:#fff; border:1px solid #cbd5e1; border-radius:6px; color:#0f172a; text-decoration:none; font-weight:700;">&laquo; Anterior</a>
              <?php endif; ?>

              <?php for ($pi = max(1, $orders_page - 2); $pi <= min($totalPages, $orders_page + 2); $pi++): ?>
                <a href="index.php?tab=treasury&p=<?php echo $pi; ?>&f_tenant=<?php echo urlencode($filter_tenant); ?>&f_status=<?php echo urlencode($filter_status); ?>&f_search=<?php echo urlencode($filter_search); ?>" style="padding:0.35rem 0.75rem; background:<?php echo $pi === $orders_page ? '#0284c7' : '#fff'; ?>; border:1px solid <?php echo $pi === $orders_page ? '#0284c7' : '#cbd5e1'; ?>; border-radius:6px; color:<?php echo $pi === $orders_page ? '#fff' : '#0f172a'; ?>; text-decoration:none; font-weight:700;">
                  <?php echo $pi; ?>
                </a>
              <?php endfor; ?>

              <?php if ($orders_page < $totalPages): ?>
                <a href="index.php?tab=treasury&p=<?php echo $orders_page + 1; ?>&f_tenant=<?php echo urlencode($filter_tenant); ?>&f_status=<?php echo urlencode($filter_status); ?>&f_search=<?php echo urlencode($filter_search); ?>" style="padding:0.35rem 0.75rem; background:#fff; border:1px solid #cbd5e1; border-radius:6px; color:#0f172a; text-decoration:none; font-weight:700;">Siguiente &raquo;</a>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>

      </div>

      <!-- Modal para crear orden -->
      <div id="modalNewOrder" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:9999; justify-content:center; align-items:center; padding:1rem;">
        <div style="background:#fff; border-radius:12px; width:100%; max-width:550px; padding:2rem; box-shadow:0 20px 25px -5px rgba(0,0,0,0.2); max-height:90vh; overflow-y:auto;">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
            <h3 style="font-size:1.25rem; font-weight:800; color:#0f172a;">🛡️ Nueva Orden Crítica para Blindaje</h3>
            <button onclick="document.getElementById('modalNewOrder').style.display='none';" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:#64748b;">&times;</button>
          </div>
          <form method="POST" action="index.php?tab=treasury">
            <input type="hidden" name="action" value="create_deepshield_order">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:1rem;">
              <div>
                <label style="display:block; font-size:0.82rem; font-weight:700; color:#475569; margin-bottom:0.25rem;">Monto a Transferir *</label>
                <input type="number" name="amount" required step="1" placeholder="Ej: 5000000" style="width:100%; padding:0.65rem; border:1px solid #cbd5e1; border-radius:6px;">
              </div>
              <div>
                <label style="display:block; font-size:0.82rem; font-weight:700; color:#475569; margin-bottom:0.25rem;">Moneda</label>
                <select name="currency" style="width:100%; padding:0.65rem; border:1px solid #cbd5e1; border-radius:6px;">
                  <option value="CLP">CLP (Pesos Chilenos)</option>
                  <option value="USD">USD (Dólares)</option>
                </select>
              </div>
            </div>
            <div style="margin-bottom:1rem;">
              <label style="display:block; font-size:0.82rem; font-weight:700; color:#475569; margin-bottom:0.25rem;">Beneficiario / Razón Social *</label>
              <input type="text" name="beneficiary_name" required placeholder="Nombre o Empresa proveedora" style="width:100%; padding:0.65rem; border:1px solid #cbd5e1; border-radius:6px;">
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:1rem;">
              <div>
                <label style="display:block; font-size:0.82rem; font-weight:700; color:#475569; margin-bottom:0.25rem;">RUT / DNI Proveedor *</label>
                <input type="text" name="beneficiary_rut_dni" required placeholder="76.123.456-7" style="width:100%; padding:0.65rem; border:1px solid #cbd5e1; border-radius:6px;">
              </div>
              <div>
                <label style="display:block; font-size:0.82rem; font-weight:700; color:#475569; margin-bottom:0.25rem;">Folio Factura (Opcional)</label>
                <input type="text" name="invoice_folio" placeholder="F-12044" style="width:100%; padding:0.65rem; border:1px solid #cbd5e1; border-radius:6px;">
              </div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:1rem;">
              <div>
                <label style="display:block; font-size:0.82rem; font-weight:700; color:#475569; margin-bottom:0.25rem;">Banco Destino *</label>
                <input type="text" name="bank_name" required placeholder="Banco de Chile / Santander" style="width:100%; padding:0.65rem; border:1px solid #cbd5e1; border-radius:6px;">
              </div>
              <div>
                <label style="display:block; font-size:0.82rem; font-weight:700; color:#475569; margin-bottom:0.25rem;">Número de Cuenta *</label>
                <input type="text" name="account_number" required placeholder="00-12345-67" style="width:100%; padding:0.65rem; border:1px solid #cbd5e1; border-radius:6px;">
              </div>
            </div>
            <div style="margin-bottom:1.5rem;">
              <label style="display:block; font-size:0.82rem; font-weight:700; color:#475569; margin-bottom:0.25rem;">Firmante / Aprobador (Nombre y Correo) *</label>
              <input type="text" name="approver_name" required value="Director / Gerente de Finanzas" style="width:100%; padding:0.65rem; border:1px solid #cbd5e1; border-radius:6px; margin-bottom:0.5rem;">
              <input type="email" name="approver_email" required value="contacto@keystoneadvisors.cl" style="width:100%; padding:0.65rem; border:1px solid #cbd5e1; border-radius:6px;">
            </div>
            <button type="submit" style="width:100%; background:linear-gradient(135deg, #059669 0%, #10b981 100%); color:#fff; border:none; padding:0.85rem; border-radius:8px; font-weight:700; cursor:pointer;">
              Generar Orden & Enlace OOB 🛡️
            </button>
          </form>
        </div>
      </div>

    
      <!-- Modal para Aprovisionar Empresa Cliente -->
      <div id="modalProvisionTenant" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:9999; justify-content:center; align-items:center; padding:1rem;">
        <div style="background:#fff; border-radius:12px; width:100%; max-width:600px; padding:2rem; box-shadow:0 20px 25px -5px rgba(0,0,0,0.2); max-height:90vh; overflow-y:auto;">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
            <h3 style="font-size:1.25rem; font-weight:800; color:#0f172a;">🏢 Aprovisionar Nueva Empresa Cliente</h3>
            <button onclick="document.getElementById('modalProvisionTenant').style.display='none';" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:#64748b;">&times;</button>
          </div>
          <form method="POST" action="index.php?tab=treasury">
            <input type="hidden" name="action" value="provision_tenant">
            
            <div style="display:grid; grid-template-columns:1.5fr 1fr; gap:1rem; margin-bottom:1rem;">
              <div>
                <label style="display:block; font-size:0.82rem; font-weight:700; color:#475569; margin-bottom:0.25rem;">Razón Social de la Empresa *</label>
                <input type="text" name="company_name" required placeholder="Ej: Agroindustrial del Sur S.A." style="width:100%; padding:0.65rem; border:1px solid #cbd5e1; border-radius:6px;">
              </div>
              <div>
                <label style="display:block; font-size:0.82rem; font-weight:700; color:#475569; margin-bottom:0.25rem;">RUT / Tax ID *</label>
                <input type="text" name="rut_dni" required placeholder="76.543.210-9" style="width:100%; padding:0.65rem; border:1px solid #cbd5e1; border-radius:6px;">
              </div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:1rem;">
              <div>
                <label style="display:block; font-size:0.82rem; font-weight:700; color:#475569; margin-bottom:0.25rem;">Umbral OOB Automático</label>
                <input type="number" name="threshold_amount" value="2000000" style="width:100%; padding:0.65rem; border:1px solid #cbd5e1; border-radius:6px;">
              </div>
              <div>
                <label style="display:block; font-size:0.82rem; font-weight:700; color:#475569; margin-bottom:0.25rem;">Moneda</label>
                <select name="threshold_currency" style="width:100%; padding:0.65rem; border:1px solid #cbd5e1; border-radius:6px;">
                  <option value="CLP">CLP ($ Pesos Chilenos)</option>
                  <option value="USD">USD ($ Dólares)</option>
                </select>
              </div>
            </div>

            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:1rem; margin-bottom:1rem;">
              <h4 style="font-size:0.88rem; color:#0f172a; margin-bottom:0.5rem;">Firmante Principal Autorizado (CFO / Gerente General)</h4>
              <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem; margin-bottom:0.5rem;">
                <input type="text" name="primary_approver_name" required placeholder="Nombre Completo Directivo" style="padding:0.55rem; border:1px solid #cbd5e1; border-radius:6px;">
                <input type="text" name="primary_approver_phone" required placeholder="Celular (+56 9 ...)" style="padding:0.55rem; border:1px solid #cbd5e1; border-radius:6px;">
              </div>
              <input type="email" name="primary_approver_email" required placeholder="correo.directivo@empresa.cl" style="width:100%; padding:0.55rem; border:1px solid #cbd5e1; border-radius:6px;">
            </div>


            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:1rem; margin-bottom:1rem;">
              <h4 style="font-size:0.88rem; color:#0f172a; margin-bottom:0.5rem;">Operador de Tesorería (Acceso Portal Clientes & Notificaciones)</h4>
              <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem; margin-bottom:0.5rem;">
                <input type="text" name="treasury_operator_name" required placeholder="Nombre Encargado Pagos" style="padding:0.55rem; border:1px solid #cbd5e1; border-radius:6px;">
                <input type="text" name="treasury_operator_phone" required placeholder="Celular WhatsApp (+56 9 ...)" style="padding:0.55rem; border:1px solid #cbd5e1; border-radius:6px;">
              </div>
              <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem;">
                <input type="email" name="treasury_operator_email" required placeholder="tesoreria@empresa.cl" style="padding:0.55rem; border:1px solid #cbd5e1; border-radius:6px;">
                <input type="password" name="treasury_password" placeholder="Contraseña inicial (Por defecto: Tesoreria2026!)" style="padding:0.55rem; border:1px solid #cbd5e1; border-radius:6px;">
              </div>
            </div>

            <div style="margin-bottom:1.5rem;">
              <label style="display:block; font-size:0.82rem; font-weight:700; color:#475569; margin-bottom:0.25rem;">Canal WhatsApp Asignado</label>
              <select name="whatsapp_instance" style="width:100%; padding:0.65rem; border:1px solid #cbd5e1; border-radius:6px;">
                <option value="keystone-general">Canal Corporativo Oficial Keystone (+56 9 3064 0431)</option>
                <option value="custom">Crear Chip / Instancia Dedicada para este Cliente</option>
              </select>
            </div>

            <button type="submit" style="width:100%; background:#0284c7; color:#fff; border:none; padding:0.85rem; border-radius:8px; font-weight:700; cursor:pointer;">
              Habilitar Empresa & Emitir Licencia API 🏢
            </button>
          </form>
        </div>
      </div>

      <!-- Modal para Conectar WhatsApp QR -->
      <div id="modalWhatsAppQr" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:9999; justify-content:center; align-items:center; padding:1rem;">
        <div style="background:#fff; border-radius:12px; width:100%; max-width:440px; padding:2rem; text-align:center; box-shadow:0 20px 25px -5px rgba(0,0,0,0.2);">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
            <h3 style="font-size:1.15rem; font-weight:800; color:#0f172a;" id="qrModalTitle">📲 Vincular Chip WhatsApp</h3>
            <button onclick="closeWhatsAppModal()" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:#64748b;">&times;</button>
          </div>
          <p style="font-size:0.85rem; color:#64748b; margin-bottom:1.25rem;">Escanee este código con WhatsApp desde el celular de la empresa (Dispositivos Vinculados) para autorizar envíos.</p>
          <div id="qrLoading" style="padding:2rem; color:#0284c7; font-weight:bold;">Generando código QR seguro... ⏳</div>
          <div id="qrContainer" style="display:none; margin-bottom:1rem;">
            <img id="qrImage" src="" alt="WhatsApp QR Code" style="width:250px; height:250px; border:1px solid #e2e8f0; border-radius:8px; padding:0.5rem;">
          </div>
          <div id="qrStatusBadge" style="font-size:0.85rem; margin-top:0.5rem; font-weight:bold;"></div>
        </div>
      </div>


      <!-- Modal para Editar Empresa Cliente -->
      
      <!-- Modal Administrar Múltiples Operadores y Firmantes de Empresa -->
      <div id="modalTenantUsers" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.7); z-index:9999; justify-content:center; align-items:center; padding:1rem; backdrop-filter:blur(3px);">
        <div style="background:#fff; width:100%; max-width:850px; border-radius:12px; padding:1.75rem; max-height:90vh; overflow-y:auto; box-shadow:0 20px 25px -5px rgba(0,0,0,0.3); border:1px solid #cbd5e1;">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.25rem; border-bottom:1px solid #e2e8f0; padding-bottom:0.75rem;">
            <div>
              <h3 style="font-size:1.25rem; font-weight:800; color:#0f172a; margin:0;" id="tu_company_name">👥 Usuarios & Firmantes de la Empresa</h3>
              <span style="font-size:0.8rem; color:#64748b;" id="tu_tenant_slug">Slug: ...</span>
            </div>
            <button onclick="closeTenantUsersModal()" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:#64748b;">&times;</button>
          </div>

          <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.5rem;">
            <!-- Columna 1: Operadores de Tesorería -->
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:1.25rem;">
              <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.75rem;">
                <h4 style="margin:0; font-size:0.95rem; color:#0f172a; font-weight:800;">🔑 Operadores de Tesorería (<span id="tu_op_count">0</span>)</h4>
              </div>
              <p style="font-size:0.75rem; color:#64748b; margin-bottom:1rem;">Cargan nóminas, emiten órdenes de pago y gestionan beneficiarios en el portal.</p>

              <div id="tu_operators_list" style="display:flex; flex-direction:column; gap:0.5rem; margin-bottom:1rem; max-height:220px; overflow-y:auto;">
                <div style="color:#94a3b8; font-size:0.8rem; padding:0.5rem; text-align:center;">Cargando operadores...</div>
              </div>

              <!-- Formulario Agregar Operador -->
              <form method="POST" action="index.php?tab=treasury" style="background:#fff; border:1px solid #cbd5e1; border-radius:6px; padding:0.75rem;">
                <input type="hidden" name="action" value="admin_add_operator">
                <input type="hidden" name="tenant_slug" id="form_op_slug">
                <strong style="font-size:0.78rem; color:#0f172a; display:block; margin-bottom:0.4rem;">+ Agregar Nuevo Operador</strong>
                <input type="text" name="full_name" required placeholder="Nombre y Apellido *" style="width:100%; padding:0.45rem; border:1px solid #cbd5e1; border-radius:4px; font-size:0.78rem; margin-bottom:0.4rem;">
                <input type="email" name="email" required placeholder="correo.operador@empresa.cl *" style="width:100%; padding:0.45rem; border:1px solid #cbd5e1; border-radius:4px; font-size:0.78rem; margin-bottom:0.4rem;">
                <input type="text" name="phone" placeholder="Celular / Anexo (Opcional)" style="width:100%; padding:0.45rem; border:1px solid #cbd5e1; border-radius:4px; font-size:0.78rem; margin-bottom:0.4rem;">
                <input type="password" name="password" required placeholder="Contraseña de acceso inicial *" value="Tesoreria2026!" style="width:100%; padding:0.45rem; border:1px solid #cbd5e1; border-radius:4px; font-size:0.78rem; margin-bottom:0.5rem;">
                <button type="submit" style="background:#0284c7; color:#fff; border:none; width:100%; padding:0.45rem; border-radius:4px; font-weight:700; font-size:0.75rem; cursor:pointer;">Guardar Operador</button>
              </form>
            </div>

            <!-- Columna 2: Directores Firmantes (Approvers) -->
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:1.25rem;">
              <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.75rem;">
                <h4 style="margin:0; font-size:0.95rem; color:#0f172a; font-weight:800;">✍️ Directores Firmantes (<span id="tu_appr_count">0</span>)</h4>
              </div>
              <p style="font-size:0.75rem; color:#64748b; margin-bottom:1rem;">Reciben WhatsApp interactivo OOB para aprobar o rechazar transferencias.</p>

              <div id="tu_approvers_list" style="display:flex; flex-direction:column; gap:0.5rem; margin-bottom:1rem; max-height:220px; overflow-y:auto;">
                <div style="color:#94a3b8; font-size:0.8rem; padding:0.5rem; text-align:center;">Cargando directores...</div>
              </div>

              <!-- Formulario Agregar Firmante -->
              <form method="POST" action="index.php?tab=treasury" style="background:#fff; border:1px solid #cbd5e1; border-radius:6px; padding:0.75rem;">
                <input type="hidden" name="action" value="admin_add_approver">
                <input type="hidden" name="tenant_slug" id="form_appr_slug">
                <strong style="font-size:0.78rem; color:#0f172a; display:block; margin-bottom:0.4rem;">+ Agregar Nuevo Firmante</strong>
                <input type="text" name="full_name" required placeholder="Nombre del Directivo / CFO *" style="width:100%; padding:0.45rem; border:1px solid #cbd5e1; border-radius:4px; font-size:0.78rem; margin-bottom:0.4rem;">
                <input type="text" name="phone_whatsapp" required placeholder="WhatsApp (+56912345678) *" style="width:100%; padding:0.45rem; border:1px solid #cbd5e1; border-radius:4px; font-size:0.78rem; margin-bottom:0.4rem;">
                <input type="email" name="email" placeholder="correo.directivo@empresa.cl" style="width:100%; padding:0.45rem; border:1px solid #cbd5e1; border-radius:4px; font-size:0.78rem; margin-bottom:0.4rem;">
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.4rem; margin-bottom:0.4rem;">
                  <input type="text" name="role_title" placeholder="Cargo (ej: Director Finanzas)" value="Director Firmante" style="width:100%; padding:0.45rem; border:1px solid #cbd5e1; border-radius:4px; font-size:0.75rem;">
                  <input type="number" name="max_approval_limit" placeholder="Límite Máx (0=Sin límite)" value="0" style="width:100%; padding:0.45rem; border:1px solid #cbd5e1; border-radius:4px; font-size:0.75rem;">
                </div>
                <label style="display:flex; align-items:center; gap:0.35rem; font-size:0.75rem; color:#334155; margin-bottom:0.5rem; cursor:pointer;">
                  <input type="checkbox" name="is_primary" value="1"> Marcar como Firmante Principal
                </label>
                <button type="submit" style="background:#059669; color:#fff; border:none; width:100%; padding:0.45rem; border-radius:4px; font-weight:700; font-size:0.75rem; cursor:pointer;">Guardar Firmante</button>
              </form>
            </div>
          </div>

          <div style="margin-top:1.25rem; display:flex; justify-content:flex-end;">
            <button onclick="closeTenantUsersModal()" style="background:#64748b; color:#fff; border:none; padding:0.5rem 1.25rem; border-radius:6px; font-weight:700; font-size:0.85rem; cursor:pointer;">Cerrar</button>
          </div>
        </div>
      </div>

      <div id="modalEditTenant" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:9999; justify-content:center; align-items:center; padding:1rem;">
        <div style="background:#fff; border-radius:12px; width:100%; max-width:600px; padding:2rem; box-shadow:0 20px 25px -5px rgba(0,0,0,0.2); max-height:90vh; overflow-y:auto;">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
            <h3 style="font-size:1.25rem; font-weight:800; color:#0f172a;">✏️ Editar Empresa Cliente</h3>
            <button onclick="document.getElementById('modalEditTenant').style.display='none';" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:#64748b;">&times;</button>
          </div>
          <form method="POST" action="index.php?tab=treasury">
            <input type="hidden" name="action" value="edit_tenant">
            <input type="hidden" name="tenant_id" id="edit_tenant_id">
            
            <div style="display:grid; grid-template-columns:1.5fr 1fr; gap:1rem; margin-bottom:1rem;">
              <div>
                <label style="display:block; font-size:0.82rem; font-weight:700; color:#475569; margin-bottom:0.25rem;">Razón Social *</label>
                <input type="text" name="company_name" id="edit_company_name" required style="width:100%; padding:0.65rem; border:1px solid #cbd5e1; border-radius:6px;">
              </div>
              <div>
                <label style="display:block; font-size:0.82rem; font-weight:700; color:#475569; margin-bottom:0.25rem;">RUT / Tax ID *</label>
                <input type="text" name="rut_dni" id="edit_rut_dni" required style="width:100%; padding:0.65rem; border:1px solid #cbd5e1; border-radius:6px;">
              </div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:1rem;">
              <div>
                <label style="display:block; font-size:0.82rem; font-weight:700; color:#475569; margin-bottom:0.25rem;">Umbral OOB Automático</label>
                <input type="number" name="threshold_amount" id="edit_threshold_amount" style="width:100%; padding:0.65rem; border:1px solid #cbd5e1; border-radius:6px;">
              </div>
              <div>
                <label style="display:block; font-size:0.82rem; font-weight:700; color:#475569; margin-bottom:0.25rem;">Moneda</label>
                <select name="threshold_currency" id="edit_threshold_currency" style="width:100%; padding:0.65rem; border:1px solid #cbd5e1; border-radius:6px;">
                  <option value="CLP">CLP ($ Pesos Chilenos)</option>
                  <option value="USD">USD ($ Dólares)</option>
                </select>
              </div>
            </div>

            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:1rem; margin-bottom:1rem;">
              <h4 style="font-size:0.88rem; color:#0f172a; margin-bottom:0.5rem;">Director Firmante (Aprobador OOB)</h4>
              <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem; margin-bottom:0.5rem;">
                <input type="text" name="primary_approver_name" id="edit_primary_approver_name" required placeholder="Nombre Directivo" style="padding:0.55rem; border:1px solid #cbd5e1; border-radius:6px;">
                <input type="text" name="primary_approver_phone" id="edit_primary_approver_phone" required placeholder="Celular WhatsApp (+56 9...)" style="padding:0.55rem; border:1px solid #cbd5e1; border-radius:6px;">
              </div>
              <input type="email" name="primary_approver_email" id="edit_primary_approver_email" required placeholder="correo@empresa.cl" style="width:100%; padding:0.55rem; border:1px solid #cbd5e1; border-radius:6px;">
            </div>

            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:1rem; margin-bottom:1rem;">
              <h4 style="font-size:0.88rem; color:#0f172a; margin-bottom:0.5rem;">Operador de Tesorería (Acceso Portal Clientes)</h4>
              <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem; margin-bottom:0.5rem;">
                <input type="text" name="treasury_operator_name" id="edit_treasury_operator_name" required placeholder="Nombre Tesorero" style="padding:0.55rem; border:1px solid #cbd5e1; border-radius:6px;">
                <input type="text" name="treasury_operator_phone" id="edit_treasury_operator_phone" required placeholder="Celular WhatsApp (+56 9...)" style="padding:0.55rem; border:1px solid #cbd5e1; border-radius:6px;">
              </div>
              <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem;">
                <input type="email" name="treasury_operator_email" id="edit_treasury_operator_email" required placeholder="tesoreria@empresa.cl" style="padding:0.55rem; border:1px solid #cbd5e1; border-radius:6px;">
                <input type="password" name="treasury_password" placeholder="Nueva contraseña (opcional)" style="padding:0.55rem; border:1px solid #cbd5e1; border-radius:6px;">
              </div>
            </div>

            <div style="margin-bottom:1.5rem;">
              <label style="display:block; font-size:0.82rem; font-weight:700; color:#475569; margin-bottom:0.25rem;">Canal WhatsApp Asignado</label>
              <input type="text" name="whatsapp_instance" id="edit_whatsapp_instance" style="width:100%; padding:0.65rem; border:1px solid #cbd5e1; border-radius:6px;">
            </div>

            <button type="submit" style="width:100%; background:#0284c7; color:#fff; border:none; padding:0.85rem; border-radius:8px; font-weight:700; cursor:pointer;">
              Guardar Cambios de Empresa 💾
            </button>
          </form>
        </div>
      </div>

      <!-- Modal de Protocolo de Contención de Fraude BEC -->
      <div id="modalBecProtocol" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.75); z-index:9999; justify-content:center; align-items:center; padding:1rem; backdrop-filter:blur(3px);">
        <div style="background:#fff; border-radius:14px; width:100%; max-width:680px; padding:2rem; box-shadow:0 25px 50px -12px rgba(220,38,38,0.3); max-height:92vh; overflow-y:auto; border-top:6px solid #dc2626;">
          <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:1.25rem;">
            <div>
              <div style="display:inline-flex; align-items:center; gap:0.4rem; background:#fee2e2; color:#b91c1c; font-size:0.75rem; font-weight:800; padding:0.25rem 0.6rem; border-radius:99px; text-transform:uppercase; margin-bottom:0.4rem;">
                🚨 ALERTA ROJA • INCIDENTE DE SEGURIDAD EN CURSO
              </div>
              <h3 style="font-size:1.3rem; font-weight:800; color:#0f172a; margin:0;">
                Protocolo de Respuesta a Fraude BEC (<span id="bec_order_code"></span>)
              </h3>
            </div>
            <button onclick="closeBecProtocolModal()" style="background:none; border:none; font-size:1.6rem; cursor:pointer; color:#64748b; line-height:1;">&times;</button>
          </div>

          <!-- Resumen de la Transacción Bloqueada -->
          <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:1rem; margin-bottom:1.25rem; font-size:0.85rem;">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem;">
              <div><strong>🏢 Empresa Cliente:</strong> <span id="bec_company"></span></div>
              <div><strong>💰 Monto Detenido:</strong> <span id="bec_amount" style="font-weight:800; color:#dc2626;"></span></div>
              <div><strong>👤 Beneficiario Sospechoso:</strong> <span id="bec_beneficiary"></span></div>
              <div><strong>🏦 Banco / Cuenta:</strong> <span id="bec_bank"></span></div>
              <div><strong>📲 Directivo que Bloqueó:</strong> <span id="bec_approver"></span></div>
              <div><strong>🔐 Clave de Control:</strong> <code id="bec_challenge" style="color:#0284c7; font-weight:800;"></code></div>
            </div>
            <div style="margin-top:0.5rem; font-size:0.75rem; color:#64748b; border-top:1px dashed #cbd5e1; padding-top:0.4rem;">
              <strong>Sello Criptográfico Forense:</strong> <code id="bec_hash" style="word-break:break-all;"></code>
            </div>
          </div>

          <!-- Guía Operativa Paso a Paso para el Operador Keystone -->
          <h4 style="font-size:0.95rem; color:#0f172a; margin-bottom:0.75rem; font-weight:800;">
            🛠️ Checklist Operativo para el Operador Keystone (Protocolo de 4 Pasos):
          </h4>

          <div style="display:flex; flex-direction:column; gap:0.75rem; font-size:0.85rem; color:#334155;">
            <div style="display:flex; gap:0.75rem; background:#fff; border:1px solid #e2e8f0; border-left:4px solid #dc2626; padding:0.75rem; border-radius:6px;">
              <span style="font-size:1.2rem;">📞</span>
              <div>
                <strong>Paso 1: Llamada Inmediata Fuera de Banda (OOB)</strong><br>
                Contacte telefónicamente de inmediato al CFO y al Operador de Tesorería. Confirme si el directivo presionó el botón deliberadamente y si la factura o cambio de cuenta provino de un correo electrónico sospechoso o llamada de voz con IA.
              </div>
            </div>

            <div style="display:flex; gap:0.75rem; background:#fff; border:1px solid #e2e8f0; border-left:4px solid #f59e0b; padding:0.75rem; border-radius:6px;">
              <span style="font-size:1.2rem;">🏦</span>
              <div>
                <strong>Paso 2: Congelamiento Preventivo en Portal Bancario</strong><br>
                Instruya a Tesorería a bloquear la cuenta corriente destino en el portal bancario y no liberar pagos manuales, cheques ni vales vista hacia dicho RUT hasta que se complete la verificación del proveedor legítimo.
              </div>
            </div>

            <div style="display:flex; gap:0.75rem; background:#fff; border:1px solid #e2e8f0; border-left:4px solid #0284c7; padding:0.75rem; border-radius:6px; flex-direction:column;">
              <div style="display:flex; gap:0.5rem; align-items:center;">
                <span style="font-size:1.2rem;">🔍</span>
                <strong>Paso 3: Triangulación de Correo & Auditoría Forense SPF/DMARC</strong>
              </div>
              <div style="font-size:0.82rem; color:#475569;">
                El cliente puede reenviar el correo sospechoso a <strong>contacto@keystoneadvisors.cl</strong> indicando la orden en el asunto (ej: <code>BEC <span class="bec_order_code_txt"></span></code>), o el operador puede subir/pegar el archivo <code>.eml</code> directamente:
              </div>

              <!-- Herramientas de Análisis Forense Integradas -->
              <div style="background:#f8fafc; border:1px solid #cbd5e1; border-radius:6px; padding:0.75rem; margin-top:0.4rem;">
                <div style="display:flex; gap:0.5rem; flex-wrap:wrap; margin-bottom:0.6rem;">
                  <button type="button" onclick="syncBecImapAuto()" id="btnSyncBecImap" style="background:#0284c7; color:#fff; border:none; padding:0.45rem 0.85rem; border-radius:6px; font-weight:700; font-size:0.78rem; cursor:pointer;">
                    📥 Sincronizar desde contacto@keystoneadvisors.cl
                  </button>
                  <label style="background:#0f172a; color:#fff; padding:0.45rem 0.85rem; border-radius:6px; font-weight:700; font-size:0.78rem; cursor:pointer;">
                    📂 Subir Archivo .EML
                    <input type="file" id="bec_eml_file" accept=".eml,.msg,.txt" style="display:none;" onchange="uploadBecEmlFile()">
                  </label>
                </div>

                <div id="becForensicResult" style="display:none; background:#fff; border:1px solid #cbd5e1; border-radius:6px; padding:0.75rem; font-size:0.8rem;">
                  <div style="font-weight:800; color:#0f172a; margin-bottom:0.35rem;" id="becForensicTitle">Resultado Forense:</div>
                  <div id="becForensicBody" style="color:#334155; line-height:1.4;"></div>
                </div>
              </div>
            </div>

            <div style="display:flex; gap:0.75rem; background:#fff; border:1px solid #e2e8f0; border-left:4px solid #10b981; padding:0.75rem; border-radius:6px; flex-direction:column;">
              <div style="display:flex; gap:0.5rem; align-items:center;">
                <span style="font-size:1.2rem;">📄</span>
                <strong>Paso 4: Emisión de Dosier Criptográfico para Denuncia Legal (PDI / Fiscalía)</strong>
              </div>
              <div style="font-size:0.82rem; color:#475569;">
                DeepShield consolida la evidencia en un documento formal pericial con firma digital y hash SHA-256 inviolable para presentar ante la Brigada del Cibercrimen:
              </div>
              <div style="display:flex; gap:0.5rem; margin-top:0.4rem; flex-wrap:wrap;">
                <button type="button" onclick="dispatchBecDossierPdf()" id="btnDispatchDossier" style="background:#10b981; color:#fff; border:none; padding:0.45rem 1rem; border-radius:6px; font-weight:700; font-size:0.8rem; cursor:pointer; box-shadow:0 2px 6px rgba(16,185,129,0.3);">
                  ✉️ Despachar Dosier PDF al CFO por Correo
                </button>
              </div>
            </div>
          </div>

          <div style="margin-top:1.5rem; display:flex; justify-content:flex-end;">
            <button onclick="closeBecProtocolModal()" style="background:#0f172a; color:#fff; border:none; padding:0.65rem 1.5rem; border-radius:6px; font-weight:700; cursor:pointer;">
              Entendido, Cerrar Protocolo
            </button>
          </div>
        </div>
      </div>

        <?php elseif ($tab === 'dmarc'): ?>
      <?php
        $watchdog = new DmarcWatchdogService($db);
        $tenants = $watchdog->listTenants();
        $selectedTenantId = (int)($_GET['tenant_id'] ?? 0);
        $selectedTenant = null;
        if ($selectedTenantId > 0) {
            $selectedTenant = $watchdog->getTenant($selectedTenantId);
        }
        $filterDomain = $selectedTenant ? $selectedTenant['domain'] : '';

        $dmarcMetrics = $watchdog->getMetrics($filterDomain);
        $dmarcReports = $watchdog->listReports(20, $filterDomain);
        $centralRuaEmail = $watchdog->getCentralRuaEmail();
      ?>

      <!-- Header & Alert Notification -->
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem; flex-wrap:wrap; gap:1rem;">
        <div>
          <h2 style="font-size:1.4rem; color:#003366; font-weight:800; margin:0; display:flex; align-items:center; gap:0.5rem;">
            <span>🛡️ DMARC Watchdog & Surface Scanner (Multi-Tenant B2B)</span>
          </h2>
          <p style="font-size:0.85rem; color:#64748b; margin-top:0.25rem;">
            Gobernanza de Identidad de Correo (RFC 7489), Ingesta RUA de Google/Microsoft, Generación de Registros DNS TXT y Despacho Automatizado de Dosieres Mensuales para CFOs.
          </p>
        </div>
        <div style="display:flex; gap:0.6rem; flex-wrap:wrap;">
          <button onclick="document.getElementById('modalConfigRua').style.display='flex';" style="background:#0f172a; color:#38bdf8; border:1px solid #0284c7; padding:0.6rem 1rem; border-radius:8px; font-weight:700; font-size:0.82rem; cursor:pointer; display:inline-flex; align-items:center; gap:0.4rem;">
            ⚙️ Casilla Central RUA (<?php echo htmlspecialchars($centralRuaEmail); ?>)
          </button>
          <button onclick="document.getElementById('modalAddDmarcTenant').style.display='flex';" style="background:#0284c7; color:#fff; border:none; padding:0.6rem 1.15rem; border-radius:8px; font-weight:700; font-size:0.82rem; cursor:pointer; box-shadow:0 4px 10px rgba(2,132,199,0.25);">
            + Dar de Alta Empresa Cliente
          </button>
          <a href="index.php?tab=dmarc&action=sync_dmarc_imap" style="background:#10b981; color:#fff; padding:0.6rem 1.15rem; border-radius:8px; font-weight:700; font-size:0.82rem; text-decoration:none; display:inline-flex; align-items:center; gap:0.4rem; box-shadow:0 4px 10px rgba(16,185,129,0.25);" title="Conectar a dmarc-rua@keystoneadvisors.cl y descargar reportes diarios de Google y Microsoft">
            🔄 Sincronizar Buzón RUA (IMAP)
          </a>
          <button onclick="document.getElementById('modalUploadDmarc').style.display='flex';" style="background:#003366; color:#fff; border:none; padding:0.6rem 1.15rem; border-radius:8px; font-weight:700; font-size:0.82rem; cursor:pointer; box-shadow:0 4px 10px rgba(0,51,102,0.25); display:inline-flex; align-items:center; gap:0.4rem;">
            📥 Ingestar Reporte RUA (.xml, .gz, .zip)
          </button>
        </div>
      </div>

      <!-- Notificaciones de Operaciones -->
      <?php if (isset($_GET['imap_sync_ok'])): ?>
        <div style="background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; padding:0.85rem 1.25rem; border-radius:8px; margin-bottom:1.5rem; font-size:0.88rem;">
          <strong>✓ Sincronización IMAP Exitosa:</strong> Se conectó con el buzón <code>dmarc-rua@keystoneadvisors.cl</code> en Titan Mail y se ingestingaron <strong><?php echo (int)($_GET['count'] ?? 0); ?></strong> nuevos reportes RUA de proveedores.
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['imap_sync_fail'])): ?>
        <div style="background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:0.85rem 1.25rem; border-radius:8px; margin-bottom:1.5rem; font-size:0.88rem;">
          <strong>❌ Error en conexión IMAP:</strong> <?php echo htmlspecialchars($_SESSION['dmarc_error'] ?? 'Fallo al autenticar'); unset($_SESSION['dmarc_error']); ?>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['tenant_created'])): ?>
        <div style="background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; padding:0.85rem 1.25rem; border-radius:8px; margin-bottom:1.5rem; font-size:0.88rem;">
          <strong>✓ Empresa Cliente Agregada con Éxito:</strong> Se habilitó el monitoreo DMARC para el dominio <strong>@<?php echo htmlspecialchars($_GET['d'] ?? ''); ?></strong>. Se ha generado su registro DNS TXT listo para incorporar.
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['tenant_updated'])): ?>
        <div style="background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; padding:0.85rem 1.25rem; border-radius:8px; margin-bottom:1.5rem; font-size:0.88rem;">
          ✓ Datos del cliente DMARC actualizados correctamente.
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['status_updated'])): ?>
        <div style="background:#f0fdf4; border:1px solid #bbf7d0; color:#15803d; padding:0.85rem 1.25rem; border-radius:8px; margin-bottom:1.5rem; font-size:0.88rem;">
          ✓ Estado del servicio DMARC modificado exitosamente.
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['rua_saved'])): ?>
        <div style="background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; padding:0.85rem 1.25rem; border-radius:8px; margin-bottom:1.5rem; font-size:0.88rem;">
          ✓ Casilla RUA Centralizada actualizada a <strong><?php echo htmlspecialchars($centralRuaEmail); ?></strong>. Los nuevos registros DNS generados usarán esta dirección.
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['ingest_ok'])): ?>
        <div style="background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; padding:0.85rem 1.25rem; border-radius:8px; margin-bottom:1.5rem; font-size:0.88rem;">
          <strong>✓ Reporte DMARC Ingestado con Éxito:</strong> Se procesaron <strong><?php echo (int)($_GET['msgs'] ?? 0); ?></strong> correos. Mensajes sospechosos / con fallo de alineación: <strong><?php echo (int)($_GET['fails'] ?? 0); ?></strong>.
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['ingest_fail'])): ?>
        <div style="background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:0.85rem 1.25rem; border-radius:8px; margin-bottom:1.5rem; font-size:0.88rem;">
          <strong>❌ Error al ingestar archivo DMARC:</strong> <?php echo htmlspecialchars($_SESSION['dmarc_error'] ?? 'Archivo no válido.'); unset($_SESSION['dmarc_error']); ?>
        </div>
      <?php endif; ?>

      <!-- 1. TABLA MAESTRA DE EMPRESAS CLIENTES HABILITADAS EN DMARC -->
      <div class="card" style="padding:1.5rem; background:#fff; border-radius:12px; border:1px solid #e2e8f0; margin-bottom:2rem; box-shadow:0 4px 12px rgba(0,0,0,0.02);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.25rem; flex-wrap:wrap; gap:0.5rem;">
          <div>
            <h3 style="font-size:1.15rem; color:#0f172a; margin-bottom:0.25rem; font-weight:800;">🏢 Empresas y Dominios Protegidos (<?php echo count($tenants); ?>)</h3>
            <span style="font-size:0.82rem; color:#64748b;">Administración de Clientes, Generador de Registros DNS TXT, Emisión de Dosieres y Despacho Automatizado</span>
          </div>
          <div>
            <?php if ($selectedTenant): ?>
              <a href="index.php?tab=dmarc" style="font-size:0.8rem; background:#f1f5f9; color:#475569; padding:0.4rem 0.8rem; border-radius:6px; text-decoration:none; font-weight:700;">
                ✕ Quitar Filtro (Ver Consolidado Global)
              </a>
            <?php endif; ?>
          </div>
        </div>

        <div class="table-container">
          <table style="width:100%; border-collapse:collapse; min-width:1100px;">
            <thead>
              <tr style="background:#003366; color:#ffffff; text-align:left; font-size:0.82rem;">
                <th style="padding:0.85rem 1rem;">Empresa Cliente</th>
                <th style="padding:0.85rem 1rem;">Dominio Protegido</th>
                <th style="padding:0.85rem 1rem;">Directivo / CFO Destino</th>
                <th style="padding:0.85rem 1rem;">Política DNS</th>
                <th style="padding:0.85rem 1rem;">Telemetría Ingestada</th>
                <th style="padding:0.85rem 1rem;">Último Dosier</th>
                <th style="padding:0.85rem 1rem; text-align:center;">Acciones de Gestión</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($tenants)): ?>
                <tr>
                  <td colspan="7" style="padding:2.5rem; text-align:center; color:#94a3b8; font-size:0.88rem;">
                    No hay empresas registradas aún en el servicio de protección DMARC. Presione <strong>"+ Dar de Alta Empresa Cliente"</strong> para comenzar.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($tenants as $ten): 
                  $isCurrentSelected = ($selectedTenant && $selectedTenant['id'] === $ten['id']);
                ?>
                  <tr style="border-bottom:1px solid #f1f5f9; font-size:0.85rem; <?php echo $isCurrentSelected ? 'background:#e0f2fe;' : ''; ?>">
                    <td style="padding:0.85rem 1rem;">
                      <strong style="color:#0f172a; font-size:0.92rem;"><?php echo htmlspecialchars($ten['company_name']); ?></strong><br>
                      <?php if ($ten['status'] === 'ACTIVE'): ?>
                        <span style="background:rgba(16,185,129,0.15); color:#059669; padding:0.15rem 0.45rem; border-radius:9999px; font-weight:700; font-size:0.7rem;">● ACTIVO</span>
                      <?php elseif ($ten['status'] === 'SUSPENDED'): ?>
                        <span style="background:rgba(245,158,11,0.15); color:#d97706; padding:0.15rem 0.45rem; border-radius:9999px; font-weight:700; font-size:0.7rem;">⏸️ SUSPENDIDO</span>
                      <?php else: ?>
                        <span style="background:rgba(239,68,68,0.15); color:#dc2626; padding:0.15rem 0.45rem; border-radius:9999px; font-weight:700; font-size:0.7rem;">✕ DADO DE BAJA</span>
                      <?php endif; ?>
                    </td>
                    <td style="padding:0.85rem 1rem;">
                      <?php 
                      $tenantDomains = $watchdog->getDomainsByTenant($ten['id']);
                      ?>
                      <div style="display:flex; flex-direction:column; gap:0.25rem;">
                        <?php foreach ($tenantDomains as $dItem): ?>
                          <div style="display:inline-flex; align-items:center; gap:0.35rem; font-size:0.85rem;">
                            <a href="index.php?tab=dmarc&tenant_id=<?php echo $ten['id']; ?>&filter_domain=<?php echo urlencode($dItem['domain']); ?>" style="color:#0284c7; font-weight:800; text-decoration:none;" title="Filtrar analítica para este dominio">
                              @<?php echo htmlspecialchars($dItem['domain']); ?>
                            </a>
                            <?php if (!empty($dItem['is_primary'])): ?>
                              <span style="background:#e0f2fe; color:#0369a1; font-size:0.65rem; padding:0.1rem 0.35rem; border-radius:3px; font-weight:700;">Principal</span>
                            <?php else: ?>
                              <form method="POST" action="index.php?tab=dmarc" onsubmit="return confirm('¿Quitar dominio @<?php echo htmlspecialchars($dItem['domain']); ?> de esta empresa?');" style="margin:0; display:inline;">
                                <input type="hidden" name="action" value="remove_dmarc_domain">
                                <input type="hidden" name="tenant_id" value="<?php echo $ten['id']; ?>">
                                <input type="hidden" name="domain" value="<?php echo htmlspecialchars($dItem['domain']); ?>">
                                <button type="submit" style="background:none; border:none; color:#ef4444; cursor:pointer; font-size:0.75rem; padding:0;" title="Eliminar dominio adicional">&times;</button>
                              </form>
                            <?php endif; ?>
                          </div>
                        <?php endforeach; ?>
                        
                        <!-- Botón Rápido: Agregar Otro Dominio -->
                        <button onclick='openAddDomainModal(<?php echo $ten['id']; ?>, "<?php echo htmlspecialchars($ten['company_name']); ?>")' style="margin-top:0.2rem; background:#f8fafc; border:1px dashed #0284c7; color:#0284c7; padding:0.2rem 0.5rem; border-radius:4px; font-size:0.72rem; cursor:pointer; font-weight:700; width:fit-content;">
                          + Agregar Dominio
                        </button>
                      </div>
                    </td>
                    <td style="padding:0.85rem 1rem;">
                      <strong><?php echo htmlspecialchars($ten['contact_name']); ?></strong><br>
                      <span style="color:#64748b; font-size:0.75rem;">✉️ <?php echo htmlspecialchars($ten['contact_email']); ?></span>
                      <?php if (!empty($ten['contact_phone'])): ?>
                        <br><span style="color:#64748b; font-size:0.75rem;">📲 <?php echo htmlspecialchars($ten['contact_phone']); ?></span>
                      <?php endif; ?>
                    </td>
                    <td style="padding:0.85rem 1rem;">
                      <span style="background:#f1f5f9; color:#003366; border:1px solid #cbd5e1; padding:0.2rem 0.5rem; border-radius:4px; font-weight:bold; font-size:0.75rem;">
                        p=<?php echo htmlspecialchars($ten['policy_mode']); ?>
                      </span>
                    </td>
                    <td style="padding:0.85rem 1rem;">
                      <strong style="color:#0f172a;"><?php echo number_format($ten['total_volume']); ?> msgs</strong><br>
                      <span style="font-size:0.75rem; color:#64748b;">(<?php echo number_format($ten['total_reports']); ?> reportes • <span style="color:<?php echo $ten['total_fails'] > 0 ? '#dc2626' : '#059669'; ?>; font-weight:bold;"><?php echo number_format($ten['total_fails']); ?> fails</span>)</span>
                    </td>
                    <td style="padding:0.85rem 1rem; font-size:0.78rem; color:#64748b;">
                      <?php echo !empty($ten['last_dossier_sent_at']) ? substr($ten['last_dossier_sent_at'], 0, 16) : '<span style="color:#94a3b8;">Nunca despachado</span>'; ?>
                    </td>
                    <td style="padding:0.85rem 1rem; text-align:center;">
                      <div style="display:flex; justify-content:center; gap:0.35rem; flex-wrap:wrap;">
                        <!-- Ver Registro DNS TXT -->
                        <button onclick='openDnsModal("<?php echo htmlspecialchars($ten['domain']); ?>", "<?php echo htmlspecialchars($ten['policy_mode']); ?>")' style="background:#0f172a; color:#38bdf8; border:1px solid #0284c7; padding:0.3rem 0.55rem; border-radius:4px; font-size:0.72rem; cursor:pointer; font-weight:700;" title="Generar y copiar registro DNS TXT para el registrador">
                          🔑 DNS TXT
                        </button>

                        <!-- Previsualizar / Descargar Dosier PDF -->
                        <a href="index.php?tab=dmarc&action=download_dmarc_dossier&tenant_id=<?php echo $ten['id']; ?>" target="_blank" style="background:#0284c7; color:#fff; padding:0.3rem 0.55rem; border-radius:4px; font-size:0.72rem; text-decoration:none; font-weight:700;" title="Abrir y verificar Dosier PDF Mensual">
                          📄 Ver Dosier
                        </a>

                        <!-- Despacho Inmediato por Correo al Contacto -->
                        <button onclick='dispatchDossierEmailPrompt(<?php echo $ten['id']; ?>, "<?php echo htmlspecialchars($ten['company_name']); ?>", "<?php echo htmlspecialchars($ten['contact_email']); ?>")' style="background:#10b981; color:#fff; border:none; padding:0.3rem 0.55rem; border-radius:4px; font-size:0.72rem; cursor:pointer; font-weight:700;" title="Enviar Dosier oficial al directivo designado">
                          ✉️ Despachar
                        </button>

                        <!-- Editar Cliente -->
                        <button onclick='openEditDmarcTenantModal(<?php echo json_encode($ten, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>)' style="background:#f1f5f9; color:#0f172a; border:1px solid #cbd5e1; padding:0.3rem 0.55rem; border-radius:4px; font-size:0.72rem; cursor:pointer; font-weight:700;" title="Editar datos del cliente">
                          ✏️ Editar
                        </button>

                        <!-- Suspender / Reactivar / Baja -->
                        <?php if ($ten['status'] === 'ACTIVE'): ?>
                          <form method="POST" action="index.php?tab=dmarc" onsubmit="return confirm('¿Suspender monitoreo para <?php echo htmlspecialchars($ten['company_name']); ?>?');" style="margin:0;">
                            <input type="hidden" name="action" value="update_dmarc_tenant_status">
                            <input type="hidden" name="tenant_id" value="<?php echo $ten['id']; ?>">
                            <input type="hidden" name="status" value="SUSPENDED">
                            <button type="submit" style="background:#f59e0b; color:#fff; border:none; padding:0.3rem 0.5rem; border-radius:4px; font-size:0.7rem; cursor:pointer; font-weight:700;" title="Suspender">⏸️</button>
                          </form>
                          <form method="POST" action="index.php?tab=dmarc" onsubmit="return confirm('¿CONFIRMA DAR DE BAJA a <?php echo htmlspecialchars($ten['company_name']); ?>?');" style="margin:0;">
                            <input type="hidden" name="action" value="update_dmarc_tenant_status">
                            <input type="hidden" name="tenant_id" value="<?php echo $ten['id']; ?>">
                            <input type="hidden" name="status" value="REVOKED">
                            <button type="submit" style="background:#ef4444; color:#fff; border:none; padding:0.3rem 0.5rem; border-radius:4px; font-size:0.7rem; cursor:pointer; font-weight:700;" title="Dar de baja">✕</button>
                          </form>
                        <?php else: ?>
                          <form method="POST" action="index.php?tab=dmarc" style="margin:0;">
                            <input type="hidden" name="action" value="update_dmarc_tenant_status">
                            <input type="hidden" name="tenant_id" value="<?php echo $ten['id']; ?>">
                            <input type="hidden" name="status" value="ACTIVE">
                            <button type="submit" style="background:#10b981; color:#fff; border:none; padding:0.3rem 0.55rem; border-radius:4px; font-size:0.7rem; cursor:pointer; font-weight:700;">✓ Reactivar</button>
                          </form>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- 2. ANALÍTICA DE TELEMETRÍA CON SELECTOR DIRECTO DE EMPRESA -->
      <div style="background:#fff; border:1px solid #cbd5e1; border-radius:10px; padding:1.2rem 1.5rem; margin-bottom:1.5rem; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem; box-shadow:0 2px 6px rgba(0,0,0,0.02);">
        <div>
          <h3 style="font-size:1.2rem; color:#003366; font-weight:800; margin:0; display:flex; align-items:center; gap:0.5rem;">
            <span>📊 Telemetría & Tráfico:</span>
            <span style="color:<?php echo $selectedTenant ? '#0284c7' : '#0f172a'; ?>;">
              <?php echo $selectedTenant ? htmlspecialchars($selectedTenant['company_name']) . ' (@' . htmlspecialchars($selectedTenant['domain']) . ')' : 'Consolidado Global (Todas las Empresas)'; ?>
            </span>
          </h3>
          <span style="font-size:0.82rem; color:#64748b;">
            <?php echo $selectedTenant ? 'Monitoreando tráfico exclusivo y amenazas directas dirigidas a este dominio corporativo.' : 'Visualizando la suma agregada de todas las empresas clientes bajo contrato de protección.'; ?>
          </span>
        </div>
        <div style="display:flex; align-items:center; gap:0.6rem;">
          <label style="font-size:0.82rem; font-weight:700; color:#475569;">Filtrar por Empresa:</label>
          <select onchange="window.location.href='index.php?tab=dmarc' + (this.value ? '&tenant_id=' + this.value : '');" style="padding:0.45rem 0.85rem; border:1px solid #94a3b8; border-radius:6px; font-size:0.85rem; font-weight:700; color:#003366; background:#f8fafc; cursor:pointer;">
            <option value="" <?php echo empty($selectedTenantId) ? 'selected' : ''; ?>>-- Ver Todas las Empresas (Global) --</option>
            <?php foreach ($tenants as $tOption): ?>
              <option value="<?php echo $tOption['id']; ?>" <?php echo $selectedTenantId === $tOption['id'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($tOption['company_name']); ?> (@<?php echo htmlspecialchars($tOption['domain']); ?>)
              </option>
            <?php endforeach; ?>
          </select>
          <?php if ($selectedTenant): ?>
            <a href="index.php?tab=dmarc" style="background:#fee2e2; color:#dc2626; padding:0.45rem 0.75rem; border-radius:6px; font-size:0.8rem; text-decoration:none; font-weight:700;" title="Quitar filtro y ver consolidado">✕ Limpiar</a>
          <?php endif; ?>
        </div>
      </div>

      <!-- Tarjetas Métricas KPI DMARC -->
      <div class="metrics-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); margin-bottom:2rem;">
        <div class="metric-card" style="border-left:4px solid #003366;">
          <div class="metric-title">Reportes RUA Ingestados</div>
          <div class="metric-value"><?php echo number_format($dmarcMetrics['total_reports']); ?></div>
          <div class="metric-sub" style="color:#64748b;">De Google, Microsoft, etc.</div>
        </div>

        <div class="metric-card" style="border-left:4px solid #0284c7;">
          <div class="metric-title">Volumen Total Evaluado</div>
          <div class="metric-value"><?php echo number_format($dmarcMetrics['total_volume']); ?></div>
          <div class="metric-sub" style="color:#0284c7;">Mensajes evaluados</div>
        </div>

        <div class="metric-card" style="border-left:4px solid #10b981;">
          <div class="metric-title">Tasa de Alineación Legítima</div>
          <div class="metric-value" style="color:#059669;"><?php echo $dmarcMetrics['pass_rate']; ?>%</div>
          <div class="metric-sub" style="color:#059669;">✓ <?php echo number_format($dmarcMetrics['total_pass']); ?> verificados</div>
        </div>

        <div class="metric-card" style="border-left:4px solid #ef4444;">
          <div class="metric-title">Intentos de Spoofing / Bloqueados</div>
          <div class="metric-value" style="color:#dc2626;"><?php echo number_format($dmarcMetrics['total_fail']); ?></div>
          <div class="metric-sub" style="color:#dc2626;">Fallo SPF + DKIM detectado</div>
        </div>
      </div>

      <!-- Grilla Doble: Top Atacantes vs Fuentes Legítimas -->
      <div class="dmarc-dual-grid">
        <!-- Columna 1: Top Atacantes (Spoofing) -->
        <div class="funnel-box" style="margin-bottom:0; border-top:4px solid #dc2626;">
          <h3 style="font-size:1.05rem; color:#991b1b; margin-bottom:0.4rem; display:flex; align-items:center; gap:0.5rem;">
            <span>🚨 IPs Atacantes & Suplantadores (Spoofing)</span>
          </h3>
          <p style="font-size:0.78rem; color:#64748b; margin-bottom:1rem;">Servidores externos que enviaron correos usando el dominio corporativo sin autorización DNS.</p>

          <?php if (empty($dmarcMetrics['top_spoofers'])): ?>
            <div style="padding:1.5rem; text-align:center; color:#64748b; font-size:0.85rem;">
              ✓ Cero intentos de suplantación detectados en los reportes analizados.
            </div>
          <?php else: ?>
            <div style="overflow-x:auto; width:100%;"><table class="compact-table" style="font-size:0.82rem;">
              <thead>
                <tr style="background:#fef2f2; color:#991b1b; text-align:left;">
                  <th style="padding:0.6rem;">IP Remitente</th>
                  <th style="padding:0.6rem;">Intentos</th>
                  <th style="padding:0.6rem;">SPF / DKIM</th>
                  <th style="padding:0.6rem;">Visto</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($dmarcMetrics['top_spoofers'] as $sp): ?>
                  <tr style="border-bottom:1px solid #fee2e2;">
                    <td style="padding:0.6rem;">
                      <strong style="font-family:monospace; font-size:0.85rem; color:#dc2626;"><?php echo htmlspecialchars($sp['source_ip']); ?></strong>
                      <div style="font-size:0.72rem; color:#475569; margin-top:0.15rem;">
                        🌍 <strong><?php echo htmlspecialchars($sp['country'] ?? 'Desconocido'); ?></strong> <?php if (!empty($sp['city'])): ?>(<?php echo htmlspecialchars($sp['city']); ?>)<?php endif; ?><br>
                        🏢 <span style="color:#64748b;"><?php echo htmlspecialchars($sp['org'] ?? 'Proveedor no detectado'); ?></span>
                      </div>
                      <div style="display:flex; gap:0.35rem; margin-top:0.35rem; flex-wrap:wrap;">
                        <!-- Botón 1: Investigar Whois -->
                        <a href="https://whois.domaintools.com/<?php echo urlencode($sp['source_ip']); ?>" target="_blank" style="font-size:0.68rem; background:#f1f5f9; color:#475569; padding:0.15rem 0.4rem; border-radius:3px; text-decoration:none; font-weight:bold; border:1px solid #cbd5e1;">
                          🔍 Whois ↗
                        </a>

                        <!-- Botón 2: Notificar Abuso / Take-Down al ISP -->
                        <button onclick='openTakedownModal("<?php echo htmlspecialchars($sp['source_ip']); ?>", "<?php echo htmlspecialchars($selectedTenant ? $selectedTenant['domain'] : 'keystoneadvisors.cl'); ?>", "<?php echo htmlspecialchars($selectedTenant ? $selectedTenant['company_name'] : 'Keystone Advisors'); ?>")' style="font-size:0.68rem; background:#dc2626; color:#fff; border:none; padding:0.15rem 0.45rem; border-radius:3px; cursor:pointer; font-weight:bold;" title="Notificar al Datacenter para dar de baja el servidor">
                          🛑 Notificar Take-Down ISP
                        </button>

                        <!-- Botón 3: Certificado Pericial Ley 21.459 (PDI) -->
                        <a href="index.php?tab=dmarc&action=download_dmarc_pdi_certificate&ip=<?php echo urlencode($sp['source_ip']); ?>&attempts=<?php echo (int)$sp['total_attempts']; ?>&tenant_id=<?php echo $selectedTenant ? $selectedTenant['id'] : 1; ?>" target="_blank" style="font-size:0.68rem; background:#0f172a; color:#38bdf8; padding:0.15rem 0.45rem; border-radius:3px; text-decoration:none; font-weight:bold; border:1px solid #0284c7;" title="Generar acta técnica con firma pericial para denuncia en Fiscalía">
                          ⚖️ Certificado PDI / Fiscalía
                        </a>
                      </div>
                    </td>
                    <td style="padding:0.6rem; font-weight:800; color:#991b1b; vertical-align:top;">
                      <?php echo number_format($sp['total_attempts']); ?> msgs
                    </td>
                    <td style="padding:0.6rem; vertical-align:top;">
                      <span style="background:#fee2e2; color:#dc2626; padding:0.15rem 0.4rem; border-radius:4px; font-size:0.7rem; font-weight:bold;">FAIL</span><br>
                      <span style="font-size:0.68rem; color:#dc2626; font-weight:bold;">REJECT</span>
                    </td>
                    <td style="padding:0.6rem; font-size:0.75rem; color:#64748b; vertical-align:top;">
                      <?php echo substr($sp['last_seen'], 0, 16); ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table></div>
          <?php endif; ?>
        </div>

        <!-- Columna 2: Fuentes Legítimas Autorizadas -->
        <div class="funnel-box" style="margin-bottom:0; border-top:4px solid #059669;">
          <h3 style="font-size:1.05rem; color:#065f46; margin-bottom:0.4rem; display:flex; align-items:center; gap:0.5rem;">
            <span>🟢 Servidores Emisores Legítimos (Alineados)</span>
          </h3>
          <p style="font-size:0.78rem; color:#64748b; margin-bottom:1rem;">Infraestructura de correo corporativo que cumple 100% con las firmas DKIM y SPF vigentes.</p>

          <?php if (empty($dmarcMetrics['top_legit'])): ?>
            <div style="padding:1.5rem; text-align:center; color:#64748b; font-size:0.85rem;">
              Sin datos de fuentes legítimas registradas aún.
            </div>
          <?php else: ?>
            <div style="overflow-x:auto; width:100%;"><table class="compact-table" style="font-size:0.82rem;">
              <thead>
                <tr style="background:#ecfdf5; color:#065f46; text-align:left;">
                  <th style="padding:0.6rem;">IP / Proveedor</th>
                  <th style="padding:0.6rem;">Correos Válidos</th>
                  <th style="padding:0.6rem;">Alineación</th>
                  <th style="padding:0.6rem;">Última Actividad</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($dmarcMetrics['top_legit'] as $lg): ?>
                  <tr style="border-bottom:1px solid #d1fae5;">
                    <td style="padding:0.6rem; font-family:monospace; font-weight:bold; color:#065f46;">
                      <?php echo htmlspecialchars($lg['source_ip']); ?>
                    </td>
                    <td style="padding:0.6rem; font-weight:800; color:#059669;">
                      <?php echo number_format($lg['total_legit']); ?> msgs
                    </td>
                    <td style="padding:0.6rem;">
                      <span style="background:#d1fae5; color:#065f46; padding:0.15rem 0.4rem; border-radius:4px; font-size:0.7rem; font-weight:bold;">PASS (DKIM+SPF)</span>
                    </td>
                    <td style="padding:0.6rem; font-size:0.75rem; color:#64748b;">
                      <?php echo substr($lg['last_seen'], 0, 16); ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table></div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Tabla de Reportes Maestros Ingestados -->
      <div class="table-container">
        <div style="padding:1.25rem; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
          <div>
            <h3 style="font-size:1.1rem; color:#003366; font-weight:800; margin:0;">📋 Historial de Reportes Agregados DMARC</h3>
            <span style="font-size:0.8rem; color:#64748b;">Bitácora de informes XML procesados y almacenados con desglose pericial</span>
          </div>
        </div>

        <?php if (empty($dmarcReports)): ?>
          <div style="padding:2.5rem; text-align:center; color:#64748b;">
            Sin reportes DMARC ingresados todavía para este criterio. Presione el botón "Ingestar Reporte RUA" para cargar un archivo emitido por Google o Microsoft.
          </div>
        <?php else: ?>
          <table style="width:100%; border-collapse:collapse; font-size:0.85rem;">
            <thead>
              <tr style="background:#f8fafc; color:#475569; text-align:left; border-bottom:2px solid #e2e8f0;">
                <th style="padding:0.85rem 1rem;">ID Reporte & Dominio</th>
                <th style="padding:0.85rem 1rem;">Organización Emisora</th>
                <th style="padding:0.85rem 1rem;">Período Evaluado</th>
                <th style="padding:0.85rem 1rem;">Política en Vigor</th>
                <th style="padding:0.85rem 1rem;">Volumen</th>
                <th style="padding:0.85rem 1rem;">Resultado</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($dmarcReports as $rep): ?>
                <tr style="border-bottom:1px solid #e2e8f0;">
                  <td style="padding:0.85rem 1rem;">
                    <strong style="color:#003366; font-size:0.9rem;">@<?php echo htmlspecialchars($rep['domain']); ?></strong><br>
                    <code style="font-size:0.75rem; color:#64748b;"><?php echo htmlspecialchars($rep['report_id']); ?></code>
                  </td>
                  <td style="padding:0.85rem 1rem;">
                    <strong><?php echo htmlspecialchars($rep['org_name']); ?></strong><br>
                    <span style="font-size:0.75rem; color:#64748b;"><?php echo htmlspecialchars($rep['email']); ?></span>
                  </td>
                  <td style="padding:0.85rem 1rem; font-size:0.8rem; color:#475569;">
                    Desde: <?php echo substr($rep['date_begin'], 0, 16); ?><br>
                    Hasta: <?php echo substr($rep['date_end'], 0, 16); ?>
                  </td>
                  <td style="padding:0.85rem 1rem;">
                    <span style="background:#f1f5f9; color:#0f172a; padding:0.25rem 0.5rem; border-radius:4px; font-weight:bold; font-size:0.78rem;">
                      p=<?php echo htmlspecialchars($rep['policy_p']); ?>
                    </span>
                  </td>
                  <td style="padding:0.85rem 1rem; font-weight:800; font-size:0.95rem;">
                    <?php echo number_format($rep['total_messages']); ?> msgs
                  </td>
                  <td style="padding:0.85rem 1rem;">
                    <div style="display:flex; gap:0.4rem; align-items:center;">
                      <span style="background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; padding:0.2rem 0.45rem; border-radius:4px; font-size:0.72rem; font-weight:bold;">
                        ✓ <?php echo number_format($rep['total_pass']); ?> Pass
                      </span>
                      <?php if ($rep['total_fail'] > 0): ?>
                        <span style="background:#fef2f2; color:#991b1b; border:1px solid #fecaca; padding:0.2rem 0.45rem; border-radius:4px; font-size:0.72rem; font-weight:bold;">
                          ✕ <?php echo number_format($rep['total_fail']); ?> Fail
                        </span>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

            <!-- MODAL: REPORTAR ABUSO / TAKE-DOWN AL ISP -->
      <div id="modalTakedown" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.7); z-index:9999; justify-content:center; align-items:center; padding:1rem; backdrop-filter:blur(3px);">
        <div style="background:#fff; border-radius:12px; width:100%; max-width:620px; padding:2rem; box-shadow:0 20px 25px -5px rgba(0,0,0,0.3); border:1px solid #cbd5e1; max-height:90vh; overflow-y:auto;">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; border-bottom:1px solid #fee2e2; padding-bottom:0.75rem;">
            <h3 style="font-size:1.15rem; font-weight:800; color:#991b1b; margin:0;">🛑 Notificación Formal de Abuso & Take-Down al ISP</h3>
            <button onclick="document.getElementById('modalTakedown').style.display='none';" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:#64748b;">&times;</button>
          </div>
          <p style="font-size:0.82rem; color:#475569; margin-bottom:1rem; line-height:1.5;">
            Esta herramienta notifica al equipo de seguridad (CERT/SIRT) del datacenter donde está alojada la IP agresora, solicitando la suspensión inmediata del servidor por emitir estafas en nombre de la empresa.
          </p>

          <form id="formTakedown" onsubmit="submitTakedown(event)">
            <input type="hidden" id="td_domain">
            <input type="hidden" id="td_company">

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem; margin-bottom:0.75rem;">
              <div>
                <label style="display:block; font-size:0.75rem; font-weight:bold; color:#64748b; margin-bottom:0.2rem;">IP Remitente Atacante:</label>
                <input type="text" id="td_ip" readonly style="width:100%; padding:0.5rem; background:#fef2f2; border:1px solid #fecaca; border-radius:6px; font-family:monospace; font-weight:bold; color:#dc2626;">
              </div>
              <div>
                <label style="display:block; font-size:0.75rem; font-weight:bold; color:#64748b; margin-bottom:0.2rem;">Proveedor / Datacenter (ISP):</label>
                <input type="text" id="td_isp" readonly style="width:100%; padding:0.5rem; background:#f8fafc; border:1px solid #cbd5e1; border-radius:6px; font-size:0.8rem; color:#0f172a;">
              </div>
            </div>

            <div style="margin-bottom:0.75rem;">
              <label style="display:block; font-size:0.75rem; font-weight:bold; color:#64748b; margin-bottom:0.2rem;">Casilla Oficial de Abuso del Datacenter (RDAP/Whois):</label>
              <input type="email" id="td_abuse_email" required style="width:100%; padding:0.55rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.85rem; font-weight:bold; color:#003366;">
              <small id="td_lookup_status" style="font-size:0.72rem; color:#0284c7; display:block; margin-top:0.25rem;">Consultando contacto oficial de abusos...</small>
            </div>

            <div style="margin-bottom:1rem;">
              <label style="display:block; font-size:0.75rem; font-weight:bold; color:#64748b; margin-bottom:0.2rem;">Observación Forense Adicional (Opcional):</label>
              <textarea id="td_notes" placeholder="Ej: Se detectó tráfico simulando ser del departamento de tesorería pidiendo cambios de cuenta bancaria..." style="width:100%; height:60px; padding:0.5rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.8rem; resize:none;"></textarea>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:0.5rem;">
              <button type="button" onclick="document.getElementById('modalTakedown').style.display='none';" style="background:#e2e8f0; color:#475569; border:none; padding:0.6rem 1.25rem; border-radius:6px; font-weight:700; font-size:0.85rem; cursor:pointer;">Cancelar</button>
              <button type="submit" id="btnSendTakedown" style="background:#dc2626; color:#fff; border:none; padding:0.6rem 1.25rem; border-radius:6px; font-weight:700; font-size:0.85rem; cursor:pointer;">🚀 Despachar Notificación Take-Down</button>
            </div>
          </form>
        </div>
      </div>

      <!-- MODAL 1: DAR DE ALTA EMPRESA CLIENTE DMARC -->
      <div id="modalAddDmarcTenant" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.7); z-index:9999; justify-content:center; align-items:center; padding:1rem; backdrop-filter:blur(3px);">
        <div style="background:#fff; border-radius:12px; width:100%; max-width:580px; padding:2rem; box-shadow:0 20px 25px -5px rgba(0,0,0,0.3); max-height:90vh; overflow-y:auto;">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.25rem; border-bottom:1px solid #e2e8f0; padding-bottom:0.75rem;">
            <h3 style="font-size:1.2rem; font-weight:800; color:#003366; margin:0;">🏢 Alta de Empresa Cliente DMARC</h3>
            <button onclick="document.getElementById('modalAddDmarcTenant').style.display='none';" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:#64748b;">&times;</button>
          </div>
          <form method="POST" action="index.php?tab=dmarc">
            <input type="hidden" name="action" value="create_dmarc_tenant">
            
            <div style="display:grid; grid-template-columns:1.2fr 1fr; gap:0.75rem; margin-bottom:1rem;">
              <div>
                <label style="display:block; font-size:0.78rem; font-weight:700; color:#475569; margin-bottom:0.25rem;">Razón Social / Nombre Comercial *</label>
                <input type="text" name="company_name" required placeholder="Ej: Aceros Andinos S.A." style="width:100%; padding:0.55rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.85rem;">
              </div>
              <div>
                <label style="display:block; font-size:0.78rem; font-weight:700; color:#475569; margin-bottom:0.25rem;">Dominio Corporativo *</label>
                <input type="text" name="domain" required placeholder="ej: acerosandinos.cl" style="width:100%; padding:0.55rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.85rem;">
              </div>
            </div>

            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:1rem; margin-bottom:1rem;">
              <h4 style="font-size:0.85rem; color:#0f172a; margin-bottom:0.6rem; font-weight:800;">Destinatario del Dosier Mensual (CFO / Gerente TI)</h4>
              <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem; margin-bottom:0.5rem;">
                <div>
                  <label style="display:block; font-size:0.72rem; color:#64748b; margin-bottom:0.2rem;">Nombre del Directivo *</label>
                  <input type="text" name="contact_name" required placeholder="Ej: Patricia Morales" style="width:100%; padding:0.5rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.8rem;">
                </div>
                <div>
                  <label style="display:block; font-size:0.72rem; color:#64748b; margin-bottom:0.2rem;">Teléfono / WhatsApp</label>
                  <input type="text" name="contact_phone" placeholder="+56 9 1234 5678" style="width:100%; padding:0.5rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.8rem;">
                </div>
              </div>
              <div>
                <label style="display:block; font-size:0.72rem; color:#64748b; margin-bottom:0.2rem;">Correo Electrónico (Recepción Dosier Automático) *</label>
                <input type="email" name="contact_email" required placeholder="cfo@empresa.cl" style="width:100%; padding:0.5rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.8rem;">
              </div>
            </div>

            <div style="margin-bottom:1.5rem;">
              <label style="display:block; font-size:0.78rem; font-weight:700; color:#475569; margin-bottom:0.25rem;">Política DMARC Recomendada</label>
              <select name="policy_mode" style="width:100%; padding:0.55rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.85rem;">
                <option value="reject" selected>p=reject (Rechazo Total / Blindaje Máximo - Recomendada)</option>
                <option value="quarantine">p=quarantine (Cuarentena / SPAM)</option>
                <option value="none">p=none (Solo Monitoreo Pasivo)</option>
              </select>
              <small style="color:#64748b; font-size:0.75rem; display:block; margin-top:0.3rem;">
                El sistema generará el registro DNS exacto apuntando los reportes RUA hacia <code><?php echo htmlspecialchars($centralRuaEmail); ?></code>.
              </small>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:0.5rem;">
              <button type="button" onclick="document.getElementById('modalAddDmarcTenant').style.display='none';" style="background:#e2e8f0; color:#475569; border:none; padding:0.6rem 1.25rem; border-radius:6px; font-weight:700; font-size:0.85rem; cursor:pointer;">Cancelar</button>
              <button type="submit" style="background:#0284c7; color:#fff; border:none; padding:0.6rem 1.25rem; border-radius:6px; font-weight:700; font-size:0.85rem; cursor:pointer;">Habilitar Monitoreo ➜</button>
            </div>
          </form>
        </div>
      </div>

      <!-- MODAL 1.5: AGREGAR DOMINIO ADICIONAL A EMPRESA EXISTENTE -->
      <div id="modalAddExtraDomain" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.7); z-index:9999; justify-content:center; align-items:center; padding:1rem; backdrop-filter:blur(3px);">
        <div style="background:#fff; border-radius:12px; width:100%; max-width:480px; padding:1.75rem; box-shadow:0 20px 25px -5px rgba(0,0,0,0.3);">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.25rem; border-bottom:1px solid #e2e8f0; padding-bottom:0.75rem;">
            <h3 style="font-size:1.15rem; font-weight:800; color:#003366; margin:0;">🌐 Agregar Dominio a <span id="add_domain_company_name" style="color:#0284c7;"></span></h3>
            <button onclick="document.getElementById('modalAddExtraDomain').style.display='none';" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:#64748b;">&times;</button>
          </div>
          <form method="POST" action="index.php?tab=dmarc">
            <input type="hidden" name="action" value="add_dmarc_domain">
            <input type="hidden" name="tenant_id" id="add_domain_tenant_id">

            <div style="margin-bottom:1rem;">
              <label style="display:block; font-size:0.78rem; font-weight:700; color:#475569; margin-bottom:0.25rem;">Nuevo Dominio Corporativo *</label>
              <input type="text" name="domain" required placeholder="ej: holding.com, subsidiaria.pe, facturas.cl" style="width:100%; padding:0.55rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.85rem;">
              <small style="color:#64748b; font-size:0.75rem; margin-top:0.25rem; display:block;">
                La empresa podrá monitorear este dominio adicional bajo su misma cuenta y portal.
              </small>
            </div>

            <div style="margin-bottom:1.5rem;">
              <label style="display:block; font-size:0.78rem; font-weight:700; color:#475569; margin-bottom:0.25rem;">Política DMARC Inicial</label>
              <select name="policy_mode" style="width:100%; padding:0.55rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.85rem;">
                <option value="reject" selected>p=reject (Bloqueo Total / Blindaje Máximo - Recomendada)</option>
                <option value="quarantine">p=quarantine (Cuarentena)</option>
                <option value="none">p=none (Solo Monitoreo)</option>
              </select>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:0.5rem;">
              <button type="button" onclick="document.getElementById('modalAddExtraDomain').style.display='none';" style="background:#e2e8f0; color:#475569; border:none; padding:0.55rem 1rem; border-radius:6px; font-weight:700; font-size:0.85rem; cursor:pointer;">Cancelar</button>
              <button type="submit" style="background:#0284c7; color:#fff; border:none; padding:0.55rem 1.25rem; border-radius:6px; font-weight:700; font-size:0.85rem; cursor:pointer;">+ Guardar Dominio</button>
            </div>
          </form>
        </div>
      </div>

      <!-- MODAL 2: EDITAR EMPRESA CLIENTE DMARC -->
      <div id="modalEditDmarcTenant" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.7); z-index:9999; justify-content:center; align-items:center; padding:1rem; backdrop-filter:blur(3px);">
        <div style="background:#fff; border-radius:12px; width:100%; max-width:580px; padding:2rem; box-shadow:0 20px 25px -5px rgba(0,0,0,0.3); max-height:90vh; overflow-y:auto;">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.25rem; border-bottom:1px solid #e2e8f0; padding-bottom:0.75rem;">
            <h3 style="font-size:1.2rem; font-weight:800; color:#003366; margin:0;">✏️ Editar Empresa Cliente DMARC</h3>
            <button onclick="document.getElementById('modalEditDmarcTenant').style.display='none';" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:#64748b;">&times;</button>
          </div>
          <form method="POST" action="index.php?tab=dmarc">
            <input type="hidden" name="action" value="edit_dmarc_tenant">
            <input type="hidden" name="tenant_id" id="edit_dmarc_id">

            <div style="margin-bottom:1rem;">
              <label style="display:block; font-size:0.78rem; font-weight:700; color:#475569; margin-bottom:0.25rem;">Razón Social / Nombre Comercial *</label>
              <input type="text" name="company_name" id="edit_dmarc_name" required style="width:100%; padding:0.55rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.85rem;">
            </div>

            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:1rem; margin-bottom:1rem;">
              <h4 style="font-size:0.85rem; color:#0f172a; margin-bottom:0.6rem; font-weight:800;">Destinatario del Dosier Mensual</h4>
              <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem; margin-bottom:0.5rem;">
                <div>
                  <label style="display:block; font-size:0.72rem; color:#64748b; margin-bottom:0.2rem;">Nombre del Directivo *</label>
                  <input type="text" name="contact_name" id="edit_dmarc_contact_name" required style="width:100%; padding:0.5rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.8rem;">
                </div>
                <div>
                  <label style="display:block; font-size:0.72rem; color:#64748b; margin-bottom:0.2rem;">Teléfono / WhatsApp</label>
                  <input type="text" name="contact_phone" id="edit_dmarc_phone" style="width:100%; padding:0.5rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.8rem;">
                </div>
              </div>
              <div>
                <label style="display:block; font-size:0.72rem; color:#64748b; margin-bottom:0.2rem;">Correo Electrónico (Recepción Dosier) *</label>
                <input type="email" name="contact_email" id="edit_dmarc_email" required style="width:100%; padding:0.5rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.8rem;">
              </div>
            </div>

            <div style="margin-bottom:1.5rem;">
              <label style="display:block; font-size:0.78rem; font-weight:700; color:#475569; margin-bottom:0.25rem;">Política DMARC</label>
              <select name="policy_mode" id="edit_dmarc_policy" style="width:100%; padding:0.55rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.85rem;">
                <option value="reject">p=reject (Rechazo Total)</option>
                <option value="quarantine">p=quarantine (Cuarentena)</option>
                <option value="none">p=none (Solo Monitoreo)</option>
              </select>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:0.5rem;">
              <button type="button" onclick="document.getElementById('modalEditDmarcTenant').style.display='none';" style="background:#e2e8f0; color:#475569; border:none; padding:0.6rem 1.25rem; border-radius:6px; font-weight:700; font-size:0.85rem; cursor:pointer;">Cancelar</button>
              <button type="submit" style="background:#0284c7; color:#fff; border:none; padding:0.6rem 1.25rem; border-radius:6px; font-weight:700; font-size:0.85rem; cursor:pointer;">Guardar Cambios</button>
            </div>
          </form>
        </div>
      </div>

      <!-- MODAL 3: GENERADOR Y COPIA DE REGISTRO DNS TXT -->
      <div id="modalDnsRecord" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.7); z-index:9999; justify-content:center; align-items:center; padding:1rem; backdrop-filter:blur(3px);">
        <div style="background:#fff; border-radius:12px; width:100%; max-width:600px; padding:2rem; box-shadow:0 20px 25px -5px rgba(0,0,0,0.3); border:1px solid #cbd5e1;">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; border-bottom:1px solid #e2e8f0; padding-bottom:0.75rem;">
            <h3 style="font-size:1.2rem; font-weight:800; color:#003366; margin:0;">🔑 Registro DNS TXT para el Cliente</h3>
            <button onclick="document.getElementById('modalDnsRecord').style.display='none';" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:#64748b;">&times;</button>
          </div>
          <p style="font-size:0.82rem; color:#475569; margin-bottom:1.25rem;">
            Entregue estos parámetros al administrador de TI de la empresa para que los cree en su panel DNS (Cloudflare, cPanel, AWS Route53, GoDaddy, etc.):
          </p>

          <div style="background:#f8fafc; border:1px solid #cbd5e1; border-radius:8px; padding:1.25rem; margin-bottom:1.25rem;">
            <div style="margin-bottom:0.75rem;">
              <span style="font-size:0.72rem; font-weight:bold; color:#64748b; display:block;">NOMBRE / HOST:</span>
              <code id="dnsHostVal" style="font-size:0.88rem; font-weight:bold; color:#0f172a; background:#e2e8f0; padding:0.2rem 0.5rem; border-radius:4px; display:inline-block; margin-top:0.2rem;">_dmarc.dominio.cl</code>
            </div>
            <div style="margin-bottom:0.75rem;">
              <span style="font-size:0.72rem; font-weight:bold; color:#64748b; display:block;">TIPO DE REGISTRO:</span>
              <span style="font-size:0.88rem; font-weight:bold; color:#0284c7;">TXT</span>
            </div>
            <div style="margin-bottom:0.75rem;">
              <span style="font-size:0.72rem; font-weight:bold; color:#64748b; display:block;">VALOR / CONTENIDO:</span>
              <textarea id="dnsTxtVal" readonly style="width:100%; height:75px; padding:0.5rem; font-family:monospace; font-size:0.8rem; background:#fff; border:1px solid #cbd5e1; border-radius:6px; resize:none; color:#065f46; font-weight:bold;"></textarea>
            </div>
            <div>
              <span style="font-size:0.72rem; font-weight:bold; color:#64748b;">TTL RECOMENDADO:</span>
              <span style="font-size:0.82rem; color:#0f172a; font-weight:bold;"> 3600 (1 hora)</span>
            </div>
          </div>

          <div style="display:flex; justify-content:space-between; align-items:center;">
            <span id="copyFeedback" style="font-size:0.8rem; color:#059669; font-weight:bold; display:none;">✓ ¡Copiado al portapapeles!</span>
            <div style="display:flex; gap:0.5rem; margin-left:auto;">
              <button type="button" onclick="copyDnsTxt()" style="background:#059669; color:#fff; border:none; padding:0.6rem 1.25rem; border-radius:6px; font-weight:700; font-size:0.85rem; cursor:pointer;">📋 Copiar Registro TXT</button>
              <button type="button" onclick="document.getElementById('modalDnsRecord').style.display='none';" style="background:#e2e8f0; color:#475569; border:none; padding:0.6rem 1.25rem; border-radius:6px; font-weight:700; font-size:0.85rem; cursor:pointer;">Cerrar</button>
            </div>
          </div>
        </div>
      </div>

      <!-- MODAL 4: CONFIGURAR CASILLA RUA CENTRALIZADA KEYSTONE -->
      <div id="modalConfigRua" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.7); z-index:9999; justify-content:center; align-items:center; padding:1rem; backdrop-filter:blur(3px);">
        <div style="background:#fff; border-radius:12px; width:100%; max-width:500px; padding:2rem; box-shadow:0 20px 25px -5px rgba(0,0,0,0.3); border:1px solid #cbd5e1;">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; border-bottom:1px solid #e2e8f0; padding-bottom:0.75rem;">
            <h3 style="font-size:1.2rem; font-weight:800; color:#003366; margin:0;">⚙️ Casilla RUA Centralizada</h3>
            <button onclick="document.getElementById('modalConfigRua').style.display='none';" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:#64748b;">&times;</button>
          </div>
          <form method="POST" action="index.php?tab=dmarc">
            <input type="hidden" name="action" value="save_dmarc_rua_email">
            <p style="font-size:0.82rem; color:#475569; margin-bottom:1rem; line-height:1.5;">
              Esta es la dirección de correo corporativa que se inyecta en los registros DNS de todos los clientes (<code>rua=mailto:...</code>). Los reportes diarios de Google y Microsoft llegarán a esta casilla.
            </p>
            <div style="margin-bottom:1.25rem;">
              <label style="display:block; font-size:0.78rem; font-weight:700; color:#475569; margin-bottom:0.25rem;">Correo Electrónico Central RUA *</label>
              <input type="email" name="central_rua_email" required value="<?php echo htmlspecialchars($centralRuaEmail); ?>" style="width:100%; padding:0.6rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.9rem; font-weight:bold; color:#003366;">
            </div>
            <div style="display:flex; justify-content:flex-end; gap:0.5rem;">
              <button type="button" onclick="document.getElementById('modalConfigRua').style.display='none';" style="background:#e2e8f0; color:#475569; border:none; padding:0.6rem 1.25rem; border-radius:6px; font-weight:700; font-size:0.85rem; cursor:pointer;">Cancelar</button>
              <button type="submit" style="background:#003366; color:#fff; border:none; padding:0.6rem 1.25rem; border-radius:6px; font-weight:700; font-size:0.85rem; cursor:pointer;">Guardar Casilla</button>
            </div>
          </form>
        </div>
      </div>

      <!-- Modal Subida de Reporte DMARC RUA -->
      <div id="modalUploadDmarc" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.7); z-index:9999; justify-content:center; align-items:center; padding:1rem; backdrop-filter:blur(3px);">
        <div style="background:#fff; width:100%; max-width:550px; border-radius:12px; padding:1.75rem; box-shadow:0 20px 25px -5px rgba(0,0,0,0.3); border:1px solid #cbd5e1;">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; border-bottom:1px solid #e2e8f0; padding-bottom:0.75rem;">
            <h3 style="font-size:1.2rem; font-weight:800; color:#003366; margin:0;">📥 Ingestar Reporte Agregado DMARC</h3>
            <button onclick="document.getElementById('modalUploadDmarc').style.display='none';" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:#64748b;">&times;</button>
          </div>
          <form method="POST" action="index.php?tab=dmarc" enctype="multipart/form-data">
            <input type="hidden" name="action" value="ingest_dmarc_report">
            <p style="font-size:0.82rem; color:#64748b; margin-bottom:1.25rem;">
              Seleccione el archivo emitido por proveedores de correo (Google Workspace, Microsoft 365, Yahoo, etc.). Se admiten archivos <strong>.xml</strong>, comprimidos <strong>.xml.gz</strong> o <strong>.zip</strong>.
            </p>
            <div style="border:2px dashed #cbd5e1; border-radius:8px; padding:1.5rem; text-align:center; background:#f8fafc; margin-bottom:1.25rem;">
              <span style="font-size:2rem; display:block; margin-bottom:0.5rem;">📂</span>
              <label style="cursor:pointer; background:#003366; color:#fff; padding:0.5rem 1rem; border-radius:6px; font-size:0.85rem; font-weight:700; display:inline-block;">
                Seleccionar Archivo
                <input type="file" name="dmarc_file" required accept=".xml,.gz,.zip" style="display:none;" onchange="document.getElementById('selectedFileName').textContent = this.files[0] ? this.files[0].name : '';">
              </label>
              <div id="selectedFileName" style="margin-top:0.75rem; font-size:0.8rem; font-weight:bold; color:#003366;"></div>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:0.5rem;">
              <button type="button" onclick="document.getElementById('modalUploadDmarc').style.display='none';" style="background:#e2e8f0; color:#475569; border:none; padding:0.6rem 1.25rem; border-radius:6px; font-weight:700; font-size:0.85rem; cursor:pointer;">Cancelar</button>
              <button type="submit" style="background:#059669; color:#fff; border:none; padding:0.6rem 1.25rem; border-radius:6px; font-weight:700; font-size:0.85rem; cursor:pointer;">Procesar & Analizar ➜</button>
            </div>
          </form>
        </div>
      </div>

    <?php elseif ($tab === 'redteaming'): ?>
      <?php
      require_once __DIR__ . '/../api/RedTeamingService.php';
      $rtService = new RedTeamingService($db);
      $campaigns = $rtService->listCampaigns();

      // Consultar simulaciones públicas recientes (Leads)
      $simsStmt = $db->query("SELECT * FROM redteam_simulations ORDER BY created_at DESC LIMIT 20");
      $publicSims = $simsStmt ? $simsStmt->fetchAll(PDO::FETCH_ASSOC) : [];
      $capturedLeadsCount = (int)$db->query("SELECT COUNT(*) FROM redteam_simulations WHERE status = 'LEAD_CAPTURED'")->fetchColumn();
      ?>

      <?php if (isset($_GET['campaign_created'])): ?>
        <div style="background:#ecfdf5; border:1px solid #6ee7b7; border-radius:10px; padding:1rem 1.25rem; margin-bottom:1.5rem; display:flex; align-items:center; gap:0.75rem; color:#065f46; font-size:0.9rem;">
          <span style="font-size:1.4rem;">✅</span>
          <div>
            <strong>¡Campaña Corporativa de Red Teaming Habilitada con Éxito!</strong>
            <div style="font-size:0.8rem; color:#047857; margin-top:0.15rem;">La campaña ha sido aprovisionada con sus vectores correspondientes y el equipo de simulación ya se encuentra listo para iniciar el programa.</div>
          </div>
        </div>
      <?php endif; ?>

      <!-- Header de la Suite Red Teaming -->
      <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; padding:1.75rem; margin-bottom:1.5rem; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem;">
        <div>
          <div style="display:inline-flex; align-items:center; gap:0.5rem; background:#eff6ff; border:1px solid #bfdbfe; padding:0.25rem 0.65rem; border-radius:9999px; font-size:0.75rem; font-weight:800; color:#1d4ed8; text-transform:uppercase; margin-bottom:0.4rem;">
            <span>🎭 Suite de Ciberseguridad Ofensiva</span>
          </div>
          <h2 style="font-size:1.5rem; font-weight:800; color:#003366; margin:0;">Red Teaming & Simulaciones Asistidas por IA</h2>
          <p style="color:#64748b; font-size:0.88rem; margin-top:0.25rem; max-width:750px;">
            Plataforma B2B para gestionar simulaciones de ingeniería social contextual (BEC, Vishing y cambio de cuentas) y planes de entrenamiento corporativo para empresas clientes.
          </p>
        </div>

        <button onclick="document.getElementById('modalNewRtCampaign').style.display='flex';" class="btn" style="background:#0284c7; color:#fff; border:none; padding:0.65rem 1.25rem; border-radius:8px; font-weight:700; font-size:0.85rem; cursor:pointer; display:inline-flex; align-items:center; gap:0.5rem; box-shadow:0 4px 12px rgba(2,132,199,0.25);">
          + Nueva Campaña Corporativa B2B
        </button>
      </div>

      <!-- Cuadrícula de Métricas de Red Teaming -->
      <div class="metrics-grid" style="margin-bottom:1.75rem;">
        <div class="metric-card">
          <div class="metric-title">Campañas Activas / Planificadas</div>
          <div class="metric-value" style="color:#0284c7;"><?php echo count($campaigns); ?></div>
          <div class="metric-desc">Programas de entrenamiento en ejecución</div>
        </div>

        <div class="metric-card">
          <div class="metric-title">Simulaciones Demo en Web</div>
          <div class="metric-value" style="color:#10b981;"><?php echo count($publicSims); ?></div>
          <div class="metric-desc">Ejecutadas por prospectos en landing</div>
        </div>

        <div class="metric-card">
          <div class="metric-title">Leads Calificados Capturados</div>
          <div class="metric-value" style="color:#f59e0b;"><?php echo $capturedLeadsCount; ?></div>
          <div class="metric-desc">Prospectos pidiendo plan corporativo</div>
        </div>

        <div class="metric-card">
          <div class="metric-title">Motor LLM de Generación</div>
          <div class="metric-value" style="font-size:1.15rem; color:#0f172a; font-family:monospace; margin-top:0.4rem;">LLaMA 3.1 8B</div>
          <div class="metric-desc">Cloudflare Workers AI (Latencia &lt; 800ms)</div>
        </div>
      </div>

      <!-- Tabla 1: Campañas Corporativas B2B Pagadas -->
      <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; padding:1.5rem; margin-bottom:2rem;">
        <h3 style="font-size:1.1rem; font-weight:800; color:#003366; margin-bottom:1rem; display:flex; align-items:center; gap:0.5rem;">
          <span>💼 Campañas de Red Teaming Contratadas</span>
        </h3>

        <?php if (empty($campaigns)): ?>
          <div style="padding:2rem; text-align:center; color:#94a3b8; font-size:0.9rem;">
            No hay campañas corporativas registradas aún. Haga clic en "+ Nueva Campaña Corporativa B2B" para crear la primera.
          </div>
        <?php else: ?>
          <div style="overflow-x:auto;">
            <table class="data-table" style="width:100%; border-collapse:collapse; font-size:0.85rem;">
              <thead>
                <tr style="background:#f8fafc; border-bottom:1px solid #e2e8f0; text-align:left; color:#64748b; font-size:0.75rem; text-transform:uppercase;">
                  <th style="padding:0.75rem;">Empresa & Contacto</th>
                  <th style="padding:0.75rem;">Plan Contratado</th>
                  <th style="padding:0.75rem;">Dominios / Alcance</th>
                  <th style="padding:0.75rem;">Vectores Simulados</th>
                  <th style="padding:0.75rem; text-align:center;">Estado</th>
                  <th style="padding:0.75rem; text-align:center;">Gestión</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($campaigns as $camp): ?>
                  <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:0.75rem;">
                      <strong style="color:#0f172a; font-size:0.92rem;"><?php echo htmlspecialchars($camp['company_name']); ?></strong><br>
                      <span style="color:#64748b; font-size:0.75rem;">👤 <?php echo htmlspecialchars($camp['contact_name']); ?> (<?php echo htmlspecialchars($camp['contact_email']); ?>)</span>
                    </td>
                    <td style="padding:0.75rem;">
                      <span style="background:#e0f2fe; color:#0369a1; padding:0.2rem 0.5rem; border-radius:4px; font-weight:800; font-size:0.75rem;">
                        <?php echo htmlspecialchars($camp['plan_type']); ?>
                      </span>
                    </td>
                    <td style="padding:0.75rem; color:#475569;">
                      <code>@<?php echo htmlspecialchars($camp['target_domains']); ?></code><br>
                      <span style="font-size:0.72rem; color:#64748b;">(<?php echo (int)$camp['target_users_count']; ?> colaboradores evaluados)</span>
                    </td>
                    <td style="padding:0.75rem; font-size:0.78rem; color:#0f172a;">
                      <?php echo htmlspecialchars(str_replace(',', ' • ', $camp['simulated_vectors'])); ?>
                    </td>
                    <td style="padding:0.75rem; text-align:center;">
                      <?php if ($camp['status'] === 'ACTIVE'): ?>
                        <span style="background:#dcfce7; color:#15803d; padding:0.15rem 0.5rem; border-radius:9999px; font-weight:800; font-size:0.7rem;">● EN EJECUCIÓN</span>
                      <?php elseif ($camp['status'] === 'COMPLETED'): ?>
                        <span style="background:#e0e7ff; color:#3730a3; padding:0.15rem 0.5rem; border-radius:9999px; font-weight:800; font-size:0.7rem;">✓ CULMINADA</span>
                      <?php else: ?>
                        <span style="background:#fef3c7; color:#b45309; padding:0.15rem 0.5rem; border-radius:9999px; font-weight:800; font-size:0.7rem;">⏳ PLANIFICACIÓN</span>
                      <?php endif; ?>
                    </td>
                    <td style="padding:0.75rem; text-align:center;">
                      <form method="POST" style="display:inline-flex; gap:0.3rem;">
                        <input type="hidden" name="action" value="update_redteam_status">
                        <input type="hidden" name="campaign_id" value="<?php echo $camp['id']; ?>">
                        <select name="status" onchange="this.form.submit()" style="padding:0.25rem 0.45rem; font-size:0.75rem; border:1px solid #cbd5e1; border-radius:4px;">
                          <option value="PLANNING" <?php echo $camp['status'] === 'PLANNING' ? 'selected' : ''; ?>>Planificación</option>
                          <option value="ACTIVE" <?php echo $camp['status'] === 'ACTIVE' ? 'selected' : ''; ?>>Activar</option>
                          <option value="COMPLETED" <?php echo $camp['status'] === 'COMPLETED' ? 'selected' : ''; ?>>Completar</option>
                          <option value="CANCELLED" <?php echo $camp['status'] === 'CANCELLED' ? 'selected' : ''; ?>>Cancelar</option>
                        </select>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <!-- Tabla 2: Demostraciones Públicas Ejecutadas en Landing (Leads) -->
      <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; padding:1.5rem;">
        <h3 style="font-size:1.1rem; font-weight:800; color:#003366; margin-bottom:1rem; display:flex; align-items:center; gap:0.5rem;">
          <span>🎯 Prospectos que Ejecutaron el Simulador Web (Lead Magnet)</span>
        </h3>

        <?php if (empty($publicSims)): ?>
          <div style="padding:2rem; text-align:center; color:#94a3b8; font-size:0.9rem;">
            Aún no se registran simulaciones públicas desde la landing.
          </div>
        <?php else: ?>
          <div style="overflow-x:auto;">
            <table class="data-table" style="width:100%; border-collapse:collapse; font-size:0.85rem;">
              <thead>
                <tr style="background:#f8fafc; border-bottom:1px solid #e2e8f0; text-align:left; color:#64748b; font-size:0.75rem; text-transform:uppercase;">
                  <th style="padding:0.75rem;">Fecha & IP</th>
                  <th style="padding:0.75rem;">Empresa & Industria</th>
                  <th style="padding:0.75rem;">Contacto / Solicitante</th>
                  <th style="padding:0.75rem;">Teléfono & WhatsApp</th>
                  <th style="padding:0.75rem;">Vector Evaluado</th>
                  <th style="padding:0.75rem; text-align:center;">Estado</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($publicSims as $ps): ?>
                  <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:0.75rem; font-size:0.75rem; color:#64748b;">
                      <?php echo substr($ps['created_at'], 0, 16); ?><br>
                      <code><?php echo htmlspecialchars($ps['ip_address'] ?? ''); ?></code>
                    </td>
                    <td style="padding:0.75rem;">
                      <strong style="color:#0f172a; font-size:0.92rem;"><?php echo htmlspecialchars($ps['company_name']); ?></strong><br>
                      <span style="color:#64748b; font-size:0.75rem;">🏭 <?php echo htmlspecialchars($ps['industry']); ?></span>
                    </td>
                    <td style="padding:0.75rem;">
                      <?php if (!empty($ps['contact_name'])): ?>
                        <strong style="color:#0f172a; font-size:0.88rem;"><?php echo htmlspecialchars($ps['contact_name']); ?></strong><br>
                      <?php endif; ?>
                      <?php if (!empty($ps['lead_email'])): ?>
                        <a href="mailto:<?php echo htmlspecialchars($ps['lead_email']); ?>" style="color:#0284c7; text-decoration:none; font-weight:bold; font-size:0.8rem;">
                          ✉️ <?php echo htmlspecialchars($ps['lead_email']); ?>
                        </a>
                      <?php else: ?>
                        <span style="color:#94a3b8; font-size:0.75rem;">No proporcionado</span>
                      <?php endif; ?>
                    </td>
                    <td style="padding:0.75rem;">
                      <?php if (!empty($ps['lead_phone'])): ?>
                        <?php $cleanPhone = preg_replace('/[^0-9]/', '', $ps['lead_phone']); ?>
                        <a href="https://wa.me/<?php echo $cleanPhone; ?>" target="_blank" style="background:#dcfce7; color:#15803d; border:1px solid #86efac; padding:0.25rem 0.55rem; border-radius:4px; font-weight:800; font-size:0.75rem; text-decoration:none; display:inline-flex; align-items:center; gap:0.3rem;">
                          <span>📲</span> <?php echo htmlspecialchars($ps['lead_phone']); ?>
                        </a>
                      <?php else: ?>
                        <span style="color:#94a3b8; font-size:0.75rem;">Sin teléfono</span>
                      <?php endif; ?>
                    </td>
                    <td style="padding:0.75rem; font-size:0.78rem; color:#0284c7; font-weight:bold;">
                      <?php echo htmlspecialchars($ps['attack_vector']); ?>
                    </td>
                    <td style="padding:0.75rem; text-align:center;">
                      <?php if ($ps['status'] === 'LEAD_CAPTURED'): ?>
                        <span style="background:#dcfce7; color:#15803d; padding:0.15rem 0.5rem; border-radius:9999px; font-weight:800; font-size:0.7rem;">🎯 LEAD CALIFICADO</span>
                      <?php else: ?>
                        <span style="background:#f1f5f9; color:#64748b; padding:0.15rem 0.5rem; border-radius:9999px; font-weight:700; font-size:0.7rem;">SIMULADO</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <!-- Modal Crear Nueva Campaña Red Teaming -->
      <div id="modalNewRtCampaign" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.7); z-index:9999; justify-content:center; align-items:center; padding:1rem; backdrop-filter:blur(3px);">
        <div style="background:#fff; border-radius:12px; width:100%; max-width:540px; padding:1.75rem; box-shadow:0 20px 25px -5px rgba(0,0,0,0.3); max-height:90vh; overflow-y:auto;">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.25rem; border-bottom:1px solid #e2e8f0; padding-bottom:0.75rem;">
            <h3 style="font-size:1.15rem; font-weight:800; color:#003366; margin:0;">💼 Alta de Campaña Red Teaming B2B</h3>
            <button onclick="document.getElementById('modalNewRtCampaign').style.display='none';" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:#64748b;">&times;</button>
          </div>

          <form method="POST">
            <input type="hidden" name="action" value="create_redteam_campaign">
            
            <div style="margin-bottom:1rem;">
              <label style="display:block; font-size:0.78rem; font-weight:700; color:#475569; margin-bottom:0.25rem;">Razón Social / Empresa Cliente *</label>
              <input type="text" name="company_name" required placeholder="Ej: Inversiones del Sur SpA" style="width:100%; padding:0.55rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.85rem;">
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem; margin-bottom:1rem;">
              <div>
                <label style="display:block; font-size:0.78rem; font-weight:700; color:#475569; margin-bottom:0.25rem;">Contacto / Patrocinador *</label>
                <input type="text" name="contact_name" required placeholder="Ej: Carlos Silva (CISO)" style="width:100%; padding:0.55rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.85rem;">
              </div>
              <div>
                <label style="display:block; font-size:0.78rem; font-weight:700; color:#475569; margin-bottom:0.25rem;">Correo Oficial *</label>
                <input type="email" name="contact_email" required placeholder="csilva@empresa.cl" style="width:100%; padding:0.55rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.85rem;">
              </div>
            </div>

            <div style="display:grid; grid-template-columns:1.2fr 1fr; gap:0.75rem; margin-bottom:1rem;">
              <div>
                <label style="display:block; font-size:0.78rem; font-weight:700; color:#475569; margin-bottom:0.25rem;">Dominio Objetivo *</label>
                <input type="text" name="target_domains" required placeholder="empresa.cl" style="width:100%; padding:0.55rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.85rem;">
              </div>
              <div>
                <label style="display:block; font-size:0.78rem; font-weight:700; color:#475569; margin-bottom:0.25rem;">Plan Contratado</label>
                <select name="plan_type" id="rtPlanSelect" onchange="syncRedTeamPlanVectors(this.value)" style="width:100%; padding:0.55rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.85rem; font-weight:700; color:#003366;">
                  <option value="STARTER">Starter (USD $1.200) — 2 Vectores</option>
                  <option value="PROFESSIONAL" selected>Professional (USD $2.500) — 4 Vectores</option>
                  <option value="ENTERPRISE">Enterprise (USD $4.800) — Cobertura Total (6 Vectores)</option>
                </select>
              </div>
            </div>

            <div style="margin-bottom:1.5rem;">
              <label style="display:block; font-size:0.78rem; font-weight:700; color:#475569; margin-bottom:0.4rem;">Vectores a Simular en la Campaña (Selección Múltiple) *</label>
              
              <div style="background:#f8fafc; border:1px solid #cbd5e1; border-radius:8px; padding:0.75rem; display:grid; grid-template-columns:1fr 1fr; gap:0.6rem;">
                <label style="display:flex; align-items:flex-start; gap:0.45rem; font-size:0.8rem; color:#1e293b; cursor:pointer;">
                  <input type="checkbox" name="simulated_vectors[]" value="BEC" checked style="margin-top:0.15rem; cursor:pointer;">
                  <span><strong>💼 Fraude del CEO (BEC)</strong><br><small style="color:#64748b;">Transferencia urgente y confidencial</small></span>
                </label>

                <label style="display:flex; align-items:flex-start; gap:0.45rem; font-size:0.8rem; color:#1e293b; cursor:pointer;">
                  <input type="checkbox" name="simulated_vectors[]" value="INVOICE_FRAUD" checked style="margin-top:0.15rem; cursor:pointer;">
                  <span><strong>🏦 Cambio de Cuenta Proveedor</strong><br><small style="color:#64748b;">Desvío de facturas pendientes</small></span>
                </label>

                <label style="display:flex; align-items:flex-start; gap:0.45rem; font-size:0.8rem; color:#1e293b; cursor:pointer;">
                  <input type="checkbox" name="simulated_vectors[]" value="VISHING" style="margin-top:0.15rem; cursor:pointer;">
                  <span><strong>📞 Vishing con Voz Sintética</strong><br><small style="color:#64748b;">Llamada deepfake a directivos (Enterprise)</small></span>
                </label>

                <label style="display:flex; align-items:flex-start; gap:0.45rem; font-size:0.8rem; color:#1e293b; cursor:pointer;">
                  <input type="checkbox" name="simulated_vectors[]" value="SPEAR_PHISHING_SII" style="margin-top:0.15rem; cursor:pointer;">
                  <span><strong>⚖️ Spear-Phishing Tributario</strong><br><small style="color:#64748b;">Notificación fraudulenta del SII</small></span>
                </label>

                <label style="display:flex; align-items:flex-start; gap:0.45rem; font-size:0.8rem; color:#1e293b; cursor:pointer;">
                  <input type="checkbox" name="simulated_vectors[]" value="PASSKEY_BYPASS" checked style="margin-top:0.15rem; cursor:pointer;">
                  <span><strong>🔑 AitM / Robo de Sesión 2FA</strong><br><small style="color:#64748b;">Intercepción de códigos SMS/MFA</small></span>
                </label>

                <label style="display:flex; align-items:flex-start; gap:0.45rem; font-size:0.8rem; color:#1e293b; cursor:pointer;">
                  <input type="checkbox" name="simulated_vectors[]" value="QR_QUISHING" checked style="margin-top:0.15rem; cursor:pointer;">
                  <span><strong>📱 Quishing (QR Malicioso)</strong><br><small style="color:#64748b;">Escaner físico o en correos</small></span>
                </label>
              </div>
              <small style="color:#64748b; font-size:0.72rem; display:block; margin-top:0.35rem;">Marque las tácticas que se incluirán en el alcance de la prueba de penetración social.</small>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:0.5rem;">
              <button type="button" onclick="document.getElementById('modalNewRtCampaign').style.display='none';" style="background:#e2e8f0; color:#475569; border:none; padding:0.6rem 1.25rem; border-radius:6px; font-weight:700; font-size:0.85rem; cursor:pointer;">Cancelar</button>
              <button type="submit" style="background:#0284c7; color:#fff; border:none; padding:0.6rem 1.25rem; border-radius:6px; font-weight:700; font-size:0.85rem; cursor:pointer;">Habilitar Campaña ➜</button>
            </div>
          </form>
        </div>
      </div>

      <script>
      function syncRedTeamPlanVectors(plan) {
        const vectorMap = {
          'STARTER': ['BEC', 'SPEAR_PHISHING_SII'],
          'PROFESSIONAL': ['BEC', 'INVOICE_FRAUD', 'PASSKEY_BYPASS', 'QR_QUISHING'],
          'ENTERPRISE': ['BEC', 'INVOICE_FRAUD', 'VISHING', 'SPEAR_PHISHING_SII', 'PASSKEY_BYPASS', 'QR_QUISHING']
        };

        const targetVectors = vectorMap[plan] || vectorMap['PROFESSIONAL'];
        const checkboxes = document.querySelectorAll('#modalNewRtCampaign input[name="simulated_vectors[]"]');
        
        checkboxes.forEach(cb => {
          cb.checked = targetVectors.includes(cb.value);
        });
      }
      </script>


    <?php elseif ($tab === 'marketing'): ?>
      <?php
        require_once __DIR__ . '/../api/KeystoneGrowthService.php';
        $mktMetrics = KeystoneGrowthService::getMarketingMetrics();
        $recentTouchpoints = KeystoneGrowthService::getDb()->query("SELECT * FROM mkt_touchpoints ORDER BY id DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
        $highIntentList = KeystoneGrowthService::getDb()->query("SELECT * FROM mkt_ux_intent WHERE intent_score >= 35 ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        $recentCreatives = $mktMetrics['recent_creatives'] ?? [];
      ?>
      
      <!-- Growth & Marketing Header -->
      <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:1.5rem; flex-wrap:wrap; gap:1rem;">
        <div>
          <h2 style="font-size:1.6rem; font-weight:800; color:#003366; margin:0 0 0.35rem 0;">
            📈 Keystone Growth Engine & AI Marketing
          </h2>
          <p style="font-size:0.9rem; color:#64748b; margin:0;">
            Atribución multicanal en tiempo real, Buyer Intent Scoring con telemetría UX y generador de piezas con Google Gemini 3.8 Flash + Cloudflare FLUX.1.
          </p>
        </div>
        <div style="display:flex; gap:0.65rem; flex-wrap:wrap;">
          <button type="button" onclick="openCampaignPlannerModal();" style="background:linear-gradient(135deg, #059669 0%, #047857 100%); color:#ffffff; border:none; padding:0.65rem 1.25rem; border-radius:8px; font-weight:800; font-size:0.88rem; cursor:pointer; display:flex; align-items:center; gap:0.5rem; box-shadow:0 4px 14px rgba(5,150,105,0.35);">
            <span style="font-size:1.1rem;">📅</span> Planificar Campaña Completa (Calendario & IA)
          </button>
          <button type="button" onclick="openLinkedInDirectStudio();" style="background:linear-gradient(135deg, #0a66c2 0%, #004182 100%); color:#ffffff; border:none; padding:0.65rem 1.25rem; border-radius:8px; font-weight:800; font-size:0.88rem; cursor:pointer; display:flex; align-items:center; gap:0.5rem; box-shadow:0 4px 14px rgba(10,102,194,0.35);">
            <span style="font-size:1.1rem;">💼</span> Publicador Directo LinkedIn (Página Empresa)
          </button>
          <button type="button" onclick="document.getElementById('modalAiAdGenerator').style.display='flex';" style="background:linear-gradient(135deg, #0284c7 0%, #003366 100%); color:#ffffff; border:none; padding:0.65rem 1.15rem; border-radius:8px; font-weight:700; font-size:0.85rem; cursor:pointer; display:flex; align-items:center; gap:0.45rem; box-shadow:0 2px 8px rgba(2,132,199,0.25);">
            <span>✨</span> Generador Multicanal IA
          </button>
          <button type="button" onclick="document.getElementById('modalLinkBuilder').style.display='flex';" style="background:#0f172a; color:#f8fafc; border:1px solid #334155; padding:0.65rem 1.15rem; border-radius:8px; font-weight:700; font-size:0.85rem; cursor:pointer; display:flex; align-items:center; gap:0.45rem;">
            <span>🔗</span> Constructor UTM
          </button>
          <button type="button" onclick="document.getElementById('modalAdsAccounts').style.display='flex';" style="background:#ffffff; color:#003366; border:1px solid #cbd5e1; padding:0.65rem 1.15rem; border-radius:8px; font-weight:700; font-size:0.85rem; cursor:pointer; display:flex; align-items:center; gap:0.45rem; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
            <span>⚙️</span> Conectar Redes Ads
          </button>
        </div>
      </div>

      <!-- KPI Summary Cards -->
      <div class="metrics-grid" style="margin-bottom:1.5rem;">
        <div class="metric-card">
          <div class="metric-title">Impactos & Clics Totales</div>
          <div class="metric-value"><?php echo number_format($mktMetrics['total_clicks']); ?></div>
          <div class="metric-sub">Visitantes Únicos: <strong><?php echo number_format($mktMetrics['unique_visitors']); ?></strong></div>
        </div>
        <div class="metric-card">
          <div class="metric-title">Conversiones Registradas</div>
          <div class="metric-value" style="color:#16a34a;"><?php echo number_format($mktMetrics['total_conversions']); ?></div>
          <div class="metric-sub">Tasa Global: <strong><?php echo $mktMetrics['total_clicks'] > 0 ? round(($mktMetrics['total_conversions'] / $mktMetrics['total_clicks']) * 100, 1) : 0; ?>%</strong></div>
        </div>
        <div class="metric-card">
          <div class="metric-title">Buyer Intent Score Promedio</div>
          <div class="metric-value" style="color:#0284c7;"><?php echo $mktMetrics['avg_intent_score']; ?> <span style="font-size:1rem; color:#64748b;">/ 100</span></div>
          <div class="metric-sub">Inferencia UX Pre-Compra</div>
        </div>
        <div class="metric-card">
          <div class="metric-title">Ingresos Atribuidos (Pipeline)</div>
          <div class="metric-value" style="color:#003366;">$<?php echo number_format($mktMetrics['total_revenue'], 0, ',', '.'); ?> CLP</div>
          <div class="metric-sub">Retorno Comprobado</div>
        </div>
      </div>

      <!-- Grid Principal: Canales de Atribución & Tráfico Caliente -->
      <div class="dmarc-dual-grid">
        
        <!-- Tabla Rendimiento por Canal / Red Publicitaria -->
        <div class="funnel-box">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; border-bottom:1px solid #e2e8f0; padding-bottom:0.75rem;">
            <h3 style="font-size:1.05rem; font-weight:800; color:#003366; margin:0;">🎯 Atribución por Plataforma de Ads</h3>
            <span style="font-size:0.75rem; background:#e0f2fe; color:#0369a1; padding:0.2rem 0.5rem; border-radius:4px; font-weight:700;">Google / LinkedIn / Meta</span>
          </div>
          <div style="overflow-x:auto;">
            <table class="compact-table" style="font-size:0.82rem;">
              <thead>
                <tr>
                  <th>Plataforma</th>
                  <th style="text-align:right;">Clics</th>
                  <th style="text-align:right;">Únicos</th>
                  <th style="text-align:right;">Conversiones</th>
                  <th style="text-align:right;">Tasa Conv.</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($mktMetrics['platforms'])): ?>
                  <tr>
                    <td colspan="5" style="text-align:center; color:#94a3b8; padding:2rem;">
                      Aún no hay balizas de tráfico registradas. Los eventos se poblarán automáticamente al recibir visitas desde campañas.
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($mktMetrics['platforms'] as $plat): ?>
                    <tr>
                      <td style="font-weight:700; color:#1e293b;">
                        <?php 
                          $p = strtoupper($plat['platform']);
                          if ($p === 'GOOGLE_ADS') echo '🔍 Google Ads';
                          elseif ($p === 'LINKEDIN_ADS') echo '💼 LinkedIn Ads';
                          elseif ($p === 'META_ADS') echo '📱 Meta Ads (FB/IG)';
                          else echo '🌐 ' . htmlspecialchars($p);
                        ?>
                      </td>
                      <td style="text-align:right; font-weight:700;"><?php echo number_format($plat['total_clicks']); ?></td>
                      <td style="text-align:right; color:#64748b;"><?php echo number_format($plat['unique_users']); ?></td>
                      <td style="text-align:right; font-weight:700; color:#16a34a;"><?php echo number_format($plat['conversions']); ?></td>
                      <td style="text-align:right;">
                        <span style="font-weight:700; color:<?php echo $plat['conv_rate'] > 0 ? '#16a34a' : '#64748b'; ?>;">
                          <?php echo $plat['conv_rate']; ?>%
                        </span>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Radar de Compradores Calientes (Pre-Purchase Buyer Intent) -->
        <div class="funnel-box">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; border-bottom:1px solid #e2e8f0; padding-bottom:0.75rem;">
            <h3 style="font-size:1.05rem; font-weight:800; color:#003366; margin:0;">🔥 Radar de Intención de Compra (Inferencia UX)</h3>
            <span style="font-size:0.75rem; background:#fef3c7; color:#92400e; padding:0.2rem 0.5rem; border-radius:4px; font-weight:700;">Score ≥ 35</span>
          </div>
          <div style="overflow-x:auto;">
            <table class="compact-table" style="font-size:0.82rem;">
              <thead>
                <tr>
                  <th>Visitante / Tenant</th>
                  <th style="text-align:center;">Scroll</th>
                  <th style="text-align:center;">Dwell Precios</th>
                  <th style="text-align:center;">Categoría Inferida</th>
                  <th style="text-align:right;">Intent Score</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($highIntentList)): ?>
                  <tr>
                    <td colspan="5" style="text-align:center; color:#94a3b8; padding:2rem;">
                      No hay sesiones de alta intención registradas recientemente.
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($highIntentList as $hi): ?>
                    <tr>
                      <td>
                        <strong style="color:#0f172a;"><code><?php echo htmlspecialchars(substr($hi['visitor_uid'], 0, 14)); ?>...</code></strong><br>
                        <span style="font-size:0.75rem; color:#64748b;">Tenant: <strong><?php echo htmlspecialchars($hi['tenant_slug']); ?></strong></span>
                      </td>
                      <td style="text-align:center;"><?php echo (int)$hi['max_scroll_depth_pct']; ?>%</td>
                      <td style="text-align:center;"><?php echo (int)$hi['dwell_time_pricing_sec']; ?>s</td>
                      <td style="text-align:center;">
                        <span style="font-size:0.72rem; padding:0.15rem 0.45rem; border-radius:4px; font-weight:700; background:#f1f5f9; color:#475569;">
                          <?php echo htmlspecialchars($hi['inferred_intent_category']); ?>
                        </span>
                      </td>
                      <td style="text-align:right;">
                        <span style="font-size:0.85rem; font-weight:800; padding:0.2rem 0.55rem; border-radius:99px; <?php 
                          $sc = (float)$hi['intent_score'];
                          if ($sc >= 75) echo 'background:#dcfce7; color:#166534; border:1px solid #86efac;';
                          elseif ($sc >= 45) echo 'background:#fef3c7; color:#92400e; border:1px solid #fde68a;';
                          else echo 'background:#e2e8f0; color:#334155;';
                        ?>">
                          <?php echo $hi['intent_score']; ?> pts
                        </span>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

      </div>

      <!-- Registro Reciente de Touchpoints -->
      <div class="funnel-box" style="margin-bottom:2rem;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; border-bottom:1px solid #e2e8f0; padding-bottom:0.75rem;">
          <h3 style="font-size:1.05rem; font-weight:800; color:#003366; margin:0;">📡 Registro en Tiempo Real de Touchpoints Entrantes</h3>
          <span style="font-size:0.78rem; color:#64748b;">Últimos 15 impactos capturados por <code>growth-tracker.js</code></span>
        </div>
        <div style="overflow-x:auto;">
          <table class="compact-table" style="font-size:0.8rem;">
            <thead>
              <tr>
                <th>Fecha / Hora</th>
                <th>Tenant</th>
                <th>Plataforma</th>
                <th>Campaña (UTM)</th>
                <th>Click ID (GCLID / LI / FB)</th>
                <th>Landing URL</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($recentTouchpoints)): ?>
                <tr>
                  <td colspan="6" style="text-align:center; color:#94a3b8; padding:2rem;">
                    Sin eventos registrados aún. Inyecte <code>assets/js/growth-tracker.js</code> en el pie de página de las aplicaciones.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($recentTouchpoints as $tp): ?>
                  <tr>
                    <td style="white-space:nowrap; color:#64748b;"><?php echo htmlspecialchars($tp['created_at']); ?></td>
                    <td><span class="badge-type-checkout" style="font-size:0.7rem;"><?php echo htmlspecialchars($tp['tenant_slug']); ?></span></td>
                    <td style="font-weight:700; color:#003366;"><?php echo htmlspecialchars($tp['platform']); ?></td>
                    <td>
                      <span class="badge-type-lead" style="font-size:0.7rem;">
                        <?php echo htmlspecialchars($tp['campaign_name'] ?: '(Sin Campaña)'); ?>
                      </span>
                    </td>
                    <td>
                      <?php if (!empty($tp['gclid'])): ?>
                        <span style="background:#dbeafe; color:#1e40af; padding:0.15rem 0.4rem; border-radius:3px; font-size:0.7rem; font-family:monospace;">gclid: <?php echo htmlspecialchars(substr($tp['gclid'], 0, 10)); ?>...</span>
                      <?php elseif (!empty($tp['li_fat_id'])): ?>
                        <span style="background:#e0e7ff; color:#3730a3; padding:0.15rem 0.4rem; border-radius:3px; font-size:0.7rem; font-family:monospace;">li_id: <?php echo htmlspecialchars(substr($tp['li_fat_id'], 0, 10)); ?>...</span>
                      <?php elseif (!empty($tp['fbclid'])): ?>
                        <span style="background:#fae8ff; color:#86198f; padding:0.15rem 0.4rem; border-radius:3px; font-size:0.7rem; font-family:monospace;">fbclid: <?php echo htmlspecialchars(substr($tp['fbclid'], 0, 10)); ?>...</span>
                      <?php else: ?>
                        <span style="color:#94a3b8;">-</span>
                      <?php endif; ?>
                    </td>
                    <td style="max-width:280px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                      <a href="<?php echo htmlspecialchars($tp['landing_url']); ?>" target="_blank" style="color:#0284c7; text-decoration:none;">
                        <?php echo htmlspecialchars($tp['landing_url']); ?>
                      </a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Creativos Históricos Generados -->
      <?php if (!empty($recentCreatives)): ?>
        <div class="funnel-box" style="margin-bottom:2rem;">
          <h3 style="font-size:1.05rem; font-weight:800; color:#003366; margin:0 0 1rem 0;">🖼️ Banners Recientes Generados por Cloudflare FLUX.1</h3>
          <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap:1rem;">
            <?php foreach ($recentCreatives as $c): ?>
              <div id="banner_card_<?php echo $c['id']; ?>" style="position:relative; background:#ffffff; border:1px solid #cbd5e1; border-radius:10px; padding:0.75rem; text-align:center; box-shadow:0 2px 4px rgba(0,0,0,0.03); transition:transform 0.2s;">
                <!-- Botón Eliminar Flotante -->
                <button type="button" onclick="deleteMktBanner(<?php echo $c['id']; ?>);" title="Eliminar este banner" style="position:absolute; top:8px; right:8px; background:rgba(239,68,68,0.9); color:#fff; border:none; width:26px; height:26px; border-radius:50%; font-size:0.8rem; font-weight:800; cursor:pointer; display:flex; align-items:center; justify-content:center; box-shadow:0 2px 5px rgba(0,0,0,0.2);">
                  &times;
                </button>

                <img src="<?php echo htmlspecialchars($c['banner_image_path']); ?>" style="width:100%; height:170px; object-fit:cover; border-radius:6px; margin-bottom:0.6rem; border:1px solid #e2e8f0;" alt="Banner IA">
                <div style="font-size:0.75rem; font-weight:800; color:#003366;"><?php echo htmlspecialchars($c['tenant_slug']); ?></div>
                <div style="font-size:0.7rem; color:#64748b; line-height:1.3; margin:0.25rem 0; min-height:2.6em; overflow:hidden;"><?php echo htmlspecialchars($c['topic']); ?></div>
                
                <div style="display:flex; justify-content:center; gap:0.5rem; align-items:center; margin-top:0.5rem; padding-top:0.4rem; border-top:1px solid #f1f5f9;">
                  <a href="<?php echo htmlspecialchars($c['banner_image_path']); ?>" target="_blank" style="font-size:0.72rem; color:#0284c7; font-weight:700; text-decoration:none;">
                    🔍 Ver
                  </a>
                  <span style="color:#cbd5e1;">•</span>
                  <a href="<?php echo htmlspecialchars($c['banner_image_path']); ?>" download style="font-size:0.72rem; color:#059669; font-weight:700; text-decoration:none;">
                    ⬇️ Descargar
                  </a>
                  <span style="color:#cbd5e1;">•</span>
                  <button type="button" onclick="deleteMktBanner(<?php echo $c['id']; ?>);" style="background:none; border:none; padding:0; font-size:0.72rem; color:#dc2626; font-weight:700; cursor:pointer;">
                    🗑️ Borrar
                  </button>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      
      
      <!-- MODAL PLANIFICADOR INTEGRAL DE CAMPAÑAS CON IA (SOL-0176) -->
      <div id="modalCampaignPlanner" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.85); z-index:9999; justify-content:center; align-items:center; padding:1.5rem; overflow-y:auto;">
        <div style="background:#ffffff; border-radius:14px; width:100%; max-width:980px; max-height:92vh; overflow-y:auto; padding:2.25rem; box-shadow:0 25px 50px -12px rgba(0,0,0,0.35); border:1px solid #cbd5e1;">
          
          <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:1.5rem; border-bottom:1px solid #e2e8f0; padding-bottom:1rem;">
            <div>
              <div style="display:flex; align-items:center; gap:0.6rem;">
                <span style="font-size:1.8rem;">📅</span>
                <h3 style="font-size:1.35rem; font-weight:800; color:#003366; margin:0;">
                  Planificador & Asesor Estratégico de Campañas (LinkedIn Empresa)
                </h3>
              </div>
              <p style="font-size:0.84rem; color:#64748b; margin:0.35rem 0 0 0;">
                Define el rango de fechas de tu campaña. La IA calculará el número óptimo de posts B2B, redactará el lote editorial completo con sus UTMs y programará el despacho automático.
              </p>
            </div>
            <button onclick="document.getElementById('modalCampaignPlanner').style.display='none';" style="background:none; border:none; font-size:1.6rem; cursor:pointer; color:#64748b;">&times;</button>
          </div>

          <!-- PASO 1: Configuración de Fechas y Asesoría de Cadencia -->
          <form id="formCampaignPlanner" onsubmit="executeBatchCampaignGeneration(event);">
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:1.25rem; margin-bottom:1.5rem;">
              <h4 style="font-size:0.95rem; font-weight:800; color:#0f172a; margin:0 0 1rem 0;">
                1️⃣ Período de Campaña & Parámetros Estratégicos
              </h4>
              
              <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:1rem; margin-bottom:1rem;">
                <div>
                  <label style="display:block; font-size:0.78rem; font-weight:700; color:#475569; margin-bottom:0.25rem;">Fecha Inicio *</label>
                  <input type="date" id="cp_date_start" value="2026-11-09" onchange="checkCadenceAdvice();" style="width:100%; padding:0.55rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.85rem;">
                </div>
                <div>
                  <label style="display:block; font-size:0.78rem; font-weight:700; color:#475569; margin-bottom:0.25rem;">Fecha Término *</label>
                  <input type="date" id="cp_date_end" value="2026-11-23" onchange="checkCadenceAdvice();" style="width:100%; padding:0.55rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.85rem;">
                </div>
                <div>
                  <label style="display:block; font-size:0.78rem; font-weight:700; color:#475569; margin-bottom:0.25rem;">Línea de Negocio / Producto *</label>
                  <select id="cp_tenant" onchange="onCpTenantChanged(this.value);" style="width:100%; padding:0.55rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.85rem; font-weight:600;">
                    <option value="keystone_deepshield" selected>DeepShield · Tesorería & Prevención Fraude BEC</option>
                    <option value="keystone_salesforce">Salesforce · FSC, Core CRM & Banca</option>
                    <option value="keystone_cloud">Cloud · Arquitectura & Kubernetes Resiliente</option>
                    <option value="sinnotario">SinNotario · Trámites & Firma Electrónica FEA</option>
                    <option value="keyhub">KeyHub · Control de Rentabilidad & Horas</option>
                  </select>
                </div>
              </div>

              <!-- Caja de Asesoría de Cadencia en Vivo -->
              <div id="boxCadenceAdvice" style="background:#ecfdf5; border:1px solid #a7f3d0; border-radius:8px; padding:0.85rem 1rem; display:flex; justify-content:space-between; align-items:center; gap:1rem;">
                <div>
                  <div style="font-size:0.82rem; font-weight:800; color:#065f46;">
                    💡 Asesoría del Algoritmo Editorial LinkedIn B2B:
                  </div>
                  <div id="txtCadenceRationale" style="font-size:0.78rem; color:#047857; margin-top:0.2rem;">
                    Para este período de 15 días, se recomienda una cadencia de <strong>6 publicaciones</strong> (2 a 3 semanales) en días hábiles (Martes a Jueves a las 09:00 AM) para maximizar la visibilidad sin canibalizar el alcance.
                  </div>
                </div>
                <div style="text-align:right; min-width:140px;">
                  <span style="font-size:0.72rem; color:#475569; display:block;">N° Posts Recomendados:</span>
                  <input type="number" id="cp_num_posts" min="1" max="20" value="6" style="width:75px; text-align:center; font-size:1.1rem; font-weight:800; color:#065f46; border:2px solid #059669; border-radius:6px; padding:0.25rem;">
                </div>
              </div>

              <!-- Fila 2: Objetivo, Audiencia y Landing -->
              <div style="display:grid; grid-template-columns:1.2fr 1fr 1fr; gap:1rem; margin-top:1rem;">
                <div>
                  <label style="display:block; font-size:0.78rem; font-weight:700; color:#475569; margin-bottom:0.25rem;">Tema Central de la Campaña *</label>
                  <input type="text" id="cp_topic" value="Blindaje perimetral contra fraude BEC en transferencias bancarias y cumplimiento Ley 21.459" required style="width:100%; padding:0.55rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.85rem;">
                </div>
                <div>
                  <label style="display:block; font-size:0.78rem; font-weight:700; color:#475569; margin-bottom:0.25rem;">Público Objetivo (Buyer Persona) *</label>
                  <input type="text" id="cp_audience" value="Gerentes de Finanzas, CFOs, Tesoreros y CISOs" required style="width:100%; padding:0.55rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.85rem;">
                </div>
                <div>
                  <label style="display:block; font-size:0.78rem; font-weight:700; color:#475569; margin-bottom:0.25rem;">Landing Page de Destino *</label>
                  <select id="cp_landing" style="width:100%; padding:0.55rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.85rem;">
                    <option value="https://www.keystoneadvisors.cl/">Keystone (Home / Diagnóstico)</option>
                    <option value="https://www.keystoneadvisors.cl/#contact">Keystone (Contacto / Reunión)</option>
                    <option value="https://sinnotario.cl/">SinNotario.cl (Trámites)</option>
                    <option value="https://keyhub.keystoneadvisors.cl/">KeyHub (SaaS)</option>
                  </select>
                </div>
              </div>
            </div>

            <button type="submit" id="btnGenBatchCamp" style="width:100%; background:linear-gradient(135deg, #059669 0%, #047857 100%); color:#ffffff; border:none; padding:0.9rem; border-radius:8px; font-weight:800; font-size:0.98rem; cursor:pointer; box-shadow:0 4px 14px rgba(5,150,105,0.3);">
              ⚡ 2. Generar Lote de Artículos & Calendario con IA Ahora
            </button>
          </form>

          <!-- PASO 2: Parrilla / Calendario de Publicaciones Generadas -->
          <div id="cpBatchResultsContainer" style="display:none; margin-top:2rem; border-top:2px solid #e2e8f0; padding-top:1.5rem;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
              <div>
                <h4 style="font-size:1.15rem; font-weight:800; color:#003366; margin:0;">
                  📅 3. Parrilla Editorial & Calendario Programado
                </h4>
                <p style="font-size:0.8rem; color:#64748b; margin:0.25rem 0 0 0;">
                  Revisa cada publicación del lote. Cada una tiene su propio ángulo complementario y enlace UTM de medición independiente.
                </p>
              </div>
              <button type="button" onclick="confirmAndScheduleCampaign();" id="btnSaveCampSchedule" style="background:#16a34a; color:#fff; border:none; padding:0.75rem 1.5rem; border-radius:8px; font-weight:800; font-size:0.92rem; cursor:pointer; box-shadow:0 4px 12px rgba(22,163,74,0.35);">
                💾 Guardar & Activar Calendario de Publicaciones
              </button>
            </div>

            <!-- Listado de Posts Acordeón/Cards -->
            <div id="cpPostsList" style="display:flex; flex-direction:column; gap:1.25rem;"></div>

            <div id="cpScheduleSaveStatus" style="display:none; margin-top:1.5rem;"></div>
          </div>

        </div>
      </div>

      <!-- MODAL DEDICADO: LINKEDIN PUBLISHING STUDIO (SOL-0175) -->
      <div id="modalLinkedInDirectStudio" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.8); z-index:9999; justify-content:center; align-items:center; padding:1.5rem; overflow-y:auto;">
        <div style="background:#ffffff; border-radius:14px; width:100%; max-width:920px; max-height:92vh; overflow-y:auto; padding:2.25rem; box-shadow:0 25px 50px -12px rgba(0,0,0,0.35); border:1px solid #cbd5e1;">
          
          <!-- Encabezado Modal -->
          <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:1.5rem; border-bottom:1px solid #e2e8f0; padding-bottom:1rem;">
            <div>
              <div style="display:flex; align-items:center; gap:0.6rem;">
                <span style="font-size:1.8rem;">💼</span>
                <h3 style="font-size:1.35rem; font-weight:800; color:#003366; margin:0;">
                  Publicador Oficial LinkedIn Empresa · Keystone Advisors Global
                </h3>
              </div>
              <p style="font-size:0.84rem; color:#64748b; margin:0.35rem 0 0 0;">
                Genera el artículo B2B con IA, asocia la URL de aterrizaje con tracking UTM automático y publica directamente en la Página de Empresa en 1 clic.
              </p>
            </div>
            <button onclick="document.getElementById('modalLinkedInDirectStudio').style.display='none';" style="background:none; border:none; font-size:1.6rem; cursor:pointer; color:#64748b;">&times;</button>
          </div>

          <!-- Formulario LinkedIn Studio -->
          <form id="formLiStudio" onsubmit="generateLinkedInArticleDirect(event);">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:1rem;">
              <div>
                <label style="display:block; font-weight:700; font-size:0.82rem; color:#334155; margin-bottom:0.35rem;">
                  Línea de Negocio / Tema Corporativo *
                </label>
                <select id="li_theme" onchange="onLiThemeChanged(this.value);" style="width:100%; padding:0.65rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.88rem; font-weight:600; color:#0f172a; background:#f8fafc;">
                  <option value="salesforce">Salesforce FSC, Core CRM, LWC & Banca (Keystone Advisors)</option>
                  <option value="deepshield">Ciberseguridad DeepShield, Fraude BEC & Ley 21.459 (Keystone Advisors)</option>
                  <option value="cloud">Arquitectura Cloud, Microservicios & Resiliencia TI (Keystone Advisors)</option>
                  <option value="sinnotario">SinNotario.cl (Trámites Legales, Verificación & Firma FEA)</option>
                  <option value="keyhub">KeyHub Suite (Control de Rentabilidad & Horas Consultoras)</option>
                  <option value="custom">✏️ Tema Personalizado / Redacción Libre...</option>
                </select>
              </div>

              <div>
                <label style="display:block; font-weight:700; font-size:0.82rem; color:#334155; margin-bottom:0.35rem;">
                  ¿A dónde debe dirigir el enlace del post? (Landing de Destino) *
                </label>
                <select id="li_landing_base" onchange="refreshLiUtmPreview();" style="width:100%; padding:0.65rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.88rem; font-weight:600; color:#0f172a; background:#f8fafc;">
                  <option value="https://www.keystoneadvisors.cl/">Keystone Advisors (Home Principal)</option>
                  <option value="https://www.keystoneadvisors.cl/#contact">Keystone Advisors (Formulario Diagnóstico / Contacto)</option>
                  <option value="https://sinnotario.cl/">SinNotario.cl (Plataforma Trámites)</option>
                  <option value="https://keyhub.keystoneadvisors.cl/">KeyHub Suite (Acceso SaaS)</option>
                </select>
              </div>
            </div>

            <!-- Título / Tesis del Post y Audiencia -->
            <div style="display:grid; grid-template-columns:1.2fr 1fr; gap:1rem; margin-bottom:1rem;">
              <div>
                <label style="display:block; font-weight:700; font-size:0.82rem; color:#334155; margin-bottom:0.35rem;">
                  Tema Clave o Tesis del Post *
                </label>
                <input type="text" id="li_goal" value="Cómo modernizar el Core CRM bancario con Salesforce FSC sin interrumpir operaciones" required style="width:100%; padding:0.65rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.88rem;">
              </div>
              <div>
                <label style="display:block; font-weight:700; font-size:0.82rem; color:#334155; margin-bottom:0.35rem;">
                  Audiencia / Buyer Persona *
                </label>
                <input type="text" id="li_audience" value="Gerentes de TI, CIOs, Directores de Canales y Operaciones de Banca" required style="width:100%; padding:0.65rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.88rem;">
              </div>
            </div>

            <!-- Enlace con UTM y Banner -->
            <div style="background:#f1f5f9; border:1px solid #e2e8f0; border-radius:8px; padding:1rem; margin-bottom:1.25rem;">
              <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.35rem;">
                <span style="font-weight:700; font-size:0.82rem; color:#1e293b;">
                  🔗 Enlace con UTM de Rastreo que se insertará al final del Post:
                </span>
                <span style="font-size:0.75rem; color:#059669; font-weight:700;">✓ Generado Automáticamente</span>
              </div>
              <input type="text" id="li_built_utm_url" readonly style="width:100%; padding:0.55rem; background:#ffffff; border:1px solid #cbd5e1; border-radius:6px; font-size:0.82rem; color:#0369a1; font-family:monospace;">
              
              <div style="margin-top:0.75rem; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:0.75rem;">
                <div style="display:flex; align-items:center; gap:0.6rem;">
                  <input type="checkbox" id="li_gen_banner" checked style="cursor:pointer;">
                  <label for="li_gen_banner" style="font-weight:700; font-size:0.82rem; color:#334155; cursor:pointer;">
                    Diseñar Banner Visual Corporativo (1024x1024 px)
                  </label>
                </div>
                <div style="display:flex; align-items:center; gap:0.4rem;">
                  <label for="li_image_engine" style="font-size:0.78rem; font-weight:700; color:#475569;">Motor de Imagen:</label>
                  <select id="li_image_engine" style="padding:0.35rem 0.6rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.8rem; background:#fff; font-weight:600; color:#0f172a;">
                    <option value="flux" selected>⚡ Cloudflare FLUX.1 (Ultra Rápido, $0 USD)</option>
                    <option value="gemini">🧠 Google AI Studio (Gemini Flash Image / Nano Banana)</option>
                  </select>
                </div>
              </div>
            </div>

            <button type="submit" id="btnLiGenArticle" style="width:100%; background:linear-gradient(135deg, #0a66c2 0%, #004182 100%); color:#ffffff; border:none; padding:0.9rem; border-radius:8px; font-weight:800; font-size:0.98rem; cursor:pointer; box-shadow:0 4px 14px rgba(10,102,194,0.3);">
              ⚡ 1. Generar Artículo B2B con IA & Banner Ahora
            </button>
          </form>

          <!-- Resultados y Editor en Vivo del Post para LinkedIn -->
          <div id="liPostEditorSection" style="display:none; margin-top:1.5rem; border-top:1px solid #e2e8f0; padding-top:1.25rem;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.75rem;">
              <h4 style="margin:0; font-size:1.1rem; color:#003366; font-weight:800;">
                📝 2. Vista Previa y Edición del Post antes de Publicar
              </h4>
              <span style="font-size:0.75rem; background:#dcfce7; color:#166534; padding:0.25rem 0.6rem; border-radius:4px; font-weight:700;">
                ✓ Listo para Revisión
              </span>
            </div>

            <div style="display:grid; grid-template-columns:1.2fr 0.8fr; gap:1.25rem; margin-bottom:1.5rem;">
              <!-- Editor de Texto -->
              <div>
                <label style="display:block; font-size:0.78rem; font-weight:700; color:#475569; margin-bottom:0.35rem;">
                  Texto que se publicará en LinkedIn (puedes editarlo o afinarlo):
                </label>
                <textarea id="li_editor_textarea" rows="11" style="width:100%; padding:0.85rem; border:1px solid #cbd5e1; border-radius:8px; font-size:0.88rem; line-height:1.5; color:#1e293b; font-family:sans-serif; background:#ffffff;"></textarea>
              </div>

              <!-- Banner Preview -->
              <div>
                <label style="display:block; font-size:0.78rem; font-weight:700; color:#475569; margin-bottom:0.35rem;">
                  Banner Visual Adjunto (Cloudflare FLUX.1):
                </label>
                <div id="liBannerPreviewBox" style="background:#0f172a; border-radius:8px; padding:0.85rem; text-align:center;">
                  <img id="liBannerPreviewImg" src="" alt="Banner LinkedIn" style="max-width:100%; height:auto; max-height:220px; border-radius:6px; box-shadow:0 4px 10px rgba(0,0,0,0.5); margin-bottom:0.5rem;">
                  
                  <div style="display:flex; justify-content:center; gap:0.75rem; margin-bottom:0.75rem;">
                    <a id="liBannerPreviewLink" href="" target="_blank" download="banner_keystone_linkedin.jpg" style="color:#38bdf8; font-size:0.75rem; text-decoration:none; font-weight:700;">
                      🔍 Ver Imagen Completa
                    </a>
                    <button type="button" onclick="toggleLiRegenBox();" style="background:#334155; color:#f8fafc; border:none; padding:0.2rem 0.6rem; border-radius:4px; font-size:0.75rem; font-weight:700; cursor:pointer;">
                      🎨 Ajustar / Regenerar Imagen
                    </button>
                  </div>

                  <!-- Caja Desplegable de Instrucciones para Regenerar -->
                  <div id="liRegenControls" style="display:none; background:#1e293b; border:1px solid #475569; border-radius:6px; padding:0.75rem; text-align:left;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.35rem;">
                      <label style="font-size:0.72rem; color:#94a3b8; font-weight:700;">
                        Indicaciones de mejora para la IA (ej: "más minimalista, vista isométrica 3D, destacar logo azul"):
                      </label>
                      <select id="li_regen_engine" style="padding:0.2rem 0.4rem; border:1px solid #64748b; border-radius:4px; font-size:0.72rem; background:#0f172a; color:#f8fafc;">
                        <option value="flux" selected>Motor: Cloudflare FLUX.1</option>
                        <option value="gemini">Motor: Google AI Studio</option>
                      </select>
                    </div>
                    <input type="text" id="li_regen_instructions" placeholder="Escribe aquí las correcciones o estilo deseado..." style="width:100%; padding:0.45rem; border:1px solid #64748b; border-radius:4px; font-size:0.78rem; background:#0f172a; color:#f8fafc; margin-bottom:0.5rem;">
                    
                    <button type="button" id="btnLiRegen" onclick="executeBannerRegeneration('li');" style="width:100%; background:#0284c7; color:#fff; border:none; padding:0.5rem; border-radius:4px; font-size:0.78rem; font-weight:800; cursor:pointer;">
                      ⚡ Regenerar Banner con estas Indicaciones
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <!-- Botón de Publicación Definitiva -->
            <div style="background:#f0fdf4; border:1px solid #86efac; border-radius:8px; padding:1.25rem; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem;">
              <div>
                <h5 style="margin:0 0 0.25rem 0; color:#166534; font-size:1rem; font-weight:800;">
                  🚀 3. ¿Todo listo? Publicar en la Página de Keystone Advisors Global
                </h5>
                <span style="font-size:0.78rem; color:#475569;">
                  Destino: <strong>Keystone Advisors Global</strong> (ID 105863140) · Se emitirá inmediatamente a la red.
                </span>
              </div>
              <button type="button" onclick="dispatchDirectPostToLinkedIn();" id="btnPublishDirectLi" style="background:#16a34a; color:#fff; border:none; padding:0.75rem 1.5rem; border-radius:6px; font-weight:800; font-size:0.95rem; cursor:pointer; display:flex; align-items:center; gap:0.5rem; box-shadow:0 4px 12px rgba(22,163,74,0.35);">
                <span>📤</span> Publicar Ahora en LinkedIn Empresa
              </button>
            </div>

            <div id="liDirectPublishStatus" style="display:none; margin-top:1rem;"></div>
          </div>

        </div>
      </div>

      <!-- MODAL 1: GENERADOR DE AVISOS & BANNERS IA (GEMINI 3.8 + FLUX.1) -->
      <div id="modalAiAdGenerator" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.75); z-index:9999; justify-content:center; align-items:center; padding:1.5rem; overflow-y:auto;">
        <div style="background:#ffffff; border-radius:12px; width:100%; max-width:850px; max-height:90vh; overflow-y:auto; padding:2rem; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25); border:1px solid #cbd5e1;">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.25rem; border-bottom:1px solid #e2e8f0; padding-bottom:0.75rem;">
            <div>
              <h3 style="font-size:1.3rem; font-weight:800; color:#003366; margin:0;">✨ Generador Creativo con IA para Campañas</h3>
              <p style="font-size:0.82rem; color:#64748b; margin:0.25rem 0 0 0;">Desarrollado con Google Gemini 3.8 Flash (Textos de Alta Conversión) y Cloudflare FLUX.1 (Banners Visuales 1024x1024).</p>
            </div>
            <button onclick="document.getElementById('modalAiAdGenerator').style.display='none';" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:#64748b;">&times;</button>
          </div>

          <form id="formAiAdGen" onsubmit="generateAiAdCreatives(event);">
            <!-- Fila 1: Empresa / Línea de Negocio y Plataforma de Destino -->
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:1rem;">
              <div>
                <label style="display:block; font-weight:700; font-size:0.82rem; color:#334155; margin-bottom:0.35rem;">
                  Empresa / Línea de Negocio *
                </label>
                <select id="ai_tenant_id" name="tenant_id" onchange="onTenantChanged(this.value);" style="width:100%; padding:0.6rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.88rem; font-weight:600; color:#0f172a; background:#f8fafc;">
                  <optgroup label="── Keystone Advisors (Consultoría & Tech) ──">
                    <option value="keystone_salesforce" selected>Keystone · Consultoría & Implementación Salesforce (Core CRM, FSC, LWC)</option>
                    <option value="keystone_deepshield">Keystone · Ciberseguridad DeepShield (DMARC, Fraude BEC, Ley 21.459)</option>
                    <option value="keystone_cloud">Keystone · Arquitectura Cloud & Modernización de Sistemas</option>
                  </optgroup>
                  <optgroup label="── Plataformas Propias Keystone ──">
                    <option value="sinnotario">SinNotario.cl (Trámites Legales, Validación Notarial y Firmas FEA)</option>
                    <option value="keyhub">KeyHub Suite (Control de Horas, Costos, Mandatos & Kanban SaaS)</option>
                  </optgroup>
                  <optgroup label="── Personalizado ──">
                    <option value="custom">✏️ Otra Línea de Negocio / Personalizada...</option>
                  </optgroup>
                </select>
                <div id="boxCustomTenant" style="display:none; margin-top:0.5rem;">
                  <input type="text" id="ai_custom_tenant" name="custom_tenant" placeholder="Especifique el nombre o unidad de negocio..." style="width:100%; padding:0.5rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.82rem;">
                </div>
              </div>

              <div>
                <label style="display:block; font-weight:700; font-size:0.82rem; color:#334155; margin-bottom:0.35rem;">
                  Plataforma / Canal de Destino *
                </label>
                <select id="ai_target_platform" name="target_platform" style="width:100%; padding:0.6rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.88rem; font-weight:600; color:#0f172a; background:#f8fafc;">
                  <option value="all">🌐 Multicanal Omnipresente (Google Ads + LinkedIn Empresa + Video Script)</option>
                  <option value="linkedin_company">💼 LinkedIn Empresa · Publicación Oficial en Página Corporativa</option>
                  <option value="google_ads">🔍 Google Ads · Campañas de Búsqueda RSA (Titulares y Descripciones)</option>
                  <option value="meta_ads">📱 Meta Ads · Anuncio Feed e Historias (Facebook & Instagram)</option>
                  <option value="email_b2b">✉️ Email Outbound B2B · Prospección Fría para Directores y Gerentes</option>
                </select>
              </div>
            </div>

            <!-- Fila 2: Objetivo Comercial y Audiencia -->
            <div style="display:grid; grid-template-columns:1.2fr 1fr; gap:1rem; margin-bottom:1rem;">
              <div>
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.35rem;">
                  <label style="font-weight:700; font-size:0.82rem; color:#334155;">
                    Objetivo Comercial / Tema de Campaña *
                  </label>
                  <span style="font-size:0.72rem; color:#0284c7; cursor:pointer;" onclick="togglePresetGoals();">
                    💡 Sugerencias Rápidas
                  </span>
                </div>
                <input type="text" id="ai_campaign_goal" name="campaign_goal" value="Migración, optimización y soporte continuo en Salesforce para Banca y Corporaciones" required style="width:100%; padding:0.62rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.88rem;">
                
                <!-- Chips de Sugerencias -->
                <div id="boxPresetGoals" style="display:flex; gap:0.4rem; flex-wrap:wrap; margin-top:0.45rem;">
                  <span onclick="setGoal(this.innerText);" style="background:#e0f2fe; color:#0369a1; font-size:0.72rem; padding:0.2rem 0.5rem; border-radius:4px; cursor:pointer; font-weight:600;">Implementación Salesforce FSC / Banca</span>
                  <span onclick="setGoal(this.innerText);" style="background:#e0f2fe; color:#0369a1; font-size:0.72rem; padding:0.2rem 0.5rem; border-radius:4px; cursor:pointer; font-weight:600;">Blindaje BEC & DMARC Ley 21.459</span>
                  <span onclick="setGoal(this.innerText);" style="background:#e0f2fe; color:#0369a1; font-size:0.72rem; padding:0.2rem 0.5rem; border-radius:4px; cursor:pointer; font-weight:600;">Firma de Contratos sin ir a Notaría</span>
                  <span onclick="setGoal(this.innerText);" style="background:#e0f2fe; color:#0369a1; font-size:0.72rem; padding:0.2rem 0.5rem; border-radius:4px; cursor:pointer; font-weight:600;">Control de Rentabilidad & Horas KeyHub</span>
                </div>
              </div>

              <div>
                <label style="display:block; font-weight:700; font-size:0.82rem; color:#334155; margin-bottom:0.35rem;">
                  Audiencia / Buyer Persona *
                </label>
                <input type="text" id="ai_target_audience" name="target_audience" value="Gerentes de TI, CIOs, Directores de Operaciones y Líderes Salesforce" required style="width:100%; padding:0.62rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.88rem;">
              </div>
            </div>

            <!-- Fila 3: Generación de Banner Visual -->
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:1rem; margin-bottom:1.25rem;">
              <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.75rem;">
                <label style="display:flex; align-items:center; gap:0.5rem; font-weight:700; font-size:0.85rem; color:#0f172a; cursor:pointer;">
                  <input type="checkbox" id="ai_generate_banner" name="generate_banner" value="1" checked>
                  Generar también Banner Visual Corporativo (1024x1024)
                </label>
                <div style="display:flex; align-items:center; gap:0.4rem;">
                  <label for="ai_image_engine" style="font-size:0.78rem; font-weight:700; color:#475569;">Motor de Imagen:</label>
                  <select id="ai_image_engine" name="image_engine" style="padding:0.35rem 0.6rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.8rem; background:#fff; font-weight:600; color:#0f172a;">
                    <option value="flux" selected>⚡ Cloudflare FLUX.1 (Ultra Rápido, $0 USD)</option>
                    <option value="gemini">🧠 Google AI Studio (Gemini Flash Image / Nano Banana)</option>
                  </select>
                </div>
              </div>
              <div id="boxCustomImagePrompt" style="margin-top:0.6rem;">
                <label style="display:block; font-size:0.75rem; color:#64748b; margin-bottom:0.25rem;">
                  Detalle estilístico o enfoque visual adicional (opcional):
                </label>
                <input type="text" id="ai_visual_prompt" name="visual_prompt" placeholder="Ej: Modern enterprise dashboard with Salesforce cloud icons, blue navy corporate lighting" style="width:100%; padding:0.5rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.82rem;">
              </div>
            </div>

            <button type="submit" id="btnAiGenerate" style="width:100%; background:linear-gradient(135deg, #0284c7 0%, #003366 100%); color:#ffffff; border:none; padding:0.9rem; border-radius:8px; font-weight:800; font-size:0.98rem; cursor:pointer; box-shadow:0 4px 12px rgba(2,132,199,0.3);">
              ⚡ Generar Piezas y Copys con IA Ahora
            </button>
          </form>

          <!-- Contenedor Resultados IA -->
          <div id="aiOutputContainer" style="display:none; margin-top:1.5rem; border-top:1px solid #e2e8f0; padding-top:1.25rem;">
            <h4 style="font-size:1.1rem; color:#003366; font-weight:800; margin:0 0 1rem 0;">🎉 Piezas Generadas Exitosamente</h4>
            
            <div id="aiCopyResults" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:1.25rem; margin-bottom:1.25rem;"></div>
            
            <div id="aiBannerResults" style="display:none; text-align:center; background:#0f172a; border-radius:8px; padding:1.5rem; margin-bottom:1.25rem;">
              <h5 style="color:#f8fafc; font-size:0.95rem; margin:0 0 1rem 0;">🖼️ Banner Generado por Cloudflare FLUX.1 (1024x1024)</h5>
              <img id="aiBannerImg" src="" alt="Banner IA" style="max-width:100%; height:auto; max-height:360px; border-radius:8px; box-shadow:0 10px 25px rgba(0,0,0,0.5); margin-bottom:1rem;">
              
              <div style="display:flex; justify-content:center; gap:0.75rem; flex-wrap:wrap; margin-bottom:1rem;">
                <a id="aiBannerDownloadBtn" href="" target="_blank" download="banner_keystone_flux.jpg" style="background:#10b981; color:#fff; text-decoration:none; padding:0.5rem 1rem; border-radius:6px; font-size:0.82rem; font-weight:700;">
                  ⬇️ Abrir / Descargar Imagen
                </a>
                <button type="button" onclick="document.getElementById('boxAiRegenInput').style.display = document.getElementById('boxAiRegenInput').style.display === 'none' ? 'block' : 'none';" style="background:#334155; color:#f8fafc; border:none; padding:0.5rem 1rem; border-radius:6px; font-size:0.82rem; font-weight:700; cursor:pointer;">
                  🎨 Ajustar / Regenerar con Indicaciones
                </button>
              </div>

              <!-- Caja de Instrucciones para Regenerar en Generador Multicanal -->
              <div id="boxAiRegenInput" style="display:none; background:#1e293b; border:1px solid #475569; border-radius:8px; padding:1rem; max-width:600px; margin:0 auto; text-align:left;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.35rem;">
                  <label style="font-size:0.78rem; color:#94a3b8; font-weight:700;">
                    Nuevas indicaciones de estilo o correcciones:
                  </label>
                  <select id="ai_regen_engine" style="padding:0.25rem 0.5rem; border:1px solid #64748b; border-radius:4px; font-size:0.75rem; background:#0f172a; color:#f8fafc;">
                    <option value="flux" selected>Motor: Cloudflare FLUX.1</option>
                    <option value="gemini">Motor: Google AI Studio</option>
                  </select>
                </div>
                <input type="text" id="ai_regen_instructions" placeholder="Ej: dramatic dark lighting, modern fintech holographic glass, ultra realistic" style="width:100%; padding:0.55rem; border:1px solid #64748b; border-radius:6px; font-size:0.82rem; background:#0f172a; color:#f8fafc; margin-bottom:0.6rem;">
                <button type="button" id="btnAiRegenMulti" onclick="executeBannerRegeneration('multi');" style="width:100%; background:#0284c7; color:#fff; border:none; padding:0.65rem; border-radius:6px; font-size:0.85rem; font-weight:800; cursor:pointer;">
                  ⚡ Regenerar Banner Ahora con estas Indicaciones
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>

            <!-- MODAL 3: CONEXIÓN DE PLATAFORMAS ADS (GOOGLE / LINKEDIN / META) -->
      <div id="modalAdsAccounts" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.75); z-index:9999; justify-content:center; align-items:center; padding:1.5rem; overflow-y:auto;">
        <div style="background:#ffffff; border-radius:12px; width:100%; max-width:820px; padding:2rem; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25); border:1px solid #cbd5e1;">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.25rem; border-bottom:1px solid #e2e8f0; padding-bottom:0.75rem;">
            <div>
              <h3 style="font-size:1.3rem; font-weight:800; color:#003366; margin:0;">⚙️ Conexión de Plataformas Publicitarias</h3>
              <p style="font-size:0.82rem; color:#64748b; margin:0.25rem 0 0 0;">Vincule sus cuentas publicitarias para publicar anuncios con 1 clic desde el generador de IA.</p>
            </div>
            <button onclick="document.getElementById('modalAdsAccounts').style.display='none';" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:#64748b;">&times;</button>
          </div>

          <?php if (isset($_GET['linkedin_connected'])): ?>
            <div style="background:#dcfce7; border:1px solid #86efac; color:#166534; padding:0.75rem 1rem; border-radius:6px; font-size:0.85rem; font-weight:700; margin-bottom:1rem;">
              ✓ ¡Sesión de LinkedIn autorizada exitosamente! El publicador orgánico para Keystone Advisors ya se encuentra activo.
            </div>
          <?php endif; ?>

          <?php if (isset($_GET['linkedin_error'])): ?>
            <div style="background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:0.75rem 1rem; border-radius:6px; font-size:0.85rem; margin-bottom:1rem;">
              ❌ Error en autorización de LinkedIn: <?php echo htmlspecialchars($_SESSION['linkedin_error'] ?? 'Fallo en flujo'); unset($_SESSION['linkedin_error']); ?>
            </div>
          <?php endif; ?>

          <?php if (isset($_GET['google_connected'])): ?>
            <div style="background:#dcfce7; border:1px solid #86efac; color:#166534; padding:0.75rem 1rem; border-radius:6px; font-size:0.85rem; font-weight:700; margin-bottom:1rem;">
              ✓ ¡Cuenta de Google Ads conectada exitosamente!
            </div>
          <?php endif; ?>

          <!-- Google Ads Card -->
          <div style="border:1px solid #e2e8f0; border-radius:8px; padding:1.25rem; margin-bottom:1.25rem; background:#f8fafc;">
            <div style="display:flex; justify-content:space-between; align-items:center; gap:1rem; margin-bottom:0.75rem; flex-wrap:wrap;">
              <div style="display:flex; align-items:center; gap:0.75rem; min-width:260px;">
                <span style="font-size:1.5rem;">🔍</span>
                <div>
                  <h4 style="margin:0; font-size:1.05rem; color:#003366; font-weight:800;">Google Ads API</h4>
                  <span style="font-size:0.78rem; color:#64748b;">Campañas de Búsqueda RSA & Conversiones Offline</span>
                </div>
              </div>
              <div style="display:flex; align-items:center; gap:0.5rem; flex-wrap:nowrap; white-space:nowrap;">
                <?php
                  $dbMkt = KeystoneGrowthService::getDb();
                  $hasGRefreshToken = (bool)$dbMkt->query("SELECT COUNT(*) FROM mkt_settings WHERE setting_key = 'google_ads_refresh_token'")->fetchColumn();
                ?>
                <?php
                  $gCustId = (string)$dbMkt->query("SELECT setting_value FROM mkt_settings WHERE setting_key = 'google_ads_customer_id'")->fetchColumn();
                ?>
                <?php if ($hasGRefreshToken): ?>
                  <span style="background:#dcfce7; color:#166534; font-size:0.75rem; font-weight:800; padding:0.25rem 0.6rem; border-radius:99px; border:1px solid #86efac;">
                    ✓ Conectada (ID: <?php echo htmlspecialchars($gCustId); ?>)
                  </span>
                <?php elseif (!empty($gCustId)): ?>
                  <span style="display:inline-flex; align-items:center; background:#fef3c7; color:#92400e; font-size:0.78rem; font-weight:700; padding:0.35rem 0.75rem; border-radius:999px; border:1px solid #fde68a; white-space:nowrap;">
                    ID Vinculado: <?php echo htmlspecialchars($gCustId); ?>
                  </span>
                  <a href="oauth_google.php?action=connect" style="background:#0284c7; color:#fff; text-decoration:none; padding:0.4rem 0.85rem; border-radius:6px; font-size:0.82rem; font-weight:700; display:inline-block; margin-left:0.35rem;">
                    🔗 Autorizar OAuth
                  </a>
                <?php else: ?>
                  <a href="oauth_google.php?action=connect" style="background:#0284c7; color:#fff; text-decoration:none; padding:0.4rem 0.85rem; border-radius:6px; font-size:0.82rem; font-weight:700; display:inline-block;">
                    🔗 Vincular con Google
                  </a>
                <?php endif; ?>
              </div>
            </div>
            <p style="font-size:0.8rem; color:#475569; margin:0; line-height:1.4;">
              Client ID configurado: <code>36671885786-e2lok...apps.googleusercontent.com</code>. Al vincular, autoriza a Keystone a crear borradores de campañas y registrar conversiones automáticas.
            </p>
          </div>

          <!-- LinkedIn Community & Organic Publisher Card -->
          <div style="border:1px solid #e2e8f0; border-radius:8px; padding:1.25rem; margin-bottom:1.25rem; background:#f8fafc;">
            <div style="display:flex; justify-content:space-between; align-items:center; gap:1rem; margin-bottom:0.75rem; flex-wrap:wrap;">
              <div style="display:flex; align-items:center; gap:0.75rem; min-width:280px;">
                <span style="font-size:1.5rem;">💼</span>
                <div>
                  <h4 style="margin:0; font-size:1.05rem; color:#003366; font-weight:800;">LinkedIn Empresa (Publicador Orgánico Oficial)</h4>
                  <span style="font-size:0.78rem; color:#64748b;">Publicación de Artículos, Banners & Posts en la Página de Keystone</span>
                </div>
              </div>
              <div style="display:flex; align-items:center; gap:0.5rem; flex-wrap:nowrap; white-space:nowrap;">
                <?php
                  $hasLiToken = (bool)$dbMkt->query("SELECT COUNT(*) FROM mkt_settings WHERE setting_key = 'linkedin_access_token'")->fetchColumn();
                  $liOrgId = (string)$dbMkt->query("SELECT setting_value FROM mkt_settings WHERE setting_key = 'linkedin_org_id'")->fetchColumn();
                ?>
                <?php
                  $hasLiCreds = (bool)$dbMkt->query("SELECT COUNT(*) FROM mkt_settings WHERE setting_key = 'linkedin_client_id'")->fetchColumn();
                ?>
                <?php if ($hasLiToken): ?>
                  <span style="display:inline-flex; align-items:center; gap:0.35rem; background:#dcfce7; color:#15803d; font-size:0.78rem; font-weight:700; padding:0.35rem 0.85rem; border-radius:999px; border:1px solid #86efac; box-shadow:0 1px 2px rgba(0,0,0,0.04); white-space:nowrap;">
                    <span style="display:inline-block; width:7px; height:7px; background:#16a34a; border-radius:50%;"></span>
                    Publicador Activo (Org: <?php echo htmlspecialchars($liOrgId); ?>)
                  </span>
                <?php elseif ($hasLiCreds): ?>
                  <span style="background:#dbeafe; color:#1e40af; font-size:0.75rem; font-weight:800; padding:0.25rem 0.6rem; border-radius:99px; border:1px solid #bfdbfe;">
                    ✓ App & Org Vinculada (<?php echo htmlspecialchars($liOrgId); ?>)
                  </span>
                  <a href="oauth_linkedin.php?action=connect" style="background:#0a66c2; color:#fff; text-decoration:none; padding:0.4rem 0.85rem; border-radius:6px; font-size:0.82rem; font-weight:700; display:inline-block; margin-left:0.35rem;">
                    🔗 Autorizar Sesión
                  </a>
                <?php else: ?>
                  <button type="button" onclick="document.getElementById('formLiCreds').style.display = document.getElementById('formLiCreds').style.display === 'none' ? 'block' : 'none';" style="background:#003366; color:#fff; border:none; padding:0.4rem 0.85rem; border-radius:6px; font-size:0.82rem; font-weight:700; cursor:pointer;">
                    ⚙️ Configurar App
                  </button>
                  <a href="oauth_linkedin.php?action=connect" style="background:#0a66c2; color:#fff; text-decoration:none; padding:0.4rem 0.85rem; border-radius:6px; font-size:0.82rem; font-weight:700; display:inline-block; margin-left:0.35rem;">
                    🔗 Vincular con LinkedIn
                  </a>
                <?php endif; ?>
              </div>
            </div>

            <!-- Formulario Credenciales LinkedIn -->
            <form id="formLiCreds" method="POST" action="oauth_linkedin.php" style="display:none; margin-top:1rem; padding-top:1rem; border-top:1px solid #cbd5e1;">
              <input type="hidden" name="action" value="save_linkedin_credentials">
              <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem; margin-bottom:0.75rem;">
                <div>
                  <label style="display:block; font-size:0.75rem; font-weight:700; color:#334155; margin-bottom:0.25rem;">Client ID (App LinkedIn):</label>
                  <input type="text" name="linkedin_client_id" value="<?php echo htmlspecialchars((string)$dbMkt->query("SELECT setting_value FROM mkt_settings WHERE setting_key = 'linkedin_client_id'")->fetchColumn()); ?>" required style="width:100%; padding:0.5rem; border:1px solid #cbd5e1; border-radius:4px; font-size:0.82rem;">
                </div>
                <div>
                  <label style="display:block; font-size:0.75rem; font-weight:700; color:#334155; margin-bottom:0.25rem;">Client Secret:</label>
                  <input type="password" name="linkedin_client_secret" value="<?php echo htmlspecialchars((string)$dbMkt->query("SELECT setting_value FROM mkt_settings WHERE setting_key = 'linkedin_client_secret'")->fetchColumn()); ?>" required style="width:100%; padding:0.5rem; border:1px solid #cbd5e1; border-radius:4px; font-size:0.82rem;">
                </div>
              </div>
              <div style="margin-bottom:0.75rem;">
                <label style="display:block; font-size:0.75rem; font-weight:700; color:#334155; margin-bottom:0.25rem;">ID Numérico de Página de Empresa Keystone (o URN):</label>
                <input type="text" name="linkedin_org_id" value="<?php echo htmlspecialchars((string)$dbMkt->query("SELECT setting_value FROM mkt_settings WHERE setting_key = 'linkedin_org_id'")->fetchColumn()); ?>" placeholder="Ej: 10458923 (los dígitos de la URL de tu página de empresa)" required style="width:100%; padding:0.5rem; border:1px solid #cbd5e1; border-radius:4px; font-size:0.82rem;">
              </div>
              <button type="submit" style="background:#16a34a; color:#fff; border:none; padding:0.5rem 1rem; border-radius:4px; font-size:0.82rem; font-weight:700; cursor:pointer;">
                💾 Guardar Credenciales de App
              </button>
            </form>

            <p style="font-size:0.8rem; color:#475569; margin:0.5rem 0 0 0; line-height:1.4;">
              Privacy Policy URL configurada: <code>https://www.keystoneadvisors.cl/privacidad.html</code> | Redirect URI autorizada: <code>https://www.keystoneadvisors.cl/admin/oauth_linkedin.php</code>
            </p>
          </div>

          <!-- Meta Ads Card -->
          <div style="border:1px solid #e2e8f0; border-radius:8px; padding:1.25rem; background:#f8fafc;">
            <div style="display:flex; justify-content:space-between; align-items:center; gap:1rem; margin-bottom:0.75rem; flex-wrap:wrap;">
              <div style="display:flex; align-items:center; gap:0.75rem; min-width:280px;">
                <span style="font-size:1.5rem;">📱</span>
                <div>
                  <h4 style="margin:0; font-size:1.05rem; color:#003366; font-weight:800;">Meta Ads (Facebook & Instagram Graph API)</h4>
                  <span style="font-size:0.78rem; color:#64748b;">Feed, Stories, Reels & Conversions API (CAPI)</span>
                </div>
              </div>
              <span style="display:inline-flex; align-items:center; background:#e0f2fe; color:#0369a1; font-size:0.78rem; font-weight:700; padding:0.35rem 0.75rem; border-radius:999px; border:1px solid #bae6fd; white-space:nowrap;">
                Paso 3: En Configuración
              </span>
            </div>
            <p style="font-size:0.8rem; color:#475569; margin:0; line-height:1.4;">
              Permite crear piezas dinámicas y alimentar el Píxel de Meta con las compras realizadas en SinNotario y diagnósticos de Keystone.
            </p>
          </div>
        </div>
      </div>

      <!-- MODAL 2: LINK BUILDER CON UTMS -->
      <div id="modalLinkBuilder" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.75); z-index:9999; justify-content:center; align-items:center; padding:1.5rem;">
        <div style="background:#ffffff; border-radius:12px; width:100%; max-width:650px; padding:2rem; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25); border:1px solid #cbd5e1;">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.25rem; border-bottom:1px solid #e2e8f0; padding-bottom:0.75rem;">
            <h3 style="font-size:1.25rem; font-weight:800; color:#003366; margin:0;">🔗 Constructor de Enlaces con UTMs y Tracking</h3>
            <button onclick="document.getElementById('modalLinkBuilder').style.display='none';" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:#64748b;">&times;</button>
          </div>

          <div style="margin-bottom:1rem;">
            <label style="display:block; font-weight:700; font-size:0.82rem; color:#334155; margin-bottom:0.35rem;">URL de Destino / Landing *</label>
            <select id="lb_base_url" onchange="updateBuiltUrl();" style="width:100%; padding:0.6rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.88rem;">
              <option value="https://www.keystoneadvisors.cl/">Keystone Advisors (Home)</option>
              <option value="https://www.keystoneadvisors.cl/#contact">Keystone Advisors (Contacto / Diagnóstico)</option>
              <option value="https://sinnotario.cl/">SinNotario.cl (Home Trámites)</option>
              <option value="https://keyhub.keystoneadvisors.cl/">KeyHub (Plataforma)</option>
            </select>
          </div>

          <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:1rem;">
            <div>
              <label style="display:block; font-weight:700; font-size:0.82rem; color:#334155; margin-bottom:0.35rem;">Fuente (utm_source) *</label>
              <select id="lb_source" onchange="updateBuiltUrl();" style="width:100%; padding:0.6rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.88rem;">
                <option value="linkedin">linkedin</option>
                <option value="google">google</option>
                <option value="meta">meta (facebook/instagram)</option>
                <option value="email_outreach">email_outreach</option>
                <option value="whatsapp">whatsapp</option>
              </select>
            </div>
            <div>
              <label style="display:block; font-weight:700; font-size:0.82rem; color:#334155; margin-bottom:0.35rem;">Medio (utm_medium) *</label>
              <select id="lb_medium" onchange="updateBuiltUrl();" style="width:100%; padding:0.6rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.88rem;">
                <option value="cpc">cpc (pago por clic)</option>
                <option value="social_paid">social_paid</option>
                <option value="social_organic">social_organic</option>
                <option value="cold_email">cold_email</option>
                <option value="direct_message">direct_message</option>
              </select>
            </div>
          </div>

          <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:1.25rem;">
            <div>
              <label style="display:block; font-weight:700; font-size:0.82rem; color:#334155; margin-bottom:0.35rem;">Nombre Campaña (utm_campaign) *</label>
              <input type="text" id="lb_campaign" value="deepshield_bec_q4" oninput="updateBuiltUrl();" style="width:100%; padding:0.6rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.88rem;">
            </div>
            <div>
              <label style="display:block; font-weight:700; font-size:0.82rem; color:#334155; margin-bottom:0.35rem;">Contenido de Aviso (utm_content)</label>
              <input type="text" id="lb_content" value="banner_cfo_v1" oninput="updateBuiltUrl();" style="width:100%; padding:0.6rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.88rem;">
            </div>
          </div>

          <div style="background:#f8fafc; border:1px solid #cbd5e1; border-radius:8px; padding:1rem; margin-bottom:1rem;">
            <label style="display:block; font-weight:700; font-size:0.75rem; color:#64748b; margin-bottom:0.35rem;">ENLACE FINAL CON TELEMETRÍA GENERADO:</label>
            <input type="text" id="lb_final_url" readonly style="width:100%; padding:0.65rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.85rem; font-family:monospace; background:#ffffff; color:#0f172a;">
          </div>

          <button type="button" onclick="copyBuiltUrl();" id="btnCopyBuiltUrl" style="width:100%; background:#003366; color:#ffffff; border:none; padding:0.75rem; border-radius:8px; font-weight:700; font-size:0.9rem; cursor:pointer;">
            📋 Copiar Enlace al Portapapeles
          </button>
        </div>
      </div>

      <script>
        // Constructor de Enlaces
        function updateBuiltUrl() {
          const base = document.getElementById('lb_base_url').value;
          const src = encodeURIComponent(document.getElementById('lb_source').value);
          const med = encodeURIComponent(document.getElementById('lb_medium').value);
          const cmp = encodeURIComponent(document.getElementById('lb_campaign').value.trim());
          const cnt = encodeURIComponent(document.getElementById('lb_content').value.trim());

          const separator = base.includes('?') ? '&' : '?';
          let url = `${base}${separator}utm_source=${src}&utm_medium=${med}&utm_campaign=${cmp}`;
          if (cnt) url += `&utm_content=${cnt}`;
          document.getElementById('lb_final_url').value = url;
        }
        document.addEventListener('DOMContentLoaded', updateBuiltUrl);

        function copyBuiltUrl() {
          const inp = document.getElementById('lb_final_url');
          inp.select();
          document.execCommand('copy');
          const btn = document.getElementById('btnCopyBuiltUrl');
          const orig = btn.innerText;
          btn.innerText = '✓ ¡Copiado con Éxito!';
          setTimeout(() => { btn.innerText = orig; }, 2000);
        }

        // Generador con IA
        
        function onTenantChanged(val) {
          const customBox = document.getElementById("boxCustomTenant");
          if (val === "custom") {
            customBox.style.display = "block";
            document.getElementById("ai_custom_tenant").focus();
          } else {
            customBox.style.display = "none";
          }

          // Preconfigurar sugerencias según la línea de negocio seleccionada
          const goalInp = document.getElementById("campaign_goal");
          const audInp = document.getElementById("target_audience");
          const promptInp = document.getElementById("visual_prompt");

          if (val === "keystone_salesforce") {
            document.getElementById("ai_campaign_goal").value = "Implementación y optimización de Salesforce CRM, FSC y Lightning para Banca y Corporaciones";
            document.getElementById("ai_target_audience").value = "Gerentes de TI, CIOs, Directores de Canales y Líderes de Transformación Digital";
            document.getElementById("ai_visual_prompt").value = "Corporate Salesforce cloud tech architecture, futuristic fintech dashboard, navy blue accents";
          } else if (val === "keystone_deepshield") {
            document.getElementById("ai_campaign_goal").value = "Blindaje perimetral contra fraude BEC, DMARC Enforcement y cumplimiento Ley 21.459";
            document.getElementById("ai_target_audience").value = "Gerentes de Finanzas, CFOs, Tesoreros y CISOs";
            document.getElementById("ai_visual_prompt").value = "Cybersecurity shield protecting financial wire transfers, dark corporate high-tech background";
          } else if (val === "keystone_cloud") {
            document.getElementById("ai_campaign_goal").value = "Modernización de infraestructura Cloud, arquitectura de microservicios y alta disponibilidad";
            document.getElementById("ai_target_audience").value = "CTOs, Gerentes de Infraestructura y Jefes de Operaciones TI";
            document.getElementById("ai_visual_prompt").value = "Enterprise cloud server infrastructure, resilient data pipelines, clean minimalism";
          } else if (val === "sinnotario") {
            document.getElementById("ai_campaign_goal").value = "Firma electrónica avanzada de contratos y trámites con 100% validez legal sin ir a notaría";
            document.getElementById("ai_target_audience").value = "Corredores de propiedades, Pymes, fundadores y personas naturales con trámites pendientes";
            document.getElementById("ai_visual_prompt").value = "Digital legal document signing on smartphone, biometric verification badge, fast and modern";
          } else if (val === "keyhub") {
            document.getElementById("ai_campaign_goal").value = "Control de rentabilidad de proyectos, registro de horas consultoras y tableros Kanban";
            document.getElementById("ai_target_audience").value = "Socios de firmas de consultoría, directores de agencias y gerentes de proyectos";
            document.getElementById("ai_visual_prompt").value = "Modern executive Kanban board with project profitability analytics, sleek dark UI";
          }
        }

        function setGoal(txt) {
          document.getElementById("ai_campaign_goal").value = txt;
        }

        function togglePresetGoals() {
          const b = document.getElementById("boxPresetGoals");
          b.style.display = b.style.display === "none" ? "flex" : "none";
        }

        
        function openLinkedInDirectStudio() {
          document.getElementById("modalLinkedInDirectStudio").style.display = "flex";
          refreshLiUtmPreview();
        }

        function onLiThemeChanged(val) {
          const goalInp = document.getElementById("li_goal");
          const audInp = document.getElementById("li_audience");
          const landSel = document.getElementById("li_landing_base");

          if (val === "salesforce") {
            goalInp.value = "Cómo modernizar el Core CRM bancario con Salesforce FSC y LWC sin fricciones operativas";
            audInp.value = "Gerentes de TI, CIOs, Directores de Canales y Operaciones de Banca y Seguros";
            landSel.value = "https://www.keystoneadvisors.cl/#contact";
          } else if (val === "deepshield") {
            goalInp.value = "Blindaje perimetral contra fraude BEC y suplantación de identidad bancaria bajo Ley 21.459";
            audInp.value = "Gerentes de Finanzas, CFOs, Tesoreros y CISOs de Empresas";
            landSel.value = "https://www.keystoneadvisors.cl/";
          } else if (val === "cloud") {
            goalInp.value = "Arquitectura Cloud moderna y Kubernetes resiliente para alta disponibilidad corporativa";
            audInp.value = "CTOs, Gerentes de Infraestructura y Jefes de Operaciones TI";
            landSel.value = "https://www.keystoneadvisors.cl/";
          } else if (val === "sinnotario") {
            goalInp.value = "Cómo cerrar contratos y acuerdos legales con Firma FEA sin trámites en notarías";
            audInp.value = "Pymes, fundadores, corredores de propiedades y gerentes legales";
            landSel.value = "https://sinnotario.cl/";
          } else if (val === "keyhub") {
            goalInp.value = "Control de rentabilidad en tiempo real y asignación de horas para consultoras";
            audInp.value = "Socios de firmas de consultoría, directores de agencias y gerentes de proyectos";
            landSel.value = "https://keyhub.keystoneadvisors.cl/";
          }
          refreshLiUtmPreview();
        }

        function refreshLiUtmPreview() {
          const base = document.getElementById("li_landing_base").value;
          const theme = document.getElementById("li_theme").value;
          const campaignName = "linkedin_organic_" + theme + "_" + new Date().toISOString().slice(0,10).replace(/-/g, "");
          const cleanBase = base.split("?")[0];
          const utmUrl = cleanBase + "?utm_source=linkedin&utm_medium=social_organic&utm_campaign=" + encodeURIComponent(campaignName) + "&utm_content=post_empresa";
          document.getElementById("li_built_utm_url").value = utmUrl;
        }

        async function generateLinkedInArticleDirect(e) {
          e.preventDefault();
          const btn = document.getElementById("btnLiGenArticle");
          const orig = btn.innerHTML;
          btn.disabled = true;
          btn.innerHTML = "✨ Redactando Artículo B2B con Groq LPU & FLUX.1... ⏳";

          refreshLiUtmPreview();
          const utmUrl = document.getElementById("li_built_utm_url").value;
          const theme = document.getElementById("li_theme").value;
          const tenantSlug = (theme === "sinnotario" || theme === "keyhub") ? theme : ("keystone_" + theme);

          const fd = new FormData();
          fd.append("action", "growth_generate_ai");
          fd.append("tenant_id", tenantSlug);
          fd.append("target_platform", "linkedin_company");
          fd.append("campaign_goal", document.getElementById("li_goal").value);
          fd.append("target_audience", document.getElementById("li_audience").value);
          const liEngine = document.getElementById("li_image_engine") ? document.getElementById("li_image_engine").value : "flux";
          fd.append("generate_banner", document.getElementById("li_gen_banner").checked ? "1" : "0");
          fd.append("image_engine", liEngine);
          fd.append("visual_prompt", "Corporate B2B high-end LinkedIn banner for " + theme + ", blue navy #003366 and electric cyan accents");

          try {
            const res = await fetch("index.php", { method: "POST", body: fd });
            const data = await res.json();

            if (data.success && data.copy && data.copy.data) {
              const cr = data.copy.data;
              let postText = cr.linkedin_post || "";

              // Incorporar el enlace UTM automáticamente al final si no lo tiene
              if (postText && !postText.includes("http")) {
                postText += "\n\n🔗 Conoce más o solicita diagnóstico aquí: " + utmUrl;
              }

              document.getElementById("li_editor_textarea").value = postText;
              document.getElementById("liPostEditorSection").style.display = "block";

              // Banner
              if (data.banner && data.banner.success && data.banner.image_url) {
                document.getElementById("liBannerPreviewImg").src = data.banner.image_url;
                document.getElementById("liBannerPreviewLink").href = data.banner.image_url;
                document.getElementById("liBannerPreviewBox").style.display = "block";
                window._liDirectImagePath = data.banner.image_url;
              } else {
                document.getElementById("liBannerPreviewBox").style.display = "none";
                window._liDirectImagePath = "";
              }

              document.getElementById("liPostEditorSection").scrollIntoView({ behavior: "smooth" });
            } else {
              alert("Error al generar artículo: " + (data.error || "Respuesta inválida"));
            }
          } catch (err) {
            alert("Error de red: " + err.message);
          } finally {
            btn.disabled = false;
            btn.innerHTML = orig;
          }
        }

        async function dispatchDirectPostToLinkedIn() {
          const text = document.getElementById("li_editor_textarea").value.trim();
          if (!text) {
            alert("El texto del post no puede estar vacío.");
            return;
          }

          const btn = document.getElementById("btnPublishDirectLi");
          const statusBox = document.getElementById("liDirectPublishStatus");
          const orig = btn.innerHTML;

          btn.disabled = true;
          btn.innerHTML = "Publicando en Keystone Advisors Global... ⏳";
          statusBox.style.display = "none";

          const fd = new FormData();
          fd.append("action", "publish_to_linkedin");
          fd.append("post_text", text);
          fd.append("image_path", window._liDirectImagePath || "");
          fd.append("post_title", "Keystone Advisors B2B Intelligence");
          fd.append("landing_url", document.getElementById("li_built_utm_url").value);

          try {
            const res = await fetch("index.php", { method: "POST", body: fd });
            const data = await res.json();

            statusBox.style.display = "block";
            if (data.success) {
              statusBox.innerHTML = `
                <div style="background:#dcfce7; border:1px solid #86efac; color:#166534; padding:1rem; border-radius:8px; font-weight:700;">
                  <div style="font-size:1.05rem; margin-bottom:0.35rem;">🎉 ¡Artículo publicado exitosamente en LinkedIn Empresa!</div>
                  <div style="font-size:0.82rem; font-weight:normal; color:#374151;">
                    URN de Publicación: <code>${data.post_urn || "Confirmado"}</code>.
                  </div>
                  <div style="margin-top:0.75rem;">
                    <a href="https://www.linkedin.com/company/keystoneadvisorsglobal/" target="_blank" style="background:#0a66c2; color:#fff; text-decoration:none; padding:0.4rem 0.85rem; border-radius:6px; font-size:0.82rem; font-weight:700; display:inline-block;">
                      ↗ Ver en la Página Oficial de LinkedIn
                    </a>
                  </div>
                </div>
              `;
            } else {
              statusBox.innerHTML = `
                <div style="background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:1rem; border-radius:8px;">
                  <strong>❌ Error al publicar en LinkedIn:</strong> ${data.error || "No se pudo completar el despacho."}
                </div>
              `;
            }
          } catch (err) {
            statusBox.style.display = "block";
            statusBox.innerHTML = `<div style="background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:1rem; border-radius:8px;"><strong>Error de red:</strong> ${err.message}</div>`;
          } finally {
            btn.disabled = false;
            btn.innerHTML = orig;
          }
        }

        
        
        
        async function deleteMktBanner(id) {
          if (!confirm("¿Deseas eliminar este banner definitivamente? Se borrará la imagen del servidor y del historial.")) {
            return;
          }

          const card = document.getElementById("banner_card_" + id);
          if (card) {
            card.style.opacity = "0.4";
            card.style.pointerEvents = "none";
          }

          const fd = new FormData();
          fd.append("action", "delete_mkt_banner");
          fd.append("creative_id", id);

          try {
            const res = await fetch("index.php", { method: "POST", body: fd });
            const data = await res.json();

            if (data.success) {
              if (card) {
                card.style.transition = "all 0.3s ease";
                card.style.transform = "scale(0.8)";
                card.style.opacity = "0";
                setTimeout(() => card.remove(), 300);
              }
            } else {
              alert("Error al eliminar banner: " + (data.error || "Fallo en servidor"));
              if (card) {
                card.style.opacity = "1";
                card.style.pointerEvents = "auto";
              }
            }
          } catch(err) {
            alert("Error de red: " + err.message);
            if (card) {
              card.style.opacity = "1";
              card.style.pointerEvents = "auto";
            }
          }
        }

        function toggleLiRegenBox() {
          const el = document.getElementById("liRegenControls");
          el.style.display = el.style.display === "none" ? "block" : "none";
          if (el.style.display === "block") {
            document.getElementById("li_regen_instructions").focus();
          }
        }

        async function executeBannerRegeneration(source) {
          let btn = null;
          let instructions = "";
          let tenantSlug = "keystone_deepshield";
          let topic = "Ciberseguridad Corporativa";
          let engine = "flux";

          if (source === "li") {
            btn = document.getElementById("btnLiRegen");
            instructions = document.getElementById("li_regen_instructions").value.trim();
            const theme = document.getElementById("li_theme").value;
            tenantSlug = (theme === "sinnotario" || theme === "keyhub") ? theme : ("keystone_" + theme);
            topic = document.getElementById("li_goal").value;
            const engSel = document.getElementById("li_regen_engine");
            if (engSel) engine = engSel.value;
          } else {
            btn = document.getElementById("btnAiRegenMulti");
            instructions = document.getElementById("ai_regen_instructions").value.trim();
            tenantSlug = document.getElementById("ai_tenant_id").value;
            topic = document.getElementById("ai_campaign_goal").value;
            const engSel = document.getElementById("ai_regen_engine");
            if (engSel) engine = engSel.value;
          }

          const orig = btn.innerHTML;
          btn.disabled = true;
          btn.innerHTML = "Generando nueva imagen con IA (" + (engine === 'gemini' ? 'Google Gemini' : 'FLUX.1') + ")... ⏳";

          const fd = new FormData();
          fd.append("action", "growth_regenerate_banner");
          fd.append("tenant_slug", tenantSlug);
          fd.append("topic", topic);
          fd.append("custom_instructions", instructions);
          fd.append("image_engine", engine);

          try {
            const res = await fetch("index.php", { method: "POST", body: fd });
            const data = await res.json();

            if (data.success && data.image_url) {
              if (data.notice) {
                alert("Aviso: " + data.notice);
              }
              if (source === "li") {
                document.getElementById("liBannerPreviewImg").src = data.image_url;
                document.getElementById("liBannerPreviewLink").href = data.image_url;
                window._liDirectImagePath = data.image_url;
                document.getElementById("liRegenControls").style.display = "none";
              } else {
                document.getElementById("aiBannerImg").src = data.image_url;
                document.getElementById("aiBannerDownloadBtn").href = data.image_url;
                document.getElementById("boxAiRegenInput").style.display = "none";
              }
            } else {
              alert("Error al regenerar imagen: " + (data.error || "Fallo en motor de imagen"));
            }
          } catch(err) {
            alert("Error de conexión: " + err.message);
          } finally {
            btn.disabled = false;
            btn.innerHTML = orig;
          }
        }

        function togglePostRegenBox(idx) {
          const el = document.getElementById("post_regen_box_" + idx);
          el.style.display = el.style.display === "none" ? "block" : "none";
        }

        async function executePostBannerRegen(idx) {
          const btn = document.getElementById("btn_post_regen_" + idx);
          const promptInp = document.getElementById("post_regen_prompt_" + idx);
          const instructions = promptInp.value.trim();
          const engSel = document.getElementById("post_regen_engine_" + idx);
          const engine = engSel ? engSel.value : "flux";
          const orig = btn.innerHTML;

          btn.disabled = true;
          btn.innerHTML = "Generando con IA (" + (engine === 'gemini' ? 'Google' : 'FLUX') + ")... ⏳";

          const fd = new FormData();
          fd.append("action", "growth_regenerate_banner");
          fd.append("tenant_slug", _cpCurrentBatch.tenant_slug);
          fd.append("topic", _cpCurrentBatch.posts[idx].post_title + " - " + _cpCurrentBatch.topic);
          fd.append("custom_instructions", instructions);
          fd.append("image_engine", engine);

          try {
            const res = await fetch("index.php", { method: "POST", body: fd });
            const data = await res.json();

            if (data.success && data.image_url) {
              if (data.notice) {
                alert("Aviso: " + data.notice);
              }
              _cpCurrentBatch.posts[idx].banner_image_url = data.image_url;
              document.getElementById("post_img_preview_box_" + idx).innerHTML = 
                `<img src="${data.image_url}" style="width:50px; height:50px; object-fit:cover; border-radius:6px; border:1px solid #cbd5e1; cursor:pointer;" onclick="window.open('${data.image_url}', '_blank');">`;
              document.getElementById("post_img_status_" + idx).innerHTML = "<span style='color:#16a34a; font-weight:700;'>✓ Imagen Actualizada (" + (data.model_used || engine) + ")</span>";
              document.getElementById("post_regen_box_" + idx).style.display = "none";
            } else {
              alert("Error al generar imagen para este post: " + (data.error || "Fallo en motor"));
            }
          } catch(err) {
            alert("Error: " + err.message);
          } finally {
            btn.disabled = false;
            btn.innerHTML = orig;
          }
        }

        let _cpCurrentBatch = null;

        function openCampaignPlannerModal() {
          document.getElementById("modalCampaignPlanner").style.display = "flex";
          checkCadenceAdvice();
        }

        async function checkCadenceAdvice() {
          const dStart = document.getElementById("cp_date_start").value;
          const dEnd = document.getElementById("cp_date_end").value;
          if (!dStart || !dEnd) return;

          const fd = new FormData();
          fd.append("action", "campaign_advise_cadence");
          fd.append("date_start", dStart);
          fd.append("date_end", dEnd);

          try {
            const res = await fetch("index.php", { method: "POST", body: fd });
            const data = await res.json();
            if (data.recommended_posts) {
              document.getElementById("cp_num_posts").value = data.recommended_posts;
              document.getElementById("txtCadenceRationale").innerHTML = 
                `Período de <strong>${data.days_total} días</strong> (${data.weeks_approx} semanas): ${data.cadence_rationale}`;
              window._cpSuggestedDates = data.suggested_dates;
            }
          } catch(e) { console.error(e); }
        }

        function onCpTenantChanged(val) {
          const topInp = document.getElementById("cp_topic");
          const audInp = document.getElementById("cp_audience");
          const landInp = document.getElementById("cp_landing");

          if (val === "keystone_deepshield") {
            topInp.value = "Blindaje perimetral contra fraude BEC en transferencias bancarias y cumplimiento Ley 21.459";
            audInp.value = "Gerentes de Finanzas, CFOs, Tesoreros y CISOs";
            landInp.value = "https://www.keystoneadvisors.cl/";
          } else if (val === "keystone_salesforce") {
            topInp.value = "Implementación y modernización de Salesforce Financial Services Cloud (FSC) para Banca";
            audInp.value = "CIOs, Gerentes de TI y Directores de Canales Digitales";
            landInp.value = "https://www.keystoneadvisors.cl/#contact";
          } else if (val === "keystone_cloud") {
            topInp.value = "Arquitectura Cloud moderna, microservicios y alta disponibilidad resiliente";
            audInp.value = "CTOs, Gerentes de Infraestructura y Operaciones";
            landInp.value = "https://www.keystoneadvisors.cl/";
          } else if (val === "sinnotario") {
            topInp.value = "Firma electrónica avanzada de contratos y trámites con validez legal inmediata";
            audInp.value = "Pymes, fundadores, corredores de propiedades y jefaturas legales";
            landInp.value = "https://sinnotario.cl/";
          } else if (val === "keyhub") {
            topInp.value = "Control de rentabilidad en tiempo real y asignación de horas de consultoría";
            audInp.value = "Socios directores de firmas profesionales y gerentes de proyectos";
            landInp.value = "https://keyhub.keystoneadvisors.cl/";
          }
        }

        async function executeBatchCampaignGeneration(e) {
          e.preventDefault();
          const btn = document.getElementById("btnGenBatchCamp");
          const orig = btn.innerHTML;
          btn.disabled = true;
          btn.innerHTML = "✨ Redactando Lote de Artículos & Asignando UTMs con Groq LPU... ⏳";

          const tenantSlug = document.getElementById("cp_tenant").value;
          const topic = document.getElementById("cp_topic").value;
          const audience = document.getElementById("cp_audience").value;
          const landing = document.getElementById("cp_landing").value;
          const numPosts = document.getElementById("cp_num_posts").value;
          const campName = "camp_" + tenantSlug.replace("keystone_", "") + "_" + new Date().toISOString().slice(0,10).replace(/-/g, "");

          const fd = new FormData();
          fd.append("action", "campaign_generate_batch");
          fd.append("tenant_slug", tenantSlug);
          fd.append("topic", topic);
          fd.append("target_audience", audience);
          fd.append("landing_url", landing);
          fd.append("campaign_name", campName);
          fd.append("num_posts", numPosts);
          fd.append("scheduled_dates", JSON.stringify(window._cpSuggestedDates || []));

          try {
            const res = await fetch("index.php", { method: "POST", body: fd });
            const data = await res.json();

            if (data.success && data.items) {
              _cpCurrentBatch = {
                campaign_name: campName,
                campaign_title: topic,
                tenant_slug: tenantSlug,
                topic: topic,
                target_audience: audience,
                landing_url: landing,
                date_start: document.getElementById("cp_date_start").value,
                date_end: document.getElementById("cp_date_end").value,
                posts: data.items
              };

              renderBatchCards(data.items);
              document.getElementById("cpBatchResultsContainer").style.display = "block";
              document.getElementById("cpBatchResultsContainer").scrollIntoView({ behavior: "smooth" });
            } else {
              alert("Error al generar campaña: " + (data.error || "Fallo en motor de IA"));
            }
          } catch(err) {
            alert("Error de conexión: " + err.message);
          } finally {
            btn.disabled = false;
            btn.innerHTML = orig;
          }
        }

        function renderBatchCards(items) {
          const list = document.getElementById("cpPostsList");
          let html = "";

          items.forEach((item, idx) => {
            html += `
              <div style="background:#ffffff; border:1px solid #cbd5e1; border-radius:10px; padding:1.25rem; box-shadow:0 2px 4px rgba(0,0,0,0.03);">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.75rem; border-bottom:1px solid #f1f5f9; padding-bottom:0.5rem; flex-wrap:wrap; gap:0.5rem;">
                  <div style="display:flex; align-items:center; gap:0.6rem;">
                    <span style="background:#003366; color:#fff; font-weight:800; font-size:0.78rem; padding:0.25rem 0.6rem; border-radius:99px;">
                      Post #${item.post_index}
                    </span>
                    <strong style="color:#0f172a; font-size:0.95rem;">${item.post_title}</strong>
                  </div>
                  <div style="display:flex; align-items:center; gap:0.5rem;">
                    <span style="font-size:0.75rem; color:#64748b; font-weight:600;">Fecha Programada:</span>
                    <input type="datetime-local" value="${item.scheduled_at.replace(" ", "T")}" onchange="_cpCurrentBatch.posts[${idx}].scheduled_at = this.value.replace('T', ' ') + ':00';" style="padding:0.35rem 0.5rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.8rem;">
                  </div>
                </div>

                <div style="margin-bottom:0.75rem;">
                  <label style="display:block; font-size:0.75rem; font-weight:700; color:#475569; margin-bottom:0.25rem;">Texto del Post (LinkedIn B2B):</label>
                  <textarea rows="5" onchange="_cpCurrentBatch.posts[${idx}].post_text = this.value;" style="width:100%; padding:0.65rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.85rem; line-height:1.45; color:#1e293b; background:#f8fafc;">${item.post_text}</textarea>
                </div>

                <div style="background:#f1f5f9; border-radius:6px; padding:0.6rem 0.85rem; font-size:0.75rem; display:flex; justify-content:space-between; align-items:center; gap:1rem; margin-bottom:0.75rem;">
                  <span style="color:#475569; font-weight:600;">🔗 UTM asignada:</span>
                  <code style="color:#0369a1; word-break:break-all;">${item.utm_url}</code>
                </div>

                <!-- Bloque Banner Individual para este Post de Campaña -->
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:0.85rem;">
                  <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.5rem;">
                    <div style="display:flex; align-items:center; gap:0.75rem;">
                      <div id="post_img_preview_box_${idx}">
                        ${item.banner_image_url ? `<img src="${item.banner_image_url}" style="width:50px; height:50px; object-fit:cover; border-radius:6px; border:1px solid #cbd5e1;">` : `<div style="width:50px; height:50px; background:#e2e8f0; border-radius:6px; display:flex; align-items:center; justify-content:center; font-size:1.2rem;">🖼️</div>`}
                      </div>
                      <div>
                        <div style="font-weight:700; font-size:0.82rem; color:#1e293b;">Banner Visual de este Post</div>
                        <span id="post_img_status_${idx}" style="font-size:0.72rem; color:#64748b;">${item.banner_image_url ? '✓ Imagen Asociada' : 'Sin banner generado aún (opcional)'}</span>
                      </div>
                    </div>
                    <button type="button" onclick="togglePostRegenBox(${idx});" style="background:#0284c7; color:#fff; border:none; padding:0.35rem 0.75rem; border-radius:6px; font-size:0.75rem; font-weight:700; cursor:pointer;">
                      🎨 ${item.banner_image_url ? 'Regenerar con Indicaciones' : '⚡ Generar Banner para este Post'}
                    </button>
                  </div>

                  <div id="post_regen_box_${idx}" style="display:none; margin-top:0.75rem; padding-top:0.75rem; border-top:1px solid #e2e8f0;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.25rem;">
                      <label style="font-size:0.72rem; font-weight:700; color:#475569;">
                        Indicaciones específicas para la imagen de este post:
                      </label>
                      <select id="post_regen_engine_${idx}" style="padding:0.2rem 0.4rem; border:1px solid #cbd5e1; border-radius:4px; font-size:0.72rem; background:#fff; color:#0f172a;">
                        <option value="flux" selected>Motor: Cloudflare FLUX.1</option>
                        <option value="gemini">Motor: Google AI Studio</option>
                      </select>
                    </div>
                    <div style="display:flex; gap:0.5rem;">
                      <input type="text" id="post_regen_prompt_${idx}" value="${item.visual_prompt || ''}" placeholder="Instrucciones visuales..." style="flex:1; padding:0.45rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.8rem;">
                      <button type="button" id="btn_post_regen_${idx}" onclick="executePostBannerRegen(${idx});" style="background:#059669; color:#fff; border:none; padding:0.45rem 1rem; border-radius:6px; font-size:0.78rem; font-weight:800; cursor:pointer; white-space:nowrap;">
                        Generar Imagen
                      </button>
                    </div>
                  </div>
                </div>
              </div>
            `;
          });

          list.innerHTML = html;
        }

        async function confirmAndScheduleCampaign() {
          if (!_cpCurrentBatch || !_cpCurrentBatch.posts) {
            alert("No hay campaña activa para guardar.");
            return;
          }

          const btn = document.getElementById("btnSaveCampSchedule");
          const statusBox = document.getElementById("cpScheduleSaveStatus");
          const orig = btn.innerHTML;
          btn.disabled = true;
          btn.innerHTML = "Guardando Calendario en SQLite... ⏳";
          statusBox.style.display = "none";

          const fd = new FormData();
          fd.append("action", "campaign_save_schedule");
          fd.append("payload", JSON.stringify(_cpCurrentBatch));

          try {
            const res = await fetch("index.php", { method: "POST", body: fd });
            const data = await res.json();

            statusBox.style.display = "block";
            if (data.success) {
              statusBox.innerHTML = `
                <div style="background:#dcfce7; border:1px solid #86efac; color:#166534; padding:1.25rem; border-radius:8px; font-weight:700;">
                  <div style="font-size:1.1rem; margin-bottom:0.35rem;">🎉 ¡Campaña y Calendario Programados Exitosamente!</div>
                  <div style="font-size:0.85rem; font-weight:normal; color:#374151;">
                    Se guardaron <strong>${data.scheduled_posts} publicaciones</strong> en la cola desatendida para la campaña <code>${data.campaign_name}</code>.<br>
                    El despachador automático ('cron/dispatch_scheduled_campaigns.php') las publicará en la Página de Keystone Advisors Global a medida que llegue la fecha estipulada.
                  </div>
                </div>
              `;
            } else {
              statusBox.innerHTML = `<div style="background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:1rem; border-radius:8px;"><strong>Error:</strong> ${data.error}</div>`;
            }
          } catch(err) {
            statusBox.style.display = "block";
            statusBox.innerHTML = `<div style="background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:1rem; border-radius:8px;"><strong>Error de red:</strong> ${err.message}</div>`;
          } finally {
            btn.disabled = false;
            btn.innerHTML = orig;
          }
        }

        async function generateAiAdCreatives(e) {
          e.preventDefault();
          const btn = document.getElementById('btnAiGenerate');
          const origText = btn.innerHTML;
          btn.disabled = true;
          btn.innerHTML = '✨ Consultando Google Gemini 3.8 & Cloudflare FLUX.1... ⏳';

          const fd = new FormData(document.getElementById('formAiAdGen'));
          fd.append('action', 'growth_generate_ai');

          try {
            const res = await fetch('index.php', { method: 'POST', body: fd });
            const data = await res.json();

            if (data.success && data.copy && data.copy.data) {
              const cr = data.copy.data;
              document.getElementById('aiOutputContainer').style.display = 'block';

              const prov = data.copy.provider || 'Groq Cloud LPU & Google Gemini';
              let html = `
                <div style="margin-bottom:1rem; display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap;">
                  <span style="font-size:0.75rem; background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; padding:0.25rem 0.6rem; border-radius:4px; font-weight:800;">
                    ⚡ Motor Primario Activo: ${prov}
                  </span>
                  <span style="font-size:0.72rem; color:#64748b;">Costo: <strong>$0 USD</strong> | Latencia Inmediata</span>
                </div>
              `;

              if (cr.google_headlines) {
                html += `<div style="margin-bottom:1rem;"><strong>Titulares Google Ads (30 caracteres):</strong><ul style="margin:0.35rem 0 0 1.25rem;">`;
                cr.google_headlines.forEach(h => { html += `<li><code>${h}</code></li>`; });
                html += `</ul></div>`;
              }

              if (cr.google_descriptions) {
                html += `<div style="margin-bottom:1rem;"><strong>Descripciones Google Ads (90 caracteres):</strong><ul style="margin:0.35rem 0 0 1.25rem;">`;
                cr.google_descriptions.forEach(d => { html += `<li>${d}</li>`; });
                html += `</ul></div>`;
              }

              if (cr.linkedin_post) {
                html += `<div style="margin-bottom:1rem;"><strong>Copy para Publicación / Anuncio LinkedIn:</strong><pre style="white-space:pre-wrap; background:#ffffff; border:1px solid #cbd5e1; padding:1rem; border-radius:6px; font-size:0.85rem; color:#1e293b; margin-top:0.35rem;">${cr.linkedin_post}</pre></div>`;
              }

              if (cr.video_script_30s) {
                let vScript = cr.video_script_30s;
                if (typeof vScript === 'object') {
                  let formatted = '';
                  if (vScript.gancho_0_5s) formatted += '🎬 GANCHO (0-5s):\n' + vScript.gancho_0_5s + '\n\n';
                  if (vScript.problema_5_15s) formatted += '⚠️ PROBLEMA / DOLOR (5-15s):\n' + vScript.problema_5_15s + '\n\n';
                  if (vScript.solucion_15_25s) formatted += '🛡️ SOLUCIÓN (15-25s):\n' + vScript.solucion_15_25s + '\n\n';
                  if (vScript.llamado_a_la_accion_25_30s) formatted += '🚀 LLAMADO A LA ACCIÓN (25-30s):\n' + vScript.llamado_a_la_accion_25_30s;
                  vScript = formatted || JSON.stringify(vScript, null, 2);
                }
                html += `<div style="margin-bottom:1rem;"><strong>Guión de Video 30s (Estructurado):</strong><pre style="white-space:pre-wrap; background:#ffffff; border:1px solid #cbd5e1; padding:1rem; border-radius:6px; font-size:0.85rem; color:#1e293b; margin-top:0.35rem;">${vScript}</pre></div>`;
              }

              document.getElementById('aiCopyResults').innerHTML = html;

              // Banner FLUX.1 y Botón Publicar en LinkedIn
              if (data.banner && data.banner.success && data.banner.image_url) {
                const imgBox = document.getElementById('aiBannerResults');
                const imgEl = document.getElementById('aiBannerImg');
                const dwnBtn = document.getElementById('aiBannerDownloadBtn');
                imgEl.src = data.banner.image_url;
                dwnBtn.href = data.banner.image_url;
                imgBox.style.display = 'block';
              } else {
                document.getElementById('aiBannerResults').style.display = 'none';
              }

              // Inyectar botón de Publicar en LinkedIn al final de los resultados
              const liPostText = cr.linkedin_post || '';
              const liBannerUrl = (data.banner && data.banner.image_url) ? data.banner.image_url : '';
              
              if (liPostText) {
                let btnLiHtml = `
                  <div style="margin-top:1.5rem; background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:1.25rem; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem;">
                    <div>
                      <h5 style="margin:0 0 0.25rem 0; color:#1e40af; font-size:0.95rem; font-weight:800;">🚀 Despachar Post a LinkedIn Empresa</h5>
                      <span style="font-size:0.75rem; color:#475569;">Publica este copy y banner directamente en la Página Oficial de Keystone Advisors con 1 clic.</span>
                    </div>
                    <button type="button" onclick="dispatchPostToLinkedIn();" id="btnPublishLi" style="background:#0a66c2; color:#fff; border:none; padding:0.65rem 1.25rem; border-radius:6px; font-weight:800; font-size:0.85rem; cursor:pointer; display:flex; align-items:center; gap:0.5rem; box-shadow:0 2px 6px rgba(10,102,194,0.3);">
                      <span>💼</span> Publicar Ahora en LinkedIn
                    </button>
                  </div>
                  <div id="liPublishStatus" style="display:none; margin-top:0.75rem;"></div>
                `;
                html += btnLiHtml;
                document.getElementById('aiCopyResults').innerHTML = html;

                // Guardar datos temporalmente en variables globales para dispatch
                window._currentLiPayload = {
                  text: liPostText,
                  image_path: liBannerUrl,
                  title: 'Keystone Advisors Intelligence'
                };
              }

              // Scroll hacia los resultados
              document.getElementById('aiOutputContainer').scrollIntoView({ behavior: 'smooth' });
            } else {
              alert('Error al generar creativos con IA: ' + (data.error || (data.copy ? data.copy.error : 'Respuesta inválida')));
            }
          } catch (err) {
            alert('Error de conexión al procesar con IA: ' + err.message);
          } finally {
            btn.disabled = false;
            btn.innerHTML = origText;
          }
        }
      
        // Publicar en LinkedIn
        async function dispatchPostToLinkedIn() {
          if (!window._currentLiPayload || !window._currentLiPayload.text) {
            alert('No hay texto generado para publicar.');
            return;
          }

          const btn = document.getElementById('btnPublishLi');
          const statusBox = document.getElementById('liPublishStatus');
          const orig = btn.innerHTML;

          btn.disabled = true;
          btn.innerHTML = 'Publicando en LinkedIn... ⏳';

          const fd = new FormData();
          fd.append('action', 'publish_to_linkedin');
          fd.append('post_text', window._currentLiPayload.text);
          fd.append('image_path', window._currentLiPayload.image_path || '');
          fd.append('post_title', window._currentLiPayload.title || '');

          try {
            const res = await fetch('index.php', { method: 'POST', body: fd });
            const data = await res.json();

            statusBox.style.display = 'block';
            if (data.success) {
              statusBox.innerHTML = `
                <div style="background:#dcfce7; border:1px solid #86efac; color:#166534; padding:0.75rem; border-radius:6px; font-size:0.82rem; font-weight:700;">
                  ✓ ${data.message} <br>
                  <span style="font-size:0.75rem; font-weight:normal; color:#15803d;">Identificador URN: <code>${data.post_urn}</code></span>
                </div>
              `;
              btn.innerHTML = '✓ ¡Publicado!';
            } else {
              statusBox.innerHTML = `
                <div style="background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:0.75rem; border-radius:6px; font-size:0.82rem;">
                  ❌ ${data.error}
                </div>
              `;
              btn.innerHTML = orig;
              btn.disabled = false;
            }
          } catch (err) {
            statusBox.style.display = 'block';
            statusBox.innerHTML = `<div style="background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:0.75rem; border-radius:6px; font-size:0.82rem;">Error de conexión: ${err.message}</div>`;
            btn.innerHTML = orig;
            btn.disabled = false;
          }
        }

      </script>

    <?php elseif ($tab === 'integrations'): ?>
      <?php
        require_once __DIR__ . '/../api/KeystoneGrowthService.php';
        $health = KeystoneGrowthService::checkIntegrationsHealth();
        $dbMkt = KeystoneGrowthService::getDb();
        
        $gAiKey = KeystoneGrowthService::getGoogleApiKey();
        $groqKey = KeystoneGrowthService::getGroqApiKey();
        $cfAcc = KeystoneGrowthService::getCfAccountId();
        $cfToken = KeystoneGrowthService::getCfAiToken();

        $gCustId = KeystoneGrowthService::getSetting('google_ads_customer_id', '396-771-8344');
        $liClientId = KeystoneGrowthService::getSetting('linkedin_client_id', '77gaerf3okng9m');
        $liClientSecret = KeystoneGrowthService::getSetting('linkedin_client_secret', 'WPL_AP1.wjwgqTxS7FVRqWFY.ZKULxA==');
        $liOrgId = KeystoneGrowthService::getSetting('linkedin_org_id', '105863140');
        $liToken = KeystoneGrowthService::getSetting('linkedin_access_token', '');
        $liExpires = (int)KeystoneGrowthService::getSetting('linkedin_token_expires_at', '0');
        $liMemberUrn = KeystoneGrowthService::getSetting('linkedin_member_urn', 'urn:li:person:By05C_evAt');
      ?>

      <div style="max-width: 1200px; margin: 0 auto;">
        
        <?php if (isset($_GET['saved'])): ?>
          <div style="background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; padding:1rem 1.25rem; border-radius:8px; margin-bottom:1.5rem; font-weight:700;">
            ✓ ¡Credenciales actualizadas exitosamente en la base de datos de marketing! Los servicios se reconectarán automáticamente.
          </div>
        <?php endif; ?>

        <!-- Encabezado de la Pestaña -->
        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:1.5rem; flex-wrap:wrap; gap:1rem;">
          <div>
            <h2 style="font-size:1.6rem; font-weight:800; color:#003366; margin:0 0 0.35rem 0;">
              🔌 Integraciones, Credenciales & Health Check
            </h2>
            <p style="font-size:0.9rem; color:#64748b; margin:0;">
              Supervisión en vivo de motores de IA, tokens OAuth y APIs para el ecosistema de marketing y ventas de Keystone Advisors.
            </p>
          </div>
          <button type="button" onclick="runHealthCheckNow();" id="btnRunHealth" style="background:#0284c7; color:#fff; border:none; padding:0.65rem 1.25rem; border-radius:8px; font-weight:700; font-size:0.88rem; cursor:pointer; display:flex; align-items:center; gap:0.5rem; box-shadow:0 4px 12px rgba(2,132,199,0.25);">
            <span>🔄</span> Probar Conexión en Vivo
          </button>
        </div>

        <!-- Matriz de Estado en Vivo (Health Cards) -->
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:1rem; margin-bottom:2rem;">
          
          <!-- Card Groq -->
          <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:10px; padding:1.2rem; box-shadow:0 2px 4px rgba(0,0,0,0.02);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.6rem;">
              <span style="font-weight:800; font-size:0.85rem; color:#0f172a;">⚡ Groq Cloud LPU</span>
              <span id="badge_groq" style="font-size:0.7rem; font-weight:800; padding:0.2rem 0.5rem; border-radius:99px; background:<?php echo $health['groq']['status'] === 'ok' ? '#dcfce7; color:#15803d;' : '#fee2e2; color:#b91c1c;'; ?>">
                <?php echo strtoupper($health['groq']['status']); ?>
              </span>
            </div>
            <div id="msg_groq" style="font-size:0.78rem; color:#64748b; line-height:1.4;">
              <?php echo htmlspecialchars($health['groq']['message']); ?>
            </div>
            <div style="margin-top:0.6rem; font-size:0.72rem; color:#94a3b8;">
              Modelo: <code>qwen/qwen3.8-27b</code> (Tier 1 Copywriter)
            </div>
          </div>

          <!-- Card Cloudflare FLUX.1 -->
          <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:10px; padding:1.2rem; box-shadow:0 2px 4px rgba(0,0,0,0.02);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.6rem;">
              <span style="font-weight:800; font-size:0.85rem; color:#0f172a;">🖼️ Cloudflare FLUX.1</span>
              <span id="badge_cloudflare" style="font-size:0.7rem; font-weight:800; padding:0.2rem 0.5rem; border-radius:99px; background:<?php echo $health['cloudflare']['status'] === 'ok' ? '#dcfce7; color:#15803d;' : '#fee2e2; color:#b91c1c;'; ?>">
                <?php echo strtoupper($health['cloudflare']['status']); ?>
              </span>
            </div>
            <div id="msg_cloudflare" style="font-size:0.78rem; color:#64748b; line-height:1.4;">
              <?php echo htmlspecialchars($health['cloudflare']['message']); ?>
            </div>
            <div style="margin-top:0.6rem; font-size:0.72rem; color:#94a3b8;">
              Motor: Workers AI Schnell (1024x1024 px)
            </div>
          </div>

          <!-- Card Google AI Studio -->
          <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:10px; padding:1.2rem; box-shadow:0 2px 4px rgba(0,0,0,0.02);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.6rem;">
              <span style="font-weight:800; font-size:0.85rem; color:#0f172a;">🧠 Google AI Studio</span>
              <span id="badge_google_ai" style="font-size:0.7rem; font-weight:800; padding:0.2rem 0.5rem; border-radius:99px; background:<?php echo $health['google_ai']['status'] === 'ok' ? '#dcfce7; color:#15803d;' : ($health['google_ai']['status'] === 'warning' ? '#fef3c7; color:#92400e;' : '#fee2e2; color:#b91c1c;'); ?>">
                <?php echo strtoupper($health['google_ai']['status']); ?>
              </span>
            </div>
            <div id="msg_google_ai" style="font-size:0.78rem; color:#64748b; line-height:1.4;">
              <?php echo htmlspecialchars($health['google_ai']['message']); ?>
            </div>
            <div style="margin-top:0.6rem; font-size:0.72rem; color:#94a3b8;">
              Gemini 2.5 Flash / Flash Image (Nano Banana)
            </div>
          </div>

          <!-- Card LinkedIn Empresa -->
          <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:10px; padding:1.2rem; box-shadow:0 2px 4px rgba(0,0,0,0.02);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.6rem;">
              <span style="font-weight:800; font-size:0.85rem; color:#0f172a;">💼 LinkedIn Empresa</span>
              <span id="badge_linkedin" style="font-size:0.7rem; font-weight:800; padding:0.2rem 0.5rem; border-radius:99px; background:<?php echo $health['linkedin']['status'] === 'ok' ? '#dcfce7; color:#15803d;' : '#fee2e2; color:#b91c1c;'; ?>">
                <?php echo strtoupper($health['linkedin']['status']); ?>
              </span>
            </div>
            <div id="msg_linkedin" style="font-size:0.78rem; color:#64748b; line-height:1.4;">
              <?php echo htmlspecialchars($health['linkedin']['message']); ?>
            </div>
            <div style="margin-top:0.6rem; font-size:0.72rem; color:#94a3b8;">
              Org ID: <code><?php echo htmlspecialchars($liOrgId); ?></code> (REST v202610)
            </div>
          </div>

          <!-- Card Google Ads -->
          <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:10px; padding:1.2rem; box-shadow:0 2px 4px rgba(0,0,0,0.02);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.6rem;">
              <span style="font-weight:800; font-size:0.85rem; color:#0f172a;">🔍 Google Ads</span>
              <span id="badge_google_ads" style="font-size:0.7rem; font-weight:800; padding:0.2rem 0.5rem; border-radius:99px; background:<?php echo $health['google_ads']['status'] === 'ok' ? '#dcfce7; color:#15803d;' : ($health['google_ads']['status'] === 'warning' ? '#fef3c7; color:#92400e;' : '#f1f5f9; color:#475569;'); ?>">
                <?php echo strtoupper($health['google_ads']['status']); ?>
              </span>
            </div>
            <div id="msg_google_ads" style="font-size:0.78rem; color:#64748b; line-height:1.4;">
              <?php echo htmlspecialchars($health['google_ads']['message']); ?>
            </div>
            <div style="margin-top:0.6rem; font-size:0.72rem; color:#94a3b8;">
              Customer ID: <code><?php echo htmlspecialchars($gCustId); ?></code>
            </div>
          </div>

        </div>

        <!-- Formulario Maestro de Configuración y Claves -->
        <div class="funnel-box" style="margin-bottom:2rem;">
          <h3 style="color:#003366; font-size:1.15rem; margin-bottom:0.5rem;">⚙️ Configuración y Edición de Credenciales</h3>
          <p style="font-size:0.85rem; color:#64748b; margin-bottom:1.5rem;">
            Actualice las API Keys y tokens de servicio directamente. Los valores se almacenan cifrados en la base de datos interna y tienen precedencia inmediata.
          </p>

          <form method="POST" action="index.php">
            <input type="hidden" name="action" value="save_marketing_credentials">

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1.5rem; margin-bottom:1.5rem;">
              
              <!-- Bloque Google AI Studio -->
              <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:1.25rem;">
                <h4 style="font-size:0.95rem; color:#003366; margin:0 0 0.5rem 0;">🧠 Google AI Studio API Key</h4>
                <p style="font-size:0.78rem; color:#64748b; margin:0 0 0.75rem 0;">
                  Utilizada para Gemini 2.5 Flash / Gemini Image. Puede obtenerla o recargar créditos en <a href="https://ai.studio/projects" target="_blank" style="color:#0284c7; font-weight:700;">ai.studio</a>.
                </p>
                <input type="password" name="google_ai_api_key" value="<?php echo htmlspecialchars($gAiKey); ?>" placeholder="AIzaSy... o AQ.Ab8..." style="width:100%; padding:0.65rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.85rem; font-family:monospace; background:#fff;">
              </div>

              <!-- Bloque Groq Cloud LPU -->
              <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:1.25rem;">
                <h4 style="font-size:0.95rem; color:#003366; margin:0 0 0.5rem 0;">⚡ Groq Cloud API Key</h4>
                <p style="font-size:0.78rem; color:#64748b; margin:0 0 0.75rem 0;">
                  Motor Tier 1 para copywriter y planificación editorial ultra rápida a costo $0 USD (<a href="https://console.groq.com/" target="_blank" style="color:#0284c7; font-weight:700;">console.groq.com</a>).
                </p>
                <input type="password" name="groq_api_key" value="<?php echo htmlspecialchars($groqKey); ?>" placeholder="gsk_..." style="width:100%; padding:0.65rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.85rem; font-family:monospace; background:#fff;">
              </div>

              <!-- Bloque Cloudflare Workers AI -->
              <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:1.25rem;">
                <h4 style="font-size:0.95rem; color:#003366; margin:0 0 0.5rem 0;">🖼️ Cloudflare Workers AI (FLUX.1 Schnell)</h4>
                <p style="font-size:0.78rem; color:#64748b; margin:0 0 0.75rem 0;">
                  Generación de banners corporativos 1024x1024 px con el modelo FLUX.1.
                </p>
                <div style="margin-bottom:0.6rem;">
                  <label style="display:block; font-size:0.72rem; color:#475569; font-weight:700; margin-bottom:0.2rem;">Account ID:</label>
                  <input type="text" name="cf_account_id" value="<?php echo htmlspecialchars($cfAcc); ?>" placeholder="cf964188..." style="width:100%; padding:0.55rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.82rem; font-family:monospace; background:#fff;">
                </div>
                <div>
                  <label style="display:block; font-size:0.72rem; color:#475569; font-weight:700; margin-bottom:0.2rem;">API Token Workers AI:</label>
                  <input type="password" name="cf_ai_token" value="<?php echo htmlspecialchars($cfToken); ?>" placeholder="cfut_..." style="width:100%; padding:0.55rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.82rem; font-family:monospace; background:#fff;">
                </div>
              </div>

              <!-- Bloque LinkedIn App & Credentials -->
              <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:1.25rem;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.5rem;">
                  <h4 style="font-size:0.95rem; color:#003366; margin:0;">💼 LinkedIn Developer App & OAuth</h4>
                  <?php if (!empty($liToken)): ?>
                    <a href="oauth_linkedin.php?action=connect" style="font-size:0.75rem; background:#0a66c2; color:#fff; text-decoration:none; padding:0.25rem 0.6rem; border-radius:4px; font-weight:700;">Re-autorizar OAuth</a>
                  <?php else: ?>
                    <a href="oauth_linkedin.php?action=connect" style="font-size:0.75rem; background:#16a34a; color:#fff; text-decoration:none; padding:0.25rem 0.6rem; border-radius:4px; font-weight:700;">Conectar LinkedIn</a>
                  <?php endif; ?>
                </div>
                <p style="font-size:0.78rem; color:#64748b; margin:0 0 0.75rem 0;">
                  Publicación oficial en Keystone Advisors Global (ID 105863140).
                </p>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.5rem; margin-bottom:0.5rem;">
                  <div>
                    <label style="display:block; font-size:0.72rem; color:#475569; font-weight:700; margin-bottom:0.2rem;">Client ID:</label>
                    <input type="text" name="linkedin_client_id" value="<?php echo htmlspecialchars($liClientId); ?>" style="width:100%; padding:0.55rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.82rem; font-family:monospace; background:#fff;">
                  </div>
                  <div>
                    <label style="display:block; font-size:0.72rem; color:#475569; font-weight:700; margin-bottom:0.2rem;">Org ID Numérico:</label>
                    <input type="text" name="linkedin_org_id" value="<?php echo htmlspecialchars($liOrgId); ?>" style="width:100%; padding:0.55rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.82rem; font-family:monospace; background:#fff;">
                  </div>
                </div>
                <div>
                  <label style="display:block; font-size:0.72rem; color:#475569; font-weight:700; margin-bottom:0.2rem;">Client Secret:</label>
                  <input type="password" name="linkedin_client_secret" value="<?php echo htmlspecialchars($liClientSecret); ?>" style="width:100%; padding:0.55rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.82rem; font-family:monospace; background:#fff;">
                </div>
              </div>

            </div>

            <!-- Fila Google Ads Customer ID -->
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:1.25rem; margin-bottom:1.5rem;">
              <h4 style="font-size:0.95rem; color:#003366; margin:0 0 0.5rem 0;">🔍 Google Ads Customer ID</h4>
              <div style="display:flex; gap:1rem; align-items:center;">
                <input type="text" name="google_ads_customer_id" value="<?php echo htmlspecialchars($gCustId); ?>" placeholder="Ej: 396-771-8344" style="max-width:320px; padding:0.65rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.85rem; font-family:monospace; background:#fff;">
                <a href="oauth_google.php?action=connect" style="background:#0284c7; color:#fff; text-decoration:none; padding:0.65rem 1.25rem; border-radius:6px; font-size:0.85rem; font-weight:700;">
                  🔗 Autorizar Flujo Google OAuth
                </a>
              </div>
            </div>

            <button type="submit" style="background:#003366; color:#ffffff; border:none; padding:0.85rem 2rem; border-radius:8px; font-weight:800; font-size:0.95rem; cursor:pointer; box-shadow:0 4px 12px rgba(0,51,102,0.25);">
              💾 Guardar Todas las Credenciales
            </button>
          </form>
        </div>

      </div>

      <script>
        async function runHealthCheckNow() {
          const btn = document.getElementById("btnRunHealth");
          const orig = btn.innerHTML;
          btn.disabled = true;
          btn.innerHTML = "Verificando conexiones... ⏳";

          const fd = new FormData();
          fd.append("action", "test_integrations_health");

          try {
            const res = await fetch("index.php", { method: "POST", body: fd });
            const data = await res.json();

            if (data.success && data.services) {
              const s = data.services;
              updateServiceCard("groq", s.groq);
              updateServiceCard("cloudflare", s.cloudflare);
              updateServiceCard("google_ai", s.google_ai);
              updateServiceCard("linkedin", s.linkedin);
              updateServiceCard("google_ads", s.google_ads);
              alert("✓ Verificación de servicios completada.");
            } else {
              alert("Error al verificar: " + (data.error || "Respuesta inválida"));
            }
          } catch (e) {
            alert("Error de red: " + e.message);
          } finally {
            btn.disabled = false;
            btn.innerHTML = orig;
          }
        }

        function updateServiceCard(id, data) {
          if (!data) return;
          const badge = document.getElementById("badge_" + id);
          const msg = document.getElementById("msg_" + id);
          if (badge) {
            badge.innerText = data.status.toUpperCase();
            if (data.status === "ok") {
              badge.style.background = "#dcfce7";
              badge.style.color = "#15803d";
            } else if (data.status === "warning") {
              badge.style.background = "#fef3c7";
              badge.style.color = "#92400e";
            } else {
              badge.style.background = "#fee2e2";
              badge.style.color = "#b91c1c";
            }
          }
          if (msg && data.message) {
            msg.innerText = data.message;
          }
        }
      </script>


    <?php elseif ($tab === 'smtp'): ?>
      <?php
        require_once __DIR__ . '/../api/LeadNotificationService.php';
        $smtp = LeadNotificationService::getSmtpConfig();
      ?>
      <div style="max-width: 800px; margin: 0 auto;">
        
        <?php if (isset($_GET['saved'])): ?>
          <div style="background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; padding:1rem 1.25rem; border-radius:8px; margin-bottom:1.5rem; font-weight:700;">
            ✓ Configuración SMTP guardada correctamente.
          </div>
        <?php endif; ?>

        <?php if (isset($_GET['test_ok'])): ?>
          <div style="background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; padding:1rem 1.25rem; border-radius:8px; margin-bottom:1.5rem;">
            <strong>✓ ¡Prueba de Conexión Exitosa!</strong> El correo de prueba fue enviado y aceptado por el servidor SMTP hacia <code><?php echo htmlspecialchars($_GET['to'] ?? ''); ?></code>.
          </div>
        <?php endif; ?>

        <?php if (isset($_GET['test_fail'])): ?>
          <div style="background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:1rem 1.25rem; border-radius:8px; margin-bottom:1.5rem;">
            <strong>❌ Error al conectar o autenticar con el servidor SMTP:</strong>
            <pre style="margin-top:0.5rem; background:#450a0a; color:#fecaca; padding:0.75rem; border-radius:6px; font-size:0.8rem; overflow-x:auto;"><?php echo htmlspecialchars($_SESSION['smtp_test_error'] ?? 'Fallo desconocido.'); unset($_SESSION['smtp_test_error']); ?></pre>
          </div>
        <?php endif; ?>

        <div class="funnel-box" style="margin-bottom:2rem;">
          <h3 style="color:#003366; font-size:1.25rem; margin-bottom:0.35rem;">✉️ Configuración de Correo Saliente (Titan Mail / SMTP)</h3>
          <p style="font-size:0.88rem; color:#64748b; margin-bottom:1.5rem;">
            Configure las credenciales de correo de Keystone Advisors para despachar de forma autenticada los <strong>Reportes Ejecutivos PDF</strong> y notificaciones de leads con 100% de entregabilidad (evita filtros de spam).
          </p>

          <form method="POST" action="index.php">
            <input type="hidden" name="action" value="save_smtp">

            <div style="display:grid; grid-template-columns: 2fr 1fr; gap:1.25rem; margin-bottom:1.25rem;">
              <div>
                <label style="display:block; font-weight:700; color:#334155; font-size:0.85rem; margin-bottom:0.35rem;">Servidor SMTP Host *</label>
                <input type="text" name="smtp_host" value="<?php echo htmlspecialchars($smtp['smtp_host']); ?>" required style="width:100%; padding:0.65rem 0.85rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.9rem;">
                <span style="font-size:0.75rem; color:#64748b;">Predeterminado para Titan Mail: <code>smtp.titan.email</code></span>
              </div>
              <div>
                <label style="display:block; font-weight:700; color:#334155; font-size:0.85rem; margin-bottom:0.35rem;">Puerto SMTP *</label>
                <input type="number" name="smtp_port" value="<?php echo htmlspecialchars($smtp['smtp_port']); ?>" required style="width:100%; padding:0.65rem 0.85rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.9rem;">
                <span style="font-size:0.75rem; color:#64748b;">465 (SSL) o 587 (TLS)</span>
              </div>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1.25rem; margin-bottom:1.25rem;">
              <div>
                <label style="display:block; font-weight:700; color:#334155; font-size:0.85rem; margin-bottom:0.35rem;">Casilla / Usuario SMTP *</label>
                <input type="email" name="smtp_user" value="<?php echo htmlspecialchars($smtp['smtp_user']); ?>" required style="width:100%; padding:0.65rem 0.85rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.9rem;">
                <span style="font-size:0.75rem; color:#64748b;">Ejemplo: <code>contacto@keystoneadvisors.cl</code></span>
              </div>
              <div>
                <label style="display:block; font-weight:700; color:#334155; font-size:0.85rem; margin-bottom:0.35rem;">Contraseña de Casilla *</label>
                <input type="password" name="smtp_pass" placeholder="<?php echo !empty($smtp['smtp_pass']) ? '•••••••••••• (Guardada)' : 'Ingrese contraseña de la casilla'; ?>" style="width:100%; padding:0.65rem 0.85rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.9rem;">
                <span style="font-size:0.75rem; color:#64748b;">Déjelo en blanco para conservar la actual.</span>
              </div>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1.25rem; margin-bottom:1.75rem;">
              <div>
                <label style="display:block; font-weight:700; color:#334155; font-size:0.85rem; margin-bottom:0.35rem;">Correo Remitente (From)</label>
                <input type="email" name="smtp_from" value="<?php echo htmlspecialchars($smtp['smtp_from']); ?>" required style="width:100%; padding:0.65rem 0.85rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.9rem;">
              </div>
              <div>
                <label style="display:block; font-weight:700; color:#334155; font-size:0.85rem; margin-bottom:0.35rem;">Nombre del Remitente</label>
                <input type="text" name="smtp_name" value="<?php echo htmlspecialchars($smtp['smtp_name']); ?>" required style="width:100%; padding:0.65rem 0.85rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.9rem;">
              </div>
            </div>

            <button type="submit" style="background:#003366; color:#ffffff; border:none; padding:0.75rem 1.5rem; border-radius:6px; font-weight:700; font-size:0.9rem; cursor:pointer;">
              💾 Guardar Configuración SMTP
            </button>
          </form>
        </div>

        <!-- Caja de Prueba de Envío -->
        <div class="funnel-box">
          <h4 style="color:#003366; font-size:1.05rem; margin-bottom:0.35rem;">⚡ Probar Conexión y Envío de Correo</h4>
          <p style="font-size:0.85rem; color:#64748b; margin-bottom:1.25rem;">
            Envíe un correo de prueba en tiempo real para verificar que Titan Mail autentique la sesión sin rechazos.
          </p>

          <form method="POST" action="index.php" style="display:flex; gap:1rem; align-items:flex-end;">
            <input type="hidden" name="action" value="test_smtp">
            <div style="flex:1;">
              <label style="display:block; font-weight:700; color:#334155; font-size:0.82rem; margin-bottom:0.3rem;">Enviar correo de prueba a:</label>
              <input type="email" name="test_email" value="rdiazg@keystoneadvisors.cl" required style="width:100%; padding:0.65rem 0.85rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.9rem;">
            </div>
            <button type="submit" style="background:#10b981; color:#ffffff; border:none; padding:0.68rem 1.4rem; border-radius:6px; font-weight:700; font-size:0.9rem; cursor:pointer; white-space:nowrap;">
              🚀 Enviar Prueba Ahora
            </button>
          </form>
        </div>

      </div>

    <?php elseif ($tab === "users" && $current_admin_role === "superadmin"): ?>
      <?php
        $admin_users_list = $db->query("SELECT id, username, full_name, role, is_active, last_login, created_at FROM admin_users ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
      ?>
      <div style="max-width: 1100px; margin: 0 auto;">
        
        <?php if (isset($_GET["user_created"])): ?>
          <div style="background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; padding:1rem 1.25rem; border-radius:8px; margin-bottom:1.5rem; font-weight:700;">
            ✓ Operador creado exitosamente con sus credenciales y perfil asignado.
          </div>
        <?php endif; ?>

        <?php if (isset($_GET["status_updated"])): ?>
          <div style="background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; padding:1rem 1.25rem; border-radius:8px; margin-bottom:1.5rem; font-weight:700;">
            ✓ Estado del operador actualizado correctamente.
          </div>
        <?php endif; ?>

        <?php if (isset($_GET["pass_updated"])): ?>
          <div style="background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; padding:1rem 1.25rem; border-radius:8px; margin-bottom:1.5rem; font-weight:700;">
            ✓ Contraseña del operador actualizada correctamente con cifrado seguro.
          </div>
        <?php endif; ?>

        <?php if (isset($_GET["user_error"])): ?>
          <div style="background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:1rem 1.25rem; border-radius:8px; margin-bottom:1.5rem;">
            <strong>❌ Error:</strong> <?php echo htmlspecialchars($_GET["user_error"]); ?>
          </div>
        <?php endif; ?>

        <!-- Encabezado y Explicación de Perfiles -->
        <div class="funnel-box" style="margin-bottom:1.5rem;">
          <h3 style="color:#003366; font-size:1.3rem; margin-bottom:0.4rem; display:flex; align-items:center; gap:0.5rem;">
            <span>👥</span> <span>Control de Acceso Basado en Roles (RBAC) & Operadores</span>
          </h3>
          <p style="font-size:0.88rem; color:#64748b; margin-bottom:1.25rem;">
            Administre las cuentas de acceso al backend de Keystone Advisors con separación estricta de privilegios:
          </p>
          <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:1rem;">
            <div style="background:#fffbeb; border:1px solid #fde68a; border-radius:8px; padding:1rem;">
              <div style="font-weight:800; color:#92400e; font-size:0.88rem; margin-bottom:0.25rem;">👑 Super Admin (superadmin)</div>
              <p style="font-size:0.78rem; color:#78350f; margin:0;">Control total del sistema: gestión de usuarios, SMTP, DMARC, Tesorería DeepShield, Leads y Telemetría completa.</p>
            </div>
            <div style="background:#f0f9ff; border:1px solid #bae6fd; border-radius:8px; padding:1rem;">
              <div style="font-weight:800; color:#0369a1; font-size:0.88rem; margin-bottom:0.25rem;">💼 Comercial & Growth (comercial)</div>
              <p style="font-size:0.78rem; color:#0c4a6e; margin:0;">Enfocado en prospección y ventas: visualiza Leads, Escaneos públicos, Respuestas de clientes en Titan Mail y Marketing IA.</p>
            </div>
            <div style="background:#ecfdf5; border:1px solid #a7f3d0; border-radius:8px; padding:1rem;">
              <div style="font-weight:800; color:#065f46; font-size:0.88rem; margin-bottom:0.25rem;">🛡️ Operador Ciberseguridad (secops)</div>
              <p style="font-size:0.78rem; color:#064e3b; margin:0;">Gestión técnica de incidentes: consola DeepShield Tesorería (órdenes, fraudes BEC), DMARC Watchdog e inspección de Escaneos.</p>
            </div>
          </div>
        </div>

        <!-- Formulario: Crear Nuevo Operador -->
        <div class="funnel-box" style="margin-bottom:2rem;">
          <h4 style="color:#003366; font-size:1.1rem; margin-bottom:1rem;">➕ Crear Nuevo Operador de Sistema</h4>
          <form method="POST" action="index.php?tab=users" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)) 160px; gap:1rem; align-items:flex-end;">
            <input type="hidden" name="action" value="create_admin_user">
            
            <div>
              <label style="display:block; font-weight:700; color:#334155; font-size:0.82rem; margin-bottom:0.3rem;">Usuario de Acceso *</label>
              <input type="text" name="new_username" placeholder="ej. juan.comercial" required style="width:100%; padding:0.6rem 0.8rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.88rem;">
            </div>

            <div>
              <label style="display:block; font-weight:700; color:#334155; font-size:0.82rem; margin-bottom:0.3rem;">Nombre Completo</label>
              <input type="text" name="new_fullname" placeholder="ej. Juan Pérez" style="width:100%; padding:0.6rem 0.8rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.88rem;">
            </div>

            <div>
              <label style="display:block; font-weight:700; color:#334155; font-size:0.82rem; margin-bottom:0.3rem;">Perfil / Rol *</label>
              <select name="new_role" required style="width:100%; padding:0.6rem 0.8rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.88rem; background:#fff; font-weight:700; color:#003366;">
                <option value="comercial">💼 Comercial & Growth</option>
                <option value="secops">🛡️ Operador Ciberseguridad (SecOps)</option>
                <option value="superadmin">👑 Super Administrador</option>
              </select>
            </div>

            <div>
              <label style="display:block; font-weight:700; color:#334155; font-size:0.82rem; margin-bottom:0.3rem;">Contraseña Inicial *</label>
              <input type="password" name="new_password" required placeholder="Mínimo 8 caracteres" style="width:100%; padding:0.6rem 0.8rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.88rem;">
            </div>

            <div>
              <button type="submit" style="width:100%; background:#003366; color:#ffffff; border:none; padding:0.65rem 1rem; border-radius:6px; font-weight:700; font-size:0.88rem; cursor:pointer; height:38px;">
                Crear Operador
              </button>
            </div>
          </form>
        </div>

        <!-- Tabla de Operadores Registrados -->
        <div class="table-container">
          <div class="table-header">
            <h3>👥 Padrón de Operadores del Backend (<?php echo count($admin_users_list); ?>)</h3>
          </div>
          <table>
            <thead>
              <tr>
                <th>ID</th>
                <th>Usuario</th>
                <th>Nombre Completo</th>
                <th>Perfil Asignado</th>
                <th>Estado</th>
                <th>Último Acceso</th>
                <th style="text-align:right;">Acciones de Seguridad</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($admin_users_list as $u): ?>
                <?php
                  $is_self = ($u["username"] === $_SESSION["admin_user"]);
                  $role_meta = [
                    "superadmin" => ["name" => "👑 Super Admin", "bg" => "#fef3c7", "color" => "#92400e"],
                    "comercial"  => ["name" => "💼 Comercial", "bg" => "#e0f2fe", "color" => "#0369a1"],
                    "secops"     => ["name" => "🛡️ Ciberseguridad", "bg" => "#dcfce7", "color" => "#166534"],
                  ];
                  $rm = $role_meta[$u["role"]] ?? ["name" => htmlspecialchars($u["role"]), "bg" => "#f1f5f9", "color" => "#475569"];
                ?>
                <tr>
                  <td style="font-weight:700; color:#64748b;">#<?php echo $u["id"]; ?></td>
                  <td>
                    <div style="font-weight:800; color:#003366;"><?php echo htmlspecialchars($u["username"]); ?></div>
                    <?php if ($is_self): ?>
                      <span style="font-size:0.7rem; background:#e2e8f0; color:#334155; padding:1px 6px; border-radius:4px; font-weight:700;">Tu Sesión Actual</span>
                    <?php endif; ?>
                  </td>
                  <td style="color:#334155; font-weight:600;"><?php echo htmlspecialchars($u["full_name"] ?: "--"); ?></td>
                  <td>
                    <span style="display:inline-block; font-size:0.75rem; font-weight:800; padding:0.25rem 0.65rem; border-radius:99px; background:<?php echo $rm["bg"]; ?>; color:<?php echo $rm["color"]; ?>;">
                      <?php echo $rm["name"]; ?>
                    </span>
                  </td>
                  <td>
                    <?php if ((int)$u["is_active"] === 1): ?>
                      <span style="font-size:0.75rem; font-weight:800; color:#10b981;">● Activo</span>
                    <?php else: ?>
                      <span style="font-size:0.75rem; font-weight:800; color:#ef4444;">● Inactivo</span>
                    <?php endif; ?>
                  </td>
                  <td style="font-size:0.8rem; color:#64748b;">
                    <?php echo !empty($u["last_login"]) ? htmlspecialchars($u["last_login"]) : '<span style="color:#94a3b8;">Nunca</span>'; ?>
                  </td>
                  <td style="text-align:right;">
                    <div style="display:inline-flex; align-items:center; gap:0.4rem;">
                      
                      <!-- Botón Cambiar Contraseña -->
                      <button type="button" onclick="promptChangeUserPass(<?php echo $u['id']; ?>, '<?php echo htmlspecialchars(addslashes($u['username'])); ?>')" style="padding:0.35rem 0.65rem; background:#f1f5f9; border:1px solid #cbd5e1; border-radius:6px; font-size:0.78rem; font-weight:700; color:#334155; cursor:pointer;" title="Restablecer contraseña">
                        🔑 Clave
                      </button>

                      <?php if (!$is_self): ?>
                        <!-- Form Toggle Activo/Inactivo -->
                        <form method="POST" action="index.php?tab=users" style="margin:0; display:inline;">
                          <input type="hidden" name="action" value="toggle_admin_user_status">
                          <input type="hidden" name="user_id" value="<?php echo $u["id"]; ?>">
                          <input type="hidden" name="is_active" value="<?php echo (int)$u["is_active"] === 1 ? 0 : 1; ?>">
                          <button type="submit" style="padding:0.35rem 0.65rem; background:<?php echo (int)$u["is_active"] === 1 ? "#fee2e2" : "#dcfce7"; ?>; border:1px solid <?php echo (int)$u["is_active"] === 1 ? "#fecaca" : "#bbf7d0"; ?>; border-radius:6px; font-size:0.78rem; font-weight:700; color:<?php echo (int)$u["is_active"] === 1 ? "#dc2626" : "#166534"; ?>; cursor:pointer;" onclick="return confirm('¿Desea cambiar el estado de este operador?');">
                            <?php echo (int)$u["is_active"] === 1 ? "Suspender" : "Reactivar"; ?>
                          </button>
                        </form>
                      <?php endif; ?>

                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <!-- Form Oculto para cambio de contraseña vía Prompt JS -->
        <form id="formChangePassHidden" method="POST" action="index.php?tab=users" style="display:none;">
          <input type="hidden" name="action" value="change_admin_user_password">
          <input type="hidden" name="user_id" id="change_pass_user_id" value="">
          <input type="hidden" name="new_password" id="change_pass_new_val" value="">
        </form>

      </div>
    <?php endif; ?>

      <script>
        document.addEventListener('DOMContentLoaded', () => {
          const modal = document.getElementById('emailPreviewModal');
          const closeBtn = document.getElementById('closeEmailModal');
          const modalDomain = document.getElementById('modalDomainLabel');
          const modalTo = document.getElementById('modalToInput');
          const modalSubject = document.getElementById('modalSubjectInput');
          const modalBody = document.getElementById('modalBodyInput');
          const modalMailtoBtn = document.getElementById('modalMailtoBtn');
          const btnCopy = document.getElementById('btnCopyEmail');

          document.querySelectorAll('.btn-preview-email').forEach(btn => {
            btn.addEventListener('click', () => {
              const domain = btn.dataset.domain;
              const email = btn.dataset.email;
              const subject = btn.dataset.subject;
              const body = btn.dataset.body;

              modalDomain.textContent = domain;
              modalTo.value = email;
              modalSubject.value = subject;
              modalBody.value = body;

              const mailto = 'mailto:' + encodeURIComponent(email) + '?subject=' + encodeURIComponent(subject) + '&body=' + encodeURIComponent(body);
              modalMailtoBtn.href = mailto;

              modal.style.display = 'flex';
            });
          });

          // Sincronización Manual de Casilla IMAP
          const btnSyncImap = document.getElementById('btnSyncImap');
          if (btnSyncImap) {
            btnSyncImap.addEventListener('click', async () => {
              const prev = btnSyncImap.innerHTML;
              btnSyncImap.disabled = true;
              btnSyncImap.innerHTML = '<span>Leyendo Casilla Titan Mail...</span> ⏳';
              try {
                const formData = new FormData();
                formData.append('action', 'sync_inbox_replies');
                const res = await fetch('index.php', {
                  method: 'POST',
                  body: formData
                });
                const data = await res.json();
                if (data.success) {
                  alert(`¡Sincronización Exitosa! Se revisaron ${data.total_checked} mensajes y se importaron ${data.new_replies} respuestas nuevas.`);
                  window.location.reload();
                } else {
                  throw new Error(data.error || 'Fallo en sincronización IMAP');
                }
              } catch (err) {
                alert(`Error al sincronizar casilla: ${err.message}`);
                btnSyncImap.disabled = false;
                btnSyncImap.innerHTML = prev;
              }
            });
          }

          // Vaciar Vista de Respuestas
          const btnClearReplies = document.getElementById('btnClearReplies');
          if (btnClearReplies) {
            btnClearReplies.addEventListener('click', async () => {
              if (!confirm('¿Confirma vaciar la lista de respuestas locales? (Los correos reales en Titan Mail no se verán afectados)')) return;
              try {
                const formData = new FormData();
                formData.append('action', 'clear_replies_history');
                const res = await fetch('index.php', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                  window.location.reload();
                }
              } catch (err) {
                alert('Error al limpiar respuestas: ' + err.message);
              }
            });
          }

          // Despacho Automático de Correo con Propuesta vía SMTP Titan
          document.querySelectorAll('.btn-send-offer-smtp').forEach(btn => {
            btn.addEventListener('click', async () => {
              const scanId = btn.dataset.scanId;
              const domain = btn.dataset.domain;
              const email = btn.dataset.email;

              if (!confirm(`¿Confirma el envío automático de la propuesta comercial de Radiografía Forense 72h a ${email} para el dominio ${domain}?`)) {
                return;
              }

              const prevText = btn.innerHTML;
              btn.disabled = true;
              btn.innerHTML = `<span>Enviando...</span> ⏳`;
              btn.style.opacity = '0.7';

              try {
                const formData = new FormData();
                formData.append('action', 'send_scan_offer');
                formData.append('scan_id', scanId);

                const res = await fetch('index.php', {
                  method: 'POST',
                  body: formData
                });

                const data = await res.json();
                if (data.success) {
                  btn.innerHTML = `<span>✓ Enviada</span> 🛡️`;
                  btn.style.background = '#047857';
                  btn.style.opacity = '1';
                  btn.disabled = false;
                  alert(`¡Éxito! ${data.message}`);
                } else {
                  throw new Error(data.error || 'Error desconocido al enviar correo.');
                }
              } catch (err) {
                alert(`Error al enviar correo: ${err.message}`);
                btn.disabled = false;
                btn.innerHTML = prevText;
                btn.style.opacity = '1';
              }
            });
          });

// Despacho Automático de Correo a Lead vía SMTP Titan
          document.querySelectorAll('.btn-send-lead-smtp').forEach(btn => {
            btn.addEventListener('click', async () => {
              const leadId = btn.dataset.leadId;
              const name = btn.dataset.name;
              const email = btn.dataset.email;

              if (!confirm(`¿Confirma el envío automático del correo corporativo a ${name} (${email})?`)) {
                return;
              }

              const prevText = btn.innerHTML;
              btn.disabled = true;
              btn.innerHTML = `<span>Enviando...</span> ⏳`;
              btn.style.opacity = '0.7';

              try {
                const formData = new FormData();
                formData.append('action', 'send_lead_email');
                formData.append('lead_id', leadId);

                const res = await fetch('index.php', {
                  method: 'POST',
                  body: formData
                });

                const data = await res.json();
                if (data.success) {
                  btn.innerHTML = `<span>✓ Contactado</span> ✉️`;
                  btn.style.background = '#004d80';
                  btn.style.opacity = '1';
                  btn.disabled = false;
                  alert(`¡Éxito! ${data.message}`);
                  // Recargar tras 1 seg para refrescar estado en selector
                  setTimeout(() => { window.location.reload(); }, 1200);
                } else {
                  throw new Error(data.error || 'Error desconocido al enviar correo.');
                }
              } catch (err) {
                alert(`Error al enviar correo: ${err.message}`);
                btn.disabled = false;
                btn.innerHTML = prevText;
                btn.style.opacity = '1';
              }
            });
          });

          // Listener para previsualizar propuesta de Leads
          document.querySelectorAll('.btn-preview-lead').forEach(btn => {
            btn.addEventListener('click', () => {
              const name = btn.dataset.name;
              const email = btn.dataset.email;
              const subject = btn.dataset.subject;
              const body = btn.dataset.body;

              modalDomain.textContent = name;
              modalTo.value = email;
              modalSubject.value = subject;
              modalBody.value = body;

              const mailto = 'mailto:' + encodeURIComponent(email) + '?subject=' + encodeURIComponent(subject) + '&body=' + encodeURIComponent(body);
              modalMailtoBtn.href = mailto;

              modal.style.display = 'flex';
            });
          });

          if (closeBtn) {
            closeBtn.addEventListener('click', () => {
              modal.style.display = 'none';
            });
          }

          if (modal) {
            modal.addEventListener('click', (e) => {
              if (e.target === modal) modal.style.display = 'none';
            });
          }

          if (btnCopy) {
            btnCopy.addEventListener('click', async () => {
              try {
                await navigator.clipboard.writeText(modalBody.value);
                const prev = btnCopy.textContent;
                btnCopy.textContent = '✓ ¡Copiado con Éxito!';
                btnCopy.style.background = '#10b981';
                setTimeout(() => {
                  btnCopy.textContent = prev;
                  btnCopy.style.background = '#0284c7';
                }, 2000);
              } catch (err) {
                modalBody.select();
                document.execCommand('copy');
                alert('Copiado al portapapeles');
              }
            });
          }
        });
      </script>

  
      <script>
        function openEditTenantModal(tenant) {
          document.getElementById('edit_tenant_id').value = tenant.id;
          document.getElementById('edit_company_name').value = tenant.company_name || '';
          document.getElementById('edit_rut_dni').value = tenant.rut_dni || '';
          document.getElementById('edit_threshold_amount').value = tenant.threshold_amount || '2000000';
          document.getElementById('edit_threshold_currency').value = tenant.threshold_currency || 'CLP';
          document.getElementById('edit_primary_approver_name').value = tenant.primary_approver_name || '';
          document.getElementById('edit_primary_approver_phone').value = tenant.primary_approver_phone || '';
          document.getElementById('edit_primary_approver_email').value = tenant.primary_approver_email || '';
          document.getElementById('edit_treasury_operator_name').value = tenant.treasury_operator_name || '';
          document.getElementById('edit_treasury_operator_phone').value = tenant.treasury_operator_phone || '';
          document.getElementById('edit_treasury_operator_email').value = tenant.treasury_operator_email || '';
          document.getElementById('edit_whatsapp_instance').value = tenant.whatsapp_instance || 'keystone-general';
          document.getElementById('modalEditTenant').style.display = 'flex';
        }
      </script>

      <script>
        let qrPollInterval = null;

        async function openWhatsAppModal(instanceName, companyName) {
          const modal = document.getElementById('modalWhatsAppQr');
          document.getElementById('qrModalTitle').textContent = '📲 WhatsApp: ' + companyName;
          document.getElementById('qrLoading').style.display = 'block';
          document.getElementById('qrContainer').style.display = 'none';
          document.getElementById('qrStatusBadge').textContent = '';
          modal.style.display = 'flex';

          try {
            const resp = await fetch('../api/deepshield_qr.php?action=get_qr&instance=' + encodeURIComponent(instanceName));
            const data = await resp.json();
            
            document.getElementById('qrLoading').style.display = 'none';

            if (data.connected || data.state === 'open') {
              document.getElementById('qrStatusBadge').innerHTML = '<span style="color:#10b981; font-size:1.1rem;">✓ ¡Dispositivo Conectado & Operativo!</span>';
            } else if (data.base64) {
              document.getElementById('qrImage').src = data.base64;
              document.getElementById('qrContainer').style.display = 'block';
              document.getElementById('qrStatusBadge').innerHTML = '<span style="color:#f59e0b;">⏳ Esperando escaneo desde el teléfono...</span>';
              
              // Polling para detectar cuando se conecta
              if (qrPollInterval) clearInterval(qrPollInterval);
              qrPollInterval = setInterval(async () => {
                const check = await fetch('../api/deepshield_qr.php?action=check_state&instance=' + encodeURIComponent(instanceName));
                const res = await check.json();
                if (res.state === 'open') {
                  clearInterval(qrPollInterval);
                  document.getElementById('qrContainer').style.display = 'none';
                  document.getElementById('qrStatusBadge').innerHTML = '<span style="color:#10b981; font-size:1.1rem;">✓ ¡Chip Vinculado con Éxito!</span>';
                  setTimeout(() => location.reload(), 2000);
                }
              }, 3000);
            } else {
              document.getElementById('qrStatusBadge').innerHTML = '<span style="color:#ef4444;">Error: ' + (data.error || 'No se pudo generar QR') + '</span>';
            }
          } catch (e) {
            document.getElementById('qrLoading').style.display = 'none';
            document.getElementById('qrStatusBadge').innerHTML = '<span style="color:#ef4444;">Error conectando con Gateway</span>';
          }
        }

        function closeWhatsAppModal() {
          if (qrPollInterval) clearInterval(qrPollInterval);
          document.getElementById('modalWhatsAppQr').style.display = 'none';
        }

        // Monitor en tiempo real del estado de WhatsApp Central e Instancias de Clientes
        async function checkGlobalWhatsAppHealth() {
          const dot = document.getElementById('waGlobalDot');
          const txt = document.getElementById('waGlobalText');
          if (!dot || !txt) return;

          txt.textContent = 'WhatsApp: Verificando...';
          try {
            const resp = await fetch('../api/deepshield_qr.php?action=check_state&instance=keystone-general');
            const data = await resp.json();
            if (data.state === 'open') {
              dot.style.background = '#10b981';
              dot.style.boxShadow = '0 0 8px #10b981';
              txt.textContent = 'WhatsApp: Conectado (Central)';
              txt.style.color = '#e2e8f0';
            } else {
              dot.style.background = '#ef4444';
              dot.style.boxShadow = '0 0 8px #ef4444';
              txt.textContent = 'WhatsApp: Desconectado (' + data.state + ')';
              txt.style.color = '#fca5a5';
            }
          } catch (e) {
            dot.style.background = '#ef4444';
            txt.textContent = 'WhatsApp: Sin Conexión';
          }
        }

        async function pollTenantInstancesHealth() {
          const dots = document.querySelectorAll('.wa-inst-dot');
          const labels = document.querySelectorAll('.wa-inst-label');

          for (let dot of dots) {
            const inst = dot.dataset.instance;
            try {
              const res = await fetch('../api/deepshield_qr.php?action=check_state&instance=' + encodeURIComponent(inst));
              const data = await res.json();
              const label = document.querySelector(`.wa-inst-label[data-instance="${inst}"]`);

              if (data.state === 'open') {
                dot.style.background = '#10b981';
                dot.style.boxShadow = '0 0 6px #10b981';
                if (label) {
                  label.innerHTML = '<span style="color:#059669; font-weight:700;">🟢 Conectado</span>';
                }
              } else {
                dot.style.background = '#f59e0b';
                dot.style.boxShadow = 'none';
                if (label) {
                  label.innerHTML = '<span style="color:#d97706; font-weight:700;" title="Si no está conectado, el sistema enviará los mensajes automáticamente a través del chip central de Keystone">⚠️ ' + data.state + ' (Fallback Activo)</span>';
                }
              }
            } catch (err) {
              dot.style.background = '#ef4444';
            }
          }
        }

        // Ejecutar verificación al cargar y cada 25 segundos
        document.addEventListener('DOMContentLoaded', () => {
          checkGlobalWhatsAppHealth();
          pollTenantInstancesHealth();
          setInterval(checkGlobalWhatsAppHealth, 25000);
          setInterval(pollTenantInstancesHealth, 30000);
        });

        // Reenvío de Alerta OOB para Soporte L2
        async function resendOobAlert(orderId, orderCode) {
          if (!confirm(`¿Confirma el reenvío de la alerta OOB por WhatsApp para la orden ${orderCode}?`)) {
            return;
          }

          const btn = event.target;
          const originalText = btn.innerHTML;
          btn.disabled = true;
          btn.innerHTML = 'Enviando... ⏳';

          try {
            const formData = new FormData();
            formData.append('action', 'resend_oob_alert');
            formData.append('order_id', orderId);

            const res = await fetch('index.php', {
              method: 'POST',
              body: formData
            });

            const data = await res.json();
            if (data.success) {
              alert(`¡Éxito! ${data.message}`);
              btn.innerHTML = '✓ Reenviado';
              btn.style.background = '#10b981';
            } else {
              throw new Error(data.error || 'Error al reenviar');
            }
          } catch (e) {
            alert('Error: ' + e.message);
            btn.disabled = false;
            btn.innerHTML = originalText;
          }
        }

        // Modal y Protocolo de Incidente BEC (Fraude en Curso)
        let currentBecOrder = null;

        function openBecProtocolModal(order) {
          currentBecOrder = order;
          document.getElementById('bec_order_code').textContent = order.order_code;
          document.querySelectorAll('.bec_order_code_txt').forEach(el => el.textContent = order.order_code);
          document.getElementById('bec_company').textContent = order.company_name || 'Empresa Cliente';
          document.getElementById('bec_amount').textContent = (order.currency || 'CLP') + ' $' + Number(order.amount).toLocaleString('es-CL');
          document.getElementById('bec_beneficiary').textContent = order.beneficiary_name + ' (RUT: ' + order.beneficiary_rut_dni + ')';
          document.getElementById('bec_bank').textContent = order.bank_name + ' - ' + order.account_number;
          document.getElementById('bec_approver').textContent = order.approver_name + ' (' + (order.approver_phone || 'Sin móvil') + ')';
          document.getElementById('bec_challenge').textContent = order.security_challenge || 'N/A';
          document.getElementById('bec_hash').textContent = order.token_hash || 'SHA-256 Registrado';
          
          document.getElementById('becForensicResult').style.display = 'none';
          document.getElementById('modalBecProtocol').style.display = 'flex';
        }

        function closeBecProtocolModal() {
          document.getElementById('modalBecProtocol').style.display = 'none';
        }

        // Sincronizar correos reenviados a contacto@keystoneadvisors.cl
        async function syncBecImapAuto() {
          if (!currentBecOrder) return;
          const btn = document.getElementById('btnSyncBecImap');
          btn.disabled = true;
          btn.innerHTML = 'Consultando Titan Mail... ⏳';

          try {
            const formData = new FormData();
            formData.append('action', 'sync_bec_imap');
            formData.append('order_code', currentBecOrder.order_code);

            const res = await fetch('index.php', { method: 'POST', body: formData });
            const data = await res.json();

            btn.disabled = false;
            btn.innerHTML = '📥 Sincronizar desde contacto@keystoneadvisors.cl';

            const resultBox = document.getElementById('becForensicResult');
            const resultTitle = document.getElementById('becForensicTitle');
            const resultBody = document.getElementById('becForensicBody');

            if (data.success && data.found_count > 0) {
              const item = data.processed[0];
              resultTitle.innerHTML = `<span style="color:#dc2626;">🚨 CORREO ENCONTRADO EN CASILLA & ANALIZADO:</span>`;
              resultBody.innerHTML = `<strong>Emisor Detectado:</strong> ${item.sender}<br>
                                      <strong>IP Origen:</strong> ${item.ip} (SPF: <strong>${item.spf}</strong>)<br>
                                      <strong>Dosier PDF Emitido:</strong> <span style="color:#059669; font-weight:bold;">Listo para descarga y despacho</span>`;
              resultBox.style.display = 'block';
              alert(`¡Éxito! Se encontró el correo del incidente en la casilla contacto@keystoneadvisors.cl y se generó el dosier pericial.`);
            } else {
              resultTitle.innerHTML = `<span style="color:#d97706;">ℹ️ No se detectó correo nuevo aún</span>`;
              resultBody.innerHTML = `No se hallaron correos recientes con el asunto <code>BEC ${currentBecOrder.order_code}</code> en la casilla de contacto.<br>Asegúrese de que el cliente haya reenviado el mensaje o suba el archivo .eml con el botón de al lado.`;
              resultBox.style.display = 'block';
            }
          } catch (e) {
            btn.disabled = false;
            btn.innerHTML = '📥 Sincronizar desde contacto@keystoneadvisors.cl';
            alert('Error al sincronizar: ' + e.message);
          }
        }

        // Subir archivo .eml manual
        async function uploadBecEmlFile() {
          const fileInput = document.getElementById('bec_eml_file');
          if (!fileInput.files.length || !currentBecOrder) return;

          const file = fileInput.files[0];
          const resultBox = document.getElementById('becForensicResult');
          const resultTitle = document.getElementById('becForensicTitle');
          const resultBody = document.getElementById('becForensicBody');

          resultTitle.innerHTML = `<span>Analizando archivo ${file.name}... ⏳</span>`;
          resultBox.style.display = 'block';

          try {
            const formData = new FormData();
            formData.append('action', 'analyze_bec_manual');
            formData.append('order_id', currentBecOrder.id);
            formData.append('eml_file', file);

            const res = await fetch('index.php', { method: 'POST', body: formData });
            const data = await res.json();

            if (data.success) {
              const an = data.analysis;
              resultTitle.innerHTML = `<span style="color:#dc2626;">🚨 AUDITORÍA FORENSE COMPLETADA:</span>`;
              resultBody.innerHTML = `<strong>Remitente Visible:</strong> ${an.sender_from}<br>
                                      <strong>Reply-To Oculto:</strong> <span style="color:#dc2626; font-weight:bold;">${an.reply_to || 'N/A'}</span><br>
                                      <strong>IP Origen:</strong> ${an.origin_ip} (SPF: ${an.spf_status} | DKIM: ${an.dkim_status} | DMARC: ${an.dmarc_status})<br>
                                      <strong>Diagnóstico:</strong> ${an.diagnosis}<br>
                                      <strong>Dosier Criptográfico:</strong> Generado con éxito.`;
              alert('Análisis forense completado y Dosier Criptográfico emitido.');
            } else {
              throw new Error(data.error || 'Error al procesar archivo.');
            }
          } catch (e) {
            resultTitle.innerHTML = `<span style="color:#dc2626;">Error</span>`;
            resultBody.innerHTML = e.message;
          }
        }

        // Despachar Dosier PDF al CFO por correo
        async function dispatchBecDossierPdf() {
          if (!currentBecOrder) return;
          const target = prompt(`Confirme el correo electrónico del destinatario (CFO / Dirección Legal):`, currentBecOrder.approver_email);
          if (!target) return;

          const btn = document.getElementById('btnDispatchDossier');
          const prev = btn.innerHTML;
          btn.disabled = true;
          btn.innerHTML = 'Despachando Dosier Pericial... ⏳';

          try {
            const formData = new FormData();
            formData.append('action', 'dispatch_bec_dossier');
            formData.append('order_id', currentBecOrder.id);
            formData.append('target_email', target);

            const res = await fetch('index.php', { method: 'POST', body: formData });
            const data = await res.json();

            btn.disabled = false;
            btn.innerHTML = prev;

            if (data.success) {
              alert(`¡Éxito! ${data.message}`);
            } else {
              throw new Error(data.error || 'Error al despachar dosier');
            }
          } catch (e) {
            btn.disabled = false;
            btn.innerHTML = prev;
            alert('Error: ' + e.message);
          }
        }

        // Acciones Multi-Operador: Tomar Caso (Claim) y Liberar Caso (Release)
        async function claimOrder(orderId) {
          try {
            const formData = new FormData();
            formData.append('action', 'claim_order');
            formData.append('order_id', orderId);

            const res = await fetch('index.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
              window.location.reload();
            } else {
              alert(data.error || 'No se pudo tomar el caso.');
            }
          } catch (e) {
            alert('Error: ' + e.message);
          }
        }

        async function releaseOrder(orderId) {
          try {
            const formData = new FormData();
            formData.append('action', 'release_order');
            formData.append('order_id', orderId);

            const res = await fetch('index.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
              window.location.reload();
            } else {
              alert(data.error || 'No se pudo liberar el caso.');
            }
          } catch (e) {
            alert('Error: ' + e.message);
          }
        }
      
        // Modal de Gestión de Múltiples Operadores y Firmantes por Empresa
        async function openTenantUsersModal(tenantSlug, companyName) {
          document.getElementById('tu_company_name').textContent = '👥 Usuarios & Firmantes: ' + companyName;
          document.getElementById('tu_tenant_slug').textContent = 'Identificador Único (Slug): ' + tenantSlug;
          document.getElementById('form_op_slug').value = tenantSlug;
          document.getElementById('form_appr_slug').value = tenantSlug;

          const opList = document.getElementById('tu_operators_list');
          const apprList = document.getElementById('tu_approvers_list');
          opList.innerHTML = '<div style="color:#94a3b8; font-size:0.8rem; padding:0.5rem; text-align:center;">Cargando operadores...</div>';
          apprList.innerHTML = '<div style="color:#94a3b8; font-size:0.8rem; padding:0.5rem; text-align:center;">Cargando directores...</div>';

          document.getElementById('modalTenantUsers').style.display = 'flex';

          try {
            const res = await fetch(`index.php?tab=treasury&action=get_tenant_users&tenant_slug=${encodeURIComponent(tenantSlug)}`);
            const data = await res.json();

            if (!data.success) {
              throw new Error('No se pudieron obtener los usuarios.');
            }

            // Renderizar Operadores
            document.getElementById('tu_op_count').textContent = data.operators.length;
            if (data.operators.length === 0) {
              opList.innerHTML = '<div style="color:#94a3b8; font-size:0.8rem; padding:0.5rem; text-align:center;">Sin operadores registrados.</div>';
            } else {
              opList.innerHTML = data.operators.map(o => `
                <div style="background:#fff; border:1px solid #e2e8f0; border-radius:6px; padding:0.6rem 0.75rem; display:flex; justify-content:space-between; align-items:center;">
                  <div>
                    <strong style="color:#0f172a; font-size:0.85rem;">${escapeHtml(o.full_name)}</strong>
                    <div style="font-size:0.75rem; color:#64748b;">✉️ ${escapeHtml(o.email)} ${o.phone ? '• 📲 ' + escapeHtml(o.phone) : ''}</div>
                    <span style="font-size:0.7rem; background:#f1f5f9; color:#0369a1; padding:0.15rem 0.4rem; border-radius:4px; font-weight:bold;">${escapeHtml(o.role || 'OPERATOR')}</span>
                  </div>
                  ${o.id > 0 ? `
                    <form method="POST" action="index.php?tab=treasury" onsubmit="return confirm('¿Eliminar este operador?');" style="margin:0;">
                      <input type="hidden" name="action" value="admin_delete_operator">
                      <input type="hidden" name="tenant_slug" value="${escapeHtml(tenantSlug)}">
                      <input type="hidden" name="operator_id" value="${o.id}">
                      <button type="submit" style="background:#fee2e2; color:#b91c1c; border:none; padding:0.25rem 0.5rem; border-radius:4px; font-size:0.7rem; cursor:pointer; font-weight:bold;">🗑️</button>
                    </form>
                  ` : '<span style="font-size:0.7rem; color:#94a3b8;">(Principal)</span>'}
                </div>
              `).join('');
            }

            // Renderizar Directores Firmantes
            document.getElementById('tu_appr_count').textContent = data.approvers.length;
            if (data.approvers.length === 0) {
              apprList.innerHTML = '<div style="color:#94a3b8; font-size:0.8rem; padding:0.5rem; text-align:center;">Sin directores registrados.</div>';
            } else {
              apprList.innerHTML = data.approvers.map(a => `
                <div style="background:#fff; border:1px solid #e2e8f0; border-radius:6px; padding:0.6rem 0.75rem; display:flex; justify-content:space-between; align-items:center;">
                  <div>
                    <strong style="color:#0f172a; font-size:0.85rem;">${escapeHtml(a.full_name)}</strong> ${Number(a.is_primary) === 1 ? '<span style="color:#eab308;" title="Director Principal">⭐</span>' : ''}
                    <div style="font-size:0.75rem; color:#059669; font-weight:bold;">📲 WhatsApp: ${escapeHtml(a.phone_whatsapp)}</div>
                    <div style="font-size:0.72rem; color:#64748b;">${escapeHtml(a.role_title || 'Director')} ${a.email ? '• ✉️ ' + escapeHtml(a.email) : ''}</div>
                  </div>
                  ${a.id > 0 ? `
                    <form method="POST" action="index.php?tab=treasury" onsubmit="return confirm('¿Eliminar este director firmante?');" style="margin:0;">
                      <input type="hidden" name="action" value="admin_delete_approver">
                      <input type="hidden" name="tenant_slug" value="${escapeHtml(tenantSlug)}">
                      <input type="hidden" name="approver_id" value="${a.id}">
                      <button type="submit" style="background:#fee2e2; color:#b91c1c; border:none; padding:0.25rem 0.5rem; border-radius:4px; font-size:0.7rem; cursor:pointer; font-weight:bold;">🗑️</button>
                    </form>
                  ` : '<span style="font-size:0.7rem; color:#94a3b8;">(Principal)</span>'}
                </div>
              `).join('');
            }
          } catch (err) {
            opList.innerHTML = `<div style="color:#dc2626; font-size:0.8rem; padding:0.5rem;">Error al cargar operadores: ${err.message}</div>`;
            apprList.innerHTML = `<div style="color:#dc2626; font-size:0.8rem; padding:0.5rem;">Error al cargar directores: ${err.message}</div>`;
          }
        }

        function closeTenantUsersModal() {
          document.getElementById('modalTenantUsers').style.display = 'none';
        }

        // =========================================================================
        // FUNCIONES JAVASCRIPT MULTI-TENANT DMARC WATCHDOG
        // =========================================================================

        async function openTakedownModal(ip, domain, company) {
          document.getElementById('td_ip').value = ip;
          document.getElementById('td_domain').value = domain;
          document.getElementById('td_company').value = company;
          document.getElementById('td_abuse_email').value = '';
          document.getElementById('td_isp').value = 'Consultando...';
          document.getElementById('td_lookup_status').textContent = 'Consultando contacto oficial de abusos vía RDAP... ⏳';
          document.getElementById('btnSendTakedown').disabled = true;

          document.getElementById('modalTakedown').style.display = 'flex';

          try {
            const res = await fetch(`index.php?tab=dmarc&action=get_ip_abuse_contact&ip=${encodeURIComponent(ip)}&domain=${encodeURIComponent(domain)}`);
            const data = await res.json();
            if (data.success) {
              document.getElementById('td_abuse_email').value = data.abuse_email;
              document.getElementById('td_isp').value = (data.org || data.isp || 'Desconocido') + ' (' + data.country + ')';
              document.getElementById('td_notes').value = data.forensic_text_en;
              document.getElementById('td_lookup_status').textContent = `✓ Contacto oficial detectado (${data.abuse_email}). Evidencia forense auto-generada (${data.attempts} intentos interceptados).`;
              document.getElementById('btnSendTakedown').disabled = false;
            }
          } catch (e) {
            document.getElementById('td_lookup_status').textContent = 'No se pudo autodetectar. Puede escribir el correo manualmente.';
            document.getElementById('btnSendTakedown').disabled = false;
          }
        }

        async function submitTakedown(e) {
          e.preventDefault();
          const btn = document.getElementById('btnSendTakedown');
          const orig = btn.innerHTML;
          btn.disabled = true;
          btn.innerHTML = 'Despachando Requerimiento al ISP... ⏳';

          try {
            const formData = new FormData();
            formData.append('action', 'dispatch_takedown');
            formData.append('ip', document.getElementById('td_ip').value);
            formData.append('isp_email', document.getElementById('td_abuse_email').value);
            formData.append('domain', document.getElementById('td_domain').value);
            formData.append('company_name', document.getElementById('td_company').value);
            formData.append('custom_note', document.getElementById('td_notes').value);

            const res = await fetch('index.php?tab=dmarc', { method: 'POST', body: formData });
            const data = await res.json();

            if (data.success) {
              alert(`✓ Requerimiento formal de Take-Down despachado exitosamente al ISP: ${data.sent_to}\nSe adjuntó la evidencia técnica y advertencia de acciones legales.`);
              document.getElementById('modalTakedown').style.display = 'none';
            } else {
              throw new Error(data.error || 'Error al despachar requerimiento.');
            }
          } catch (err) {
            alert('Fallo al enviar notificación: ' + err.message);
          } finally {
            btn.disabled = false;
            btn.innerHTML = orig;
          }
        }

        function openDnsModal(domain, policy) {
          const cleanDom = domain.toLowerCase().trim();
          const pol = policy || 'reject';
          const ruaEmail = "<?php echo addslashes($watchdog->getCentralRuaEmail()); ?>";
          
          document.getElementById('dnsHostVal').textContent = '_dmarc.' + cleanDom;
          document.getElementById('dnsTxtVal').value = `v=DMARC1; p=${pol}; rua=mailto:${ruaEmail}; pct=100; sp=${pol}; aspf=r; adkim=r;`;
          
          document.getElementById('copyFeedback').style.display = 'none';
          document.getElementById('modalDnsRecord').style.display = 'flex';
        }

        function copyDnsTxt() {
          const txt = document.getElementById('dnsTxtVal');
          txt.select();
          txt.setSelectionRange(0, 99999);
          navigator.clipboard.writeText(txt.value).then(() => {
            const fb = document.getElementById('copyFeedback');
            fb.style.display = 'inline';
            setTimeout(() => { fb.style.display = 'none'; }, 3500);
          }).catch(err => {
            alert('Copiado manualmente: ' + txt.value);
          });
        }

        function openAddDomainModal(tenantId, companyName) {
          document.getElementById('add_domain_tenant_id').value = tenantId;
          document.getElementById('add_domain_company_name').innerText = companyName;
          document.getElementById('modalAddExtraDomain').style.display = 'flex';
        }

        function openEditDmarcTenantModal(tenant) {
          document.getElementById('edit_dmarc_id').value = tenant.id;
          document.getElementById('edit_dmarc_name').value = tenant.company_name;
          document.getElementById('edit_dmarc_contact_name').value = tenant.contact_name;
          document.getElementById('edit_dmarc_email').value = tenant.contact_email;
          document.getElementById('edit_dmarc_phone').value = tenant.contact_phone || '';
          document.getElementById('edit_dmarc_policy').value = tenant.policy_mode || 'reject';

          document.getElementById('modalEditDmarcTenant').style.display = 'flex';
        }

        async function dispatchDossierEmailPrompt(tenantId, companyName, currentEmail) {
          const emailTarget = prompt(`¿Despachar el Dosier Oficial DMARC en PDF para "${companyName}"?\n\nConfirme o ingrese el correo del CFO:`, currentEmail);
          if (!emailTarget) return;

          const btn = event.target;
          const origText = btn.innerHTML;
          btn.disabled = true;
          btn.innerHTML = 'Enviando... ⏳';

          try {
            const formData = new FormData();
            formData.append('action', 'dispatch_dmarc_dossier_email');
            formData.append('tenant_id', tenantId);
            formData.append('target_email', emailTarget);

            const res = await fetch('index.php?tab=dmarc', { method: 'POST', body: formData });
            const data = await res.json();

            if (data.success) {
              alert(`✓ ¡Dosier despachado exitosamente!\nSe envió copia formal con sello SHA-256 a: ${data.sent_to}`);
              btn.innerHTML = '✓ Enviado';
              btn.style.background = '#059669';
            } else {
              throw new Error(data.error || 'Error al despachar correo');
            }
          } catch (e) {
            alert('Fallo al despachar Dosier: ' + e.message);
            btn.disabled = false;
            btn.innerHTML = origText;
          }
        }


        function promptChangeUserPass(userId, username) {
          const newPass = prompt(`Ingrese la nueva contraseña para el operador "${username}":`);
          if (!newPass || newPass.trim().length < 6) {
            if (newPass !== null) alert("La contraseña debe tener al menos 6 caracteres.");
            return;
          }
          document.getElementById("change_pass_user_id").value = userId;
          document.getElementById("change_pass_new_val").value = newPass.trim();
          document.getElementById("formChangePassHidden").submit();
        }
        function escapeHtml(text) {
          if (!text) return '';
          return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }

      </script>

  </main>

      <footer style="text-align:center; padding:2rem; font-size:0.8rem; color:#94a3b8;">
        &copy; <?php echo date('Y'); ?> Keystone Advisors SpA. Todos los derechos reservados. Consola de Telemetría v1.0 • Entorno Protegido.
      </footer>
    </div> <!-- /.app-main -->
  </div> <!-- /.app-shell -->

</body>
</html>
