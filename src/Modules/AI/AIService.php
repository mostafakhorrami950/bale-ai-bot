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
        // GapGPT (OpenAI-compatible)
        $this->gapgptApiKey = Config::get('GAPGPT_API_KEY', '');
        $this->gapgptBaseUrl = rtrim(Config::get('GAPGPT_BASE_URL', 'https://api.gapgpt.app/v1'), '/');
        // MetisAI
        $this->metisaiApiKey = Config::get('METISAI_API_KEY', '');
        $this->metisaiBaseUrl = rtrim(Config::get('METISAI_BASE_URL', 'https://api.metisai.ir/api/v2'), '/');
        $this->timeout = (int) Config::get('AI_TIMEOUT', 300);
    }

    /**
     * Generate image(s). Routes to the correct provider.
     *
     * $params can contain:
     *   - model    (string) required — e.g. "gpt-image-2"
     *   - prompt   (string) required
     *   - provider (string|null) optional — "gapgpt", "metisai", or null (auto-detect)
     *   - image    (string|null) optional base64 or URL for img2img
     *   - mask     (string|null) optional base64 or URL for inpainting
     *   - size     (string) optional, default '1024x1024'
     *   - n        (int)    optional, default 1
     *
     * @param array $params
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
            return ['error' => 'Prompt is required'];
        }
        if (empty($model)) {
            return ['error' => 'Model is required'];
        }

        // Route by provider from the ai_models table
        if (strtolower($provider) === 'metisai') {
            return $this->metisaiGenerate($prompt, $model, $image, $mask, $size, $n);
        }

        // Default: GapGPT (OpenAI-compatible)
        if ($image !== null && $image !== '') {
            return $this->gapgptImageEdit($prompt, $image, $model, $n, $size);
        } else {
            return $this->gapgptImageGeneration($prompt, $model, $n, $size);
        }
    }

    // ──────────────────────────────────────────────
    //  GapGPT (OpenAI-compatible) implementation
    // ──────────────────────────────────────────────

    private function gapgptImageGeneration(string $prompt, string $model, int $n, string $size): array
    {
        $payload = [
            'model'  => $model,
            'prompt' => $prompt,
            'n'      => $n,
            'size'   => $size,
        ];

        $response = $this->gapgptCallApi('/images/generations', $payload);
        return $this->gapgptParseResponse($response);
    }

    private function gapgptImageEdit(string $prompt, string $imageBase64, string $model, int $n, string $size): array
    {
        $payload = [
            'model'   => $model,
            'image'   => $imageBase64,
            'prompt'  => $prompt,
            'n'       => $n,
            'size'    => $size,
        ];

        $response = $this->gapgptCallApi('/images/edits', $payload);
        return $this->gapgptParseResponse($response);
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
            Logger::error('AIService GapGPT cURL error', [
                'endpoint' => $endpoint,
                'errno'    => $errno,
                'error'    => $error,
            ]);
            return ['error' => 'Connection error: ' . $error, '_http_code' => $httpCode];
        }

        $result = json_decode($body, true);
        if (!is_array($result)) {
            Logger::error('AIService GapGPT invalid JSON', [
                'endpoint'  => $endpoint,
                'http_code' => $httpCode,
                'body'      => mb_substr($body, 0, 500),
            ]);
            return ['error' => 'Invalid response from AI service', '_http_code' => $httpCode];
        }

        if (isset($result['error'])) {
            $msg = is_array($result['error'])
                ? ($result['error']['message'] ?? json_encode($result['error']))
                : $result['error'];
            Logger::error('AIService GapGPT API error', [
                'endpoint' => $endpoint,
                'error'    => $msg,
                'http'     => $httpCode,
            ]);
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
            Logger::error('AIService GapGPT empty data', ['response' => $response]);
            return ['error' => 'AI service returned no images'];
        }

        $images = [];
        foreach ($data as $item) {
            if (isset($item['url'])) {
                $images[] = $item['url'];
            } elseif (isset($item['b64_json'])) {
                // Could decode and save, but for now skip
            }
        }

        if (empty($images)) {
            Logger::error('AIService GapGPT no URLs', ['data' => $data]);
            return ['error' => 'No image URLs found in AI response'];
        }

        return ['images' => $images];
    }

    // ──────────────────────────────────────────────
    //  MetisAI implementation (2-step async)
    // ──────────────────────────────────────────────

    /**
     * Step 1: Submit generation request → get generation_id
     * Step 2: Poll until ready → get result images
     */
    private function metisaiGenerate(string $prompt, string $model, ?string $image, ?string $mask, string $size, int $n): array
    {
        // Build args
        $args = [
            'prompt'        => $prompt,
            'moderation'    => 'low',
            'output_format' => 'png',
            'quality'       => 'medium',
            'size'          => $size,
        ];

        if ($image) {
            // If it's a valid URL, use as-is; otherwise treat as base64 and upload
            if (filter_var($image, FILTER_VALIDATE_URL)) {
                $args['image'] = $image;
            } else {
                // Upload base64 to a temporary hosting or pass directly
                // MetisAI may accept base64 data URIs
                $args['image'] = $image;
            }
        }
        if ($mask) {
            if (filter_var($mask, FILTER_VALIDATE_URL)) {
                $args['mask'] = $mask;
            } else {
                $args['mask'] = $mask;
            }
        }

        // Determine operation
        $operation = 'Imagine';
        if ($image && !$mask) {
            $operation = 'Imagine';
        } elseif ($image && $mask) {
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

        // Step 1: Submit generation
        $submitResult = $this->metisaiCallApi('/generate', $payload);
        if (isset($submitResult['error'])) {
            return $submitResult;
        }

        // Extract generation_id from response
        $generationId = $submitResult['generation_id'] ?? $submitResult['data']['generation_id'] ?? null;
        if (!$generationId) {
            Logger::error('AIService MetisAI: no generation_id', ['response' => $submitResult]);
            return ['error' => 'Failed to get generation ID from MetisAI'];
        }

        // Step 2: Poll for result
        $pollResult = $this->metisaiPoll($generationId);
        return $pollResult;
    }

    /**
     * Poll MetisAI for the generation result.
     * Retries up to timeout/2 seconds with 2-second intervals.
     */
    private function metisaiPoll(string $generationId): array
    {
        $maxAttempts = max(1, (int) ($this->timeout / 2)); // every 2 seconds
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            $attempt++;
            $result = $this->metisaiGetResult($generationId);

            if (isset($result['error'])) {
                return $result;
            }

            $status = $result['status'] ?? $result['data']['status'] ?? 'unknown';

            if ($status === 'completed' || $status === 'succeeded') {
                // Extract image URLs from result
                return $this->metisaiExtractImages($result);
            }

            if ($status === 'failed' || $status === 'error') {
                $errorMsg = $result['error'] ?? $result['data']['error'] ?? 'Generation failed';
                Logger::error('AIService MetisAI generation failed', [
                    'generation_id' => $generationId,
                    'response'      => $result,
                ]);
                return ['error' => 'Image generation failed: ' . $errorMsg];
            }

            // Still processing — wait 2 seconds
            sleep(2);
        }

        Logger::error('AIService MetisAI poll timeout', [
            'generation_id' => $generationId,
            'attempts'      => $maxAttempts,
        ]);
        return ['error' => 'Image generation timed out after ' . $this->timeout . ' seconds'];
    }

    /**
     * GET /generate/{generationId} to check status.
     */
    private function metisaiGetResult(string $generationId): array
    {
        $url = $this->metisaiBaseUrl . '/generate/' . urlencode($generationId);
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
            return ['error' => 'MetisAI poll invalid response', 'body' => mb_substr($body, 0, 500)];
        }

        if (isset($result['error'])) {
            $msg = is_array($result['error'])
                ? ($result['error']['message'] ?? json_encode($result['error']))
                : $result['error'];
            return ['error' => 'MetisAI poll error: ' . $msg];
        }

        return $result;
    }

    /**
     * Parse MetisAI completed result to extract image URLs.
     */
    private function metisaiExtractImages(array $result): array
    {
        $images = [];

        // Try various response structures
        $data = $result['data'] ?? $result;
        $resultsArr = $data['results'] ?? $data['images'] ?? [];

        if (is_array($resultsArr)) {
            foreach ($resultsArr as $item) {
                if (is_string($item)) {
                    // Direct URL string
                    $images[] = $item;
                } elseif (is_array($item)) {
                    if (isset($item['url'])) {
                        $images[] = $item['url'];
                    } elseif (isset($item['image'])) {
                        $images[] = $item['image'];
                    } elseif (isset($item['src'])) {
                        $images[] = $item['src'];
                    }
                }
            }
        } elseif (is_string($data['url'] ?? null)) {
            $images[] = $data['url'];
        } elseif (is_string($data['image'] ?? null)) {
            $images[] = $data['image'];
        }

        if (empty($images)) {
            Logger::error('AIService MetisAI no images in result', ['result' => $result]);
            return ['error' => 'No images found in MetisAI response'];
        }

        return ['images' => $images];
    }

    /**
     * POST to MetisAI API.
     */
    private function metisaiCallApi(string $endpoint, array $data): array
    {
        $url = $this->metisaiBaseUrl . $endpoint;
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
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

        if ($errno !== 0) {
            Logger::error('AIService MetisAI HTTP error', [
                'endpoint' => $endpoint,
                'errno'    => $errno,
                'error'    => $error,
            ]);
            return ['error' => 'MetisAI connection error: ' . $error];
        }

        $result = json_decode($body, true);
        if (!is_array($result)) {
            Logger::error('AIService MetisAI invalid JSON', [
                'endpoint'  => $endpoint,
                'http_code' => $httpCode,
                'body'      => mb_substr($body, 0, 500),
            ]);
            return ['error' => 'Invalid response from MetisAI', '_http_code' => $httpCode];
        }

        // MetisAI returns { "error": "..." } on failure
        if (isset($result['error'])) {
            $msg = is_array($result['error'])
                ? ($result['error']['message'] ?? json_encode($result['error']))
                : $result['error'];
            Logger::error('AIService MetisAI API error', [
                'endpoint' => $endpoint,
                'error'    => $msg,
                'http'     => $httpCode,
            ]);
            return ['error' => $msg, '_http_code' => $httpCode];
        }

        $result['_http_code'] = $httpCode;
        return $result;
    }

    // ──────────────────────────────────────────────
    //  Shared model utilities (unchanged)
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
            Logger::error('AIService::getModelById failed', [
                'model_id' => $id,
                'error'    => $e->getMessage()
            ]);
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
}