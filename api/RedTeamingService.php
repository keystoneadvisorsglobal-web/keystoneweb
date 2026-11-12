<?php
/**
 * Keystone Advisors - RedTeamingService.php
 * Motor de Simulación de Ciberataques e Ingeniería Social Asistido por IA (Red Teaming)
 * Permite simulaciones públicas interactivas (Lead Magnet) y gestión de campañas corporativas B2B.
 */

require_once __DIR__ . '/Database.php';

class RedTeamingService {
    private PDO $db;
    
    // Credenciales Cloudflare Workers AI oficiales
    private string $cfAccountId = 'cf9641882a84e35bbf80ae7681d7ee71';
    private string $cfAiToken = 'cfut_X7T32a3GAFH9iSkdoVkFY4YOc1Vn84RqB178bS6Q3b09495d';

    public function __construct(?PDO $db = null) {
        $this->db = $db ?: Database::getInstance();
        $this->initSchema();
    }

    private function initSchema(): void {
        // Tabla de Simulaciones Públicas (Prospectos / Leads)
        $this->db->exec("CREATE TABLE IF NOT EXISTS redteam_simulations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company_name TEXT NOT NULL,
            industry TEXT NOT NULL,
            attack_vector TEXT NOT NULL,
            target_profile TEXT,
            attacker_impersonated TEXT,
            subject TEXT,
            email_body TEXT,
            ip_address TEXT,
            user_agent TEXT,
            contact_name TEXT,
            lead_email TEXT,
            lead_phone TEXT,
            status TEXT DEFAULT 'SIMULATED',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_redteam_sim_created ON redteam_simulations(created_at)");

        // Tabla de Campañas B2B Pagadas de Red Teaming
        $this->db->exec("CREATE TABLE IF NOT EXISTS redteam_campaigns (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company_name TEXT NOT NULL,
            contact_name TEXT NOT NULL,
            contact_email TEXT NOT NULL,
            plan_type TEXT DEFAULT 'PROFESSIONAL', -- STARTER, PROFESSIONAL, ENTERPRISE
            target_domains TEXT NOT NULL,
            target_users_count INTEGER DEFAULT 10,
            simulated_vectors TEXT NOT NULL, -- BEC, INVOICE_FRAUD, VISHING, PASSKEY_BYPASS
            scheduled_start DATE,
            scheduled_end DATE,
            status TEXT DEFAULT 'PLANNING', -- PLANNING, ACTIVE, COMPLETED, CANCELLED
            vulnerability_score REAL DEFAULT 0,
            report_pdf_path TEXT,
            notes TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    }

    /**
     * Simulación instantánea para el demostrador público con Cloudflare Workers AI (LLaMA 3.1)
     * Responde en menos de 2 segundos.
     */
    public function generatePublicSimulation(string $companyName, string $industry, string $vector, string $contactName = '', string $leadEmail = '', string $leadPhone = ''): array {
        $companyName = trim($companyName);
        if (empty($companyName)) {
            $companyName = 'Su Empresa';
        }
        $industry = trim($industry) ?: 'Servicios Corporativos';
        $vector = trim($vector) ?: 'BEC_CEO_FRAUD';

        $vectorLabels = [
            'BEC_CEO_FRAUD' => 'Fraude del CEO / Transferencia Urgente Fuera de Nómina',
            'SUPPLIER_ACCOUNT_CHANGE' => 'Cambio Fraudulento de Cuenta Corriente de Proveedor Clave',
            'TAX_INSPECTION_FRAUD' => 'Spear-Phishing Tributario / Notificación Crítica del SII'
        ];

        $vectorDescription = $vectorLabels[$vector] ?? $vectorLabels['BEC_CEO_FRAUD'];

        $prompt = "Eres un especialista senior en Red Teaming y ciberseguridad ofensiva para el sector corporativo. " .
                  "Tu tarea es generar un ejemplo forense realista de correo electrónico de ingeniería social de alta precisión para entrenar a directivos. " .
                  "Empresa objetivo: '{$companyName}'. Industria: '{$industry}'. " .
                  "Vector de ataque seleccionado: '{$vectorDescription}'. " .
                  "\nGenera un correo persuasivo, con urgencia creíble y lenguaje corporativo chileno o latinoamericano neutro, que simule un atacante avanzado suplantando una identidad legítima. " .
                  "\nDebes responder OBLIGATORIAMENTE en formato JSON con las siguientes claves: " .
                  "1. 'target_profile': a quién va dirigido (ej. Jefe de Tesorería, Gerente de Finanzas). " .
                  "2. 'attacker_impersonated': a quién suplanta el atacante (ej. Gerente General, Proveedor Estratégico). " .
                  "3. 'subject': asunto del correo altamente convincente. " .
                  "4. 'email_body': el texto completo del correo de suplantación (máximo 120 palabras). " .
                  "5. 'psychological_triggers': array de 3 gatillos psicológicos usados (ej. Urgencia, Confidencialidad, Autoridad). " .
                  "6. 'detection_tip': un consejo forense clave de cómo un humano entrenado podría sospechar de este correo. " .
                  "7. 'deepshield_defense': una frase contundente explicando por qué Keystone DeepShield bloquea este ataque automáticamente mediante desafío Fuera de Banda (OOB) y biometría.";

        $simulationData = $this->callCloudflareLlama($prompt);

        // Fallback algorítmico si el LLM tarda o falla
        if (empty($simulationData) || empty($simulationData['subject'])) {
            $simulationData = $this->getAlgorithmicFallback($companyName, $industry, $vector);
        }

        // Registrar la simulación para trazabilidad y lead generation
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
            $initialStatus = (!empty($leadEmail)) ? 'LEAD_CAPTURED' : 'SIMULATED';
            $stmt = $this->db->prepare("INSERT INTO redteam_simulations 
                (company_name, industry, attack_vector, target_profile, attacker_impersonated, subject, email_body, ip_address, user_agent, contact_name, lead_email, lead_phone, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $companyName, $industry, $vector,
                $simulationData['target_profile'] ?? '',
                $simulationData['attacker_impersonated'] ?? '',
                $simulationData['subject'] ?? '',
                $simulationData['email_body'] ?? '',
                $ip, $ua, $contactName, $leadEmail, $leadPhone, $initialStatus
            ]);
            
            // Si el prospecto ingresó sus datos de contacto, notificar de inmediato
            if (!empty($leadEmail)) {
                $newSimId = (int)$this->db->lastInsertId();
                $this->notifySalesTeam($newSimId, $companyName, $contactName, $leadEmail, $leadPhone, $vector);
            }
            $simulationData['simulation_id'] = (int)$this->db->lastInsertId();
        } catch (Exception $e) {}

        return $simulationData;
    }

    /**
     * Llamada a Cloudflare Workers AI (@cf/meta/llama-3.1-8b-instruct)
     */
    private function callCloudflareLlama(string $prompt): ?array {
        $url = "https://api.cloudflare.com/client/v4/accounts/{$this->cfAccountId}/ai/run/@cf/meta/llama-3.1-8b-instruct";
        
        $payload = [
            'messages' => [
                ['role' => 'system', 'content' => 'Eres un analista de ciberseguridad ofensiva. Devuelve ÚNICAMENTE un objeto JSON válido y parseable, sin delimitadores de código markdown, sin explicaciones antes ni después.'],
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => 0.2,
            'max_tokens' => 800
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->cfAiToken,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8); // Ultra rápido

        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200 && !empty($response)) {
            $json = json_decode($response, true);
            $raw = $json['result']['response'] ?? '';
            if (is_array($raw)) {
                $raw = json_encode($raw);
            }
            if (is_string($raw) && !empty($raw)) {
                $clean = trim($raw);
                // Extraer el bloque JSON si viene envuelto en markdown
                if (preg_match('/\{.*\}/s', $clean, $matches)) {
                    $clean = $matches[0];
                }
                $parsed = json_decode($clean, true);
                if (is_array($parsed) && isset($parsed['subject'])) {
                    return $parsed;
                }
            }
        }
        return null;
    }

    /**
     * Fallback algorítmico premium en caso de interrupción de red
     */
    private function getAlgorithmicFallback(string $companyName, string $industry, string $vector): array {
        if ($vector === 'SUPPLIER_ACCOUNT_CHANGE') {
            return [
                'target_profile' => 'Jefe de Tesorería & Cuentas por Pagar',
                'attacker_impersonated' => 'Gerente de Cobranzas de Proveedor Crítico',
                'subject' => "URGENTE: Actualización Datos Bancarios para Factura Pendiente - {$companyName}",
                'email_body' => "Estimado equipo de Finanzas,\n\nJunto con saludar, les informamos que debido a una auditoría bancaria interna hemos modificado nuestra cuenta receptora para los pagos de esta semana. Agradecemos transferir el monto de la factura adjunta a la nueva cuenta corriente del Banco Santander N° 77482910 a nombre de Servicios Integrales SpA.\n\nPor favor confirmar recepción para evitar la retención del servicio contratado.",
                'psychological_triggers' => ['Urgencia de Continuidad Operativa', 'Falsa Autoridad Contable', 'Contexto de Facturación Real'],
                'detection_tip' => 'El dominio remitente usa una letra cambiada (typosquatting) y la cuenta bancaria no coincide con el padrón histórico.',
                'deepshield_defense' => 'Keystone DeepShield intercepta este cambio de cuenta automáticamente con su Whitelist inmutable y exige confirmación OOB biométrica antes de liberar el dinero.'
            ];
        } elseif ($vector === 'TAX_INSPECTION_FRAUD') {
            return [
                'target_profile' => 'Contador General & Representante Legal',
                'attacker_impersonated' => 'Fiscalizador Servicio de Impuestos Internos (SII)',
                'subject' => "NOTIFICACIÓN URGENTE: Discrepancia en Declaración F29 y Bloqueo Preventivo - {$companyName}",
                'email_body' => "Señor(a) Contribuyente de {$companyName}:\n\nSe ha detectado una inconsistencia de crédito fiscal en su última declaración F29. Para evitar la suspensión de timbraje y multas bajo la Ley N° 21.459, debe descargar el acta de requerimiento pericial adjunta e ingresar sus credenciales para subsanar la observación antes de las 17:00 hrs de hoy.",
                'psychological_triggers' => ['Temor a Sanción Legal / Multas', 'Límite Horario Estricto', 'Apariencia Oficial del Estado'],
                'detection_tip' => 'El enlace no apunta al portal oficial del SII y el archivo adjunto contiene macros o scripts ejecutables.',
                'deepshield_defense' => 'El blindaje DMARC p=reject de Keystone destruye correos de servidores no autenticados antes de que ingresen a la bandeja de sus colaboradores.'
            ];
        } else {
            // BEC_CEO_FRAUD por defecto
            return [
                'target_profile' => 'Jefe de Finanzas / Tesorería',
                'attacker_impersonated' => 'Gerente General / Directorio',
                'subject' => "CONFIDENCIAL: Operación Adquisición Estratégica - Pago Urgente Hoy",
                'email_body' => "Hola,\n\nEstoy en una reunión de directorio muy delicada y no puedo hablar por teléfono. Estamos cerrando una adquisición estratégica confidencial en el sector de {$industry} para {$companyName}.\n\nNecesito que proceses una transferencia por anticipo de \$14.500.000 antes de las 14:00 hrs para garantizar la exclusividad. Te paso los datos de la cuenta por esta vía. Por favor no lo comentes con el equipo hasta que hagamos el anuncio oficial.",
                'psychological_triggers' => ['Secreto y Confidencialidad Forzada', 'Presión Jerárquica del CEO', 'Imposibilidad de Llamar por Teléfono'],
                'detection_tip' => 'El atacante aísla a la víctima prohibiendo la comunicación telefónica y apela al estatus confidencial para eludir controles contables.',
                'deepshield_defense' => 'El protocolo de Desafío Anti-Voz IA y aprobación OOB de DeepShield hace físicamente imposible que un operador desembolse fondos sin que el director lo autorice en su smartphone.'
            ];
        }
    }

    private function notifySalesTeam(int $simId, string $company, string $contact, string $email, string $phone, string $vector): void {
        try {
            require_once __DIR__ . '/LeadNotificationService.php';
            require_once __DIR__ . '/KeystoneSmtpClient.php';
            $smtpConfig = LeadNotificationService::getSmtpConfig();
            if (!empty($smtpConfig['smtp_pass'])) {
                $client = new KeystoneSmtpClient($smtpConfig);
                $body = "<h3>🎯 Nuevo Lead de Ciberseguridad: Red Teaming Asistido por IA</h3>" .
                        "<p>Un directivo ha ejecutado una simulación de ataque y registrado sus datos de contacto para una cotización formal:</p>" .
                        "<table style='border-collapse:collapse; width:100%; max-width:550px; font-family:sans-serif; font-size:14px;'>" .
                        "<tr style='background:#f8fafc;'><td style='padding:8px; border:1px solid #cbd5e1;'><strong>Empresa:</strong></td><td style='padding:8px; border:1px solid #cbd5e1;'>{$company}</td></tr>" .
                        "<tr><td style='padding:8px; border:1px solid #cbd5e1;'><strong>Contacto:</strong></td><td style='padding:8px; border:1px solid #cbd5e1;'>{$contact}</td></tr>" .
                        "<tr style='background:#f8fafc;'><td style='padding:8px; border:1px solid #cbd5e1;'><strong>Correo:</strong></td><td style='padding:8px; border:1px solid #cbd5e1;'><a href='mailto:{$email}'>{$email}</a></td></tr>" .
                        "<tr><td style='padding:8px; border:1px solid #cbd5e1;'><strong>Teléfono / WhatsApp:</strong></td><td style='padding:8px; border:1px solid #cbd5e1;'><a href='https://wa.me/" . preg_replace('/[^0-9]/', '', $phone) . "'>{$phone}</a></td></tr>" .
                        "<tr style='background:#f8fafc;'><td style='padding:8px; border:1px solid #cbd5e1;'><strong>Vector Evaluado:</strong></td><td style='padding:8px; border:1px solid #cbd5e1; color:#0284c7; font-weight:bold;'>{$vector}</td></tr>" .
                        "</table>" .
                        "<p style='margin-top:15px;'><a href='https://www.keystoneadvisors.cl/admin/?tab=redteaming' style='background:#0284c7; color:#fff; padding:8px 16px; text-decoration:none; border-radius:4px;'>Ver en Consola de Operaciones ➜</a></p>";
                $client->send('contacto@keystoneadvisors.cl', "🎯 Lead Calificado Red Teaming: {$company} ({$contact})", $body);
            }
        } catch (Exception $e) {}
    }

    /**
     * Guardar contacto de lead interesado desde el demostrador público
     */
    public function captureSimulationLead(int $simulationId, string $email, string $phone = ''): bool {
        $stmt = $this->db->prepare("UPDATE redteam_simulations SET lead_email = ?, status = 'LEAD_CAPTURED' WHERE id = ?");
        $ok = $stmt->execute([$email, $simulationId]);

        // Notificar al equipo comercial de Keystone
        try {
            require_once __DIR__ . '/LeadNotificationService.php';
            require_once __DIR__ . '/KeystoneSmtpClient.php';
            $smtpConfig = LeadNotificationService::getSmtpConfig();
            if (!empty($smtpConfig['smtp_pass'])) {
                $client = new KeystoneSmtpClient($smtpConfig);
                $body = "<h3>Nuevo Lead de Red Teaming Asistido por IA</h3>" .
                        "<p>Un prospecto ejecutó una simulación y solicitó una cotización de entrenamiento corporativo.</p>" .
                        "<p><strong>Correo:</strong> {$email}<br><strong>Teléfono:</strong> {$phone}<br><strong>ID Simulación:</strong> #{$simulationId}</p>";
                $client->send('contacto@keystoneadvisors.cl', '🎯 Lead Calificado: Red Teaming IA B2B', $body);
            }
        } catch (Exception $e) {}

        return $ok;
    }

    /**
     * Gestión de Campañas B2B Pagadas (Admin)
     */
    public function listCampaigns(): array {
        $stmt = $this->db->query("SELECT * FROM redteam_campaigns ORDER BY created_at DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createCampaign(array $data): array {
        $companyName = trim($data['company_name'] ?? '');
        $contactName = trim($data['contact_name'] ?? '');
        $contactEmail = trim($data['contact_email'] ?? '');
        $planType = in_array($data['plan_type'] ?? '', ['STARTER', 'PROFESSIONAL', 'ENTERPRISE']) ? $data['plan_type'] : 'PROFESSIONAL';
        $targetDomains = trim($data['target_domains'] ?? '');
        $targetUsers = max(1, (int)($data['target_users_count'] ?? 10));
        $vectors = is_array($data['simulated_vectors'] ?? null) ? implode(',', $data['simulated_vectors']) : ($data['simulated_vectors'] ?? 'BEC,INVOICE_FRAUD');
        $notes = trim($data['notes'] ?? '');

        if (empty($companyName) || empty($contactEmail)) {
            return ['success' => false, 'error' => 'Empresa y correo de contacto son obligatorios.'];
        }

        $stmt = $this->db->prepare("INSERT INTO redteam_campaigns 
            (company_name, contact_name, contact_email, plan_type, target_domains, target_users_count, simulated_vectors, notes, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'PLANNING')");
        $stmt->execute([$companyName, $contactName, $contactEmail, $planType, $targetDomains, $targetUsers, $vectors, $notes]);

        return ['success' => true, 'campaign_id' => (int)$this->db->lastInsertId()];
    }

    public function updateCampaignStatus(int $campaignId, string $status): bool {
        $stmt = $this->db->prepare("UPDATE redteam_campaigns SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        return $stmt->execute([$status, $campaignId]);
    }

    public function getCampaign(int $campaignId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM redteam_campaigns WHERE id = ?");
        $stmt->execute([$campaignId]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        return $res ?: null;
    }

    public function getCampaignTargets(int $campaignId): array {
        $stmt = $this->db->prepare("SELECT * FROM redteam_targets WHERE campaign_id = ? ORDER BY id ASC");
        $stmt->execute([$campaignId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function importTargets(int $campaignId, string $csvText): int {
        $lines = explode("\n", str_replace("\r", "", $csvText));
        $imported = 0;
        $insert = $this->db->prepare("INSERT INTO redteam_targets (campaign_id, full_name, email, role_title, department, unique_token, status) VALUES (?, ?, ?, ?, ?, ?, 'PENDING')");

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || stripos($line, 'email') !== false || stripos($line, 'correo') !== false) {
                continue;
            }
            $parts = str_getcsv($line);
            if (count($parts) >= 2) {
                $name = trim($parts[0]);
                $email = strtolower(trim($parts[1]));
                $role = isset($parts[2]) ? trim($parts[2]) : '';
                $dept = isset($parts[3]) ? trim($parts[3]) : 'General';
                $token = bin2hex(random_bytes(16));

                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    try {
                        $insert->execute([$campaignId, $name, $email, $role, $dept, $token]);
                        $imported++;
                    } catch (Exception $e) {
                        // Ignorar duplicados
                    }
                }
            }
        }

        // Actualizar contador en la campaña
        $this->db->prepare("UPDATE redteam_campaigns SET target_users_count = (SELECT COUNT(*) FROM redteam_targets WHERE campaign_id = ?) WHERE id = ?")->execute([$campaignId, $campaignId]);
        return $imported;
    }

    public function getCampaignMetrics(int $campaignId): array {
        $targets = $this->getCampaignTargets($campaignId);
        $total = count($targets);
        if ($total === 0) {
            return [
                'total_targets' => 0,
                'opened_count' => 0,
                'open_rate' => 0,
                'clicked_count' => 0,
                'click_rate' => 0,
                'compromised_count' => 0,
                'compromised_rate' => 0,
                'departments' => []
            ];
        }

        $opened = 0;
        $clicked = 0;
        $compromised = 0;
        $depts = [];

        foreach ($targets as $t) {
            $d = !empty($t['department']) ? $t['department'] : 'General';
            if (!isset($depts[$d])) {
                $depts[$d] = ['eval' => 0, 'clicks' => 0, 'comp' => 0, 'risk' => 'BAJO / SEGURO'];
            }
            $depts[$d]['eval']++;

            if (in_array($t['status'], ['OPENED', 'CLICKED', 'COMPROMISED'])) {
                $opened++;
            }
            if (in_array($t['status'], ['CLICKED', 'COMPROMISED'])) {
                $clicked++;
                $depts[$d]['clicks']++;
            }
            if ($t['status'] === 'COMPROMISED') {
                $compromised++;
                $depts[$d]['comp']++;
            }
        }

        foreach ($depts as $d => &$val) {
            $rate = $val['eval'] > 0 ? ($val['clicks'] / $val['eval']) * 100 : 0;
            if ($rate >= 50) $val['risk'] = 'CRÍTICO (BEC)';
            elseif ($rate >= 25) $val['risk'] = 'ALTO (Facturas)';
            elseif ($rate > 0) $val['risk'] = 'MEDIO';
            else $val['risk'] = 'BAJO / SEGURO';
        }

        $clickRate = round(($clicked / $total) * 100, 1);
        $openRate = round(($opened / $total) * 100, 1);
        $compRate = round(($compromised / $total) * 100, 1);

        // Actualizar vulnerability_score en campaña
        $this->db->prepare("UPDATE redteam_campaigns SET vulnerability_score = ? WHERE id = ?")->execute([$clickRate, $campaignId]);

        return [
            'total_targets' => $total,
            'opened_count' => $opened,
            'open_rate' => $openRate,
            'clicked_count' => $clicked,
            'click_rate' => $clickRate,
            'compromised_count' => $compromised,
            'compromised_rate' => $compRate,
            'departments' => $depts
        ];
    }


    public function generateTargetLure(array $target, array $campaign): array {
        $name = $target['full_name'];
        $email = $target['email'];
        $role = !empty($target['role_title']) ? $target['role_title'] : 'Colaborador';
        $dept = !empty($target['department']) ? $target['department'] : 'General';
        $company = $campaign['company_name'];
        $token = $target['unique_token'];
        $link = "https://www.keystoneadvisors.cl/shield/rt.php?t={$token}";

        // Selección del vector más idóneo según el departamento
        if (stripos($dept, 'tesor') !== false || stripos($dept, 'finan') !== false || stripos($role, 'conta') !== false || stripos($role, 'pago') !== false) {
            $vector = 'INVOICE_FRAUD';
            $subject = "URGENTE: Modificación de Datos Bancarios y Factura Pendiente - Servicios TI Global";
            $senderDisplay = "Facturación Proveedores <proveedores@keystoneadvisors.cl>";
            $body = "Estimada {$name} ({$role}),\n\n"
                  . "Junto con saludar, le informamos que debido a una reestructuración de nuestras operaciones financieras y cierre de año bancario, nuestra cuenta corriente en Banco de Chile ha quedado inhabilitada para recepción de transferencias.\n\n"
                  . "A partir de hoy, todas las órdenes de compra y facturas pendientes de emisión hacia {$company} deben ser cursadas a nuestra nueva cuenta corriente corporativa.\n\n"
                  . "Para revisar el anexo de cambio de cuenta firmado digitalmente y el estado de la factura pendiente, ingrese al siguiente enlace institucional:\n"
                  . "👉 Ver Comprobante y Nuevos Datos Bancarios: {$link}\n\n"
                  . "Agradecemos confirmar la recepción y actualización de este cambio en su sistema ERP a la brevedad para no suspender el servicio.\n\n"
                  . "Atentamente,\n"
                  . "Departamento de Cobranzas & Tesorería\n"
                  . "Servicios de Tecnología e Integraciones SpA";
        } elseif (stripos($dept, 'geren') !== false || stripos($role, 'geren') !== false || stripos($role, 'director') !== false || stripos($role, 'cfo') !== false || stripos($role, 'ceo') !== false) {
            $vector = 'BEC_CEO_FRAUD';
            $subject = "CONFIDENCIAL: Requerimiento Urgente del Directorio / M&A Operativo";
            $senderDisplay = "Dirección Ejecutiva <presidencia@keystoneadvisors.cl>";
            $body = "Estimado/a {$name},\n\n"
                  . "Me encuentro en este momento en una reunión privada y confidencial de Directorio fuera de la oficina, por lo que no puedo atender llamadas telefónicas.\n\n"
                  . "Estamos cerrando una operación estratégica urgente y reservada para {$company}. Necesito que revise de forma inmediata el memorándum adjunto en el enlace seguro y me confirme su visto bueno para proceder hoy antes del cierre bancario de las 14:00 hrs.\n\n"
                  . "👉 Acceder al Memorándum Confidencial de Operaciones: {$link}\n\n"
                  . "Por favor mantener estricta reserva de este asunto con el resto del equipo hasta que emitamos el comunicado oficial.\n\n"
                  . "Saludos cordiales,\n"
                  . "Dirección General & Presidencia\n"
                  . "{$company}";
        } else {
            $vector = 'SPEAR_PHISHING_SII';
            $subject = "NOTIFICACIÓN TRIBUTARIA: Inconsistencias F-29 Detectadas Periodo Reciente";
            $senderDisplay = "Servicio de Impuestos Internos <notificaciones@keystoneadvisors.cl>";
            $body = "Estimado/a {$name} ({$role}),\n\n"
                  . "El Servicio de Impuestos Internos informa que mediante cruce de información electrónica con el registro de compras y ventas de {$company}, se han detectado observaciones e inconsistencias en la declaración mensual F-29.\n\n"
                  . "Dispone de un plazo fatal de 48 horas hábiles para subsanar los antecedentes antes de que se proceda al bloqueo de timbraje o emisión de documentos tributarios electrónicos.\n\n"
                  . "👉 Descargar Detalle de Observaciones y Citación Digital: {$link}\n\n"
                  . "Subdirección de Fiscalización Tributaria\n"
                  . "Servicio de Impuestos Internos - Chile";
        }

        return [
            'vector' => $vector,
            'subject' => $subject,
            'sender_display' => $senderDisplay,
            'body' => $body,
            'link' => $link
        ];
    }

}
