<?php

namespace Modules\AI;

use Core\Config;
use Database\Logger;

/**
 * VideoService — async video generation via OpenRouter API.
 * Follows OpenRouter async workflow: submit → poll → download.
 */
class VideoService
{
    private string $apiKey;
    private string $baseUrl;
    private int $timeout;
    private string $logFile;

    public function __construct()
    {
        $this->apiKey = Config::get('OPENROUTER_API_KEY', '');
        $this->baseUrl = 'https://openrouter.ai/api/v1';
        $this->timeout = (int) Config::get('AI_TIMEOUT', 300);
        $this->logFile = Config::get('AI_LOG_FILE', Config::get('BASE_PATH', __DIR__ . '/../..') . '/logs_ai.txt');
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
    }

    private function aiLog(string $level, string $message, array $context = []): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
        $line = "[{$timestamp}] [{$level}] [VideoService] {$message}{$contextStr}\n";
        @file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Submit a video generation job to OpenRouter.
     *
     * @param array $params Keys: model, prompt, duration, resolution, aspect_ratio, size,
     *                      frame_images, input_references, generate_audio, seed
     * @return array With keys: 'job_id', 'polling_url', 'status' on success, or 'error' on failure.
     */
    public function submit(array $params): array
    {
        if (empty($this->apiKey)) {
            return ['error' => 'OPENROUTER_API_KEY not configured in .env'];
        }

        $model = $params['model'] ?? '';
        $prompt = $params['prompt'] ?? '';

        if (empty($model) || empty($prompt)) {
            return ['error' => 'Model and prompt are required'];
        }

        $payload = [
            'model' => $model,
            'prompt' => $prompt,
        ];

        // Optional parameters — only include if set
        if (!empty($params['duration'])) {
            $payload['duration'] = (int) $params['duration'];
        }
        if (!empty($params['resolution'])) {
            $payload['resolution'] = $params['resolution'];
        }
        if (!empty($params['aspect_ratio'])) {
            $payload['aspect_ratio'] = $params['aspect_ratio'];
        }
        if (!empty($params['size'])) {
            $payload['size'] = $params['size'];
        }
        if (!empty($params['generate_audio'])) {
            $payload['generate_audio'] = true;
        }
        if (!empty($params['seed'])) {
            $payload['seed'] = (int) $params['seed'];
        }

        // Frame images (img2video)
        if (!empty($params['frame_images']) && is_array($params['frame_images'])) {
            $payload['frame_images'] = $params['frame_images'];
        }

        // Input references
        if (!empty($params['input_references']) && is_array($params['input_references'])) {
            $payload['input_references'] = $params['input_references'];
        }

        $url = $this->baseUrl . '/videos';
        $this->aiLog('INFO', 'Submitting video job', [
            'model' => $model,
            'url' => $url,
            'payload_keys' => array_keys($payload),
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $this->aiLog('ERROR', 'cURL error on submit', ['error' => $curlError]);
            return ['error' => 'خطا در ارتباط با سرور: ' . $curlError];
        }

        $this->aiLog('INFO', 'Submit response', ['http_code' => $httpCode, 'body' => $response]);

        $result = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE || $httpCode >= 400) {
            $errMsg = $result['error']['message'] ?? $result['error'] ?? "HTTP {$httpCode}";
            $this->aiLog('ERROR', 'Submit failed', ['http_code' => $httpCode, 'error' => $errMsg]);
            return ['error' => $errMsg];
        }

        return [
            'job_id' => $result['id'] ?? '',
            'polling_url' => $result['polling_url'] ?? '',
            'status' => $result['status'] ?? 'pending',
        ];
    }

    /**
     * Poll the video job status.
     *
     * @param string $pollingUrl The URL returned from submit()
     * @return array Keys: 'status', 'unsigned_urls', 'usage', 'error' (optional).
     */
    public function poll(string $pollingUrl): array
    {
        if (empty($pollingUrl)) {
            return ['error' => 'Polling URL is empty'];
        }

        $ch = curl_init($pollingUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Accept: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $this->aiLog('ERROR', 'Poll cURL error', ['error' => $curlError]);
            return ['error' => 'خطا در بررسی وضعیت: ' . $curlError];
        }

        $result = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE || !$result) {
            return ['error' => 'Invalid poll response'];
        }

        $status = $result['status'] ?? 'unknown';

        if ($status === 'completed') {
            $cost = $result['usage']['cost'] ?? 0;
            return [
                'status' => 'completed',
                'unsigned_urls' => $result['unsigned_urls'] ?? [],
                'usage' => $result['usage'] ?? [],
                'cost' => $cost,
            ];
        }

        if ($status === 'failed') {
            return [
                'status' => 'failed',
                'error' => $result['error'] ?? 'Unknown error',
            ];
        }

        return [
            'status' => $status, // pending, in_progress
        ];
    }

    /**
     * Download video content from unsigned_url.
     * OpenRouter requires Authorization header for content endpoint.
     * Returns binary data.
     */
    public function download(string $contentUrl): ?string
    {
        if (empty($contentUrl)) return null;

        $ch = curl_init($contentUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
            ],
        ]);

        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $this->aiLog('ERROR', 'Download cURL error', ['error' => $curlError, 'http_code' => $httpCode]);
            return null;
        }

        if ($httpCode !== 200 || empty($data)) {
            $this->aiLog('ERROR', 'Download failed', ['http_code' => $httpCode, 'url' => $contentUrl]);
            return null;
        }

        $this->aiLog('INFO', 'Download successful', ['http_code' => $httpCode, 'size' => strlen($data)]);
        return $data;
    }

    /**
     * Get supported video models from OpenRouter's /videos/models endpoint.
     * Returns array of model capabilities.
     */
    public function getModelsList(): array
    {
        if (empty($this->apiKey)) {
            return [];
        }

        $url = $this->baseUrl . '/videos/models';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Accept: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) return [];

        $result = json_decode($response, true);
        return $result['data'] ?? [];
    }
}