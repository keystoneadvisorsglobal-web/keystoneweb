<?php
/**
 * BankLureTemplates.php
 * Plantillas clonadas de alta fidelidad para Simulación de Red Teaming / BEC / Phishing Bancario:
 * 1. Banco Santander OfficeBanking - Aviso de Transferencia de Fondos
 * 2. LarrainVial Corredora de Bolsa - Solicitud y Abono de Transferencia
 * 3. Banco Santander - Cartola Mensual de Fondos Mutuos (Protegida con Contraseña)
 */

class BankLureTemplates {

    public static function getList(): array {
        return [
            'SANTANDER_AVISO_TRANSFERENCIA' => [
                'name' => '🏦 Banco Santander: Aviso de Transferencia de Fondos (OfficeBanking)',
                'category' => 'Bancario / Tesorería',
                'difficulty' => 'Extrema (Réplica Oficial)',
                'default_subject' => 'Aviso de Transferencia de Fondos - Banco Santander',
                'sender' => 'Banco Santander <mensajeria@santander.cl>',
                'description' => 'Notificación de transferencia saliente de alto monto desde la cuenta corriente corporativa. Genera alarma inmediata en finanzas para verificar la transacción.'
            ],
            'LARRAINVIAL_TRANSFERENCIA' => [
                'name' => '📈 LarrainVial: Solicitud de Transferencia y Abono de Fondos',
                'category' => 'Inversiones / Tesorería',
                'difficulty' => 'Alta (Alerta de Depósito)',
                'default_subject' => 'Solicitud de transferencia desde LarrainVial',
                'sender' => 'LarrainVial <larrainvialmail@larrainvial.com>',
                'description' => 'Aviso de abono de liquidación de fondos mutuos o acciones a la cuenta bancaria de la empresa.'
            ],
            'SANTANDER_CARTOLA_FONDOS' => [
                'name' => '📄 Banco Santander: Cartola Mensual Fondos Mutuos (Cifrada)',
                'category' => 'Bancario / Inversiones',
                'difficulty' => 'Extrema (Ingeniería Social de Cifrado)',
                'default_subject' => 'Cartola Mensual de Fondos Mutuos - Banco Santander',
                'sender' => 'Santander <mensajeria@santander.cl>',
                'description' => 'Envío de cartola mensual con contraseña (RUT sin guión). El señuelo induce a descargar o abrir la cartola corporativa.'
            ]
        ];
    }

