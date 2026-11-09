<?php
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Endpoint de escaneo pasivo y público de dominios
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Payload JSON inválido']);
    exit;
}

$domain = isset($data['domain']) ? trim($data['domain']) : '';
$email = isset($data['email']) ? trim($data['email']) : '';
$name = isset($data['name']) ? trim($data['name']) : 'Visitante Ejecutivo';

if (empty($domain) || empty($email)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Dominio y correo electrónico corporativo son obligatorios']);
    exit;
}

// Blindaje Anti-DoS / Rate Limiting (Máximo 10 escaneos por IP cada 1 hora)
$ip_address = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
try {
    $db_check = new PDO('sqlite:' . __DIR__ . '/../data/leads_keystone.sqlite');
    $db_check->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt_rate = $db_check->prepare("SELECT COUNT(*) FROM cyber_scans WHERE ip_address = ? AND created_at > datetime('now', '-1 hour')");
    $stmt_rate->execute([$ip_address]);
    if ((int)$stmt_rate->fetchColumn() >= 10) {
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'error' => 'Límite de solicitudes alcanzado. Para proteger la estabilidad del servicio, se permite un máximo de 10 diagnósticos por hora.'
        ]);
        exit;
    }
} catch (Exception $e) {
    // Si la base de datos se encuentra momentáneamente bloqueada por concurrencia, permitir continuar
}

// Limpiar dominio (remover http/https, barras, etc.)
$clean_domain = preg_replace('#^https?://#i', '', $domain);
$clean_domain = explode('/', $clean_domain)[0];
$clean_domain = strtolower(trim($clean_domain));

// Validar sintaxis básica de dominio
if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/i', $clean_domain)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Formato de dominio no válido (ejemplo: miempresa.cl)']);
    exit;
}

// 1. CHEQUEO PASIVO DNS: DMARC, SPF, MX
$score = 100;
$findings = [];
$dmarc_status = 'no_configurado';
$dmarc_policy = 'none';

// Chequeo DMARC (_dmarc.domain)
$dmarc_records = @dns_get_record("_dmarc.{$clean_domain}", DNS_TXT);
$has_dmarc = false;
if (!empty($dmarc_records)) {
    foreach ($dmarc_records as $rec) {
        $txt = isset($rec['txt']) ? $rec['txt'] : '';
        if (stripos($txt, 'v=DMARC1') !== false) {
            $has_dmarc = true;
            if (stripos($txt, 'p=reject') !== false) {
                $dmarc_status = 'blindado';
                $dmarc_policy = 'reject';
            } elseif (stripos($txt, 'p=quarantine') !== false) {
                $dmarc_status = 'cuarentena';
                $dmarc_policy = 'quarantine';
                $score -= 15;
            } else {
                $dmarc_status = 'solo_monitoreo';
                $dmarc_policy = 'none';
                $score -= 25;
            }
            break;
        }
    }
}

if (!$has_dmarc) {
    $score -= 35;
    $findings[] = [
        'severity' => 'critical',
        'title' => 'Ausencia de Protección DMARC',
        'desc' => 'Cualquier ciberdelincuente puede emitir correos fraudulentos suplantando su dominio corporativo hacia clientes o bancos (Vulnerabilidad crítica a estafas BEC y phishing con IA).'
    ];
} elseif ($dmarc_status === 'solo_monitoreo') {
    $findings[] = [
        'severity' => 'warning',
        'title' => 'DMARC en modo permisivo (p=none)',
        'desc' => 'Su dominio monitorea pero NO bloquea correos suplantados. Los atacantes aún pueden entregar mensajes maliciosos en bandejas de entrada legítimas.'
    ];
}

// Chequeo SPF
$txt_records = @dns_get_record($clean_domain, DNS_TXT);
$has_spf = false;
if (!empty($txt_records)) {
    foreach ($txt_records as $rec) {
        $txt = isset($rec['txt']) ? $rec['txt'] : '';
        if (stripos($txt, 'v=spf1') !== false) {
            $has_spf = true;
            if (stripos($txt, '+all') !== false || stripos($txt, '?all') !== false) {
                $score -= 15;
                $findings[] = [
                    'severity' => 'warning',
                    'title' => 'Política SPF Permisiva (+all / ?all)',
                    'desc' => 'El registro SPF permite que cualquier servidor en internet declare ser emisor autorizado de su organización.'
                ];
            }
            break;
        }
    }
}

if (!$has_spf) {
    $score -= 20;
    $findings[] = [
        'severity' => 'high',
        'title' => 'Sin registro SPF configurado',
        'desc' => 'No existen directivas SPF para autenticar servidores autorizados de correo corporativo.'
    ];
}

// 2. CHEQUEO HTTP / HTTPS / HEADERS DE SEGURIDAD
$url = "https://{$clean_domain}";
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_NOBODY, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 6);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_USERAGENT, 'KeystoneCyberScanner/1.0 (+https://keystoneadvisors.cl)');
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$ssl_verify_result = curl_getinfo($ch, CURLINFO_SSL_VERIFYRESULT);
$headers_text = curl_error($ch) ? '' : $response;
curl_close($ch);

$has_ssl = ($http_code > 0 && $ssl_verify_result === 0);
if (!$has_ssl) {
    $score -= 25;
    $findings[] = [
        'severity' => 'high',
        'title' => 'Problema en Certificado SSL/TLS Web',
        'desc' => 'El sitio web principal no cuenta con cifrado HTTPS válido o posee certificados vencidos o no confiables.'
    ];
}

// Chequeo de cabeceras de endurecimiento
$has_hsts = (stripos($headers_text, 'strict-transport-security') !== false);
$has_xframe = (stripos($headers_text, 'x-frame-options') !== false);
$has_csp = (stripos($headers_text, 'content-security-policy') !== false);

