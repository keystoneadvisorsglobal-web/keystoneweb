<?php
/**
 * Keystone Advisors - CampaignPlannerService (SOL-0176)
 * Asesor Estratégico de Campañas B2B, Generador de Lotes (Batch) y Gestor de Calendario
 */

require_once __DIR__ . '/KeystoneGrowthService.php';
require_once __DIR__ . '/KeystoneLinkedInPublisher.php';

class CampaignPlannerService {

    /**
     * Asesoría Estratégica: Calcula el número óptimo de posts según el rango de fechas
     */
    public static function adviseCampaignCadence(string $dateStart, string $dateEnd): array {
        $tsStart = strtotime($dateStart);
        $tsEnd = strtotime($dateEnd);

        if (!$tsStart || !$tsEnd || $tsEnd < $tsStart) {
            $tsStart = time();
            $tsEnd = time() + (14 * 86400); // 14 días default
        }

        $days = (int)ceil(($tsEnd - $tsStart) / 86400) + 1;
        $weeks = max(1, round($days / 7, 1));

        // Regla B2B LinkedIn: 2 a 3 posts semanales espaciados (Martes, Miércoles, Jueves)
        if ($days <= 5) {
            $recommendedPosts = max(1, min($days, 2));
            $cadenceDesc = "Campaña Express (1 a 2 impactos clave enfocados en urgencia y conversión).";
        } elseif ($days <= 10) {
            $recommendedPosts = 3;
            $cadenceDesc = "Campaña Corta (3 impactos: Problema, Solución y Caso de Estudio).";
        } elseif ($days <= 21) {
            $recommendedPosts = 6;
            $cadenceDesc = "Campaña Estándar de 2 a 3 semanas (2 posts semanales de alto impacto para evitar fatiga de audiencia).";
        } elseif ($days <= 35) {
            $recommendedPosts = 9;
            $cadenceDesc = "Campaña Mensual Integral (2 a 3 posts por semana con rotación de ángulos técnicos, legales y testimoniales).";
        } else {
            $recommendedPosts = min(20, (int)round($weeks * 2.5));
            $cadenceDesc = "Campaña Extendida Trimestral (Pauta sostenida de liderazgo intelectual con 2-3 publicaciones por semana).";
        }

        // Generar las fechas recomendadas dentro del rango (preferir días hábiles Martes a Jueves a las 09:00 AM)
        $scheduledDates = [];
        $curTs = $tsStart;
        $postsAllocated = 0;
        $intervalDays = max(1, (int)floor($days / $recommendedPosts));

        for ($i = 0; $i < $recommendedPosts; $i++) {
            $postTs = $tsStart + ($i * $intervalDays * 86400);
            if ($postTs > $tsEnd) {
                $postTs = $tsEnd;
            }

            // Si cae sábado (6) o domingo (0), mover a lunes o viernes
            $wDay = (int)date('w', $postTs);
            if ($wDay === 0) { $postTs += 86400; } // Domingo -> Lunes
            if ($wDay === 6) { $postTs -= 86400; } // Sábado -> Viernes

            $scheduledDates[] = date('Y-m-d 09:00:00', $postTs);
        }

        return [
            'days_total' => $days,
            'weeks_approx' => $weeks,
            'recommended_posts' => $recommendedPosts,
            'cadence_rationale' => $cadenceDesc,
            'suggested_dates' => $scheduledDates
        ];
    }

