<?php
/**
 * Plantillas HTML Oficiales del SII Clonadas para Red Teaming
 * Réplica exacta de los 4 formatos institucionales del Servicio de Impuestos Internos:
 * 1. Carpeta Tributaria Electrónica (Compartida / Regular)
 * 2. Notificación de Resolución Exenta (RAV / Ministro de Fe)
 * 3. Solicitud de Certificados Internacionales (Aceptada / Firma Avanzada)
 * 4. Notificación DJ 1955 / Operación Renta (Inconsistencias Bancarias > 1.500 UF)
 */

class SiiLureTemplates {

    public static function getTemplate(string $type, array $params): array {
        $name = htmlspecialchars($params['name'] ?? 'Contribuyente');
        $company = htmlspecialchars($params['company'] ?? 'KEYSTONE ADVISORS SPA');
        $rut = htmlspecialchars($params['rut'] ?? '78089686-8');
        $link = htmlspecialchars($params['link'] ?? '#');
        $dateStr = date('d \d\e F \d\e Y');

        switch ($type) {
            case 'CARPETA_TRIBUTARIA':
                $subject = "CARPETA TRIBUTARIA ELECTRONICA SR.(A) " . strtoupper($company);
                $from = "Informativo SII <InformativoSII@i.sii.cl>";
                $bodyHtml = '
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family:Arial,Helvetica,sans-serif; background:#f4f6f8; margin:0; padding:20px; color:#222;">
  <table width="640" align="center" style="background:#fff; border:1px solid #d1d5db; padding:25px; border-radius:4px;">
    <tr>
      <td>
        <table width="100%">
          <tr>
            <td width="140" valign="middle">
              <span style="font-size:28px; font-weight:900; color:#0055a5; letter-spacing:-1px;">Sii</span>
              <span style="display:inline-block; font-size:11px; line-height:1.1; color:#555; vertical-align:middle; margin-left:6px; font-weight:bold;">Servicio de<br>Impuestos<br>Internos</span>
            </td>
            <td align="right" style="font-size:12px; color:#666;">
              Se ha compartido un acceso a:<br>
              <strong style="font-size:14px; color:#475569; letter-spacing:0.5px;">CARPETA TRIBUTARIA REGULAR</strong>
            </td>
          </tr>
        </table>
        <hr style="border:none; border-top:1px solid #e5e7eb; margin:18px 0;">

        <div style="text-align:right; font-size:12px; color:#555; margin-bottom:15px;">' . date('F \d\e Y') . '</div>

        <p style="font-size:13px; margin:0 0 5px 0;"><strong>Estimado(a) Contribuyente:</strong></p>
        <p style="font-size:14px; font-weight:bold; color:#111; margin:0 0 15px 0;">' . strtoupper($company) . '</p>

        <p style="font-size:12.5px; line-height:1.55; color:#333;">
          Mediante el presente, informamos que se ha compartido un acceso a su Carpeta Tributaria Regular a <strong>' . strtoupper($name) . '</strong> de la institución <strong>DIRECCIÓN DE FISCALIZACIÓN TRIBUTARIA</strong>.
        </p>

        <p style="font-size:12.5px; line-height:1.55; color:#333;">
          Para que el destinatario pueda validar la autenticidad de la información y acceder a su historial de declaraciones F-29 y Renta, deberá ingresar a la opción <strong>Revisar Carpeta Tributaria Regular</strong> disponible en el menú de Servicios online del sitio web del SII.
        </p>

        <table width="100%" style="background:#f8fafc; border:1px solid #cbd5e1; border-radius:6px; padding:15px; margin:20px 0;">
          <tr>
            <td align="center">
              <div style="font-size:13px; font-weight:bold; color:#0055a5; margin-bottom:10px;">Acceso Directo al Expediente Tributario Electrónico</div>
              <a href="' . $link . '" style="background:#0055a5; color:#ffffff; text-decoration:none; padding:10px 22px; font-size:13px; font-weight:bold; border-radius:4px; display:inline-block;">Revisar Carpeta Tributaria en Línea ➜</a>
              <div style="font-size:10.5px; color:#64748b; margin-top:8px;">Código de Verificación Seguro: SII-CT-' . strtoupper(substr(md5($name), 0, 8)) . '</div>
            </td>
          </tr>
        </table>

        <p style="font-size:11.5px; color:#555; line-height:1.5;">
          La información contenida en esta Carpeta Tributaria Electrónica corresponde a la situación tributaria a la fecha y hora exacta de consulta.
        </p>

        <p style="font-size:12px; color:#333; margin-top:25px;">
          Saluda atentamente,<br>
          <strong>Servicio de Impuestos Internos.</strong>
        </p>

        <p style="font-size:11px; font-style:italic; color:#0055a5; text-align:center; margin:25px 0 15px 0;">
          Nuestro compromiso, facilitar su aporte al desarrollo del país.
        </p>

        <table width="100%" style="border-top:1px solid #e5e7eb; padding-top:15px; font-size:10px; color:#6b7280; line-height:1.4;">
          <tr>
            <td width="30%"><strong>SEGURIDAD PARTE POR TI</strong><br>NUNCA TE SOLICITAREMOS CLAVES NI DATOS PERSONALES.</td>
            <td width="70%" style="padding-left:15px;">
              El SII nunca envía links que soliciten su RUT o Clave Secreta.<br>
              Los correos se envían solo a direcciones registradas en nuestra base de datos.<br>
              Este mensaje es informativo, por favor no responder.
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>';
                break;

            case 'RESOLUCION_EXENTA':
                $rolRav = rand(100, 299) . '.' . rand(100, 999);
                $resNum = rand(200, 399) . '.' . rand(100, 999);
                $subject = "NOTIFICA RESOLUCIÓN EX. N° {$resNum} - [SII_Nro" . rand(20000000, 29999999) . "]";
                $from = "webadm <webadm@sii.cl>";
                $bodyHtml = '
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family:Arial,Helvetica,sans-serif; background:#f4f6f8; margin:0; padding:20px; color:#111;">
  <table width="640" align="center" style="background:#fff; border:1px solid #d1d5db; padding:30px; border-radius:4px;">
    <tr>
      <td>
        <h2 style="font-size:16px; margin:0 0 10px 0; color:#0f172a; border-bottom:2px solid #0f172a; padding-bottom:8px;">
          NOTIFICA RESOLUCIÓN EX. N° ' . $resNum . '
        </h2>
        <div style="font-size:12px; color:#475569; margin-bottom:20px;">
          <strong>Notificación SII</strong><br>
          Asunto: Se notifica la Resolución Exenta N° ' . $resNum . ', de fecha ' . date('d \d\e F \d\e Y') . ', dictada en la causa RAV rol N° ' . $rolRav . '
        </div>

        <p style="font-size:13px; margin:0 0 5px 0;">Estimado(a),</p>
        <p style="font-size:14px; font-weight:bold; margin:0 0 15px 0;">' . strtoupper($company) . '</p>

        <p style="font-size:12.5px; line-height:1.6; text-align:justify;">
          En Santiago, siendo las ' . date('H:i') . ' horas del día ' . date('d \d\e F \d\e Y') . ', por este medio, procedo a notificar a Ud., de conformidad con lo dispuesto en el inciso primero del artículo 11 del Código Tributario, la <strong>Resolución Exenta N° ' . $resNum . '</strong>, dictada en la causa rol RAV N° ' . $rolRav . '.
        </p>

        <p style="font-size:12.5px; line-height:1.6;">
          El documento referido y el expediente con las liquidaciones se encuentra disponible para su descarga en la oficina virtual:
        </p>

        <div style="text-align:center; margin:22px 0;">
          <a href="' . $link . '" style="background:#0f172a; color:#ffffff; text-decoration:none; padding:11px 24px; font-size:12.5px; font-weight:bold; border-radius:4px; display:inline-block;">
            Descargar Notificación & Archivo Adjunto (PDF) ➜
          </a>
        </div>

        <div style="margin-top:30px; font-size:11.5px; color:#333; line-height:1.4;">
          Firmado Electrónicamente por:<br>
          <strong>RAÚL EDUARDO VENEGAS SÁNCHEZ</strong><br>
          MINISTRO DE FE<br>
          GRUPO N°1 DEPAT<br>
          DEPARTAMENTO DE PROCEDIMIENTOS ADMINISTRATIVOS TRIBUTARIOS<br>
          XV DIRECCIÓN REGIONAL METROPOLITANA SANTIAGO ORIENTE<br>
          SERVICIO DE IMPUESTOS INTERNOS
        </div>
      </td>
    </tr>
  </table>
</body>
</html>';
                break;

            case 'F1955_RENTA':
            default:
                $subject = "Revise si corresponde tributar lo Informado en F1955 - [SII_Nro" . rand(20000000, 29999999) . "]";
                $from = "Informativo SII <informativosii@e.sii.cl>";
                $bodyHtml = '
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family:Arial,Helvetica,sans-serif; background:#f4f6f8; margin:0; padding:20px; color:#222;">
  <table width="640" align="center" style="background:#fff; border:1px solid #d1d5db; padding:25px; border-radius:4px;">
    <tr>
      <td>
        <table width="100%">
          <tr>
            <td width="140" valign="middle">
              <span style="font-size:28px; font-weight:900; color:#0055a5; letter-spacing:-1px;">Sii</span>
              <span style="display:inline-block; font-size:11px; line-height:1.1; color:#555; vertical-align:middle; margin-left:6px; font-weight:bold;">Servicio de<br>Impuestos<br>Internos</span>
            </td>
            <td align="right" style="font-size:12px; color:#555;">
              ' . date('F \d\e Y') . '
            </td>
          </tr>
        </table>

        <div style="margin-top:20px;">
          <p style="font-size:13px; margin:0 0 4px 0;"><strong>Sr(a). contribuyente:</strong></p>
          <p style="font-size:14px; font-weight:bold; color:#111; margin:0 0 15px 0;">' . strtoupper($company) . ' &bull; RUT: ' . $rut . '</p>

          <p style="font-size:12.5px; line-height:1.6; text-align:justify; color:#333;">
            Junto con saludar, le recordamos que, para evitar cualquier tipo de problema u observación en su declaración, como Servicio contamos con la información que los bancos e instituciones financieras están obligados a entregarnos a través de la <strong>Declaración Jurada 1955</strong> sobre los saldos y sumas de abonos de cuentas bancarias que superan las 1.500 UF al mes.
          </p>

          <p style="font-size:12.5px; line-height:1.6; text-align:justify; color:#333;">
            Se han detectado diferencias entre los abonos bancarios informados y los ingresos declarados en sus declaraciones mensuales recientes. Para evitar procesos de fiscalización y bloqueo de facturación, le solicitamos contrastar los antecedentes reportados por su entidad bancaria:
          </p>

          <div style="background:#f8fafc; border:1px solid #cbd5e1; border-radius:6px; padding:15px; text-align:center; margin:20px 0;">
            <div style="font-size:12.5px; font-weight:bold; color:#0f172a; margin-bottom:10px;">Consulta de Inconsistencias DJ 1955 & Saldos Financieros</div>
            <a href="' . $link . '" style="background:#0055a5; color:#ffffff; text-decoration:none; padding:10px 22px; font-size:13px; font-weight:bold; border-radius:4px; display:inline-block;">
              Acceder a Detalle de Declaración Jurada 1955 ➜
            </a>
          </div>

          <p style="font-size:12px; color:#333;">
            Atentamente,<br>
            <strong>Servicio de Impuestos Internos</strong><br>
            <span style="font-size:11px; font-style:italic; color:#0055a5;">Nuestro compromiso es facilitar su aporte al desarrollo del país</span>
          </p>

          <table width="100%" style="border-top:1px solid #e5e7eb; margin-top:20px; padding-top:12px; font-size:10px; color:#6b7280; line-height:1.4;">
            <tr>
              <td width="35%"><strong>EMAIL SEGURO SII</strong><br>No le solicitaremos claves ni datos personales.</td>
              <td width="65%" style="padding-left:15px;">
                Para su mayor seguridad, el Servicio de Impuestos Internos no envía por correo electrónico ningún tipo de link que requiera el ingreso de su Clave Secreta.<br>
                Por ser de carácter informativo, le solicitamos no responder el presente e-mail.
              </td>
            </tr>
          </table>
        </div>
      </td>
    </tr>
  </table>
</body>
</html>';
                break;
        }

        return [
            'subject' => $subject,
            'from' => $from,
            'html' => $bodyHtml
        ];
    }
}
