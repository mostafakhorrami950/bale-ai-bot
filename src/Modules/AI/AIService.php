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
     * Generate image. Routes to correct provider.
     *
     * $params:
     *   - model      (string) required — model name
     *   - prompt     (string) required
     *   - provider   (string) — "gapgpt", "metisai", "openrouter"
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

        $provider = strtolower(trim($provider));

        \Core\AILogger::log('AISERVICE_GENERATE', [
            'provider' => $provider,
            'model' => $modelName,
            'has_image' => !empty($image),
            'prompt_len' => mb_strlen($prompt),
        ]);

        $startTime = microtime(true);

        if ($provider === 'metisai') {
            $parts = explode(' ', trim($modelName));
            $cleanModel = $parts[0];
            $result = $this->metisaiGenerate($prompt, $cleanModel, $image, $modelData);
        } elseif ($provider === 'openrouter') {
            $result = $this->openrouterGenerate($prompt, $modelName, $image, $modelData);
        } elseif ($image) {
            $result = $this->gapgptImageEdit($prompt, $image, $modelName);
        } else {
            $result = $this->gapgptImageGeneration($prompt, $modelName);
        }

        $duration = microtime(true) - $startTime;
        \Core\AILogger::log('AISERVICE_RESULT', [
            'provider' => $provider,
            'model' => $modelName,
            'duration' => round($duration, 2) . 's',
            'has_images' => isset($result['images']) ? count($result['images']) : 0,
            'error' => $result['error'] ?? null,
        ]);

        return $result;
    }

    // ═══════════════════════════════════════════════
    //   OpenRouter (Chat Completions with images)
    // ═══════════════════════════════════════════════

    private function openrouterGenerate(string $prompt, string $modelName, ?string $image, ?array $modelData): array
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

        // Build messages
        $messages = [];

        // If img2img: include the image as a user message content part
        if (!empty($image)) {
            $imageContent = $this->resolveImageContent($image);
            $messages[] = [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    ['type' => 'image_url', 'image_url' => ['url' => $imageContent]],
                ],
            ];
        } else {
            $messages[] = [
                'role' => 'user',
                'content' => $prompt,
            ];
        }

        // Determine modality
        $modalities = ['image']; // image-only by default
        // Gemini-like models output both text+image
        if (str_contains($modelName, 'gemini')) {
            $modalities = ['image', 'text'];
        }

        $payload = [
            'model'      => $modelName,
            'messages'   => $messages,
            'modalities' => $modalities,
            'stream'     => false,
        ];

        // image_config (for supported models) — only set if not "auto"
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
            'model'      => $modelName,
            'modalities' => $modalities,
            'has_image'  => !empty($image),
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
            // Detect mime
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
                        $this->aiLog('DEBUG', 'Found image in images[]', ['url_type' => str_starts_with($url, 'data:') ? 'data_uri' : 'http', 'len' => strlen($url)]);
                    }
                }
            }
            
            // Also check content for data URIs (Gemini models often return images as data URIs in content)
            $content = $message['content'] ?? '';
            if (is_string($content) && str_starts_with($content, 'data:image')) {
                $imageUrls[] = $content;
                $this->aiLog('DEBUG', 'Found data URI in content', ['len' => strlen($content)]);
            }
            // Check if content is an array with image_url parts
            if (is_array($content)) {
                foreach ($content as $part) {
                    if (($part['type'] ?? '') === 'image_url' && !empty($part['image_url']['url'])) {
                        $imageUrls[] = $part['image_url']['url'];
                        $this->aiLog('DEBUG', 'Found image in content array');
                    }
                }
            }
        }

        if (empty($imageUrls)) {
            $this->aiLog('WARN', 'OpenRouter: no images in response', ['full' => mb_substr(json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 5000)]);
            return ['error' => 'OpenRouter: تصویری در پاسخ یافت نشد'];
        }

        $this->aiLog('INFO', 'OpenRouter images found', ['count' => count($imageUrls), 'first_type' => str_starts_with($imageUrls[0], 'data:') ? 'data_uri' : 'http']);

        // Download all images and convert to base64 data URIs
        // Bale Bot API cannot download from OpenRouter URLs directly
        // Bale's sendPhoto only supports: file_id, HTTP URL (max 5MB), or multipart upload
        // HTTP URLs must be publicly accessible. OpenRouter generated images may not be.
        $downloadedImages = [];
        foreach ($imageUrls as $url) {
            if (str_starts_with($url, 'data:')) {
                $downloadedImages[] = $url;
                continue;
            }
            
            // Try to download the image
            $this->aiLog('INFO', 'Downloading OpenRouter image', ['url' => substr($url, 0, 150)]);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; MobixBot/1.0)',
                CURLOPT_HTTPHEADER => [
                    'Accept: image/webp,image/*,*/*;q=0.8',
                ],
            ]);
            $imgData = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $curlError = curl_error($ch);
            $downloadSize = strlen($imgData ?? '');
            curl_close($ch);
            
            $this->aiLog('INFO', 'Download result', [
                'http' => $httpCode,
                'size' => $downloadSize,
                'mime' => $contentType,
                'error' => $curlError ?: null,
            ]);
            
            if ($httpCode === 200 && $downloadSize > 500) {
                // Detect mime type from Content-Type or magic bytes
                $mime = $contentType ?: 'image/png';
                if (str_contains($mime, 'jpeg') || str_contains($mime, 'jpg')) $mime = 'image/jpeg';
                elseif (str_contains($mime, 'png')) $mime = 'image/png';
                elseif (str_contains($mime, 'gif')) $mime = 'image/gif';
                elseif (str_contains($mime, 'webp')) $mime = 'image/webp';
                else {
                    // Detect by magic bytes
                    $first = substr($imgData, 0, 4);
                    if (str_starts_with($first, "\xff\xd8")) $mime = 'image/jpeg';
                    elseif (str_starts_with($first, "\x89PNG")) $mime = 'image/png';
                    elseif (str_starts_with($first, "GIF8")) $mime = 'image/gif';
                }
                $b64 = base64_encode($imgData);
                $dataUri = 'data:' . $mime . ';base64,' . $b64;
                $downloadedImages[] = $dataUri;
                $this->aiLog('INFO', 'Image converted to data URI', ['mime' => $mime, 'b64_len' => strlen($b64)]);
            } else {
                $this->aiLog('ERROR', 'OpenRouter image download failed. URL won\'t work with Bale.', [
                    'http' => $httpCode,
                    'size' => $downloadSize,
                    'curl_err' => $curlError ?: null,
                ]);
                // Do NOT return URL — Bale Bot API cannot download OpenRouter signed URLs.
                // Return error so the user gets a clear message.
                return ['error' => 'امکان دانلود تصویر از سرور OpenRouter وجود ندارد. لطفاً دوباره تلاش کنید.'];
            }
        }

        return ['images' => $downloadedImages];
    }

    // ═══════════════════════════════════════════════
    //   GapGPT
    // ═══════════════════════════════════════════════

    private function gapgptImageGeneration(string $prompt, string $model): array
    {
        return $this->gapgptParse($this->gapgptCall('/images/generations', [
            'model' => $model, 'prompt' => $prompt, 'n' => 1, 'size' => '1024x1024',
        ]));
    }

    private function imageToTempFile(string $image): array
    {
        $imageData = null;
        if (str_starts_with($image, 'data:image/')) {
            $parts = explode('base64,', $image, 2);
            $imageData = base64_decode($parts[1] ?? $parts[0], true);
        } elseif (filter_var($image, FILTER_VALIDATE_URL)) {
            $ch = curl_init($image);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => true, CURLOPT_SSL_VERIFYPEER => true]);
            $imageData = curl_exec($ch);
            curl_close($ch);
        } else {
            $imageData = base64_decode($image, true);
        }
        if (empty($imageData) || strlen($imageData) < 100) throw new \Exception('تصویر نامعتبر');
        $mime = 'image/jpeg'; $ext = 'jpg';
        $first = substr($imageData, 0, 4);
        if (str_starts_with($first, "\x89PNG")) { $mime = 'image/png'; $ext = 'png'; }
        elseif (str_starts_with($first, "\xff\xd8")) { $mime = 'image/jpeg'; $ext = 'jpg'; }
        if (function_exists('finfo_open')) {
            $detected = finfo_buffer(finfo_open(FILEINFO_MIME_TYPE), $imageData);
            if ($detected && str_starts_with($detected, 'image/')) $mime = $detected;
        }
        $tmpFile = tempnam(sys_get_temp_dir(), 'gpt_') . '.' . $ext;
        file_put_contents($tmpFile, $imageData);
        return [$tmpFile, $mime];
    }

    private function gapgptImageEdit(string $prompt, string $image, string $model): array
    {
        try { [$tmpFile, $mime] = $this->imageToTempFile($image); }
        catch (\Throwable $e) { return ['error' => $e->getMessage()]; }

        $result = $this->tryMultipartEdit($prompt, $tmpFile, $mime, $model);
        if (isset($result['images'])) { @unlink($tmpFile); return $result; }
        $this->aiLog('WARN', 'gapgpt /images/edits failed', $result);

        $imageData = file_get_contents($tmpFile); @unlink($tmpFile);
        if (!empty($imageData)) {
            $b64 = base64_encode($imageData);
            $dataUri = "data:{$mime};base64,{$b64}";
            $enhanced = "Edit this image: {$prompt}\nReference image: {$dataUri}";
            $result2 = $this->gapgptImageGeneration($enhanced, $model);
            if (isset($result2['images'])) return $result2;
        }
        return ['error' => 'ویرایش تصویر با GapGPT ممکن نیست.'];
    }

    private function tryMultipartEdit(string $prompt, string $tmpFile, string $mime, string $model): array
    {
        $ext = pathinfo($tmpFile, PATHINFO_EXTENSION);
        $postFields = [
            'image' => new \CURLFile($tmpFile, $mime, 'image.' . $ext),
            'prompt' => $prompt, 'model' => $model, 'n' => '1', 'size' => '1024x1024',
        ];
        $ch = curl_init($this->gapgptBaseUrl . '/images/edits');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_HEADER => true, CURLOPT_VERBOSE => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->gapgptApiKey],
        ]);
        $vf = tmpfile(); curl_setopt($ch, CURLOPT_STDERR, $vf);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch); $error = curl_error($ch);
        $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
        rewind($vf); $verbose = stream_get_contents($vf); fclose($vf);
        $body = substr($response, $hs);
        $this->aiLog('INFO', 'tryMultipartEdit', ['http' => $httpCode, 'body' => mb_substr($body, 0, 1000)]);
        if ($errno || $httpCode >= 500) return ['error' => "HTTP {$httpCode}"];
        $r = json_decode($body, true);
        if (!is_array($r)) return ['error' => 'Invalid JSON'];
        if (isset($r['error'])) {
            $msg = is_array($r['error']) ? ($r['error']['message'] ?? json_encode($r['error'])) : $r['error'];
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

    // ═══════════════════════════════════════════════
    //   MetisAI
    // ═══════════════════════════════════════════════

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
        if ($hasImage && $imgSupp) $args[$imgParam] = $image;

        $payload = [
            'model' => ['name' => $apiName, 'model' => $apiModel],
            'operation' => 'Imagine',
            'args' => $args,
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

    // ═══════════════════════════════════════════════
    //   Model helpers
    // ═══════════════════════════════════════════════

    /**
     * Get an image generation model by ID.
     */
    public function getModelById(int $id): ?array
    {
        try {
            $db = \Database\Database::getInstance();
            $stmt = $db->query("SELECT id, name, provider, cost_per_image, is_active, model_config FROM ai_image_models WHERE id = ? AND is_active = 1", [$id]);
            $row = $stmt->fetch();
            if ($row) return $row;
            $fb = $this->getFirstActiveImageModel();
            if ($fb) return $fb;
            return ['id' => 0, 'name' => 'gpt-image-1', 'provider' => 'gapgpt', 'cost_per_image' => 2, 'is_active' => 1, 'model_config' => null];
        } catch (\Throwable $e) { Logger::error('getModelById', ['id' => $id, 'error' => $e->getMessage()]); return null; }
    }

    /**
     * Get active image generation model by ID.
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
}