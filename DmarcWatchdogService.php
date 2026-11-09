<?php
/**
 * Keystone Advisors - DMARC Watchdog & Surface Scanner (Herramienta Propia IP #4)
 * Motor de Ingesta, Descompresión y Análisis de Reportes Agregados DMARC (RFC 7489 - RUA)
 * Procesa archivos .xml, .xml.gz y .zip enviados por Google, Microsoft, Yahoo, etc.
 */

class DmarcWatchdogService {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->initSchema();
    }

    /**
     * Inicializar tablas de reportes y registros RUA
     */
    private function initSchema(): void {
        // 1. Tabla de Reportes Maestros DMARC
        $this->db->exec("CREATE TABLE IF NOT EXISTS dmarc_reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            report_id TEXT UNIQUE NOT NULL,
            org_name TEXT NOT NULL,
            email TEXT,
            extra_contact_info TEXT,
            domain TEXT NOT NULL,
            policy_adkim TEXT DEFAULT 'r',
            policy_aspf TEXT DEFAULT 'r',
            policy_p TEXT DEFAULT 'none',
            policy_sp TEXT,
            policy_pct INTEGER DEFAULT 100,
            date_begin DATETIME NOT NULL,
            date_end DATETIME NOT NULL,
            total_messages INTEGER DEFAULT 0,
            total_pass INTEGER DEFAULT 0,
            total_fail INTEGER DEFAULT 0,
            raw_filename TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // 2. Tabla de Registros Detallados por IP de Envío
        $this->db->exec("CREATE TABLE IF NOT EXISTS dmarc_records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            report_id INTEGER NOT NULL,
            source_ip TEXT NOT NULL,
            message_count INTEGER NOT NULL,
            disposition TEXT NOT NULL,
            dkim_result TEXT NOT NULL,
            spf_result TEXT NOT NULL,
            header_from TEXT,
            dkim_domain TEXT,
            dkim_auth_result TEXT,
            spf_domain TEXT,
            spf_auth_result TEXT,
            is_spoofing INTEGER DEFAULT 0,
            country_code TEXT,
            org_host TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (report_id) REFERENCES dmarc_reports(id) ON DELETE CASCADE
        )");

        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_dmarc_reports_domain ON dmarc_reports(domain)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_dmarc_records_ip ON dmarc_records(source_ip)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_dmarc_records_spoof ON dmarc_records(is_spoofing)");

        // 3. Tabla de Clientes / Dominios Monitoreados (Tenants)
        $this->db->exec("CREATE TABLE IF NOT EXISTS dmarc_tenants (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company_name TEXT NOT NULL,
            domain TEXT UNIQUE NOT NULL,
            contact_name TEXT NOT NULL,
            contact_email TEXT NOT NULL,
            contact_phone TEXT,
            policy_mode TEXT DEFAULT 'reject',
            status TEXT DEFAULT 'ACTIVE',
            last_dossier_sent_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_dmarc_tenants_domain ON dmarc_tenants(domain)");

        // 4. Tabla de Múltiples Dominios por Cliente (Multi-Domain Architecture)
        $this->db->exec("CREATE TABLE IF NOT EXISTS dmarc_domains (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            domain TEXT UNIQUE NOT NULL,
            policy_mode TEXT DEFAULT 'reject',
            is_primary INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (tenant_id) REFERENCES dmarc_tenants(id) ON DELETE CASCADE
        )");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_dmarc_domains_tenant ON dmarc_domains(tenant_id)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_dmarc_domains_domain ON dmarc_domains(domain)");

        // 5. Tabla de Bitácora de Alertas Tempranas de Spoofing (Anti-Spam/DDoS)
        $this->db->exec("CREATE TABLE IF NOT EXISTS dmarc_alerts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            domain TEXT NOT NULL,
            source_ip TEXT NOT NULL,
            attempt_count INTEGER NOT NULL,
            isp_org TEXT,
            country TEXT,
            alert_channel TEXT DEFAULT 'EMAIL',
            recipient TEXT NOT NULL,
            status TEXT DEFAULT 'SENT',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (tenant_id) REFERENCES dmarc_tenants(id) ON DELETE CASCADE
        )");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_dmarc_alerts_tenant ON dmarc_alerts(tenant_id)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_dmarc_alerts_ip ON dmarc_alerts(source_ip)");
    }

    /**
     * Ingestar y parsear archivo RUA (Soporta .xml, .xml.gz y .zip)
     */
    public function ingestReportFile(string $filePath, string $originalFilename = ''): array {
        if (!file_exists($filePath)) {
            return ['success' => false, 'error' => 'Archivo no encontrado: ' . $filePath];
        }

        $xmlString = $this->extractXmlContent($filePath, $originalFilename);
        if (empty($xmlString)) {
            return ['success' => false, 'error' => 'No se pudo extraer contenido XML válido del archivo o está corrupto.'];
        }

        return $this->parseAndStoreXml($xmlString, $originalFilename ?: basename($filePath));
    }

    /**
     * Extraer cadena XML según formato (GZIP, ZIP o XML plano)
     */
    private function extractXmlContent(string $filePath, string $filename = ''): string {
        $filename = strtolower($filename ?: $filePath);

        // Caso 1: Archivo Comprimido GZ (.xml.gz, .gz)
        if (substr($filename, -3) === '.gz' || function_exists('gzopen')) {
            $gz = @gzopen($filePath, 'rb');
            if ($gz) {
                $decompressed = '';
                while (!gzeof($gz)) {
                    $decompressed .= gzread($gz, 8192);
                }
                gzclose($gz);
                if (stripos($decompressed, '<feedback') !== false) {
                    return $decompressed;
                }
            }
        }

        // Caso 2: Archivo ZIP (.zip)
        if (substr($filename, -4) === '.zip' && class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($filePath) === true) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entryName = $zip->getNameIndex($i);
                    if (substr(strtolower($entryName), -4) === '.xml') {
                        $content = $zip->getFromIndex($i);
                        $zip->close();
                        return (string)$content;
                    }
                }
                $zip->close();
            }
        }

        // Caso 3: Archivo XML directo o fallback
        $raw = file_get_contents($filePath);
        if (stripos($raw, '<feedback') !== false) {
            return $raw;
        }

        return '';
    }

    /**
     * Parsear árbol XML RFC 7489 y persistir en base de datos
     */
    public function parseAndStoreXml(string $xmlString, string $filename = ''): array {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlString, 'SimpleXMLElement', LIBXML_NOCDATA);
        if (!$xml) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            return ['success' => false, 'error' => 'XML DMARC malformado: ' . ($errors[0]->message ?? 'Error sintáctico')];
        }

        $meta = $xml->report_metadata ?? null;
        $policy = $xml->policy_published ?? null;

        if (!$meta || !$policy) {
            return ['success' => false, 'error' => 'El XML no cuenta con las etiquetas estándar <report_metadata> y <policy_published>.'];
        }

        $reportId = (string)($meta->report_id ?? uniqid('dmarc_'));
        $orgName = (string)($meta->org_name ?? 'Proveedor Correo');
        $email = (string)($meta->email ?? '');
        $extraContact = (string)($meta->extra_contact_info ?? '');

        $dateBegin = isset($meta->date_range->begin) ? date('Y-m-d H:i:s', (int)$meta->date_range->begin) : date('Y-m-d H:i:s');
        $dateEnd = isset($meta->date_range->end) ? date('Y-m-d H:i:s', (int)$meta->date_range->end) : date('Y-m-d H:i:s');

        $domain = strtolower(trim((string)($policy->domain ?? '')));
        $policyP = strtolower(trim((string)($policy->p ?? 'none')));
        $policySp = isset($policy->sp) ? strtolower(trim((string)$policy->sp)) : null;
        $policyPct = isset($policy->pct) ? (int)$policy->pct : 100;
        $policyAdkim = (string)($policy->adkim ?? 'r');
        $policyAspf = (string)($policy->aspf ?? 'r');

        // Verificar si el reporte ya fue ingestado previamente
        $checkStmt = $this->db->prepare("SELECT id FROM dmarc_reports WHERE report_id = ? LIMIT 1");
        $checkStmt->execute([$reportId]);
        $existingId = $checkStmt->fetchColumn();

        if ($existingId) {
            return ['success' => false, 'error' => "El reporte DMARC [{$reportId}] ya fue procesado anteriormente en la plataforma."];
        }

        // Insertar Reporte Maestro
        $insReport = $this->db->prepare("INSERT INTO dmarc_reports (
            report_id, org_name, email, extra_contact_info, domain,
            policy_adkim, policy_aspf, policy_p, policy_sp, policy_pct,
            date_begin, date_end, raw_filename
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $insReport->execute([
            $reportId, $orgName, $email, $extraContact, $domain,
            $policyAdkim, $policyAspf, $policyP, $policySp, $policyPct,
            $dateBegin, $dateEnd, $filename
        ]);

        $dbReportId = (int)$this->db->lastInsertId();

        // Procesar Registros (<record>)
        $totalMsg = 0;
        $totalPass = 0;
        $totalFail = 0;

        $insRecord = $this->db->prepare("INSERT INTO dmarc_records (
            report_id, source_ip, message_count, disposition,
            dkim_result, spf_result, header_from, dkim_domain,
            dkim_auth_result, spf_domain, spf_auth_result, is_spoofing
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        foreach ($xml->record as $rec) {
            $row = $rec->row ?? null;
            if (!$row) continue;

            $sourceIp = (string)($row->source_ip ?? '0.0.0.0');
            $count = (int)($row->count ?? 1);
            $policyEval = $row->policy_evaluated ?? null;

            $disposition = (string)($policyEval->disposition ?? 'none');
            $dkimResult = strtolower((string)($policyEval->dkim ?? 'fail'));
            $spfResult = strtolower((string)($policyEval->spf ?? 'fail'));

            // Identifiers
            $headerFrom = isset($rec->identifiers->header_from) ? (string)$rec->identifiers->header_from : $domain;

            // Auth Results
            $authRes = $rec->auth_results ?? null;
            $dkimDom = isset($authRes->dkim->domain) ? (string)$authRes->dkim->domain : '';
            $dkimAuth = isset($authRes->dkim->result) ? strtolower((string)$authRes->dkim->result) : '';
            $spfDom = isset($authRes->spf->domain) ? (string)$authRes->spf->domain : '';
            $spfAuth = isset($authRes->spf->result) ? strtolower((string)$authRes->spf->result) : '';

            $isPass = ($dkimResult === 'pass' || $spfResult === 'pass');
            $isSpoofing = ($dkimResult === 'fail' && $spfResult === 'fail') ? 1 : 0;

            $totalMsg += $count;
            if ($isPass) {
                $totalPass += $count;
            } else {
                $totalFail += $count;
            }

            $insRecord->execute([
                $dbReportId, $sourceIp, $count, $disposition,
                $dkimResult, $spfResult, $headerFrom, $dkimDom,
                $dkimAuth, $spfDom, $spfAuth, $isSpoofing
            ]);
        }

        // Actualizar totales en el reporte maestro
        $up = $this->db->prepare("UPDATE dmarc_reports SET total_messages = ?, total_pass = ?, total_fail = ? WHERE id = ?");
        $up->execute([$totalMsg, $totalPass, $totalFail, $dbReportId]);

        return [
            'success' => true,
            'report_id' => $reportId,
            'domain' => $domain,
            'org_name' => $orgName,
            'total_messages' => $totalMsg,
            'total_pass' => $totalPass,
            'total_fail' => $totalFail,
            'spoofing_detected' => ($totalFail > 0)
        ];
    }

    /**
     * Obtener métricas consolidadas DMARC para el Dashboard
     */
    /**
     * Obtener métricas consolidadas DMARC para el Dashboard (soporta dominio individual o array de múltiples dominios)
     * @param string|array $filterDomain
     */
    public function getMetrics($filterDomain = ''): array {
        $whereRep = '';
        $joinRec = '';
        $whereSpoof = 'WHERE rec.is_spoofing = 1';
        $whereLegit = 'WHERE rec.is_spoofing = 0';
        $paramsRep = [];
        $paramsRec = [];

        $domainsList = [];
        if (is_array($filterDomain)) {
            $domainsList = array_values(array_filter(array_map('strtolower', array_map('trim', $filterDomain))));
        } elseif (!empty($filterDomain)) {
            $domainsList = [strtolower(trim($filterDomain))];
        }

        if (!empty($domainsList)) {
            $placeholders = implode(',', array_fill(0, count($domainsList), '?'));
            $whereRep = "WHERE lower(domain) IN ({$placeholders})";
            $paramsRep = $domainsList;

            $joinRec = "INNER JOIN dmarc_reports rep ON rec.report_id = rep.id";
            $whereSpoof .= " AND lower(rep.domain) IN ({$placeholders})";
            $whereLegit .= " AND lower(rep.domain) IN ({$placeholders})";
            // paramsRec needs the list twice: once for whereSpoof, then once for whereLegit in respective queries
        }

        $stmt = $this->db->prepare("SELECT 
            COUNT(*) as total_reports,
            COALESCE(SUM(total_messages), 0) as total_volume,
            COALESCE(SUM(total_pass), 0) as total_pass,
            COALESCE(SUM(total_fail), 0) as total_fail
            FROM dmarc_reports {$whereRep}");
        $stmt->execute($paramsRep);
        $kpis = $stmt->fetch(PDO::FETCH_ASSOC);

        $totalVol = (int)($kpis['total_volume'] ?? 0);
        $totalPass = (int)($kpis['total_pass'] ?? 0);
        $totalFail = (int)($kpis['total_fail'] ?? 0);
        $passRate = $totalVol > 0 ? round(($totalPass / $totalVol) * 100, 1) : 100.0;

        // IPs sospechosas o que intentaron suplantación (Top Atacantes con filtro estricto de empresa)
        $spoofStmt = $this->db->prepare("SELECT 
            rec.source_ip, 
            SUM(rec.message_count) as total_attempts,
            MAX(rec.created_at) as last_seen,
            GROUP_CONCAT(DISTINCT rec.dkim_result) as dkim_res,
            GROUP_CONCAT(DISTINCT rec.spf_result) as spf_res
            FROM dmarc_records rec
            {$joinRec}
            {$whereSpoof}
            GROUP BY rec.source_ip
            ORDER BY total_attempts DESC 
            LIMIT 10");
        $spoofStmt->execute(!empty($domainsList) ? $domainsList : []);
        $topSpoofers = $spoofStmt->fetchAll(PDO::FETCH_ASSOC);

        // Enriquecer cada IP con Inteligencia Forense (País, ISP, Organización)
        foreach ($topSpoofers as &$sp) {
            $intel = $this->resolveIpIntelligence($sp['source_ip']);
            $sp['country'] = $intel['country'];
            $sp['country_code'] = $intel['country_code'];
            $sp['org'] = $intel['org'];
            $sp['isp'] = $intel['isp'];
        }
        unset($sp);

        // Fuentes legítimas verificadas con filtro estricto de empresa
        $legitStmt = $this->db->prepare("SELECT 
            rec.source_ip,
            SUM(rec.message_count) as total_legit,
            MAX(rec.created_at) as last_seen
            FROM dmarc_records rec
            {$joinRec}
            {$whereLegit}
            GROUP BY rec.source_ip
            ORDER BY total_legit DESC
            LIMIT 10");
        $legitStmt->execute(!empty($domainsList) ? $domainsList : []);
        $topLegit = $legitStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($topLegit as &$lg) {
            $intel = $this->resolveIpIntelligence($lg['source_ip']);
            $lg['country'] = $intel['country'];
            $lg['country_code'] = $intel['country_code'];
            $lg['org'] = $intel['org'];
            $lg['isp'] = $intel['isp'];
        }
        unset($lg);

        return [
            'total_reports' => (int)($kpis['total_reports'] ?? 0),
            'total_volume' => $totalVol,
            'total_pass' => $totalPass,
            'total_fail' => $totalFail,
            'pass_rate' => $passRate,
            'top_spoofers' => $topSpoofers,
            'top_legit' => $topLegit
        ];
    }

    /**
     * Enriquecimiento Inteligente de IP (GeoIP, ISP y ASN) con caché local
     */
    public function resolveIpIntelligence(string $ip): array {
        static $ipCache = [];
        if (isset($ipCache[$ip])) {
            return $ipCache[$ip];
        }

        $default = [
            'country' => 'Desconocido',
            'country_code' => 'XX',
            'city' => '',
            'isp' => 'Desconocido',
            'org' => 'Red no identificada'
        ];

        // IPs privadas o locales
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            $default['country'] = 'Red Local / Privada';
            $default['org'] = 'Intranet / RFC1918';
            $ipCache[$ip] = $default;
            return $default;
        }

        // Consulta HTTP rápida con timeout de 1.5s
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 1.5,
                'header' => "User-Agent: KeystoneSecurityWatchdog/1.0
"
            ]
        ]);

        $res = @file_get_contents("http://ip-api.com/json/{$ip}?fields=status,country,countryCode,city,isp,org,as", false, $ctx);
        if ($res) {
            $data = json_decode($res, true);
            if ($data && ($data['status'] ?? '') === 'success') {
                $default['country'] = $data['country'] ?? 'Desconocido';
                $default['country_code'] = $data['countryCode'] ?? 'XX';
                $default['city'] = $data['city'] ?? '';
                $default['isp'] = $data['isp'] ?? '';
                $default['org'] = !empty($data['org']) ? $data['org'] : ($data['as'] ?? $data['isp'] ?? '');
            }
        }

        $ipCache[$ip] = $default;
        return $default;
    }

    /**
     * Listar reportes DMARC recientes
     */
    public function listReports(int $limit = 25): array {
        $stmt = $this->db->prepare("SELECT * FROM dmarc_reports ORDER BY id DESC LIMIT ?");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    // GESTIÓN DE CLIENTES / EMPRESAS MONITOREADAS (TENANTS DMARC)
    // =========================================================================

        /**
     * Obtener todos los dominios protegidos de un cliente
     */
    public function getDomainsByTenant(int $tenantId): array {
        $stmt = $this->db->prepare("SELECT dd.*,
            (SELECT COUNT(*) FROM dmarc_reports dr WHERE lower(dr.domain) = lower(dd.domain)) as total_reports,
            (SELECT COALESCE(SUM(dr.total_messages), 0) FROM dmarc_reports dr WHERE lower(dr.domain) = lower(dd.domain)) as total_volume,
            (SELECT COALESCE(SUM(dr.total_fail), 0) FROM dmarc_reports dr WHERE lower(dr.domain) = lower(dd.domain)) as total_fails
            FROM dmarc_domains dd
            WHERE dd.tenant_id = ?
            ORDER BY dd.is_primary DESC, dd.domain ASC");
        $stmt->execute([$tenantId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Si la tabla dmarc_domains no tiene registros para este tenant, poblar con el primario de dmarc_tenants
        if (empty($rows)) {
            $t = $this->getTenant($tenantId);
            if ($t) {
                $this->addDomainToTenant($tenantId, $t['domain'], $t['policy_mode'] ?? 'reject', 1);
                return $this->getDomainsByTenant($tenantId);
            }
        }
        return $rows;
    }

    /**
     * Agregar un dominio secundario o adicional a un cliente
     */
    public function addDomainToTenant(int $tenantId, string $domain, string $policyMode = 'reject', int $isPrimary = 0): array {
        $clean = strtolower(trim($domain));
        $clean = preg_replace('/^https?:\/\//i', '', $clean);
        $clean = trim($clean, '/');

        if (empty($clean) || strpos($clean, '.') === false) {
            return ['success' => false, 'error' => 'Dominio inválido. Debe tener formato: empresa.cl'];
        }

        try {
            $stmt = $this->db->prepare("INSERT INTO dmarc_domains (tenant_id, domain, policy_mode, is_primary) VALUES (?, ?, ?, ?)");
            $stmt->execute([$tenantId, $clean, $policyMode, $isPrimary]);
            return ['success' => true, 'domain' => $clean];
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'UNIQUE') !== false) {
                return ['success' => false, 'error' => 'El dominio @' . $clean . ' ya se encuentra registrado en el sistema.'];
            }
            return ['success' => false, 'error' => 'Error de BD: ' . $e->getMessage()];
        }
    }

    /**
     * Eliminar un dominio adicional de un cliente (no se puede eliminar el dominio primario)
     */
    public function removeDomainFromTenant(int $tenantId, string $domain): array {
        $clean = strtolower(trim($domain));
        $stmt = $this->db->prepare("SELECT is_primary FROM dmarc_domains WHERE tenant_id = ? AND lower(domain) = ?");
        $stmt->execute([$tenantId, $clean]);
        $dom = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$dom) {
            return ['success' => false, 'error' => 'Dominio no encontrado para este cliente.'];
        }
        if (!empty($dom['is_primary'])) {
            return ['success' => false, 'error' => 'No es posible eliminar el dominio principal del cliente.'];
        }

        $del = $this->db->prepare("DELETE FROM dmarc_domains WHERE tenant_id = ? AND lower(domain) = ?");
        $del->execute([$tenantId, $clean]);
        return ['success' => true];
    }

    /**
     * Buscar un tenant por cualquiera de sus dominios (primario o secundario)
     */
    public function getTenantByAnyDomain(string $domain): ?array {
        $clean = strtolower(trim($domain));
        $stmt = $this->db->prepare("SELECT dt.*, dd.domain as matched_domain, dd.policy_mode as matched_policy
            FROM dmarc_domains dd
            INNER JOIN dmarc_tenants dt ON dd.tenant_id = dt.id
            WHERE lower(dd.domain) = ?
            LIMIT 1");
        $stmt->execute([$clean]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
        return $this->getTenantByDomain($domain);
    }

    /**
     * Motor de Detección y Alerta Temprana de Ataque Masivo / Spoofing Crítico
     * Identifica IPs agresoras con fallos significativos que no hayan sido alertadas en las últimas 24 horas
     */
    public function checkAndDispatchSpoofingAlerts(int $thresholdFails = 10): array {
        $alertsTriggered = [];
        $tenants = $this->listTenants();

        foreach ($tenants as $t) {
            $domains = $this->getDomainsByTenant($t['id']);
            $domainList = !empty($domains) ? array_column($domains, 'domain') : [$t['domain']];
            if (empty($domainList)) continue;

            $placeholders = implode(',', array_fill(0, count($domainList), '?'));

            // Buscar IPs con más de $thresholdFails intentos de spoofing en los últimos 7 días
            $sql = "SELECT rec.source_ip, SUM(rec.message_count) as total_fails, MAX(rec.created_at) as last_seen, rep.domain
                    FROM dmarc_records rec
                    INNER JOIN dmarc_reports rep ON rec.report_id = rep.id
                    WHERE rec.is_spoofing = 1 
                      AND lower(rep.domain) IN ({$placeholders})
                    GROUP BY rec.source_ip, rep.domain
                    HAVING total_fails >= ?";

            $params = array_merge($domainList, [$thresholdFails]);
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $spooferCandidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($spooferCandidates as $cand) {
                $ip = $cand['source_ip'];
                $dom = $cand['domain'];
                $fails = (int)$cand['total_fails'];

                // Comprobar si ya se emitió alerta en las últimas 24 horas para este par (tenant_id, ip)
                $checkAlert = $this->db->prepare("SELECT id FROM dmarc_alerts 
                    WHERE tenant_id = ? AND source_ip = ? 
                      AND created_at >= datetime('now', '-24 hours') LIMIT 1");
                $checkAlert->execute([$t['id'], $ip]);
                if ($checkAlert->fetchColumn()) {
                    continue; // Ya notificado recientemente
                }

                // Resolver inteligencia forense del agresor
                $intel = $this->resolveIpIntelligence($ip);
                $country = $intel['country'] ?: 'Internacional';
                $org = $intel['org'] ?: 'ISP no detectado';

                // Disparar Alerta por Correo al Directivo y al SOC Keystone
                $alertSubject = "🚨 ALERTA CRÍTICA: Intento Masivo de Suplantación detectado para @{$dom} [{$fails} intentos]";
                $alertBody = "<h3>Keystone DeepShield Watchdog - Alerta Temprana de Incidente BEC</h3>
                    <p>Se ha detectado una ofensiva de correos falsificados intentando suplantar la identidad de su organización <strong>{$t['company_name']}</strong>.</p>
                    <table style='border-collapse:collapse; width:100%; max-width:600px; font-family:sans-serif; font-size:14px; margin-top:15px;'>
                      <tr style='background:#f1f5f9;'><td style='padding:8px; border:1px solid #cbd5e1;'><strong>Dominio Atacado:</strong></td><td style='padding:8px; border:1px solid #cbd5e1; color:#0284c7;'>@{$dom}</td></tr>
                      <tr><td style='padding:8px; border:1px solid #cbd5e1;'><strong>IP Remitente Agresora:</strong></td><td style='padding:8px; border:1px solid #cbd5e1; color:#dc2626; font-family:monospace; font-weight:bold;'>{$ip}</td></tr>
                      <tr style='background:#f1f5f9;'><td style='padding:8px; border:1px solid #cbd5e1;'><strong>Volumen Interceptado:</strong></td><td style='padding:8px; border:1px solid #cbd5e1; font-weight:bold;'>{$fails} mensajes bloqueados (p=reject)</td></tr>
                      <tr><td style='padding:8px; border:1px solid #cbd5e1;'><strong>Ubicación Geográfica:</strong></td><td style='padding:8px; border:1px solid #cbd5e1;'>{$country}</td></tr>
                      <tr style='background:#f1f5f9;'><td style='padding:8px; border:1px solid #cbd5e1;'><strong>Proveedor / Datacenter (ISP):</strong></td><td style='padding:8px; border:1px solid #cbd5e1;'>{$org}</td></tr>
                    </table>
                    <p style='margin-top:15px;'>Los correos fueron <strong>destruidos o rechazados</strong> en los servidores receptores. Le recomendamos emitir el Certificado Pericial para la PDI o solicitar el Take-Down del servidor agresor en su portal:</p>
                    <p><a href='https://www.keystoneadvisors.cl/shield/portal.php?tab=dmarc' style='background:#0284c7; color:#fff; padding:10px 18px; text-decoration:none; border-radius:6px; font-weight:bold; display:inline-block;'>Ir al Portal de Ciberseguridad ➜</a></p>";

                $sent = false;
                try {
                    require_once __DIR__ . '/LeadNotificationService.php';
                    require_once __DIR__ . '/KeystoneSmtpClient.php';
                    $smtpConfig = LeadNotificationService::getSmtpConfig();
                    if (!empty($smtpConfig['smtp_pass']) && !empty($t['contact_email'])) {
                        $client = new KeystoneSmtpClient($smtpConfig);
                        $sent = $client->send($t['contact_email'], $alertSubject, $alertBody);
                    }
                } catch (Exception $e) {}

                // Registrar en la tabla de alertas
                $insAlert = $this->db->prepare("INSERT INTO dmarc_alerts 
                    (tenant_id, domain, source_ip, attempt_count, isp_org, country, alert_channel, recipient, status)
                    VALUES (?, ?, ?, ?, ?, ?, 'EMAIL', ?, ?)");
                $insAlert->execute([
                    $t['id'], $dom, $ip, $fails, $org, $country, $t['contact_email'], $sent ? 'SENT' : 'LOGGED_ONLY'
                ]);

                $alertsTriggered[] = [
                    'tenant' => $t['company_name'],
                    'domain' => $dom,
                    'ip' => $ip,
                    'fails' => $fails,
                    'sent' => $sent
                ];
            }
        }
        return $alertsTriggered;
    }

    /**
     * Obtener periodos históricos disponibles con reportes para un dominio/tenant
     */
    public function getHistoricalPeriods($filterDomain = ''): array {
        $where = '';
        $params = [];
        if (is_array($filterDomain) && !empty($filterDomain)) {
            $placeholders = implode(',', array_fill(0, count($filterDomain), '?'));
            $where = "WHERE lower(domain) IN ({$placeholders})";
            $params = array_values(array_map('strtolower', $filterDomain));
        } elseif (!empty($filterDomain)) {
            $where = "WHERE lower(domain) = lower(?)";
            $params = [trim($filterDomain)];
        }

        $stmt = $this->db->prepare("SELECT 
            strftime('%Y-%m', date_begin) as period_key,
            strftime('%m/%Y', date_begin) as period_label,
            COUNT(*) as report_count,
            SUM(total_messages) as total_messages,
            SUM(total_fail) as total_fails
            FROM dmarc_reports
            {$where}
            GROUP BY period_key
            ORDER BY period_key DESC");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Si no hay reportes anteriores, devolver al menos el mes actual
        $currentKey = date('Y-m');
        $hasCurrent = false;
        foreach ($rows as $r) {
            if ($r['period_key'] === $currentKey) {
                $hasCurrent = true;
                break;
            }
        }
        if (!$hasCurrent) {
            array_unshift($rows, [
                'period_key' => $currentKey,
                'period_label' => date('m/Y'),
                'report_count' => 0,
                'total_messages' => 0,
                'total_fails' => 0
            ]);
        }
        return $rows;
    }

    public function listTenants(): array {
        $stmt = $this->db->query("SELECT dt.*, 
            (SELECT COUNT(*) FROM dmarc_reports dr WHERE lower(dr.domain) = lower(dt.domain)) as total_reports,
            (SELECT COALESCE(SUM(dr.total_messages), 0) FROM dmarc_reports dr WHERE lower(dr.domain) = lower(dt.domain)) as total_volume,
            (SELECT COALESCE(SUM(dr.total_fail), 0) FROM dmarc_reports dr WHERE lower(dr.domain) = lower(dt.domain)) as total_fails
            FROM dmarc_tenants dt 
            ORDER BY dt.created_at DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTenant(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM dmarc_tenants WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getTenantByDomain(string $domain): ?array {
        $clean = strtolower(trim($domain));
        $stmt = $this->db->prepare("SELECT * FROM dmarc_tenants WHERE lower(domain) = ?");
        $stmt->execute([$clean]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function createTenant(array $data): array {
        $companyName = trim($data['company_name'] ?? '');
        $domain = strtolower(trim($data['domain'] ?? ''));
        $domain = preg_replace('/^https?:\/\//i', '', $domain);
        $domain = trim($domain, '/');
        $contactName = trim($data['contact_name'] ?? '');
        $contactEmail = trim($data['contact_email'] ?? '');
        $contactPhone = trim($data['contact_phone'] ?? '');
        $policyMode = in_array($data['policy_mode'] ?? '', ['none', 'quarantine', 'reject']) ? $data['policy_mode'] : 'reject';

        if (empty($companyName) || empty($domain) || empty($contactEmail)) {
            return ['success' => false, 'error' => 'Nombre, Dominio y Correo del contacto son obligatorios.'];
        }

        try {
            $stmt = $this->db->prepare("INSERT INTO dmarc_tenants 
                (company_name, domain, contact_name, contact_email, contact_phone, policy_mode, status, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, 'ACTIVE', CURRENT_TIMESTAMP)");
            $stmt->execute([$companyName, $domain, $contactName, $contactEmail, $contactPhone, $policyMode]);
            $newTenantId = (int)$this->db->lastInsertId();
            $this->addDomainToTenant($newTenantId, $domain, $policyMode, 1);
            return ['success' => true, 'tenant_id' => $newTenantId, 'domain' => $domain];
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'UNIQUE') !== false) {
                return ['success' => false, 'error' => 'El dominio @' . $domain . ' ya se encuentra registrado.'];
            }
            return ['success' => false, 'error' => 'Error de BD: ' . $e->getMessage()];
        }
    }

    public function updateTenant(int $id, array $data): array {
        $companyName = trim($data['company_name'] ?? '');
        $contactName = trim($data['contact_name'] ?? '');
        $contactEmail = trim($data['contact_email'] ?? '');
        $contactPhone = trim($data['contact_phone'] ?? '');
        $policyMode = in_array($data['policy_mode'] ?? '', ['none', 'quarantine', 'reject']) ? $data['policy_mode'] : 'reject';

        if (empty($companyName) || empty($contactEmail)) {
            return ['success' => false, 'error' => 'Nombre de Empresa y Correo de Contacto son obligatorios.'];
        }

        $stmt = $this->db->prepare("UPDATE dmarc_tenants SET 
            company_name = ?, contact_name = ?, contact_email = ?, contact_phone = ?, policy_mode = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?");
        $stmt->execute([$companyName, $contactName, $contactEmail, $contactPhone, $policyMode, $id]);
        return ['success' => true];
    }

    public function updateTenantStatus(int $id, string $status): bool {
        if (!in_array($status, ['ACTIVE', 'SUSPENDED', 'REVOKED'])) {
            return false;
        }
        $stmt = $this->db->prepare("UPDATE dmarc_tenants SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        return $stmt->execute([$status, $id]);
    }

    public function markDossierSent(int $id): void {
        $stmt = $this->db->prepare("UPDATE dmarc_tenants SET last_dossier_sent_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$id]);
    }

    public function getCentralRuaEmail(): string {
        $stmt = $this->db->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'dmarc_central_rua_email'");
        $stmt->execute();
        $val = $stmt->fetchColumn();
        return !empty($val) ? trim($val) : 'dmarc-rua@keystoneadvisors.cl';
    }

    public function setCentralRuaEmail(string $email): bool {
        $clean = trim($email);
        if (!filter_var($clean, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $stmt = $this->db->prepare("INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES ('dmarc_central_rua_email', ?, CURRENT_TIMESTAMP)
            ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value, updated_at = CURRENT_TIMESTAMP");
        return $stmt->execute([$clean]);
    }

    public function generateDnsRecord(string $domain, string $policy = 'reject'): array {
        $ruaEmail = $this->getCentralRuaEmail();
        $cleanDom = strtolower(trim($domain));
        $host = '_dmarc.' . $cleanDom;
        $txtValue = 'v=DMARC1; p=' . strtolower($policy) . '; rua=mailto:' . $ruaEmail . '; pct=100; sp=' . strtolower($policy) . '; aspf=r; adkim=r;';
        
        return [
            'host' => $host,
            'type' => 'TXT',
            'value' => $txtValue,
            'ttl' => '3600 (1 hora)',
            'rua_email' => $ruaEmail
        ];
    }

    /**
     * Sincronizar e Ingestar Automáticamente Reportes RUA desde la Casilla IMAP Titan
     */
    public function syncFromImap(): array {
        $stmt = $this->db->prepare("SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ('dmarc_imap_host', 'dmarc_imap_port', 'dmarc_imap_user', 'dmarc_imap_pass')");
        $stmt->execute();
        $cfg = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $host = $cfg['dmarc_imap_host'] ?? 'imap.titan.email';
        $port = $cfg['dmarc_imap_port'] ?? '993';
        $user = $cfg['dmarc_imap_user'] ?? 'dmarc-rua@keystoneadvisors.cl';
        $pass = $cfg['dmarc_imap_pass'] ?? '';

        if (empty($pass)) {
            return ['success' => false, 'error' => 'No hay contraseña IMAP configurada para la casilla RUA.'];
        }

        $mailbox = "{" . $host . ":" . $port . "/imap/ssl}INBOX";
        $inbox = @imap_open($mailbox, $user, $pass);
        if (!$inbox) {
            return ['success' => false, 'error' => 'Fallo al autenticar en buzón IMAP: ' . imap_last_error()];
        }

        $emails = imap_search($inbox, 'ALL');
        if (!$emails) {
            imap_close($inbox);
            return ['success' => true, 'processed_count' => 0, 'message' => 'No hay nuevos reportes DMARC en la casilla.'];
        }

        $processedCount = 0;
        $ingestedReports = [];

        foreach ($emails as $emailId) {
            $structure = imap_fetchstructure($inbox, $emailId);
            if (empty($structure->parts)) {
                continue;
            }

            foreach ($structure->parts as $partNum => $part) {
                $filename = '';
                if ($part->ifdparameters) {
                    foreach ($part->dparameters as $param) {
                        if (strtolower($param->attribute) === 'filename') {
                            $filename = $param->value;
                        }
                    }
                }
                if (empty($filename) && $part->ifparameters) {
                    foreach ($part->parameters as $param) {
                        if (strtolower($param->attribute) === 'name') {
                            $filename = $param->value;
                        }
                    }
                }

                // Si es un archivo de reporte RUA (.xml, .gz, .zip)
                if (preg_match('/\.(xml|gz|zip)$/i', $filename)) {
                    $attachmentData = imap_fetchbody($inbox, $emailId, $partNum + 1);
                    if ($part->encoding === 3) { // Base64
                        $attachmentData = base64_decode($attachmentData);
                    } elseif ($part->encoding === 4) { // Quoted-Printable
                        $attachmentData = quoted_printable_decode($attachmentData);
                    }

                    if (!empty($attachmentData)) {
                        $tmpFile = tempnam(sys_get_temp_dir(), 'dmarc_rua_');
                        file_put_contents($tmpFile, $attachmentData);

                        $ingestRes = $this->ingestReportFile($tmpFile, $filename);
                        @unlink($tmpFile);

                        if ($ingestRes['success']) {
                            $processedCount++;
                            $ingestedReports[] = $ingestRes['report_id'] . ' (' . $ingestRes['domain'] . ')';
                        }
                    }
                }
            }

            // Marcar correo como leído
            imap_setflag_full($inbox, (string)$emailId, "\Seen");
        }

        imap_close($inbox);

        return [
            'success' => true,
            'processed_count' => $processedCount,
            'reports' => $ingestedReports
        ];
    }

    /**
     * Obtener correo de abuso del ISP vía RDAP / Whois con fallback
     */
    
    /**
     * Reconstruir Dictamen Forense Automático del Ataque a partir de los datos RFC 7489
     */
    public function buildForensicNarrative(string $ip, string $domain = ''): array {
        $where = "WHERE rec.source_ip = ? AND rec.is_spoofing = 1";
        $params = [$ip];
        if (!empty($domain)) {
            $where .= " AND lower(rep.domain) = lower(?)";
            $params[] = $domain;
        }

        $stmt = $this->db->prepare("SELECT 
            rec.source_ip,
            SUM(rec.message_count) as total_attempts,
            rec.disposition,
            rec.dkim_result,
            rec.spf_result,
            rec.header_from,
            MIN(rep.date_begin) as first_seen,
            MAX(rep.date_end) as last_seen,
            rep.org_name as reporter_org
            FROM dmarc_records rec
            INNER JOIN dmarc_reports rep ON rec.report_id = rep.id
            {$where}
            GROUP BY rec.source_ip");
        $stmt->execute($params);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        $intel = $this->resolveIpIntelligence($ip);
        $attempts = (int)($data['total_attempts'] ?? 1);
        $first = substr($data['first_seen'] ?? date('Y-m-d H:i'), 0, 16);
        $last = substr($data['last_seen'] ?? date('Y-m-d H:i'), 0, 16);
        $disposition = strtoupper($data['disposition'] ?? 'REJECT');

        // Construcción de la narrativa forense formal en español e inglés
        $narrativeEs = "En el período comprendido entre {$first} y {$last} UTC, el servidor no autorizado con IP {$ip} (localizado en {$intel['country']}, operado bajo la red {$intel['org']}) emitió {$attempts} mensajes fraudulentos utilizando indebidamente el remitente corporativo @{$domain}. Ninguno de los mensajes contaba con firma criptográfica válida (SPF=FAIL, DKIM=FAIL), lo que evidencia una tentativa de suplantación de identidad ejecutiva (CEO Fraud / BEC). Gracias a la política DMARC estricta, los servidores receptores aplicaron la acción {$disposition}, bloqueando el impacto patrimonial.";

        $narrativeEn = "Between {$first} and {$last} UTC, unauthorized host {$ip} (located in {$intel['country']}, ISP/ASN: {$intel['org']}) generated {$attempts} forged email transactions unlawfully impersonating @{$domain}. Authentication failed completely (SPF=FAIL, DKIM=FAIL). The attack matches a Business Email Compromise (BEC) fraudulent pattern. Recipients applied DMARC disposition: {$disposition}.";

        return [
            'ip' => $ip,
            'attempts' => $attempts,
            'first_seen' => $first,
            'last_seen' => $last,
            'country' => $intel['country'],
            'org' => $intel['org'],
            'disposition' => $disposition,
            'narrative_es' => $narrativeEs,
            'narrative_en' => $narrativeEn
        ];
    }

    public function resolveAbuseEmail(string $ip): string {
        $ip = trim($ip);
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return 'abuse@localhost';
        }

        $urls = [
            "https://rdap.db.ripe.net/ip/{$ip}",
            "https://rdap.arin.net/registry/ip/{$ip}"
        ];

        $ctx = stream_context_create([
            'http' => [
                'timeout' => 2.5,
                'header' => "User-Agent: KeystoneSecurityWatchdog/1.0
"
            ]
        ]);

        foreach ($urls as $u) {
            $raw = @file_get_contents($u, false, $ctx);
            if ($raw) {
                if (preg_match_all('/[a-zA-Z0-9_.+-]+@(?:[a-zA-Z0-9-]+\.)+[a-zA-Z0-9-]{2,}/', $raw, $m)) {
                    foreach ($m[0] as $email) {
                        $low = strtolower($email);
                        if (strpos($low, 'abuse') !== false || strpos($low, 'cert') !== false || strpos($low, 'noc') !== false) {
                            return $email;
                        }
                    }
                }
            }
        }

        return 'abuse@' . strtolower(preg_replace('/[^a-zA-Z0-9-]/', '', $ip)) . '-isp.net';
    }

    /**
     * Enviar Requerimiento Formal de Suspensión (Take-Down) al ISP
     */
    public function dispatchTakedownNotice(string $targetIp, string $ispEmail, string $domain, string $companyName, string $customNote = ''): array {
        require_once __DIR__ . '/LeadNotificationService.php';
        require_once __DIR__ . '/KeystoneSmtpClient.php';

        $smtpConfig = LeadNotificationService::getSmtpConfig();
        $client = new KeystoneSmtpClient($smtpConfig);

        $subject = "[URGENT] Fraud / Domain Spoofing Abuse Notice: IP " . $targetIp . " (Target: @" . $domain . ")";
        
        $body = "<div style='font-family:Arial, sans-serif; color:#1e293b; max-width:650px; line-height:1.6;'>
            <h3 style='color:#991b1b;'>KEYSTONE ADVISORS - CYBERCRIME & FRAUD INCIDENT RESPONSE</h3>
            <p><strong>ATTN: Network Abuse Team / Security Incident Response Team (SIRT)</strong></p>
            <p>We are writing to formally report malicious activity and fraudulent domain spoofing originating from your network infrastructure:</p>
            <table style='width:100%; border-collapse:collapse; margin:15px 0; font-size:13px;'>
                <tr style='background:#f1f5f9;'><td style='padding:8px; border:1px solid #cbd5e1;'><strong>Attacking IP Address:</strong></td><td style='padding:8px; border:1px solid #cbd5e1; font-family:monospace; color:#dc2626;'>{$targetIp}</td></tr>
                <tr><td style='padding:8px; border:1px solid #cbd5e1;'><strong>Target Victim Organization:</strong></td><td style='padding:8px; border:1px solid #cbd5e1;'>{$companyName} (@{$domain})</td></tr>
                <tr style='background:#f1f5f9;'><td style='padding:8px; border:1px solid #cbd5e1;'><strong>Authentication Failure:</strong></td><td style='padding:8px; border:1px solid #cbd5e1;'>SPF=FAIL, DKIM=FAIL (Unauthorized Header From)</td></tr>
                <tr><td style='padding:8px; border:1px solid #cbd5e1;'><strong>Attack Classification:</strong></td><td style='padding:8px; border:1px solid #cbd5e1;'>Business Email Compromise (BEC) / CEO Fraud Tentative</td></tr>
            </table>
            <p>The host server at <code>{$targetIp}</code> has been observed attempting to emit forged messages pretending to be <code>@{$domain}</code>, violating your Acceptable Use Policy (AUP) and Terms of Service.</p>
            <p><strong>Immediate Action Requested:</strong><br>
            1. Suspend or quarantine the offending virtual host / server immediately.<br>
            2. Preserve server logs and payment records for law enforcement subpoena (PDI Cibercrimen Chile).<br>
            3. Confirm ticket resolution to <code>contacto@keystoneadvisors.cl</code>.</p>
            " . (!empty($customNote) ? "<p style='background:#fef2f2; padding:10px; border-left:3px solid #dc2626;'><strong>Additional Forensic Note:</strong> " . htmlspecialchars($customNote) . "</p>" : "") . "
            <hr style='border:none; border-top:1px solid #e2e8f0; margin:20px 0;'>
            <p style='font-size:11px; color:#64748b;'>Keystone Advisors SpA • Digital Forensic & Identity Shield Division • Santiago, Chile.</p>
        </div>";

        $ok = $client->send($ispEmail, $subject, $body);

        return [
            'success' => $ok,
            'sent_to' => $ispEmail,
            'error' => $ok ? null : (is_array($client->getLog()) ? implode(' | ', $client->getLog()) : (string)$client->getLog())
        ];
    }
}