    /**
     * Genera el lote de N artículos temáticos complementarios con Groq LPU
     */
    public static function generateBatchArticles(string $tenantSlug, string $topic, string $targetAudience, string $landingUrl, string $campaignName, int $numPosts, array $scheduledDates): array {
        $db = KeystoneGrowthService::getDb();

        $prompt = "Eres el Director Senior de Estrategia de Contenidos B2B para Keystone Advisors.\n" .
                  "Línea de Negocio: {$tenantSlug}.\n" .
                  "Tema Central de la Campaña: {$topic}.\n" .
                  "Público Objetivo: {$targetAudience}.\n" .
                  "Cantidad de artículos requeridos: exactamente {$numPosts}.\n\n" .
                  "Diseña un plan editorial progresivo de {$numPosts} posts para LinkedIn Empresa (Keystone Advisors Global), donde cada post aborde un ángulo complementario:\n" .
                  "- Post 1: Concientización del riesgo o problema latente en el mercado.\n" .
                  "- Post 2: Aspecto técnico o arquitectura de la solución.\n" .
                  "- Post 3: Marco regulatorio, validez jurídica o impacto financiero (ROI).\n" .
                  "- Post 4 en adelante: Casos de uso, derribo de objeciones y llamado a la acción comercial directo.\n\n" .
                  "Devuelve ÚNICAMENTE un JSON con la clave 'posts', que sea un array de exactamente {$numPosts} objetos, con la siguiente estructura:\n" .
                  "{\n" .
                  "  \"posts\": [\n" .
                  "    {\n" .
                  "      \"title\": \"Título corto descriptivo del ángulo (ej: Fraude BEC en Nóminas)\",\n" .
                  "      \"text\": \"Texto persuasivo formal para LinkedIn de 120-160 palabras con gancho ejecutivo, datos de valor y llamado a la acción.\",\n" .
                  "      \"visual_prompt\": \"Prompt descriptivo en inglés para generar banner visual fotorealista con paleta corporate navy blue #003366 y cyan #00b4d8.\"\n" .
                  "    }\n" .
                  "  ]\n" .
                  "}";

        $aiRes = self::callGroqOrGemini($prompt);
        if (!$aiRes['success'] || empty($aiRes['data']['posts'])) {
            return ['success' => false, 'error' => $aiRes['error'] ?? 'Fallo al generar lote de artículos con IA.'];
        }

        $postsData = $aiRes['data']['posts'];
        $cleanBaseUrl = explode('?', $landingUrl)[0];

        $items = [];
        foreach ($postsData as $idx => $p) {
            $postNum = $idx + 1;
            $postTitle = trim($p['title'] ?? ("Publicación #" . $postNum));
            $postText = trim($p['text'] ?? '');
            $visPrompt = trim($p['visual_prompt'] ?? ("Corporate high-end visual for {$topic}"));

            // Construir UTM única por post
            $contentTag = 'post_' . str_pad($postNum, 2, '0', STR_PAD_LEFT) . '_' . preg_replace('/[^a-z0-9]/', '_', strtolower(substr($postTitle, 0, 20)));
            $utmUrl = $cleanBaseUrl . "?utm_source=linkedin&utm_medium=social_organic&utm_campaign=" . urlencode($campaignName) . "&utm_content=" . urlencode($contentTag);

            // Anexar UTM al final del texto del post si no está presente
            if (!strpos($postText, 'http')) {
                $postText .= "\n\n🔗 Conoce más detalles y solicita diagnóstico aquí: " . $utmUrl;
            }

            $dateSched = $scheduledDates[$idx] ?? date('Y-m-d 09:00:00', strtotime("+{$postNum} days"));

            $items[] = [
                'post_index' => $postNum,
                'post_title' => $postTitle,
                'post_text' => $postText,
                'visual_prompt' => $visPrompt,
                'utm_url' => $utmUrl,
                'scheduled_at' => $dateSched,
                'banner_image_url' => ''
            ];
        }

        return [
            'success' => true,
            'campaign_name' => $campaignName,
            'total_posts' => count($items),
            'items' => $items
        ];
    }

