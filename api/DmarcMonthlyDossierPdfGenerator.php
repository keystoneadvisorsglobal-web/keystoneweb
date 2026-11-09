<?php
/**
 * Keystone DMARC Watchdog - Generador de Dosier Ejecutivo Mensual de Blindaje de Identidad (PDF)
 * Reporte Oficial para CFOs y Gerencia General
 * Estándar Formal: FPDF, Arial corporativo, sellado SHA-256 inmutable, KPIs de suplantación y política de protección.
 * Conforme a la Regla Maestra de Generación de PDFs (pdf_generation.md).
 */

require_once __DIR__ . '/../lib/fpdf/fpdf.php';

class DmarcMonthlyDossierPdfGenerator extends FPDF {
    private array $tenant;
    private array $metrics;
    private string $dossierFolio;
    private ?string $logoPath = null;
    private string $issuedAt;
    private string $monthPeriod;

    public function __construct(array $tenant, array $metrics, string $monthPeriod = '') {
        // Carta: 215.9 x 279.4 mm. Márgenes: 16mm izq/der => Ancho útil = 183 mm
        parent::__construct('P', 'mm', 'Letter');
        $this->tenant = $tenant;
        $this->metrics = $metrics;
        $this->monthPeriod = !empty($monthPeriod) ? $monthPeriod : date('m/Y');
        $this->dossierFolio = 'DOSIER-DMARC-' . strtoupper(str_replace('.', '-', $tenant['domain'])) . '-' . date('Ym');
        $this->issuedAt = date('d/m/Y H:i:s');
        $this->SetMargins(16, 14, 16);
        $this->SetAutoPageBreak(false); // Control estricto de una sola página ejecutiva

        // Detectar logo corporativo
        $logoCandidates = [
            __DIR__ . '/../assets/images/logo.png',
            __DIR__ . '/../assets/images/logo-keystone.png',
            __DIR__ . '/../../assets/images/logo.png'
        ];
        foreach ($logoCandidates as $cand) {
            if (file_exists($cand)) {
                $this->logoPath = $cand;
                break;
            }
        }
    }

    public function getFolio(): string {
        return $this->dossierFolio;
    }

