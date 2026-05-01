<?php

namespace Modules\AI;

use Core\Config;
use Database\Logger;

class AIService
{
    private string $gapgptApiKey;
    private string $gapgptBaseUrl;
    private string $metisaiApiKey;
    private string $metisaiBaseUrl;
    private int $timeout;
    private string $logFile;

    // OpenRouter
    private string $openrouterApiKey;
    private string $openrouterBaseUrl;

    public function __construct()
    {
        $this->gapgptApiKey = Config::get('GAPGPT_API_KEY', '');
        $this->gapgptBaseUrl = rtrim(Config::get('GAPGPT_BASE_URL', 'https://api.gapgpt.app/v1'), '/');
        $this->metisaiApiKey = Config::get('METISAI_API_KEY', '');
        $this->metisaiBaseUrl = rtrim(Config::get('METISAI_BASE_URL', 'https://api.metisai.ir/api/v2'), '/');

        $this->openrouterApiKey = Config::get('OPENROUTER_API_KEY', '');
        $this->openrouterBaseUrl = 'https://openrouter.ai/api/v1';

        $this->timeout = (int) Config::get('AI_TIMEOUT', 300);
        // Use __DIR__ relative path to avoid BASE_PATH issues
        $this->logFile = Config::get('AI_LOG_FILE', __DIR__ . '/../../logs_ai.txt');
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
    }

    private function aiLog(string $level, string $message, array $context = []): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
        $line = "[{$timestamp}] [{$level}] {$message}{$contextStr}\n";
        @file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Generate image(s). Routes to correct provider.
     *
     * $params:
     *   - model      (string) required — model name
     *   - prompt     (string) required
     *   - provider   (string) — "gapgpt", "metisai", "openrouter"
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

        // Multi-image support — only OpenRouter supports it
        $hasMultipleImages = is_array($images) && count($images) > 0;

        if ($provider === 'metisai') {
            $parts = explode(' ', trim($modelName));
            $cleanModel = $parts[0];
            $result = $this->metisaiGenerate($prompt, $cleanModel, $image, $modelData);
        } elseif ($provider === 'openrouter') {
            $result = $this->openrouterGenerate($prompt, $modelName, $image, $modelData, $images);
        } elseif ($image) {
            $result = $this->gapgptImageEdit($prompt, $image, $modelName);
        } else {
            $result = $this->gapgptImageGeneration($prompt, $modelName);
        }

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

        $this->aiLog('INFO', 'OpenRouter request', [
            'model'       => $modelName,
            'modalities'  => $modalities,
            'image_count' => count($imageUris),
            'image_config' => $imageConfig ?: null,
        ]);

        $result = $this->openrouterCall($payload);

        $this->aiLog('INFO', 'OpenRouter response', [
            'result_keys' => array_keys($result),
            'has_images'  => isset($result['images']),
            'error'       => $result['error'] ?? null,
        ]);

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

        $this->aiLog('INFO', 'OpenRouter API call', [
            'http'   => $httpCode,
            'errno'  => $errno,
            'error'  => $error,
            'body'   => mb_substr($body ?? '', 0, 3000),
        ]);

        if ($errno) return ['error' => 'OpenRouter connection error: ' . $error];
        if ($httpCode >= 400) {
            return ['error' => "OpenRouter HTTP {$httpCode}: " . mb_substr($body, 0, 500)];
        }

        $r = json_decode($body, true);
        if (!is_array($r)) return ['error' => 'OpenRouter: پاسخ نامعتبر'];

        // Standard OpenAI-compatible error
        if (isset($r['error'])) {
            $msg = is_array($r['error']) ? ($r['error']['message'] ?? json_encode($r['error'])) : $r['error'];
            $this->aiLog('ERROR', 'OpenRouter API error', ['msg' => $msg]);
            return ['error' => $msg];
        }

        // Extract images from response
        $imageUrls = [];
        $choices = $r['choices'] ?? [];
        
        // Log full response structure for debugging
        $this->aiLog('DEBUG', 'OpenRouter full response sample', [
            'has_choices' => !empty($choices),
            'choice_keys' => !empty($choices) ? array_keys($choices[0] ?? []) : [],
            'message_keys' => !empty($choices) ? array_keys($choices[0]['message'] ?? []) : [],
            'has_images_field' => isset($choices[0]['message']['images']),
            'content_type' => gettype($choices[0]['message']['content'] ?? null),
        ]);
        
