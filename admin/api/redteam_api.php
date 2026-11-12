<?php
/**
 * Keystone Red Teaming - Endpoint Interno AJAX para Consola Administrativa
 * Ruta: /admin/api/redteam_api.php
 */

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin_logged'])) {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

$dbFile = __DIR__ . '/../../data/leads_keystone.sqlite';
try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    require_once __DIR__ . '/../../api/RedTeamingService.php';
    require_once __DIR__ . '/../../api/SiiLureTemplates.php';
require_once __DIR__ . '/../../api/BankLureTemplates.php';
    require_once __DIR__ . '/../../api/KeystoneSmtpClient.php';
    $rtService = new RedTeamingService($db);

    $action = $_GET['action'] ?? ($_POST['action'] ?? '');

    

if ($action === 'delete_target') {
    $targetId = (int)($_POST['target_id'] ?? 0);
    $stmt = $db->prepare("DELETE FROM redteam_targets WHERE id = :id");
    $stmt->bindValue(':id', $targetId, PDO::PARAM_INT);
    $stmt->execute();
    echo json_encode(['success' => true, 'message' => 'Colaborador eliminado']);
    exit;
}

if ($action === 'edit_target') {
    $targetId = (int)($_POST['target_id'] ?? 0);
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = trim($_POST['role'] ?? '');
    $department = trim($_POST['department'] ?? '');

    $stmt = $db->prepare("UPDATE redteam_targets SET full_name = :fn, email = :em, role = :rl, department = :dp WHERE id = :id");
    $stmt->bindValue(':fn', $fullName, PDO::PARAM_STR);
    $stmt->bindValue(':em', $email, PDO::PARAM_STR);
    $stmt->bindValue(':rl', $role, PDO::PARAM_STR);
    $stmt->bindValue(':dp', $department, PDO::PARAM_STR);
    $stmt->bindValue(':id', $targetId, PDO::PARAM_INT);
    $stmt->execute();
    echo json_encode(['success' => true, 'message' => 'Colaborador actualizado']);
    exit;
}

if ($action === 'get_client_artifacts') {
    $campaignId = (int)($_GET['campaign_id'] ?? $_POST['campaign_id'] ?? 0);
    $stmt = $db->prepare("SELECT * FROM redteam_client_artifacts WHERE campaign_id = :cid ORDER BY id DESC");
    $stmt->bindValue(':cid', $campaignId, PDO::PARAM_INT);
    $stmt->execute();
    $artifacts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'data' => $artifacts]);
    exit;
}

if ($action === 'upload_client_artifact') {
    $campaignId = (int)($_POST['campaign_id'] ?? 0);
    $title = trim($_POST['title'] ?? 'Insumo de Comunicación');
    $category = trim($_POST['category'] ?? 'BANKING');
    $notes = trim($_POST['notes'] ?? '');

    if ($campaignId <= 0 || empty($_FILES['artifact_file']['name'])) {
        echo json_encode(['success' => false, 'error' => 'Archivo o ID de campaña faltante']);
        exit;
    }

    $file = $_FILES['artifact_file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['pdf', 'png', 'jpg', 'jpeg', 'eml', 'msg', 'docx'];

    if (!in_array($ext, $allowed)) {
        echo json_encode(['success' => false, 'error' => 'Formato no permitido. Use PDF, PNG, JPG, EML o DOCX.']);
        exit;
    }

    $uploadDir = __DIR__ . '/../../data/redteam_artifacts/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $safeName = 'art_' . $campaignId . '_' . time() . '_' . preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $file['name']);
    $destPath = $uploadDir . $safeName;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        echo json_encode(['success' => false, 'error' => 'Error al guardar el archivo en el servidor']);
        exit;
    }

    $relPath = 'data/redteam_artifacts/' . $safeName;
    $stmt = $db->prepare("INSERT INTO redteam_client_artifacts (campaign_id, title, category, file_name, file_path, file_type, file_size, notes) VALUES (:cid, :title, :cat, :fname, :fpath, :ftype, :fsize, :notes)");
    $stmt->bindValue(':cid', $campaignId, PDO::PARAM_INT);
    $stmt->bindValue(':title', $title, PDO::PARAM_STR);
    $stmt->bindValue(':cat', $category, PDO::PARAM_STR);
    $stmt->bindValue(':fname', $file['name'], PDO::PARAM_STR);
    $stmt->bindValue(':fpath', $relPath, PDO::PARAM_STR);
    $stmt->bindValue(':ftype', $ext, PDO::PARAM_STR);
    $stmt->bindValue(':fsize', $file['size'], PDO::PARAM_INT);
    $stmt->bindValue(':notes', $notes, PDO::PARAM_STR);
    $stmt->execute();

    echo json_encode(['success' => true, 'message' => 'Insumo cargado correctamente']);
    exit;
}

