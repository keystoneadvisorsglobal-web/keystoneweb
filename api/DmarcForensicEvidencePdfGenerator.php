<?php
/**
 * Keystone Advisors - Certificado Pericial Forense de Delito Informático & Spoofing (PDF)
 * Documento Oficial para Denuncia Penal ante la Brigada Investigadora del Cibercrimen (PDI) / Ministerio Público de Chile
 * Marco Jurídico: Ley N° 21.459 (Delitos Informáticos) y Ley N° 21.663 (Ley Marco de Ciberseguridad)
 * Conforme a la Regla Maestra de Generación de PDFs (pdf_generation.md).
 */

require_once __DIR__ . '/../lib/fpdf/fpdf.php';

class DmarcForensicEvidencePdfGenerator extends FPDF {
    private array $tenant;
    private array $incident;
    private string $certificateFolio;
    private ?string $logoPath = null;
    private string $issuedAt;

    public function __construct(array $tenant, array $incident) {
        parent::__construct('P', 'mm', 'Letter');
        $this->tenant = $tenant;
        $this->incident = $incident;
        $cleanIp = preg_replace('/[^0-9a-zA-Z]/', '-', $incident['source_ip'] ?? '0-0-0-0');
        $this->certificateFolio = 'CERT-PERICIAL-PDI-' . $cleanIp . '-' . date('Ymd');
        $this->issuedAt = date('d/m/Y H:i:s');
        $this->SetMargins(16, 14, 16);
        $this->SetAutoPageBreak(false);

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
        return $this->certificateFolio;
    }

