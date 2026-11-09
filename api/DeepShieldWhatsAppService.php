<?php
/**
 * Keystone DeepShield - WhatsApp Integration Service
 * Conexión nativa con Evolution API v2 para despacho instantáneo de alertas OOB y gestión de QR.
 */

class DeepShieldWhatsAppService {
    private string $gatewayUrl;
    private string $masterApiKey;

    public function __construct(string $gatewayUrl = 'http://5.183.9.148:8080', string $masterApiKey = '04e9aeffff588f410c0be3bba26d6075de9d47360b134d98635ea3c71b03fdde') {
        $this->gatewayUrl = rtrim($gatewayUrl, '/');
        $this->masterApiKey = $masterApiKey;
    }

    /**
     * Petición genérica HTTP a Evolution API
     */
    private function request(string $endpoint, string $method = 'GET', array $data = []): array {
        $url = $this->gatewayUrl . '/' . ltrim($endpoint, '/');
        $ch = curl_init($url);

        $headers = [
            'apikey: ' . $this->masterApiKey,
            'Content-Type: application/json'
        ];

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['success' => false, 'error' => "Error de conexión WhatsApp: $err", 'http_code' => $httpCode];
        }

        $decoded = json_decode($res, true);
        if ($httpCode >= 200 && $httpCode < 300) {
            return ['success' => true, 'data' => $decoded, 'http_code' => $httpCode];
        }

