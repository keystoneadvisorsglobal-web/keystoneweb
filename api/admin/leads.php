<?php
/**
 * Keystone Command Console v4.0 — Pipeline B2B, Micro-Conversiones & Radar BEC (SOL-0334)
 */
require_once __DIR__ . '/_core.php';

AdminCore::sendHeaders();
$currentUser = AdminCore::requireAuth(['superadmin', 'comercial']);
$db = AdminCore::db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    // 1. Obtener todos los micro-leads enriquecidos con el último score BEC conocido de su dominio
    $leadsSql = "
        SELECT
            m.id,
            m.order_ref,
            m.action,
            m.email,
            m.domain,
            m.pillar,
            m.service_name,
            m.price_info,
            m.scan_summary,
            COALESCE(m.status, 'NUEVO') AS status,
            COALESCE(m.priority, 'ALTA') AS priority,
            COALESCE(m.assigned_to, '') AS assigned_to,
            COALESCE(m.notes, '') AS notes,
            COALESCE(m.keyhub_card_code, '') AS keyhub_card_code,
            COALESCE(m.keyhub_project_key, '') AS keyhub_project_key,
            m.keyhub_synced_at,
            m.ip_address,
            m.created_at,
            m.updated_at,
            (
                SELECT b.score
                FROM bec_scans b
                WHERE LOWER(b.domain) = LOWER(m.domain)
                ORDER BY b.id DESC
                LIMIT 1
            ) AS latest_bec_score,
            (
                SELECT b.dmarc_label
                FROM bec_scans b
                WHERE LOWER(b.domain) = LOWER(m.domain)
                ORDER BY b.id DESC
                LIMIT 1
            ) AS latest_dmarc
        FROM micro_leads m
        ORDER BY m.id DESC
        LIMIT 250
    ";
    $leads = $db->query($leadsSql)->fetchAll();

    // 2. Obtener escaneos BEC recientes
    $scans = $db->query("
        SELECT id, scan_ref, domain, email, score, risk_level, dmarc_label, spf_status, mx_status, tls_status, ip_address, created_at
        FROM bec_scans
        ORDER BY id DESC
        LIMIT 250
    ")->fetchAll();

    // 3. Calcular KPIs ejecutivos del Pulso
    $totalLeads = count($leads);
    $newLeads = 0;
    $inProgressLeads = 0;
    $wonLeads = 0;
    $pillarCounts = [
        'Digital Core' => 0,
        'DeepShield' => 0,
        'KeyHub' => 0,
    ];
    $uniqueDomains = [];

    foreach ($leads as $l) {
        $st = strtoupper((string)$l['status']);
        if ($st === 'NUEVO') {
            $newLeads++;
        } elseif (in_array($st, ['DIAGNOSTICO_ENVIADO', 'REUNION_EJECUTIVA', 'PROPUESTA'], true)) {
            $inProgressLeads++;
        } elseif ($st === 'GANADO_KEYHUB') {
            $wonLeads++;
        }

        $p = (string)($l['pillar'] ?? '');
        if (stripos($p, 'Digital Core') !== false || stripos($p, 'CRM') !== false) {
            $pillarCounts['Digital Core']++;
        } elseif (stripos($p, 'DeepShield') !== false || stripos($p, 'BEC') !== false) {
            $pillarCounts['DeepShield']++;
        } elseif (stripos($p, 'KeyHub') !== false) {
            $pillarCounts['KeyHub']++;
        }

        if (!empty($l['domain'])) {
            $uniqueDomains[strtolower($l['domain'])] = true;
        }
    }

    $totalScans = count($scans);
    $sumScore = 0;
    $exposedDomainsCount = 0;
    foreach ($scans as $s) {
        $sc = (int)$s['score'];
        $sumScore += $sc;
        if ($sc < 80) {
            $exposedDomainsCount++;
        }
        if (!empty($s['domain'])) {
            $uniqueDomains[strtolower($s['domain'])] = true;
        }
    }
    $avgBecScore = $totalScans > 0 ? (int)round($sumScore / $totalScans) : 100;

    AdminCore::jsonOk([
        'kpis' => [
            'total_leads' => $totalLeads,
            'new_leads' => $newLeads,
            'active_pipeline' => $inProgressLeads,
            'won_leads' => $wonLeads,
            'total_scans' => $totalScans,
            'avg_bec_score' => $avgBecScore,
            'exposed_scans' => $exposedDomainsCount,
            'unique_organizations' => count($uniqueDomains),
            'by_pillar' => $pillarCounts,
        ],
        'leads' => $leads,
        'bec_scans' => $scans,
    ]);
}

