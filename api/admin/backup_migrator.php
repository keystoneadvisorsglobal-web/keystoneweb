<?php
/**
 * Keystone Command Console v4.4 — Respaldo Atómico SQLite & Migrador de Solo Lectura Pre-Corte a Producción (SOL-0342)
 * Acceso exclusivo: Rol superadmin.
 * Regla Global #1: Jamás escribe ni altera la base de producción; solo lee en modo SQLITE3_OPEN_READONLY cuando se consulta o importa hacia staging.
 */
require_once __DIR__ . '/_core.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET' && ($_GET['action'] ?? '') === 'download_sqlite') {
    AdminCore::startSecureSession();
    if (empty($_SESSION['ks_admin_user']) || ($_SESSION['ks_admin_user']['role'] ?? '') !== 'superadmin') {
        http_response_code(403);
        die('Solo Superadmin puede descargar respaldos íntegros de base de datos.');
    }

    $db = AdminCore::db();
    $tmpBackup = sys_get_temp_dir() . '/ks_console_backup_' . bin2hex(random_bytes(8)) . '.sqlite';
    @unlink($tmpBackup);

    // Respaldo consistente en caliente (compatible con modo WAL)
    $quoted = str_replace("'", "''", $tmpBackup);
    $db->exec("VACUUM INTO '{$quoted}'");

    if (!is_file($tmpBackup)) {
        http_response_code(500);
        die('No fue posible empaquetar el respaldo SQLite.');
    }

    AdminCore::logAudit($_SESSION['ks_admin_user']['username'], 'DB_BACKUP_DOWNLOAD', 'spatial_leads.sqlite', 'Descarga snapshot atómico');

    $dlName = 'Keystone_Console_Snapshot_' . date('Ymd_Hi') . '.sqlite';
    header('Content-Type: application/x-sqlite3');
    header('Content-Disposition: attachment; filename="' . $dlName . '"');
    header('Content-Length: ' . filesize($tmpBackup));
    readfile($tmpBackup);
    @unlink($tmpBackup);
    exit;
}

AdminCore::sendHeaders();
$currentUser = AdminCore::requireAuth(['superadmin']);
$db = AdminCore::db();

// Localizar posibles rutas de solo lectura de la base histórica del sitio anterior
$candidateLegacyPaths = [
    dirname(__DIR__, 4) . '/data/leads_keystone.sqlite',
    dirname(__DIR__, 3) . '/data/leads_keystone.sqlite',
    '/home/u705937345/domains/keystoneadvisors.cl/public_html/data/leads_keystone.sqlite',
];
$legacyDbPath = null;
foreach ($candidateLegacyPaths as $cand) {
    if (is_file($cand) && is_readable($cand)) {
        $legacyDbPath = $cand;
        break;
    }
}

if ($method === 'GET') {
    $stagingStats = [
        'leads' => (int)$db->query("SELECT COUNT(*) FROM micro_leads")->fetchColumn(),
        'scans' => (int)$db->query("SELECT COUNT(*) FROM bec_scans")->fetchColumn(),
        'tenants' => (int)$db->query("SELECT COUNT(*) FROM deepshield_tenants")->fetchColumn(),
        'orders' => (int)$db->query("SELECT COUNT(*) FROM deepshield_orders")->fetchColumn(),
        'dmarc_reports' => (int)$db->query("SELECT COUNT(*) FROM dmarc_reports")->fetchColumn(),
    ];

    $legacyInfo = ['available' => false, 'path_detected' => false, 'tables' => [], 'counts' => []];
    if ($legacyDbPath && class_exists('SQLite3')) {
        try {
            $ro = new SQLite3($legacyDbPath, SQLITE3_OPEN_READONLY);
            $tRes = $ro->query("SELECT name FROM sqlite_master WHERE type='table'");
            $tables = [];
            while ($r = $tRes->fetchArray(SQLITE3_ASSOC)) {
                $tables[] = $r['name'];
            }
            $counts = [];
            foreach (['leads', 'bec_scans', 'dmarc_domains', 'watched_domains', 'deepshield_orders'] as $tb) {
                if (in_array($tb, $tables, true)) {
                    $counts[$tb] = (int)$ro->querySingle("SELECT COUNT(*) FROM \"{$tb}\"");
                }
            }
            $ro->close();
            $legacyInfo = [
                'available' => true,
                'path_detected' => basename(dirname($legacyDbPath)) . '/' . basename($legacyDbPath),
                'tables' => $tables,
                'counts' => $counts,
            ];
        } catch (Exception $e) {
            $legacyInfo['error'] = $e->getMessage();
        }
    }

    AdminCore::jsonOk([
        'staging_stats' => $stagingStats,
        'legacy_source' => $legacyInfo,
    ]);
}

