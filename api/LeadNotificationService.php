<?php
/**
 * Notificador y Despachador de Alertas Comerciales - KeyGuard / Keystone Advisors
 * Soporta despacho híbrido:
 * 1. Intento primario vía SMTP Autenticado (Titan Mail) configurado desde /admin/
 * 2. Fallback secundario vía función nativa mail() con Envelope (-f)
 */

require_once __DIR__ . '/KeystoneSmtpClient.php';

class LeadNotificationService {
    private static $defaultWebhook = ''; 

    /**
     * Obtiene la configuración SMTP desde la base de datos sqlite
     */
    public static function getSmtpConfig() {
        $dbFile = __DIR__ . '/../data/leads_keystone.sqlite';
        if (!file_exists($dbFile)) {
            $dbFile = __DIR__ . '/../data/leads_sitionuevo.sqlite';
        }

        $config = [
            'smtp_host' => 'smtp.titan.email',
            'smtp_port' => 465,
            'smtp_user' => 'contacto@keystoneadvisors.cl',
            'smtp_pass' => '',
            'smtp_from' => 'contacto@keystoneadvisors.cl',
            'smtp_name' => 'Keystone Advisors'
        ];

        try {
            if (file_exists($dbFile)) {
                $db = new PDO('sqlite:' . $dbFile);
                $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
                $stmt = $db->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key LIKE 'smtp_%'");
                if ($stmt) {
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $config[$row['setting_key']] = $row['setting_value'];
                    }
                }
            }
        } catch (Exception $e) {
            // Silencioso
        }