        $errMsg = $decoded['response']['message'] ?? ($decoded['message'] ?? 'Error en Gateway WhatsApp');
        if (is_array($errMsg)) $errMsg = implode(', ', $errMsg);
        return ['success' => false, 'error' => $errMsg, 'http_code' => $httpCode, 'raw' => $res];
    }

    /**
     * Crear o conectar instancia para obtener el código QR de vinculación
     */
    public function getOrConnectInstance(string $instanceName): array {
        $cleanName = preg_replace('/[^a-zA-Z0-9_-]/', '', $instanceName);
        
        // 1. Verificar si la instancia ya existe
        $stateRes = $this->request("/instance/connectionState/{$cleanName}", 'GET');
        if ($stateRes['success']) {
            $state = $stateRes['data']['instance']['state'] ?? 'unknown';
            if ($state === 'open') {
                return ['success' => true, 'connected' => true, 'instance' => $cleanName, 'state' => 'open'];
            }
            // Si está creada pero desconectada, pedir QR de conexión
            $connectRes = $this->request("/instance/connect/{$cleanName}", 'GET');
            if ($connectRes['success']) {
                $code = $connectRes['data']['code'] ?? ($connectRes['data']['base64'] ?? null);
                $base64 = $connectRes['data']['base64'] ?? null;
                return ['success' => true, 'connected' => false, 'instance' => $cleanName, 'state' => $state, 'code' => $code, 'base64' => $base64];
            }
        }

        // 2. Si no existe, crear nueva instancia
        $createRes = $this->request('/instance/create', 'POST', [
            'instanceName' => $cleanName,
            'token' => bin2hex(random_bytes(16)),
            'qrcode' => true,
            'integration' => 'WHATSAPP-BAILEYS'
        ]);

        if (!$createRes['success']) {
            return $createRes;
        }

        // Obtener QR recién creado
        $base64 = $createRes['data']['qrcode']['base64'] ?? null;
        $code = $createRes['data']['qrcode']['code'] ?? null;

        return [
            'success' => true,
            'connected' => false,
            'instance' => $cleanName,
            'base64' => $base64,
            'code' => $code
        ];
    }

    /**
     * Consultar estado de conexión
     */
    public function getConnectionState(string $instanceName): string {
        $res = $this->request("/instance/connectionState/{$instanceName}", 'GET');
        if ($res['success']) {
            return $res['data']['instance']['state'] ?? 'unknown';
        }
        return 'disconnected';
    }

    /**
     * Enviar mensaje de texto directo con reintento y fallback garantizado a Keystone General
     */
    public function sendRawMessage(string $instanceName, string $phoneNumber, string $text): array {
        $cleanPhone = preg_replace('/[^0-9]/', '', $phoneNumber);
        if (strlen($cleanPhone) < 8) {
            return ['success' => false, 'error' => "Número de celular inválido: {$phoneNumber}"];
        }

        $payload = [
            'number' => $cleanPhone,
            'text' => $text,
            'delay' => 1200,
            'linkPreview' => true
        ];

        // 1. Intentar con la instancia especificada (ej: chip corporativo del cliente)
        $targetInstance = !empty($instanceName) ? $instanceName : 'keystone-general';
        $res = $this->request("/message/sendText/{$targetInstance}", 'POST', $payload);

        // Si tuvo éxito, retornar
        if ($res['success']) {
            $res['dispatched_via'] = $targetInstance;
            $res['fallback_used'] = false;
            return $res;
        }

        // 2. Si falló y la instancia no era la de respaldo de Keystone, ejecutar fallback de emergencia
        if ($targetInstance !== 'keystone-general') {
            error_log("DeepShieldWhatsApp: Falla en instancia '{$targetInstance}'. Ejecutando reintento por 'keystone-general'. Error original: " . ($res['error'] ?? 'desconocido'));
            $fallbackRes = $this->request("/message/sendText/keystone-general", 'POST', $payload);
            if ($fallbackRes['success']) {
                $fallbackRes['dispatched_via'] = 'keystone-general';
                $fallbackRes['fallback_used'] = true;
                $fallbackRes['primary_error'] = $res['error'] ?? 'Instancia principal no disponible';
                return $fallbackRes;
            }
            return [
                'success' => false,
                'error' => "Fallo en canal cliente ({$res['error']}) y en canal Keystone ({$fallbackRes['error']})",
                'primary_res' => $res,
                'fallback_res' => $fallbackRes
            ];
        }

        return $res;
    }

    /**
     * Enviar mensaje de alerta OOB para autorizar pago con fallback garantizado a Keystone General
     */
    public function sendTreasuryAlert(string $instanceName, string $phoneNumber, array $orderData, string $verifyUrl): array {
        $cleanPhone = preg_replace('/[^0-9]/', '', $phoneNumber);
        if (strlen($cleanPhone) < 8) {
            return ['success' => false, 'error' => "Número de celular inválido: {$phoneNumber}"];
        }

        $amountFormatted = number_format($orderData['amount'], 0, ',', '.');
        $currency = $orderData['currency'] ?? 'CLP';

        $msg = "🛡️ *ALERTA KEYSTONE DEEPSHIELD TREASURY*\n";
        $msg .= "----------------------------------------\n";
        $msg .= "Estimada(o) *{$orderData['approver_name']}*,\n\n";
        $msg .= "Se ha registrado una orden de pago crítica para la empresa:\n";
        $msg .= "🏢 *Empresa:* {$orderData['company_name']}\n";
        $msg .= "💰 *Monto a Pagar:* {$currency} \${$amountFormatted}\n";
        $msg .= "👤 *Beneficiario:* {$orderData['beneficiary_name']}\n";
        $msg .= "📄 *RUT / Identificador:* {$orderData['beneficiary_rut_dni']}\n";
        $msg .= "🏦 *Banco / Cuenta:* {$orderData['bank_name']} ({$orderData['account_number']})\n";
        if (!empty($orderData['invoice_folio'])) {
            $msg .= "🧾 *Factura / Folio:* {$orderData['invoice_folio']}\n";
        }
        $msg .= "\n🔐 *DESAFÍO ANTI-CLONACIÓN DE VOZ:* `{$orderData['security_challenge']}`\n";
        $msg .= "_(Si recibió una llamada o mensaje previo, exija que le confirmen esta clave)_\n\n";
        $msg .= "📲 *Para Autorizar con Firma Criptográfica o Bloquear por Fraude:*\n";
        $msg .= "👉 {$verifyUrl}\n\n";
        $msg .= "🔒 _Protocolo Zero Trust • Keystone Advisors SpA_";

        $payload = [
            'number' => $cleanPhone,
            'text' => $msg,
            'delay' => 1200,
            'linkPreview' => true
        ];

        // 1. Intentar con la instancia especificada (ej: chip corporativo del cliente)
        $targetInstance = !empty($instanceName) ? $instanceName : 'keystone-general';
        $res = $this->request("/message/sendText/{$targetInstance}", 'POST', $payload);

        if ($res['success']) {
            $res['dispatched_via'] = $targetInstance;
            $res['fallback_used'] = false;
            return $res;
        }

        // 2. Si falló la instancia del cliente, reintento inmediato por keystone-general
        if ($targetInstance !== 'keystone-general') {
            error_log("DeepShieldWhatsApp: Falla en instancia cliente '{$targetInstance}'. Reintentando por 'keystone-general'...");
            $fallbackRes = $this->request("/message/sendText/keystone-general", 'POST', $payload);
            if ($fallbackRes['success']) {
                $fallbackRes['dispatched_via'] = 'keystone-general';
                $fallbackRes['fallback_used'] = true;
                $fallbackRes['primary_error'] = $res['error'] ?? 'Instancia cliente caída o desconectada';
                return $fallbackRes;
            }
            return [
                'success' => false,
                'error' => "Fallo en canal cliente ({$res['error']}) y en canal Keystone ({$fallbackRes['error']})",
                'primary_res' => $res,
                'fallback_res' => $fallbackRes
            ];
        }

        return $res;
    }
}