if ($action === 'delete_client_artifact') {
    $artifactId = (int)($_POST['artifact_id'] ?? 0);
    $stmt = $db->prepare("SELECT file_path FROM redteam_client_artifacts WHERE id = :id");
    $stmt->bindValue(':id', $artifactId, PDO::PARAM_INT);
    $stmt->execute();
    $art = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($art) {
        $fullPath = __DIR__ . '/../../' . $art['file_path'];
        if (file_exists($fullPath)) {
            @unlink($fullPath);
        }
        $del = $db->prepare("DELETE FROM redteam_client_artifacts WHERE id = :id");
        $del->bindValue(':id', $artifactId, PDO::PARAM_INT);
        $del->execute();
        echo json_encode(['success' => true, 'message' => 'Insumo eliminado']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Insumo no encontrado']);
    }
    exit;
}


if ($action === 'get_campaign_details') {
        $campId = (int)($_GET['campaign_id'] ?? 0);
        $campaign = $rtService->getCampaign($campId);
        if (!$campaign) {
            echo json_encode(['success' => false, 'error' => 'Campaña no encontrada']);
            exit;
        }

        $targets = $rtService->getCampaignTargets($campId);
        $metrics = $rtService->getCampaignMetrics($campId);

        foreach ($targets as &$t) {
            $baseLure = $rtService->generateTargetLure($t, $campaign);
            
            // Generar versión HTML enriquecida por defecto
            if ($baseLure['vector'] === 'SPEAR_PHISHING_SII') {
                $sii = SiiLureTemplates::getTemplate('F1955_RENTA', [
                    'name' => $t['full_name'],
                    'company' => $campaign['company_name'],
                    'rut' => '78089686-8',
                    'link' => $baseLure['link']
                ]);
                $baseLure['html'] = $sii['html'];
            } else {
                $baseLure['html'] = '<div style="font-family:Arial,Helvetica,sans-serif; background:#fff; border:1px solid #cbd5e1; border-radius:6px; padding:25px; line-height:1.6; color:#1e293b; max-width:600px; margin:auto;">'
                    . '<div style="font-size:15px; font-weight:bold; color:#0f172a; margin-bottom:12px; border-bottom:1px solid #e2e8f0; padding-bottom:8px;">' . htmlspecialchars($baseLure['subject']) . '</div>'
                    . nl2br(htmlspecialchars($baseLure['body']))
                    . '</div>';
            }
            $t['lure_preview'] = $baseLure;
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'campaign' => $campaign,
                'targets' => $targets,
                'metrics' => $metrics
            ]
        ]);
        exit;
    }

    if ($action === 'get_template_preview') {
        $targetId = (int)($_POST['target_id'] ?? 0);
        $campId = (int)($_POST['campaign_id'] ?? 0);
        $templateKey = trim($_POST['template_key'] ?? 'AUTO');

        $stmtT = $db->prepare("SELECT * FROM redteam_targets WHERE id = ?");
        $stmtT->execute([$targetId]);
        $target = $stmtT->fetch(PDO::FETCH_ASSOC);

        $stmtC = $db->prepare("SELECT * FROM redteam_campaigns WHERE id = ?");
        $stmtC->execute([$campId]);
        $campaign = $stmtC->fetch(PDO::FETCH_ASSOC);

        if (!$target || !$campaign) {
            echo json_encode(['success' => false, 'error' => 'No encontrado']);
            exit;
        }

        $link = "https://www.keystoneadvisors.cl/shield/rt.php?t={$target['unique_token']}";

        if ($templateKey === 'SII_CARPETA') {
            $tpl = SiiLureTemplates::getTemplate('CARPETA_TRIBUTARIA', [
                'name' => $target['full_name'],
                'company' => $campaign['company_name'],
                'link' => $link
            ]);
            echo json_encode([
                'success' => true,
                'data' => [
                    'vector' => 'SII_CARPETA_TRIBUTARIA',
                    'sender_display' => $tpl['from'],
                    'subject' => $tpl['subject'],
                    'body' => "Se ha compartido acceso a la Carpeta Tributaria Regular de {$campaign['company_name']}...",
                    'html' => $tpl['html'],
                    'link' => $link
                ]
            ]);
            exit;
        } elseif ($templateKey === 'SII_RESOLUCION') {
            $tpl = SiiLureTemplates::getTemplate('RESOLUCION_EXENTA', [
                'name' => $target['full_name'],
                'company' => $campaign['company_name'],
                'link' => $link
            ]);
            echo json_encode([
                'success' => true,
                'data' => [
                    'vector' => 'SII_RESOLUCION_EXENTA',
                    'sender_display' => $tpl['from'],
                    'subject' => $tpl['subject'],
                    'body' => "Notificación de Resolución Exenta dictada en causa RAV...",
                    'html' => $tpl['html'],
                    'link' => $link
                ]
            ]);
            exit;
        } elseif ($templateKey === 'SII_F1955') {
            $tpl = SiiLureTemplates::getTemplate('F1955_RENTA', [
                'name' => $target['full_name'],
                'company' => $campaign['company_name'],
                'rut' => '78089686-8',
                'link' => $link
            ]);
            echo json_encode([
                'success' => true,
                'data' => [
                    'vector' => 'SII_F1955_RENTA',
                    'sender_display' => $tpl['from'],
                    'subject' => $tpl['subject'],
                    'body' => "Revise si corresponde tributar lo informado en F1955 por abonos superiores a 1.500 UF...",
                    'html' => $tpl['html'],
                    'link' => $link
                ]
            ]);
            exit;
        } else {
            // Auto / Original
            $baseLure = $rtService->generateTargetLure($target, $campaign);
            $baseLure['html'] = '<div style="font-family:Arial,Helvetica,sans-serif; background:#fff; border:1px solid #cbd5e1; border-radius:6px; padding:25px; line-height:1.6; color:#1e293b; max-width:600px; margin:auto;">'
                . '<div style="font-size:15px; font-weight:bold; color:#0f172a; margin-bottom:12px; border-bottom:1px solid #e2e8f0; padding-bottom:8px;">' . htmlspecialchars($baseLure['subject']) . '</div>'
                . nl2br(htmlspecialchars($baseLure['body']))
                . '</div>';
            echo json_encode(['success' => true, 'data' => $baseLure]);
            exit;
        }
    }

    if ($action === 'send_target_lure_smtp') {
        $targetId = (int)($_POST['target_id'] ?? 0);
        $campId = (int)($_POST['campaign_id'] ?? 0);

        $stmtT = $db->prepare("SELECT * FROM redteam_targets WHERE id = ?");
        $stmtT->execute([$targetId]);
        $target = $stmtT->fetch(PDO::FETCH_ASSOC);

        $stmtC = $db->prepare("SELECT * FROM redteam_campaigns WHERE id = ?");
        $stmtC->execute([$campId]);
        $campaign = $stmtC->fetch(PDO::FETCH_ASSOC);

        if (!$target || !$campaign) {
            echo json_encode(['success' => false, 'error' => 'Destinatario no encontrado']);
            exit;
        }

        $customSubject = trim($_POST['custom_subject'] ?? '');
        $customBody = trim($_POST['custom_body'] ?? '');
        $customHtml = trim($_POST['custom_html'] ?? '');

        $lure = $rtService->generateTargetLure($target, $campaign);
        $subject = !empty($customSubject) ? $customSubject : $lure['subject'];
        $bodyHtml = !empty($customHtml) ? $customHtml : (nl2br(htmlspecialchars($customBody ?: $lure['body'])));

        // Configuración SMTP
        $stmtSmtp = $db->query("SELECT * FROM app_settings WHERE setting_key = 'smtp_config' LIMIT 1");
        $smtpRow = $stmtSmtp ? $stmtSmtp->fetch(PDO::FETCH_ASSOC) : null;
        $smtpConfig = $smtpRow ? json_decode($smtpRow['setting_value'], true) : [];

        $smtpClient = new KeystoneSmtpClient($smtpConfig);
        $sent = $smtpClient->send(
            $target['email'],
            $subject,
            $bodyHtml,
            [],
            [],
            true
        );

        if ($sent) {
            $db->prepare("UPDATE redteam_targets SET status = 'SENT', sent_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$targetId]);
            echo json_encode(['success' => true, 'message' => "Correo de simulación enviado exitosamente a {$target['email']}"]);
        } else {
            echo json_encode(['success' => false, 'error' => "Fallo de conexión SMTP: " . $smtpClient->getLog()]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Acción no válida']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
