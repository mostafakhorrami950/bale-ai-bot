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

    public function __construct()
    {
        $this->gapgptApiKey = Config::get('GAPGPT_API_KEY', '');
        $this->gapgptBaseUrl = rtrim(Config::get('GAPGPT_BASE_URL', 'https://api.gapgpt.app/v1'), '/');
        $this->metisaiApiKey = Config::get('METISAI_API_KEY', '');
        $this->metisaiBaseUrl = rtrim(Config::get('METISAI_BASE_URL', 'https://api.metisai.ir/api/v2'), '/');
        $this->timeout = (int) Config::get('AI_TIMEOUT', 300);
    }

    /**
     * Generate image(s). Routes to the correct provider.
     *
     * $params:
     *   - model    (string) required
     *   - prompt   (string) required
     *   - provider (string) — "gapgpt", "metisai"
     *   - image    (string|null) optional base64 or URL for img2img
     *   - mask     (string|null) optional
     *   - size     (string) optional, default '1024x1024'
     *   - n        (int)    optional, default 1
     *
     * @return array  ['images' => [url1, url2, ...]] on success
     *                ['error' => 'message'] on failure
     */
    public function generate(array $params): array
    {
        $prompt   = $params['prompt']   ?? '';
        $model    = $params['model']    ?? '';
        $provider = $params['provider'] ?? '';
        $image    = $params['image']    ?? null;
        $mask     = $params['mask']     ?? null;
        $size     = $params['size']     ?? '1024x1024';
        $n        = (int) ($params['n'] ?? 1);

        if (empty($prompt)) {
            return ['error' => 'متن درخواست (Prompt) الزامی است'];
        }
        if (empty($model)) {
            return ['error' => 'مدل الزامی است'];
        }

        // Route by provider
        if (strtolower($provider) === 'metisai') {
            $parts = explode(' ', trim($model));
            $cleanModel = $parts[0];
            return $this->metisaiGenerate($prompt, $cleanModel, $image, $mask, $size, $n);
        }

        // Default: GapGPT (OpenAI-compatible)
        if ($image !== null && $image !== '') {
            return $this->gapgptImageEdit($prompt, $image, $model, $n, $size);
        }
        return $this->gapgptImageGeneration($prompt, $model, $n, $size);
    }

    // ──────────────────────────────────────────────
    //  GapGPT (OpenAI-compatible)
    // ──────────────────────────────────────────────

    private function gapgptImageGeneration(string $prompt, string $model, int $n, string $size): array
    {
        return $this->gapgptParseResponse(
            $this->gapgptCallApi('/images/generations', [
                'model'  => $model,
                'prompt' => $prompt,
                'n'      => $n,
                'size'   => $size,
            ])
        );
    }

    private function gapgptImageEdit(string $prompt, string $imageBase64, string $model, int $n, string $size): array
    {
        return $this->gapgptParseResponse(
            $this->gapgptCallApi('/images/edits', [
                'model'   => $model,
                'image'   => $imageBase64,
                'prompt'  => $prompt,
                'n'       => $n,
                'size'    => $size,
            ])
        );
    }

    private function gapgptCallApi(string $endpoint, array $data): array
    {
        $url = $this->gapgptBaseUrl . $endpoint;
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->gapgptApiKey,
            ],
        ]);
        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno    = curl_errno($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0) {
            Logger::error('AIService GapGPT cURL error', ['endpoint' => $endpoint, 'errno' => $errno, 'error' => $error]);
            return ['error' => 'Connection error: ' . $error, '_http_code' => $httpCode];
        }
        $result = json_decode($body, true);
        if (!is_array($result)) {
            return ['error' => 'Invalid response from AI service', '_http_code' => $httpCode];
        }
        if (isset($result['error'])) {
            $msg = is_array($result['error']) ? ($result['error']['message'] ?? json_encode($result['error'])) : $result['error'];
            Logger::error('AIService GapGPT API error', ['endpoint' => $endpoint, 'error' => $msg, 'http' => $httpCode]);
            return ['error' => $msg, '_http_code' => $httpCode];
        }
        $result['_http_code'] = $httpCode;
        return $result;
    }

    private function gapgptParseResponse(array $response): array
    {
        if (isset($response['error'])) {
            return $response;
        }
        $data = $response['data'] ?? [];
        if (empty($data)) {
            return ['error' => 'AI service returned no images'];
        }
        $images = [];
        foreach ($data as $item) {
            if (isset($item['url'])) {
                $images[] = $item['url'];
            }
        }
        if (empty($images)) {
            return ['error' => 'No image URLs found in AI response'];
        }
        return ['images' => $images];
    }

    // ──────────────────────────────────────────────
    //  MetisAI API (2-step async)
    //  ── POST /api/v2/generate  →  { id, status: "WAITING", ... }
    //  ── GET  /api/v2/generate/{id}  →  { id, status: "COMPLETED", generations: [{url,contentType}], usage: {cost} }
    //  Statuses: QUEUE, WAITING, RUNNING, COMPLETED, ERROR, CANCELLED
    // ──────────────────────────────────────────────

    private function metisaiGenerate(string $prompt, string $model, ?string $image, ?string $mask, string $size, int $n): array
    {
        $args = [
            'prompt'        => $prompt,
            'moderation'    => 'low',
            'output_format' => 'png',
            'quality'       => 'medium',
        ];

        // MetisAI uses "auto" for default size (not all models support custom sizes)
        if ($size !== '1024x1024') {
            $args['size'] = $size;
        }

        // IMPORTANT: MetisAI requires publicly accessible URLs for images
        if ($image) {
            $args['image'] = $image;
        }

        $operation = 'Imagine';
        if ($image && $mask) {
            $operation = 'Inpaint';
        }

        $payload = [
            'model' => [
                'name'  => 'openai',
                'model' => $model,
            ],
            'operation' => $operation,
            'args'      => $args,
        ];

        Logger::info('AIService MetisAI submitting', [
            'payload' => mb_substr(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 2000),
        ]);

        // Step 1: POST /generate
        $submitResult = $this->metisaiCallApi('/generate', $payload);
        if (isset($submitResult['error'])) {
            return $submitResult;
        }

        // Extract task ID from response — MetisAI uses "id" field
        $taskId = $submitResult['id'] ?? $submitResult['data']['id'] ?? null;
        if (!$taskId) {
            Logger::error('AIService MetisAI: no id in response', ['response' => $submitResult]);
            return ['error' => 'Failed to get task ID from MetisAI'];
        }

        Logger::info('AIService MetisAI submitted', ['task_id' => $taskId, 'initial_status' => $submitResult['status'] ?? 'unknown']);

        // Step 2: Poll GET /generate/{id}
        return $this->metisaiPoll($taskId);
    }

    /**
     * Poll MetisAI for result — minimum 5 seconds between requests per docs.
     */
    private function metisaiPoll(string $taskId): array
    {
        // Max attempts = timeout / 5 seconds
        $maxAttempts = max(1, (int) ($this->timeout / 5));
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            $attempt++;
            $result = $this->metisaiGetResult($taskId);

            if (isset($result['error'])) {
                return $result;
            }

            $status = $result['status'] ?? '';

            Logger::info('AIService MetisAI poll', ['attempt' => $attempt, 'status' => $status]);

            // Statuses (case-sensitive): QUEUE, WAITING, RUNNING, COMPLETED, ERROR, CANCELLED
            if ($status === 'COMPLETED') {
                return $this->metisaiExtractImages($result);
            }

            if (in_array($status, ['ERROR', 'CANCELLED', 'FAILED'], true)) {
                $errorMsg = $result['error'] ?? ($status === 'CANCELLED' ? 'Cancelled' : 'Generation failed');
                Logger::error('AIService MetisAI generation ended with status', [
                    'task_id' => $taskId, 'status' => $status, 'error' => $errorMsg,
                ]);
                return ['error' => 'Image generation ' . strtolower($status) . ': ' . $errorMsg];
            }

            // Still processing (QUEUE, WAITING, RUNNING) — wait 5 seconds
            sleep(5);
        }

        Logger::error('AIService MetisAI poll timeout', ['task_id' => $taskId, 'attempts' => $maxAttempts]);
        return ['error' => 'Image generation timed out after ' . $this->timeout . ' seconds'];
    }

    /**
     * GET /generate/{taskId}
     */
    private function metisaiGetResult(string $taskId): array
    {
        $url = $this->metisaiBaseUrl . '/generate/' . urlencode($taskId);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_HTTPGET        => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->metisaiApiKey,
            ],
        ]);
        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno    = curl_errno($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0) {
            return ['error' => 'MetisAI poll connection error: ' . $error];
        }
        $result = json_decode($body, true);
        if (!is_array($result)) {
            return ['error' => 'MetisAI poll invalid response'];
        }
        if (isset($result['error'])) {
            $msg = is_array($result['error']) ? ($result['error']['message'] ?? json_encode($result['error'])) : $result['error'];
            return ['error' => 'MetisAI poll error: ' . $msg];
        }
        return $result;
    }

    /**
     * Extract image URLs from MetisAI completed response.
     *
     * Response structure when COMPLETED:
     * {
     *   "id": "...",
     *   "status": "COMPLETED",
     *   "generations": [
     *     { "url": "https://...", "contentType": "IMAGE", "content": null }
     *   ],
     *   "usage": { "cost": 0.14 }
     * }
     */
    private function metisaiExtractImages(array $result): array
    {
        $images = [];

        // Primary: generations[] array with {url, contentType}
        $generations = $result['generations'] ?? [];
        if (is_array($generations)) {
            foreach ($generations as $gen) {
                if (is_array($gen) && isset($gen['url'])) {
                    $images[] = $gen['url'];
                } elseif (is_string($gen)) {
                    $images[] = $gen;
                }
            }
        }

        // Fallback: direct URL at top level
        if (empty($images) && isset($result['url']) && is_string($result['url'])) {
            $images[] = $result['url'];
        }

        if (empty($images)) {
            Logger::error('AIService MetisAI no images in completed result', ['result' => $result]);
            return ['error' => 'No images found in MetisAI response'];
        }

        // Log cost if available
        if (isset($result['usage']['cost'])) {
            Logger::info('AIService MetisAI completed', [
                'images_count' => count($images),
                'cost'         => $result['usage']['cost'] . ' cents',
            ]);
        }

        return ['images' => $images];
    }

    /**
     * POST to MetisAI API.
     */
    private function metisaiCallApi(string $endpoint, array $data): array
    {
        $url = $this->metisaiBaseUrl . $endpoint;
        $payloadJson = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payloadJson,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->metisaiApiKey,
            ],
        ]);
        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno    = curl_errno($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        Logger::info('AIService MetisAI API call', [
            'endpoint'  => $endpoint,
            'http_code' => $httpCode,
            'response'  => mb_substr($body ?? '', 0, 1000),
        ]);

        if ($errno !== 0) {
            Logger::error('AIService MetisAI cURL error', ['endpoint' => $endpoint, 'errno' => $errno, 'error' => $error]);
            return ['error' => 'MetisAI connection error: ' . $error];
        }
        $result = json_decode($body, true);
        if (!is_array($result)) {
            Logger::error('AIService MetisAI invalid JSON', ['endpoint' => $endpoint, 'http_code' => $httpCode, 'body' => mb_substr($body, 0, 500)]);
            return ['error' => 'Invalid response from MetisAI', '_http_code' => $httpCode];
        }
        if (isset($result['error'])) {
            $msg = is_array($result['error']) ? ($result['error']['message'] ?? json_encode($result['error'])) : $result['error'];
            Logger::error('AIService MetisAI API error', ['endpoint' => $endpoint, 'error' => $msg, 'http' => $httpCode]);
            return ['error' => $msg, '_http_code' => $httpCode];
        }
        $result['_http_code'] = $httpCode;
        return $result;
    }

    // ──────────────────────────────────────────────
    //  Shared model utilities
    // ──────────────────────────────────────────────

    public function getModelById(int $id): ?array
    {
        try {
            $db = \Database\Database::getInstance();
            $stmt = $db->query("SELECT id, name, provider, cost_per_image, is_active FROM ai_models WHERE id = ? AND is_active = 1", [$id]);
            $row = $stmt->fetch();
            if ($row) return $row;
            $fallback = $this->getFirstActiveModel();
            if ($fallback) return $fallback;
            return [
                'id' => 0,
                'name' => 'gpt-image-1',
                'provider' => 'gapgpt',
                'cost_per_image' => 2,
                'is_active' => 1
            ];
        } catch (\Throwable $e) {
            Logger::error('AIService::getModelById failed', ['model_id' => $id, 'error' => $e->getMessage()]);
            return null;
        }
    }

    public function getDefaultModelId(): ?int
    {
        $defaultId = (int) Config::get('DEFAULT_AI_MODEL_ID', 0);
        if ($defaultId > 0) {
            $model = $this->getModelById($defaultId);
            if ($model && $model['is_active']) {
                return (int) $model['id'];
            }
        }
        try {
            $db = \Database\Database::getInstance();
            $stmt = $db->query("SELECT id FROM ai_models WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
            $row = $stmt->fetch();
            return $row ? (int) $row['id'] : null;
        } catch (\Throwable $e) {
            Logger::error('AIService::getDefaultModelId failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function getActiveModelById(int $id): ?array
    {
        try {
            $db = \Database\Database::getInstance();
            $stmt = $db->query("SELECT id, name, provider, cost_per_image, is_active FROM ai_models WHERE id = ? AND is_active = 1", [$id]);
            return $stmt->fetch() ?: null;
        } catch (\Throwable $e) {
            Logger::error('AIService::getActiveModelById failed', ['model_id' => $id, 'error' => $e->getMessage()]);
            return null;
        }
    }

    public function getFirstActiveModel(): ?array
    {
        try {
            $db = \Database\Database::getInstance();
            $stmt = $db->query("SELECT id, name, provider, cost_per_image, is_active FROM ai_models WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
            return $stmt->fetch() ?: null;
        } catch (\Throwable $e) {
            Logger::error('AIService::getFirstActiveModel failed', ['error' => $e->getMessage()]);
            return null;
        }
    }
}