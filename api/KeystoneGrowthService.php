<?php
/**
 * KeystoneGrowthService.php
 * Motor de Marketing Intelligence, Atribución Multicanal, Telemetría UX
 * y Generación Creativa con IA (Google Gemini 3.8 Flash + Cloudflare FLUX.1)
 *
 * Keystone Advisors - Solución SOL-0172
 */

class KeystoneGrowthService {

    private static ?PDO $db = null;
    private static string $dbFile = __DIR__ . '/../data/marketing_keystone.sqlite';

    // Credenciales oficiales validadas
    private static string $googleApiKey = 'AQ.Ab8RN6Kokak-uy_C3-zfgAEpApm5tNiG_4d-pDILVQ4oKuYllA';
    private static string $cfAccountId = 'cf9641882a84e35bbf80ae7681d7ee71';
    private static string $cfAiToken = 'cfut_X7T32a3GAFH9iSkdoVkFY4YOc1Vn84RqB178bS6Q3b09495d';
    private static string $groqApiKey = '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';

    public static function getDb(): PDO {
        if (self::$db === null) {
            $dir = dirname(self::$dbFile);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            self::$db = new PDO('sqlite:' . self::$dbFile);
            self::$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$db->exec("PRAGMA journal_mode = WAL;");
            self::initSchema();
        }
        return self::$db;
    }

