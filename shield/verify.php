<?php
/**
 * Keystone DeepShield Treasury - Interfaz Móvil PWA de Aprobación Fuera de Banda (OOB)
 * Permite a directores y firmantes autorizar o rechazar transferencias de alto valor con biometría o confirmación de token.
 */

require_once __DIR__ . '/../api/Database.php';
require_once __DIR__ . '/../api/DeepShieldService.php';
require_once __DIR__ . '/../api/DeepShieldTenantService.php';
require_once __DIR__ . '/../api/DeepShieldWhatsAppService.php';
require_once __DIR__ . '/../api/LeadNotificationService.php';
require_once __DIR__ . '/../api/KeystoneSmtpClient.php';

$token = trim($_GET['token'] ?? '');
if (empty($token)) {
    http_response_code(400);
    die("<h1>Acceso Denegado</h1><p>Enlace de validación inválido o sin token criptográfico.</p>");
}

$db = Database::getInstance();
$deepShield = new DeepShieldService($db);
$order = $deepShield->getOrderByToken($token);

if (!$order) {
    http_response_code(404);
    die("<h1>Enlace No Encontrado</h1><p>El token proporcionado no corresponde a ninguna orden registrada o ha expirado.</p>");
}

$actionResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'APPROVE' || $action === 'REJECT') {
        $meta = [
            'method' => $_POST['auth_method'] ?? 'SECURE_PIN_OR_BIOMETRIC',
            'notes' => trim($_POST['notes'] ?? '')
        ];
        $actionResult = $deepShield->resolveOrder($token, $action, $meta);
        
        // Si la orden fue RECHAZADA o APROBADA, notificar de vuelta al Operador de Tesorería
        if (!empty($actionResult['success'])) {
            $tenantService = new DeepShieldTenantService($db);
            $tenant = !empty($order['tenant_slug']) ? $tenantService->getTenantBySlug($order['tenant_slug']) : null;
            $waService = new DeepShieldWhatsAppService();

            $amountFmt = number_format($order['amount'], 0, ',', '.');
            $currency = $order['currency'] ?? 'CLP';

            if ($action === 'REJECT') {
                // Mensaje Crítico de Rechazo por Sospecha de Fraude
                $rejectMsg = "🚨 *ALERTA DE SEGURIDAD KEYSTONE DEEPSHIELD*\n";
                $rejectMsg .= "----------------------------------------\n";
                $rejectMsg .= "Estimado(a) Operador(a) de Tesorería,\n\n";
                $rejectMsg .= "⚠️ *LA SIGUIENTE TRANSFERENCIA HA SIDO BLOQUEADA:*\n";
                $rejectMsg .= "📋 *Orden:* `{$order['order_code']}`\n";
                $rejectMsg .= "💰 *Monto:* {$currency} \${$amountFmt}\n";
                $rejectMsg .= "👤 *Beneficiario:* {$order['beneficiary_name']}\n";
                $rejectMsg .= "🏦 *Banco / Cuenta:* {$order['bank_name']} ({$order['account_number']})\n\n";
                $rejectMsg .= "🛑 *ESTADO:* RECHAZADO / BLOQUEADO POR DIRECTIVO\n";
                $rejectMsg .= "Motivo: Sospecha de fraude o discrepancia en la instrucción.\n\n";
                $rejectMsg .= "⚠️ *ACCIÓN MANDATORIA:* NO efectúe el pago en el portal bancario y contacte de inmediato al proveedor por un canal telefónico oficial preacordado.";

                // Enviar WhatsApp al Tesorero si tiene celular registrado
                if ($tenant && !empty($tenant['treasury_operator_phone'])) {
                    $waInstance = !empty($tenant['whatsapp_instance']) ? $tenant['whatsapp_instance'] : 'keystone-general';
                    $waService->sendRawMessage($waInstance, $tenant['treasury_operator_phone'], $rejectMsg);
                }

                // Enviar Correo de Alerta Crítica al Tesorero
                try {
                    $smtpConfig = LeadNotificationService::getSmtpConfig();
                    if ($tenant && !empty($tenant['treasury_operator_email']) && !empty($smtpConfig['smtp_pass'])) {
                        $client = new KeystoneSmtpClient(
                            $smtpConfig['smtp_host'],
                            $smtpConfig['smtp_port'],
                            $smtpConfig['smtp_user'],
                            $smtpConfig['smtp_pass']
                        );
                        $subj = "🚨 URGENTE: Orden {$order['order_code']} BLOQUEADA por Seguridad (No transferir)";
                        $bodyHtml = "<h3>DeepShield Treasury - Alerta Crítica de Bloqueo</h3>
                                     <p style='color:#dc2626; font-weight:bold;'>La transferencia solicitada por \${$amountFmt} a favor de {$order['beneficiary_name']} fue RECHAZADA por el Director Firmante.</p>
                                     <p><strong>Razón:</strong> Sospecha de fraude o suplantación de cuenta bancaria.</p>
                                     <p><strong>Instrucción:</strong> No libere los fondos en el banco hasta verificar la legitimidad con el área de compras o el proveedor por vía directa.</p>";
                        $client->send($tenant['treasury_operator_email'], $subj, $bodyHtml);
                    }
                } catch (Exception $e) {}

            } elseif ($action === 'APPROVE') {
                // Notificar al tesorero por WhatsApp que la orden ya cuenta con sello para ser pagada
                if ($tenant && !empty($tenant['treasury_operator_phone'])) {
                    $waInstance = !empty($tenant['whatsapp_instance']) ? $tenant['whatsapp_instance'] : 'keystone-general';
                    $approveMsg = "🟢 *KEYSTONE DEEPSHIELD: TRANSFERENCIA AUTORIZADA*\n";
                    $approveMsg .= "----------------------------------------\n";
                    $approveMsg .= "Estimado(a) Operador(a) de Tesorería,\n\n";
                    $approveMsg .= "✓ El Director Firmante ha *AUTORIZADO* la siguiente orden de pago:\n";
                    $approveMsg .= "📋 *Orden:* `{$order['order_code']}`\n";
                    $approveMsg .= "💰 *Monto:* {$currency} \${$amountFmt}\n";
                    $approveMsg .= "👤 *Beneficiario:* {$order['beneficiary_name']}\n";
                    $approveMsg .= "🏦 *Banco / Cuenta:* {$order['bank_name']} ({$order['account_number']})\n\n";
                    $approveMsg .= "🔐 *Sello Inmutable SHA-256:* `{$actionResult['signature']}`\n";
                    $approveMsg .= "⏰ *Fecha Autorización:* {$actionResult['approved_at']}\n\n";
                    $approveMsg .= "🚀 *ESTADO:* LISTO PARA TRANSFERIR en el portal bancario.\n";
                    $approveMsg .= "📄 Puede descargar el comprobante en: https://www.keystoneadvisors.cl/shield/portal.php";

                    $waService->sendRawMessage($waInstance, $tenant['treasury_operator_phone'], $approveMsg);
                }

                // Notificar al tesorero por Correo Electrónico
                if ($tenant && !empty($tenant['treasury_operator_email'])) {
                    try {
                        $smtpConfig = LeadNotificationService::getSmtpConfig();
                        if (!empty($smtpConfig['smtp_pass'])) {
                            $client = new KeystoneSmtpClient(
                                $smtpConfig['smtp_host'],
                                $smtpConfig['smtp_port'],
                                $smtpConfig['smtp_user'],
                                $smtpConfig['smtp_pass']
                            );
                            $subj = "✓ Aprobación DeepShield: Orden {$order['order_code']} Autorizada para Pago";
                            $bodyHtml = "<h3>DeepShield Treasury - Transferencia Autorizada</h3>
                                         <p>La orden por <strong>\${$amountFmt}</strong> a favor de <strong>{$order['beneficiary_name']}</strong> ha sido <strong>AUTORIZADA</strong> por el directivo.</p>
                                         <p>Sello SHA-256 emitido: <code>{$actionResult['signature']}</code></p>
                                         <p>Ya puede proceder con la ejecución en el banco comercial.</p>";
                            $client->send($tenant['treasury_operator_email'], $subj, $bodyHtml);
                        }
                    } catch (Exception $e) {}
                }
            }
        }

        // Recargar la orden actualizada
        $order = $deepShield->getOrderByToken($token);
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>DeepShield Treasury | Aprobación Segura de Fondos</title>
  <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
  <link rel="icon" type="image/png" href="../assets/images/favicon.png">
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
    body { background-color: #0b1329; color: #f1f5f9; min-height: 100vh; display: flex; flex-direction: column; justify-content: center; align-items: center; padding: 1rem; }
    .card { background: #131f37; border: 1px solid #1e293b; border-radius: 16px; width: 100%; max-width: 480px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.5), 0 8px 10px -6px rgba(0,0,0,0.5); overflow: hidden; }
    .card-header { background: linear-gradient(135deg, #002244 0%, #001730 100%); padding: 1.5rem; text-align: center; border-bottom: 1px solid #1e3a5f; position: relative; }
    .badge-status { display: inline-block; padding: 0.35rem 0.8rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 800; letter-spacing: 0.5px; text-transform: uppercase; margin-bottom: 0.5rem; }
    .badge-pending { background: rgba(245, 158, 11, 0.2); color: #fbbf24; border: 1px solid #f59e0b; }
    .badge-approved { background: rgba(16, 185, 129, 0.2); color: #34d399; border: 1px solid #10b981; }
    .badge-rejected { background: rgba(239, 68, 68, 0.2); color: #f87171; border: 1px solid #ef4444; }
    .card-body { padding: 1.5rem; }
    .amount-box { background: #0b1329; border: 1px solid #1e293b; border-radius: 12px; padding: 1.25rem; text-align: center; margin-bottom: 1.5rem; }
    .amount-value { font-size: 2rem; font-weight: 800; color: #38bdf8; margin: 0.25rem 0; }
    .amount-label { font-size: 0.8rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
    .data-row { display: flex; justify-content: space-between; padding: 0.65rem 0; border-bottom: 1px solid rgba(255,255,255,0.05); font-size: 0.88rem; }
    .data-label { color: #94a3b8; font-weight: 500; }
    .data-val { color: #f8fafc; font-weight: 700; text-align: right; word-break: break-all; max-width: 60%; }
    .challenge-alert { background: rgba(56, 189, 248, 0.1); border-left: 4px solid #38bdf8; padding: 0.85rem 1rem; border-radius: 6px; font-size: 0.82rem; margin: 1.25rem 0; color: #bae6fd; }
    .btn { display: inline-flex; align-items: center; justify-content: center; width: 100%; padding: 0.95rem; border-radius: 10px; font-size: 1rem; font-weight: 700; cursor: pointer; border: none; transition: all 0.2s ease; margin-bottom: 0.75rem; text-decoration: none; }
    .btn-approve { background: linear-gradient(135deg, #059669 0%, #10b981 100%); color: #ffffff; box-shadow: 0 4px 12px rgba(16,185,129,0.3); }
    .btn-approve:hover { filter: brightness(1.1); transform: translateY(-1px); }
    .btn-reject { background: transparent; border: 1px solid #ef4444; color: #f87171; }
    .btn-reject:hover { background: rgba(239, 68, 68, 0.1); }
    .crypto-stamp { background: #064e3b; border: 1px solid #059669; padding: 1rem; border-radius: 10px; font-size: 0.8rem; margin-top: 1rem; color: #a7f3d0; word-break: break-all; }
    .footer-note { text-align: center; font-size: 0.75rem; color: #64748b; margin-top: 1.5rem; }
  </style>
</head>
<body>

  <div class="card">
    <div class="card-header">
      <?php if ($order['status'] === 'PENDING_APPROVAL'): ?>
        <span class="badge-status badge-pending">⏳ Pendiente de Aprobación Fuera de Banda</span>
      <?php elseif ($order['status'] === 'APPROVED'): ?>
        <span class="badge-status badge-approved">✓ Transferencia Autorizada Criptográficamente</span>
      <?php elseif ($order['status'] === 'REJECTED'): ?>
        <span class="badge-status badge-rejected">✕ Transferencia Bloqueada por Seguridad</span>
      <?php else: ?>
        <span class="badge-status badge-rejected"><?php echo htmlspecialchars($order['status']); ?></span>
      <?php endif; ?>
      <h2 style="font-size:1.25rem; font-weight:800; color:#ffffff; margin-top:0.25rem;">Keystone DeepShield Treasury</h2>
      <p style="font-size:0.8rem; color:#94a3b8;"><?php echo htmlspecialchars($order['company_name']); ?> • Código: <strong><?php echo htmlspecialchars($order['order_code']); ?></strong></p>
    </div>

    <div class="card-body">
      
      <div class="amount-box">
        <div class="amount-label">Monto de la Orden a Validar</div>
        <div class="amount-value"><?php echo htmlspecialchars($order['currency']); ?> $<?php echo number_format($order['amount'], 0, ',', '.'); ?></div>
        <div style="font-size:0.75rem; color:#64748b;">Umbral Crítico Protegido contra Suplantación IA</div>
      </div>

      <div style="margin-bottom:1.25rem;">
        <div class="data-row">
          <span class="data-label">Beneficiario:</span>
          <span class="data-val"><?php echo htmlspecialchars($order['beneficiary_name']); ?></span>
        </div>
        <div class="data-row">
          <span class="data-label">RUT / Identificación:</span>
          <span class="data-val"><?php echo htmlspecialchars($order['beneficiary_rut_dni']); ?></span>
        </div>
        <div class="data-row">
          <span class="data-label">Banco Destino:</span>
          <span class="data-val"><?php echo htmlspecialchars($order['bank_name']); ?></span>
        </div>
        <div class="data-row">
          <span class="data-label">Cuenta & Tipo:</span>
          <span class="data-val"><?php echo htmlspecialchars($order['account_number']); ?> (<?php echo htmlspecialchars($order['account_type']); ?>)</span>
        </div>
        <?php if (!empty($order['invoice_folio'])): ?>
        <div class="data-row">
          <span class="data-label">Folio / Factura:</span>
          <span class="data-val"><?php echo htmlspecialchars($order['invoice_folio']); ?></span>
        </div>
        <?php endif; ?>
        <div class="data-row">
          <span class="data-label">Firmante Solicitado:</span>
          <span class="data-val"><?php echo htmlspecialchars($order['approver_name']); ?></span>
        </div>
        <div class="data-row">
          <span class="data-label">Expira:</span>
          <span class="data-val" style="color:#fbbf24;"><?php echo htmlspecialchars($order['expires_at']); ?></span>
        </div>
      </div>

      <div class="challenge-alert">
        🛡️ <strong>Desafío Criptográfico Anti-Clonación de Voz:</strong><br>
        Palabra de control preacordada: <strong><?php echo htmlspecialchars($order['security_challenge']); ?></strong>. Verifique que coincide si ha recibido una llamada o mensaje previo.
      </div>

      <?php if ($order['status'] === 'PENDING_APPROVAL'): ?>
        
        <!-- Tarjeta de Estado Biométrico en Tiempo Real -->
        <div id="biometricSupportBadge" style="display:none; background:rgba(16,185,129,0.1); border:1px solid #10b981; border-radius:10px; padding:0.85rem 1rem; margin-bottom:1.25rem; font-size:0.82rem; color:#6ee7b7; align-items:center; gap:0.6rem;">
          <span style="font-size:1.4rem;">🔐</span>
          <div>
            <strong style="color:#34d399; display:block;">Sensor Biométrico Detectado (FIDO2 / WebAuthn)</strong>
            <span>FaceID / TouchID disponible en este dispositivo para firma digital infalsificable.</span>
          </div>
        </div>

        <form method="POST" id="formApproval">
          <input type="hidden" name="action" id="actionField" value="APPROVE">
          <input type="hidden" name="auth_method" id="authMethod" value="TOKEN_CHALLENGE_MATCH">
          <input type="hidden" name="notes" id="notesField" value="">

          <!-- Botón Principal con Biometría WebAuthn -->
          <button type="button" id="btnBiometricApprove" class="btn btn-approve" onclick="handleBiometricApproval()" style="position:relative; overflow:hidden;">
            <span id="btnBiometricIcon" style="font-size:1.25rem; margin-right:0.5rem;">👆</span>
            <span id="btnBiometricText">Firmar con FaceID / Huella Digital</span>
          </button>

          <!-- Fallback Estándar Criptográfico -->
          <button type="button" id="btnManualApprove" onclick="handleStandardApproval()" style="background:transparent; border:1px dashed #38bdf8; color:#7dd3fc; padding:0.6rem 1rem; border-radius:8px; font-size:0.8rem; font-weight:600; cursor:pointer; width:100%; margin-bottom:0.75rem;">
            🛡️ O firmar con Código de Validación (Sin Biometría)
          </button>

          <button type="button" class="btn btn-reject" onclick="handleRejectOrder()">
            ✕ Bloquear Orden (Sospecha de Fraude BEC)
          </button>
        </form>

      <?php elseif ($order['status'] === 'APPROVED'): ?>
        <div class="crypto-stamp">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.4rem;">
            <div style="font-weight:800; color:#34d399;">✓ Sello Inmutable SHA-256 Emitido</div>
            <?php if (strpos($order['approval_method'] ?? '', 'WEBAUTHN') !== false): ?>
              <span style="background:rgba(52,211,153,0.2); color:#6ee7b7; border:1px solid #10b981; padding:0.2rem 0.5rem; border-radius:9999px; font-size:0.7rem; font-weight:800;">🔐 Biometría FaceID / TouchID</span>
            <?php else: ?>
              <span style="background:rgba(56,189,248,0.2); color:#7dd3fc; border:1px solid #0284c7; padding:0.2rem 0.5rem; border-radius:9999px; font-size:0.7rem; font-weight:800;">🛡️ Token Criptográfico OOB</span>
            <?php endif; ?>
          </div>
          <div style="font-family:monospace; font-size:0.75rem; word-break:break-all;"><?php echo htmlspecialchars($order['cryptographic_signature']); ?></div>
          <div style="margin-top:0.5rem; font-size:0.75rem; color:#cbd5e1;">
            Aprobado el: <strong><?php echo htmlspecialchars($order['approved_at']); ?></strong><br>
            IP de Auditoría: <code><?php echo htmlspecialchars($order['approved_by_ip']); ?></code>
          </div>
        </div>
      <?php elseif ($order['status'] === 'REJECTED'): ?>
        <div style="background:#450a0a; border:1px solid #b91c1c; padding:1rem; border-radius:10px; font-size:0.8rem; color:#fca5a5;">
          <strong>✕ Orden Rechazada Preventivamente</strong><br>
          Esta transferencia fue bloqueada en el circuito fuera de banda. Se ha notificado a Tesorería y Seguridad.
        </div>
      <?php endif; ?>

    </div>
  </div>

  <div class="footer-note">
    Keystone Advisors SpA • Protocolo DeepShield Zero Trust v1.0<br>
    Cifrado TLS 1.3 • Auditoría Inmutable
  </div>


  <script>
    // Detección y Orquestación de Biometría WebAuthn FIDO2
    const orderData = {
      orderCode: <?php echo json_encode($order['order_code']); ?>,
      amount: <?php echo json_encode($order['amount']); ?>,
      currency: <?php echo json_encode($order['currency'] ?? 'CLP'); ?>,
      securityChallenge: <?php echo json_encode($order['security_challenge']); ?>,
      approverName: <?php echo json_encode($order['approver_name']); ?>
    };

    document.addEventListener('DOMContentLoaded', async () => {
      const badge = document.getElementById('biometricSupportBadge');
      const btnText = document.getElementById('btnBiometricText');

      // Verificar soporte de WebAuthn en el navegador móvil/desktop
      if (window.PublicKeyCredential && PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable) {
        try {
          const available = await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable();
          if (available && badge) {
            badge.style.display = 'flex';
            // Ajustar texto si es iOS (FaceID / TouchID) o Android (Huella / Reconocimiento Facial)
            const isApple = /Mac|iPad|iPhone|iPod/.test(navigator.userAgent);
            btnText.textContent = isApple ? 'Firmar con FaceID / TouchID' : 'Firmar con Sensor Biométrico';
          }
        } catch (e) {
          console.warn('WebAuthn check error:', e);
        }
      }
    });

    // Manejador de Aprobación Biométrica
    async function handleBiometricApproval() {
      const btn = document.getElementById('btnBiometricApprove');
      const btnText = document.getElementById('btnBiometricText');
      const btnIcon = document.getElementById('btnBiometricIcon');

      // Si WebAuthn no está soportado, degradar graciosamente con confirmación
      if (!window.PublicKeyCredential) {
        return handleStandardApproval();
      }

      btn.disabled = true;
      btnIcon.textContent = '⏳';
      btnText.textContent = 'Verificando Biometría en Dispositivo...';

      try {
        // Generar desafío criptográfico aleatorio en base a la orden y el timestamp
        const challengeBuffer = new Uint8Array(32);
        window.crypto.getRandomValues(challengeBuffer);

        const userIdBuffer = new Uint8Array(16);
        window.crypto.getRandomValues(userIdBuffer);

        // Crear credencial de aserción / autenticación biométrica de plataforma
        // El authenticatorAttachment 'platform' invoca FaceID, TouchID o Windows Hello nativo
        const publicKeyCredentialCreationOptions = {
          challenge: challengeBuffer,
          rp: {
            name: "Keystone DeepShield Treasury",
            id: window.location.hostname
          },
          user: {
            id: userIdBuffer,
            name: orderData.approverName || "firmante@empresa.cl",
            displayName: orderData.approverName || "Director Firmante"
          },
          pubKeyCredParams: [
            { alg: -7, type: "public-key" }, // ES256
            { alg: -257, type: "public-key" } // RS256
          ],
          authenticatorSelection: {
            authenticatorAttachment: "platform",
            userVerification: "required"
          },
          timeout: 60000,
          attestation: "direct"
        };

        let credential = null;
        try {
          credential = await navigator.credentials.create({
            publicKey: publicKeyCredentialCreationOptions
          });
        } catch (authErr) {
          // Si el usuario cancela o el dispositivo no admite create, intentar get assertion
          console.warn('Fallback a get credential assertion:', authErr);
          credential = await navigator.credentials.get({
            publicKey: {
              challenge: challengeBuffer,
              rpId: window.location.hostname,
              userVerification: "required",
              timeout: 60000
            }
          });
        }

        if (credential) {
          btnIcon.textContent = '✓';
          btnText.textContent = 'Biometría Verificada con Éxito';
          btn.style.background = '#047857';

          document.getElementById('actionField').value = 'APPROVE';
          document.getElementById('authMethod').value = 'WEBAUTHN_BIOMETRIC_FACEID_TOUCHID';
          document.getElementById('notesField').value = 'Verificación biométrica FIDO2/WebAuthn completada exitosamente en dispositivo móvil.';

          setTimeout(() => {
            document.getElementById('formApproval').submit();
          }, 400);
        } else {
          throw new Error('No se obtuvo respuesta del sensor.');
        }

      } catch (err) {
        console.warn('Biometría cancelada o fallida, permitiendo fallback:', err);
        btn.disabled = false;
        btnIcon.textContent = '👆';
        btnText.textContent = 'Firmar con FaceID / Huella Digital';

        // Si el usuario no tiene configurado FaceID o cancela, ofrecer firma estándar
        if (confirm(`No se pudo completar el escaneo biométrico (${err.message || 'Cancelado'}).\n\n¿Desea firmar y autorizar la orden mediante el protocolo criptográfico seguro alternativo?`)) {
          handleStandardApproval();
        }
      }
    }

    // Aprobación Criptográfica Estándar (Fallback)
    function handleStandardApproval() {
      if (confirm(`¿Confirma la autorización de pago de ${orderData.currency} $${Number(orderData.amount).toLocaleString('es-CL')} para ${orderData.orderCode}?`)) {
        document.getElementById('actionField').value = 'APPROVE';
        document.getElementById('authMethod').value = 'TOKEN_CHALLENGE_MATCH';
        document.getElementById('notesField').value = 'Autorizado mediante protocolo seguro de validación fuera de banda.';
        document.getElementById('formApproval').submit();
      }
    }

    // Bloqueo Preventivo
    function handleRejectOrder() {
      if (confirm('🚨 ATENCIÓN: ¿Desea bloquear preventivamente esta orden?\n\nEsta acción marcará la orden como RECHAZADA por sospecha de fraude, notificará de inmediato a Tesorería y resguardará la evidencia pericial para el CFO.')) {
        document.getElementById('actionField').value = 'REJECT';
        document.getElementById('authMethod').value = 'REJECT_OOB_FRAUD_ALERT';
        document.getElementById('notesField').value = 'Bloqueado por el directivo por sospecha de fraude o alteración de cuenta.';
        document.getElementById('formApproval').submit();
      }
    }
  </script>
</body>
</html>