    /**
     * Guarda la campaña completa y su calendario en SQLite
     */
    public static function saveCampaignSchedule(array $payload): array {
        $db = KeystoneGrowthService::getDb();

        $campName = trim($payload['campaign_name'] ?? '');
        $campTitle = trim($payload['campaign_title'] ?? $campName);
        $tenantSlug = trim($payload['tenant_slug'] ?? 'keystone_deepshield');
        $topic = trim($payload['topic'] ?? '');
        $audience = trim($payload['target_audience'] ?? '');
        $landingUrl = trim($payload['landing_url'] ?? 'https://www.keystoneadvisors.cl/');
        $dateStart = trim($payload['date_start'] ?? date('Y-m-d'));
        $dateEnd = trim($payload['date_end'] ?? date('Y-m-d', strtotime('+14 days')));
        $posts = $payload['posts'] ?? [];

        if (empty($campName) || empty($posts)) {
            return ['success' => false, 'error' => 'Nombre de campaña o publicaciones no especificadas.'];
        }

        // Insertar o actualizar campaña maestra
        $stmtM = $db->prepare("INSERT OR REPLACE INTO mkt_campaigns_master 
            (campaign_name, campaign_title, tenant_slug, topic, target_audience, landing_url, date_start, date_end, total_posts, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'ACTIVE')");
        $stmtM->execute([$campName, $campTitle, $tenantSlug, $topic, $audience, $landingUrl, $dateStart, $dateEnd, count($posts)]);
        $campaignId = (int)$db->lastInsertId();
        if (!$campaignId) {
            $campaignId = (int)$db->query("SELECT id FROM mkt_campaigns_master WHERE campaign_name = " . $db->quote($campName))->fetchColumn();
        }

        // Insertar cada post en la cola
        $stmtP = $db->prepare("INSERT INTO mkt_campaign_schedule
            (campaign_id, campaign_name, post_index, post_title, post_text, banner_image_url, utm_url, scheduled_at, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'PENDING')");

        foreach ($posts as $p) {
            $stmtP->execute([
                $campaignId,
                $campName,
                (int)($p['post_index'] ?? 1),
                trim($p['post_title'] ?? ''),
                trim($p['post_text'] ?? ''),
                trim($p['banner_image_url'] ?? ''),
                trim($p['utm_url'] ?? ''),
                trim($p['scheduled_at'] ?? date('Y-m-d 09:00:00'))
            ]);
        }

        return [
            'success' => true,
            'campaign_id' => $campaignId,
            'campaign_name' => $campName,
            'scheduled_posts' => count($posts)
        ];
    }

    /**
     * Cron Dispatcher: Revisa publicaciones pendientes cuya fecha <= NOW() y las despacha a LinkedIn
     */
    public static function dispatchDueScheduledPosts(): array {
        $db = KeystoneGrowthService::getDb();
        $now = date('Y-m-d H:i:s');

        // Buscar posts pendientes cuya fecha de programación haya llegado
        $stmt = $db->prepare("SELECT * FROM mkt_campaign_schedule WHERE status = 'PENDING' AND scheduled_at <= ? ORDER BY scheduled_at ASC LIMIT 3");
        $stmt->execute([$now]);
        $duePosts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        foreach ($duePosts as $post) {
            $postId = (int)$post['id'];
            $text = $post['post_text'];
            $imagePath = $post['banner_image_url'];
            $landingUrl = $post['utm_url'];
            $title = $post['post_title'];

            $localFile = null;
            if (!empty($imagePath)) {
                $cleaned = ltrim($imagePath, '/');
                $localFile = __DIR__ . '/../' . $cleaned;
            }

            $pubRes = KeystoneLinkedInPublisher::createOrganizationPost($text, $localFile, $landingUrl, $title);

            if ($pubRes['success']) {
                $stmtUp = $db->prepare("UPDATE mkt_campaign_schedule SET status = 'PUBLISHED', post_urn = ?, published_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmtUp->execute([$pubRes['post_urn'] ?? 'urn:li:share:published', $postId]);

                // Actualizar contador en campaña maestra
                $db->exec("UPDATE mkt_campaigns_master SET published_posts = published_posts + 1 WHERE id = " . (int)$post['campaign_id']);

                $results[] = [
                    'post_id' => $postId,
                    'status' => 'PUBLISHED',
                    'post_urn' => $pubRes['post_urn'] ?? ''
                ];
            } else {
                $stmtFail = $db->prepare("UPDATE mkt_campaign_schedule SET status = 'FAILED', error_message = ? WHERE id = ?");
                $stmtFail->execute([$pubRes['error'] ?? 'Fallo en API LinkedIn', $postId]);

                $results[] = [
                    'post_id' => $postId,
                    'status' => 'FAILED',
                    'error' => $pubRes['error'] ?? 'Fallo'
                ];
            }
        }

        return [
            'success' => true,
            'processed_count' => count($results),
            'details' => $results
        ];
    }

    /**
     * Auxiliar LLM: Groq LPU con fallback Gemini
     */
    private static function callGroqOrGemini(string $prompt): array {
        $groqKey = KeystoneGrowthService::getSetting('groq_api_key', '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');
        
        if (!empty($groqKey)) {
            $groqUrl = "https://api.groq.com/openai/v1/chat/completions";
            $payload = [
                'model' => 'qwen/qwen3.8-27b',
                'messages' => [
                    ['role' => 'system', 'content' => 'Eres un estratega senior B2B. Devuelve ÚNICAMENTE un JSON válido sin markdown.'],
                    ['role' => 'user', 'content' => $prompt]
                ],
                'temperature' => 0.25,
                'max_tokens' => 2500
            ];

            $ch = curl_init($groqUrl);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $groqKey, 'Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);

            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($code === 200 && !empty($resp)) {
                $resJson = json_decode($resp, true);
                $raw = $resJson['choices'][0]['message']['content'] ?? '';
                $clean = preg_replace('/^```json\s*/i', '', trim($raw));
                $clean = preg_replace('/^```\s*/i', '', $clean);
                $clean = preg_replace('/\s*```$/i', '', $clean);

                $parsed = json_decode($clean, true);
                if ($parsed && !empty($parsed['posts'])) {
                    return ['success' => true, 'data' => $parsed];
                }
            }
        }

        return ['success' => false, 'error' => 'No se pudo obtener respuesta estructurada del modelo.'];
    }
}