    private static function initSchema(): void {
        $db = self::$db;

        // 1. Tenants / Unidades de negocio
        $db->exec("CREATE TABLE IF NOT EXISTS mkt_tenants (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slug TEXT UNIQUE NOT NULL,
            name TEXT NOT NULL,
            primary_domain TEXT NOT NULL,
            target_currency TEXT DEFAULT 'USD',
            is_active INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // 2. Catálogo central de campañas y anuncios
        $db->exec("CREATE TABLE IF NOT EXISTS mkt_campaigns (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_slug TEXT NOT NULL,
            platform TEXT NOT NULL,
            campaign_name TEXT NOT NULL,
            ad_group TEXT,
            ad_id TEXT,
            ad_headline TEXT,
            budget_allocated REAL DEFAULT 0,
            status TEXT DEFAULT 'ACTIVE',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // 3. Bitácora de clics y puntos de contacto (Touchpoints)
        $db->exec("CREATE TABLE IF NOT EXISTS mkt_touchpoints (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            visitor_uid TEXT NOT NULL,
            tenant_slug TEXT NOT NULL,
            session_id TEXT NOT NULL,
            platform TEXT NOT NULL,
            campaign_name TEXT,
            utm_medium TEXT,
            utm_term TEXT,
            utm_content TEXT,
            gclid TEXT,
            li_fat_id TEXT,
            fbclid TEXT,
            landing_url TEXT NOT NULL,
            referrer_url TEXT,
            ip_hash TEXT NOT NULL,
            user_country TEXT,
            device_type TEXT,
            browser TEXT,
            is_first_touch INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_mkt_touch_visitor ON mkt_touchpoints(visitor_uid)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_mkt_touch_campaign ON mkt_touchpoints(tenant_slug, campaign_name)");

        // 4. Telemetría UX e inferencia de intención de compra
        $db->exec("CREATE TABLE IF NOT EXISTS mkt_ux_intent (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            session_id TEXT NOT NULL,
            visitor_uid TEXT NOT NULL,
            tenant_slug TEXT NOT NULL,
            page_url TEXT NOT NULL,
            dwell_time_pricing_sec INTEGER DEFAULT 0,
            dwell_time_total_sec INTEGER DEFAULT 0,
            max_scroll_depth_pct INTEGER DEFAULT 0,
            search_queries_entered TEXT,
            faq_questions_clicked TEXT,
            copied_texts TEXT,
            simulator_interactions INTEGER DEFAULT 0,
            rage_clicks_detected INTEGER DEFAULT 0,
            inferred_intent_category TEXT,
            intent_score INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_mkt_intent_visitor ON mkt_ux_intent(visitor_uid)");

        // 5. Conversiones y facturación atribuida
        $db->exec("CREATE TABLE IF NOT EXISTS mkt_conversions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            visitor_uid TEXT NOT NULL,
            session_id TEXT NOT NULL,
            tenant_slug TEXT NOT NULL,
            conversion_type TEXT NOT NULL,
            lead_email TEXT,
            order_code TEXT,
            revenue_amount REAL DEFAULT 0,
            currency TEXT DEFAULT 'USD',
            attributed_first_campaign TEXT,
            attributed_first_platform TEXT,
            attributed_last_campaign TEXT,
            attributed_last_platform TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_mkt_conv_tenant ON mkt_conversions(tenant_slug)");

        // 6. Almacén de creativos generados por IA (Ad Studio)
        $db->exec("CREATE TABLE IF NOT EXISTS mkt_ai_creatives (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_slug TEXT NOT NULL,
            platform TEXT NOT NULL,
            topic TEXT NOT NULL,
            headlines_json TEXT,
            descriptions_json TEXT,
            video_script_text TEXT,
            banner_image_path TEXT,
            banner_prompt TEXT,
            model_used TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Poblar tenants base si está vacía
        $count = (int)$db->query("SELECT COUNT(*) FROM mkt_tenants")->fetchColumn();
        if ($count === 0) {
            $stmt = $db->prepare("INSERT INTO mkt_tenants (slug, name, primary_domain, target_currency) VALUES (?, ?, ?, ?)");
            $stmt->execute(['keystone', 'Keystone Advisors', 'https://www.keystoneadvisors.cl', 'USD']);
            $stmt->execute(['sinnotario', 'SinNotario.cl', 'https://sinnotario.cl', 'CLP']);
            $stmt->execute(['keyhub', 'KeyHub Suite', 'https://keyhub.keystoneadvisors.cl', 'USD']);
        }
    }

    // =========================================================================
    // INGESTA DE EVENTOS & TELEMETRÍA UX
    // =========================================================================

    public static function recordTouchpoint(array $payload): array {
        $db = self::getDb();

        $visitorUid = trim($payload['visitor_uid'] ?? '');
        if (!$visitorUid) {
            $visitorUid = bin2hex(random_bytes(16));
        }

        $tenantSlug = trim($payload['tenant_slug'] ?? 'keystone');
        $sessionId = trim($payload['session_id'] ?? bin2hex(random_bytes(12)));
        $platform = trim($payload['platform'] ?? 'direct');
        $campaign = trim($payload['campaign_name'] ?? '');
        $utmMedium = trim($payload['utm_medium'] ?? '');
        $utmTerm = trim($payload['utm_term'] ?? '');
        $utmContent = trim($payload['utm_content'] ?? '');
        $gclid = trim($payload['gclid'] ?? '');
        $liFatId = trim($payload['li_fat_id'] ?? '');
        $fbclid = trim($payload['fbclid'] ?? '');
        $landingUrl = trim($payload['landing_url'] ?? '/');
        $referrer = trim($payload['referrer_url'] ?? '');
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $ipHash = hash('sha256', $ip . '_keystone_growth_salt_2026');
        $device = self::detectDevice($_SERVER['HTTP_USER_AGENT'] ?? '');
        $browser = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 100);

        // Detectar si es primer impacto
        $stmtChk = $db->prepare("SELECT COUNT(*) FROM mkt_touchpoints WHERE visitor_uid = ?");
        $stmtChk->execute([$visitorUid]);
        $isFirst = ((int)$stmtChk->fetchColumn() === 0) ? 1 : 0;

        $stmt = $db->prepare("INSERT INTO mkt_touchpoints 
            (visitor_uid, tenant_slug, session_id, platform, campaign_name, utm_medium, utm_term, utm_content, gclid, li_fat_id, fbclid, landing_url, referrer_url, ip_hash, user_country, device_type, browser, is_first_touch)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'CL', ?, ?, ?)");
        $stmt->execute([$visitorUid, $tenantSlug, $sessionId, $platform, $campaign, $utmMedium, $utmTerm, $utmContent, $gclid, $liFatId, $fbclid, $landingUrl, $referrer, $ipHash, $device, $browser, $isFirst]);

        return [
            'success' => true,
            'visitor_uid' => $visitorUid,
            'session_id' => $sessionId,
            'is_first_touch' => (bool)$isFirst
        ];
    }

    public static function recordUxIntent(array $data): array {
        $db = self::getDb();

        $sessionId = trim($data['session_id'] ?? '');
        $visitorUid = trim($data['visitor_uid'] ?? '');
        $tenantSlug = trim($data['tenant_slug'] ?? 'keystone');
        $pageUrl = trim($data['page_url'] ?? '/');
        $dwellPricing = (int)($data['dwell_time_pricing_sec'] ?? 0);
        $dwellTotal = (int)($data['dwell_time_total_sec'] ?? 0);
        $scrollDepth = min(100, max(0, (int)($data['max_scroll_depth_pct'] ?? 0)));
        $searches = isset($data['search_queries']) ? json_encode($data['search_queries'], JSON_UNESCAPED_UNICODE) : null;
        $faqs = isset($data['faq_questions']) ? json_encode($data['faq_questions'], JSON_UNESCAPED_UNICODE) : null;
        $copied = isset($data['copied_texts']) ? json_encode($data['copied_texts'], JSON_UNESCAPED_UNICODE) : null;
        $simulator = (int)($data['simulator_interactions'] ?? 0);
        $rage = (int)($data['rage_clicks_detected'] ?? 0);

        // Algoritmo de Inferencia de Intención (Buyer Intent Score 0 a 100)
        $score = (int)round(
            (min(60, $dwellPricing) / 60.0 * 35) +
            ($scrollDepth / 100.0 * 20) +
            (min(5, count($data['faq_questions'] ?? [])) / 5.0 * 20) +
            (min(4, $simulator + (isset($data['copied_texts']) ? 2 : 0)) / 4.0 * 25) -
            (min(3, $rage) * 5)
        );
        $score = max(0, min(100, $score));

        // Categoría inferida
        $category = 'EVALUACION_GENERAL';
        if ($dwellPricing > 25) {
            $category = 'PRICE_SENSITIVE';
        } elseif (!empty($data['faq_questions'])) {
            $category = 'LEGAL_COMPLIANCE_CONCERN';
        } elseif ($simulator > 0) {
            $category = 'HIGH_INTENT_SCALING';
        }

        $stmt = $db->prepare("INSERT INTO mkt_ux_intent 
            (session_id, visitor_uid, tenant_slug, page_url, dwell_time_pricing_sec, dwell_time_total_sec, max_scroll_depth_pct, search_queries_entered, faq_questions_clicked, copied_texts, simulator_interactions, rage_clicks_detected, inferred_intent_category, intent_score)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$sessionId, $visitorUid, $tenantSlug, $pageUrl, $dwellPricing, $dwellTotal, $scrollDepth, $searches, $faqs, $copied, $simulator, $rage, $category, $score]);

        return [
            'success' => true,
            'intent_score' => $score,
            'intent_category' => $category
        ];
    }

    public static function recordConversion(array $conv): array {
        $db = self::getDb();

        $visitorUid = trim($conv['visitor_uid'] ?? '');
        $sessionId = trim($conv['session_id'] ?? '');
        $tenantSlug = trim($conv['tenant_slug'] ?? 'keystone');
        $type = trim($conv['conversion_type'] ?? 'LEAD_FORM');
        $email = trim($conv['lead_email'] ?? '');
        $orderCode = trim($conv['order_code'] ?? '');
        $revenue = (float)($conv['revenue_amount'] ?? 0.0);
        $currency = trim($conv['currency'] ?? 'USD');

        // Atribución First-Touch y Last-Touch
        $stmtFirst = $db->prepare("SELECT platform, campaign_name FROM mkt_touchpoints WHERE visitor_uid = ? ORDER BY id ASC LIMIT 1");
        $stmtFirst->execute([$visitorUid]);
        $first = $stmtFirst->fetch(PDO::FETCH_ASSOC) ?: ['platform' => 'direct', 'campaign_name' => 'direct'];

        $stmtLast = $db->prepare("SELECT platform, campaign_name FROM mkt_touchpoints WHERE visitor_uid = ? ORDER BY id DESC LIMIT 1");
        $stmtLast->execute([$visitorUid]);
        $last = $stmtLast->fetch(PDO::FETCH_ASSOC) ?: ['platform' => 'direct', 'campaign_name' => 'direct'];

        $stmt = $db->prepare("INSERT INTO mkt_conversions 
            (visitor_uid, session_id, tenant_slug, conversion_type, lead_email, order_code, revenue_amount, currency, attributed_first_campaign, attributed_first_platform, attributed_last_campaign, attributed_last_platform)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$visitorUid, $sessionId, $tenantSlug, $type, $email, $orderCode, $revenue, $currency, $first['campaign_name'], $first['platform'], $last['campaign_name'], $last['platform']]);

        return [
            'success' => true,
            'conversion_id' => (int)$db->lastInsertId(),
            'first_touch_platform' => $first['platform'],
            'last_touch_platform' => $last['platform']
        ];
    }

    // =========================================================================
    // MOTOR CREATIVO IA (GEMINI 3.8 FLASH + CLOUDFLARE FLUX.1)
    // =========================================================================

    public static function generateAdCopy(string $tenantSlug, string $topic, string $targetAudience, string $targetPlatform = 'all'): array {
        $tenantContexts = [
            'keystone_salesforce' => 'Keystone Advisors (Consultoría líder en Salesforce Financial Services Cloud, Core CRM, Integraciones complejas y LWC para Banca y Fintech)',
            'keystone_deepshield' => 'Keystone Advisors - DeepShield (Solución de ciberseguridad perimetral, DMARC Enforcement, prevención de fraude BEC y cumplimiento Ley N° 21.459)',
            'keystone_cloud' => 'Keystone Advisors (Arquitectura Cloud Empresarial, Kubernetes, Resiliencia y Modernización de Sistemas Core)',
            'sinnotario' => 'SinNotario.cl (Plataforma pionera en Chile de trámites legales, redacción asistida y firma electrónica avanzada con 100% validez legal y certificación)',
            'keyhub' => 'KeyHub Suite (SaaS corporativo para consultoras: control de rentabilidad de proyectos, tracking de horas, imputación financiera y tableros Kanban)'
        ];
        $tenantContext = $tenantContexts[$tenantSlug] ?? ("Keystone Advisors / " . $tenantSlug);

        $platformDirectives = [
            'linkedin_company' => "Enfoque primordial: Publicación formal para la Página de Empresa oficial en LinkedIn. Debe sonar altamente ejecutivo, reflexivo, con autoridad técnica B2B y 3 hashtags estratégicos.",
            'google_ads' => "Enfoque primordial: Campañas Search de alta intención en Google Ads. Titulares con alto CTR y descripciones que resuelvan objeciones directas.",
            'meta_ads' => "Enfoque primordial: Redes visuales Meta (Instagram/Facebook). Tono ágil, gancho visual rápido y lenguaje claro.",
            'email_b2b' => "Enfoque primordial: Correo de prospección en frío (Cold Outbound) de 3 párrafos cortos para Directores y Gerentes."
        ];
        $platformDirective = $platformDirectives[$targetPlatform] ?? "Enfoque: Campaña multicanal 360 (Google Search, LinkedIn Empresa, Meta y Video Script).";

        $prompt = "Eres el Director Senior de Growth Marketing y Estrategia B2B para: {$tenantContext}.\n" .
                  "Tema / Objetivo Comercial: {$topic}.\n" .
                  "Público objetivo / Buyer Persona: {$targetAudience}.\n" .
                  "Directriz de Plataforma: {$platformDirective}\n\n" .
                  "Genera en formato JSON estructurado:\n" .
                  "1. 'google_headlines': Lista de exactamente 5 titulares para Google Ads (CADA UNO DE MÁXIMO 30 CARACTERES sin excepciones).\n" .
                  "2. 'google_descriptions': Lista de exactamente 3 descripciones para Google Ads (CADA UNA DE MÁXIMO 90 CARACTERES sin excepciones).\n" .
                  "3. 'linkedin_post': Texto persuasivo de alta conversión para LinkedIn (120-180 palabras, con gancho inicial, cifras o argumentos de valor, llamada a la acción clara y 3 hashtags al final).\n" .
                  "4. 'video_script_30s': Objeto con las claves 'gancho_0_5s', 'problema_5_15s', 'solucion_15_25s' y 'llamado_a_la_accion_25_30s'.\n" .
                  "Responde ÚNICAMENTE el objeto JSON sin delimitadores de código markdown.";

        // =========================================================================
        // PRIMERA OPCIÓN: GROQ CLOUD LPU (Ultra Baja Latencia & Costo $0)
        // =========================================================================
        if (!empty(self::$groqApiKey)) {
            $groqUrl = "https://api.groq.com/openai/v1/chat/completions";
            $groqPayload = [
                'model' => 'qwen/qwen3.8-27b',
                'messages' => [
                    ['role' => 'system', 'content' => 'Eres un estratega experto en marketing B2B y copywriting para anuncios. Devuelve únicamente JSON válido.'],
                    ['role' => 'user', 'content' => $prompt]
                ],
                'temperature' => 0.2,
                'max_tokens' => 950
            ];

            $chG = curl_init($groqUrl);
            curl_setopt($chG, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . self::$groqApiKey,
                'Content-Type: application/json'
            ]);
            curl_setopt($chG, CURLOPT_POST, true);
            curl_setopt($chG, CURLOPT_POSTFIELDS, json_encode($groqPayload));
            curl_setopt($chG, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($chG, CURLOPT_TIMEOUT, 15);

            $groqResp = curl_exec($chG);
            $groqCode = curl_getinfo($chG, CURLINFO_HTTP_CODE);
            curl_close($chG);

            if ($groqCode === 200 && !empty($groqResp)) {
                $groqJson = json_decode($groqResp, true);
                $rawGroq = $groqJson['choices'][0]['message']['content'] ?? '';
                $cleanGroq = preg_replace('/^```json\s*/i', '', trim($rawGroq));
                $cleanGroq = preg_replace('/^```\s*/i', '', $cleanGroq);
                $cleanGroq = preg_replace('/\s*```$/i', '', $cleanGroq);

                $parsedGroq = json_decode($cleanGroq, true);
                if ($parsedGroq && !empty($parsedGroq['google_headlines'])) {
                    return [
                        'success' => true,
                        'provider' => 'Groq Cloud (qwen/qwen3.8-27b LPU)',
                        'data' => $parsedGroq
                    ];
                }
            }
        }

        // =========================================================================
        // SEGUNDA OPCIÓN (FALLBACK): GOOGLE GEMINI FLASH
        // =========================================================================

        $models = ['gemini-3.5-flash', 'gemini-3.7-flash', 'gemini-3.8-flash', 'gemini-2.5-flash', 'gemini-flash-latest'];
        $response = null;
        $httpCode = 0;
        $lastError = '';

        foreach ($models as $model) {
            $attempts = 0;
            while ($attempts < 2) {
                $attempts++;
                $url = "https://generativelanguage.googleapis.com/v1beta/models/" . $model . ":generateContent?key=" . self::$googleApiKey;
                $payload = [
                    'contents' => [
                        ['parts' => [['text' => $prompt]]]
                    ]
                ];

                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 25);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode === 200 && !empty($response)) {
                    break 2; // Éxito con este modelo
                }

                if ($httpCode === 503 || $httpCode === 429) {
                    usleep(600000); // Pausa breve de 600ms ante indisponibilidad temporal
                } else {
                    break; // Error de cliente o modelo no disponible, saltar al siguiente modelo
                }
            }
        }

        if ($httpCode !== 200 || !$response) {
            return [
                'success' => false,
                'error' => 'Error al invocar Google Gemini API (HTTP ' . $httpCode . '): ' . substr($response, 0, 300)
            ];
        }

        $resJson = json_decode($response, true);
        $rawText = $resJson['candidates'][0]['content']['parts'][0]['text'] ?? '';

        // Limpiar posibles bloques ```json ... ```
        $rawText = preg_replace('/^```json\s*/i', '', trim($rawText));
        $rawText = preg_replace('/^```\s*/i', '', $rawText);
        $rawText = preg_replace('/\s*```$/i', '', $rawText);

        $parsed = json_decode($rawText, true);
        if (!$parsed) {
            return [
                'success' => true,
                'raw_text' => $rawText
            ];
        }

        return [
            'success' => true,
            'data' => $parsed
        ];
    }

    public static function generateBannerFlux(string $tenantSlug, string $topic, string $styleModifier = ''): array {
        $prompt = "A high-end corporate advertisement banner for {$tenantSlug}. Theme: {$topic}. " .
                  "Palette: corporate deep navy blue #003366, elegant charcoal and glowing cyan neon #00b4d8 accents. " .
                  "Photorealistic, 8k resolution, minimalist sleek tech aesthetic, professional B2B marketing visual. " . $styleModifier;

        $url = "https://api.cloudflare.com/client/v4/accounts/" . self::$cfAccountId . "/ai/run/@cf/black-forest-labs/flux-1-schnell";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . self::$cfAiToken,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['prompt' => $prompt]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 45);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            return [
                'success' => false,
                'error' => 'Error al generar imagen con Cloudflare FLUX.1 (HTTP ' . $httpCode . '): ' . substr($response, 0, 300)
            ];
        }