        foreach ($choices as $idx => $choice) {
            $message = $choice['message'] ?? [];
            
            // OpenRouter returns images in an "images" field (array of {image_url: {url: ...}})
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
        }

        if (empty($imageUrls)) {
            $this->aiLog('WARN', 'OpenRouter: no images in response', [
                'full' => json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            return ['error' => 'OpenRouter: تصویری در پاسخ یافت نشد'];
        }

        $this->aiLog('INFO', 'OpenRouter images found', [
            'count'      => count($imageUrls),
            'first_type' => str_starts_with($imageUrls[0] ?? '', 'data:') ? 'data_uri' : 'url',
        ]);

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
                    $this->aiLog('ERROR', 'OpenRouter image download failed. URL won\'t work with Bale.', [
                        'http' => $httpCode,
                        'size' => $downloadSize,
                        'curl_err' => $curlError ?: null,
                    ]);
                    return ['error' => 'امکان دانلود تصویر از سرور OpenRouter وجود ندارد. لطفاً دوباره تلاش کنید.'];
                }
            } else {
                $downloadedImages[] = $url;
            }
        }

        return ['images' => $downloadedImages];
    }

    // ═══════════════════════════════════════════════
    //   GapGPT (OpenAI-compatible)
    // ═══════════════════════════════════════════════

    private function gapgptImageGeneration(string $prompt, string $modelName): array
    {
        $url = $this->gapgptBaseUrl . '/images/generations';
        $payload = [
            'model'  => $modelName,
            'prompt' => $prompt,
            'n'      => 1,
            'size'   => '1024x1024',
        ];

        $result = $this->gapgptCall($url, $payload);
        if (isset($result['error'])) return $result;

        $images = [];
        foreach ($result['data'] ?? [] as $item) {
            $images[] = $item['url'] ?? $item['b64_json'] ?? '';
        }
        return array_filter($images) ? ['images' => $images] : ['error' => 'GapGPT: پاسخی دریافت نشد'];
    }

    private function gapgptImageEdit(string $prompt, string $image, string $modelName): array
    {
        $url = $this->gapgptBaseUrl . '/images/edits';
        $payload = [
            'model'  => $modelName,
            'prompt' => $prompt,
            'image'  => $image,
            'n'      => 1,
            'size'   => '1024x1024',
        ];

        $result = $this->gapgptCall($url, $payload);
        if (isset($result['error'])) return $result;

        $images = [];
        foreach ($result['data'] ?? [] as $item) {
            $images[] = $item['url'] ?? $item['b64_json'] ?? '';
        }
        return array_filter($images) ? ['images' => $images] : ['error' => 'GapGPT: پاسخی دریافت نشد'];
    }

    private function gapgptCall(string $url, array $payload): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->gapgptApiKey,
            ],
        ]);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (empty($body)) return ['error' => 'GapGPT: پاسخی دریافت نشد'];
        $r = json_decode($body, true);
        if (!is_array($r)) return ['error' => 'GapGPT: پاسخ نامعتبر'];
        if (isset($r['error'])) {
            $msg = is_array($r['error']) ? ($r['error']['message'] ?? json_encode($r['error'])) : $r['error'];
            return ['error' => $msg];
        }
        return $r;
    }

    // ═══════════════════════════════════════════════
    //   MetisAI
    // ═══════════════════════════════════════════════

    private function metisaiGenerate(string $prompt, string $modelName, ?string $image, ?array $modelData): array
    {
        return ['error' => 'MetisAI: پیاده‌سازی نشده است'];
    }

    // ═══════════════════════════════════════════════
    //   Model Lookup Helpers
    // ═══════════════════════════════════════════════

    /**
     * Get a model by ID from ai_image_models or ai_edit_models (active).
     */
    public function getActiveModelById(int $id): ?array
    {
        try {
            $db = \Database\Database::getInstance();
            // Try image table first
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
            $stmt = $db->query("SELECT id, name, provider, cost_per_input_char, cost_per_output_char, free_model, model_config, is_active FROM ai_text_models WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
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