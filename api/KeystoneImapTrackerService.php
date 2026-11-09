<?php
/**
 * Servicio de Lectura y Seguimiento de Casilla IMAP - Keystone Advisors
 * Conecta a Titan Mail (imap.titan.email:993/SSL) y rastrea respuestas de clientes
 * vinculándolas automáticamente con la bandeja de Leads (requests) o Escaneos (cyber_scans).
 */

require_once __DIR__ . '/LeadNotificationService.php';

class KeystoneImapTrackerService {

    /**
     * Sincroniza correos entrantes con espejo estricto de la casilla
     * Si un correo fue eliminado de la casilla Titan Mail, se remueve de la base de datos local
     */
    public static function syncInboxReplies($limit = 50) {
        $cfg = LeadNotificationService::getSmtpConfig();
        $user = $cfg['smtp_user'] ?? 'contacto@keystoneadvisors.cl';
        $pass = $cfg['smtp_pass'] ?? '';

        if (empty($pass)) {
            return [
                'success' => false,
                'error' => 'Contraseña de casilla no configurada en app_settings.'
            ];
        }

        $server = "{imap.titan.email:993/imap/ssl}INBOX";
        $mbox = @imap_open($server, $user, $pass, 0, 1);

        if (!$mbox) {
            return [
                'success' => false,
                'error' => 'Error de conexión IMAP: ' . imap_last_error()
            ];
        }

        $dbFile = __DIR__ . '/../data/leads_keystone.sqlite';
        $db = new PDO('sqlite:' . $dbFile);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Asegurar tabla email_replies
        $db->exec("CREATE TABLE IF NOT EXISTS email_replies (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            message_id TEXT UNIQUE,
            lead_id INTEGER,
            scan_id INTEGER,
            sender_name TEXT,
            sender_email TEXT NOT NULL,
            subject TEXT,
            body_text TEXT,
            body_html TEXT,
            received_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $check = imap_check($mbox);
        $totalMsgs = $check->Nmsgs;

        // Si la casilla en Titan Mail está vacía (usuario borró todo), vaciar espejo local
        if ($totalMsgs === 0) {
            $deletedCount = $db->exec("DELETE FROM email_replies");
            imap_close($mbox);
            return [
                'success' => true,
                'new_replies' => 0,
                'deleted_orphans' => $deletedCount,
                'total_checked' => 0,
                'message' => 'Casilla vacía. La bandeja de respuestas se limpió por completo.'
            ];
        }

        // Obtener todos los Message-IDs actualmente presentes en la casilla del servidor
        $serverMessageIds = [];
        $start = max(1, $totalMsgs - $limit + 1);
        $newRepliesCount = 0;

        for ($i = $totalMsgs; $i >= $start; $i--) {
            $header = imap_headerinfo($mbox, $i);
            if (!$header) continue;

            $messageId = trim($header->message_id ?? md5($header->date . ($header->fromaddress ?? '') . $i));
            $fromObj = $header->from[0] ?? null;
            $senderEmail = strtolower(trim(($fromObj->mailbox ?? '') . '@' . ($fromObj->host ?? '')));
            $senderName = self::decodeMimeStr($fromObj->personal ?? $senderEmail);
            $subject = self::decodeMimeStr($header->subject ?? '(Sin Asunto)');
            $date = date('Y-m-d H:i:s', strtotime($header->date ?? 'now'));

            // Ignorar correos salientes propios de la casilla o notificaciones automáticas internas
            if ($senderEmail === strtolower($user) || 
                $senderEmail === 'no-reply@keystoneadvisors.cl' || 
                strpos($senderEmail, 'mailer-daemon') !== false) {
                continue;
            }

            $serverMessageIds[] = $messageId;

            // Verificar si ya fue procesado en la base de datos
            $stmtCheck = $db->prepare("SELECT COUNT(*) FROM email_replies WHERE message_id = ?");
            $stmtCheck->execute([$messageId]);
            if ($stmtCheck->fetchColumn() > 0) {
                continue; // Ya guardado previamente
            }

            // Obtener cuerpo del correo
            $body = self::getMessageBody($mbox, $i);

            // Intentar vincular con un Lead existente por email
            $stmtLead = $db->prepare("SELECT id FROM requests WHERE lower(email) = ? ORDER BY id DESC LIMIT 1");
            $stmtLead->execute([$senderEmail]);
            $leadId = $stmtLead->fetchColumn() ?: null;

            // Intentar vincular con un Escáner existente por email
            $stmtScan = $db->prepare("SELECT id FROM cyber_scans WHERE lower(email) = ? ORDER BY id DESC LIMIT 1");
            $stmtScan->execute([$senderEmail]);
            $scanId = $stmtScan->fetchColumn() ?: null;

            // Guardar respuesta
            $stmtInsert = $db->prepare("INSERT INTO email_replies (message_id, lead_id, scan_id, sender_name, sender_email, subject, body_text, received_at)
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmtInsert->execute([
                $messageId,
                $leadId,
                $scanId,
                $senderName,
                $senderEmail,
                $subject,
                $body,
                $date
            ]);

            // Si el correo pertenece a un lead activo, actualizar estado a 'EN_EVALUACION'
            if ($leadId) {
                $upLead = $db->prepare("INSERT INTO lead_status (lead_id, status, notes, updated_at) VALUES (?, 'EN_EVALUACION', 'Cliente respondió por correo: ' || ?, CURRENT_TIMESTAMP)
                                        ON CONFLICT(lead_id) DO UPDATE SET status = 'EN_EVALUACION', notes = 'Cliente respondió por correo el ' || CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP");
                $upLead->execute([$leadId, mb_substr($subject, 0, 50)]);
            }

            // Si el correo pertenece a un escáner, actualizar estado a 'CLIENTE_RESPONDIO'
            if ($scanId) {
                $upScan = $db->prepare("UPDATE cyber_scans SET status = 'CLIENTE_RESPONDIO' WHERE id = ?");
                $upScan->execute([$scanId]);
            }
            // Además marcar todos los escaneos registrados bajo ese mismo correo electrónico
            if ($senderEmail) {
                $upScanAll = $db->prepare("UPDATE cyber_scans SET status = 'CLIENTE_RESPONDIO' WHERE lower(email) = ?");
                $upScanAll->execute([$senderEmail]);
            }

            $newRepliesCount++;
        }

        // PURGA DE MENSAJES ELIMINADOS (Espejo estricto):
        // Eliminar de la base de datos cualquier mensaje que ya no exista en el servidor Titan
        if (!empty($serverMessageIds)) {
            $inClause = implode(',', array_fill(0, count($serverMessageIds), '?'));
            $purgeStmt = $db->prepare("DELETE FROM email_replies WHERE message_id NOT IN ($inClause)");
            $purgeStmt->execute($serverMessageIds);
        } else {
            // Si no hay mensajes válidos entrantes en el servidor, limpiar toda la tabla
            $db->exec("DELETE FROM email_replies");
        }

        imap_close($mbox);

        return [
            'success' => true,
            'new_replies' => $newRepliesCount,
            'total_checked' => ($totalMsgs - $start + 1),
            'message' => 'Casilla sincronizada correctamente con el servidor Titan Mail.'
        ];
    }

    /**
     * Limpia completamente el historial de respuestas en la base de datos
     */
    public static function clearRepliesHistory() {
        $dbFile = __DIR__ . '/../data/leads_keystone.sqlite';
        $db = new PDO('sqlite:' . $dbFile);
        $count = $db->exec("DELETE FROM email_replies");
        return $count;
    }

    private static function decodeMimeStr($str) {
        $elements = imap_mime_header_decode($str);
        $out = '';
        foreach ($elements as $el) {
            $charset = $el->charset === 'default' ? 'auto' : $el->charset;
            $out .= mb_convert_encoding($el->text, 'UTF-8', $charset);
        }
        return $out;
    }

    private static function getMessageBody($mbox, $msgNumber) {
        $structure = imap_fetchstructure($mbox, $msgNumber);
        if (!$structure) return '';

        $body = '';
        if (empty($structure->parts)) {
            // Mensaje simple
            $body = imap_body($mbox, $msgNumber);
            if ($structure->encoding == 3) {
                $body = base64_decode($body);
            } elseif ($structure->encoding == 4) {
                $body = quoted_printable_decode($body);
            }
        } else {
            // Multipart
            foreach ($structure->parts as $partIndex => $part) {
                if ($part->subtype === 'PLAIN') {
                    $bodyPart = imap_fetchbody($mbox, $msgNumber, $partIndex + 1);
                    if ($part->encoding == 3) {
                        $bodyPart = base64_decode($bodyPart);
                    } elseif ($part->encoding == 4) {
                        $bodyPart = quoted_printable_decode($bodyPart);
                    }
                    $body .= $bodyPart;
                }
            }
            if (empty($body)) {
                $body = strip_tags(imap_body($mbox, $msgNumber));
            }
        }

        return mb_convert_encoding(trim($body), 'UTF-8', 'auto');
    }
}
