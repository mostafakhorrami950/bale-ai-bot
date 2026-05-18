<?php

namespace Modules\AI;

use Core\Config;
use Database\Logger;

class AIService
{
    private int $timeout;

    // OpenRouter
    private string $openrouterApiKey;
    private string $openrouterBaseUrl;

    public function __construct()
    {
        $this->openrouterApiKey = Config::get('OPENROUTER_API_KEY', '');
        $this->openrouterBaseUrl = 'https://openrouter.ai/api/v1';

        $this->timeout = (int) Config::get('AI_TIMEOUT', 600);
    }

    /**
     * Generate image(s). Routes to OpenRouter provider only.
     *
     * $params:
     *   - model      (string) required — model name
     *   - prompt     (string) required
     *   - provider   (string) — "openrouter" (default)
     *   - image      (string|null) single base64 data URI for img2img (backward compat)
     *   - images     (array|null) multiple base64 data URIs for multi-image input
     *   - model_data (array|null) full model row from DB (includes model_config JSON)
     */
    public function generate(array $params): array
    {
        $prompt    = $params['prompt']    ?? '';
        $modelName = $params['model']     ?? '';
        $provider  = $params['provider']  ?? '';
        $image     = $params['image']     ?? null;
        $images    = $params['images']    ?? null;
        $modelData = $params['model_data'] ?? null;

        if (empty($prompt)) return ['error' => 'متن درخواست (Prompt) الزامی است'];
        if (empty($modelName)) return ['error' => 'مدل الزامی است'];

        $provider = strtolower(trim($provider));

        \Core\AILogger::log('AISERVICE_GENERATE', [
            'provider'   => $provider,
            'model'      => $modelName,
            'has_image'  => !empty($image),
            'has_images' => is_array($images) ? count($images) : 0,
            'prompt_len' => mb_strlen($prompt),
        ]);

        $startTime = microtime(true);

        // Only OpenRouter is supported — route all requests to OpenRouter
        $result = $this->openrouterGenerate($prompt, $modelName, $image, $modelData, $images);

        $duration = microtime(true) - $startTime;
        \Core\AILogger::log('AISERVICE_RESULT', [
            'provider'    => $provider,
            'model'       => $modelName,
            'duration'    => round($duration, 2) . 's',
            'has_images'  => isset($result['images']) ? count($result['images']) : 0,
            'error'       => $result['error'] ?? null,
        ]);

        return $result;
    }

    // ═══════════════════════════════════════════════
    //   OpenRouter (Chat Completions with images)
    // ═══════════════════════════════════════════════

    private function openrouterGenerate(string $prompt, string $modelName, ?string $image, ?array $modelData, ?array $images = null): array
    {
        // Read model_config for aspect_ratio / image_size
        $cfg = [];
        if ($modelData && !empty($modelData['model_config'])) {
            $raw = is_string($modelData['model_config'])
                ? json_decode($modelData['model_config'], true)
                : $modelData['model_config'];
            $cfg = is_array($raw) ? $raw : [];
        }
        $o = $cfg['openrouter'] ?? [];
        $aspectRatio = $o['aspect_ratio'] ?? '1:1';
        $imageSize   = $o['image_size'] ?? '1K';

        // Build messages content array
        $contentParts = [];

        // Always include text prompt first
        $contentParts[] = ['type' => 'text', 'text' => $prompt];

        // Collect all image URIs
        $imageUris = [];

        // Single image (backward compat)
        if (!empty($image)) {
            $imageUris[] = $this->resolveImageContent($image);
        }

        // Multiple images (new multi-image support)
        if (is_array($images)) {
            foreach ($images as $img) {
                if (!empty($img)) {
                    $imageUris[] = $this->resolveImageContent($img);
                }
            }
        }

        // Add all images as content parts
        foreach ($imageUris as $uri) {
            $contentParts[] = [
                'type' => 'image_url',
                'image_url' => ['url' => $uri],
            ];
        }

        $messages[] = [
            'role' => 'user',
            'content' => $contentParts,
        ];

        // Determine modality
        $modalities = ['image']; // image-only by default
        if (str_contains($modelName, 'gemini')) {
            $modalities = ['image', 'text'];
        }

        $payload = [
            'model'      => $modelName,
            'messages'   => $messages,
            'modalities' => $modalities,
            'stream'     => false,
        ];

        // image_config (for supported models)
        $imageConfig = [];
        if ($aspectRatio !== '1:1' || !empty($imageSize)) {
            if ($aspectRatio !== '1:1') {
                $imageConfig['aspect_ratio'] = $aspectRatio;
            }
            if (!empty($imageSize) && $imageSize !== 'auto') {
                $imageConfig['image_size'] = $imageSize;
            }
        }
        if (!empty($imageConfig)) {
            $payload['image_config'] = $imageConfig;
        }

        $result = $this->openrouterCall($payload);

        return $result;
    }