if ($method === 'POST') {
    $body = AdminCore::readJsonBody();
    $action = trim((string)($body['action'] ?? ''));

    if ($action === 'import_legacy_readonly') {
        if (!$legacyDbPath || !class_exists('SQLite3')) {
            AdminCore::jsonError('No se detectó archivo histórico leads_keystone.sqlite accesible en lectura.', 404);
        }

        $ro = new SQLite3($legacyDbPath, SQLITE3_OPEN_READONLY);
        $tRes = $ro->query("SELECT name FROM sqlite_master WHERE type='table'");
        $tables = [];
        while ($r = $tRes->fetchArray(SQLITE3_ASSOC)) {
            $tables[] = $r['name'];
        }

        $importedLeads = 0;
        $importedScans = 0;
        $importedDomains = 0;

        // 1. Importar Leads históricos sin duplicar por correo + fecha
        if (in_array('leads', $tables, true)) {
            $q = $ro->query("SELECT * FROM leads ORDER BY id ASC LIMIT 500");
            $chkL = $db->prepare("SELECT COUNT(*) FROM micro_leads WHERE LOWER(email) = LOWER(?) AND created_at = ?");
            $insL = $db->prepare("
                INSERT INTO micro_leads (order_ref, action, email, domain, pillar, service_name, notes, status, created_at)
                VALUES (?, 'legacy_import', ?, ?, ?, ?, ?, 'CONTACTADO', ?)
            ");
            while ($row = $q->fetchArray(SQLITE3_ASSOC)) {
                $em = trim((string)($row['email'] ?? ''));
                if ($em === '' || strpos($em, '@') === false) continue;
                $cAt = (string)($row['created_at'] ?? date('Y-m-d H:i:s'));
                $chkL->execute([$em, $cAt]);
                if ((int)$chkL->fetchColumn() > 0) continue;

                $dom = strtolower(substr(strrchr($em, '@'), 1));
                $ref = 'KS-LEG-' . str_pad((string)($row['id'] ?? rand(100, 999)), 4, '0', STR_PAD_LEFT);
                $srv = trim((string)($row['service'] ?? ($row['interest'] ?? 'Consulta Histórica')));
                $nts = trim((string)($row['message'] ?? ($row['company'] ?? '')));
                $insL->execute([$ref, $em, $dom, 'Digital Core', $srv, $nts, $cAt]);
                $importedLeads++;
            }
        }

        // 2. Importar Escaneos BEC históricos si existen
        if (in_array('bec_scans', $tables, true)) {
            $qS = $ro->query("SELECT * FROM bec_scans ORDER BY id ASC LIMIT 500");
            $chkS = $db->prepare("SELECT COUNT(*) FROM bec_scans WHERE domain = ? AND created_at = ?");
            $insS = $db->prepare("
                INSERT INTO bec_scans (scan_ref, domain, email, score, risk_level, dmarc_label, spf_status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            while ($row = $qS->fetchArray(SQLITE3_ASSOC)) {
                $dom = trim((string)($row['domain'] ?? ''));
                if ($dom === '') continue;
                $cAt = (string)($row['created_at'] ?? date('Y-m-d H:i:s'));
                $chkS->execute([$dom, $cAt]);
                if ((int)$chkS->fetchColumn() > 0) continue;

                $ref = (string)($row['scan_ref'] ?? ('BEC-LEG-' . ($row['id'] ?? rand(100, 999))));
                $sc = (int)($row['score'] ?? 60);
                $rk = (string)($row['risk_level'] ?? 'Evaluado');
                $dm = (string)($row['dmarc_status'] ?? ($row['dmarc_label'] ?? 'Histórico'));
                $sp = (string)($row['spf_status'] ?? 'Histórico');
                $insS->execute([$ref, $dom, $row['email'] ?? '', $sc, $rk, $dm, $sp, $cAt]);
                $importedScans++;
            }
        }

        // 3. Importar Dominios custodiados históricos
        foreach (['watched_domains', 'dmarc_domains'] as $domTable) {
            if (in_array($domTable, $tables, true)) {
                $qD = $ro->query("SELECT * FROM \"{$domTable}\"");
                $insD = $db->prepare("INSERT OR IGNORE INTO watched_domains (domain, client_name, tier) VALUES (?, ?, 'Enterprise')");
                while ($row = $qD->fetchArray(SQLITE3_ASSOC)) {
                    $dom = strtolower(trim((string)($row['domain'] ?? '')));
                    $cli = trim((string)($row['client_name'] ?? ($row['company_name'] ?? $dom)));
                    if ($dom !== '') {
                        $insD->execute([$dom, $cli]);
                        if ($insD->rowCount() > 0) {
                            $importedDomains++;
                        }
                    }
                }
            }
        }

        $ro->close();
        AdminCore::logAudit($currentUser['username'], 'LEGACY_DB_IMPORT', basename($legacyDbPath), "Leads:{$importedLeads} · Scans:{$importedScans} · Dom:{$importedDomains}");
        AdminCore::jsonOk([
            'imported_leads' => $importedLeads,
            'imported_scans' => $importedScans,
            'imported_domains' => $importedDomains,
            'message' => "Sincronización histórica completada (modo lectura segura): {$importedLeads} leads, {$importedScans} escaneos BEC y {$importedDomains} dominios incorporados.",
        ]);
    }

    AdminCore::jsonError('Acción desconocida.', 400);
}
