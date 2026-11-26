<?php
/**
 * Keystone Command Console v4.1 — Core Database, Auth, RBAC, TOTP 2FA, SMTP & WhatsApp Engine (SOL-0335)
 * Ubicación aislada para Staging: ../../dev_leads_data/spatial_leads.sqlite
 */

class AdminCore {
    private static ?PDO $pdo = null;

    public static function sendHeaders(): void {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
    }

    public static function startSecureSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['SERVER_PORT'] ?? '') == 443);
            @session_start([
                'name' => 'KS_SPATIAL_ADMIN_SID',
                'cookie_httponly' => true,
                'cookie_secure' => $isHttps,
                'cookie_samesite' => 'Lax',
                'gc_maxlifetime' => 14400,
            ]);
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
        }
    }

    public static function detectEnvTier(): string {
        $dirPath = str_replace('\\', '/', __DIR__);
        if (strpos($dirPath, '/public_html/dev/') !== false) {
            return 'DEV';
        }
        if (strpos($dirPath, '/public_html/qa/') !== false) {
            return 'QA';
        }
        $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
        if (strpos($host, 'dev.') === 0) {
            return 'DEV';
        }
        if (strpos($host, 'qa.') === 0) {
            return 'QA';
        }
        return 'PROD';
    }

    public static function getPublicHtmlRoot(): string {
        // En subdirectorios (public_html/dev/api/admin o public_html/qa/api/admin) -> dirname(__DIR__, 3) = public_html
        // En raíz de producción (public_html/api/admin) -> dirname(__DIR__, 2) = public_html
        $dirPath = str_replace('\\', '/', __DIR__);
        if (strpos($dirPath, '/public_html/dev/') !== false || strpos($dirPath, '/public_html/qa/') !== false) {
            return dirname(__DIR__, 3);
        }
        return dirname(__DIR__, 2);
    }

    public static function getDataDir(): string {
        $tier = self::detectEnvTier();
        $root = self::getPublicHtmlRoot();
        if ($tier === 'DEV') {
            $dataDir = $root . '/dev_leads_data';
        } elseif ($tier === 'QA') {
            $dataDir = $root . '/qa_leads_data';
        } else {
            // Para Producción: carpeta protegida fuera de sobreescritura estática
            $dataDir = $root . '/prod_leads_data';
        }
        if (!is_dir($dataDir)) {
            @mkdir($dataDir, 0750, true);
        }
        $htFile = $dataDir . '/.htaccess';
        if (!is_file($htFile)) {
            @file_put_contents($htFile, "Require all denied\nDeny from all\n");
        }
        return $dataDir;
    }

    public static function db(): PDO {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $dbPath = self::getDataDir() . '/spatial_leads.sqlite';
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode = WAL;');
        $pdo->exec('PRAGMA foreign_keys = ON;');

        self::initSchema($pdo);
        self::$pdo = $pdo;
        return $pdo;
    }

    private static function initSchema(PDO $db): void {
        // 1. Usuarios administrativos con RBAC + 2FA TOTP + Google SSO
        $db->exec("CREATE TABLE IF NOT EXISTS admin_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            email TEXT DEFAULT '',
            password_hash TEXT NOT NULL,
            full_name TEXT DEFAULT '',
            role TEXT DEFAULT 'comercial',
            totp_secret TEXT DEFAULT '',
            totp_enabled INTEGER DEFAULT 0,
            google_sub TEXT DEFAULT '',
            is_active INTEGER DEFAULT 1,
            last_login DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $uCols = array_column($db->query("PRAGMA table_info(admin_users)")->fetchAll(), 'name');
        if (!in_array('email', $uCols, true)) {
            $db->exec("ALTER TABLE admin_users ADD COLUMN email TEXT DEFAULT ''");
        }
        if (!in_array('totp_secret', $uCols, true)) {
            $db->exec("ALTER TABLE admin_users ADD COLUMN totp_secret TEXT DEFAULT ''");
        }
        if (!in_array('totp_enabled', $uCols, true)) {
            $db->exec("ALTER TABLE admin_users ADD COLUMN totp_enabled INTEGER DEFAULT 0");
        }
        if (!in_array('google_sub', $uCols, true)) {
            $db->exec("ALTER TABLE admin_users ADD COLUMN google_sub TEXT DEFAULT ''");
        }

        // 2. Rate Limiting anti fuerza bruta
        $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip_address TEXT NOT NULL,
            username TEXT DEFAULT '',
            attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // 3. Micro-leads del sitio espacial + trazabilidad KeyHub
        $db->exec("CREATE TABLE IF NOT EXISTS micro_leads (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_ref TEXT NOT NULL,
            action TEXT NOT NULL,
            email TEXT NOT NULL,
            domain TEXT,
            pillar TEXT,
            service_name TEXT,
            price_info TEXT,
            scan_summary TEXT,
            status TEXT DEFAULT 'NUEVO',
            priority TEXT DEFAULT 'ALTA',
            assigned_to TEXT DEFAULT '',
            notes TEXT DEFAULT '',
            keyhub_card_code TEXT DEFAULT '',
            keyhub_project_key TEXT DEFAULT '',
            keyhub_synced_at DATETIME,
            ip_address TEXT,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $leadCols = array_column($db->query("PRAGMA table_info(micro_leads)")->fetchAll(), 'name');
        foreach ([
            'status' => "TEXT DEFAULT 'NUEVO'",
            'priority' => "TEXT DEFAULT 'ALTA'",
            'assigned_to' => "TEXT DEFAULT ''",
            'notes' => "TEXT DEFAULT ''",
            'keyhub_card_code' => "TEXT DEFAULT ''",
            'keyhub_project_key' => "TEXT DEFAULT ''",
            'keyhub_synced_at' => "DATETIME",
            'updated_at' => "DATETIME",
        ] as $cName => $cDef) {
            if (!in_array($cName, $leadCols, true)) {
                $db->exec("ALTER TABLE micro_leads ADD COLUMN {$cName} {$cDef}");
            }
        }

        // 4. Escaneos pasivos BEC
        $db->exec("CREATE TABLE IF NOT EXISTS bec_scans (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            scan_ref TEXT NOT NULL,
            domain TEXT NOT NULL,
            email TEXT,
            score INTEGER NOT NULL,
            risk_level TEXT NOT NULL,
            dmarc_label TEXT,
            spf_status TEXT,
            mx_status TEXT DEFAULT '',
            tls_status TEXT DEFAULT '',
            ip_address TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $scanCols = array_column($db->query("PRAGMA table_info(bec_scans)")->fetchAll(), 'name');
        if (!in_array('mx_status', $scanCols, true)) {
            $db->exec("ALTER TABLE bec_scans ADD COLUMN mx_status TEXT DEFAULT ''");
        }
        if (!in_array('tls_status', $scanCols, true)) {
            $db->exec("ALTER TABLE bec_scans ADD COLUMN tls_status TEXT DEFAULT ''");
        }

        // 5. Configuración viva del sitio, SMTP, WhatsApp y OAuth
        $db->exec("CREATE TABLE IF NOT EXISTS site_settings (
            setting_key TEXT PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_by TEXT DEFAULT 'system',
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // 6. Dominios corporativos bajo vigilancia activa
        $db->exec("CREATE TABLE IF NOT EXISTS watched_domains (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            domain TEXT UNIQUE NOT NULL,
            client_name TEXT NOT NULL,
            tier TEXT DEFAULT 'Enterprise',
            last_score INTEGER DEFAULT 100,
            dmarc_policy TEXT DEFAULT 'Verificando',
            spf_status TEXT DEFAULT 'Verificando',
            tls_status TEXT DEFAULT 'Verificando',
            is_active INTEGER DEFAULT 1,
            badge_installed INTEGER DEFAULT 0,
            badge_impressions INTEGER DEFAULT 0,
            badge_last_origin TEXT DEFAULT '',
            badge_last_seen_at DATETIME,
            last_checked_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $wdCols = array_column($db->query("PRAGMA table_info(watched_domains)")->fetchAll(), 'name');
        foreach ([
            'badge_installed' => "INTEGER DEFAULT 0",
            'badge_impressions' => "INTEGER DEFAULT 0",
            'badge_last_origin' => "TEXT DEFAULT ''",
            'badge_last_seen_at' => "DATETIME",
        ] as $cName => $cDef) {
            if (!in_array($cName, $wdCols, true)) {
                $db->exec("ALTER TABLE watched_domains ADD COLUMN {$cName} {$cDef}");
            }
        }

        // 7. Órdenes DeepShield Treasury OOB (en staging DB con tenant_slug incluido)
        $db->exec("CREATE TABLE IF NOT EXISTS deepshield_orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_code TEXT UNIQUE NOT NULL,
            tenant_slug TEXT DEFAULT '',
            company_name TEXT NOT NULL,
            request_type TEXT NOT NULL DEFAULT 'PAYMENT_TRANSFER',
            amount REAL NOT NULL,
            currency TEXT NOT NULL DEFAULT 'CLP',
            beneficiary_name TEXT NOT NULL,
            beneficiary_rut_dni TEXT NOT NULL,
            bank_name TEXT NOT NULL,
            account_number TEXT NOT NULL,
            account_type TEXT NOT NULL DEFAULT 'Cuenta Corriente',
            invoice_folio TEXT DEFAULT '',
            approver_name TEXT NOT NULL,
            approver_email TEXT NOT NULL,
            approver_phone TEXT DEFAULT '',
            token_hash TEXT UNIQUE NOT NULL,
            status TEXT NOT NULL DEFAULT 'PENDING_APPROVAL',
            security_challenge TEXT NOT NULL,
            approval_method TEXT,
            approved_by_ip TEXT,
            approved_user_agent TEXT,
            approved_at DATETIME,
            cryptographic_signature TEXT,
            audit_notes TEXT,
            assigned_to TEXT,
            assigned_at DATETIME,
            operator_notes TEXT,
            expires_at DATETIME NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $ordCols = array_column($db->query("PRAGMA table_info(deepshield_orders)")->fetchAll(), 'name');
        foreach ([
            'whitelist_status' => "TEXT DEFAULT 'UNVERIFIED'",
            'whitelist_detail' => "TEXT DEFAULT ''",
            'required_approvals' => "INTEGER DEFAULT 1",
            'approval_count' => "INTEGER DEFAULT 0",
            'secondary_approver_name' => "TEXT DEFAULT ''",
            'secondary_approver_phone' => "TEXT DEFAULT ''",
            'secondary_token_hash' => "TEXT DEFAULT ''",
            'approvals_log_json' => "TEXT DEFAULT '[]'",
        ] as $cName => $cDef) {
            if (!in_array($cName, $ordCols, true)) {
                $db->exec("ALTER TABLE deepshield_orders ADD COLUMN {$cName} {$cDef}");
            }
        }

        // 8. Reportes agregados DMARC RUA (RFC 7489)
        $db->exec("CREATE TABLE IF NOT EXISTS dmarc_reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            report_id TEXT UNIQUE NOT NULL,
            org_name TEXT NOT NULL,
            email TEXT DEFAULT '',
            domain TEXT NOT NULL,
            policy_p TEXT DEFAULT 'none',
            policy_pct INTEGER DEFAULT 100,
            date_begin DATETIME NOT NULL,
            date_end DATETIME NOT NULL,
            total_messages INTEGER DEFAULT 0,
            total_pass INTEGER DEFAULT 0,
            total_fail INTEGER DEFAULT 0,
            spoofing_ips INTEGER DEFAULT 0,
            raw_filename TEXT DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // 9. Campañas y Simulaciones IA Red Teaming
        $db->exec("CREATE TABLE IF NOT EXISTS redteam_campaigns (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company_name TEXT NOT NULL,
            contact_name TEXT NOT NULL,
            contact_email TEXT NOT NULL,
            plan_type TEXT DEFAULT 'PROFESSIONAL',
            target_domains TEXT NOT NULL,
            target_users_count INTEGER DEFAULT 15,
            simulated_vectors TEXT NOT NULL DEFAULT 'BEC_CEO_FRAUD,SUPPLIER_ACCOUNT_CHANGE',
            status TEXT DEFAULT 'PLANNING',
            last_lure_subject TEXT DEFAULT '',
            last_lure_body TEXT DEFAULT '',
            notes TEXT DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // 10. Bitácora de despachos SMTP & WhatsApp
        $db->exec("CREATE TABLE IF NOT EXISTS dispatch_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            channel TEXT NOT NULL,
            recipient TEXT NOT NULL,
            subject_or_ref TEXT DEFAULT '',
            status TEXT NOT NULL,
            detail TEXT DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // 11. Bitácora de auditoría ejecutiva
        $db->exec("CREATE TABLE IF NOT EXISTS admin_audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            actor TEXT NOT NULL,
            action_code TEXT NOT NULL,
            target_ref TEXT DEFAULT '',
            detail TEXT DEFAULT '',
            ip_address TEXT DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // 12. Empresas Clientes Multi-Tenant DeepShield + Proveedores Whitelist
        $db->exec("CREATE TABLE IF NOT EXISTS deepshield_tenants (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_slug TEXT UNIQUE NOT NULL,
            company_name TEXT NOT NULL,
            rut_dni TEXT NOT NULL,
            threshold_amount REAL DEFAULT 2000000,
            threshold_currency TEXT DEFAULT 'CLP',
            primary_approver_name TEXT NOT NULL,
            primary_approver_email TEXT NOT NULL,
            primary_approver_phone TEXT DEFAULT '',
            treasury_operator_name TEXT DEFAULT 'Tesorero Corporativo',
            treasury_operator_email TEXT NOT NULL,
            treasury_operator_phone TEXT DEFAULT '',
            treasury_operator_password TEXT NOT NULL,
            whatsapp_instance TEXT DEFAULT 'keystone-general',
            client_api_key TEXT UNIQUE NOT NULL,
            status TEXT DEFAULT 'ACTIVE',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $tenCols = array_column($db->query("PRAGMA table_info(deepshield_tenants)")->fetchAll(), 'name');
        foreach ([
            'secondary_approver_name' => "TEXT DEFAULT ''",
            'secondary_approver_email' => "TEXT DEFAULT ''",
            'secondary_approver_phone' => "TEXT DEFAULT ''",
            'dual_approval_threshold' => "REAL DEFAULT 0",
        ] as $cName => $cDef) {
            if (!in_array($cName, $tenCols, true)) {
                $db->exec("ALTER TABLE deepshield_tenants ADD COLUMN {$cName} {$cDef}");
            }
        }

        $db->exec("CREATE TABLE IF NOT EXISTS deepshield_whitelist (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_slug TEXT NOT NULL,
            supplier_name TEXT NOT NULL,
            supplier_rut TEXT NOT NULL,
            bank_name TEXT NOT NULL,
            account_number TEXT NOT NULL,
            verified_by TEXT DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // 13. Bandeja Espejo de Respuestas Entrantes IMAP
        $db->exec("CREATE TABLE IF NOT EXISTS email_replies (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            message_id TEXT UNIQUE NOT NULL,
            sender_name TEXT DEFAULT '',
            sender_email TEXT NOT NULL,
            subject TEXT DEFAULT '',
            snippet TEXT DEFAULT '',
            linked_lead_ref TEXT DEFAULT '',
            received_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // 14. Growth, Campañas Outbound & LinkedIn IA
        $db->exec("CREATE TABLE IF NOT EXISTS growth_campaigns (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            campaign_name TEXT NOT NULL,
            channel TEXT NOT NULL DEFAULT 'LINKEDIN',
            pillar_focus TEXT DEFAULT 'Digital Core',
            target_audience TEXT DEFAULT '',
            ai_copy_headline TEXT DEFAULT '',
            ai_copy_body TEXT DEFAULT '',
            budget_usd REAL DEFAULT 0,
            status TEXT DEFAULT 'READY',
            published_url TEXT DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $rtCampCols = array_column($db->query("PRAGMA table_info(redteam_campaigns)")->fetchAll(), 'name');
        foreach ([
            'lure_cta_label' => "TEXT DEFAULT 'REVISAR SOLICITUD EN PORTAL INTERNO'",
            'lure_accent_color' => "TEXT DEFAULT '#0F172A'",
            'custom_html_body' => "TEXT DEFAULT ''",
        ] as $cName => $cDef) {
            if (!in_array($cName, $rtCampCols, true)) {
                $db->exec("ALTER TABLE redteam_campaigns ADD COLUMN {$cName} {$cDef}");
            }
        }

        // 18. Biblioteca Reutilizable de Plantillas HTML de Concientización & Cliente (SOL-0344)
        $db->exec("CREATE TABLE IF NOT EXISTS redteam_templates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            client_scope TEXT DEFAULT 'GLOBAL',
            template_name TEXT NOT NULL,
            category TEXT DEFAULT 'Corporativo',
            subject TEXT NOT NULL,
            body_text TEXT DEFAULT '',
            custom_html TEXT DEFAULT '',
            cta_label TEXT DEFAULT 'REVISAR DOCUMENTO EN LINEA',
            accent_color TEXT DEFAULT '#0F172A',
            created_by TEXT DEFAULT 'system',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        if ((int)$db->query("SELECT COUNT(*) FROM redteam_templates")->fetchColumn() === 0) {
            $seedTpl = $db->prepare("
                INSERT INTO redteam_templates (client_scope, template_name, category, subject, body_text, custom_html, cta_label, accent_color, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'system')
            ");
            $seedTpl->execute([
                'GLOBAL',
                'Expediente / Carpeta Tributaria Electrónica (Formato Institucional)',
                'Tributario / Cumplimiento',
                'NOTIFICACIÓN DE EXPEDIENTE TRIBUTARIO ELECTRÓNICO — {{EMPRESA}}',
                "Estimado(a) {{NOMBRE}},\n\nSe ha puesto a disposición para revisión de cumplimiento un expediente electrónico de Carpeta Tributaria y consistencia de declaraciones mensuales asociado a {{EMPRESA}}.\n\nPara verificar folios y validar consistencia contable del período vigente, ingrese al acceso documental seguro.",
                '',
                'Revisar Expediente Tributario ->',
                '#004B93',
            ]);
            $seedTpl->execute([
                'GLOBAL',
                'Aviso de Transferencia Saliente Alto Monto (Tesorería)',
                'Tesorería / Finanzas',
                'Aviso de Instrucción de Transferencia de Fondos — {{EMPRESA}}',
                "Estimado(a) {{NOMBRE}} ({{DEPARTAMENTO}}),\n\nSe ha registrado en bandeja una instrucción de transferencia electrónica saliente por CLP $14.850.000 con cargo a la cuenta corriente operativa de {{EMPRESA}}.\n\nSi usted reconoce esta operación o desea auditar el comprobante antes del corte de las 14:00 hrs, revise el detalle a continuación.",
                '',
                'Ver Detalle de Transferencia',
                '#B91C1C',
            ]);
            $seedTpl->execute([
                'GLOBAL',
                'Cartola Mensual e Informe de Liquidación Cifrado',
                'Inversiones / Finanzas',
                'Estado de Cuenta y Cartola Mensual de Fondos — {{EMPRESA}}',
                "Estimado(a) {{NOMBRE}},\n\nYa se encuentra emitido el estado consolidado de fondos y movimientos de tesorería del cierre mensual de {{EMPRESA}}.\n\nAcceda al visor documental interno para descargar el respaldo de conciliación.",
                '',
                'Abrir Cartola del Período',
                '#1E3A8A',
            ]);
            $seedTpl->execute([
                'GLOBAL',
                'Actualización Urgente de Cuenta Corriente Proveedor (Anti-BEC)',
                'Proveedores / Pagos',
                'IMPORTANTE: Nueva Cuenta Receptora para Pago de Facturas — {{EMPRESA}}',
                "Estimado equipo de {{DEPARTAMENTO}} de {{EMPRESA}},\n\nPor reestructuración bancaria informamos el cambio de nuestra cuenta corriente receptora para las facturas con vencimiento esta semana. Rogamos actualizar nuestro maestro y confirmar recepción.",
                '',
                'Validar Certificado Bancario Adjunto',
                '#047857',
            ]);
            $seedTpl->execute([
                'GLOBAL',
                'Instrucción Reservada de Directorio / Pretexto Anti-Voz',
                'Ejecutivo / Directorio',
                'RESERVADO COMITÉ: Gestión Prioritaria antes de las 14:00 hrs — {{EMPRESA}}',
                "Hola {{NOMBRE}},\n\nEstoy entrando a sesión de directorio sin señal telefónica estable. Necesito dejar validado hoy un anticipo reservado para consultores legales de {{EMPRESA}}. Revisa los antecedentes en el enlace interno.",
                '',
                'Revisar Antecedentes Reservados',
                '#4C1D95',
            ]);
        }

        // Poblar maquetas HTML transaccionales genéricas descargables en las plantillas base que aún tengan custom_html vacío (SOL-0345)
        $emptyHtmlTpls = $db->query("SELECT id, category, template_name, body_text, cta_label, accent_color FROM redteam_templates WHERE created_by = 'system' AND (custom_html IS NULL OR custom_html = '')")->fetchAll();
        if (!empty($emptyHtmlTpls)) {
            $updHtml = $db->prepare("UPDATE redteam_templates SET custom_html = ? WHERE id = ?");
            foreach ($emptyHtmlTpls as $et) {
                $acc = $et['accent_color'] ?: '#0F172A';
                $cta = htmlspecialchars((string)$et['cta_label'], ENT_QUOTES, 'UTF-8');
                $cat = htmlspecialchars((string)$et['category'], ENT_QUOTES, 'UTF-8');
                $bTxt = nl2br(htmlspecialchars((string)$et['body_text'], ENT_QUOTES, 'UTF-8'));
                $htmlTpl = "<!DOCTYPE html>\n<html lang=\"es\">\n<head><meta charset=\"utf-8\"></head>\n"
                    . "<body style=\"font-family:Arial,Helvetica,sans-serif;background:#F1F5F9;margin:0;padding:24px;color:#1E293B;\">\n"
                    . "  <table width=\"620\" align=\"center\" cellpadding=\"0\" cellspacing=\"0\" style=\"background:#FFFFFF;border:1px solid #CBD5E1;border-radius:6px;overflow:hidden;\">\n"
                    . "    <tr>\n"
                    . "      <td style=\"background:{$acc};color:#FFFFFF;padding:18px 24px;\">\n"
                    . "        <div style=\"font-size:16px;font-weight:bold;letter-spacing:0.3px;\">PORTAL DOCUMENTAL INTERNO · {{EMPRESA}}</div>\n"
                    . "        <div style=\"font-size:11px;opacity:0.85;margin-top:2px;\">Módulo: {$cat}</div>\n"
                    . "      </td>\n"
                    . "    </tr>\n"
                    . "    <tr>\n"
                    . "      <td style=\"padding:24px;\">\n"
                    . "        <div style=\"font-size:13.5px;line-height:1.6;color:#334155;\">{$bTxt}</div>\n"
                    . "        <table width=\"100%\" cellpadding=\"8\" cellspacing=\"0\" style=\"margin:18px 0;border:1px solid #E2E8F0;background:#F8FAFC;font-size:12px;\">\n"
                    . "          <tr><td style=\"border-bottom:1px solid #E2E8F0;color:#64748B;width:38%;\">Organización</td><td style=\"border-bottom:1px solid #E2E8F0;font-weight:bold;\">{{EMPRESA}}</td></tr>\n"
                    . "          <tr><td style=\"border-bottom:1px solid #E2E8F0;color:#64748B;\">Área Destinataria</td><td style=\"border-bottom:1px solid #E2E8F0;\">{{DEPARTAMENTO}} ({{CORREO}})</td></tr>\n"
                    . "          <tr><td style=\"color:#64748B;\">Estado de Revisión</td><td style=\"font-weight:bold;color:{$acc};\">Pendiente de Confirmación</td></tr>\n"
                    . "        </table>\n"
                    . "        <div style=\"text-align:center;margin:22px 0;\">\n"
                    . "          <a href=\"{{LINK_CAPSULA}}\" style=\"display:inline-block;background:{$acc};color:#FFFFFF;text-decoration:none;padding:11px 24px;border-radius:5px;font-size:13px;font-weight:bold;\">{$cta}</a>\n"
                    . "        </div>\n"
                    . "        <div style=\"border-top:1px solid #E2E8F0;padding-top:12px;font-size:10.5px;color:#94A3B8;line-height:1.4;\">\n"
                    . "          Notificación generada para fines de verificación procedimental interna. Nunca ingrese contraseñas en enlaces recibidos por correo.\n"
                    . "        </div>\n"
                    . "      </td>\n"
                    . "    </tr>\n"
                    . "  </table>\n"
                    . "</body>\n</html>";
                $updHtml->execute([$htmlTpl, $et['id']]);
            }
        }

        // 15. Objetivos & Telemetría Ética de Concientización Red Teaming (SOL-0342)
        $db->exec("CREATE TABLE IF NOT EXISTS redteam_targets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            campaign_id INTEGER NOT NULL,
            full_name TEXT NOT NULL,
            email TEXT NOT NULL,
            department TEXT DEFAULT 'General',
            role_title TEXT DEFAULT '',
            tracking_token TEXT UNIQUE NOT NULL,
            status TEXT DEFAULT 'PENDING',
            sent_at DATETIME,
            opened_at DATETIME,
            clicked_at DATETIME,
            reported_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // 16. Bóveda de Insumos de Referencia por Campaña o Central sin Campaña (SOL-0342 / SOL-0346)
        $db->exec("CREATE TABLE IF NOT EXISTS redteam_assets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            campaign_id INTEGER NOT NULL DEFAULT 0,
            client_label TEXT DEFAULT 'General',
            asset_name TEXT NOT NULL,
            asset_type TEXT DEFAULT 'DOCUMENT',
            stored_filename TEXT NOT NULL,
            file_size INTEGER DEFAULT 0,
            uploaded_by TEXT DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $rtAssetCols = array_column($db->query("PRAGMA table_info(redteam_assets)")->fetchAll(), 'name');
        if (!in_array('client_label', $rtAssetCols, true)) {
            $db->exec("ALTER TABLE redteam_assets ADD COLUMN client_label TEXT DEFAULT 'General'");
        }

        // Sembrar plantillas corporativas internas regionales (RD, Argentina y LATAM) si aún no existen (SOL-0346)
        $chkReg = (int)$db->query("SELECT COUNT(*) FROM redteam_templates WHERE category LIKE 'Regional%'")->fetchColumn();
        if ($chkReg === 0) {
            $insReg = $db->prepare("
                INSERT INTO redteam_templates (client_scope, template_name, category, subject, body_text, custom_html, cta_label, accent_color, created_by)
                VALUES (?, ?, ?, ?, ?, '', ?, ?, 'system')
            ");
            $insReg->execute([
                'GLOBAL',
                'República Dominicana: Validación Interna RNC / Comprobante e-CF y Cuenta DOP',
                'Regional · Rep. Dominicana',
                '[Cuentas por Pagar RD] Validación de RNC y Comprobante e-CF Pendiente — {{EMPRESA}}',
                "Estimado(a) {{NOMBRE}} ({{DEPARTAMENTO}}),\n\nPrevio al cierre de nómina de suplidores locales de {{EMPRESA}}, se requiere validar la actualización de cuenta receptora en DOP/USD y concordancia de comprobante fiscal electrónico (e-CF / NCF) reportada por el proveedor.\n\nIngrese al visor interno de tesorería para confirmar los antecedentes antes de autorizar el desembolso.",
                'Revisar Ficha RNC / e-CF',
                '#0F4C81',
            ]);
            $insReg->execute([
                'GLOBAL',
                'Argentina: Solicitud Interna de Actualización de CBU / Alias Proveedor (CUIT)',
                'Regional · Argentina',
                '[Tesorería AR] Cambio de CBU / Alias para Liquidación Factura — {{EMPRESA}}',
                "Estimado(a) {{NOMBRE}} ({{DEPARTAMENTO}}),\n\nInformamos que se ha recibido una solicitud de modificación de CBU y Alias bancario asociada al CUIT de un proveedor activo de {{EMPRESA}} para pagos en ARS/USD de esta semana.\n\nPor protocolo interno Anti-BEC, verifique la constancia en bandeja documental antes de liberar la transferencia.",
                'Verificar Constancia CBU / CUIT',
                '#1D4ED8',
            ]);
            $insReg->execute([
                'GLOBAL',
                'LATAM Internacional: Instrucción Wire USD (SWIFT / ABA) Pendiente de Control',
                'Regional · Multi-País USD',
                '[Tesorería Internacional] Confirmación de Instrucción Wire SWIFT/ABA — {{EMPRESA}}',
                "Estimado(a) {{NOMBRE}},\n\nSe encuentra retenida para control fuera de banda una instrucción de transferencia internacional en USD (código SWIFT / Routing ABA) con cargo a {{EMPRESA}}.\n\nRevise las coordenadas bancarias del beneficiario exterior en el portal documental interno.",
                'Inspeccionar Coordenadas SWIFT',
                '#065F46',
            ]);
        }

        // 17. Despachos Outbound B2B con Telemetría de Apertura y Clic (SOL-0342)
        $db->exec("CREATE TABLE IF NOT EXISTS outbound_dispatches (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            campaign_id INTEGER DEFAULT 0,
            lead_id INTEGER DEFAULT 0,
            recipient_email TEXT NOT NULL,
            recipient_name TEXT DEFAULT '',
            subject TEXT NOT NULL,
            track_token TEXT UNIQUE NOT NULL,
            status TEXT DEFAULT 'SENT',
            opened_at DATETIME,
            clicked_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Seed inicial de Tenant Demo Corporativo para pruebas inmediatas de /shield/portal
        if ((int)$db->query("SELECT COUNT(*) FROM deepshield_tenants")->fetchColumn() === 0) {
            $demoPass = password_hash('Tesoreria2026!', PASSWORD_BCRYPT);
            $insT = $db->prepare("INSERT INTO deepshield_tenants (
                tenant_slug, company_name, rut_dni, threshold_amount, threshold_currency,
                primary_approver_name, primary_approver_email, primary_approver_phone,
                treasury_operator_name, treasury_operator_email, treasury_operator_phone,
                treasury_operator_password, whatsapp_instance, client_api_key, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ACTIVE')");
            $insT->execute([
                'keystone-demo',
                'Keystone Demo Financiero SpA',
                '77.542.910-K',
                1500000,
                'CLP',
                'Rodrigo Díaz (CFO)',
                'contacto@keystoneadvisors.cl',
                '56930640431',
                'Operador Tesorería',
                'tesoreria@keystoneadvisors.cl',
                '56930640431',
                $demoPass,
                'keystone-general',
                'dst_' . bin2hex(random_bytes(16)),
            ]);
        }

        // Bootstrap inicial de usuario superadmin y credenciales SMTP desde ../data/leads_keystone.sqlite (solo lectura)
        $countStmt = $db->query("SELECT COUNT(*) FROM admin_users");
        if ((int)$countStmt->fetchColumn() === 0) {
            $seeded = false;
            $legacyDbFile = self::getPublicHtmlRoot() . '/data/leads_keystone.sqlite';
            if (is_readable($legacyDbFile)) {
                try {
                    $legacyPdo = new PDO('sqlite:' . $legacyDbFile);
                    $legacyPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    $rows = $legacyPdo->query("SELECT username, password_hash, full_name, role, is_active FROM admin_users")->fetchAll(PDO::FETCH_ASSOC);
                    if (!empty($rows)) {
                        $ins = $db->prepare("INSERT INTO admin_users (username, email, password_hash, full_name, role, is_active) VALUES (?, ?, ?, ?, ?, ?)");
                        foreach ($rows as $r) {
                            $roleNorm = ($r['role'] === 'admin') ? 'superadmin' : ($r['role'] ?: 'superadmin');
                            $mailGuess = ($r['username'] === 'keystone_admin') ? 'rodrigo.diaz@keystoneadvisors.cl' : '';
                            $ins->execute([
                                $r['username'],
                                $mailGuess,
                                $r['password_hash'],
                                $r['full_name'] ?: 'Rodrigo Díaz',
                                $roleNorm,
                                (int)($r['is_active'] ?? 1),
                            ]);
                        }
                        $seeded = true;
                    }
                } catch (Exception $e) {
                    $seeded = false;
                }
            }
            if (!$seeded) {
                $hash = password_hash('Keystone2026!SecOps', PASSWORD_BCRYPT);
                $ins = $db->prepare("INSERT INTO admin_users (username, email, password_hash, full_name, role, is_active) VALUES (?, ?, ?, ?, ?, 1)");
                $ins->execute(['keystone_admin', 'rodrigo.diaz@keystoneadvisors.cl', $hash, 'Rodrigo Díaz', 'superadmin']);
            }
        }

        // Bootstrap inicial de canales SMTP + WhatsApp si aún no existe en site_settings
        $chkNotif = $db->prepare("SELECT COUNT(*) FROM site_settings WHERE setting_key = 'notification_settings'");
        $chkNotif->execute();
        if ((int)$chkNotif->fetchColumn() === 0) {
            $defaultNotif = [
                'smtp_enabled' => true,
                'smtp_host' => 'smtp.titan.email',
                'smtp_port' => 465,
                'smtp_user' => 'contacto@keystoneadvisors.cl',
                'smtp_pass' => '',
                'smtp_from' => 'contacto@keystoneadvisors.cl',
                'smtp_name' => 'Keystone Advisors Global',
                'sales_alert_email' => 'contacto@keystoneadvisors.cl',
                'send_prospect_autoresponder' => true,
                'whatsapp_enabled' => true,
                'whatsapp_gateway_url' => 'http://5.183.9.148:8080',
                'whatsapp_api_key' => '04e9aeffff588f410c0be3bba26d6075de9d47360b134d98635ea3c71b03fdde',
                'whatsapp_instance' => 'keystone-general',
                'whatsapp_alert_phone' => '56930640431',
                'google_sso_enabled' => true,
                'google_allowed_domain' => 'keystoneadvisors.cl',
                'google_client_id' => '36671885786-e2lok6cv5a8rpakvudrko9u0rsh78248.apps.googleusercontent.com',
                'google_client_secret' => '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',
            ];

            // Heredar credenciales SMTP existentes en el servidor si están disponibles
            $legacyDbFile = self::getPublicHtmlRoot() . '/data/leads_keystone.sqlite';
            if (is_readable($legacyDbFile)) {
                try {
                    $legacyPdo = new PDO('sqlite:' . $legacyDbFile);
                    $sRows = $legacyPdo->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key LIKE 'smtp_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
                    foreach (['smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_from', 'smtp_name'] as $k) {
                        if (!empty($sRows[$k])) {
                            $defaultNotif[$k] = ($k === 'smtp_port') ? (int)$sRows[$k] : $sRows[$k];
                        }
                    }
                } catch (Exception $e) {
                    // ignore
                }
            }

            $insSet = $db->prepare("INSERT INTO site_settings (setting_key, setting_value, updated_by) VALUES ('notification_settings', ?, 'bootstrap')");
            $insSet->execute([json_encode($defaultNotif, JSON_UNESCAPED_UNICODE)]);
        }

        // Seed inicial de dominios vigilados
        if ((int)$db->query("SELECT COUNT(*) FROM watched_domains")->fetchColumn() === 0) {
            $insWd = $db->prepare("INSERT INTO watched_domains (domain, client_name, tier, last_score, dmarc_policy, spf_status, tls_status) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $insWd->execute(['keystoneadvisors.cl', 'Keystone Advisors Global', 'Internal Core', 100, 'Blindado (p=reject)', 'Activo (-all)', 'TLS 1.3 + HSTS']);
        }
    }

    public static function getNotificationSettings(): array {
        $db = self::db();
        $stmt = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'notification_settings' LIMIT 1");
        $stmt->execute();
        $val = $stmt->fetchColumn();
        $decoded = $val ? json_decode((string)$val, true) : [];
        return is_array($decoded) ? $decoded : [];
    }

    public static function getSetting(string $key): ?array {
        $db = self::db();
        $stmt = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        if (!$val) {
            return null;
        }
        $decoded = json_decode((string)$val, true);
        return is_array($decoded) ? $decoded : null;
    }

    public static function saveSetting(string $key, array $data, string $actor = 'system'): void {
        $db = self::db();
        $upsert = $db->prepare("
            INSERT INTO site_settings (setting_key, setting_value, updated_by, updated_at)
            VALUES (?, ?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT(setting_key) DO UPDATE SET
                setting_value = excluded.setting_value,
                updated_by = excluded.updated_by,
                updated_at = CURRENT_TIMESTAMP
        ");
        $upsert->execute([$key, json_encode($data, JSON_UNESCAPED_UNICODE), $actor]);
    }

    // =========================================================================
    // MOTOR SMTP NATIVO TLS/SSL (SIN DEPENDENCIAS EXTERNAS)
    // =========================================================================
    public static function sendSmtpEmail(
        string $toEmail,
        string $subject,
        string $htmlBody,
        ?array $overrideCfg = null,
        ?string $pdfAttachmentBytes = null,
        string $pdfFilename = 'Dosier_Keystone.pdf'
    ): array {
        $cfg = $overrideCfg ?? self::getNotificationSettings();
        $toEmail = trim($toEmail);
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Destinatario inválido'];
        }
        $host = trim((string)($cfg['smtp_host'] ?? 'smtp.titan.email'));
        $port = (int)($cfg['smtp_port'] ?? 465);
        $user = trim((string)($cfg['smtp_user'] ?? ''));
        $pass = (string)($cfg['smtp_pass'] ?? '');
        $from = trim((string)($cfg['smtp_from'] ?? $user));
        $name = trim((string)($cfg['smtp_name'] ?? 'Keystone Advisors Global'));

        if ($host === '' || $user === '' || $pass === '') {
            self::logDispatch('SMTP', $toEmail, $subject, 'SKIPPED', 'Credenciales SMTP incompletas');
            return ['success' => false, 'error' => 'Credenciales SMTP no configuradas'];
        }

        $remote = ($port === 465 ? 'ssl://' : '') . $host;
        $fp = @fsockopen($remote, $port, $errno, $errstr, 8);
        if (!$fp) {
            self::logDispatch('SMTP', $toEmail, $subject, 'ERROR', "Socket {$errno}: {$errstr}");
            return ['success' => false, 'error' => "Conexión SMTP fallida: {$errstr}"];
        }
        stream_set_timeout($fp, 8);

        $read = static function ($sock): string {
            $out = '';
            while ($line = fgets($sock, 515)) {
                $out .= $line;
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }
            return $out;
        };

        $cmd = static function ($sock, string $c) use ($read): string {
            fwrite($sock, $c . "\r\n");
            return $read($sock);
        };

        $banner = $read($fp);
        if (substr($banner, 0, 3) !== '220') {
            fclose($fp);
            return ['success' => false, 'error' => 'Servidor SMTP no disponible'];
        }

        $ehlo = $cmd($fp, 'EHLO keystoneadvisors.cl');
        if ($port === 587 && stripos($ehlo, 'STARTTLS') !== false) {
            $tlsRes = $cmd($fp, 'STARTTLS');
            if (substr($tlsRes, 0, 3) === '220') {
                @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                $cmd($fp, 'EHLO keystoneadvisors.cl');
            }
        }

        $cmd($fp, 'AUTH LOGIN');
        $cmd($fp, base64_encode($user));
        $authRes = $cmd($fp, base64_encode($pass));
        if (substr($authRes, 0, 3) !== '235') {
            fclose($fp);
            self::logDispatch('SMTP', $toEmail, $subject, 'AUTH_ERROR', trim($authRes));
            return ['success' => false, 'error' => 'Autenticación SMTP rechazada'];
        }

        $cmd($fp, "MAIL FROM:<{$from}>");
        $rcpt = $cmd($fp, "RCPT TO:<{$toEmail}>");
        if (substr($rcpt, 0, 3) !== '250' && substr($rcpt, 0, 3) !== '251') {
            fclose($fp);
            self::logDispatch('SMTP', $toEmail, $subject, 'RCPT_ERROR', trim($rcpt));
            return ['success' => false, 'error' => 'Destinatario rechazado por SMTP'];
        }

        $dataRes = $cmd($fp, 'DATA');
        if (substr($dataRes, 0, 3) !== '354') {
            fclose($fp);
            return ['success' => false, 'error' => 'Servidor rechazó comando DATA'];
        }

        $encodedSubj = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $encodedName = '=?UTF-8?B?' . base64_encode($name) . '?=';
        if ($pdfAttachmentBytes !== null && strlen($pdfAttachmentBytes) > 0) {
            $boundary = '----=_KSConsolePart_' . bin2hex(random_bytes(8));
            $headers = [
                "From: {$encodedName} <{$from}>",
                "To: <{$toEmail}>",
                "Reply-To: <{$from}>",
                "Subject: {$encodedSubj}",
                'Date: ' . date('r'),
                'MIME-Version: 1.0',
                "Content-Type: multipart/mixed; boundary=\"{$boundary}\"",
                'X-Mailer: Keystone-Console-v4.2',
            ];
            $safeFn = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $pdfFilename);
            $bodyMime = "--{$boundary}\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: 8bit\r\n\r\n"
                . $htmlBody . "\r\n\r\n"
                . "--{$boundary}\r\n"
                . "Content-Type: application/pdf; name=\"{$safeFn}\"\r\n"
                . "Content-Transfer-Encoding: base64\r\n"
                . "Content-Disposition: attachment; filename=\"{$safeFn}\"\r\n\r\n"
                . chunk_split(base64_encode($pdfAttachmentBytes)) . "\r\n"
                . "--{$boundary}--";
            $mime = implode("\r\n", $headers) . "\r\n\r\n" . $bodyMime . "\r\n.";
        } else {
            $headers = [
                "From: {$encodedName} <{$from}>",
                "To: <{$toEmail}>",
                "Reply-To: <{$from}>",
                "Subject: {$encodedSubj}",
                'Date: ' . date('r'),
                'MIME-Version: 1.0',
                'Content-Type: text/html; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
                'X-Mailer: Keystone-Console-v4.2',
            ];
            $mime = implode("\r\n", $headers) . "\r\n\r\n" . $htmlBody . "\r\n.";
        }
        $sendRes = $cmd($fp, $mime);
        $cmd($fp, 'QUIT');
        fclose($fp);

        $ok = substr($sendRes, 0, 3) === '250';
        self::logDispatch('SMTP', $toEmail, $subject, $ok ? 'SENT' : 'FAILED', trim($sendRes));
        return $ok
            ? ['success' => true]
            : ['success' => false, 'error' => 'Error entregando cuerpo MIME'];
    }

    // =========================================================================
    // MOTOR WHATSAPP EVOLUTION API v2 (ALERTAS EJECUTIVAS, MULTI-TENANT QR & OOB)
    // =========================================================================
    public static function sendWhatsAppAlert(string $phone, string $message, ?array $overrideCfg = null, ?string $preferredInstance = null): array {
        $cfg = $overrideCfg ?? self::getNotificationSettings();
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($cleanPhone) < 8) {
            return ['success' => false, 'error' => 'Número WhatsApp inválido'];
        }

        $gateway = rtrim((string)($cfg['whatsapp_gateway_url'] ?? ''), '/');
        $apiKey = trim((string)($cfg['whatsapp_api_key'] ?? ''));
        $fallbackInstance = trim((string)($cfg['whatsapp_instance'] ?? 'keystone-general'));
        if ($fallbackInstance === '') {
            $fallbackInstance = 'keystone-general';
        }

        $customInst = $preferredInstance !== null
            ? preg_replace('/[^a-zA-Z0-9_-]/', '', trim($preferredInstance))
            : '';
        $instance = $customInst !== '' ? $customInst : $fallbackInstance;

        if ($gateway === '' || $apiKey === '') {
            self::logDispatch('WHATSAPP', $cleanPhone, $instance, 'SKIPPED', 'Gateway WhatsApp no configurado');
            return ['success' => false, 'error' => 'Gateway WhatsApp sin credenciales'];
        }

        $sendVia = function (string $instName) use ($gateway, $apiKey, $cleanPhone, $message): array {
            $url = "{$gateway}/message/sendText/{$instName}";
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 6);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'apikey: ' . $apiKey,
                'Content-Type: application/json',
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'number' => $cleanPhone,
                'text' => $message,
                'delay' => 800,
                'linkPreview' => true,
            ]));

            $resp = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            $ok = ($err === '' && $httpCode >= 200 && $httpCode < 300);
            return [
                'ok' => $ok,
                'http_code' => $httpCode,
                'err' => $err,
                'resp' => $resp,
                'instance' => $instName,
            ];
        };

        $res = $sendVia($instance);
        if (!$res['ok'] && $instance !== $fallbackInstance) {
            self::logDispatch('WHATSAPP', $cleanPhone, $instance, 'FALLBACK', "Instancia propia no disponible (HTTP {$res['http_code']}), reintentando vía {$fallbackInstance}");
            $res = $sendVia($fallbackInstance);
        }

        self::logDispatch('WHATSAPP', $cleanPhone, $res['instance'], $res['ok'] ? 'SENT' : 'FAILED', $res['err'] ?: "HTTP {$res['http_code']}");
        return $res['ok']
            ? ['success' => true, 'http_code' => $res['http_code'], 'instance_used' => $res['instance']]
            : ['success' => false, 'error' => $res['err'] ?: "Gateway retornó HTTP {$res['http_code']}", 'raw' => $res['resp']];
    }

    /**
     * Consulta estado o aprovisiona automáticamente una instancia dedicada en Evolution API v2 y retorna su QR Base64.
     */
    public static function getOrConnectWhatsAppInstance(string $instanceName): array {
        $inst = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($instanceName));
        if ($inst === '') {
            return ['success' => false, 'error' => 'Identificador de instancia WhatsApp inválido.'];
        }

        $cfg = self::getNotificationSettings();
        $gw = rtrim((string)($cfg['whatsapp_gateway_url'] ?? ''), '/');
        $key = trim((string)($cfg['whatsapp_api_key'] ?? ''));
        if ($gw === '' || $key === '') {
            return ['success' => false, 'error' => 'Gateway Evolution API no configurado.'];
        }

        // 1. Verificar si la instancia ya existe y su estado de sesión
        $ch = curl_init("{$gw}/instance/connectionState/{$inst}");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["apikey: {$key}"]);
        $resp = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $dec = $resp ? json_decode($resp, true) : [];
        $state = $dec['instance']['state'] ?? ($dec['state'] ?? 'uncreated');

        if ($httpCode === 200 && $state === 'open') {
            return [
                'success' => true,
                'connected' => true,
                'instance' => $inst,
                'state' => 'open',
                'qr_base64' => null,
                'message' => "La instancia WhatsApp '{$inst}' se encuentra vinculada y transmitiendo en línea.",
            ];
        }

        $qrBase64 = null;

        // 2. Si no existe aún (HTTP 404), crearla automáticamente en Evolution API v2 con generación de QR
        if ($httpCode === 404 || $httpCode === 400 || $state === 'uncreated') {
            $chCreate = curl_init("{$gw}/instance/create");
            curl_setopt($chCreate, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($chCreate, CURLOPT_POST, true);
            curl_setopt($chCreate, CURLOPT_TIMEOUT, 12);
            curl_setopt($chCreate, CURLOPT_HTTPHEADER, [
                "apikey: {$key}",
                'Content-Type: application/json',
            ]);
            curl_setopt($chCreate, CURLOPT_POSTFIELDS, json_encode([
                'instanceName' => $inst,
                'token' => bin2hex(random_bytes(16)),
                'qrcode' => true,
                'integration' => 'WHATSAPP-BAILEYS',
            ]));
            $createResp = curl_exec($chCreate);
            curl_close($chCreate);
            $createDec = $createResp ? json_decode($createResp, true) : [];
            $qrBase64 = $createDec['qrcode']['base64'] ?? ($createDec['base64'] ?? null);
            $state = 'connecting';
        }

        // 3. Solicitar código QR activo si aún no viene en el payload
        if (empty($qrBase64)) {
            $chQr = curl_init("{$gw}/instance/connect/{$inst}");
            curl_setopt($chQr, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($chQr, CURLOPT_TIMEOUT, 10);
            curl_setopt($chQr, CURLOPT_HTTPHEADER, ["apikey: {$key}"]);
            $qrResp = curl_exec($chQr);
            curl_close($chQr);
            $qrDec = $qrResp ? json_decode($qrResp, true) : [];
            $qrBase64 = $qrDec['base64'] ?? ($qrDec['qrcode']['base64'] ?? null);
        }

        return [
            'success' => true,
            'connected' => false,
            'instance' => $inst,
            'state' => $state,
            'qr_base64' => $qrBase64,
            'message' => $qrBase64
                ? "Escanee el código QR desde WhatsApp Corporativo (Dispositivos vinculados) para activar '{$inst}'."
                : "Instancia '{$inst}' preparada ({$state}). Pulse Actualizar QR si no visualiza la imagen.",
        ];
    }

    /**
     * Cierra la sesión vinculada de una instancia WhatsApp dedicada de cliente (protegiendo keystone-general).
     */
    public static function logoutWhatsAppInstance(string $instanceName): array {
        $inst = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($instanceName));
        if ($inst === '' || $inst === 'keystone-general') {
            return ['success' => false, 'error' => 'La instancia maestra keystone-general está protegida contra desconexión de clientes.'];
        }
        $cfg = self::getNotificationSettings();
        $gw = rtrim((string)($cfg['whatsapp_gateway_url'] ?? ''), '/');
        $key = trim((string)($cfg['whatsapp_api_key'] ?? ''));
        if ($gw === '' || $key === '') {
            return ['success' => false, 'error' => 'Gateway no configurado.'];
        }
        $ch = curl_init("{$gw}/instance/logout/{$inst}");
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["apikey: {$key}"]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['success' => ($code >= 200 && $code < 500), 'instance' => $inst];
    }

    public static function logDispatch(string $channel, string $recipient, string $ref, string $status, string $detail = ''): void {
        try {
            $db = self::db();
            $ins = $db->prepare("INSERT INTO dispatch_logs (channel, recipient, subject_or_ref, status, detail) VALUES (?, ?, ?, ?, ?)");
            $ins->execute([$channel, $recipient, $ref, $status, substr($detail, 0, 240)]);
        } catch (Exception $e) {
            // ignore
        }
    }

    // =========================================================================
    // MOTOR NATIVO TOTP 2FA (RFC 6238 — GOOGLE AUTHENTICATOR / AUTHY)
    // =========================================================================
    private const BASE32_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateTotpSecret(int $length = 16): string {
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= self::BASE32_CHARS[random_int(0, 31)];
        }
        return $secret;
    }

    private static function base32Decode(string $secret): string {
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret));
        $bits = '';
        $out = '';
        for ($i = 0, $len = strlen($secret); $i < $len; $i++) {
            $val = strpos(self::BASE32_CHARS, $secret[$i]);
            if ($val === false) {
                continue;
            }
            $bits .= str_pad(decbin($val), 5, '0', STR_PAD_LEFT);
            while (strlen($bits) >= 8) {
                $out .= chr(bindec(substr($bits, 0, 8)));
                $bits = substr($bits, 8);
            }
        }
        return $out;
    }

    public static function computeTotpCode(string $secret, int $timeSlice): string {
        $key = self::base32Decode($secret);
        $timeBin = pack('N*', 0) . pack('N*', $timeSlice);
        $hm = hash_hmac('sha1', $timeBin, $key, true);
        $offset = ord($hm[19]) & 0x0F;
        $hashpart = substr($hm, $offset, 4);
        $value = unpack('N', $hashpart)[1] & 0x7FFFFFFF;
        return str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public static function verifyTotpCode(string $secret, string $code, int $discrepancy = 1): bool {
        $cleanCode = preg_replace('/[^0-9]/', '', $code);
        if (strlen($cleanCode) !== 6 || empty($secret)) {
            return false;
        }
        $slice = (int)floor(time() / 30);
        for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
            if (hash_equals(self::computeTotpCode($secret, $slice + $i), $cleanCode)) {
                return true;
            }
        }
        return false;
    }

    // =========================================================================
    // UTILIDADES HTTP, AUTH & ESCANEO DNS BEC
    // =========================================================================
    public static function readJsonBody(): array {
        $raw = file_get_contents('php://input');
        if (!$raw) {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public static function jsonOk(array $payload = []): void {
        echo json_encode(array_merge(['success' => true], $payload), JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function jsonError(string $message, int $httpCode = 400): void {
        http_response_code($httpCode);
        echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function requireAuth(array $allowedRoles = ['superadmin', 'comercial', 'secops'], bool $checkCsrf = true): array {
        self::startSecureSession();
        if (empty($_SESSION['ks_admin_user'])) {
            self::jsonError('Sesión no autenticada o expirada.', 401);
        }
        $user = $_SESSION['ks_admin_user'];
        $role = $user['role'] ?? 'comercial';
        if ($role !== 'superadmin' && !in_array($role, $allowedRoles, true)) {
            self::jsonError('Su rol (' . $role . ') no cuenta con permisos para esta operación.', 403);
        }

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($checkCsrf && in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
            $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
            if (empty($headerToken) || !hash_equals($_SESSION['csrf_token'] ?? '', $headerToken)) {
                self::jsonError('Token de seguridad CSRF inválido. Recargue la consola.', 403);
            }
        }

        return $user;
    }

    public static function logAudit(string $actor, string $actionCode, string $targetRef = '', string $detail = ''): void {
        try {
            $db = self::db();
            $stmt = $db->prepare("INSERT INTO admin_audit_log (actor, action_code, target_ref, detail, ip_address) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                $actor,
                $actionCode,
                $targetRef,
                $detail,
                $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            ]);
        } catch (Exception $e) {
            // ignore
        }
    }

    public static function runPassiveDomainCheck(string $rawDomain): array {
        $domain = preg_replace('#^https?://#i', '', strtolower(trim($rawDomain)));
        $domain = explode('/', $domain)[0];
        $domain = preg_replace('/^www\./i', '', $domain);
        $domain = trim($domain);

        if (empty($domain) || !preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/i', $domain)) {
            throw new InvalidArgumentException('Dominio corporativo inválido.');
        }

        $score = 100;
        $findings = [];

        $hasMx = @checkdnsrr($domain, 'MX');
        if (!$hasMx) {
            $score -= 15;
            $findings[] = ['severity' => 'medium', 'title' => 'Sin registros MX públicos', 'desc' => 'No se detectaron servidores MX entrantes activos.'];
        }

        $hasSpf = false;
        $spfStrict = false;
        $txtRecords = @dns_get_record($domain, DNS_TXT);
        if (is_array($txtRecords)) {
            foreach ($txtRecords as $rec) {
                $txt = (string)($rec['txt'] ?? '');
                if (stripos($txt, 'v=spf1') !== false) {
                    $hasSpf = true;
                    if (strpos($txt, '-all') !== false || strpos($txt, '~all') !== false) {
                        $spfStrict = true;
                    }
                }
            }
        }
        if (!$hasSpf) {
            $score -= 30;
            $findings[] = ['severity' => 'high', 'title' => 'Ausencia de SPF', 'desc' => 'Sin lista autorizada de despacho para @' . $domain . '.'];
        } elseif (!$spfStrict) {
            $score -= 10;
            $findings[] = ['severity' => 'medium', 'title' => 'SPF permisivo', 'desc' => 'Falta directiva estricta -all o ~all.'];
        }

        $hasDmarc = false;
        $dmarcPolicy = 'none';
        $dmarcLabel = 'No configurado';
        $dmarcRecords = @dns_get_record('_dmarc.' . $domain, DNS_TXT);
        if (is_array($dmarcRecords)) {
            foreach ($dmarcRecords as $rec) {
                $txt = (string)($rec['txt'] ?? '');
                if (stripos($txt, 'v=DMARC1') !== false) {
                    $hasDmarc = true;
                    if (preg_match('/p\s*=\s*([a-zA-Z]+)/i', $txt, $m)) {
                        $dmarcPolicy = strtolower($m[1]);
                    }
                    if ($dmarcPolicy === 'reject') {
                        $dmarcLabel = 'Blindado (p=reject)';
                    } elseif ($dmarcPolicy === 'quarantine') {
                        $dmarcLabel = 'Cuarentena (p=quarantine)';
                        $score -= 10;
                        $findings[] = ['severity' => 'low', 'title' => 'DMARC en cuarentena', 'desc' => 'Recomendable evolucionar a p=reject definitivo.'];
                    } else {
                        $dmarcLabel = 'Solo monitoreo (p=none)';
                        $score -= 35;
                        $findings[] = ['severity' => 'high', 'title' => 'DMARC sin bloqueo (p=none)', 'desc' => 'Suplantaciones ejecutivas llegan a bandeja de entrada.'];
                    }
                }
            }
        }
        if (!$hasDmarc) {
            $score -= 45;
            $findings[] = ['severity' => 'critical', 'title' => 'Sin protección DMARC', 'desc' => 'Exposición directa a fraude BEC y spoofing de identidad.'];
        }

        $hasSsl = false;
        $hasHsts = false;
        $ctx = stream_context_create([
            'http' => ['method' => 'HEAD', 'timeout' => 2.5, 'ignore_errors' => true],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $headers = @get_headers('https://' . $domain, true, $ctx);
        if (is_array($headers)) {
            $hasSsl = true;
            foreach ($headers as $k => $v) {
                if (strtolower((string)$k) === 'strict-transport-security') {
                    $hasHsts = true;
                    break;
                }
            }
        }
        if (!$hasSsl) {
            $score -= 10;
        } elseif (!$hasHsts) {
            $score -= 5;
        }

        $score = max(15, min(100, $score));
        $riskLevel = 'Protegido';
        if ($score < 50) {
            $riskLevel = 'Riesgo Crítico BEC';
        } elseif ($score < 78) {
            $riskLevel = 'Exposición Media / Alta';
        } elseif ($score < 92) {
            $riskLevel = 'Aceptable (Mejorable)';
        }

        if (empty($findings)) {
            $findings[] = ['severity' => 'ok', 'title' => 'Postura DNS endurecida', 'desc' => 'Controles SPF, DMARC y TLS verificados correctamente.'];
        }

        return [
            'domain' => $domain,
            'score' => $score,
            'risk_level' => $riskLevel,
            'spf' => $hasSpf ? ($spfStrict ? 'Activo (-all/~all)' : 'Activo (Permisivo)') : 'Ausente',
            'dmarc' => $dmarcLabel,
            'mx' => $hasMx ? 'Activo' : 'No detectado',
            'https_hsts' => $hasSsl ? ($hasHsts ? 'TLS + HSTS Activo' : 'TLS Activo (Sin HSTS)') : 'Sin HTTPS directo',
            'findings' => $findings,
            'scanned_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Evalúa una cuenta de destino contra el Padrón Verificado (Whitelist Anti-BEC) del Tenant.
     * Retorna status: VERIFIED | ACCOUNT_MISMATCH | UNREGISTERED_SUPPLIER
     */
    public static function evaluateWhitelistMatch(string $tenantSlug, string $rut, string $bank, string $account, string $companyName = ''): array {
        $normRut = strtoupper(preg_replace('/[^0-9kK]/', '', $rut));
        $normAcc = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $account));
        if ($normRut === '' || $normAcc === '') {
            return [
                'status' => 'UNREGISTERED_SUPPLIER',
                'detail' => 'Sin RUT o cuenta normalizable para contrastar con Padrón.',
            ];
        }

        $db = self::db();
        if ($tenantSlug !== '') {
            $stmt = $db->prepare("SELECT * FROM deepshield_whitelist WHERE LOWER(tenant_slug) = LOWER(?)");
            $stmt->execute([$tenantSlug]);
            $rows = $stmt->fetchAll();
        } elseif ($companyName !== '') {
            $stmt = $db->prepare("
                SELECT w.* FROM deepshield_whitelist w
                LEFT JOIN deepshield_tenants t ON LOWER(t.tenant_slug) = LOWER(w.tenant_slug)
                WHERE LOWER(t.company_name) = LOWER(?) OR LOWER(w.verified_by) LIKE LOWER(?)
            ");
            $stmt->execute([$companyName, $companyName . '%']);
            $rows = $stmt->fetchAll();
        } else {
            $rows = [];
        }

        $rutMatches = [];
        foreach ($rows as $w) {
            $wRut = strtoupper(preg_replace('/[^0-9kK]/', '', (string)$w['supplier_rut']));
            $wAcc = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', (string)$w['account_number']));
            if ($wRut !== '' && $wRut === $normRut) {
                if ($wAcc === $normAcc) {
                    return [
                        'status' => 'VERIFIED',
                        'detail' => "Cuenta certificada en Padrón Verificado ({$w['supplier_name']} · {$w['bank_name']}).",
                    ];
                }
                $rutMatches[] = $w;
            }
        }

        if (!empty($rutMatches)) {
            $ref = $rutMatches[0];
            return [
                'status' => 'ACCOUNT_MISMATCH',
                'detail' => "ALERTA ANTI-BEC: El proveedor {$ref['supplier_name']} ({$ref['supplier_rut']}) tiene inscrita oficialmente la cuenta {$ref['account_number']} ({$ref['bank_name']}), distinta a la cuenta solicitada ({$account}).",
            ];
        }

        return [
            'status' => 'UNREGISTERED_SUPPLIER',
            'detail' => 'Proveedor no inscrito aún en el Padrón Verificado de la empresa. Se recomienda confirmar por teléfono antes de autorizar.',
        ];
    }
}
