<?php

namespace Modules\AI;

use Core\Config;
use Database\Logger;

class AIService
{
    private string $apiKey;
    private string $baseUrl;
    private int $timeout;

    public function __construct()
    {
        $this->apiKey = Config::get('GAPGPT_API_KEY', '');
        $this->baseUrl = rtrim(Config::get('GAPGPT_BASE_URL', 'https://api.gapgpt.app/v1'), '/');
        $this->timeout = (int) Config::get('AI_TIMEOUT', 60);
    }

    /**
     * Generate image(s) using GapGPT OpenAI-compatible API.
     *
     * $params can contain:
     *   - model  (string) required
     *   - prompt (string) required
     *   - image  (string|null) optional base64-encoded image for img2img
     *   - size   (string) optional, default '1024x1024'
     *   - n      (int)    optional, default 1
     *
     * @param array $params
     * @return array  ['images' => [url1, url2, ...]] on success
     *                ['error' => 'message'] on failure
     */
    public function generate(array $params): array
    {
        $prompt = $params['prompt'] ?? '';
        $model  = $params['model']  ?? '';
        $image  = $params['image']  ?? null;
        $size   = $params['size']   ?? '1024x1024';
        $n      = (int) ($params['n'] ?? 1);

        if (empty($prompt)) {
            return ['error' => 'Prompt is required'];
        }
        if (empty($model)) {
            return ['error' => 'Model is required'];
        }

        // Decide endpoint based on image presence
        if ($image !== null && $image !== '') {
            // img2img — use /v1/images/edits with base64
            return $this->imageEdit($prompt, $image, $model, $n, $size);
        } else {
            // text2img — use /v1/images/generations
            return $this->imageGeneration($prompt, $model, $n, $size);
        }
    }

    /**
     * Fetch model config from ai_models table.
     *
     * @param int $id
     * @return array|null  ['id', 'name', 'provider', 'cost_per_image', 'is_active']
     */
    public function getModelById(int $id): ?array
    {
        try {
            $db = \Database\Database::getInstance();
            $stmt = $db->query("SELECT id, name, provider, cost_per_image, is_active FROM ai_models WHERE id = ? AND is_active = 1", [$id]);
            $row = $stmt->fetch();
            if ($row) return $row;
            // Fallback: return first active model
            $fallback = $this->getFirstActiveModel();
            if ($fallback) return $fallback;
            // Ultimate fallback: return hardcoded default if table is empty
            return [
                'id' => 0,
                'name' => 'gpt-image-1',
                'provider' => 'gapgpt',
                'cost_per_image' => 2,
                'is_active' => 1
            ];
        } catch (\Throwable $e) {
            Logger::error('AIService::getModelById failed', [
                'model_id' => $id,
                'error'    => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get default active model.
     *
     * @return array|null
     */
    public function getDefaultModelId(): ?int
    {
        $defaultId = (int) Config::get('DEFAULT_AI_MODEL_ID', 0);
        if ($defaultId > 0) {
            $model = $this->getModelById($defaultId);
            if ($model && $model['is_active']) {
                return (int) $model['id'];
            }
        }
        // Fallback: first active model
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

    /**
     * Get active model by its id.
     *
     * @param int $id
     * @return array|null
     */
    public function getActiveModelById(int $id): ?array
    {
        try {
            $db = \Database\Database::getInstance();
            $stmt = $db->query(
                "SELECT id, name, provider, cost_per_image, is_active FROM ai_models WHERE id = ? AND is_active = 1",
                [$id]
            );
            return $stmt->fetch() ?: null;
        } catch (\Throwable $e) {
            Logger::error('AIService::getActiveModelById failed', [
                'model_id' => $id,
                'error'    => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get first active model.
     *
     * @return array|null
     */
    public function getFirstActiveModel(): ?array
    {
        try {
            $db = \Database\Database::getInstance();
            $stmt = $db->query(
                "SELECT id, name, provider, cost_per_image, is_active FROM ai_models WHERE is_active = 1 ORDER BY id ASC LIMIT 1"
            );
            return $stmt->fetch() ?: null;
        } catch (\Throwable $e) {
            Logger::error('AIService::getFirstActiveModel failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Call POST /v1/images/generations (text-to-image)
     */
    private function imageGeneration(string $prompt, string $model, int $n, string $size): array
    {
        $payload = [
            'model'  => $model,
            'prompt' => $prompt,
            'n'      => $n,
            'size'   => $size,
        ];

        $response = $this->callApi('/images/generations', $payload);
        return $this->parseResponse($response);
    }

    /**
     * Call POST /v1/images/edits (image-to-image with base64)
     */
    private function imageEdit(string $prompt, string $imageBase64, string $model, int $n, string $size): array
    {
        $payload = [
            'model'   => $model,
            'image'   => $imageBase64,
            'prompt'  => $prompt,
            'n'       => $n,
            'size'    => $size,
        ];

        $response = $this->callApi('/images/edits', $payload);
        return $this->parseResponse($response);
    }

    /**
     * Execute HTTP POST via cURL.
     */
    private function callApi(string $endpoint, array $data): array
    {
        $url = $this->baseUrl . $endpoint;
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
                'Authorization: Bearer ' . $this->apiKey,
            ],
        ]);

        $body    = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno   = curl_errno($ch);
        $error   = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0) {
            Logger::error('AIService cURL error', [
                'endpoint' => $endpoint,
                'errno'    => $errno,
                'error'    => $error,
            ]);
            return ['error' => 'Connection error: ' . $error];
        }

        $result = json_decode($body, true);

        if (!is_array($result)) {
            Logger::error('AIService invalid JSON response', [
                'endpoint'  => $endpoint,
                'http_code' => $httpCode,
                'body'      => mb_substr($body, 0, 500),
            ]);
            return ['error' => 'Invalid response from AI service'];
        }

        // GapGPT returns errors in OpenAI format: { "error": { "message": "..." } }
        if (isset($result['error'])) {
            $msg = is_array($result['error'])
                ? ($result['error']['message'] ?? json_encode($result['error']))
                : $result['error'];
            Logger::error('AIService API error', [
                'endpoint' => $endpoint,
                'error'    => $msg,
                'http'     => $httpCode,
            ]);
            return ['error' => $msg];
        }

        // Successful response — attach HTTP code for downstream parsing
        $result['_http_code'] = $httpCode;
        return $result;
    }

    /**
     * Parse API response to extract image URLs.
     */
    private function parseResponse(array $response): array
    {
        if (isset($response['error'])) {
            return $response; // Already an error
        }

        $data = $response['data'] ?? [];
        if (empty($data)) {
            Logger::error('AIService empty data in response', ['response' => $response]);
            return ['error' => 'AI service returned no images'];
        }

        $images = [];
        foreach ($data as $item) {
            if (isset($item['url'])) {
                $images[] = $item['url'];
            } elseif (isset($item['b64_json'])) {
                // If the API returns base64, we could handle it, but GapGPT returns URLs
                // For now, skip — we expect URLs
            }
        }

        if (empty($images)) {
            Logger::error('AIService no image URLs in response', ['data' => $data]);
            return ['error' => 'No image URLs found in AI response'];
        }

        return ['images' => $images];
    }
}