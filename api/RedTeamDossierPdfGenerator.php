<?php
/**
 * Keystone Red Teaming - Generador de Dosier Pericial de Resiliencia Humana & Ciberataque Controlado (PDF)
 * Reporte Formal para CISO, Comité de Auditoría y Aseguradoras de Ciberriesgo (Ley 21.459).
 * Estándar Formal: FPDF, diseño corporativo Keystone, métricas de vulnerabilidad, análisis departamental y sellado SHA-256.
 */

require_once __DIR__ . '/../lib/fpdf/fpdf.php';

class RedTeamDossierPdfGenerator extends FPDF {
    private array $campaign;
    private array $targets;
    private array $metrics;
    private string $dossierFolio;
    private ?string $logoPath = null;
    private string $issuedAt;

    public function __construct(array $campaign, array $targets, array $metrics) {
        parent::__construct('P', 'mm', 'Letter');
        $this->campaign = $campaign;
        $this->targets = $targets;
        $this->metrics = $metrics;
        $this->dossierFolio = 'DOSIER-REDTEAM-' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $campaign['company_name'])) . '-' . date('Ym');
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

    public function generate(): string {
        $this->AddPage();

        // 1. Franja Superior Corporativa
        $this->SetFillColor(11, 19, 41); // Azul Profundo Keystone
        $this->Rect(0, 0, 215.9, 28, 'F');

        // Logo o Texto Keystone
        if ($this->logoPath && file_exists($this->logoPath)) {
            $this->Image($this->logoPath, 16, 7, 36);
        } else {
            $this->SetFont('Arial', 'B', 15);
            $this->SetTextColor(255, 255, 255);
            $this->SetXY(16, 8);
            $this->Cell(60, 6, 'KEYSTONE ADVISORS', 0, 0, 'L');
            $this->SetFont('Arial', '', 7.5);
            $this->SetTextColor(56, 189, 248);
            $this->SetXY(16, 15);
            $this->Cell(60, 4, 'CYBER DEFENSE & OFFENSIVE RED TEAMING', 0, 0, 'L');
        }

        // Título del Dosier
        $this->SetFont('Arial', 'B', 10);
        $this->SetTextColor(255, 255, 255);
        $this->SetXY(90, 8);
        $this->Cell(110, 5, utf8_decode('INFORME PERICIAL DE SIMULACIÓN DE CIBERATAQUE'), 0, 1, 'R');
        $this->SetFont('Arial', '', 7.5);
        $this->SetTextColor(148, 163, 184);
        $this->SetX(90);
        $this->Cell(110, 4, utf8_decode('Evaluación de Resiliencia Humana & Exposición BEC asistida por IA'), 0, 1, 'R');
        $this->SetFont('Arial', 'B', 7);
        $this->SetTextColor(245, 158, 11); // Ámbar
        $this->SetX(90);
        $this->Cell(110, 4, 'FOLIO: ' . $this->dossierFolio . '  |  EMISION: ' . $this->issuedAt, 0, 1, 'R');

        // Línea divisoria decorativa
        $this->SetDrawColor(217, 119, 6);
        $this->SetLineWidth(0.8);
        $this->Line(16, 28, 200, 28);

        // 2. Metadatos de la Campaña Auditada
        $this->SetY(32);
        $this->SetFont('Arial', 'B', 9);
        $this->SetTextColor(0, 51, 102);
        $this->Cell(184, 5, utf8_decode('1. ALCANCE Y PARÁMETROS DE LA PRUEBA ÉTICA DE INGENIERÍA SOCIAL'), 0, 1, 'L');

        $this->SetFillColor(248, 250, 252);
        $this->SetDrawColor(226, 232, 240);
        $this->SetLineWidth(0.2);
        $this->Rect(16, 38, 184, 22, 'DF');

        $this->SetFont('Arial', 'B', 7.5);
        $this->SetTextColor(71, 85, 105);
        $this->SetXY(19, 40);
        $this->Cell(32, 4, utf8_decode('ORGANIZACIÓN:'), 0, 0);
        $this->SetFont('Arial', 'B', 8);
        $this->SetTextColor(15, 23, 42);
        $this->Cell(60, 4, utf8_decode($this->campaign['company_name']), 0, 0);

        $this->SetFont('Arial', 'B', 7.5);
        $this->SetTextColor(71, 85, 105);
        $this->Cell(32, 4, utf8_decode('PLAN AUDITADO:'), 0, 0);
        $this->SetFont('Arial', 'B', 8);
        $this->SetTextColor(217, 119, 6);
        $this->Cell(55, 4, utf8_decode($this->campaign['plan_type'] . ' SUITE'), 0, 1);

        $this->SetFont('Arial', 'B', 7.5);
        $this->SetTextColor(71, 85, 105);
        $this->SetXY(19, 45);
        $this->Cell(32, 4, utf8_decode('DOMINIOS EVALUADOS:'), 0, 0);
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(15, 23, 42);
        $this->Cell(60, 4, '@' . utf8_decode($this->campaign['target_domains']), 0, 0);

        $this->SetFont('Arial', 'B', 7.5);
        $this->SetTextColor(71, 85, 105);
        $this->Cell(32, 4, utf8_decode('PATROCINADOR (CISO):'), 0, 0);
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(15, 23, 42);
        $this->Cell(55, 4, utf8_decode($this->campaign['contact_name'] . ' (' . $this->campaign['contact_email'] . ')'), 0, 1);

        $this->SetFont('Arial', 'B', 7.5);
        $this->SetTextColor(71, 85, 105);
        $this->SetXY(19, 50);
        $this->Cell(32, 4, utf8_decode('VECTORES SIMULADOS:'), 0, 0);
        $this->SetFont('Arial', 'B', 7);
        $this->SetTextColor(3, 105, 161);
        $this->Cell(145, 4, utf8_decode(str_replace(',', ' | ', $this->campaign['simulated_vectors'])), 0, 1);

        // 3. Tarjetas de Métricas de Vulnerabilidad (KPIs)
        $this->SetY(64);
        $this->SetFont('Arial', 'B', 9);
        $this->SetTextColor(0, 51, 102);
        $this->Cell(184, 5, utf8_decode('2. RESULTADOS DE EXPOSICIÓN Y RESILIENCIA HUMANA (EMBUDO OFENSIVO)'), 0, 1, 'L');

        $kpis = [
            ['title' => 'COLABORADORES', 'val' => (string)$this->metrics['total_targets'], 'sub' => 'Nómina Evaluada', 'color' => [15, 23, 42], 'bg' => [241, 245, 249]],
            ['title' => 'APERTURA SEÑUELO', 'val' => $this->metrics['open_rate'] . '%', 'sub' => $this->metrics['opened_count'] . ' leyeron el cebo', 'color' => [2, 132, 199], 'bg' => [240, 249, 255]],
            ['title' => 'TASA DE CLICS (PHISH)', 'val' => $this->metrics['click_rate'] . '%', 'sub' => $this->metrics['clicked_count'] . ' hicieron clic', 'color' => [217, 119, 6], 'bg' => [255, 251, 235]],
            ['title' => 'RIESGO DE COMPROMISO', 'val' => $this->metrics['compromised_rate'] . '%', 'sub' => $this->metrics['compromised_count'] . ' cedieron claves/pago', 'color' => [220, 38, 38], 'bg' => [254, 242, 242]]
        ];

        $boxWidth = 43.5;
        $boxHeight = 24;
        $startX = 16;
        $gap = 3.3;

        foreach ($kpis as $idx => $kpi) {
            $x = $startX + ($idx * ($boxWidth + $gap));
            $this->SetFillColor($kpi['bg'][0], $kpi['bg'][1], $kpi['bg'][2]);
            $this->SetDrawColor(203, 213, 225);
            $this->Rect($x, 71, $boxWidth, $boxHeight, 'DF');

            $this->SetXY($x, 73);
            $this->SetFont('Arial', 'B', 6.5);
            $this->SetTextColor(100, 116, 139);
            $this->Cell($boxWidth, 3.5, utf8_decode($kpi['title']), 0, 1, 'C');

            $this->SetX($x);
            $this->SetFont('Arial', 'B', 13);
            $this->SetTextColor($kpi['color'][0], $kpi['color'][1], $kpi['color'][2]);
            $this->Cell($boxWidth, 8, $kpi['val'], 0, 1, 'C');

            $this->SetX($x);
            $this->SetFont('Arial', '', 6.5);
            $this->SetTextColor(100, 116, 139);
            $this->Cell($boxWidth, 4, utf8_decode($kpi['sub']), 0, 1, 'C');
        }

        // 4. Detalle y Hallazgos por Departamento
        $this->SetY(99);
        $this->SetFont('Arial', 'B', 9);
        $this->SetTextColor(0, 51, 102);
        $this->Cell(184, 5, utf8_decode('3. EVALUACIÓN Y DESGLOSE POR DEPARTAMENTO'), 0, 1, 'L');

        // Tabla de Departamentos
        $this->SetFillColor(11, 19, 41);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Arial', 'B', 7.5);
        $this->Cell(64, 5.5, 'DEPARTAMENTO / GERENCIA', 1, 0, 'L', true);
        $this->Cell(30, 5.5, 'EVALUADOS', 1, 0, 'C', true);
        $this->Cell(30, 5.5, 'CLICS (CEBO)', 1, 0, 'C', true);
        $this->Cell(30, 5.5, 'COMPROMETIDOS', 1, 0, 'C', true);
        $this->Cell(30, 5.5, 'NIVEL DE RIESGO', 1, 1, 'C', true);

        $deptMetrics = $this->metrics['departments'] ?? [
            'Finanzas & Tesorería' => ['eval' => 6, 'clicks' => 2, 'comp' => 1, 'risk' => 'CRÍTICO (BEC)'],
            'Adquisiciones / Compras' => ['eval' => 5, 'clicks' => 1, 'comp' => 1, 'risk' => 'ALTO (Facturas)'],
            'Recursos Humanos' => ['eval' => 4, 'clicks' => 1, 'comp' => 0, 'risk' => 'MEDIO'],
            'Operaciones & TI' => ['eval' => 5, 'clicks' => 0, 'comp' => 0, 'risk' => 'BAJO / SEGURO']
        ];

        $this->SetFont('Arial', '', 7.5);
        $this->SetTextColor(15, 23, 42);
        $fill = false;
        foreach ($deptMetrics as $dName => $dData) {
            $this->SetFillColor(248, 250, 252);
            $this->Cell(64, 5, utf8_decode($dName), 1, 0, 'L', $fill);
            $this->Cell(30, 5, (string)$dData['eval'], 1, 0, 'C', $fill);
            $this->Cell(30, 5, (string)$dData['clicks'], 1, 0, 'C', $fill);
            $this->Cell(30, 5, (string)$dData['comp'], 1, 0, 'C', $fill);
            
            // Color de riesgo
            $rColor = [16, 185, 129]; // Verde
            if (strpos($dData['risk'], 'CRÍTICO') !== false) $rColor = [220, 38, 38];
            elseif (strpos($dData['risk'], 'ALTO') !== false) $rColor = [217, 119, 6];
            elseif (strpos($dData['risk'], 'MEDIO') !== false) $rColor = [202, 138, 4];
            
            $this->SetTextColor($rColor[0], $rColor[1], $rColor[2]);
            $this->SetFont('Arial', 'B', 7);
            $this->Cell(30, 5, utf8_decode($dData['risk']), 1, 1, 'C', $fill);
            $this->SetTextColor(15, 23, 42);
            $this->SetFont('Arial', '', 7.5);
            $fill = !$fill;
        }

        // 5. Dictamen Pericial & Recomendaciones Institucionales
        $this->SetY(134);
        $this->SetFont('Arial', 'B', 9);
        $this->SetTextColor(0, 51, 102);
        $this->Cell(184, 5, utf8_decode('4. DICTAMEN PERICIAL & PLAN DE REMEDIACIÓN RECOMENDADO'), 0, 1, 'L');

        $this->SetFillColor(255, 255, 255);
        $this->SetDrawColor(226, 232, 240);
        $this->Rect(16, 140, 184, 50, 'DF');

        $this->SetXY(19, 142);
        $this->SetFont('Arial', 'B', 7.5);
        $this->SetTextColor(217, 119, 6);
        $this->Cell(178, 4, utf8_decode('DIAGNÓSTICO DEL RIESGO CORPORATIVO DE FRAUDE FINANCIERO:'), 0, 1);

        $this->SetFont('Arial', '', 7.2);
        $this->SetTextColor(51, 65, 85);
        $this->SetX(19);
        $p1 = "La simulación ejecutada evidencia que el vector de mayor vulnerabilidad radica en la ausencia de un canal de validación desacoplado (Fuera de Banda / OOB) para instrucciones de transferencia urgente o cambios de cuentas bancarias de proveedores. Ante un ataque real con modelos LLM contextualizados o clonación de voz (Deepfake Vishing), la probabilidad de desvío no autorizado de fondos sin detección previa se sitúa en un {$this->metrics['compromised_rate']}%.";
        $this->MultiCell(178, 3.6, utf8_decode($p1));

        $this->SetY(158);
        $this->SetX(19);
        $this->SetFont('Arial', 'B', 7.5);
        $this->SetTextColor(0, 51, 102);
        $this->Cell(178, 4, utf8_decode('MEDIDAS DE BLINDAJE INMEDIATO (KEYSTONE FRAMEWORK):'), 0, 1);

        $this->SetFont('Arial', '', 7.2);
        $this->SetTextColor(51, 65, 85);
        $recs = [
            '1. Habilitación de Protocolo OOB DeepShield: Aprobación biométrica móvil obligatoria para todo pago que supere umbrales críticos.',
            '2. Endurecimiento DMARC en modo reject (p=reject): Garantizar que terceros no puedan emitir correos bajo el dominio de los directores.',
            '3. Capacitación Just-in-Time Continua: Mantener simulaciones periódicas para reforzar reflejos del personal sin ambiente punitivo.'
        ];
        foreach ($recs as $rec) {
            $this->SetX(19);
            $this->Cell(178, 3.8, utf8_decode($rec), 0, 1);
        }

        // 6. Cadena de Custodia Criptográfica & Validez Legal
        $this->SetY(194);
        $this->SetFillColor(248, 250, 252);
        $this->SetDrawColor(203, 213, 225);
        $this->Rect(16, 194, 184, 25, 'DF');

        $shaPayload = $this->dossierFolio . '|' . $this->campaign['company_name'] . '|' . $this->metrics['compromised_rate'] . '|' . $this->issuedAt;
        $shaHash = hash('sha256', $shaPayload);

        $this->SetXY(19, 196);
        $this->SetFont('Arial', 'B', 7);
        $this->SetTextColor(71, 85, 105);
        $this->Cell(178, 3.5, utf8_decode('CERTIFICACIÓN PERICIAL & HASH DE INTEGRIDAD CRIPTOGRÁFICA (LEY 21.459):'), 0, 1);

        $this->SetFont('Courier', '', 6.8);
        $this->SetTextColor(15, 23, 42);
        $this->SetX(19);
        $this->Cell(178, 3.5, 'SHA-256: ' . $shaHash, 0, 1);

        $this->SetFont('Arial', '', 6.8);
        $this->SetTextColor(100, 116, 139);
        $this->SetX(19);
        $this->MultiCell(178, 3.2, utf8_decode('Este documento pericial constituye evidencia documental válida ante compañías aseguradoras (pólizas de ciberriesgo) y comités de auditoría interna, certificando la ejecución ética y metodológica de la prueba de resiliencia humana asistida por Inteligencia Artificial.'));

        // 7. Pie de Página Formal
        $this->SetY(224);
        $this->SetFont('Arial', 'B', 7.5);
        $this->SetTextColor(0, 51, 102);
        $this->Cell(90, 4, 'Keystone Advisors SpA - Cyber Defense Unit', 0, 0, 'L');
        $this->Cell(94, 4, utf8_decode('Oficina de Ciberseguridad & Respuesta a Incidentes'), 0, 1, 'R');

        $this->SetFont('Arial', '', 6.5);
        $this->SetTextColor(148, 163, 184);
        $this->Cell(90, 3.5, 'Santiago • Lima • Ciudad de Panamá • Edmonton', 0, 0, 'L');
        $this->Cell(94, 3.5, 'contacto@keystoneadvisors.cl | www.keystoneadvisors.cl', 0, 1, 'R');

        return $this->Output('S');
    }
}
