<?php
/**
 * Keystone Command Console v4.1 — Puente en 1 Clic hacia KeyHub Producción (SOL-0335)
 * Convierte cualquier oportunidad B2B de dev.keystoneadvisors.cl en una tarjeta trazable en el tablero oficial de KeyHub
 * (Regla #6 de KeyHub: Operación directa sobre la base oficial de producción en keyhub/database/keyhub.sqlite).
 */
require_once __DIR__ . '/_core.php';

AdminCore::sendHeaders();
$currentUser = AdminCore::requireAuth(['superadmin', 'comercial']);
$stagingDb = AdminCore::db();
$keyhubDbPath = AdminCore::getPublicHtmlRoot() . '/keyhub/database/keyhub.sqlite';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    if (!is_readable($keyhubDbPath)) {
        AdminCore::jsonOk(['available' => false, 'projects' => []]);
    }
    try {
        $khDb = new PDO('sqlite:' . $keyhubDbPath);
        $khDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $projects = $khDb->query("SELECT id, project_key, name FROM projects ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
        AdminCore::jsonOk([
            'available' => true,
            'projects' => $projects,
        ]);
    } catch (Exception $e) {
        AdminCore::jsonError('Error consultando tableros KeyHub: ' . $e->getMessage(), 500);
    }
}

