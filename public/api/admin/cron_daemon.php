<?php
/**
 * Keystone Command Console v4.4 — Daemon Autónomo Cron (SOL-0342)
 * Ejecuta 3 ciclos desatendidos:
 *  1) Auto-Ingesta IMAP de Reportes DMARC RUA (.xml / .gz / .zip RFC 7489)
 *  2) Re-escaneo 24/7 de Dominios Vigilados (Watchdog DNS/BEC + Alerta WhatsApp ante degradación)
 *  3) Expiración automática de órdenes DeepShield Treasury OOB vencidas (>48h)
 *
 * Modos de invocación soportados:
 *  - CLI / Crontab: php cron_daemon.php
 *  - HTTP Token:    GET /api/admin/cron_daemon.php?key=<cron_secret>
 *  - Consola Admin: POST /api/admin/cron_daemon.php (Sesión autenticada superadmin/secops)
 */
require_once __DIR__ . '/_core.php';

$isCli = (php_sapi_name() === 'cli');
$db = AdminCore::db();

// Obtener o inicializar clave secreta de Cron en site_settings
$cronKeyRow = AdminCore::getSetting('cron_secret_key');
$cronSecret = is_array($cronKeyRow) && !empty($cronKeyRow['key'])
    ? (string)$cronKeyRow['key']
    : '';
if ($cronSecret === '') {
    $cronSecret = 'ks_cron_' . bin2hex(random_bytes(12));
    AdminCore::saveSetting('cron_secret_key', ['key' => $cronSecret], 'system');
}

$actor = 'cron_daemon';
if (!$isCli) {
    AdminCore::sendHeaders();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // Consulta de estado del Daemon para la UI
    if ($method === 'GET' && ($_GET['action'] ?? '') === 'status') {
        $u = AdminCore::requireAuth(['superadmin', 'secops']);
        $lastRun = AdminCore::getSetting('last_cron_run') ?: null;
        AdminCore::jsonOk([
            'cron_secret' => $cronSecret,
            'last_run' => $lastRun,
        ]);
    }

    if ($method === 'POST') {
        $u = AdminCore::requireAuth(['superadmin', 'secops']);
        $actor = $u['username'];
    } else {
        $provided = trim((string)($_GET['key'] ?? ''));
        if (!hash_equals($cronSecret, $provided)) {
            AdminCore::jsonError('Clave de ejecución Cron no autorizada.', 403);
        }
    }
}

$startedAt = microtime(true);
$cfg = AdminCore::getNotificationSettings();