if (!$has_hsts) {
    $score -= 10;
    $findings[] = [
        'severity' => 'medium',
        'title' => 'Sin HSTS (Strict-Transport-Security)',
        'desc' => 'Los visitantes pueden ser degradados a conexiones inseguras HTTP sin cifrar mediante ataques de intermediario (MitM).'
    ];
}

if (!$has_xframe && !$has_csp) {
    $score -= 5;
    $findings[] = [
        'severity' => 'low',
        'title' => 'Protección Anti-Clickjacking limitada',
        'desc' => 'No se detectaron cabeceras X-Frame-Options o CSP restrictivas que eviten que su portal sea embebido en sitios maliciosos.'
    ];
}

// Normalizar puntaje
if ($score < 15) $score = 15;
if ($score > 100) $score = 100;

// Nivel de riesgo
$risk_level = 'Bajo';
$risk_color = '#10b981';
if ($score < 50) {
    $risk_level = 'Crítico';
    $risk_color = '#ef4444';
} elseif ($score < 75) {
    $risk_level = 'Alto / Moderado';
    $risk_color = '#f59e0b';
} elseif ($score < 90) {
    $risk_level = 'Aceptable';
    $risk_color = '#00b4d8';
}

// Registrar en base de datos local SQLite para prospección comercial
$leads_dir = __DIR__ . '/../data';
if (!is_dir($leads_dir)) {
    @mkdir($leads_dir, 0755, true);
}
// Migración limpia: preferir leads_keystone.sqlite con fallback
$db_file = $leads_dir . '/leads_keystone.sqlite';

$lead_id = 0;
try {
    $db = new PDO('sqlite:' . $db_file);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("CREATE TABLE IF NOT EXISTS cyber_scans (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        domain TEXT NOT NULL,
        email TEXT NOT NULL,
        name TEXT,
        score INTEGER NOT NULL,
        risk_level TEXT NOT NULL,
        dmarc_policy TEXT,
        findings_json TEXT,
        pdf_folio TEXT,
        ip_address TEXT,
        status TEXT DEFAULT 'nuevo',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $stmt = $db->prepare("INSERT INTO cyber_scans (domain, email, name, score, risk_level, dmarc_policy, findings_json, ip_address)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $clean_domain,
        $email,
        $name,
        $score,
        $risk_level,
        $dmarc_policy,
        json_encode($findings, JSON_UNESCAPED_UNICODE),
        $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
    ]);
    $lead_id = (int)$db->lastInsertId();
} catch (Exception $e) {
    // Si falla el log en SQLite, no frenar el resultado del escáner
}

// 3. GENERACIÓN AUTOMÁTICA DEL REPORTE EJECUTIVO PDF (3 PÁGINAS)
$pdf_generated = false;
$pdf_folio = '';
$pdf_url = '';

$reports_dir = __DIR__ . '/../data/reports';
if (!is_dir($reports_dir)) {
    @mkdir($reports_dir, 0755, true);
}

try {
    require_once __DIR__ . '/CyberReportPdfGenerator.php';
    require_once __DIR__ . '/LeadNotificationService.php';

    $scanPayload = [
        'domain' => $clean_domain,
        'email' => $email,
        'name' => $name,
        'score' => $score,
        'risk_level' => $risk_level,
        'dmarc_policy' => $dmarc_policy,
        'has_spf' => $has_spf,
        'has_ssl' => $has_ssl,
        'has_hsts' => $has_hsts,
        'findings' => $findings
    ];

    $pdfGen = new CyberReportPdfGenerator($scanPayload);
    $pdfGen->generateReport();
    $pdf_folio = $pdfGen->getFolio();

    $pdf_filename = 'Reporte_Ciberriesgo_' . preg_replace('/[^a-z0-9_-]/i', '_', $clean_domain) . '_' . $pdf_folio . '.pdf';
    $pdf_path = $reports_dir . '/' . $pdf_filename;
    $pdfGen->Output('F', $pdf_path);
    $pdf_generated = file_exists($pdf_path);
    $pdf_url = 'api/download_report.php?file=' . urlencode($pdf_filename);

    // Actualizar folio en base de datos
    if ($lead_id > 0 && $pdf_generated) {
        $updateStmt = $db->prepare("UPDATE cyber_scans SET pdf_folio = ? WHERE id = ?");
        $updateStmt->execute([$pdf_folio, $lead_id]);
    }

    // 4. DESPACHO DE CORREO CON PDF ADJUNTO AL PROSPECTO
    if ($pdf_generated) {
        LeadNotificationService::sendReportEmail($email, $clean_domain, $score, $risk_level, $pdf_path, $pdf_folio);
    }

    // 5. ALERTA INSTANTÁNEA AL EQUIPO COMERCIAL (WEBHOOK / CORREO)
    LeadNotificationService::triggerCommercialAlert($clean_domain, $email, $score, $risk_level, count($findings), $lead_id);

} catch (Exception $e) {
    // Si falla la generación del PDF o el correo, no impedir la respuesta JSON
}

echo json_encode([
    'success' => true,
    'domain' => $clean_domain,
    'email' => $email,
    'score' => $score,
    'risk_level' => $risk_level,
    'risk_color' => $risk_color,
    'dmarc_status' => $dmarc_status,
    'dmarc_policy' => $dmarc_policy,
    'has_spf' => $has_spf,
    'has_ssl' => $has_ssl,
    'has_hsts' => $has_hsts,
    'findings' => $findings,
    'recommendations_count' => count($findings),
    'pdf_generated' => $pdf_generated,
    'pdf_folio' => $pdf_folio,
    'pdf_url' => $pdf_url,
    'scanned_at' => date('d/m/Y H:i:s')
], JSON_UNESCAPED_UNICODE);

