<?php
/**
 * KeyGuard / Keystone Advisors - Motor de Generación de Reporte Ejecutivo en PDF (3 Páginas)
 * "Informe Ejecutivo de Riesgo y Ciberseguridad"
 * Con soporte completo UTF-8/Windows-1252, logo corporativo y márgenes perfectos.
 */

require_once __DIR__ . '/../lib/fpdf/fpdf.php';

class CyberReportPdfGenerator extends FPDF {
    private $scanData;
    private $reportFolio;
    private $logoPath;

    public function __construct($scanData) {
        parent::__construct('P', 'mm', 'Letter');
        $this->scanData = $scanData;
        $this->reportFolio = 'KG-' . strtoupper(substr(md5($scanData['domain'] . time()), 0, 8));
        $this->SetMargins(18, 18, 18);
        $this->SetAutoPageBreak(true, 20);

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

    public function getFolio() {
        return $this->reportFolio;
    }

    /**
     * Conversor robusto de texto a codificación soportada por FPDF (ISO-8859-1 / Windows-1252)
     */
    private function c($str) {
        if (!is_string($str)) return '';
        // Si no es UTF-8 válido, devolver directo
        if (!mb_check_encoding($str, 'UTF-8')) {
            return $str;
        }
        return iconv('UTF-8', 'windows-1252//TRANSLIT', $str);
    }

    // Cabecera institucional
    public function Header() {
        if ($this->logoPath && file_exists($this->logoPath)) {
            // Posicionar logo a la izquierda
            $this->Image($this->logoPath, 18, 14, 38);
        } else {
            $this->SetFont('Arial', 'B', 10);
            $this->SetTextColor(15, 42, 74);
            $this->SetXY(18, 14);
            $this->Cell(60, 6, $this->c('KEYSTONE ADVISORS'), 0, 0, 'L');
        }

        $this->SetXY(70, 15);
        $this->SetFont('Arial', 'B', 8);
        $this->SetTextColor(15, 42, 74);
        $this->Cell(60, 4, $this->c('KEYGUARD CYBER DEFENSE SUITE'), 0, 0, 'L');

        $this->SetFont('Arial', '', 7.5);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(0, 4, $this->c('FOLIO: ' . $this->reportFolio . ' | ' . date('d/m/Y H:i')), 0, 1, 'R');

        $this->SetDrawColor(226, 232, 240);
        $this->SetLineWidth(0.3);
        $this->Line(18, 25, 198, 25);
        $this->SetY(28);
    }

    // Pie de página institucional
    public function Footer() {
        $this->SetY(-16);
        $this->SetDrawColor(226, 232, 240);
        $this->SetLineWidth(0.3);
        $this->Line(18, $this->GetY(), 198, $this->GetY());
        $this->Ln(2);

        $this->SetFont('Arial', '', 7.5);
        $this->SetTextColor(148, 163, 184);
        $this->Cell(125, 4, $this->c('Documento confidencial emitido para la Alta Dirección. Prohibida su divulgación sin autorización.'), 0, 0, 'L');
        $this->Cell(0, 4, $this->c('Página ' . $this->PageNo() . ' de {nb}'), 0, 1, 'R');
    }

    public function generateReport() {
        $this->AliasNbPages();

        // ==========================================
        // PÁGINA 1: RESUMEN EJECUTIVO & EXPOSICIÓN FINANCIERA
        // ==========================================
        $this->AddPage();
        
        // Título del reporte (con redacción profesional separada)
        $this->SetFont('Arial', 'B', 16);
        $this->SetTextColor(15, 42, 74);
        $this->Cell(0, 8, $this->c('INFORME EJECUTIVO DE CIBERSEGURIDAD Y RIESGO'), 0, 1, 'L');

        $this->SetFont('Arial', '', 9.5);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(0, 5, $this->c('Diagnóstico Pasivo de Exposición a Fraudes Asistidos por IA & Suplantación de Dominio'), 0, 1, 'L');
        $this->Ln(4);

        // Tarjeta resumen de la entidad (con anchos ajustados y sin desbordes)
        $this->SetFillColor(248, 250, 252);
        $this->SetDrawColor(226, 232, 240);
        $cardY = $this->GetY();
        $this->RoundedRect(18, $cardY, 180, 24, 3, 'DF');

        // Fila 1: Dominio y Fecha
        $this->SetXY(22, $cardY + 3.5);
        $this->SetFont('Arial', 'B', 8);
        $this->SetTextColor(71, 85, 105);
        $this->Cell(38, 5, $this->c('DOMINIO EVALUADO:'), 0, 0);
        
        $this->SetFont('Arial', 'B', 10);
        $this->SetTextColor(15, 42, 74);
        $this->Cell(54, 5, $this->c($this->scanData['domain']), 0, 0);

        $this->SetFont('Arial', 'B', 8);
        $this->SetTextColor(71, 85, 105);
        $this->Cell(36, 5, $this->c('FECHA DEL ANÁLISIS:'), 0, 0);

        $this->SetFont('Arial', '', 8.5);
        $this->SetTextColor(15, 42, 74);
        $this->Cell(0, 5, $this->c(date('d/m/Y H:i:s')), 0, 1);

        // Fila 2: Receptor y Tipo de Escaneo (dimensionado con margen holgado)
        $this->SetXY(22, $cardY + 11.5);
        $this->SetFont('Arial', 'B', 8);
        $this->SetTextColor(71, 85, 105);
        $this->Cell(38, 5, $this->c('RECEPTOR DESIGNADO:'), 0, 0);

        $this->SetFont('Arial', '', 8.5);
        $this->SetTextColor(15, 42, 74);
        $this->Cell(54, 5, $this->c($this->scanData['email']), 0, 0);

        $this->SetFont('Arial', 'B', 8);
        $this->SetTextColor(71, 85, 105);
        $this->Cell(36, 5, $this->c('TIPO DE ESCANEO:'), 0, 0);

        $this->SetFont('Arial', 'B', 8);
        $this->SetTextColor(5, 150, 105); // Verde esmeralda
        $this->Cell(0, 5, $this->c('100% Pasivo (Registros Públicos)'), 0, 1);

        $this->SetY($cardY + 28);

        // Termómetro de Score
        $score = intval($this->scanData['score']);
        $riskLevel = $this->scanData['risk_level'];
        
        $boxColor = [239, 68, 68]; // Rojo
        if ($score >= 75) {
            $boxColor = [16, 185, 129]; // Verde
        } elseif ($score >= 50) {
            $boxColor = [245, 158, 11]; // Ámbar
        }

        $this->SetFillColor($boxColor[0], $boxColor[1], $boxColor[2]);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Arial', 'B', 11);
        $this->RoundedRect(18, $this->GetY(), 180, 22, 3, 'F');
        
        $this->SetXY(23, $this->GetY() + 3.5);
        $this->Cell(110, 6, $this->c('PUNTUACIÓN DE EXPOSICIÓN CORPORATIVA:'), 0, 0);
        $this->SetFont('Arial', 'B', 19);
        $this->Cell(0, 6, $score . ' / 100', 0, 1, 'R');

        $this->SetXY(23, $this->GetY() + 1.5);
        $this->SetFont('Arial', '', 9);
        $this->Cell(0, 5, $this->c('NIVEL DE RIESGO OPERACIONAL: ' . strtoupper($riskLevel)), 0, 1);

        $this->Ln(10);

        // Sección de Impacto Financiero y Riesgo del Directorio
        $this->SetFont('Arial', 'B', 11.5);
        $this->SetTextColor(15, 42, 74);
        $this->Cell(0, 6, $this->c('1. IMPACTO FINANCIERO & VECTORES DE RIESGO PARA EL DIRECTORIO'), 0, 1);
        
        $this->SetDrawColor(0, 180, 216);
        $this->SetLineWidth(0.6);
        $this->Line(18, $this->GetY() + 1, 60, $this->GetY() + 1);
        $this->Ln(4);

        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(51, 65, 85);
        $p1 = "El presente diagnóstico evalúa la susceptibilidad de su organización frente a ataques asistidos por Inteligencia Artificial y la fortaleza del perímetro de identidad de su dominio. Con el advenimiento de modelos LLM capaces de redactar correos contextualizados y motores de clonación de voz, los atacantes concentran sus esfuerzos en interceptar pagos y alterar cuentas corrientes bancarias de proveedores.";
        $this->MultiCell(0, 4.8, $this->c($p1));
        $this->Ln(3);

        // Tabla de Riesgos Clave
        $this->SetFont('Arial', 'B', 8.5);
        $this->SetFillColor(241, 245, 249);
        $this->SetTextColor(30, 41, 59);
        $this->Cell(55, 6.5, $this->c('Vector de Amenaza'), 1, 0, 'L', true);
        $this->Cell(35, 6.5, $this->c('Probabilidad'), 1, 0, 'C', true);
        $this->Cell(0, 6.5, $this->c('Impacto Económico / Operativo Estimado'), 1, 1, 'L', true);

        $this->SetFont('Arial', '', 8);
        $this->Cell(55, 9, $this->c('Suplantación BEC (Cambio de cuenta)'), 1, 0, 'L');
        $this->SetTextColor($score < 60 ? 220 : 71, $score < 60 ? 38 : 85, $score < 60 ? 38 : 105);
        $this->SetFont('Arial', 'B', 8);
        $this->Cell(35, 9, $score < 60 ? $this->c('ALTA (Crítica)') : $this->c('MEDIA'), 1, 0, 'C');
        $this->SetTextColor(51, 65, 85);
        $this->SetFont('Arial', '', 8);
        $this->Cell(0, 9, $this->c('Desvío de fondos en pagos masivos / Pérdida directa de flujo'), 1, 1, 'L');

        $this->Cell(55, 9, $this->c('Vishing & Voz Clonada a Finanzas'), 1, 0, 'L');
        $this->SetTextColor(217, 119, 6);
        $this->SetFont('Arial', 'B', 8);
        $this->Cell(35, 9, $this->c('ALTA'), 1, 0, 'C');
        $this->SetTextColor(51, 65, 85);
        $this->SetFont('Arial', '', 8);
        $this->Cell(0, 9, $this->c('Autorización indebida de transferencias sin validación OOB'), 1, 1, 'L');

        $this->Cell(55, 9, $this->c('Sanciones Ley Marco Ciberseguridad'), 1, 0, 'L');
        $this->SetTextColor(51, 65, 85);
        $this->SetFont('Arial', '', 8);
        $this->Cell(35, 9, $this->c('REGULATORIA'), 1, 0, 'C');
        $this->SetFont('Arial', '', 8);
        $this->Cell(0, 9, $this->c('Responsabilidad de directores por falta de diligencia técnica'), 1, 1, 'L');

        $this->Ln(5);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(0, 5, $this->c('* Continúe a la página 2 para la inspección técnica detallada de los hallazgos superficiales.'), 0, 1, 'R');


        // ==========================================
        // PÁGINA 2: RADIOGRAFÍA TÉCNICA DE HALLAZGOS
        // ==========================================
        $this->AddPage();

        $this->SetFont('Arial', 'B', 13);
        $this->SetTextColor(15, 42, 74);
        $this->Cell(0, 7, $this->c('2. RADIOGRAFÍA TÉCNICA & SUPERFICIE DE EXPOSICIÓN'), 0, 1);
        
        $this->SetDrawColor(0, 180, 216);
        $this->SetLineWidth(0.6);
        $this->Line(18, $this->GetY() + 1, 60, $this->GetY() + 1);
        $this->Ln(5);

        // Detalle de comprobaciones con acentos y caracteres 100% limpios
        $findings = $this->scanData['findings'] ?? [];
        if (empty($findings)) {
            $this->SetFont('Arial', '', 9.5);
            $this->SetTextColor(5, 150, 105);
            $this->Cell(0, 8, $this->c('✓ No se detectaron brechas críticas en los registros públicos analizados.'), 0, 1);
        } else {
            foreach ($findings as $idx => $f) {
                $sev = $f['severity'] ?? 'medium';
                $borderCol = [203, 213, 225];
                $tagCol = [100, 116, 139];
                $tagText = 'INSPECCIÓN';

                if ($sev === 'critical') {
                    $borderCol = [239, 68, 68];
                    $tagCol = [220, 38, 38];
                    $tagText = 'CRÍTICO';
                } elseif ($sev === 'high' || $sev === 'warning') {
                    $borderCol = [245, 158, 11];
                    $tagCol = [217, 119, 6];
                    $tagText = 'ADVERTENCIA';
                }

                $this->SetDrawColor($borderCol[0], $borderCol[1], $borderCol[2]);
                $this->SetFillColor(248, 250, 252);
                $this->SetLineWidth(0.4);
                
                $startY = $this->GetY();
                $this->RoundedRect(18, $startY, 180, 22, 2, 'DF');

                $this->SetXY(22, $startY + 3);
                $this->SetFont('Arial', 'B', 8);
                $this->SetTextColor($tagCol[0], $tagCol[1], $tagCol[2]);
                $this->Cell(28, 4, '[' . $this->c($tagText) . ']', 0, 0);

                $this->SetFont('Arial', 'B', 9);
                $this->SetTextColor(15, 42, 74);
                $this->Cell(0, 4, $this->c($f['title']), 0, 1);

                $this->SetXY(22, $startY + 8.5);
                $this->SetFont('Arial', '', 8);
                $this->SetTextColor(71, 85, 105);
                $this->MultiCell(172, 4.1, $this->c($f['desc']));

                $this->SetY($startY + 25);
            }
        }

        $this->Ln(2);

        // Resumen de parámetros analizados
        $this->SetFont('Arial', 'B', 9.5);
        $this->SetTextColor(15, 42, 74);
        $this->Cell(0, 6, $this->c('Matriz de Registros de Identidad Digital:'), 0, 1);
        $this->Ln(1.5);

        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(30, 41, 59);

        // Fila 1: DMARC
        $this->Cell(50, 6, $this->c('Registro DMARC:'), 1, 0, 'L');
        $this->SetFont('Arial', 'B', 8);
        $dmarcPolicy = strtoupper($this->scanData['dmarc_policy'] ?? 'NONE');
        $this->Cell(45, 6, $dmarcPolicy, 1, 0, 'C');
        $this->SetFont('Arial', '', 8);
        $this->Cell(0, 6, $dmarcPolicy === 'REJECT' ? $this->c('Blindado contra falsificación') : $this->c('Vulnerable a suplantación activa'), 1, 1, 'L');

        // Fila 2: SPF
        $this->SetFont('Arial', '', 8);
        $this->Cell(50, 6, $this->c('Mecanismo SPF:'), 1, 0, 'L');
        $this->SetFont('Arial', 'B', 8);
        $hasSpf = !empty($this->scanData['has_spf']);
        $this->Cell(45, 6, $hasSpf ? 'DETECTADO' : 'NO DETECTADO', 1, 0, 'C');
        $this->SetFont('Arial', '', 8);
        $this->Cell(0, 6, $hasSpf ? $this->c('Servidores autorizados declarados') : $this->c('Sin delimitación de servidores de salida'), 1, 1, 'L');

        // Fila 3: Certificado SSL
        $this->SetFont('Arial', '', 8);
        $this->Cell(50, 6, $this->c('Cifrado Web SSL/TLS:'), 1, 0, 'L');
        $this->SetFont('Arial', 'B', 8);
        $hasSsl = !empty($this->scanData['has_ssl']);
        $this->Cell(45, 6, $hasSsl ? $this->c('VÁLIDO') : $this->c('FALLO / INVÁLIDO'), 1, 0, 'C');
        $this->SetFont('Arial', '', 8);
        $this->Cell(0, 6, $hasSsl ? $this->c('Tráfico seguro HTTPS') : $this->c('Comunicaciones vulnerables a interceptación'), 1, 1, 'L');

        // Fila 4: Cabecera HSTS
        $this->SetFont('Arial', '', 8);
        $this->Cell(50, 6, $this->c('Endurecimiento HSTS:'), 1, 0, 'L');
        $this->SetFont('Arial', 'B', 8);
        $hasHsts = !empty($this->scanData['has_hsts']);
        $this->Cell(45, 6, $hasHsts ? 'ACTIVO' : 'NO CONFIGURADO', 1, 0, 'C');
        $this->SetFont('Arial', '', 8);
        $this->Cell(0, 6, $hasHsts ? $this->c('Prevención de degradación MitM') : $this->c('Permite conexiones HTTP no seguras'), 1, 1, 'L');


        // ==========================================
        // PÁGINA 3: PLAN DE REMEDIACIÓN & PLAN DE ACCIÓN
        // ==========================================
        $this->AddPage();

        $this->SetFont('Arial', 'B', 13);
        $this->SetTextColor(15, 42, 74);
        $this->Cell(0, 7, $this->c('3. PLAN DE REMEDIACIÓN INMEDIATA & SIGUIENTES PASOS'), 0, 1);
        
        $this->SetDrawColor(0, 180, 216);
        $this->SetLineWidth(0.6);
        $this->Line(18, $this->GetY() + 1, 60, $this->GetY() + 1);
        $this->Ln(5);

        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(51, 65, 85);
        $p3 = "Para neutralizar los vectores de riesgo identificados antes de que deriven en incidentes financieros, Keystone Advisors recomienda ejecutar el siguiente plan de remediación acelerada de 3 etapas:";
        $this->MultiCell(0, 4.8, $this->c($p3));
        $this->Ln(4);

        // Paso 1
        $this->SetFillColor(241, 245, 249);
        $this->SetDrawColor(0, 180, 216);
        $this->RoundedRect(18, $this->GetY(), 180, 23, 2.5, 'DF');
        $y1 = $this->GetY() + 2.5;
        $this->SetXY(23, $y1);
        $this->SetFont('Arial', 'B', 9);
        $this->SetTextColor(0, 51, 102);
        $this->Cell(0, 5, $this->c('FASE 1 (DÍAS 1 A 7): BLINDAJE DE IDENTIDAD & DMARC STRICT (p=reject)'), 0, 1);
        $this->SetXY(23, $y1 + 5.5);
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(71, 85, 105);
        $this->MultiCell(170, 4.1, $this->c('Mapeo de remitentes corporativos y cierre definitivo de la política DMARC para garantizar que ningún servidor no autorizado en el mundo pueda emitir correos con su dominio.'));
        $this->SetY($y1 + 23);

        // Paso 2
        $this->SetFillColor(241, 245, 249);
        $this->SetDrawColor(0, 180, 216);
        $this->RoundedRect(18, $this->GetY(), 180, 23, 2.5, 'DF');
        $y2 = $this->GetY() + 2.5;
        $this->SetXY(23, $y2);
        $this->SetFont('Arial', 'B', 9);
        $this->SetTextColor(0, 51, 102);
        $this->Cell(0, 5, $this->c('FASE 2 (DÍAS 8 A 15): BLINDAJE DE TESORERÍA FUERA DE BANDA (OOB)'), 0, 1);
        $this->SetXY(23, $y2 + 5.5);
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(71, 85, 105);
        $this->MultiCell(170, 4.1, $this->c('Despliegue de protocolos criptográficos y validación biométrica en smartphones para pagos críticos y cambios de cuentas de proveedores, neutralizando fraudes de voz clonada o correos falsos.'));
        $this->SetY($y2 + 23);

        // Paso 3
        $this->SetFillColor(241, 245, 249);
        $this->SetDrawColor(0, 180, 216);
        $this->RoundedRect(18, $this->GetY(), 180, 23, 2.5, 'DF');
        $y3 = $this->GetY() + 2.5;
        $this->SetXY(23, $y3);
        $this->SetFont('Arial', 'B', 9);
        $this->SetTextColor(0, 51, 102);
        $this->Cell(0, 5, $this->c('FASE 3 (CONTINUO): ENTRENAMIENTO DE RESILIENCIA CON IA & vCISO'), 0, 1);
        $this->SetXY(23, $y3 + 5.5);
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(71, 85, 105);
        $this->MultiCell(170, 4.1, $this->c('Simulaciones programadas de phishing y vishing contextual con retroalimentación en pantalla de 45 segundos, junto a monitoreo mensual de cumplimiento normativo y Ley Marco.'));
        $this->SetY($y3 + 26);

        // Banner de Llamado a la Acción (CTA)
        $this->SetFillColor(15, 42, 74);
        $this->RoundedRect(18, $this->GetY(), 180, 30, 3, 'F');
        $ctaY = $this->GetY() + 4.5;

        $this->SetXY(23, $ctaY);
        $this->SetFont('Arial', 'B', 10.5);
        $this->SetTextColor(255, 255, 255);
        $this->Cell(0, 5, $this->c('¿DESEA AGENDAR UNA SESIÓN EJECUTIVA DE REMEDIACIÓN (20 MIN)?'), 0, 1);

        $this->SetXY(23, $ctaY + 6.5);
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(203, 213, 225);
        $this->Cell(0, 5, $this->c('Un especialista senior de Keystone Advisors revisará este informe con su equipo de TI y Finanzas.'), 0, 1);

        $this->SetXY(23, $ctaY + 13.5);
        $this->SetFont('Arial', 'B', 8.5);
        $this->SetTextColor(0, 180, 216);
        $this->Cell(0, 5, $this->c('Contacto Directo: contacto@keystoneadvisors.cl | www.keystoneadvisors.cl'), 0, 1);
    }

    // Utilidad para rectángulos con bordes redondeados en FPDF
    private function RoundedRect($x, $y, $w, $h, $r, $style = '') {
        $k = $this->k;
        $hp = $this->h;
        if($style=='F')
            $op='f';
        elseif($style=='FD' || $style=='DF')
            $op='B';
        else
            $op='S';
        $MyArc = 4/3 * (sqrt(2) - 1);
        $this->_out(sprintf('%.2F %.2F m',($x+$r)*$k,($hp-$y)*$k ));
        $xc = $x+$w-$r ;
        $yc = $y+$r;
        $this->_out(sprintf('%.2F %.2F l', $xc*$k,($hp-$y)*$k ));

        $this->_Arc($xc + $r*$MyArc, $yc - $r, $xc + $r, $yc - $r*$MyArc, $xc + $r, $yc);
        $xc = $x+$w-$r ;
        $yc = $y+$h-$r;
        $this->_out(sprintf('%.2F %.2F l',($x+$w)*$k,($hp-$yc)*$k));
        $this->_Arc($xc + $r, $yc + $r*$MyArc, $xc + $r*$MyArc, $yc + $r, $xc, $yc + $r);
        $xc = $x+$r ;
        $yc = $y+$h-$r;
        $this->_out(sprintf('%.2F %.2F l',($xc)*$k,($hp-($y+$h))*$k));
        $this->_Arc($xc - $r*$MyArc, $yc + $r, $xc - $r, $yc + $r*$MyArc, $xc - $r, $yc);
        $xc = $x+$r ;
        $yc = $y+$r;
        $this->_out(sprintf('%.2F %.2F l',($x)*$k,($hp-$yc)*$k ));
        $this->_Arc($xc - $r, $yc - $r*$MyArc, $xc - $r*$MyArc, $yc - $r, $xc, $yc - $r);
        $this->_out($op);
    }

    private function _Arc($x1, $y1, $x2, $y2, $x3, $y3) {
        $h = $this->h;
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c ', $x1*$this->k, ($h-$y1)*$this->k,
            $x2*$this->k, ($h-$y2)*$this->k, $x3*$this->k, ($h-$y3)*$this->k));
    }
}
