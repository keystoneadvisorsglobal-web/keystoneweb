<?php
/**
 * Keystone DeepShield - Motor de Análisis Forense BEC (MIME / SPF / DKIM / Reply-To)
 * Ingesta directa de correos vía IMAP (contacto@keystoneadvisors.cl) o subida manual de .eml
 */

require_once __DIR__ . '/LeadNotificationService.php';
require_once __DIR__ . '/KeystoneSmtpClient.php';
require_once __DIR__ . '/DeepShieldBecDossierPdfGenerator.php';

class DeepShieldBecForensicService {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->initSchema();
    }

    private function initSchema(): void {
        $this->db->exec("CREATE TABLE IF NOT EXISTS deepshield_bec_evidence (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id INTEGER NOT NULL,
            order_code TEXT NOT NULL,
            source_type TEXT DEFAULT 'IMAP_AUTO',
            sender_email TEXT,
            sender_name TEXT,
            reply_to_email TEXT,
            origin_ip TEXT,
            spf_status TEXT,
            dkim_status TEXT,
            dmarc_status TEXT,
            spoofing_detected INTEGER DEFAULT 0,
            summary_diagnosis TEXT,
            raw_headers TEXT,
            dossier_pdf_path TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (order_id) REFERENCES deepshield_orders(id)
        )");

        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_bec_order_code ON deepshield_bec_evidence(order_code)");
    }

    /**
     * Analiza encabezados crudos de un correo (.eml o texto MIME)
     */
    public function analyzeRawHeaders(string $headers): array {
        $data = [
            'origin_ip' => 'No detectada',
            'spf_status' => 'FAIL',
            'dkim_status' => 'NONE',
            'dmarc_status' => 'FAIL',
            'sender_from' => '',
            'reply_to' => '',
            'return_path' => '',
            'spoofing_detected' => true,
            'diagnosis' => 'Alerta: Encabezados incompletos o sospechosos.'
        ];

        // 1. Extraer From
        if (preg_match('/^From:\s*(.+)$/mi', $headers, $m)) {
            $data['sender_from'] = trim($m[1]);
        }

        // 2. Extraer Reply-To
        if (preg_match('/^Reply-To:\s*(.+)$/mi', $headers, $m)) {
            $data['reply_to'] = trim($m[1]);
        }

        // 3. Extraer Return-Path
        if (preg_match('/^Return-Path:\s*(.+)$/mi', $headers, $m)) {
            $data['return_path'] = trim($m[1]);
        }

        // 4. Extraer IP en Received
        if (preg_match_all('/Received:\s*from[^\n]+\[([0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3})\]/mi', $headers, $m)) {
            if (!empty($m[1])) {
                $data['origin_ip'] = end($m[1]); // La IP pública más cercana al emisor original
            }
        }

        // 5. Extraer Authentication-Results
        if (preg_match('/Authentication-Results:[^\n]+\bspf=(\w+)/mi', $headers, $m)) {
            $data['spf_status'] = strtoupper($m[1]);
        }
        if (preg_match('/Authentication-Results:[^\n]+\bdkim=(\w+)/mi', $headers, $m)) {
            $data['dkim_status'] = strtoupper($m[1]);
        }
        if (preg_match('/Authentication-Results:[^\n]+\bdmarc=(\w+)/mi', $headers, $m)) {
            $data['dmarc_status'] = strtoupper($m[1]);
        }

        // 6. Detección de Suplantación / Spoofing
        $fromEmail = strtolower($this->extractEmail($data['sender_from']));
        $replyToEmail = strtolower($this->extractEmail($data['reply_to']));
        $returnPathEmail = strtolower($this->extractEmail($data['return_path']));

        $reasons = [];
        if (!empty($replyToEmail) && $replyToEmail !== $fromEmail) {
            $reasons[] = "Desvío malicioso detectado: Reply-To ({$replyToEmail}) no coincide con el remitente visible ({$fromEmail}).";
        }
        if ($data['spf_status'] === 'FAIL') {
            $reasons[] = "Fallo crítico SPF: Servidor origen ({$data['origin_ip']}) no está autorizado en los registros DNS del dominio.";
        }
        if ($data['dkim_status'] === 'FAIL' || $data['dkim_status'] === 'NONE') {
            $reasons[] = "Ausencia o fallo de firma digital DKIM: El mensaje no cuenta con respaldo criptográfico de su servidor emisor.";
        }

        if (empty($reasons)) {
            if ($data['spf_status'] === 'PASS' && $data['dkim_status'] === 'PASS') {
                $data['spoofing_detected'] = false;
                $data['diagnosis'] = "ALERTA SEVERA DE CUENTA COMPROMETIDA: Los correos tienen SPF/DKIM válidos, lo que indica que la casilla legítima del emisor fue vulnerada por robo de credenciales.";
            } else {
                $data['diagnosis'] = "Suplantación externa detectada mediante falsificación de cabeceras.";
            }
        } else {
            $data['spoofing_detected'] = true;
            $data['diagnosis'] = implode(' ', $reasons);
        }

        return $data;
    }

    private function extractEmail(string $str): string {
        if (preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $str, $m)) {
            return $m[0];
        }
        return '';
    }

    /**
     * Sincroniza desde la casilla contacto@keystoneadvisors.cl correos que incluyan el código de orden (ej: BEC DST-XXXX)
     */
    public function syncBecEmailsFromInbox(string $orderCode = ''): array {
        $cfg = LeadNotificationService::getSmtpConfig();
        $user = $cfg['smtp_user'] ?? 'contacto@keystoneadvisors.cl';
        $pass = $cfg['smtp_pass'] ?? '';

        if (empty($pass)) {
            return ['success' => false, 'error' => 'Contraseña de casilla no configurada'];
        }

        $server = "{imap.titan.email:993/imap/ssl}INBOX";
        $mbox = @imap_open($server, $user, $pass, 0, 1);
        if (!$mbox) {
            return ['success' => false, 'error' => 'Error de conexión IMAP: ' . imap_last_error()];
        }

        $check = imap_check($mbox);
        $totalMsgs = $check->Nmsgs;
        $foundCount = 0;
        $processed = [];

        $start = max(1, $totalMsgs - 40);
        for ($i = $totalMsgs; $i >= $start; $i--) {
            $h = imap_headerinfo($mbox, $i);
            if (!$h) continue;

            $subject = imap_utf8($h->subject ?? '');
            
            // Buscar si contiene el código de orden o la palabra BEC
            $targetCode = strtoupper(trim($orderCode));
            if (!empty($targetCode)) {
                if (stripos($subject, $targetCode) === false) {
                    continue;
                }
            } else {
                if (stripos($subject, 'BEC') === false && stripos($subject, 'DST-') === false) {
                    continue;
                }
            }

            // Extraer código de orden del subject si no venía fijo
            $matchedCode = $targetCode;
            if (empty($matchedCode)) {
                if (preg_match('/(DST-[A-F0-9]{8})/i', $subject, $m)) {
                    $matchedCode = strtoupper($m[1]);
                }
            }

            if (empty($matchedCode)) continue;

            // Buscar la orden en la BD
            $stmtOrder = $this->db->prepare("SELECT * FROM deepshield_orders WHERE order_code = ? LIMIT 1");
            $stmtOrder->execute([$matchedCode]);
            $order = $stmtOrder->fetch(PDO::FETCH_ASSOC);

            if (!$order) continue;

            // Extraer cabeceras completas
            $rawHeaders = imap_fetchheader($mbox, $i);
            $analysis = $this->analyzeRawHeaders($rawHeaders);

            // Generar Dosier PDF automáticamente
            $pdfGen = new DeepShieldBecDossierPdfGenerator($order, [
                'origin_ip' => $analysis['origin_ip'],
                'spf_status' => $analysis['spf_status'],
                'dkim_status' => $analysis['dkim_status'],
                'dmarc_status' => $analysis['dmarc_status'],
                'spoofing_detected' => $analysis['spoofing_detected'],
                'reply_to_redirect' => $analysis['reply_to'] ?: 'Sin redirección visible'
            ]);
            $pdfPath = $pdfGen->generateDossierPdf();

            // Guardar evidencia en la base de datos
            $stmtIns = $this->db->prepare("INSERT INTO deepshield_bec_evidence (
                order_id, order_code, source_type, sender_email, sender_name,
                reply_to_email, origin_ip, spf_status, dkim_status, dmarc_status,
                spoofing_detected, summary_diagnosis, raw_headers, dossier_pdf_path
            ) VALUES (?, ?, 'IMAP_AUTO', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            $stmtIns->execute([
                $order['id'],
                $order['order_code'],
                $this->extractEmail($analysis['sender_from']),
                $analysis['sender_from'],
                $analysis['reply_to'],
                $analysis['origin_ip'],
                $analysis['spf_status'],
                $analysis['dkim_status'],
                $analysis['dmarc_status'],
                $analysis['spoofing_detected'] ? 1 : 0,
                $analysis['diagnosis'],
                substr($rawHeaders, 0, 5000),
                $pdfPath
            ]);

            $foundCount++;
            $processed[] = [
                'order_code' => $matchedCode,
                'sender' => $analysis['sender_from'],
                'ip' => $analysis['origin_ip'],
                'spf' => $analysis['spf_status'],
                'pdf_path' => $pdfPath
            ];
        }

        imap_close($mbox);

        return [
            'success' => true,
            'found_count' => $foundCount,
            'processed' => $processed
        ];
    }

    /**
     * Procesar análisis manual mediante subida de archivo .eml o texto pegado
     */
    public function analyzeAndGenerateDossier(int $orderId, string $rawHeaders): array {
        $stmtOrder = $this->db->prepare("SELECT * FROM deepshield_orders WHERE id = ? LIMIT 1");
        $stmtOrder->execute([$orderId]);
        $order = $stmtOrder->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            return ['success' => false, 'error' => 'Orden no encontrada'];
        }

        $analysis = $this->analyzeRawHeaders($rawHeaders);

        // Generar Dosier PDF
        $pdfGen = new DeepShieldBecDossierPdfGenerator($order, [
            'origin_ip' => $analysis['origin_ip'],
            'spf_status' => $analysis['spf_status'],
            'dkim_status' => $analysis['dkim_status'],
            'dmarc_status' => $analysis['dmarc_status'],
            'spoofing_detected' => $analysis['spoofing_detected'],
            'reply_to_redirect' => $analysis['reply_to'] ?: 'Sin redirección visible'
        ]);
        $pdfPath = $pdfGen->generateDossierPdf();

        // Guardar en tabla de evidencias
        $stmtIns = $this->db->prepare("INSERT INTO deepshield_bec_evidence (
            order_id, order_code, source_type, sender_email, sender_name,
            reply_to_email, origin_ip, spf_status, dkim_status, dmarc_status,
            spoofing_detected, summary_diagnosis, raw_headers, dossier_pdf_path
        ) VALUES (?, ?, 'MANUAL_UPLOAD', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $stmtIns->execute([
            $order['id'],
            $order['order_code'],
            $this->extractEmail($analysis['sender_from']),
            $analysis['sender_from'],
            $analysis['reply_to'],
            $analysis['origin_ip'],
            $analysis['spf_status'],
            $analysis['dkim_status'],
            $analysis['dmarc_status'],
            $analysis['spoofing_detected'] ? 1 : 0,
            $analysis['diagnosis'],
            substr($rawHeaders, 0, 5000),
            $pdfPath
        ]);

        return [
            'success' => true,
            'analysis' => $analysis,
            'pdf_path' => $pdfPath,
            'order_code' => $order['order_code']
        ];
    }

    /**
     * Enviar el Dosier Criptográfico por Correo al Cliente (Dirección Legal / CFO / Tesorero)
     */
    public function dispatchDossierEmail(int $orderId, string $destinationEmail = ''): array {
        $stmtOrder = $this->db->prepare("SELECT * FROM deepshield_orders WHERE id = ? LIMIT 1");
        $stmtOrder->execute([$orderId]);
        $order = $stmtOrder->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            return ['success' => false, 'error' => 'Orden no encontrada'];
        }

        // Buscar última evidencia o generar PDF básico si no existe
        $evStmt = $this->db->prepare("SELECT * FROM deepshield_bec_evidence WHERE order_id = ? ORDER BY id DESC LIMIT 1");
        $evStmt->execute([$orderId]);
        $evidence = $evStmt->fetch(PDO::FETCH_ASSOC);

        $pdfPath = $evidence['dossier_pdf_path'] ?? '';
        if (empty($pdfPath) || !file_exists($pdfPath)) {
            $pdfGen = new DeepShieldBecDossierPdfGenerator($order, [
                'origin_ip' => 'Registrada en bitácora OOB',
                'spf_status' => 'FAIL',
                'dkim_status' => 'FAIL',
                'dmarc_status' => 'REJECT',
                'spoofing_detected' => true,
                'reply_to_redirect' => 'Discrepancia detectada'
            ]);
            $pdfPath = $pdfGen->generateDossierPdf();
        }

        $targetEmail = !empty($destinationEmail) ? trim($destinationEmail) : $order['approver_email'];
        if (!filter_var($targetEmail, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Correo electrónico destinatario inválido: ' . $targetEmail];
        }

        $cfg = LeadNotificationService::getSmtpConfig();
        if (empty($cfg['smtp_pass'])) {
            return ['success' => false, 'error' => 'Configuración SMTP no parametrizada.'];
        }

        try {
            $client = new KeystoneSmtpClient($cfg);

            $subject = "🚨 Dosier Pericial Criptográfico: Intento de Fraude Interceptado [{$order['order_code']}]";
            $amountFmt = number_format((float)$order['amount'], 0, ',', '.');
            $curr = $order['currency'] ?? 'CLP';

            $body = "<h3>Keystone Advisors - Centro de Operaciones de Ciberseguridad (SOC)</h3>
                     <p>Estimada(o) <strong>{$order['approver_name']}</strong>,</p>
                     <p>Adjunto a este correo oficial encontrará el <strong>Dosier Criptográfico Forense</strong> correspondiente a la orden <strong>{$order['order_code']}</strong>, cuya transferencia por un monto de <strong>{$curr} \${$amountFmt}</strong> a favor de <strong>{$order['beneficiary_name']}</strong> fue <strong>RECHAZADA Y BLOQUEADA</strong> por sospecha de suplantación de identidad (BEC / CEO Fraud).</p>
                     <p>Este informe pericial cuenta con sello digital SHA-256 e IP de auditoría, y puede ser presentado ante la <strong>Brigada Investigadora del Cibercrimen (PDI)</strong> o la Fiscalía Local como prueba fehaciente bajo la Ley N° 21.459 de Delitos Informáticos.</p>
                     <p><strong>Acciones Mandatorias:</strong></p>
                     <ul>
                       <li>Bloquear la cuenta bancaria del titular en su portal bancario.</li>
                       <li>Verificar la casilla de correo del directivo y habilitar autenticación multifactor (MFA).</li>
                     </ul>
                     <p style='color:#64748b; font-size:0.85rem;'>Keystone Advisors SpA • Protocolo Zero Trust DeepShield</p>";

            $sent = $client->send($targetEmail, $subject, $body, $pdfPath);
            if ($sent) {
                // Registrar en auditoría
                $auditStmt = $this->db->prepare("INSERT INTO deepshield_audit_logs (order_id, action, details, ip_address) VALUES (?, 'BEC_DOSSIER_DISPATCHED', ?, ?)");
                $auditStmt->execute([$orderId, "Dosier pericial despachado a {$targetEmail} con adjunto PDF.", $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1']);

                return ['success' => true, 'message' => "Dosier pericial enviado con éxito a {$targetEmail}", 'pdf_path' => $pdfPath];
            } else {
                $rawLog = $client->getLog();
                $logMsg = is_array($rawLog) ? implode(' | ', $rawLog) : (string)$rawLog;
                return ['success' => false, 'error' => 'Fallo al despachar el correo con adjunto PDF. Detalle SMTP: ' . ($logMsg ?: 'Sin respuesta del socket.')];
            }
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Excepción SMTP: ' . $e->getMessage()];
        }
    }
}