if ($method === 'POST') {
    $body = AdminCore::readJsonBody();
    $leadId = (int)($body['lead_id'] ?? 0);
    $projectId = trim((string)($body['project_id'] ?? 'prj_ksweb_01'));
    $cardType = in_array($body['card_type'] ?? '', ['TASK', 'STORY', 'EPIC'], true) ? $body['card_type'] : 'TASK';

    if ($leadId <= 0) {
        AdminCore::jsonError('Seleccione una oportunidad válida.', 422);
    }

    if (!is_writable($keyhubDbPath)) {
        AdminCore::jsonError('La base oficial de KeyHub no está accesible en este entorno.', 500);
    }

    $lStmt = $stagingDb->prepare("
        SELECT m.*,
               (SELECT b.score FROM bec_scans b WHERE LOWER(b.domain) = LOWER(m.domain) ORDER BY b.id DESC LIMIT 1) AS bec_score,
               (SELECT b.dmarc_label FROM bec_scans b WHERE LOWER(b.domain) = LOWER(m.domain) ORDER BY b.id DESC LIMIT 1) AS bec_dmarc,
               (SELECT b.spf_status FROM bec_scans b WHERE LOWER(b.domain) = LOWER(m.domain) ORDER BY b.id DESC LIMIT 1) AS bec_spf
        FROM micro_leads m
        WHERE m.id = ?
        LIMIT 1
    ");
    $lStmt->execute([$leadId]);
    $lead = $lStmt->fetch();

    if (!$lead) {
        AdminCore::jsonError('Oportunidad no encontrada.', 404);
    }

    if (!empty($lead['keyhub_card_code'])) {
        AdminCore::jsonOk([
            'already_synced' => true,
            'card_code' => $lead['keyhub_card_code'],
            'project_key' => $lead['keyhub_project_key'],
            'message' => "Esta oportunidad ya está vinculada a la tarjeta {$lead['keyhub_card_code']}.",
        ]);
    }

    try {
        $khDb = new PDO('sqlite:' . $keyhubDbPath);
        $khDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $khDb->exec('PRAGMA foreign_keys = ON;');

        // 1. Verificar proyecto destino
        $pStmt = $khDb->prepare("SELECT id, project_key, name FROM projects WHERE id = ? LIMIT 1");
        $pStmt->execute([$projectId]);
        $proj = $pStmt->fetch(PDO::FETCH_ASSOC);
        if (!$proj) {
            AdminCore::jsonError('Tablero destino no existe en KeyHub.', 404);
        }
        $pKey = strtoupper(trim($proj['project_key']));

        // 2. Determinar primera columna activa del proyecto (TODO / BACKLOG)
        $colStmt = $khDb->prepare("SELECT column_key FROM project_columns WHERE project_id = ? ORDER BY position ASC LIMIT 1");
        $colStmt->execute([$projectId]);
        $firstCol = (string)($colStmt->fetchColumn() ?: 'TODO');

        // 3. Calcular siguiente correlativo numérico exacto para project_key (ej. KSWEB-14)
        $codesStmt = $khDb->prepare("SELECT card_code FROM cards WHERE project_id = ? OR card_code LIKE ?");
        $codesStmt->execute([$projectId, $pKey . '-%']);
        $maxSeq = 0;
        foreach ($codesStmt->fetchAll(PDO::FETCH_COLUMN) as $existingCode) {
            if (preg_match('/^' . preg_quote($pKey, '/') . '-(\d+)$/i', (string)$existingCode, $m)) {
                $num = (int)$m[1];
                if ($num > $maxSeq) {
                    $maxSeq = $num;
                }
            }
        }
        $nextCode = $pKey . '-' . ($maxSeq + 1);
        $newCardId = 'card_' . bin2hex(random_bytes(8));

        $orgLabel = $lead['domain'] ?: $lead['email'];
        $title = "[Lead B2B · {$orgLabel}] " . ($lead['service_name'] ?: 'Diagnóstico Ejecutivo');
        $becBlock = ($lead['bec_score'] !== null)
            ? "\n\n### Telemetría Pasiva DNS / BEC ({$lead['domain']})\n- **Score:** {$lead['bec_score']}/100\n- **DMARC:** {$lead['bec_dmarc']}\n- **SPF:** {$lead['bec_spf']}"
            : '';
        $notesBlock = !empty($lead['notes']) ? "\n\n### Notas Ejecutivas\n{$lead['notes']}" : '';

        $description = "## Oportunidad Comercial Capturada en Sitio Espacial v4\n"
            . "- **Referencia Origen:** `{$lead['order_ref']}`\n"
            . "- **Contacto Corporativo:** `{$lead['email']}`\n"
            . "- **Dominio / Empresa:** `{$orgLabel}`\n"
            . "- **Pilar de Negocio:** {$lead['pillar']}\n"
            . "- **Solución / Oferta:** {$lead['service_name']} ({$lead['price_info']})\n"
            . "- **Sincronizado por:** @{$currentUser['username']}"
            . $becBlock
            . $notesBlock;

        $insCard = $khDb->prepare("
            INSERT INTO cards (
                id, project_id, card_code, card_type, title, description,
                status, priority, start_date, estimated_hours,
                target_record_name, target_record_id, target_record_url,
                status_changed_at, created_at
            ) VALUES (
                :id, :project_id, :card_code, :card_type, :title, :description,
                :status, 'HIGH', date('now'), 2.0,
                :target_name, :target_id, :target_url,
                CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
        ");
        $insCard->execute([
            ':id' => $newCardId,
            ':project_id' => $projectId,
            ':card_code' => $nextCode,
            ':card_type' => $cardType,
            ':title' => $title,
            ':description' => $description,
            ':status' => $firstCol,
            ':target_name' => $orgLabel,
            ':target_id' => $lead['order_ref'],
            ':target_url' => 'https://' . ($lead['domain'] ?: 'keystoneadvisors.cl'),
        ]);

        // Actualizar trazabilidad en micro_leads de la consola
        $updLead = $stagingDb->prepare("
            UPDATE micro_leads
            SET status = 'GANADO_KEYHUB',
                keyhub_card_code = ?,
                keyhub_project_key = ?,
                keyhub_synced_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $updLead->execute([$nextCode, $pKey, $leadId]);

        AdminCore::logAudit($currentUser['username'], 'KEYHUB_SYNC', $nextCode, "Lead {$lead['order_ref']} -> Tablero {$pKey}");

        AdminCore::jsonOk([
            'card_id' => $newCardId,
            'card_code' => $nextCode,
            'project_key' => $pKey,
            'project_name' => $proj['name'],
            'message' => "Tarjeta {$nextCode} creada exitosamente en el tablero {$proj['name']} de KeyHub.",
        ]);
    } catch (Exception $e) {
        AdminCore::jsonError('Fallo al crear tarjeta en KeyHub: ' . $e->getMessage(), 500);
    }
}

AdminCore::jsonError('Método no permitido.', 405);