        return $config;
    }

    /**
     * Envía el reporte PDF por correo electrónico al prospecto
     */
    public static function sendReportEmail($toEmail, $domain, $score, $riskLevel, $pdfFilePath, $folio) {
        $toEmail = trim($toEmail);
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $subjectText = "Informe Ejecutivo de Ciberseguridad: {$domain} [Folio {$folio}]";
        $scoreColor = ($score < 60 ? "#ef4444" : ($score < 75 ? "#f59e0b" : "#10b981"));

        $htmlBody = "
        <!DOCTYPE html>
        <html>
        <head><meta charset='UTF-8'></head>
        <body style='font-family: Arial, sans-serif; background-color: #f8fafc; margin: 0; padding: 20px; color: #334155;'>
            <table align='center' border='0' cellpadding='0' cellspacing='0' width='100%' style='max-width: 600px; background: #ffffff; border-radius: 8px; overflow: hidden; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px rgba(0,0,0,0.04);'>
                <tr>
                    <td style='background-color: #0F2A4A; padding: 25px 20px; text-align: center;'>
                        <h1 style='color: #ffffff; margin: 0; font-size: 20px; font-weight: 700; letter-spacing: 0.5px;'>KEYSTONE ADVISORS</h1>
                        <p style='color: #00b4d8; font-size: 12px; margin: 5px 0 0 0; text-transform: uppercase; font-weight: bold;'>KeyGuard Cyber Defense Suite</p>
                    </td>
                </tr>
                <tr>
                    <td style='padding: 30px 25px;'>
                        <h2 style='color: #0F2A4A; font-size: 17px; margin-top: 0;'>Informe Ejecutivo de Ciberseguridad</h2>
                        <p style='font-size: 14px; line-height: 1.6; color: #475569;'>
                            Estimado/a,<br><br>
                            Adjunto a este correo encontrará el <strong>Informe Ejecutivo de Ciberseguridad y Riesgo (Folio {$folio})</strong> emitido tras la inspección pasiva realizada a los registros públicos de <strong>{$domain}</strong>.
                        </p>
                        
                        <table width='100%' border='0' cellpadding='0' cellspacing='0' style='background: #f1f5f9; border-left: 4px solid {$scoreColor}; border-radius: 4px; margin: 20px 0;'>
                            <tr>
                                <td style='padding: 15px;'>
                                    <div style='font-size: 13px; color: #64748b;'>PUNTUACIÓN DE EXPOSICIÓN:</div>
                                    <div style='font-size: 22px; font-weight: bold; color: {$scoreColor};'>{$score} / 100</div>
                                    <div style='font-size: 12px; color: #334155; margin-top: 3px;'>Nivel de Riesgo Operacional: <strong>" . strtoupper($riskLevel) . "</strong></div>
                                </td>
                            </tr>
                        </table>

                        <p style='font-size: 14px; line-height: 1.6; color: #475569;'>
                            El documento adjunto incluye:
                        </p>
                        <ul style='font-size: 13px; color: #475569; line-height: 1.6; padding-left: 20px;'>
                            <li>Evaluación de vectores críticos como la suplantación de correo (estafas BEC) y clonación de voz con IA (Vishing).</li>
                            <li>Radiografía técnica de registros DNS, DMARC, SPF, SSL y cabeceras de endurecimiento HSTS.</li>
                            <li>Plan de remediación acelerada de 3 fases recomendado por Keystone Advisors.</li>
                        </ul>

                        <div style='margin-top: 30px; padding-top: 20px; border-top: 1px solid #e2e8f0;'>
                            <p style='font-size: 13px; color: #64748b; margin-bottom: 5px;'>¿Desea revisar estos hallazgos junto a un consultor senior?</p>
                            <a href='https://www.keystoneadvisors.cl/#contacto' style='display: inline-block; background: #00b4d8; color: #ffffff; text-decoration: none; padding: 10px 18px; border-radius: 6px; font-size: 13px; font-weight: bold;'>Agendar Sesión de Remediación (20 min)</a>
                        </div>
                    </td>
                </tr>
                <tr>
                    <td style='background: #f8fafc; padding: 15px 25px; text-align: center; border-top: 1px solid #e2e8f0;'>
                        <p style='font-size: 11px; color: #94a3b8; margin: 0;'>
                            © " . date('Y') . " Keystone Advisors SpA. Todos los derechos reservados.<br>
                            Documento emitido con fines de diagnóstico preventivo confidencial.
                        </p>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        ";

        $attName = "Reporte_Ciberseguridad_{$domain}_{$folio}.pdf";

        // 1. INTENTO PRINCIPAL: SMTP AUTENTICADO (TITAN MAIL)
        $smtpConfig = self::getSmtpConfig();
        if (!empty($smtpConfig['smtp_pass'])) {
            $smtpClient = new KeystoneSmtpClient($smtpConfig);
            $sent = $smtpClient->send($toEmail, $subjectText, $htmlBody, $pdfFilePath, $attName);
            if ($sent) {
                return true;
            }
        }

        // 2. FALLBACK: FUNCIÓN MAIL NATIVA
        $fromEmail = $smtpConfig['smtp_from'] ?? "contacto@keystoneadvisors.cl";
        $fromName = $smtpConfig['smtp_name'] ?? "Keystone Advisors";
        $encodedSubject = "=?UTF-8?B?" . base64_encode($subjectText) . "?=";
        $boundary = "----=_NextPart_" . md5(uniqid(time()));

        $headers = [];
        $headers[] = "From: {$fromName} <{$fromEmail}>";
        $headers[] = "Reply-To: {$fromEmail}";
        $headers[] = "Return-Path: <{$fromEmail}>";
        $headers[] = "MIME-Version: 1.0";
        $headers[] = "X-Mailer: KeyGuard-Engine/2.0";
        $headers[] = "Content-Type: multipart/mixed; boundary=\"{$boundary}\"";
        $headersStr = implode("\r\n", $headers);

        $message = "--{$boundary}\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $message .= $htmlBody . "\r\n\r\n";

        if (!empty($pdfFilePath) && file_exists($pdfFilePath)) {
            $fileContent = file_get_contents($pdfFilePath);
            $attachmentData = chunk_split(base64_encode($fileContent));

            $message .= "--{$boundary}\r\n";
            $message .= "Content-Type: application/pdf; name=\"{$attName}\"\r\n";
            $message .= "Content-Transfer-Encoding: base64\r\n";
            $message .= "Content-Disposition: attachment; filename=\"{$attName}\"\r\n\r\n";
            $message .= $attachmentData . "\r\n\r\n";
        }
        $message .= "--{$boundary}--";

        return @mail($toEmail, $encodedSubject, $message, $headersStr, "-f" . $fromEmail);
    }

    /**
     * Dispara una alerta comercial instantánea cuando se detecta un riesgo Crítico o Alto
     */
    public static function triggerCommercialAlert($domain, $email, $score, $riskLevel, $findingsCount, $leadId) {
        $webhookUrl = getenv('KEYGUARD_WEBHOOK_URL') ?: self::$defaultWebhook;

        if (!empty($webhookUrl)) {
            $isCritical = ($score < 60);
            $payload = [
                'text' => ($isCritical ? "🚨 *¡NUEVO LEAD CRÍTICO DETECTADO EN KEYGUARD!*" : "🎯 *Nuevo Lead KeyGuard Escaneado*"),
                'attachments' => [
                    [
                        'title' => "Diagnóstico para: {$domain}",
                        'color' => ($isCritical ? '#ef4444' : '#00b4d8'),
                        'fields' => [
                            ['title' => 'Dominio', 'value' => $domain, 'short' => true],
                            ['title' => 'Correo Contacto', 'value' => $email, 'short' => true],
                            ['title' => 'Puntuación', 'value' => "{$score} / 100 (" . strtoupper($riskLevel) . ")", 'short' => true],
                            ['title' => 'Brechas Detectadas', 'value' => "{$findingsCount} hallazgos", 'short' => true]
                        ],
                        'footer' => "KeyGuard Lead Acquisition Engine | ID #{$leadId}",
                        'ts' => time()
                    ]
                ]
            ];

            $ch = curl_init($webhookUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);
            @curl_exec($ch);
            curl_close($ch);
        }

        // Alerta interna por correo si el score es crítico
        if ($score < 60) {
            $internalSubject = "=?UTF-8?B?" . base64_encode("🚨 URGENTE: Lead Crítico Escaneado ({$domain}) - Riesgo BEC") . "?=";
            $internalHeaders = "From: Keystone Advisors <contacto@keystoneadvisors.cl>\r\nReply-To: contacto@keystoneadvisors.cl\r\nContent-Type: text/html; charset=UTF-8\r\n";
            $internalBody = "
            <h3>Se ha registrado un prospecto con exposición crítica:</h3>
            <ul>
                <li><strong>Dominio:</strong> {$domain}</li>
                <li><strong>Email:</strong> {$email}</li>
                <li><strong>Score:</strong> {$score}/100 ({$riskLevel})</li>
                <li><strong>Fecha/Hora:</strong> " . date('d/m/Y H:i:s') . "</li>
                <li><strong>ID Lead:</strong> #{$leadId}</li>
            </ul>
            <p>Se sugiere contactar al tomador de decisión dentro de 1 hora.</p>";
            @mail("contacto@keystoneadvisors.cl", $internalSubject, $internalBody, $internalHeaders, "-fcontacto@keystoneadvisors.cl");
        }
    }

    /**
     * Envia propuesta comercial de Radiografia Forense 72h via SMTP Titan
     */
    public static function sendRadiografiaOfferEmail($toEmail, $domain, $score, $riskLevel, $dmarcPolicy, $findingsArr = array(), $pdfPath = "") {
        $toEmail = trim($toEmail);
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $subjectText = "Diagnostico Forense de Exposicion Digital: " . $domain . " [Keystone Advisors]";
        $scoreColor = ($score < 50 ? "#ef4444" : ($score < 80 ? "#f59e0b" : "#10b981"));

        $findingsListHtml = "";
        if (!empty($findingsArr)) {
            foreach ($findingsArr as $f) {
                $fTitle = htmlspecialchars($f["title"] ?? "Brecha detectada");
                $fDesc = htmlspecialchars($f["desc"] ?? "");
                $findingsListHtml .= "<li style=\"margin-bottom: 10px;\"><strong style=\"color:#0F2A4A;\">Vulnerabilidad: " . $fTitle . "</strong><div style=\"color:#64748b; font-size:13px; margin-top:2px;\">" . $fDesc . "</div></li>";
            }
        } else {
            $findingsListHtml = "<li style=\"margin-bottom: 8px;\"><strong style=\"color:#0F2A4A;\">Politica DMARC no restrictiva (p=" . $dmarcPolicy . "):</strong><div style=\"color:#64748b; font-size:13px; margin-top:2px;\">Permite que atacantes emitan correos fraudulentos suplantando su dominio corporativo.</div></li>";
        }

        $htmlBody = "<!DOCTYPE html><html><head><meta charset=\"UTF-8\"></head><body style=\"font-family: Arial, sans-serif; background-color: #f8fafc; margin: 0; padding: 20px; color: #334155;\"><table align=\"center\" border=\"0\" cellpadding=\"0\" cellspacing=\"0\" width=\"100%\" style=\"max-width: 620px; background: #ffffff; border-radius: 10px; overflow: hidden; border: 1px solid #e2e8f0; box-shadow: 0 4px 12px rgba(0,0,0,0.06);\"><tr><td style=\"background-color: #001730; padding: 28px 24px; text-align: center;\"><h1 style=\"color: #ffffff; margin: 0; font-size: 22px; font-weight: 800; letter-spacing: 0.5px;\">KEYSTONE ADVISORS</h1><p style=\"color: #00b4d8; font-size: 12px; margin: 6px 0 0 0; text-transform: uppercase; font-weight: bold; letter-spacing: 1px;\">Ciberseguridad B2B & Resiliencia Operativa</p></td></tr><tr><td style=\"padding: 30px 25px;\"><h2 style=\"color: #003366; font-size: 18px; margin-top: 0; font-weight: 800;\">Radiografia Forense de Exposicion Digital</h2><p style=\"font-size: 14px; line-height: 1.6; color: #475569;\">Estimado/a,<br><br>Junto con saludar, le contactamos desde el area de <strong>Ciberseguridad & Resiliencia Operativa de Keystone Advisors</strong>.<br><br>Registramos recientemente la ejecucion de nuestro Escaner de Exposicion Digital para el dominio corporativo <strong>" . htmlspecialchars($domain) . "</strong>. A continuacion presentamos el resumen preliminar obtenido:</p><table width=\"100%\" border=\"0\" cellpadding=\"0\" cellspacing=\"0\" style=\"background: #f8fafc; border-left: 4px solid " . $scoreColor . "; border-radius: 6px; margin: 20px 0; border: 1px solid #e2e8f0; border-left-width: 4px;\"><tr><td style=\"padding: 16px 20px;\"><div style=\"font-size: 12px; color: #64748b; font-weight: 700; text-transform: uppercase;\">CYBER EXPOSURE SCORE:</div><div style=\"font-size: 26px; font-weight: 800; color: " . $scoreColor . "; margin-top: 2px;\">" . $score . " / 100</div><div style=\"font-size: 13px; color: #1e293b; margin-top: 4px;\">Nivel de Exposicion Detectado: <strong style=\"color:" . $scoreColor . ";\">" . htmlspecialchars($riskLevel) . "</strong></div><div style=\"font-size: 12px; color: #64748b; margin-top: 2px;\">Politica DMARC en vigor: <code>p=" . htmlspecialchars($dmarcPolicy) . "</code></div></td></tr></table><h3 style=\"font-size: 15px; color: #003366; margin-top: 25px; margin-bottom: 12px; font-weight: 700;\">Vulnerabilidades Identificadas:</h3><ul style=\"font-size: 13px; color: #475569; line-height: 1.6; padding-left: 20px; margin: 0;\">" . $findingsListHtml . "</ul><div style=\"background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 18px; margin: 25px 0;\"><h4 style=\"color: #166534; margin: 0 0 8px 0; font-size: 14px; font-weight: 700;\">[Propuesta] Radiografia Forense en 72 Horas</h4><p style=\"font-size: 13px; color: #166534; line-height: 1.5; margin: 0;\">Ponemos a su disposicion nuestro servicio especializado <strong>Executive Cyber Risk Assessment</strong> para auditar filtraciones de credenciales de sus directores en la Dark Web (OSINT), guiar la implementacion segura de <code>p=reject</code> y blindar sus transferencias de tesoreria contra fraudes asistidos por IA.</p></div><div style=\"text-align: center; margin: 30px 0 20px 0;\"><a href=\"https://www.keystoneadvisors.cl/#contacto\" style=\"background: #003366; color: #ffffff; padding: 14px 28px; text-decoration: none; border-radius: 6px; font-weight: bold; display: inline-block; font-size: 14px; box-shadow: 0 4px 10px rgba(0,51,102,0.25);\">Coordinar Sesion de Diagnostico (15 min) &rarr;</a></div><p style=\"font-size: 12px; color: #94a3b8; text-align: center; margin-top: 25px; border-top: 1px solid #e2e8f0; padding-top: 15px; line-height: 1.5;\">Keystone Advisors &bull; Ciberseguridad & Gobernanza de TI<br>Santiago &bull; Lima &bull; Ciudad de Panama &bull; Edmonton<br>WhatsApp Directo: +56 9 7971 9857 | Web: www.keystoneadvisors.cl</p></td></tr></table></body></html>";

        $smtpConfig = self::getSmtpConfig();
        if (!empty($smtpConfig["smtp_pass"])) {
            require_once __DIR__ . "/KeystoneSmtpClient.php";
            $smtpClient = new KeystoneSmtpClient($smtpConfig);
            $attName = !empty($pdfPath) && file_exists($pdfPath) ? basename($pdfPath) : "";
            return $smtpClient->send($toEmail, $subjectText, $htmlBody, $pdfPath, $attName);
        }

        $fromEmail = $smtpConfig["smtp_from"] ?? "contacto@keystoneadvisors.cl";
        $fromName = $smtpConfig["smtp_name"] ?? "Keystone Advisors";
        $headers = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <" . $fromEmail . ">\r\n" .
                   "Reply-To: " . $fromEmail . "\r\n" .
                   "Content-Type: text/html; charset=UTF-8\r\n";
        return @mail($toEmail, "=?UTF-8?B?" . base64_encode($subjectText) . "?=", $htmlBody, $headers, "-f" . $fromEmail);
    }

    /**
     * Envia correo corporativo de primer contacto a Lead o Solicitud Checkout via SMTP Titan
     */
    public static function sendLeadDirectEmail($toEmail, $name, $company, $service, $type, $phone, $customMessage = "") {
        $toEmail = trim($toEmail);
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $subjectText = "Contacto Keystone Advisors - Propuesta " . $service . " [" . (!empty($company) ? $company : $name) . "]";
        
        $msgSection = "";
        if (!empty($customMessage)) {
            $msgSection = "
            <div style=\"background: #f1f5f9; border-left: 4px solid #003366; padding: 12px 16px; border-radius: 4px; margin: 18px 0; font-size: 13px; color: #334155;\">
                <strong>Requerimiento registrado:</strong><br>
                <em>\"" . nl2br(htmlspecialchars($customMessage)) . "\"</em>
            </div>";
        }

        if ($type === "checkout") {
            $headerSubtitle = "Solicitud de Contratacion Directa";
            $introText = "Hemos recibido exitosamente su solicitud para el servicio / paquete <strong>" . htmlspecialchars($service) . "</strong> para la empresa <strong>" . htmlspecialchars($company ?: "No especificada") . "</strong>.";
            $ctaProposal = "Para avanzar con la emision de la orden de trabajo formal, asignacion del squad de especialistas certificados y parametrizacion inicial, nos gustaria coordinar una breve sesion de alineacion tecnica de 20 minutos via Teams o Google Meet.";
        } else {
            $headerSubtitle = "Consulta Comercial & Consultoria TI";
            $introText = "Agradecemos su interes en los servicios estrategicos de Keystone Advisors respecto a <strong>" . htmlspecialchars($service) . "</strong> para <strong>" . htmlspecialchars($company ?: $name) . "</strong>.";
            $ctaProposal = "Nos gustaria conocer mas detalles sobre sus objetivos, arquitectura y plazos para presentarle la propuesta tecnica y modelo de squad que mejor se adapte a su infraestructura.";
        }

        $htmlBody = "<!DOCTYPE html><html><head><meta charset=\"UTF-8\"></head><body style=\"font-family: Arial, sans-serif; background-color: #f8fafc; margin: 0; padding: 20px; color: #334155;\"><table align=\"center\" border=\"0\" cellpadding=\"0\" cellspacing=\"0\" width=\"100%\" style=\"max-width: 620px; background: #ffffff; border-radius: 10px; overflow: hidden; border: 1px solid #e2e8f0; box-shadow: 0 4px 12px rgba(0,0,0,0.06);\"><tr><td style=\"background-color: #001730; padding: 28px 24px; text-align: center;\"><h1 style=\"color: #ffffff; margin: 0; font-size: 22px; font-weight: 800; letter-spacing: 0.5px;\">KEYSTONE ADVISORS</h1><p style=\"color: #00b4d8; font-size: 12px; margin: 6px 0 0 0; text-transform: uppercase; font-weight: bold; letter-spacing: 1px;\">" . $headerSubtitle . "</p></td></tr><tr><td style=\"padding: 30px 25px;\"><h2 style=\"color: #003366; font-size: 18px; margin-top: 0; font-weight: 800;\">Estimado/a " . htmlspecialchars($name) . ",</h2><p style=\"font-size: 14px; line-height: 1.6; color: #475569;\">Junto con saludarle cordialmente, le escribimos desde el equipo directivo y comercial de <strong>Keystone Advisors</strong>.<br><br>" . $introText . "</p>" . $msgSection . "<table width=\"100%\" border=\"0\" cellpadding=\"0\" cellspacing=\"0\" style=\"background: #f8fafc; border-left: 4px solid #00b4d8; border-radius: 6px; margin: 20px 0; border: 1px solid #e2e8f0; border-left-width: 4px;\"><tr><td style=\"padding: 16px 20px;\"><div style=\"font-size: 12px; color: #64748b; font-weight: 700; text-transform: uppercase;\">RESUMEN DE REGISTRO:</div><div style=\"font-size: 14px; color: #1e293b; margin-top: 5px;\">&bull; <strong>Servicio / Producto:</strong> " . htmlspecialchars($service) . "</div><div style=\"font-size: 14px; color: #1e293b; margin-top: 3px;\">&bull; <strong>Empresa:</strong> " . htmlspecialchars($company ?: "No indicada") . "</div><div style=\"font-size: 14px; color: #1e293b; margin-top: 3px;\">&bull; <strong>Telefono:</strong> " . htmlspecialchars($phone ?: "No indicado") . "</div></td></tr></table><div style=\"background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 18px; margin: 25px 0;\"><h4 style=\"color: #166534; margin: 0 0 8px 0; font-size: 14px; font-weight: 700;\">🎯 Siguiente Paso Propuesto</h4><p style=\"font-size: 13px; color: #166534; line-height: 1.5; margin: 0;\">" . $ctaProposal . "</p></div><div style=\"text-align: center; margin: 30px 0 20px 0;\"><a href=\"https://www.keystoneadvisors.cl/#contacto\" style=\"background: #003366; color: #ffffff; padding: 14px 28px; text-decoration: none; border-radius: 6px; font-weight: bold; display: inline-block; font-size: 14px; box-shadow: 0 4px 10px rgba(0,51,102,0.25);\">Confirmar Horario de Reunion &rarr;</a></div><p style=\"font-size: 12px; color: #94a3b8; text-align: center; margin-top: 25px; border-top: 1px solid #e2e8f0; padding-top: 15px; line-height: 1.5;\">Keystone Advisors &bull; Consultoria Estrategica TI & Ciberseguridad<br>Santiago &bull; Lima &bull; Ciudad de Panama &bull; Edmonton<br>WhatsApp Directo: +56 9 7971 9857 | Web: www.keystoneadvisors.cl</p></td></tr></table></body></html>";

        $smtpConfig = self::getSmtpConfig();
        if (!empty($smtpConfig["smtp_pass"])) {
            require_once __DIR__ . "/KeystoneSmtpClient.php";
            $smtpClient = new KeystoneSmtpClient($smtpConfig);
            return $smtpClient->send($toEmail, $subjectText, $htmlBody);
        }

        $fromEmail = $smtpConfig["smtp_from"] ?? "contacto@keystoneadvisors.cl";
        $fromName = $smtpConfig["smtp_name"] ?? "Keystone Advisors";
        $headers = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <" . $fromEmail . ">\r\n" .
                   "Reply-To: " . $fromEmail . "\r\n" .
                   "Content-Type: text/html; charset=UTF-8\r\n";
        return @mail($toEmail, "=?UTF-8?B?" . base64_encode($subjectText) . "?=", $htmlBody, $headers, "-f" . $fromEmail);
    }
}