if ($method === 'POST') {
    $body = AdminCore::readJsonBody();
    $action = trim((string)($body['action'] ?? ''));

    if ($action === 'update_lead') {
        $leadId = (int)($body['id'] ?? 0);
        if ($leadId <= 0) {
            AdminCore::jsonError('Identificador de solicitud inválido.', 422);
        }

        $allowedStatuses = ['NUEVO', 'DIAGNOSTICO_ENVIADO', 'REUNION_EJECUTIVA', 'PROPUESTA', 'GANADO_KEYHUB', 'DESCARTADO'];
        $status = strtoupper(trim((string)($body['status'] ?? 'NUEVO')));
        if (!in_array($status, $allowedStatuses, true)) {
            $status = 'NUEVO';
        }

        $priority = strtoupper(trim((string)($body['priority'] ?? 'ALTA')));
        if (!in_array($priority, ['CRITICA', 'ALTA', 'MEDIA', 'BAJA'], true)) {
            $priority = 'ALTA';
        }

        $assignedTo = trim((string)($body['assigned_to'] ?? ''));
        $notes = trim((string)($body['notes'] ?? ''));

        $stmt = $db->prepare("
            UPDATE micro_leads
            SET status = ?, priority = ?, assigned_to = ?, notes = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([$status, $priority, $assignedTo, $notes, $leadId]);

        AdminCore::logAudit($currentUser['username'], 'LEAD_UPDATE', "LEAD#{$leadId}", "Estado -> {$status}");
        AdminCore::jsonOk(['message' => 'Solicitud actualizada correctamente.']);
    }

    if ($action === 'create_manual_lead') {
        $email = strtolower(trim((string)($body['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            AdminCore::jsonError('Ingrese un correo corporativo válido.', 422);
        }
        $parts = explode('@', $email);
        $domain = trim((string)($body['domain'] ?? ($parts[1] ?? '')));
        $pillar = trim((string)($body['pillar'] ?? 'Digital Core'));
        $serviceName = trim((string)($body['service_name'] ?? 'Diagnóstico Ejecutivo'));
        $priceInfo = trim((string)($body['price_info'] ?? 'A medida'));
        $notes = trim((string)($body['notes'] ?? ''));
        $orderRef = 'KS-' . strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 7));

        $ins = $db->prepare("
            INSERT INTO micro_leads (order_ref, action, email, domain, pillar, service_name, price_info, status, priority, assigned_to, notes, ip_address)
            VALUES (?, 'manual_entry', ?, ?, ?, ?, ?, 'NUEVO', 'ALTA', ?, ?, ?)
        ");
        $ins->execute([
            $orderRef,
            $email,
            $domain,
            $pillar,
            $serviceName,
            $priceInfo,
            $currentUser['full_name'] ?? $currentUser['username'],
            $notes,
            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
        ]);

        AdminCore::logAudit($currentUser['username'], 'LEAD_CREATE', $orderRef, "{$email} · {$pillar}");
        AdminCore::jsonOk(['message' => 'Oportunidad registrada en el pipeline.', 'order_ref' => $orderRef]);
    }

    if ($action === 'delete_lead') {
        if (($currentUser['role'] ?? '') !== 'superadmin') {
            AdminCore::jsonError('Solo Superadmin puede eliminar registros del pipeline.', 403);
        }
        $leadId = (int)($body['id'] ?? 0);
        $del = $db->prepare("DELETE FROM micro_leads WHERE id = ?");
        $del->execute([$leadId]);
        AdminCore::logAudit($currentUser['username'], 'LEAD_DELETE', "LEAD#{$leadId}", 'Registro eliminado');
        AdminCore::jsonOk(['message' => 'Registro eliminado.']);
    }

    AdminCore::jsonError('Acción no reconocida en pipeline.', 400);
}

AdminCore::jsonError('Método no permitido.', 405);