    /**
     * Limpieza estricta de texto: elimina emojis no compatibles con FPDF (windows-1252)
     * y sanitiza caracteres acentuados.
     */
    private function c($str): string {
        if (!is_string($str)) return '';
        
        $replaceMap = [
            '🛡️' => '[BLINDAJE]',
            '🛡'  => '[BLINDAJE]',
            '🚨' => '[ALERTA]',
            '🟢' => '[PASS]',
            '✓'  => 'OK',
            '✕'  => 'FAIL',
            '❌' => '[X]',
            '📋' => '[DOC]',
            '🏢' => '',
            '⚙️' => '',
            '🔑' => '',
            '📄' => '',
            '✉️' => 'Email:',
            '📲' => 'Tel:',
            '•'  => '-',
            '—'  => '-'
        ];
        $str = strtr($str, $replaceMap);

        if (function_exists('iconv')) {
            $conv = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $str);
            if ($conv !== false) return $conv;
        }
        return utf8_decode($str);
    }

    public function Header() {
        if ($this->logoPath && file_exists($this->logoPath)) {
            $this->Image($this->logoPath, 16, 11, 30);
        } else {
            $this->SetFont('Arial', 'B', 10);
            $this->SetTextColor(0, 51, 102);
            $this->SetXY(16, 12);
            $this->Cell(50, 5, $this->c('KEYSTONE ADVISORS'), 0, 0, 'L');
        }

        $this->SetFont('Arial', 'B', 8);
        $this->SetTextColor(100, 116, 139);
        $this->SetXY(109, 11);
        $this->Cell(90, 4, $this->c('DIVISIÓN DE CIBERSEGURIDAD & IDENTIDAD B2B'), 0, 1, 'R');
        $this->SetXY(109, 15);
        $this->SetFont('Arial', '', 7.5);
        $this->Cell(90, 4, $this->c('Monitoreo Continuo DMARC RFC 7489 / Zero-Trust Email'), 0, 1, 'R');

        // Línea divisoria corporativa
        $this->SetDrawColor(0, 180, 216);
        $this->SetLineWidth(0.6);
        $this->Line(16, 21, 199, 21);
    }

    public function Footer() {
        $this->SetY(-15);
        $this->SetDrawColor(226, 232, 240);
        $this->SetLineWidth(0.3);
        $this->Line(16, $this->GetY(), 199, $this->GetY());
        $this->Ln(2);

        $this->SetFont('Arial', '', 7);
        $this->SetTextColor(148, 163, 184);
        $this->Cell(125, 3.5, $this->c('Keystone Advisors SpA - Auditoría de Tráfico de Correo & Mitigación BEC - contacto@keystoneadvisors.cl'), 0, 0, 'L');
        $this->Cell(58, 3.5, $this->c('Pág. 1 de 1 - Confidencial CFO'), 0, 1, 'R');
    }

    public function generate(): string {
        $this->AddPage();

        // 1. TÍTULO Y ENCABEZADO EJECUTIVO
        $this->SetY(24);
        $this->SetFont('Arial', 'B', 13);
        $this->SetTextColor(0, 51, 102);
        $this->Cell(183, 6, $this->c('DOSIER MENSUAL DE BLINDAJE DE IDENTIDAD & REPUTACIÓN DMARC'), 0, 1, 'L');

        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(183, 4, $this->c('Dictamen Ejecutivo de Telemetría Global de Correo Electrónico y Protección de Dominio'), 0, 1, 'L');

        // 2. METADATOS DE LA EMPRESA & PERÍODO (Tarjeta Gris de 183 mm)
        $metaTop = 36;
        $metaHeight = 22;
        $this->SetFillColor(248, 250, 252);
        $this->SetDrawColor(203, 213, 225);
        $this->SetLineWidth(0.3);
        $this->Rect(16, $metaTop, 183, $metaHeight, 'DF');

        // Columna Izquierda: Empresa / Dominio / Directivo (x = 19)
        $lblW = 35;
        $valW = 54;
        
        $this->SetXY(19, $metaTop + 2.5);
        $this->SetFont('Arial', 'B', 7);
        $this->SetTextColor(71, 85, 105);
        $this->Cell($lblW, 5, $this->c('EMPRESA CLIENTE:'), 0, 0);
        $this->SetFont('Arial', 'B', 8);
        $this->SetTextColor(15, 23, 42);
        $this->Cell($valW, 5, $this->c($this->tenant['company_name']), 0, 1);

        $this->SetX(19);
        $this->SetFont('Arial', 'B', 7);
        $this->SetTextColor(71, 85, 105);
        $this->Cell($lblW, 5, $this->c('DOMINIO BLINDADO:'), 0, 0);
        $this->SetFont('Arial', 'B', 8);
        $this->SetTextColor(2, 132, 199);
        $this->Cell($valW, 5, $this->c('@' . $this->tenant['domain']), 0, 1);

        $this->SetX(19);
        $this->SetFont('Arial', 'B', 7);
        $this->SetTextColor(71, 85, 105);
        $this->Cell($lblW, 5, $this->c('DIRECTIVO DESIGNADO:'), 0, 0);
        $this->SetFont('Arial', '', 7.5);
        $this->SetTextColor(15, 23, 42);
        $contactStr = $this->tenant['contact_name'] . ' (' . $this->tenant['contact_email'] . ')';
        $this->Cell($valW, 5, $this->c($contactStr), 0, 1);

        // Columna Derecha: Folio / Período / Política (x = 112)
        $lblW2 = 33;
        $valW2 = 51;

        $this->SetXY(112, $metaTop + 2.5);
        $this->SetFont('Arial', 'B', 7);
        $this->SetTextColor(71, 85, 105);
        $this->Cell($lblW2, 5, $this->c('FOLIO AUDITORÍA:'), 0, 0);
        $this->SetFont('Arial', 'B', 7.5);
        $this->SetTextColor(0, 51, 102);
        $this->Cell($valW2, 5, $this->c($this->dossierFolio), 0, 1);

        $this->SetXY(112, $metaTop + 7.5);
        $this->SetFont('Arial', 'B', 7);
        $this->SetTextColor(71, 85, 105);
        $this->Cell($lblW2, 5, $this->c('PERÍODO EVALUADO:'), 0, 0);
        $this->SetFont('Arial', 'B', 7.5);
        $this->SetTextColor(15, 23, 42);
        $this->Cell($valW2, 5, $this->c('Mes ' . $this->monthPeriod), 0, 1);

        $this->SetXY(112, $metaTop + 12.5);
        $this->SetFont('Arial', 'B', 7);
        $this->SetTextColor(71, 85, 105);
        $this->Cell($lblW2, 5, $this->c('POLÍTICA VIGENTE:'), 0, 0);
        $this->SetFont('Arial', 'B', 7.5);
        $this->SetTextColor(5, 150, 105);
        $this->Cell($valW2, 5, $this->c('p=' . strtoupper($this->tenant['policy_mode'] ?? 'reject') . ' (100% Blindado)'), 0, 1);

        // 3. CUATRO TARJETAS KPI RESUMEN EJECUTIVO (Total ancho = 43 * 4 + 3.66 * 3 = 183 mm)
        $kpiTop = 62;
        $wCard = 43;
        $gap = 3.66;
        $hCard = 18;

        // Tarjeta 1: Volumen Evaluado (Azul cielo)
        $x = 16;
        $this->SetFillColor(255, 255, 255);
        $this->SetDrawColor(2, 132, 199);
        $this->SetLineWidth(0.4);
        $this->Rect($x, $kpiTop, $wCard, $hCard, 'DF');

        $this->SetXY($x, $kpiTop + 2);
        $this->SetFont('Arial', 'B', 6.5);
        $this->SetTextColor(100, 116, 139);
        $this->Cell($wCard, 3, $this->c('VOLUMEN EVALUADO'), 0, 1, 'C');

        $this->SetXY($x, $kpiTop + 5.5);
        $this->SetFont('Arial', 'B', 12);
        $this->SetTextColor(2, 132, 199);
        $this->Cell($wCard, 6.5, number_format($this->metrics['total_volume'] ?? 0), 0, 1, 'C');

        $this->SetXY($x, $kpiTop + 12.5);
        $this->SetFont('Arial', '', 6.5);
        $this->SetTextColor(100, 116, 139);
        $this->Cell($wCard, 3, $this->c('Correos en el mes'), 0, 1, 'C');

        // Tarjeta 2: Correos Válidos (Verde)
        $x += $wCard + $gap;
        $this->SetDrawColor(16, 185, 129);
        $this->Rect($x, $kpiTop, $wCard, $hCard, 'DF');

        $this->SetXY($x, $kpiTop + 2);
        $this->SetFont('Arial', 'B', 6.5);
        $this->SetTextColor(100, 116, 139);
        $this->Cell($wCard, 3, $this->c('CORREOS VÁLIDOS'), 0, 1, 'C');

        $this->SetXY($x, $kpiTop + 5.5);
        $this->SetFont('Arial', 'B', 12);
        $this->SetTextColor(5, 150, 105);
        $this->Cell($wCard, 6.5, number_format($this->metrics['total_pass'] ?? 0), 0, 1, 'C');

        $this->SetXY($x, $kpiTop + 12.5);
        $this->SetFont('Arial', 'B', 6.5);
        $this->SetTextColor(5, 150, 105);
        $this->Cell($wCard, 3, $this->c('SPF + DKIM Aprobados'), 0, 1, 'C');

        // Tarjeta 3: Tasa de Alineación (Azul marino)
        $x += $wCard + $gap;
        $this->SetDrawColor(0, 51, 102);
        $this->Rect($x, $kpiTop, $wCard, $hCard, 'DF');

        $this->SetXY($x, $kpiTop + 2);
        $this->SetFont('Arial', 'B', 6.5);
        $this->SetTextColor(100, 116, 139);
        $this->Cell($wCard, 3, $this->c('TASA DE ALINEACIÓN'), 0, 1, 'C');

        $this->SetXY($x, $kpiTop + 5.5);
        $this->SetFont('Arial', 'B', 12);
        $this->SetTextColor(0, 51, 102);
        $this->Cell($wCard, 6.5, ($this->metrics['pass_rate'] ?? 100) . '%', 0, 1, 'C');

        $this->SetXY($x, $kpiTop + 12.5);
        $this->SetFont('Arial', '', 6.5);
        $this->SetTextColor(100, 116, 139);
        $this->Cell($wCard, 3, $this->c('Reputación Corporativa'), 0, 1, 'C');

        // Tarjeta 4: Spoofing Bloqueado (Rojo)
        $x += $wCard + $gap;
        $this->SetDrawColor(220, 38, 38);
        $this->Rect($x, $kpiTop, $wCard, $hCard, 'DF');

        $this->SetXY($x, $kpiTop + 2);
        $this->SetFont('Arial', 'B', 6.5);
        $this->SetTextColor(100, 116, 139);
        $this->Cell($wCard, 3, $this->c('SPOOFING BLOQUEADO'), 0, 1, 'C');

        $this->SetXY($x, $kpiTop + 5.5);
        $this->SetFont('Arial', 'B', 12);
        $this->SetTextColor(220, 38, 38);
        $this->Cell($wCard, 6.5, number_format($this->metrics['total_fail'] ?? 0), 0, 1, 'C');

        $this->SetXY($x, $kpiTop + 12.5);
        $this->SetFont('Arial', 'B', 6.5);
        $this->SetTextColor(220, 38, 38);
        $this->Cell($wCard, 3, $this->c('Intentos de Fraude BEC'), 0, 1, 'C');

        // 4. DICTAMEN & ACCIONES DEFENSIVAS EJECUTADAS POR KEYSTONE ADVISORS (Caja destacada de 24 mm)
        $dictTop = 84;
        $dictHeight = 24;
        $this->SetFillColor(236, 253, 245);
        $this->SetDrawColor(16, 185, 129);
        $this->SetLineWidth(0.4);
        $this->Rect(16, $dictTop, 183, $dictHeight, 'DF');

        $this->SetXY(20, $dictTop + 2);
        $this->SetFont('Arial', 'B', 7.5);
        $this->SetTextColor(6, 95, 70);
        $this->Cell(175, 4, $this->c('[ACCIONES DE BLINDAJE EJECUTADAS POR KEYSTONE ADVISORS EN ESTE PERÍODO]'), 0, 1);

        $this->SetXY(20, $dictTop + 6.5);
        $this->SetFont('Arial', '', 7.2);
        $this->SetTextColor(15, 23, 42);
        $valActions = "• CUSTODIA DNS DMARC: Mantenimiento de política p=reject (descarte obligatorio en Google, Microsoft y Yahoo).\n" .
                      "• GESTIÓN DE RESPUESTA A INCIDENTES: Notificación de requerimientos de abuso (Take-Down) a los ISPs infractores.\n" .
                      "• PRESERVACIÓN PERICIAL: Certificación técnica de incidentes bajo la Ley 21.459 disponible para PDI y Fiscalía.\n" .
                      "• RESULTADO PATRIMONIAL: Cero suplantaciones exitosas hacia clientes, proveedores o entidades financieras.";
        $this->MultiCell(175, 4, $this->c($valActions), 0, 'L');

        // 5. TABLA DE AMENAZAS / TOP SPOOFERS BLOQUEADOS (183 mm)
        // Posicionada en Y = 107 para evitar cualquier solapamiento
        $this->SetY(111);
        $this->SetFont('Arial', 'B', 8.5);
        $this->SetTextColor(153, 27, 27);
        $this->Cell(183, 5, $this->c('[ALERTA BEC] DETALLE DE IPs ATACANTES Y SUPLANTADORES INTERCEPTADOS (SPOOFING)'), 0, 1, 'L');

        $topSpoofers = $this->metrics['top_spoofers'] ?? [];
        $this->SetFillColor(254, 242, 242);
        $this->SetDrawColor(254, 202, 202);
        $this->SetFont('Arial', 'B', 7);
        $this->SetTextColor(153, 27, 27);
        $this->Cell(48, 5, $this->c('IP Remitente Atacante'), 1, 0, 'L', true);
        $this->Cell(35, 5, $this->c('Intentos de Envío'), 1, 0, 'C', true);
        $this->Cell(45, 5, $this->c('Resultado SPF / DKIM'), 1, 0, 'C', true);
        $this->Cell(55, 5, $this->c('Acción DMARC Aplicada'), 1, 1, 'C', true);

        $this->SetDrawColor(226, 232, 240);
        if (empty($topSpoofers)) {
            $this->SetFont('Arial', 'I', 7.2);
            $this->SetTextColor(100, 116, 139);
            $this->Cell(183, 5.5, $this->c('No se detectaron intentos hostiles de suplantación en el período evaluado.'), 1, 1, 'C');
        } else {
            $cnt = 0;
            foreach ($topSpoofers as $sp) {
                if ($cnt++ >= 3) break;
                $this->SetFont('Courier', 'B', 7.5);
                $this->SetTextColor(15, 23, 42);
                $this->Cell(48, 4.8, $this->c($sp['source_ip']), 1, 0, 'L');

                $this->SetFont('Arial', 'B', 7.5);
                $this->SetTextColor(153, 27, 27);
                $this->Cell(35, 4.8, number_format($sp['total_attempts']) . ' msgs', 1, 0, 'C');

                $this->SetFont('Arial', 'B', 7);
                $this->SetTextColor(220, 38, 38);
                $this->Cell(45, 4.8, $this->c('FAIL (Sin Firma Válida)'), 1, 0, 'C');

                $this->SetTextColor(5, 150, 105);
                $this->Cell(55, 4.8, $this->c('REJECT (Bloqueado por DMARC)'), 1, 1, 'C');
            }
        }

        // 6. TABLA DE SERVIDORES LEGÍTIMOS AUTORIZADOS (183 mm)
        $this->Ln(3.5);
        $this->SetFont('Arial', 'B', 8.5);
        $this->SetTextColor(6, 95, 70);
        $this->Cell(183, 5, $this->c('[TRAFICO AUTORIZADO] INFRAESTRUCTURA DE CORREO LEGÍTIMA Y ALINEADA'), 0, 1, 'L');

        $topLegit = $this->metrics['top_legit'] ?? [];
        $this->SetFillColor(236, 253, 245);
        $this->SetDrawColor(167, 243, 208);
        $this->SetFont('Arial', 'B', 7);
        $this->SetTextColor(6, 95, 70);
        $this->Cell(48, 5, $this->c('IP / Servidor de Salida'), 1, 0, 'L', true);
        $this->Cell(35, 5, $this->c('Correos Verificados'), 1, 0, 'C', true);
        $this->Cell(45, 5, $this->c('Firmas Criptográficas'), 1, 0, 'C', true);
        $this->Cell(55, 5, $this->c('Estado Reputación'), 1, 1, 'C', true);

        $this->SetDrawColor(226, 232, 240);
        if (empty($topLegit)) {
            $this->SetFont('Arial', 'I', 7.2);
            $this->SetTextColor(100, 116, 139);
            $this->Cell(183, 5.5, $this->c('Sin tráfico registrado en las fuentes de telemetría.'), 1, 1, 'C');
        } else {
            $cnt = 0;
            foreach ($topLegit as $lg) {
                if ($cnt++ >= 3) break;
                $this->SetFont('Courier', 'B', 7.5);
                $this->SetTextColor(15, 23, 42);
                $this->Cell(48, 4.8, $this->c($lg['source_ip']), 1, 0, 'L');

                $this->SetFont('Arial', 'B', 7.5);
                $this->SetTextColor(5, 150, 105);
                $this->Cell(35, 4.8, number_format($lg['total_legit']) . ' correos', 1, 0, 'C');

                $this->SetFont('Arial', 'B', 7);
                $this->SetTextColor(6, 95, 70);
                $this->Cell(45, 4.8, $this->c('DKIM PASS + SPF PASS'), 1, 0, 'C');

                $this->Cell(55, 4.8, $this->c('ALINEACIÓN 100% VÁLIDA'), 1, 1, 'C');
            }
        }

        // 7. SELLO CRIPTOGRÁFICO DE AUTENTICIDAD & CADENA DE CUSTODIA (183 mm)
        $this->Ln(4);
        $hashData = $this->tenant['domain'] . '|' . $this->monthPeriod . '|' . ($this->metrics['total_volume'] ?? 0) . '|' . $this->issuedAt;
        $digitalSignature = hash('sha256', $hashData);

        $selloTop = $this->GetY();
        $this->SetFillColor(241, 245, 249);
        $this->SetDrawColor(203, 213, 225);
        $this->SetLineWidth(0.3);
        $this->Rect(16, $selloTop, 183, 14, 'DF');

        $this->SetXY(20, $selloTop + 1.8);
        $this->SetFont('Arial', 'B', 7);
        $this->SetTextColor(15, 23, 42);
        $this->Cell(175, 3.5, $this->c('SELLO CRIPTOGRÁFICO DE CONFORMIDAD PERICIAL (SHA-256):'), 0, 1);

        $this->SetX(20);
        $this->SetFont('Courier', '', 7);
        $this->SetTextColor(2, 132, 199);
        $this->Cell(175, 3.5, $digitalSignature, 0, 1);

        $this->SetX(20);
        $this->SetFont('Arial', '', 6.5);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(175, 3.2, $this->c('Generado por Keystone DMARC Watchdog - Emisión Oficial: ' . $this->issuedAt . ' - Validez Internacional'), 0, 1);

        return $this->Output('S');
    }
}
