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
     * Generate image. Routes to correct provider.
     *
     * $params:
     *   - model      (string) required — model name (cleaned, first word)
     *   - prompt     (string) required
     *   - provider   (string) — "gapgpt", "metisai"
     *   - image      (string|null) URL or base64 for img2img
     *   - model_data (array|null) full model row from DB (includes model_config JSON)
     */
    public function generate(array $params): array
    {
        $prompt    = $params['prompt']    ?? '';
        $modelName = $params['model']     ?? '';
        $provider  = $params['provider']  ?? '';
        $image     = $params['image']     ?? null;
        $modelData = $params['model_data'] ?? null;

        if (empty($prompt)) return ['error' => 'متن درخواست (Prompt) الزامی است'];
        if (empty($modelName)) return ['error' => 'مدل الزامی است'];

        if (strtolower($provider) === 'metisai') {
            $parts = explode(' ', trim($modelName));
            $cleanModel = $parts[0];
            return $this->metisaiGenerate($prompt, $cleanModel, $image, $modelData);
        }

        // GapGPT
        if ($image) {
            return $this->gapgptImageEdit($prompt, $image, $modelName);
        }
        return $this->gapgptImageGeneration($prompt, $modelName);
    }

    // ─── GapGPT ─────────────────────────────────

    private function gapgptImageGeneration(string $prompt, string $model): array
    {
        return $this->gapgptParse($this->gapgptCall('/images/generations', [
            'model' => $model, 'prompt' => $prompt, 'n' => 1, 'size' => '1024x1024',
        ]));
    }

    /**
     * Convert image from any format (base64, URL) to temp file and detect mime.
     * Returns [tmpPath, mimeType] or throws on failure.
     */
    private function imageToTempFile(string $image): array
    {
        $imageData = null;

        // 1) data: URI
        if (str_starts_with($image, 'data:image/')) {
            $parts = explode('base64,', $image, 2);
            $imageData = base64_decode($parts[1] ?? $parts[0], true);
        }
        // 2) URL
        elseif (filter_var($image, FILTER_VALIDATE_URL)) {
            $ch = curl_init($image);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
                CURLOPT_FOLLOWLOCATION => true, CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $imageData = curl_exec($ch);
            curl_close($ch);
        }
        // 3) Raw base64
        else {
            $imageData = base64_decode($image, true);
        }

        if (empty($imageData) || strlen($imageData) < 100) {
            throw new \Exception('تصویر ورودی نامعتبر است (کمتر از 100 بایت)');
        }

        // Detect mime from magic bytes
        $mime = 'image/jpeg';
        $ext  = 'jpg';
        $firstBytes = substr($imageData, 0, 4);
        if (str_starts_with($firstBytes, "\x89PNG")) {
            $mime = 'image/png';
            $ext  = 'png';
        } elseif (str_starts_with($firstBytes, "\xff\xd8")) {
            $mime = 'image/jpeg';
            $ext  = 'jpg';
        } elseif (str_starts_with($firstBytes, "GIF8") || str_starts_with($firstBytes, "GIF89") || str_starts_with($firstBytes, "GIF87")) {
            $mime = 'image/gif';
            $ext  = 'gif';
        } elseif (str_starts_with($firstBytes, "RIFF") && substr($imageData, 8, 4) === "WEBP") {
            $mime = 'image/webp';
            $ext  = 'webp';
        }

        // Try finfo if available
        if (function_exists('finfo_open') && function_exists('finfo_buffer')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $detected = finfo_buffer($finfo, $imageData);
            finfo_close($finfo);
            if ($detected && $detected !== 'application/octet-stream' && str_starts_with($detected, 'image/')) {
                $mime = $detected;
                $extMap = ['png' => 'png', 'jpeg' => 'jpg', 'jpg' => 'jpg', 'gif' => 'gif', 'webp' => 'webp'];
                $ext = $extMap[explode('/', $mime)[1]] ?? $ext;
            }
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'gpt_') . '.' . $ext;
        file_put_contents($tmpFile, $imageData);

        return [$tmpFile, $mime];
    }

    private function gapgptImageEdit(string $prompt, string $image, string $model): array
    {
        try {
            [$tmpFile, $mime] = $this->imageToTempFile($image);
        } catch (\Throwable $e) {
            Logger::error('gapgptImageEdit: image decode failed', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }

        $ext = pathinfo($tmpFile, PATHINFO_EXTENSION);
        $curlFile = new \CURLFile($tmpFile, $mime, 'image.' . $ext);

        $postFields = [
            'image'  => $curlFile,
            'prompt' => $prompt,
            'model'  => $model,
            'n'      => '1',
            'size'   => '1024x1024',
        ];

        $url = $this->gapgptBaseUrl . '/images/edits';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST          => true,
            CURLOPT_POSTFIELDS    => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT       => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_VERBOSE       => true,
            CURLOPT_HEADER        => true,
            CURLOPT_HTTPHEADER    => [
                'Authorization: Bearer ' . $this->gapgptApiKey,
            ],
        ]);
        // Capture verbose output
        $verboseFile = tmpfile();
        curl_setopt($ch, CURLOPT_STDERR, $verboseFile);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno    = curl_errno($ch);
        $error    = curl_error($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        // Get verbose log
        rewind($verboseFile);
        $verboseLog = stream_get_contents($verboseFile);
        fclose($verboseFile);

        @unlink($tmpFile);

        // Extract header & body
        $responseHeader = substr($response, 0, $headerSize);
        $responseBody   = substr($response, $headerSize);

        Logger::info('GapGPT edits response', [
            'http'       => $httpCode,
            'errno'      => $errno,
            'error'      => $error,
            'header'     => mb_substr($responseHeader, 0, 500),
            'body'       => mb_substr($responseBody, 0, 2000),
            'verbose'    => mb_substr($verboseLog, 0, 1000),
            'mime'       => $mime,
            'file_ext'   => $ext,
            'file_size'  => filesize($tmpFile) ?: 'deleted',
        ]);

        if ($errno) return ['error' => 'Connection error: ' . $error];

        $r = json_decode($responseBody, true);
        if (!is_array($r)) {
            Logger::error('gapgptImageEdit: invalid JSON', ['body_raw' => mb_substr($responseBody, 0, 1000)]);
            return ['error' => 'Invalid response from AI service (HTTP ' . $httpCode . ')'];
        }
        if (isset($r['error'])) {
            $msg = is_array($r['error']) ? ($r['error']['message'] ?? json_encode($r['error'])) : $r['error'];
            Logger::error('gapgptImageEdit: API error', ['msg' => $msg]);
            return ['error' => $msg];
        }
        return $this->gapgptParse($r);
    }

    private function gapgptCall(string $endpoint, array $data): array
    {
        $ch = curl_init($this->gapgptBaseUrl . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $this->gapgptApiKey],
        ]);
        $body = curl_exec($ch); $errno = curl_errno($ch); $error = curl_error($ch); curl_close($ch);
        if ($errno) return ['error' => 'Connection error: ' . $error];
        $r = json_decode($body, true);
        if (!is_array($r)) return ['error' => 'Invalid response from AI service'];
        if (isset($r['error'])) return ['error' => is_array($r['error']) ? ($r['error']['message'] ?? json_encode($r['error'])) : $r['error']];
        return $r;
    }

    private function gapgptParse(array $r): array
    {
        if (isset($r['error'])) return $r;
        $images = [];
        foreach (($r['data'] ?? []) as $item) {
            if (isset($item['url'])) $images[] = $item['url'];
        }
        if (empty($images)) return ['error' => 'هیچ تصویری دریافت نشد'];
        return ['images' => $images];
    }

    // ─── MetisAI (per-model config supported) ─────

    private function metisaiGenerate(string $prompt, string $modelName, ?string $image, ?array $modelData): array
    {
        $cfg = [];
        if ($modelData && !empty($modelData['model_config'])) {
            $raw = is_string($modelData['model_config']) ? json_decode($modelData['model_config'], true) : $modelData['model_config'];
            $cfg = is_array($raw) ? $raw : [];
        }
        $m = $cfg['metisai'] ?? [];

        $apiName  = $m['model_name'] ?? 'openai';
        $apiModel = $m['model_model'] ?? $modelName;
        $imgParam = $m['image_param'] ?? 'image';
        $imgSupp  = $m['supports_image'] ?? true;
        $sz       = $m['size'] ?? 'auto';
        $qual     = $m['quality'] ?? 'medium';
        $fmt      = $m['output_format'] ?? 'png';

        $hasImage = !empty($image);

        $args = ['prompt' => $prompt, 'moderation' => 'low', 'output_format' => $fmt, 'quality' => $qual];
        if ($sz !== 'auto') $args['size'] = $sz;

        if ($hasImage && $imgSupp) {
            $args[$imgParam] = $image;
        }

        $payload = [
            'model'     => ['name' => $apiName, 'model' => $apiModel],
            'operation' => 'Imagine',
            'args'      => $args,
        ];

        $res = $this->metisaiPost('/generate', $payload);
        if (isset($res['error'])) return $res;

        $taskId = $res['id'] ?? null;
        if (!$taskId) return ['error' => 'شناسه تسک از سرور دریافت نشد'];

        return $this->metisaiPoll($taskId);
    }

    private function metisaiPoll(string $taskId): array
    {
        $lastResponse = '';
        for ($i = 1; $i <= max(1, (int)($this->timeout / 5)); $i++) {
            $r = $this->metisaiGet("/generate/$taskId");
            if (isset($r['error'])) return $r;
            $s = $r['status'] ?? '';
            $lastResponse = json_encode($r, JSON_UNESCAPED_UNICODE);

            if ($s === 'COMPLETED') {
                $images = [];
                foreach (($r['generations'] ?? []) as $g) {
                    if (is_array($g) && !empty($g['url'])) $images[] = $g['url'];
                    elseif (is_string($g)) $images[] = $g;
                }
                if (empty($images)) return ['error' => 'تصویری در پاسخ یافت نشد'];
                return ['images' => $images];
            }
            if (in_array($s, ['ERROR', 'CANCELLED', 'FAILED'], true)) {
                $err = $r['error'] ?? ($s === 'CANCELLED' ? 'لغو شده' : 'خطا در تولید');
                if (is_array($err)) $err = $err['message'] ?? json_encode($err);
                Logger::error('MetisAI failed', ['task_id' => $taskId, 'status' => $s, 'error' => $err, 'raw' => mb_substr($lastResponse, 0, 3000)]);
                return ['error' => "تولید تصویر ناموفق: $err"];
            }
            sleep(5);
        }
        Logger::error('MetisAI timeout', ['task_id' => $taskId, 'last_response' => mb_substr($lastResponse, 0, 2000)]);
        return ['error' => 'زمان تولید تصویر به پایان رسید'];
    }

    private function metisaiGet(string $path): array
    {
        $ch = curl_init($this->metisaiBaseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $this->metisaiApiKey],
        ]);
        $body = curl_exec($ch); curl_close($ch);
        $r = json_decode($body, true);
        if (!is_array($r)) return ['error' => 'پاسخ نامعتبر از متیس'];
        if (isset($r['error'])) {
            $msg = is_array($r['error']) ? ($r['error']['message'] ?? json_encode($r['error'])) : $r['error'];
            return ['error' => "MetisAI: $msg"];
        }
        return $r;
    }

    private function metisaiPost(string $endpoint, array $data): array
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ch = curl_init($this->metisaiBaseUrl . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $this->metisaiApiKey],
        ]);
        $body = curl_exec($ch); $errno = curl_errno($ch); $error = curl_error($ch); curl_close($ch);

        Logger::info('MetisAI API', ['ep' => $endpoint, 'response' => mb_substr($body ?? '', 0, 1000)]);

        if ($errno) return ['error' => "MetisAI connection error: $error"];
        $r = json_decode($body, true);
        if (!is_array($r)) return ['error' => 'Invalid response from MetisAI'];
        if (isset($r['error'])) {
            $msg = is_array($r['error']) ? ($r['error']['message'] ?? json_encode($r['error'])) : $r['error'];
            return ['error' => $msg];
        }
        return $r;
    }

    // ─── Model helpers ────────────────────────────

    public function getModelById(int $id): ?array
    {
        try {
            $db = \Database\Database::getInstance();
            $stmt = $db->query("SELECT id, name, provider, cost_per_image, is_active, model_config FROM ai_models WHERE id = ? AND is_active = 1", [$id]);
            $row = $stmt->fetch();
            if ($row) return $row;
            $fb = $this->getFirstActiveModel();
            if ($fb) return $fb;
            return ['id' => 0, 'name' => 'gpt-image-1', 'provider' => 'gapgpt', 'cost_per_image' => 2, 'is_active' => 1, 'model_config' => null];
        } catch (\Throwable $e) { Logger::error('getModelById', ['id' => $id, 'error' => $e->getMessage()]); return null; }
    }

    public function getActiveModelById(int $id): ?array
    {
        try {
            $db = \Database\Database::getInstance();
            $stmt = $db->query("SELECT id, name, provider, cost_per_image, is_active, model_config FROM ai_models WHERE id = ? AND is_active = 1", [$id]);
            return $stmt->fetch() ?: null;
        } catch (\Throwable $e) { Logger::error('getActiveModelById', ['id' => $id, 'error' => $e->getMessage()]); return null; }
    }

    public function getFirstActiveModel(): ?array
    {
        try {
            $db = \Database\Database::getInstance();
            $stmt = $db->query("SELECT id, name, provider, cost_per_image, is_active, model_config FROM ai_models WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
            return $stmt->fetch() ?: null;
        } catch (\Throwable $e) { Logger::error('getFirstActiveModel', ['error' => $e->getMessage()]); return null; }
    }

    public function getDefaultModelId(): ?int
    {
        $id = (int) Config::get('DEFAULT_AI_MODEL_ID', 0);
        if ($id > 0) { $m = $this->getModelById($id); if ($m && $m['is_active']) return (int)$m['id']; }
        try {
            $db = \Database\Database::getInstance();
            $stmt = $db->query("SELECT id FROM ai_models WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
            $row = $stmt->fetch();
            return $row ? (int)$row['id'] : null;
        } catch (\Throwable $e) { return null; }
    }
}