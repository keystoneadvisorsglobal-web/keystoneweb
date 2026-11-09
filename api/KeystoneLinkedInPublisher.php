<?php
/**
 * Keystone Advisors - KeystoneLinkedInPublisher
 * Integración oficial con LinkedIn Community Management & Rest API
 * Compatible con versión RestLi 202610
 */

class KeystoneLinkedInPublisher {

    private static string $apiBase = 'https://api.linkedin.com/rest';
    private static string $apiVersion = '202610'; // LinkedIn Rest-Version activa

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
            'linkedin_member_urn',
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
     * Obtener URN de Miembro (persona) a través de userinfo
     */
    public static function getMemberUrn(string $token): ?string {
        require_once __DIR__ . '/KeystoneGrowthService.php';
        $db = KeystoneGrowthService::getDb();
        $stored = $db->query("SELECT setting_value FROM mkt_settings WHERE setting_key = 'linkedin_member_urn'")->fetchColumn();
        if (!empty($stored)) {
            return $stored;
        }

        $ch = curl_init("https://api.linkedin.com/v2/userinfo");
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . $token]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200 && !empty($res)) {
            $json = json_decode($res, true);
            if (!empty($json['sub'])) {
                $memberUrn = "urn:li:person:" . $json['sub'];
                $stmt = $db->prepare("INSERT OR REPLACE INTO mkt_settings (setting_key, setting_value, updated_at) VALUES ('linkedin_member_urn', ?, CURRENT_TIMESTAMP)");
                $stmt->execute([$memberUrn]);
                return $memberUrn;
            }
        }
        return null;
    }

    /**
     * Subir imagen binaria a LinkedIn Media Asset
     */
    public static function uploadImage(string $imageLocalPath, string $authorUrn): ?string {
        $cfg = self::getConfig();
        $token = $cfg['linkedin_access_token'] ?? '';

        if (empty($token) || empty($authorUrn) || !file_exists($imageLocalPath)) {
            return null;
        }

        // Paso 1: Inicializar Upload en LinkedIn
        $initUrl = self::$apiBase . '/images?action=initializeUpload';
        $initPayload = [
            'initializeUploadRequest' => [
                'owner' => $authorUrn
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

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return null;
        }

        $resJson = json_decode($response, true);
        $uploadUrl = $resJson['value']['uploadUrl'] ?? null;
        $imageUrn = $resJson['value']['image'] ?? null;

        if (!$uploadUrl || !$imageUrn) {
            return null;
        }

        // Paso 2: Subir archivo binario
        $imgBinary = file_get_contents($imageLocalPath);
        $mimeType = mime_content_type($imageLocalPath) ?: 'image/jpeg';

        $chUp = curl_init($uploadUrl);
        curl_setopt($chUp, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Content-Type: ' . $mimeType
        ]);
        curl_setopt($chUp, CURLOPT_PUT, true);
        curl_setopt($chUp, CURLOPT_POSTFIELDS, $imgBinary);
        curl_setopt($chUp, CURLOPT_RETURNTRANSFER, true);

        $upResp = curl_exec($chUp);
        $upCode = curl_getinfo($chUp, CURLINFO_HTTP_CODE);
        curl_close($chUp);

        if ($upCode >= 200 && $upCode < 300) {
            return $imageUrn;
        }

        return null;
    }

    /**
     * Crear publicación en LinkedIn con resolución automática de autoría
     */
    public static function createOrganizationPost(string $text, ?string $imageLocalPath = null, ?string $landingUrl = null, ?string $title = null): array {
        $cfg = self::getConfig();
        $token = $cfg['linkedin_access_token'] ?? '';
        $orgUrn = $cfg['linkedin_org_urn'] ?? '';

        if (empty($token)) {
            return ['success' => false, 'error' => 'No hay Access Token de LinkedIn configurado. Vincule su cuenta primero.'];
        }

        $memberUrn = self::getMemberUrn($token);

        // Estrategia de intento: Primero como Organización; si la App no tiene el permiso w_organization_social, publicar como Fundador / Administrador
        $candidateAuthors = [];
        if (!empty($orgUrn)) {
            $candidateAuthors[] = $orgUrn;
        }
        if (!empty($memberUrn) && $memberUrn !== $orgUrn) {
            $candidateAuthors[] = $memberUrn;
        }

        if (empty($candidateAuthors)) {
            return ['success' => false, 'error' => 'No se encontró un URN de autor válido (ni Organización ni Perfil personal).'];
        }

        $lastError = '';
        $postUrl = self::$apiBase . '/posts';

        foreach ($candidateAuthors as $authorUrn) {
            // Subir imagen para este author
            $imageUrn = null;
            if (!empty($imageLocalPath) && file_exists($imageLocalPath)) {
                $imageUrn = self::uploadImage($imageLocalPath, $authorUrn);
            }

            $postPayload = [
                'author' => $authorUrn,
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
                $postUrn = null;
                if (preg_match('/x-restli-id:\s*([^\r\n]+)/i', $headerStr, $m)) {
                    $postUrn = trim($m[1]);
                } elseif (preg_match('/location:\s*([^\r\n]+)/i', $headerStr, $m)) {
                    $postUrn = urldecode(basename(trim($m[1])));
                }

                // Guardar en bitácora de SQLite
                require_once __DIR__ . '/KeystoneGrowthService.php';
                $db = KeystoneGrowthService::getDb();
                $stmtIns = $db->prepare("INSERT INTO mkt_ai_creatives 
                    (tenant_slug, platform, topic, headlines_json, descriptions_json, video_script_text, banner_image_path, model_used, created_at)
                    VALUES (?, 'linkedin_published', ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)");
                $stmtIns->execute([
                    strpos($authorUrn, 'organization') !== false ? 'keystone_org' : 'keystone_founder',
                    $title ?: 'Publicación LinkedIn',
                    json_encode([$title]),
                    json_encode([$postUrn]),
                    $text,
                    $imageLocalPath ?: '',
                    'LinkedIn Rest API ' . self::$apiVersion . " ($authorUrn)"
                ]);

                return [
                    'success' => true,
                    'post_urn' => $postUrn,
                    'author_used' => $authorUrn,
                    'is_organization' => (strpos($authorUrn, 'organization') !== false),
                    'message' => '¡Publicación enviada exitosamente a LinkedIn!'
                ];
            }

            $lastError = "HTTP $httpCode: " . $bodyStr;
            // Si el error fue de permisos de Organización (400 / 403), el bucle continúa e intenta con Member
        }

        return [
            'success' => false,
            'error' => 'Error al publicar en LinkedIn: ' . $lastError
        ];
    }
}