        $resJson = json_decode($response, true);
        $imgB64 = $resJson['result']['image'] ?? null;

        if (!$imgB64) {
            return [
                'success' => false,
                'error' => 'No se recibió imagen en la respuesta de Cloudflare'
            ];
        }

        // Guardar imagen en data/creatives/
        $creativesDir = __DIR__ . '/../assets/creatives';
        if (!is_dir($creativesDir)) {
            @mkdir($creativesDir, 0755, true);
        }

        $filename = 'banner_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $tenantSlug) . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.jpg';
        $fullPath = $creativesDir . '/' . $filename;
        file_put_contents($fullPath, base64_decode($imgB64));

        $publicUrl = '/assets/creatives/' . $filename;

        // Registrar en base de datos
        $db = self::getDb();
        $stmt = $db->prepare("INSERT INTO mkt_ai_creatives (tenant_slug, platform, topic, banner_image_path, banner_prompt, model_used)
                              VALUES (?, 'MULTI_PLATFORM', ?, ?, ?, 'Cloudflare FLUX.1 Schnell')");
        $stmt->execute([$tenantSlug, $topic, $publicUrl, $prompt]);

        return [
            'success' => true,
            'image_url' => $publicUrl,
            'file_name' => $filename,
            'prompt' => $prompt
        ];
    }