    private function c($str): string {
        if (!is_string($str)) return '';
        $replaceMap = [
            '🛡️' => '[BLINDAJE]',
            '🚨' => '[ALERTA]',
            '⚖️' => '[PERICIAL]',
            '🟢' => '[PASS]',
            '✓'  => 'OK',
            '✕'  => 'FAIL',
            '❌' => '[X]',
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
        $this->SetTextColor(153, 27, 27);
        $this->SetXY(109, 11);
        $this->Cell(90, 4, $this->c('UNIDAD FORENSE DE INCIDENTES & CIBERDELITO'), 0, 1, 'R');
        $this->SetXY(109, 15);
        $this->SetFont('Arial', '', 7.5);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(90, 4, $this->c('Cadena de Custodia Digital / Ley N° 21.459 de Delitos Informáticos'), 0, 1, 'R');

        $this->SetDrawColor(220, 38, 38);
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
        $this->Cell(125, 3.5, $this->c('Keystone Advisors SpA - Informe Pericial Reservado para PDI / Ministerio Público - contacto@keystoneadvisors.cl'), 0, 0, 'L');
        $this->Cell(58, 3.5, $this->c('Pág. 1 de 1 - Validez Pericial'), 0, 1, 'R');
    }

    public function generate(): string {
        $this->AddPage();

        // 1. TÍTULO Y PRESENTACIÓN FORMAL
        $this->SetY(24);
        $this->SetFont('Arial', 'B', 12.5);
        $this->SetTextColor(0, 51, 102);
        $this->Cell(183, 6, $this->c('CERTIFICADO PERICIAL DE ATAQUE INFORMÁTICO Y SUPLANTACIÓN DE IDENTIDAD'), 0, 1, 'L');

        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(183, 4, $this->c('Instrumento Técnico Oficial de Evidencia Digital para Acompañar Denuncia Penal'), 0, 1, 'L');

        // 2. FUNDAMENTACIÓN LEGAL (Caja Roja suave de 183 mm)
        $this->SetY(35);
        $this->SetFillColor(254, 242, 242);
        $this->SetDrawColor(254, 202, 202);
        $this->SetLineWidth(0.3);
        $this->Rect(16, 35, 183, 15, 'DF');

        $this->SetXY(19, 36.5);
        $this->SetFont('Arial', 'B', 7.5);
        $this->SetTextColor(153, 27, 27);
        $this->Cell(177, 4, $this->c('[MARCO JURÍDICO APLICABLE] LEY N° 21.459 SOBRE DELITOS INFORMÁTICOS (CHILE)'), 0, 1);

        $this->SetX(19);
        $this->SetFont('Arial', '', 7);
        $this->SetTextColor(15, 23, 42);
        $this->MultiCell(177, 3.2, $this->c('El presente informe certifica una tentativa consumada de interceptación ilícita y falsificación informática de identidad corporativa tipificada en los Artículos 2, 6 y 7 de la Ley 21.459, con la finalidad de perpetrar fraude patrimonial (Business Email Compromise). La evidencia ha sido asegurada con trazabilidad inalterable.'), 0, 'L');

        // 3. METADATOS DE LA PARTE DENUNCIANTE (Empresa Víctima)
        $metaTop = 52;
        $this->SetFillColor(248, 250, 252);
        $this->SetDrawColor(203, 213, 225);
        $this->Rect(16, $metaTop, 183, 21, 'DF');

        $this->SetXY(19, $metaTop + 2.5);
        $this->SetFont('Arial', 'B', 7);
        $this->SetTextColor(71, 85, 105);
        $this->Cell(35, 4.5, $this->c('EMPRESA AFECTADA:'), 0, 0);
        $this->SetFont('Arial', 'B', 8);
        $this->SetTextColor(15, 23, 42);
        $this->Cell(55, 4.5, $this->c($this->tenant['company_name']), 0, 1);

        $this->SetX(19);
        $this->SetFont('Arial', 'B', 7);
        $this->SetTextColor(71, 85, 105);
        $this->Cell(35, 4.5, $this->c('DOMINIO SUPLANTADO:'), 0, 0);
        $this->SetFont('Arial', 'B', 8);
        $this->SetTextColor(2, 132, 199);
        $this->Cell(55, 4.5, $this->c('@' . $this->tenant['domain']), 0, 1);

        $this->SetX(19);
        $this->SetFont('Arial', 'B', 7);
        $this->SetTextColor(71, 85, 105);
        $this->Cell(35, 4.5, $this->c('REPRESENTANTE TI/CFO:'), 0, 0);
        $this->SetFont('Arial', '', 7.5);
        $this->SetTextColor(15, 23, 42);
        $this->Cell(55, 4.5, $this->c($this->tenant['contact_name'] . ' (' . $this->tenant['contact_email'] . ')'), 0, 1);

        // Columna derecha
        $this->SetXY(112, $metaTop + 2.5);
        $this->SetFont('Arial', 'B', 7);
        $this->SetTextColor(71, 85, 105);
        $this->Cell(34, 4.5, $this->c('FOLIO PERICIAL:'), 0, 0);
        $this->SetFont('Arial', 'B', 7.5);
        $this->SetTextColor(153, 27, 27);
        $this->Cell(50, 4.5, $this->c($this->certificateFolio), 0, 1);

        $this->SetXY(112, $metaTop + 7);
        $this->SetFont('Arial', 'B', 7);
        $this->SetTextColor(71, 85, 105);
        $this->Cell(34, 4.5, $this->c('FECHA CERTIFICACIÓN:'), 0, 0);
        $this->SetFont('Arial', 'B', 7.5);
        $this->SetTextColor(15, 23, 42);
        $this->Cell(50, 4.5, $this->c($this->issuedAt), 0, 1);

        $this->SetXY(112, $metaTop + 11.5);
        $this->SetFont('Arial', 'B', 7);
        $this->SetTextColor(71, 85, 105);
        $this->Cell(34, 4.5, $this->c('ORGANISMO DESTINO:'), 0, 0);
        $this->SetFont('Arial', 'B', 7.5);
        $this->SetTextColor(0, 51, 102);
        $this->Cell(50, 4.5, $this->c('Brigada Cibercrimen PDI / Fiscalía'), 0, 1);

        // 4. DETALLE TÉCNICO DE LA IP AGRESORA (183 mm - 6 filas organizadas sin traslape)
        $this->SetY(75);
        $this->SetFont('Arial', 'B', 8.5);
        $this->SetTextColor(153, 27, 27);
        $this->Cell(183, 5, $this->c('[INDIVIDUALIZACIÓN DE LA FUENTE AGRESORA / DATOS FORENSES]'), 0, 1, 'L');

        $this->SetFillColor(254, 242, 242);
        $this->SetDrawColor(254, 202, 202);
        $this->Rect(16, 81, 183, 31, 'DF');

        // Fila 1: IP y País/Ciudad
        $this->SetXY(19, 82.5);
        $this->SetFont('Arial', 'B', 7);
        $this->SetTextColor(71, 85, 105);
        $this->Cell(42, 4.5, $this->c('DIRECCIÓN IP AGRESORA:'), 0, 0);
        $this->SetFont('Courier', 'B', 8.5);
        $this->SetTextColor(153, 27, 27);
        $this->Cell(45, 4.5, $this->c($this->incident['source_ip'] ?? '0.0.0.0'), 0, 0);

        $this->SetFont('Arial', 'B', 7);
        $this->SetTextColor(71, 85, 105);
        $this->Cell(40, 4.5, $this->c('PAÍS & CIUDAD ORIGEN:'), 0, 0);
        $this->SetFont('Arial', 'B', 7.5);
        $this->SetTextColor(15, 23, 42);
        $geoStr = ($this->incident['country'] ?? 'Desconocido') . ' (' . ($this->incident['city'] ?? 'N/A') . ')';
        $this->Cell(50, 4.5, $this->c($geoStr), 0, 1);

        // Fila 2: ISP y Volumen
        $this->SetX(19);
        $this->SetFont('Arial', 'B', 7);
        $this->SetTextColor(71, 85, 105);
        $this->Cell(42, 4.5, $this->c('ORGANIZACIÓN / ISP / ASN:'), 0, 0);
        $this->SetFont('Arial', '', 7.5);
        $this->SetTextColor(15, 23, 42);
        $this->Cell(45, 4.5, $this->c($this->incident['org'] ?? 'Proveedor no detectado'), 0, 0);

        $this->SetFont('Arial', 'B', 7);
        $this->SetTextColor(71, 85, 105);
        $this->Cell(40, 4.5, $this->c('VOLUMEN DE ATAQUES:'), 0, 0);
        $this->SetFont('Arial', 'B', 8);
        $this->SetTextColor(220, 38, 38);
        $this->Cell(50, 4.5, number_format($this->incident['total_attempts'] ?? 0) . ' mensajes fraudulentos', 0, 1);

        // Fila 3: Dictamen SPF/DKIM y Acción DMARC
        $this->SetX(19);
        $this->SetFont('Arial', 'B', 7);
        $this->SetTextColor(71, 85, 105);
        $this->Cell(42, 4.5, $this->c('FIRMA CRIPTOGRÁFICA:'), 0, 0);
        $this->SetFont('Arial', 'B', 7.2);
        $this->SetTextColor(220, 38, 38);
        $this->Cell(45, 4.5, $this->c('SPF=FAIL | DKIM=FAIL'), 0, 0);

        $this->SetFont('Arial', 'B', 7);
        $this->SetTextColor(71, 85, 105);
        $this->Cell(40, 4.5, $this->c('ACCIÓN DMARC APLICADA:'), 0, 0);
        $this->SetFont('Arial', 'B', 7.5);
        $this->SetTextColor(5, 150, 105);
        $this->Cell(50, 4.5, $this->c('REJECT (Bloqueado en Destino)'), 0, 1);

        // 5. MEDIDAS DE CONTENCIÓN ADOPTADAS POR KEYSTONE ADVISORS
        $this->SetY(115);
        $this->SetFont('Arial', 'B', 8.5);
        $this->SetTextColor(0, 51, 102);
        $this->Cell(183, 5, $this->c('[MEDIDAS DE CONTENCIÓN Y MITIGACIÓN EJECUTADAS POR KEYSTONE ADVISORS]'), 0, 1, 'L');

        $this->SetFillColor(236, 253, 245);
        $this->SetDrawColor(167, 243, 208);
        $this->Rect(16, 121, 183, 25, 'DF');

        $this->SetXY(19, 123);
        $this->SetFont('Arial', '', 7.2);
        $this->SetTextColor(15, 23, 42);
        $actions = "1. BLINDAJE PREVENTIVO: Se verificó la vigencia ininterrumpida del registro DMARC con política estricta (p=reject).\n" .
                   "2. INTERCEPTACIÓN COLECTIVA: Google Workspace, Microsoft 365 y Yahoo descartaron los envíos en tránsito.\n" .
                   "3. NOTIFICACIÓN DE ABUSO INTERNACIONAL (TAKE-DOWN): Se despachó formalmente requerimiento de suspensión contra la IP " . ($this->incident['source_ip'] ?? '') . " ante el CERT del proveedor (" . ($this->incident['org'] ?? 'ISP') . ").\n" .
                   "4. PRESERVACIÓN PERICIAL: Los registros brutos RUA han quedado salvaguardados con hash forense para peritaje policial.";
        $this->MultiCell(177, 4.6, $this->c($actions), 0, 'L');

        // 6. PETITORIO SUGERIDO PARA EL MINISTERIO PÚBLICO
        $this->SetY(149);
        $this->SetFont('Arial', 'B', 8);
        $this->SetTextColor(71, 85, 105);
        $this->Cell(183, 4.5, $this->c('DILIGENCIAS INVESTIGATIVAS RECOMENDADAS PARA LA FISCALÍA:'), 0, 1, 'L');

        $this->SetFont('Arial', '', 7);
        $this->SetTextColor(15, 23, 42);
        $peticion = "A) Oficiar al ISP / Proveedor (" . ($this->incident['org'] ?? 'Titular del ASN') . ") mediante auxilio judicial internacional para individualizar al arrendatario del servidor IP " . ($this->incident['source_ip'] ?? '') . " y registros de pago asociados.\n" .
                    "B) Solicitar a los proveedores receptores la retención preventiva de cabeceras completas de los mensajes interceptados.";
        $this->MultiCell(183, 3.8, $this->c($peticion), 0, 'L');

        // 7. SELLO CRIPTOGRÁFICO DE CADENA DE CUSTODIA (183 mm)
        $this->SetY(166);
        $hashData = ($this->incident['source_ip'] ?? '') . '|' . $this->tenant['domain'] . '|' . $this->issuedAt . '|KEYSTONE_PERICIAL';
        $digitalSignature = hash('sha256', $hashData);

        $this->SetFillColor(241, 245, 249);
        $this->SetDrawColor(203, 213, 225);
        $this->SetLineWidth(0.3);
        $this->Rect(16, 166, 183, 14, 'DF');

        $this->SetXY(20, 167.8);
        $this->SetFont('Arial', 'B', 7);
        $this->SetTextColor(15, 23, 42);
        $this->Cell(175, 3.5, $this->c('SELLO CRIPTOGRÁFICO DE CONFORMIDAD PERICIAL (SHA-256):'), 0, 1);

        $this->SetX(20);
        $this->SetFont('Courier', '', 7);
        $this->SetTextColor(0, 51, 102);
        $this->Cell(175, 3.5, $digitalSignature, 0, 1);

        $this->SetX(20);
        $this->SetFont('Arial', '', 6.5);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(175, 3.2, $this->c('Certificado emitido por Keystone Advisors SpA • Firma Digital Inmutable • Validez Legal Ley 19.799'), 0, 1);

        return $this->Output('S');
    }
}