    /**
     * Convert image to data URI if it's raw base64 or URL
     */
    private function resolveImageContent(string $image): string
    {
        // Already a data URI
        if (str_starts_with($image, 'data:')) {
            return $image;
        }
        // HTTP URL
        if (filter_var($image, FILTER_VALIDATE_URL)) {
            return $image;
        }
        // Raw base64 — wrap as data URI
        $decoded = base64_decode($image, true);
        if ($decoded && strlen($decoded) > 100) {
            $mime = 'image/jpeg';
            $first = substr($decoded, 0, 4);
            if (str_starts_with($first, "\x89PNG")) $mime = 'image/png';
            elseif (str_starts_with($first, "\xff\xd8")) $mime = 'image/jpeg';
            return 'data:' . $mime . ';base64,' . $image;
        }
        return $image;
    }

    private function openrouterCall(array $payload): array
    {
        $ch = curl_init($this->openrouterBaseUrl . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->openrouterApiKey,
            ],
        ]);
        $body   = curl_exec($ch);
        $errno  = curl_errno($ch);
        $error  = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) return ['error' => 'OpenRouter connection error: ' . $error];
        if ($httpCode >= 400) {
            return ['error' => "OpenRouter HTTP {$httpCode}: " . mb_substr($body, 0, 500)];
        }

        $r = json_decode($body, true);
        if (!is_array($r)) return ['error' => 'OpenRouter: پاسخ نامعتبر'];

        // Standard OpenAI-compatible error
        if (isset($r['error'])) {
            $msg = is_array($r['error']) ? ($r['error']['message'] ?? json_encode($r['error'])) : $r['error'];
            return ['error' => $msg];
        }

        // Extract images from response
        $imageUrls = [];
        $choices = $r['choices'] ?? [];

        foreach ($choices as $idx => $choice) {
            $message = $choice['message'] ?? [];

            // Method 1: OpenRouter "images" field (array of {image_url: {url: ...}})
            $imagesField = $message['images'] ?? [];
            if (is_array($imagesField)) {
                foreach ($imagesField as $img) {
                    $url = '';
                    if (is_string($img)) {
                        $url = $img;
                    } elseif (is_array($img)) {
                        $url = $img['image_url']['url'] ?? $img['url'] ?? '';
                    }
                    if (!empty($url)) {
                        $imageUrls[] = $url;
                    }
                }
            }

            // Method 2: Some models (Gemini, etc.) return image_url directly in content array
            if (empty($imageUrls) && is_array($message['content'] ?? null)) {
                foreach ($message['content'] as $part) {
                    if (($part['type'] ?? '') === 'image_url' && !empty($part['image_url']['url'] ?? '')) {
                        $imageUrls[] = $part['image_url']['url'];
                    }
                }
            }

            // Method 3: Text content may contain a data URI (base64 image embedded in markdown)
            if (empty($imageUrls) && is_string($message['content'] ?? null) && preg_match('/(data:image\/[a-z]+;base64,[^\s\'"]+)/', $message['content'], $m)) {
                $imageUrls[] = $m[1];
            }
        }

        if (empty($imageUrls)) {
            // No images found — check if model returned text response instead
            $textResponse = '';
            if (!empty($choices[0]['message']['content'] ?? null)) {
                $raw = $choices[0]['message']['content'];
                if (is_string($raw)) {
                    $textResponse = $raw;
                } elseif (is_array($raw)) {
                    // Try to extract text from content array
                    foreach ($raw as $part) {
                        if (($part['type'] ?? '') === 'text' && !empty($part['text'])) {
                            $textResponse .= $part['text'];
                        }
                    }
                }
                $textResponse = trim($textResponse);
            }
            
            if (!empty($textResponse)) {
                // Model returned text only — return it as text response
                \Core\AILogger::log('AISERVICE_TEXT_ONLY', [
                    'model' => $r['model'] ?? 'unknown',
                    'text_len' => mb_strlen($textResponse),
                ]);
                return [
                    'text' => $textResponse,
                    'usage' => $r['usage'] ?? null,
                ];
            }
            
            // Log the raw response for debugging  
            \Core\AILogger::log('AISERVICE_NO_IMAGE', [
                'http_code' => $httpCode,
                'model' => $r['model'] ?? 'unknown',
                'content_type' => gettype($choices[0]['message']['content'] ?? null),
                'finish_reason' => $choices[0]['finish_reason'] ?? null,
                'usage' => $r['usage'] ?? null,
            ]);
            return ['error' => 'OpenRouter: تصویری در پاسخ یافت نشد'];
        }

        // Download images that are data URIs or HTTP URLs
        $downloadedImages = [];
        foreach ($imageUrls as $url) {
            if (str_starts_with($url, 'data:')) {
                // Data URI — keep as-is for handler to process
                $downloadedImages[] = $url;
            } elseif (str_starts_with($url, 'http')) {
                // Download from OpenRouter
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 60,
                    CURLOPT_CONNECTTIMEOUT => 15,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; AI-Bot/1.0)',
                ]);
                $imageBinary = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                $downloadSize = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
                curl_close($ch);

                if ($httpCode === 200 && !empty($imageBinary) && $downloadSize > 500) {
                    $mime = 'image/png';
                    $first = substr($imageBinary, 0, 4);
                    if (str_starts_with($first, "\xff\xd8")) $mime = 'image/jpeg';
                    elseif (str_starts_with($first, "\x89PNG")) $mime = 'image/png';
                    $downloadedImages[] = 'data:' . $mime . ';base64,' . base64_encode($imageBinary);
                } else {
                    return ['error' => 'امکان دانلود تصویر از سرور OpenRouter وجود ندارد. لطفاً دوباره تلاش کنید.'];
                }
            } else {
                $downloadedImages[] = $url;
            }
        }

        return [
            'images' => $downloadedImages,
            'usage' => $r['usage'] ?? null,
        ];
    }

    // ═══════════════════════════════════════════════
    //   Model Lookup Helpers
    // ═══════════════════════════════════════════════

    /**
     * Get a model by ID from ai_image_models or ai_edit_models (active).
     * If $modelType is provided, only query that specific table.
     */
    public function getActiveModelById(int $id, ?string $modelType = null): ?array
    {
        try {
            $db = \Database\Database::getInstance();

            // If type is specified, query only that table
            if ($modelType === 'image_editing') {
                $stmt = $db->query("SELECT id, name, provider, cost_per_edit AS cost_per_image, is_active, model_config FROM ai_edit_models WHERE id = ? AND is_active = 1", [$id]);
                return $stmt->fetch() ?: null;
            }
            if ($modelType === 'image_generation' || $modelType === 'text2img') {
                $stmt = $db->query("SELECT id, name, provider, cost_per_image, is_active, model_config FROM ai_image_models WHERE id = ? AND is_active = 1", [$id]);
                return $stmt->fetch() ?: null;
            }
            if ($modelType === 'text') {
                $stmt = $db->query("SELECT id, name, provider, cost_per_input_char, cost_per_output_char, free_model, model_config, is_active FROM ai_text_models WHERE id = ? AND is_active = 1", [$id]);
                return $stmt->fetch() ?: null;
            }
            if ($modelType === 'video') {
                $stmt = $db->query("SELECT id, name, provider, cost_per_video AS cost_per_image, model_config, is_active FROM ai_video_models WHERE id = ? AND is_active = 1", [$id]);
                return $stmt->fetch() ?: null;
            }

            // Fallback: try image table first (backward compat)
            $stmt = $db->query("SELECT id, name, provider, cost_per_image, is_active, model_config FROM ai_image_models WHERE id = ? AND is_active = 1", [$id]);
            $row = $stmt->fetch();
            if ($row) return $row;
            // Try edit table
            $stmt = $db->query("SELECT id, name, provider, cost_per_edit AS cost_per_image, is_active, model_config FROM ai_edit_models WHERE id = ? AND is_active = 1", [$id]);
            $row = $stmt->fetch();
            if ($row) return $row;
            return null;
        } catch (\Throwable $e) { Logger::error('getActiveModelById', ['id' => $id, 'error' => $e->getMessage()]); return null; }
    }

    /**
     * Get the first active image generation model.
     */
    public function getFirstActiveModel(): ?array
    {
        return $this->getFirstActiveImageModel();
    }

    /**
     * Get first active image generation model.
     */
    public function getFirstActiveImageModel(): ?array
    {
        try {
            $db = \Database\Database::getInstance();
            $stmt = $db->query("SELECT id, name, provider, cost_per_image, is_active, model_config FROM ai_image_models WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
            return $stmt->fetch() ?: null;
        } catch (\Throwable $e) { Logger::error('getFirstActiveImageModel', ['error' => $e->getMessage()]); return null; }
    }

    /**
     * Get first active edit model.
     */
    public function getFirstActiveEditModel(): ?array
    {
        try {
            $db = \Database\Database::getInstance();
            $stmt = $db->query("SELECT id, name, provider, cost_per_edit AS cost_per_image, is_active, model_config FROM ai_edit_models WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
            return $stmt->fetch() ?: null;
        } catch (\Throwable $e) { Logger::error('getFirstActiveEditModel', ['error' => $e->getMessage()]); return null; }
    }

    /**
     * Get first active text model.
     */
    public function getFirstActiveTextModel(): ?array
    {
        try {
            $db = \Database\Database::getInstance();
            // Check if 'default_text_model' setting exists and is valid
            $settingStmt = $db->query("SELECT value FROM settings WHERE key_name = 'default_text_model'");
            $settingRow = $settingStmt->fetch();
            $defaultId = $settingRow ? (int)$settingRow['value'] : 0;
            if ($defaultId > 0) {
                $stmt = $db->query("SELECT * FROM ai_text_models WHERE id = ? AND is_active = 1", [$defaultId]);
                $row = $stmt->fetch();
                if ($row) return $row;
            }
            $stmt = $db->query("SELECT * FROM ai_text_models WHERE is_active = 1 ORDER BY sort_order ASC, id ASC LIMIT 1");
            return $stmt->fetch() ?: null;
        } catch (\Throwable $e) { Logger::error('getFirstActiveTextModel', ['error' => $e->getMessage()]); return null; }
    }

    /**
     * Get default model ID (image generation).
     */
    public function getDefaultModelId(): ?int
    {
        $id = (int) Config::get('DEFAULT_AI_MODEL_ID', 0);
        if ($id > 0) { 
            $m = $this->getModelById($id); 
            if ($m && ($m['is_active'] ?? false)) return (int)$m['id']; 
        }
        try {
            $db = \Database\Database::getInstance();
            $stmt = $db->query("SELECT id FROM ai_image_models WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
            $row = $stmt->fetch();
            return $row ? (int)$row['id'] : null;
        } catch (\Throwable $e) { return null; }
    }

    /**
     * Get model by ID from any table (including inactive).
     */
    public function getModelById(int $id): ?array
    {
        try {
            $db = \Database\Database::getInstance();
            $stmt = $db->query("SELECT * FROM ai_image_models WHERE id = ?", [$id]);
            $row = $stmt->fetch();
            if ($row) return $row;
            $stmt = $db->query("SELECT * FROM ai_edit_models WHERE id = ?", [$id]);
            $row = $stmt->fetch();
            if ($row) return $row;
            $stmt = $db->query("SELECT * FROM ai_text_models WHERE id = ?", [$id]);
            $row = $stmt->fetch();
            if ($row) return $row;
            $stmt = $db->query("SELECT * FROM ai_video_models WHERE id = ?", [$id]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (\Throwable $e) { Logger::error('getModelById', ['id' => $id, 'error' => $e->getMessage()]); return null; }
    }
}