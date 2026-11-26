<?php
/**
 * Keystone Command Console v4.4 — Bandeja IMAP, Conector API REST LinkedIn v202610 & Outbound B2B con Tracking (SOL-0336 / SOL-0342)
 */
require_once __DIR__ . '/_core.php';

AdminCore::sendHeaders();
$currentUser = AdminCore::requireAuth(['superadmin', 'comercial']);
$db = AdminCore::db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $cfg = AdminCore::getNotificationSettings();
    $imapUser = trim((string)($cfg['smtp_user'] ?? 'contacto@keystoneadvisors.cl'));
    $imapHost = str_replace('smtp.', 'imap.', (string)($cfg['smtp_host'] ?? 'imap.titan.email'));
    $replies = $db->query("SELECT * FROM email_replies ORDER BY received_at DESC, id DESC LIMIT 60")->fetchAll();
    $campaigns = $db->query("SELECT * FROM growth_campaigns ORDER BY id DESC LIMIT 40")->fetchAll();
    $outbound = $db->query("SELECT * FROM outbound_dispatches ORDER BY id DESC LIMIT 50")->fetchAll();

    AdminCore::jsonOk([
        'imap_supported' => function_exists('imap_open'),
        'imap_mailbox' => $imapUser,
        'imap_host' => $imapHost . ':993/SSL',
        'linkedin_configured' => !empty($cfg['linkedin_access_token']),
        'replies' => $replies,
        'growth_campaigns' => $campaigns,
        'outbound_dispatches' => $outbound,
    ]);
}