// =========================================================================
// TAREA 1: EXPIRACIÓN DE ÓRDENES DEEPSHIELD TREASURY VENCIDAS
// =========================================================================
$expStmt = $db->prepare("
    UPDATE deepshield_orders
    SET status = 'EXPIRED', audit_notes = COALESCE(audit_notes, '') || ' [Expirada automáticamente por Cron tras ventana 48h]'
    WHERE status IN ('PENDING_APPROVAL', 'PARTIAL_APPROVAL')
      AND expires_at IS NOT NULL
      AND datetime(expires_at) < datetime('now')
");
$expStmt->execute();
$expiredOrders = $expStmt->rowCount();

// =========================================================================
// TAREA 2: RE-ESCANEO 24/7 DE DOMINIOS EN WATCHDOG + ALERTA DE DEGRADACIÓN
// =========================================================================
$watched = $db->query("SELECT * FROM watched_domains WHERE is_active = 1")->fetchAll();
$watchdogChecked = 0;
$watchdogDegradations = [];

$updWatch = $db->prepare("
    UPDATE watched_domains
    SET last_score = ?, dmarc_policy = ?, spf_status = ?, tls_status = ?, last_checked_at = CURRENT_TIMESTAMP
    WHERE id = ?
");

foreach ($watched as $w) {
    $prevScore = (int)($w['last_score'] ?? 100);
    $scan = AdminCore::runPassiveDomainCheck((string)$w['domain']);
    $newScore = (int)$scan['score'];
    $updWatch->execute([$newScore, $scan['dmarc'], $scan['spf'], $scan['https_hsts'], $w['id']]);
    $watchdogChecked++;

    if (($prevScore - $newScore) >= 15 || ($prevScore >= 85 && $newScore < 70)) {
        $watchdogDegradations[] = "{$w['domain']} ({$prevScore} -> {$newScore}/100 · DMARC: {$scan['dmarc']})";
    }
}

if (!empty($watchdogDegradations) && !empty($cfg['whatsapp_alert_phone'])) {
    $alertMsg = "*ALERTA WATCHDOG KEYSTONE 24/7*\n"
        . "Se detectó degradación DNS/BEC en dominio(s) custodiado(s):\n• "
        . implode("\n• ", $watchdogDegradations);
    @AdminCore::sendWhatsAppAlert((string)$cfg['whatsapp_alert_phone'], $alertMsg, $cfg);
}

// =========================================================================
// TAREA 3: AUTO-INGESTA IMAP DE REPORTES AGREGADOS DMARC RUA (.xml/.gz/.zip)
// =========================================================================
$ruaProcessed = 0;
$ruaSpoofAlerts = 0;
$ruaMailboxStatus = 'SKIPPED';

if (function_exists('imap_open')) {
    $ruaUser = !empty($cfg['dmarc_rua_user']) ? trim((string)$cfg['dmarc_rua_user']) : trim((string)($cfg['smtp_user'] ?? ''));
    $ruaPass = !empty($cfg['dmarc_rua_pass']) ? (string)$cfg['dmarc_rua_pass'] : (string)($cfg['smtp_pass'] ?? '');
    $imapHost = str_replace('smtp.', 'imap.', (string)($cfg['smtp_host'] ?? 'imap.titan.email'));

    if ($ruaUser !== '' && $ruaPass !== '') {
        $mbox = @imap_open("{{$imapHost}:993/imap/ssl}INBOX", $ruaUser, $ruaPass, OP_READONLY, 1);
        if ($mbox) {
            $ruaMailboxStatus = "CONNECTED ({$ruaUser})";
            $totalMsgs = (int)(imap_check($mbox)->Nmsgs ?? 0);
            $startIdx = max(1, $totalMsgs - 29);

            $insRua = $db->prepare("
                INSERT OR IGNORE INTO dmarc_reports (
                    report_id, org_name, email, domain, policy_p, policy_pct,
                    date_begin, date_end, total_messages, total_pass, total_fail, spoofing_ips, raw_filename
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            for ($msgNo = $totalMsgs; $msgNo >= $startIdx; $msgNo--) {
                $struct = @imap_fetchstructure($mbox, $msgNo);
                if (!$struct || empty($struct->parts)) {
                    continue;
                }
                foreach ($struct->parts as $pIdx => $part) {
                    $fname = '';
                    if (!empty($part->dparameters)) {
                        foreach ($part->dparameters as $dp) {
                            if (strtolower($dp->attribute) === 'filename') {
                                $fname = strtolower((string)$dp->value);
                            }
                        }
                    }
                    if ($fname === '' && !empty($part->parameters)) {
                        foreach ($part->parameters as $pr) {
                            if (strtolower($pr->attribute) === 'name') {
                                $fname = strtolower((string)$pr->value);
                            }
                        }
                    }
                    if (!preg_match('/\.(xml|gz|zip)$/i', $fname)) {
                        continue;
                    }

                    $rawPart = @imap_fetchbody($mbox, $msgNo, (string)($pIdx + 1));
                    if (!$rawPart) continue;
                    if (($part->encoding ?? 0) == 3) {
                        $rawPart = base64_decode($rawPart);
                    } elseif (($part->encoding ?? 0) == 4) {
                        $rawPart = quoted_printable_decode($rawPart);
                    }

                    $xmlStr = '';
                    if (substr($fname, -3) === '.gz' && function_exists('gzdecode')) {
                        $xmlStr = (string)@gzdecode($rawPart);
                    } elseif (substr($fname, -4) === '.zip' && class_exists('ZipArchive')) {
                        $tmpZip = tempnam(sys_get_temp_dir(), 'ksrua_');
                        file_put_contents($tmpZip, $rawPart);
                        $zip = new ZipArchive();
                        if ($zip->open($tmpZip) === true) {
                            for ($z = 0; $z < $zip->numFiles; $z++) {
                                $zn = (string)$zip->getNameIndex($z);
                                if (substr(strtolower($zn), -4) === '.xml') {
                                    $xmlStr = (string)$zip->getFromIndex($z);
                                    break;
                                }
                            }
                            $zip->close();
                        }
                        @unlink($tmpZip);
                    } else {
                        $xmlStr = (string)$rawPart;
                    }

                    if (stripos($xmlStr, '<feedback') === false) continue;
                    libxml_use_internal_errors(true);
                    $xml = @simplexml_load_string($xmlStr, 'SimpleXMLElement', LIBXML_NOCDATA);
                    if (!$xml || !isset($xml->report_metadata) || !isset($xml->policy_published)) continue;

                    $repId = (string)($xml->report_metadata->report_id ?? md5($fname . $msgNo));
                    $org = (string)($xml->report_metadata->org_name ?? 'ISP');
                    $em = (string)($xml->report_metadata->email ?? '');
                    $dom = strtolower(trim((string)($xml->policy_published->domain ?? '')));
                    $polP = strtolower(trim((string)($xml->policy_published->p ?? 'none')));
                    $polPct = (int)($xml->policy_published->pct ?? 100);
                    $dBeg = isset($xml->report_metadata->date_range->begin) ? date('Y-m-d H:i', (int)$xml->report_metadata->date_range->begin) : date('Y-m-d H:i');
                    $dEnd = isset($xml->report_metadata->date_range->end) ? date('Y-m-d H:i', (int)$xml->report_metadata->date_range->end) : date('Y-m-d H:i');

                    $totM = 0;
                    $totP = 0;
                    $totF = 0;
                    $spIps = 0;
                    if (isset($xml->record)) {
                        foreach ($xml->record as $rec) {
                            $cnt = (int)($rec->row->count ?? 1);
                            $dkim = strtolower((string)($rec->row->policy_evaluated->dkim ?? 'fail'));
                            $spf = strtolower((string)($rec->row->policy_evaluated->spf ?? 'fail'));
                            $totM += $cnt;
                            if ($dkim === 'pass' || $spf === 'pass') {
                                $totP += $cnt;
                            } else {
                                $totF += $cnt;
                                $spIps++;
                            }
                        }
                    }

                    $insRua->execute([$repId, $org, $em, $dom, $polP, $polPct, $dBeg, $dEnd, $totM, $totP, $totF, $spIps, $fname]);
                    if ($insRua->rowCount() > 0) {
                        $ruaProcessed++;
                        if ($totF >= 10) {
                            $ruaSpoofAlerts++;
                        }
                    }
                }
            }
            @imap_close($mbox);
        } else {
            $ruaMailboxStatus = 'IMAP_AUTH_PENDING';
        }
    }
}

$elapsed = round(microtime(true) - $startedAt, 2);
$summary = [
    'executed_at' => date('Y-m-d H:i:s'),
    'actor' => $actor,
    'duration_sec' => $elapsed,
    'expired_orders' => $expiredOrders,
    'watchdog_checked' => $watchdogChecked,
    'watchdog_degradations' => count($watchdogDegradations),
    'rua_ingested' => $ruaProcessed,
    'rua_spoof_alerts' => $ruaSpoofAlerts,
    'rua_mailbox_status' => $ruaMailboxStatus,
];

AdminCore::saveSetting('last_cron_run', $summary, $actor);
AdminCore::logAudit($actor, 'CRON_CYCLE_OK', 'daemon', "Watchdog:{$watchdogChecked} · RUA:{$ruaProcessed} · Exp:{$expiredOrders} ({$elapsed}s)");

if ($isCli) {
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit;
}

AdminCore::jsonOk([
    'summary' => $summary,
    'message' => "Ciclo autónomo completado en {$elapsed}s: {$watchdogChecked} dominios auditados, {$ruaProcessed} reportes RUA nuevos, {$expiredOrders} órdenes expiradas.",
]);
