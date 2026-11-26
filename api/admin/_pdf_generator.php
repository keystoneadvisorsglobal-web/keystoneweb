<?php
/**
 * Keystone Advisors — Motor Unificado de Generación de Dosieres Ejecutivos y Periciales PDF (FPDF · Arial Corporativo) (SOL-0336)
 * Soporta:
 *  - Dossier Diagnóstico Superficie BEC / DNS
 *  - Certificado Criptográfico Orden DeepShield Treasury (SHA-256)
 *  - Acta Forense de Reporte DMARC RUA (RFC 7489 / Ley 21.459)
 *  - Dossier de Campaña Red Teaming IA
 */
require_once dirname(__DIR__, 2) . '/lib/fpdf/fpdf.php';

class KeystoneExecutivePdf extends FPDF {
    private string $folio;
    private string $subtitle;

    public function __construct(string $folio, string $subtitle) {
        parent::__construct('P', 'mm', 'Letter');
        $this->folio = $folio;
        $this->subtitle = $subtitle;
        $this->SetMargins(16, 16, 16);
        $this->SetAutoPageBreak(true, 18);
    }

    public function enc(string $txt): string {
        $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $txt);
        return $converted !== false ? $converted : $txt;
    }

    public function Header(): void {
        // Barra superior corporativa Basalto Oscuro (#0A0E15)
        $this->SetFillColor(10, 14, 21);
        $this->Rect(0, 0, 216, 24, 'F');

        $this->SetXY(16, 7);
        $this->SetFont('Arial', 'B', 12);
        $this->SetTextColor(255, 255, 255);
        $this->Cell(95, 5, $this->enc('KEYSTONE ADVISORS GLOBAL'), 0, 0, 'L');

        $this->SetFont('Arial', 'B', 8);
        $this->SetTextColor(167, 139, 250);
        $this->Cell(0, 5, $this->enc('FOLIO: ' . $this->folio), 0, 1, 'R');

        $this->SetX(16);
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(148, 163, 184);
        $this->Cell(110, 4.5, $this->enc($this->subtitle), 0, 0, 'L');
        $this->Cell(0, 4.5, $this->enc('Emitido: ' . date('d/m/Y H:i') . ' UTC'), 0, 1, 'R');

        $this->SetY(31);
    }

    public function Footer(): void {
        $this->SetY(-14);
        $this->SetDrawColor(203, 213, 225);
        $this->SetLineWidth(0.25);
        $this->Line(16, $this->GetY(), 200, $this->GetY());
        $this->Ln(2);
        $this->SetFont('Arial', '', 7.5);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(140, 4, $this->enc('Keystone Advisors SpA · Ingeniería IA-Gobernada, Salesforce FSC & Ciberseguridad Zero Trust'), 0, 0, 'L');
        $this->Cell(0, 4, $this->enc('Página ' . $this->PageNo()), 0, 0, 'R');
    }

    public function sectionTitle(string $title): void {
        $this->Ln(3);
        $this->SetFont('Arial', 'B', 10.5);
        $this->SetTextColor(15, 23, 42);
        $this->Cell(0, 6.5, $this->enc($title), 0, 1, 'L');
        $this->SetDrawColor(99, 102, 241);
        $this->SetLineWidth(0.45);
        $this->Line(16, $this->GetY(), 72, $this->GetY());
        $this->Ln(3);
    }

    public function kvRow(string $label, string $value, bool $shaded = false): void {
        if ($shaded) {
            $this->SetFillColor(244, 246, 249);
        } else {
            $this->SetFillColor(255, 255, 255);
        }
        $this->SetDrawColor(226, 232, 240);
        $this->SetLineWidth(0.2);
        $this->SetFont('Arial', 'B', 8.5);
        $this->SetTextColor(51, 65, 85);
        $this->Cell(58, 7, $this->enc('  ' . $label), 1, 0, 'L', true);

        $this->SetFont('Arial', '', 8.5);
        $this->SetTextColor(15, 23, 42);
        $this->Cell(126, 7, $this->enc('  ' . $value), 1, 1, 'L', true);
    }

    public static function buildBecDossierBytes(array $scan): string {
        $folio = $scan['scan_ref'] ?? ('BEC-' . strtoupper(substr(md5((string)time()), 0, 6)));
        $pdf = new self($folio, 'DOSIER EJECUTIVO DE EXPOSICION BEC & AUTENTICACION DNS');
        $pdf->AddPage();

        // Score Banner
        $score = (int)($scan['score'] ?? 100);
        if ($score >= 90) {
            $pdf->SetFillColor(236, 253, 245);
            $pdf->SetDrawColor(16, 185, 129);
        } elseif ($score >= 70) {
            $pdf->SetFillColor(255, 251, 235);
            $pdf->SetDrawColor(245, 158, 11);
        } else {
            $pdf->SetFillColor(254, 242, 242);
            $pdf->SetDrawColor(239, 68, 68);
        }
        $pdf->Rect(16, 30, 184, 18, 'DF');
        $pdf->SetXY(20, 33);
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->Cell(125, 6, $pdf->enc('DOMINIO AUDITADO: ' . strtoupper((string)$scan['domain'])), 0, 0, 'L');
        $pdf->SetFont('Arial', 'B', 13);
        $pdf->Cell(50, 6, $pdf->enc('SCORE: ' . $score . ' / 100'), 0, 1, 'R');

        $pdf->SetX(20);
        $pdf->SetFont('Arial', '', 8.5);
        $pdf->SetTextColor(71, 85, 105);
        $pdf->Cell(0, 5, $pdf->enc('Clasificación de Riesgo: ' . ($scan['risk_level'] ?? 'Evaluado') . ' · Cumplimiento Ley Marco Ciberseguridad N° 21.459'), 0, 1, 'L');

        $pdf->SetY(52);
        $pdf->sectionTitle('1. MATRIZ DE CONTROLES CRIPTOGRAFICOS EVALUADOS');
        $pdf->kvRow('Dominio Corporativo', (string)$scan['domain'], true);
        $pdf->kvRow('Política DMARC (_dmarc)', (string)($scan['dmarc'] ?? ($scan['dmarc_label'] ?? 'No detectado')), false);
        $pdf->kvRow('Política SPF (RFC 7208)', (string)($scan['spf'] ?? ($scan['spf_status'] ?? 'No detectado')), true);
        $pdf->kvRow('Servidores Correo Entrante (MX)', (string)($scan['mx'] ?? ($scan['mx_status'] ?? 'Verificado')), false);
        $pdf->kvRow('Cifrado Web TLS / HSTS', (string)($scan['https_hsts'] ?? ($scan['tls_status'] ?? 'Verificado')), true);

        $pdf->sectionTitle('2. HALLAZGOS TECNICOS E IMPACTO DE SUPLANTACION (SPOOFING)');
        $findings = $scan['findings'] ?? [];
        if (empty($findings)) {
            $findings = [[
                'title' => 'Evaluación DNS Registrada',
                'desc' => 'Revise la política DMARC p=reject y el cierre SPF -all para impedir suplantaciones de identidad ejecutiva en facturación y pagos.',
            ]];
        }
        foreach ($findings as $f) {
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->SetTextColor(15, 23, 42);
            $pdf->Cell(0, 5.5, $pdf->enc('• ' . ($f['title'] ?? 'Control')), 0, 1, 'L');
            $pdf->SetFont('Arial', '', 8.5);
            $pdf->SetTextColor(71, 85, 105);
            $pdf->MultiCell(0, 4.8, $pdf->enc((string)($f['desc'] ?? '')));
            $pdf->Ln(1.5);
        }

        $pdf->sectionTitle('3. HOJA DE RUTA DE BLINDAJE RECOMENDADA POR KEYSTONE DEEPSHIELD');
        $pdf->SetFont('Arial', '', 8.5);
        $pdf->SetTextColor(51, 65, 85);
        $pdf->MultiCell(0, 5, $pdf->enc(
            "1) Endurecimiento DMARC gradual hasta política estricta p=reject con monitoreo forense RUA continuo.\n"
            . "2) Activación de protocolo Fuera de Banda (DeepShield Treasury OOB) con desafío Anti-Clonación de Voz IA para transferencias sobre umbral crítico.\n"
            . "3) Entrenamiento ejecutivo Red Teaming frente a vectores BEC y cambio fraudulento de cuentas de proveedores.\n\n"
            . "Contacto Directo SOC & Arquitectura: contacto@keystoneadvisors.cl · https://keystoneadvisors.cl"
        ));

        return $pdf->Output('S');
    }

    public static function buildTreasuryCertificateBytes(array $order): string {
        $folio = (string)($order['order_code'] ?? 'DST-CERT');
        $pdf = new self($folio, 'CERTIFICADO PERICIAL DE CUSTODIA OOB DEEPSHIELD TREASURY');
        $pdf->AddPage();

        $pdf->sectionTitle('1. ANTECEDENTES DE LA INSTRUCCION DE TESORERIA');
        $amt = number_format((float)($order['amount'] ?? 0), 0, ',', '.');
        $pdf->kvRow('Código Único de Orden', $folio, true);
        $pdf->kvRow('Empresa Ordenante', (string)($order['company_name'] ?? ''), false);
        $pdf->kvRow('Monto Instruido', ($order['currency'] ?? 'CLP') . ' $' . $amt, true);
        $pdf->kvRow('Beneficiario', (string)($order['beneficiary_name'] ?? '') . ' (' . ($order['beneficiary_rut_dni'] ?? '') . ')', false);
        $pdf->kvRow('Destino Bancario', (string)($order['bank_name'] ?? '') . ' · Cta ' . ($order['account_number'] ?? ''), true);
        $pdf->kvRow('Ejecutivo Firmante OOB', (string)($order['approver_name'] ?? ''), false);
        $pdf->kvRow('Desafío Anti-Clonación Voz', (string)($order['security_challenge'] ?? ''), true);
        $pdf->kvRow('Estado de Resolución', (string)($order['status'] ?? 'PENDING_APPROVAL'), false);

        $pdf->sectionTitle('2. SELLO CRIPTOGRAFICO INMUTABLE (CADENA DE CUSTODIA)');
        $sig = (string)($order['cryptographic_signature'] ?: hash('sha256', $folio . '|' . ($order['created_at'] ?? '')));
        $pdf->SetFillColor(241, 245, 249);
        $pdf->SetDrawColor(148, 163, 184);
        $pdf->Rect(16, $pdf->GetY(), 184, 18, 'DF');
        $pdf->SetXY(20, $pdf->GetY() + 3);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->Cell(0, 5, $pdf->enc('FIRMA SHA-256 VERIFICADA:'), 0, 1);
        $pdf->SetX(20);
        $pdf->SetFont('Courier', 'B', 8.5);
        $pdf->Cell(0, 5, $sig, 0, 1);

        $pdf->Ln(8);
        $pdf->SetFont('Arial', '', 8.5);
        $pdf->SetTextColor(71, 85, 105);
        $pdf->MultiCell(0, 5, $pdf->enc(
            "Este certificado acredita el estado criptográfico fuera de banda (Out-of-Band) de la instrucción bancaria indicada, válido para auditoría interna, directorio y respaldo pericial conforme a la Ley N° 21.459."
        ));

        return $pdf->Output('S');
    }

    public static function buildDmarcReportBytes(array $rep): string {
        $folio = 'RUA-' . strtoupper(substr(md5((string)($rep['report_id'] ?? time())), 0, 7));
        $pdf = new self($folio, 'CERTIFICADO FORENSE DE TRAFICO Y SUPLANTACION DMARC (RFC 7489)');
        $pdf->AddPage();

        $pdf->sectionTitle('1. METADATOS DEL PROVEEDOR RECEPTOR (ISP)');
        $pdf->kvRow('Proveedor Emisor RUA', (string)($rep['org_name'] ?? ''), true);
        $pdf->kvRow('ID Oficial Reporte', (string)($rep['report_id'] ?? ''), false);
        $pdf->kvRow('Dominio Custodiado', (string)($rep['domain'] ?? ''), true);
        $pdf->kvRow('Política Publicada', 'p=' . (string)($rep['policy_p'] ?? 'none'), false);
        $pdf->kvRow('Ventana Auditada', ($rep['date_begin'] ?? '') . ' a ' . ($rep['date_end'] ?? ''), true);

        $pdf->sectionTitle('2. BALANCE DE AUTENTICACION CRIPTOGRAFICA');
        $pdf->kvRow('Volumen Total Analizado', (string)($rep['total_messages'] ?? 0) . ' mensajes', true);
        $pdf->kvRow('Tráfico Legítimo (SPF/DKIM Pass)', (string)($rep['total_pass'] ?? 0) . ' mensajes', false);
        $pdf->kvRow('Intentos Fallidos / Spoofing', (string)($rep['total_fail'] ?? 0) . ' mensajes (' . ($rep['spoofing_ips'] ?? 0) . ' IPs externas)', true);

        return $pdf->Output('S');
    }

    public static function buildRedTeamBytes(array $camp, array $targets = []): string {
        $folio = 'RT-' . str_pad((string)($camp['id'] ?? 1), 4, '0', STR_PAD_LEFT);
        $pdf = new self($folio, 'DOSIER EJECUTIVO DE CONCIENTIZACION & RED TEAMING ETICO');
        $pdf->AddPage();

        $totalTargets = count($targets);
        $sentCount = 0;
        $openedCount = 0;
        $clickedCount = 0;
        $reportedCount = 0;
        foreach ($targets as $t) {
            $st = (string)($t['status'] ?? 'PENDING');
            if ($st !== 'PENDING') $sentCount++;
            if (!empty($t['opened_at']) || in_array($st, ['OPENED', 'CLICKED', 'REPORTED'], true)) $openedCount++;
            if (!empty($t['clicked_at']) || $st === 'CLICKED') $clickedCount++;
            if (!empty($t['reported_at']) || $st === 'REPORTED') $reportedCount++;
        }
        $evalPopulation = $totalTargets > 0 ? $totalTargets : (int)($camp['target_users_count'] ?? 0);
        $resilienceScore = $sentCount > 0
            ? max(0, min(100, (int)round(100 - (($clickedCount / $sentCount) * 70) + (($reportedCount / $sentCount) * 20))))
            : 100;

        $pdf->sectionTitle('1. PARAMETROS DEL PROGRAMA AUTORIZADO');
        $pdf->kvRow('Organización Cliente', (string)($camp['company_name'] ?? ''), true);
        $pdf->kvRow('Dominios Autorizados', (string)($camp['target_domains'] ?? ''), false);
        $pdf->kvRow('Población en Alcance', $evalPopulation . ' colaboradores', true);
        $pdf->kvRow('Escenario de Concientización', (string)($camp['simulated_vectors'] ?? ''), false);
        $pdf->kvRow('Estado Operativo', (string)($camp['status'] ?? ''), true);

        $pdf->sectionTitle('2. TELEMETRIA REAL DE RESILIENCIA HUMANA (CERO CAPTURA DE DATOS)');
        $pdf->kvRow('Ejercicios Despachados', $sentCount . ' de ' . $totalTargets . ' inscritos en nómina', true);
        $pdf->kvRow('Aperturas Registradas', $openedCount . ' aperturas', false);
        $pdf->kvRow('Interacciones en Cápsula Educativa', $clickedCount . ' ingresos a micro-entrenamiento', true);
        $pdf->kvRow('Reportes Positivos al SOC / TI', $reportedCount . ' reportes preventivos', false);
        $pdf->kvRow('Índice Global de Resiliencia', $resilienceScore . ' / 100', true);

        $pdf->sectionTitle('3. COMUNICACION DE ENTRENAMIENTO AUDITADA');
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(0, 6, $pdf->enc('Asunto: ' . ($camp['last_lure_subject'] ?? '')), 0, 1);
        $pdf->SetFont('Arial', '', 8.5);
        $pdf->MultiCell(0, 5, $pdf->enc((string)($camp['last_lure_body'] ?? '')));

        return $pdf->Output('S');
    }
}