    // =========================================================================
    // MÉTRICAS Y DASHBOARD EJECUTIVO
    // =========================================================================

    public static function getMarketingMetrics(?string $tenantSlug = null): array {
        $db = self::getDb();

        $whereTenant = $tenantSlug ? "WHERE tenant_slug = " . $db->quote($tenantSlug) : "";
        $whereTenantAnd = $tenantSlug ? "AND tenant_slug = " . $db->quote($tenantSlug) : "";

        // 1. Totales generales
        $totalTouches = (int)$db->query("SELECT COUNT(*) FROM mkt_touchpoints $whereTenant")->fetchColumn();
        $totalUniqueVisitors = (int)$db->query("SELECT COUNT(DISTINCT visitor_uid) FROM mkt_touchpoints $whereTenant")->fetchColumn();
        $totalConversions = (int)$db->query("SELECT COUNT(*) FROM mkt_conversions $whereTenant")->fetchColumn();
        $totalRevenue = (float)$db->query("SELECT SUM(revenue_amount) FROM mkt_conversions $whereTenant")->fetchColumn();
        $avgIntentScore = (int)$db->query("SELECT AVG(intent_score) FROM mkt_ux_intent $whereTenant")->fetchColumn();

        // 2. Rendimiento por red publicitaria
        $sqlPlatforms = "SELECT 
            platform, 
            COUNT(*) as total_clicks,
            COUNT(DISTINCT visitor_uid) as unique_users
            FROM mkt_touchpoints 
            $whereTenant
            GROUP BY platform
            ORDER BY total_clicks DESC";
        $platformsData = $db->query($sqlPlatforms)->fetchAll(PDO::FETCH_ASSOC);

        // Cruce de conversiones por plataforma
        foreach ($platformsData as &$plat) {
            $pName = $plat['platform'];
            $stmtC = $db->prepare("SELECT COUNT(*) as convs, SUM(revenue_amount) as rev FROM mkt_conversions WHERE (attributed_first_platform = ? OR attributed_last_platform = ?) $whereTenantAnd");
            $stmtC->execute([$pName, $pName]);
            $cRow = $stmtC->fetch(PDO::FETCH_ASSOC);
            $plat['conversions'] = (int)($cRow['convs'] ?? 0);
            $plat['revenue'] = (float)($cRow['rev'] ?? 0.0);
            $plat['conv_rate'] = $plat['total_clicks'] > 0 ? round(($plat['conversions'] / $plat['total_clicks']) * 100, 1) : 0;
        }

        // 3. Radar de Objeciones UX (Inferencia Pre-Compra)
        $sqlIntent = "SELECT inferred_intent_category, COUNT(*) as count, AVG(intent_score) as avg_score
            FROM mkt_ux_intent 
            $whereTenant
            GROUP BY inferred_intent_category
            ORDER BY count DESC";
        $intentBreakdown = $db->query($sqlIntent)->fetchAll(PDO::FETCH_ASSOC);

        // 4. Últimos creativos generados por IA
        $sqlCreatives = "SELECT * FROM mkt_ai_creatives $whereTenant ORDER BY id DESC LIMIT 6";
        $recentCreatives = $db->query($sqlCreatives)->fetchAll(PDO::FETCH_ASSOC);

        return [
            'total_clicks' => $totalTouches,
            'unique_visitors' => $totalUniqueVisitors,
            'total_conversions' => $totalConversions,
            'total_revenue' => $totalRevenue,
            'avg_intent_score' => $avgIntentScore,
            'platforms' => $platformsData,
            'intent_breakdown' => $intentBreakdown,
            'recent_creatives' => $recentCreatives
        ];
    }

    private static function detectDevice(string $ua): string {
        if (preg_match('/(mobile|android|iphone|ipad|phone)/i', $ua)) {
            return 'MOBILE';
        }
        if (preg_match('/(tablet|ipad)/i', $ua)) {
            return 'TABLET';
        }
        return 'DESKTOP';
    }
}