    public static function render(string $templateKey, array $data): array {
        $name = htmlspecialchars($data['target_name'] ?? 'Colaborador', ENT_QUOTES, 'UTF-8');
        $company = htmlspecialchars($data['company_name'] ?? 'KEYSTONE ADVISORS SPA', ENT_QUOTES, 'UTF-8');
        $rut = htmlspecialchars($data['rut'] ?? '78.089.686-8', ENT_QUOTES, 'UTF-8');
        $phishUrl = htmlspecialchars($data['phish_url'] ?? '#', ENT_QUOTES, 'UTF-8');
        $dateNow = date('d-m-Y');
        $timeNow = date('H:i');

        switch ($templateKey) {
            case 'SANTANDER_AVISO_TRANSFERENCIA':
                $subject = "Aviso de Transferencia de Fondos";
                $html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>
  body { font-family: Arial, Helvetica, sans-serif; background-color: #f7f7f7; margin:0; padding:20px 0; color: #222222; }
  .container { max-width: 650px; margin: 0 auto; background: #ffffff; border: 1px solid #e0e0e0; border-radius: 4px; overflow: hidden; }
  .header-santander { background-color: #ec0000; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; }
  .header-logo { color: #ffffff; font-weight: bold; font-size: 22px; letter-spacing: -0.5px; }
  .header-tag { color: #ffffff; font-size: 13px; font-weight: normal; background: rgba(0,0,0,0.15); padding: 4px 10px; border-radius: 3px; }
  .title-section { padding: 24px 28px 10px 28px; }
  .title-section h1 { color: #999999; font-size: 22px; font-weight: normal; margin: 0 0 16px 0; }
  .alert-info-box { display: flex; align-items: center; background: #f0f7fd; border-left: 4px solid #007bc4; padding: 12px 16px; margin-bottom: 20px; font-size: 14px; color: #333333; }
  .alert-info-icon { font-size: 20px; margin-right: 12px; color: #007bc4; font-weight: bold; }
  .greeting { font-size: 15px; font-weight: bold; color: #222222; margin-bottom: 8px; }
  .intro-text { font-size: 13px; color: #555555; margin-bottom: 20px; line-height: 1.4; }
  .section-label { font-size: 16px; font-weight: bold; color: #222222; border-bottom: 1px solid #e5e5e5; padding-bottom: 6px; margin: 20px 28px 12px 28px; }
  .data-table { width: calc(100% - 56px); margin: 0 28px 20px 28px; border-collapse: collapse; font-size: 12.5px; }
  .data-table td { padding: 8px 10px; border: 1px solid #eeeeee; vertical-align: top; }
  .data-table .label { color: #777777; font-size: 11px; text-transform: uppercase; margin-bottom: 3px; }
  .data-table .value { color: #222222; font-weight: 500; font-size: 13px; }
  .amount-card { background: #fafafa; border: 1px dashed #d0d0d0; margin: 15px 28px 25px 28px; padding: 16px 20px; display: flex; justify-content: space-between; align-items: center; border-radius: 4px; }
  .amount-label { font-size: 13px; color: #555555; }
  .amount-val { font-size: 24px; font-weight: bold; color: #ec0000; }
  .btn-container { text-align: center; margin: 25px 28px; }
  .btn-santander { display: inline-block; background-color: #ec0000; color: #ffffff !important; text-decoration: none; font-size: 14px; font-weight: bold; padding: 12px 30px; border-radius: 24px; box-shadow: 0 2px 4px rgba(236,0,0,0.25); }
  .footer-santander { background-color: #f2f2f2; padding: 20px 28px; font-size: 11px; color: #666666; line-height: 1.5; border-top: 1px solid #e0e0e0; }
  .footer-santander a { color: #ec0000; text-decoration: none; }
</style>
</head>
<body>
<div class="container">
  <div class="header-santander">
    <div class="header-logo">Santander</div>
    <div class="header-tag">office<b>banking</b></div>
  </div>
  
  <div class="title-section">
    <h1>Aviso de Transferencia de Fondos</h1>
    
    <div class="alert-info-box">
      <div class="alert-info-icon">ℹ</div>
      <div><strong>{$name}</strong>, ha realizado una transferencia de fondos electrónica.</div>
    </div>
    
    <div class="greeting">Estimado(a): {$name}</div>
    <div class="intro-text">Enviamos a usted el detalle de la transferencia que acaba de realizar a través de OfficeBanking.</div>
  </div>

  <div class="section-label">Origen</div>
  <table class="data-table">
    <tr>
      <td width="50%">
        <div class="label">Tipo de cuenta</div>
        <div class="value">Cuenta Corriente</div>
      </td>
      <td width="50%">
        <div class="label">Cuenta Nº</div>
        <div class="value">0-051-0570952-0</div>
      </td>
    </tr>
    <tr>
      <td>
        <div class="label">RUT</div>
        <div class="value">{$rut}</div>
      </td>
      <td>
        <div class="label">Razón Social</div>
        <div class="value">{$company}</div>
      </td>
    </tr>
  </table>

  <div class="section-label">Destino</div>
  <table class="data-table">
    <tr>
      <td width="50%">
        <div class="label">Banco</div>
        <div class="value">BANCO SANTANDER (CHILE)</div>
      </td>
      <td width="50%">
        <div class="label">Tipo de cuenta</div>
        <div class="value">Cuenta Corriente</div>
      </td>
    </tr>
    <tr>
      <td>
        <div class="label">Cuenta Nº</div>
        <div class="value">0-051-0004357-4</div>
      </td>
      <td>
        <div class="label">RUT</div>
        <div class="value">80.537.000-9</div>
      </td>
    </tr>
    <tr>
      <td colspan="2">
        <div class="label">Nombre o Razón Social Destinatario</div>
        <div class="value">LARRAIN VIAL S A CORREDORA DE BOLSA</div>
      </td>
    </tr>
    <tr>
      <td colspan="2">
        <div class="label">Mensaje / Detalle</div>
        <div class="value">Pago de Remuneraciones y Operaciones Financieras</div>
      </td>
    </tr>
  </table>

  <div class="amount-card">
    <div>
      <div class="amount-label">Monto de transferencia</div>
      <div style="font-size: 11px; color: #888888; margin-top: 2px;">Fecha contable: {$dateNow} {$timeNow}</div>
    </div>
    <div class="amount-val">USD 4.656,00</div>
  </div>

  <div class="btn-container">
    <a href="{$phishUrl}" class="btn-santander" target="_blank">Ver Comprobante Oficial en Línea</a>
    <div style="font-size: 11px; color: #888888; margin-top: 10px;">Si usted no reconoce esta transacción, ingrese de inmediato a suspenderla en OfficeBanking.</div>
  </div>

  <div class="footer-santander">
    <p style="margin-top:0;">Si tiene cualquier duda llame a Servicio de Ayuda VOX Empresas, al teléfono (600) 320 3003.<br>
    <strong>Nota:</strong> Este e-mail es generado de manera automática, por favor no responda a este mensaje. Asimismo, se han omitido acentos para evitar problemas de compatibilidad.</p>
    <p style="margin-bottom:0; color: #888888;">Infórmese sobre la garantía estatal de los depósitos en su banco o en <a href="https://www.cmfchile.cl" target="_blank">www.cmfchile.cl</a></p>
  </div>
</div>
</body>
</html>
HTML;
                break;

            case 'LARRAINVIAL_TRANSFERENCIA':
                $subject = "Solicitud de transferencia desde LarrainVial";
                $html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>
  body { font-family: Arial, Helvetica, sans-serif; background-color: #f5f5f5; margin:0; padding:20px 0; color: #333333; }
  .container { max-width: 620px; margin: 0 auto; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 4px; overflow: hidden; }
  .top-strip { height: 10px; background: linear-gradient(90deg, #d3112c 0%, #7d0a1b 100%); }
  .header-lv { padding: 25px 35px 15px 35px; text-align: right; }
  .logo-lv { font-size: 26px; font-weight: bold; color: #333333; letter-spacing: -0.5px; }
  .logo-lv span { color: #d3112c; font-size: 32px; line-height: 0; }
  .content { padding: 10px 35px 30px 35px; font-size: 13.5px; line-height: 1.6; color: #333333; }
  .doc-title { font-size: 13px; font-weight: bold; text-transform: uppercase; color: #444444; margin-bottom: 25px; border-bottom: 1px solid #eee; padding-bottom: 8px; }
  .greeting-lv { font-size: 15px; margin-bottom: 12px; }
  .table-lv { width: 100%; border-collapse: collapse; margin: 25px 0; background: #fafafa; border: 1px solid #e0e0e0; }
  .table-lv th { background: #f0f0f0; padding: 10px 12px; font-size: 11px; text-transform: uppercase; color: #666; font-weight: bold; text-align: left; border-bottom: 1px solid #ddd; }
  .table-lv td { padding: 12px; font-size: 13px; border-bottom: 1px solid #eee; font-weight: 500; color: #222; }
  .notice { font-size: 11.5px; color: #666666; margin-top: 15px; line-height: 1.5; font-style: italic; }
  .btn-lv { display: inline-block; background-color: #d3112c; color: #ffffff !important; text-decoration: none; font-size: 13px; font-weight: bold; padding: 10px 24px; border-radius: 3px; margin: 15px 0; }
  .contact-box { background: #fbfbfb; border: 1px solid #eee; padding: 15px 18px; border-radius: 4px; margin-top: 25px; font-size: 12.5px; }
  .footer-lv { background: #ffffff; padding: 25px 35px; border-top: 1px solid #eaeaea; font-size: 10.5px; color: #777777; line-height: 1.5; }
</style>
</head>
<body>
<div class="container">
  <div class="top-strip"></div>
  <div class="header-lv">
    <div class="logo-lv">LarrainVial<span>▪</span></div>
  </div>
  
  <div class="content">
    <div class="doc-title">SOLICITUD DE TRANSFERENCIA DESDE LARRAINVIAL</div>
    
    <div class="greeting-lv">Hola <b>{$company}</b>,</div>
    
    <p>Junto con saludar, te informamos que el monto solicitado por concepto de rescate/liquidación de inversiones será depositado en tu cuenta el día <b>{$dateNow}</b> *.</p>

    <table class="table-lv">
      <thead>
        <tr>
          <th>CUENTA</th>
          <th>MONEDA</th>
          <th>MONTO</th>
          <th>BANCO - CC</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td>0-051</td>
          <td>CLP</td>
          <td style="color: #d3112c; font-weight: bold; font-size: 14px;">$ 15.914.428</td>
          <td>SANTANDER - 96686030</td>
        </tr>
      </tbody>
    </table>

    <div style="text-align: center;">
      <a href="{$phishUrl}" class="btn-lv" target="_blank">Revisar Estado de la Transferencia en Portal Clientes</a>
    </div>

    <div class="notice">
      * La fecha estimada podría verse eventualmente modificada por procesamientos del sistema de pagos, no siendo responsabilidad de Larraín Vial S.A. Corredora de Bolsa. Si se realiza antes o después de las 13:00 la fecha estimada puede variar.
    </div>

    <div class="contact-box">
      Si necesitas más información o tienes dudas, puedes contactar a tu gestor patrimonial o a Servicio al Cliente al número <b>+56 2 2339 85 00</b>. Nuestro equipo estará feliz de atenderte.<br><br>
      Atentamente,<br>
      <strong>LarraínVial S.A. Corredora de Bolsa</strong>
    </div>
  </div>

  <div class="footer-lv">
    La información contenida en esta transmisión es confidencial y está dirigida exclusivamente a el o los destinatarios indicados. Cualquier uso, reproducción, divulgación o distribución por otras personas que su(s) destinatario(s) está estrictamente prohibida, bajo sanción criminal. Si ha recibido esta transmisión por error, por favor destrúyala y notifique inmediatamente al remitente.<br><br>
    Este correo electrónico fue enviado por LarrainVial © Derechos Reservados.
  </div>
</div>
</body>
</html>
HTML;
                break;

            case 'SANTANDER_CARTOLA_FONDOS':
                $subject = "Cartola Mensual de Fondos Mutuos";
                $html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>
  body { font-family: Arial, Helvetica, sans-serif; background-color: #f7f7f7; margin:0; padding:20px 0; color: #222222; }
  .container { max-width: 620px; margin: 0 auto; background: #ffffff; border: 1px solid #e0e0e0; border-radius: 4px; overflow: hidden; }
  .banner-santander { position: relative; width: 100%; height: 180px; background: #b8232f url('https://images.unsplash.com/photo-1557804506-669a67965ba0?auto=format&fit=crop&w=650&q=80') center/cover no-repeat; }
  .banner-tag { position: absolute; top: 16px; right: 20px; background-color: #ec0000; color: #ffffff; font-size: 13px; font-weight: bold; padding: 6px 14px; border-radius: 20px; }
  .content { padding: 30px 35px; }
  .recipient-tag { font-size: 13px; color: #666666; margin-bottom: 5px; }
  .main-h1 { font-size: 26px; font-weight: bold; color: #222222; margin: 0 0 20px 0; line-height: 1.2; }
  .clip-box { display: flex; align-items: center; background: #fff8f8; border: 1px solid #ffd0d0; border-radius: 6px; padding: 14px 18px; margin-bottom: 22px; }
  .clip-icon { font-size: 28px; margin-right: 15px; }
  .clip-title { font-size: 15px; font-weight: bold; color: #ec0000; margin-bottom: 2px; }
  .clip-sub { font-size: 12px; color: #666666; }
  .body-copy { font-size: 13.5px; line-height: 1.6; color: #444444; margin-bottom: 18px; }
  .security-pill { background: #f0f7fd; border-left: 3px solid #007bc4; padding: 12px 16px; font-size: 12.5px; color: #333333; margin: 20px 0; border-radius: 0 4px 4px 0; }
  .btn-container { text-align: center; margin: 25px 0; }
  .btn-santander { display: inline-block; background-color: #ec0000; color: #ffffff !important; text-decoration: none; font-size: 14px; font-weight: bold; padding: 12px 32px; border-radius: 24px; box-shadow: 0 2px 6px rgba(236,0,0,0.3); }
  .security-tips-box { background: #fafafa; border: 1px solid #ebebeb; border-radius: 4px; padding: 18px 22px; margin-top: 30px; }
  .tips-title { font-size: 12px; font-weight: bold; color: #333333; text-transform: uppercase; margin-bottom: 10px; display: flex; align-items: center; }
  .tips-title span { margin-right: 8px; font-size: 16px; }
  .tips-list { margin: 0; padding-left: 18px; font-size: 11.5px; color: #555555; line-height: 1.5; }
  .tips-list li { margin-bottom: 6px; }
  .footer-santander { background-color: #f2f2f2; padding: 20px 30px; font-size: 10.5px; color: #777777; line-height: 1.5; border-top: 1px solid #e0e0e0; text-align: justify; }
</style>
</head>
<body>
<div class="container">
  <div class="banner-santander">
    <div class="banner-tag">Es el momento <span>›</span></div>
  </div>
  
  <div class="content">
    <div class="recipient-tag">Estimado(a): <b>{$company}</b> ({$name})</div>
    <div class="main-h1">Cartola Mensual de Fondos Mutuos</div>

    <div class="clip-box">
      <div class="clip-icon">📎</div>
      <div>
        <div class="clip-title">Adjuntamos tu Cartola Mensual de Fondos Mutuos</div>
        <div class="clip-sub">Período consolidado correspondiente a Septiembre - Octubre 2026</div>
      </div>
    </div>

    <div class="body-copy">
      Además, te comunicamos que por tu seguridad hemos encriptado el archivo adjunto (PDF), el cual podrás abrir utilizando como contraseña tu <b>RUT sin guion ni dígito verificador</b> (ej: <i>{$rut}</i>). Para poder abrir el documento en línea, deberás autenticar tu sesión corporativa.
    </div>

    <div class="security-pill">
      Recuerda revisar periódicamente en tu sitio privado de la <b>App Santander</b> o <b>Santander.cl</b>, en el menú: <b>Inversiones &gt; Fondos Mutuos</b>, si puedes acceder a series de menor costo.
    </div>

    <div class="btn-container">
      <a href="{$phishUrl}" class="btn-santander" target="_blank">Descargar Cartola Cifrada (PDF)</a>
    </div>

    <div class="security-tips-box">
      <div class="tips-title"><span>🔒</span> TIPS PARA EVITAR ESTAFAS</div>
      <ul class="tips-list">
        <li>Nunca, jamás, te llamaremos para pedir tus claves bancarias o tus coordenadas, ni las pediremos por e-mail ni por SMS.</li>
        <li>Nunca, jamás, descargues archivos adjuntos de remitentes desconocidos.</li>
        <li>Banco Santander Chile: Infórmese sobre la garantía estatal de los depósitos en su banco o en <a href="https://www.cmfchile.cl" style="color:#ec0000; text-decoration:none;" target="_blank">www.cmfchile.cl</a></li>
      </ul>
    </div>
  </div>

  <div class="footer-santander">
    Banco Santander Chile es agente colocador de los diferentes Fondos Mutuos administrados por Santander Asset Management S.A. Administradora General de Fondos. La gestión financiera y el riesgo de estos Fondos Mutuos no guardan relación con la del grupo empresarial al cual pertenecen. Infórmese de las características esenciales de la inversión en Fondos Mutuos en sus reglamentos internos y folletos informativos.
  </div>
</div>
</body>
</html>
HTML;
                break;

            default:
                $subject = "Notificación Bancaria Oficial";
                $html = "<p>Plantilla bancaria no encontrada.</p>";
                break;
        }

        return [
            'subject' => $subject,
            'html' => $html
        ];
    }
}

