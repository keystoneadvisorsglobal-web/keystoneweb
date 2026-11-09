<?php
/**
 * Keystone Advisors - LinkedIn Community Management & Organic Publisher
 * Publicación oficial y automatizada de artículos, banners y posts B2B en la Página de Empresa.
 * API v2: /rest/posts y /rest/images
 */

class KeystoneLinkedInPublisher {

    private static string $apiBase = 'https://api.linkedin.com/rest';
    private static string $apiVersion = '202401'; // LinkedIn Rest-Version header

    /**
     * Obtener configuración almacenada en mkt_settings
     */
    public static function getConfig(): array {
        require_once __DIR__ . '/KeystoneGrowthService.php';
        $db = KeystoneGrowthService::getDb();
        
        $keys = [
            'linkedin_client_id',
            'linkedin_client_secret',
            'linkedin_access_token',
            'linkedin_org_id',
            'linkedin_org_urn',
            'linkedin_token_expires_at'
        ];

        $config = [];
        foreach ($keys as $k) {
            $stmt = $db->prepare("SELECT setting_value FROM mkt_settings WHERE setting_key = ?");
            $stmt->execute([$k]);
            $config[$k] = $stmt->fetchColumn() ?: '';
        }

        return $config;
    }

    /**
     * Subir imagen binaria a LinkedIn Media Asset
     */
    public static function uploadImage(string $imageLocalPath): ?string {
        $cfg = self::getConfig();
        $token = $cfg['linkedin_access_token'] ?? '';
        $orgUrn = $cfg['linkedin_org_urn'] ?? '';

        if (empty($token) || empty($orgUrn) || !file_exists($imageLocalPath)) {
            return null;
        }

        // Paso 1: Inicializar Upload en LinkedIn
        $initUrl = self::$apiBase . '/images?action=initializeUpload';
        $initPayload = [
            'initializeUploadRequest' => [
                'owner' => $orgUrn
            ]
        ];

        $ch = curl_init($initUrl);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'LinkedIn-Version: ' . self::$apiVersion,
            'X-Restli-Protocol-Version: 2.0.0'
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($initPayload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $initResp = curl_exec($ch);
        $initCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($initCode !== 200 || empty($initResp)) {
            error_log("LinkedIn init upload error ($initCode): $initResp");
            return null;
        }

        $initData = json_decode($initResp, true);
        $uploadUrl = $initData['value']['uploadUrl'] ?? '';
        $imageUrn = $initData['value']['image'] ?? '';

        if (empty($uploadUrl) || empty($imageUrn)) {
            return null;
        }

        // Paso 2: Subir el archivo binario al CDN de LinkedIn
        $imageData = file_get_contents($imageLocalPath);
        $chUp = curl_init($uploadUrl);
        curl_setopt($chUp, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Content-Type: image/jpeg'
        ]);
        curl_setopt($chUp, CURLOPT_PUT, true);
        curl_setopt($chUp, CURLOPT_POSTFIELDS, $imageData);
        curl_setopt($chUp, CURLOPT_RETURNTRANSFER, true);
        $upResp = curl_exec($chUp);
        $upCode = curl_getinfo($chUp, CURLINFO_HTTP_CODE);
        curl_close($chUp);

        if ($upCode >= 200 && $upCode < 300) {
            return $imageUrn; // Retorna ej: "urn:li:image:D4E10AQH..."
        }

        error_log("LinkedIn binary upload error ($upCode): $upResp");
        return null;
    }

    /**
     * Crear y despachar Post a la Página de Empresa en LinkedIn
     */
    public static function createOrganizationPost(string $text, ?string $imageLocalPath = null, ?string $landingUrl = null, ?string $title = null): array {
        $cfg = self::getConfig();
        $token = $cfg['linkedin_access_token'] ?? '';
        $orgUrn = $cfg['linkedin_org_urn'] ?? '';

        if (empty($token)) {
            return ['success' => false, 'error' => 'No hay Access Token de LinkedIn configurado. Vincule su cuenta primero.'];
        }
        if (empty($orgUrn)) {
            return ['success' => false, 'error' => 'No se ha configurado el URN de la Página de Empresa de Keystone.'];
        }

        // Subir imagen si viene provista
        $imageUrn = null;
        if (!empty($imageLocalPath) && file_exists($imageLocalPath)) {
            $imageUrn = self::uploadImage($imageLocalPath);
        }

        $postPayload = [
            'author' => $orgUrn,
            'commentary' => $text,
            'visibility' => 'PUBLIC',
            'distribution' => [
                'feedDistribution' => 'MAIN_FEED',
                'targetEntities' => [],
                'thirdPartyDistributionChannels' => []
            ],
            'lifecycleState' => 'PUBLISHED',
            'isReshareDisabledByAuthor' => false
        ];

        // Adjuntar Media (Imagen o Enlace)
        if (!empty($imageUrn)) {
            $postPayload['content'] = [
                'media' => [
                    'title' => $title ?: 'Keystone Advisors B2B Intelligence',
                    'id' => $imageUrn
                ]
            ];
        } elseif (!empty($landingUrl)) {
            $postPayload['content'] = [
                'article' => [
                    'source' => $landingUrl,
                    'title' => $title ?: 'Keystone Advisors'
                ]
            ];
        }

        $postUrl = self::$apiBase . '/posts';
        $ch = curl_init($postUrl);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'LinkedIn-Version: ' . self::$apiVersion,
            'X-Restli-Protocol-Version: 2.0.0'
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postPayload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headerStr = substr($response, 0, $headerSize);
        $bodyStr = substr($response, $headerSize);
        curl_close($ch);

        if ($httpCode === 201) {
            // LinkedIn devuelve el Post URN en la cabecera x-linkedin-id o x-restli-id
            preg_match('/x-restli-id:\s*([^\r\n]+)/i', $headerStr, $matches);
            $postUrn = $matches[1] ?? 'urn:li:share:published';

            return [
                'success' => true,
                'post_urn' => trim($postUrn),
                'message' => '¡Publicación despachada con éxito a la Página de LinkedIn de Keystone Advisors!'
            ];
        }

        return [
            'success' => false,
            'http_code' => $httpCode,
            'error' => "Error al publicar en LinkedIn (HTTP $httpCode): " . $bodyStr
        ];
    }
}