if ($method === 'POST') {
    $body = AdminCore::readJsonBody();
    $action = trim((string)($body['action'] ?? ''));

    // 1. Sincronización en vivo de respuestas entrantes IMAP (Titan Mail)
    if ($action === 'sync_imap') {
        if (!function_exists('imap_open')) {
            AdminCore::jsonError('La extensión PHP IMAP no está activa en este worker.', 500);
        }
        $cfg = AdminCore::getNotificationSettings();
        $user = trim((string)($cfg['smtp_user'] ?? 'contacto@keystoneadvisors.cl'));
        $pass = (string)($cfg['smtp_pass'] ?? '');
        if ($pass === '') {
            AdminCore::jsonError('Configure primero la clave de casilla en Canales & SSO.', 422);
        }

        $imapHost = str_replace('smtp.', 'imap.', (string)($cfg['smtp_host'] ?? 'imap.titan.email'));
        $mbox = @imap_open("{{$imapHost}:993/imap/ssl}INBOX", $user, $pass, OP_READONLY, 1);
        if (!$mbox) {
            AdminCore::jsonError('No fue posible abrir casilla IMAP: ' . (imap_last_error() ?: 'Verifique credenciales'), 500);
        }

        $check = imap_check($mbox);
        $total = (int)($check->Nmsgs ?? 0);
        if ($total === 0) {
            $purged = $db->exec("DELETE FROM email_replies");
            imap_close($mbox);
            AdminCore::logAudit($currentUser['username'], 'IMAP_SYNC_EMPTY', $user, 'Casilla vacía; bandeja espejo limpiada');
            AdminCore::jsonOk([
                'new_count' => 0,
                'message' => "La casilla {$user} está vacía. Se limpiaron {$purged} registros locales.",
            ]);
        }

        $start = max(1, $total - 49);
        $added = 0;
        $serverMessageIds = [];

        $ins = $db->prepare("
            INSERT OR IGNORE INTO email_replies (message_id, sender_name, sender_email, subject, snippet, linked_lead_ref, received_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        for ($i = $total; $i >= $start; $i--) {
            $hdr = @imap_headerinfo($mbox, $i);
            if (!$hdr) continue;
            $fromObj = $hdr->from[0] ?? null;
            $senderEmail = strtolower(trim(($fromObj->mailbox ?? '') . '@' . ($fromObj->host ?? '')));
            if ($senderEmail === '' || $senderEmail === strtolower($user) || strpos($senderEmail, 'mailer-daemon') !== false) {
                continue;
            }
            $msgId = trim((string)($hdr->message_id ?? md5($senderEmail . ($hdr->date ?? '') . $i)));
            $serverMessageIds[] = $msgId;
            $senderName = isset($fromObj->personal) ? imap_utf8((string)$fromObj->personal) : $senderEmail;
            $subj = isset($hdr->subject) ? imap_utf8((string)$hdr->subject) : '(Sin asunto)';
            $dateStr = date('Y-m-d H:i:s', strtotime((string)($hdr->date ?? 'now')));

            $rawBody = @imap_fetchbody($mbox, $i, '1');
            $cleanText = trim(preg_replace('/\s+/', ' ', strip_tags(quoted_printable_decode((string)$rawBody))));
            $snippet = mb_substr($cleanText, 0, 220);

            $lStmt = $db->prepare("SELECT order_ref FROM micro_leads WHERE LOWER(email) = ? ORDER BY id DESC LIMIT 1");
            $lStmt->execute([$senderEmail]);
            $leadRef = (string)($lStmt->fetchColumn() ?: '');

            $ins->execute([$msgId, $senderName, $senderEmail, $subj, $snippet, $leadRef, $dateStr]);
            if ($ins->rowCount() > 0) {
                $added++;
            }
        }

        if (!empty($serverMessageIds)) {
            $placeholders = implode(',', array_fill(0, count($serverMessageIds), '?'));
            $purgeStmt = $db->prepare("DELETE FROM email_replies WHERE message_id NOT IN ({$placeholders})");
            $purgeStmt->execute($serverMessageIds);
        } else {
            $db->exec("DELETE FROM email_replies");
        }

        imap_close($mbox);

        AdminCore::logAudit($currentUser['username'], 'IMAP_SYNC', $user, "Nuevas respuestas: {$added}");
        AdminCore::jsonOk([
            'new_count' => $added,
            'message' => "Casilla {$user} sincronizada en espejo ({$added} nuevos correos).",
        ]);
    }

    if ($action === 'clear_imap_replies') {
        $deleted = (int)$db->exec("DELETE FROM email_replies");
        AdminCore::logAudit($currentUser['username'], 'IMAP_CLEAR', 'email_replies', "Registros limpiados: {$deleted}");
        AdminCore::jsonOk([
            'deleted' => $deleted,
            'message' => "Bandeja sincronizada limpiada ({$deleted} correos removidos de la vista local).",
        ]);
    }

    // 2. Generador de Campaña / Post Ejecutivo B2B (Growth & LinkedIn IA)
    if ($action === 'create_growth_campaign') {
        $name = trim((string)($body['campaign_name'] ?? ''));
        $channel = strtoupper(trim((string)($body['channel'] ?? 'LINKEDIN')));
        $pillar = trim((string)($body['pillar_focus'] ?? 'Digital Core'));
        $audience = trim((string)($body['target_audience'] ?? 'CIOs, CTOs y Gerentes de Finanzas'));
        $budget = (float)($body['budget_usd'] ?? 0);

        if ($name === '') {
            AdminCore::jsonError('Indique el nombre o tema de la campaña.', 422);
        }

        if (stripos($pillar, 'DeepShield') !== false) {
            $headline = "El fraude BEC asistido por clonación de voz ya no se detiene con capacitación tradicional.";
            $copy = "Hoy los directorios exigen certeza criptográfica antes de liberar tesorería.\n\nEn Keystone Advisors combinamos endurecimiento DMARC p=reject con aprobación Fuera de Banda (DeepShield Treasury OOB), detector automático de discrepancias contra Padrón Verificado y desafío Anti-Voz IA.\n\nEvalúa en 30 segundos la exposición pasiva de tu dominio en https://keystoneadvisors.cl — Cero fricción, cumplimiento Ley N° 21.459.";
        } elseif (stripos($pillar, 'KeyHub') !== false) {
            $headline = "La IA comoditizó escribir código. El nuevo cuello de botella es el Gobierno de Ingeniería.";
            $copy = "Producir 5x más líneas sin control solo multiplica la deuda técnica.\n\nCon KeyHub + Code Knowledge Graph AST logramos hasta 97% de ahorro en consumo de tokens, cadena de custodia GitFlow obligatoria y cuadratura forense de horas para banca y corporativos.\n\nVelocidad de escuadrón sintético con criterio arquitectónico senior.";
        } else {
            $headline = "De meses de 'body shopping' a arquitecturas Salesforce FSC & IA certificadas en semanas.";
            $copy = "Los CIOs dejaron de comprar horas hombre infladas; hoy compran outcomes auditables sobre ecosistemas reales.\n\nNuestros Squads Híbridos Senior + Agentes IA operan con bisturí sobre Salesforce Financial Services Cloud, SAP y APIs críticas sin interrumpir la continuidad operativa.";
        }

        $insG = $db->prepare("
            INSERT INTO growth_campaigns (campaign_name, channel, pillar_focus, target_audience, ai_copy_headline, ai_copy_body, budget_usd, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'READY')
        ");
        $insG->execute([$name, $channel, $pillar, $audience, $headline, $copy, $budget]);

        AdminCore::logAudit($currentUser['username'], 'GROWTH_CREATE', $name, "{$channel} · {$pillar}");
        AdminCore::jsonOk(['message' => 'Pieza ejecutiva Growth generada y lista para despacho.']);
    }

    // 3. Publicación Directa vía API Oficial de LinkedIn REST v202610 (Organización -> Fallback Perfil Fundador)
    if ($action === 'publish_linkedin') {
        $id = (int)($body['id'] ?? 0);
        $cStmt = $db->prepare("SELECT * FROM growth_campaigns WHERE id = ? LIMIT 1");
        $cStmt->execute([$id]);
        $camp = $cStmt->fetch();
        if (!$camp) {
            AdminCore::jsonError('Campaña no encontrada.', 404);
        }

        $cfg = AdminCore::getNotificationSettings();
        $liToken = trim((string)($cfg['linkedin_access_token'] ?? ''));
        $orgUrn = trim((string)($cfg['linkedin_org_urn'] ?? ''));

        if ($liToken === '') {
            $db->prepare("UPDATE growth_campaigns SET status = 'APPROVED' WHERE id = ?")->execute([$id]);
            AdminCore::jsonOk([
                'published_live' => false,
                'message' => 'Pieza marcada como Aprobada. Ingrese su Access Token OAuth de LinkedIn en Canales & SSO para publicación directa vía API.',
            ]);
        }

        // Resolver Member URN vía /v2/userinfo como respaldo si no hay Organization URN
        $candidateUrns = [];
        if ($orgUrn !== '') {
            $candidateUrns[] = strpos($orgUrn, 'urn:li:') === 0 ? $orgUrn : ('urn:li:organization:' . preg_replace('/[^0-9]/', '', $orgUrn));
        }

        $chMe = curl_init('https://api.linkedin.com/v2/userinfo');
        curl_setopt($chMe, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $liToken]);
        curl_setopt($chMe, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($chMe, CURLOPT_TIMEOUT, 8);
        $meRes = curl_exec($chMe);
        $meCode = (int)curl_getinfo($chMe, CURLINFO_HTTP_CODE);
        curl_close($chMe);
        if ($meCode === 200 && $meRes) {
            $meJson = json_decode($meRes, true);
            if (!empty($meJson['sub'])) {
                $mUrn = 'urn:li:person:' . $meJson['sub'];
                if (!in_array($mUrn, $candidateUrns, true)) {
                    $candidateUrns[] = $mUrn;
                }
            }
        }

        if (empty($candidateUrns)) {
            AdminCore::jsonError('El Access Token de LinkedIn expiró o no retornó un URN válido en /v2/userinfo.', 422);
        }

        $fullCommentary = $camp['ai_copy_headline'] . "\n\n" . $camp['ai_copy_body'];
        $lastErr = '';

        foreach ($candidateUrns as $authorUrn) {
            $payload = [
                'author' => $authorUrn,
                'commentary' => $fullCommentary,
                'visibility' => 'PUBLIC',
                'distribution' => [
                    'feedDistribution' => 'MAIN_FEED',
                    'targetEntities' => [],
                    'thirdPartyDistributionChannels' => [],
                ],
                'content' => [
                    'article' => [
                        'source' => 'https://keystoneadvisors.cl',
                        'title' => $camp['campaign_name'] . ' | Keystone Advisors Global',
                    ],
                ],
                'lifecycleState' => 'PUBLISHED',
                'isReshareDisabledByAuthor' => false,
            ];

            $chPost = curl_init('https://api.linkedin.com/rest/posts');
            curl_setopt($chPost, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $liToken,
                'Content-Type: application/json',
                'LinkedIn-Version: 202610',
                'X-Restli-Protocol-Version: 2.0.0',
            ]);
            curl_setopt($chPost, CURLOPT_POST, true);
            curl_setopt($chPost, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($chPost, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($chPost, CURLOPT_HEADER, true);
            curl_setopt($chPost, CURLOPT_TIMEOUT, 12);

            $resp = curl_exec($chPost);
            $httpCode = (int)curl_getinfo($chPost, CURLINFO_HTTP_CODE);
            $hdrSize = (int)curl_getinfo($chPost, CURLINFO_HEADER_SIZE);
            $hdrStr = substr((string)$resp, 0, $hdrSize);
            $bodyStr = substr((string)$resp, $hdrSize);
            curl_close($chPost);

            if ($httpCode === 201) {
                $postUrn = '';
                if (preg_match('/x-restli-id:\s*([^\r\n]+)/i', $hdrStr, $m)) {
                    $postUrn = trim($m[1]);
                }
                $feedUrl = $postUrn !== '' ? ('https://www.linkedin.com/feed/update/' . rawurlencode($postUrn) . '/') : 'https://www.linkedin.com/';
                $db->prepare("UPDATE growth_campaigns SET status = 'PUBLISHED', published_url = ? WHERE id = ?")->execute([$feedUrl, $id]);
                AdminCore::logAudit($currentUser['username'], 'LINKEDIN_API_PUBLISHED', (string)$id, "URN: {$postUrn} ({$authorUrn})");
                AdminCore::jsonOk([
                    'published_live' => true,
                    'post_urn' => $postUrn,
                    'published_url' => $feedUrl,
                    'message' => "Publicado en vivo en LinkedIn ({$authorUrn}).",
                ]);
            }
            $lastErr = "HTTP {$httpCode}: " . substr($bodyStr, 0, 180);
        }

        AdminCore::jsonError('LinkedIn API rechazó la publicación: ' . $lastErr, 502);
    }

    // 4. Despacho Outbound B2B Personalizado con Telemetría de Apertura y Clic (/api/track.php)
    if ($action === 'send_outbound_b2b') {
        $campId = (int)($body['campaign_id'] ?? 0);
        $toEmail = strtolower(trim((string)($body['recipient_email'] ?? '')));
        $toName = trim((string)($body['recipient_name'] ?? 'Ejecutivo'));

        if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            AdminCore::jsonError('Indique un correo corporativo válido para el despacho outbound.', 422);
        }

        $cStmt = $db->prepare("SELECT * FROM growth_campaigns WHERE id = ? LIMIT 1");
        $cStmt->execute([$campId]);
        $camp = $cStmt->fetch();
        if (!$camp) {
            AdminCore::jsonError('Seleccione primero una pieza ejecutiva Growth.', 404);
        }

        $trackTok = 'out_' . bin2hex(random_bytes(12));
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'dev.keystoneadvisors.cl';
        $pixelUrl = "{$proto}://{$host}/api/track.php?o={$trackTok}";
        $ctaUrl = "{$proto}://{$host}/api/track.php?c={$trackTok}&p=trust";

        $subj = "Keystone Advisors | " . $camp['ai_copy_headline'];
        $bodyParagraphs = nl2br(htmlspecialchars((string)$camp['ai_copy_body'], ENT_QUOTES, 'UTF-8'));

        $html = "<div style='font-family:Arial,sans-serif;max-width:620px;margin:0 auto;background:#0A0E15;color:#F8FAFC;padding:28px;border-radius:14px;border:1px solid #1E293B;'>"
            . "<div style='font-size:11px;letter-spacing:2px;color:#34D399;font-weight:bold;text-transform:uppercase;'>Keystone Advisors Global · Ingeniería & Cyber Defense</div>"
            . "<h2 style='color:#FFFFFF;font-size:18px;line-height:1.35;margin:12px 0;'>" . htmlspecialchars((string)$camp['ai_copy_headline'], ENT_QUOTES, 'UTF-8') . "</h2>"
            . "<p style='color:#CBD5E1;font-size:13.5px;line-height:1.6;'>Estimado/a " . htmlspecialchars($toName, ENT_QUOTES, 'UTF-8') . ",</p>"
            . "<div style='color:#CBD5E1;font-size:13.5px;line-height:1.65;'>{$bodyParagraphs}</div>"
            . "<div style='margin:24px 0;'>"
            . "<a href='{$ctaUrl}' style='display:inline-block;background:#6EE7B7;color:#0A0E15;font-weight:bold;font-size:12px;text-decoration:none;padding:11px 20px;border-radius:8px;'>INSPECCIONAR ARQUITECTURA & TRUST CENTER &rarr;</a>"
            . "</div>"
            . "<hr style='border:none;border-top:1px solid #1E293B;margin:20px 0;'/>"
            . "<div style='font-size:11px;color:#64748B;'>Keystone Advisors SpA · Santiago, Chile · Comunicación ejecutiva B2B</div>"
            . "<img src='{$pixelUrl}' width='1' height='1' alt='' style='display:none;' />"
            . "</div>";

        $sendRes = AdminCore::sendSmtpEmail($toEmail, $subj, $html);
        if (empty($sendRes['success'])) {
            AdminCore::jsonError($sendRes['error'] ?? 'No fue posible despachar el correo outbound.', 500);
        }

        // Vincular lead si existe
        $lStmt = $db->prepare("SELECT id FROM micro_leads WHERE LOWER(email) = ? ORDER BY id DESC LIMIT 1");
        $lStmt->execute([$toEmail]);
        $leadId = (int)($lStmt->fetchColumn() ?: 0);

        $insO = $db->prepare("
            INSERT INTO outbound_dispatches (campaign_id, lead_id, recipient_email, recipient_name, subject, track_token, status)
            VALUES (?, ?, ?, ?, ?, ?, 'SENT')
        ");
        $insO->execute([$campId, $leadId, $toEmail, $toName, $subj, $trackTok]);

        AdminCore::logAudit($currentUser['username'], 'OUTBOUND_B2B_SENT', $toEmail, $camp['campaign_name']);
        AdminCore::jsonOk([
            'track_token' => $trackTok,
            'message' => "Secuencia ejecutiva enviada a {$toEmail} con telemetría activa.",
        ]);
    }

    if ($action === 'delete_growth_campaign') {
        $id = (int)($body['id'] ?? 0);
        $db->prepare("DELETE FROM growth_campaigns WHERE id = ?")->execute([$id]);
        AdminCore::jsonOk(['message' => 'Pieza eliminada.']);
    }

    AdminCore::jsonError('Acción desconocida.', 400);
}

AdminCore::jsonError('Método no permitido.', 405);
