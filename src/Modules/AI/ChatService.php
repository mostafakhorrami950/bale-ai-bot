<?php

namespace Modules\AI;

use Core\Config;
use Database\Database;
use Database\Logger;

class ChatService
{
    private const PROVIDERS = [
        'openrouter' => [
            'base_url' => 'https://openrouter.ai/api/v1',
            'key_env'  => 'OPENROUTER_API_KEY',
            'endpoint' => '/chat/completions',
        ],
        'gapgpt' => [
            'base_url' => 'https://api.gapgpt.app/v1',
            'key_env'  => 'GAPGPT_API_KEY',
            'endpoint' => '/chat/completions',
        ],
        'metisai' => [
            'base_url' => 'https://api.metisai.ir/api/v1',
            'key_env'  => 'METISAI_API_KEY',
            'endpoint' => '/wrapper/',
        ],
    ];

    private function buildEndpoint(string $provider, array $modelData): string
    {
        $provider = strtolower($provider);
        $cfg = self::PROVIDERS[$provider] ?? self::PROVIDERS['openrouter'];

        if ($provider === 'metisai') {
            $mc = json_decode($modelData['model_config'] ?? '{}', true);
            $wrapperName = $mc['metisai']['model_name'] ?? 'openai';
            return $cfg['base_url'] . '/wrapper/' . trim($wrapperName) . '/chat/completions';
        }

        return $cfg['base_url'] . $cfg['endpoint'];
    }

    private function getApiKey(string $provider): string
    {
        $provider = strtolower($provider);
        $envKey = self::PROVIDERS[$provider]['key_env'] ?? 'OPENROUTER_API_KEY';
        $key = Config::get($envKey, '');
        if (empty($key)) {
            $key = Config::get('OPENROUTER_API_KEY', '');
        }
        return $key;
    }

    public function chat(array $messages, string $model, array $modelData): array
    {
        $provider = strtolower($modelData['provider'] ?? 'openrouter');
        $apiKey = $this->getApiKey($provider);

        \Core\AILogger::log('CHATSERVICE_START', [
            'provider' => $provider,
            'model' => $model,
            'msg_count' => count($messages),
            'has_api_key' => !empty($apiKey),
        ]);

        if (empty($apiKey)) {
            $msg = "API Key برای ارائه‌دهنده «{$provider}» تنظیم نشده است.";
            \Core\AILogger::error('chat', $msg, ['provider' => $provider]);
            return ['error' => $msg];
        }

        $payload = [
            'model'      => $model,
            'messages'   => $messages,
            'stream'     => false,
        ];

        // Add plugins for PDF file processing (OpenRouter only — let OpenRouter handle the URL)
        if ($provider === 'openrouter') {
            $hasPdf = false;
            foreach ($messages as $msg) {
                if (is_array($msg['content'] ?? null)) {
                    foreach ($msg['content'] as $part) {
                        if (($part['type'] ?? '') === 'file' && isset($part['file']['file_data']) && str_contains($part['file']['file_data'], 'application/pdf')) {
                            $hasPdf = true;
                            break 2;
                        }
                    }
                }
            }
            if ($hasPdf) {
                $payload['plugins'] = [
                    ['id' => 'file-parser', 'pdf' => ['engine' => 'mistral-ocr']]
                ];
            }
        }

        $extraHeaders = [];
        if ($provider === 'openrouter') {
            $extraHeaders[] = 'HTTP-Referer: https://mobixai.ir';
            $extraHeaders[] = 'X-OpenRouter-Title: MobixAI Bot';
        }

        if (in_array($provider, ['gapgpt', 'metisai'])) {
            $mc = json_decode($modelData['model_config'] ?? '{}', true);
            if ($provider === 'metisai') {
                $mcfg = $mc['metisai'] ?? [];
                if (!empty($mcfg['model_model'])) {
                    $payload['model'] = $mcfg['model_model'];
                }
                if (!empty($mcfg['size'])) {
                    $payload['size'] = $mcfg['size'];
                }
            }
        }

        $endpoint = $this->buildEndpoint($provider, $modelData);

        \Core\AILogger::request($provider, $endpoint, $payload);

        $startTime = microtime(true);
        $result = $this->providerCall($endpoint, $payload, $apiKey, $extraHeaders);
        $duration = microtime(true) - $startTime;

        \Core\AILogger::response($provider, $result['http_code'] ?? 0, $result['raw_body'] ?? null, $duration);

        if (isset($result['error'])) {
            \Core\AILogger::error($provider, $result['error'], ['model' => $model]);
            return $result;
        }

        $responseText = $result['response'] ?? '';
        $outputChars = mb_strlen($responseText);
        $actualCostUsd = $result['cost_usd'] ?? 0.0;
        $inputTokens = $result['input_tokens'] ?? 0;
        $outputTokens = $result['output_tokens'] ?? 0;

        \Core\AILogger::log('CHATSERVICE_DONE', [
            'provider' => $provider,
            'model' => $model,
            'actual_cost_usd' => $actualCostUsd,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'duration' => round($duration, 2) . 's',
        ]);

        return [
            'response'       => $responseText,
            'output_chars'   => $outputChars,
            'cost_usd'       => $actualCostUsd,
            'input_tokens'   => $inputTokens,
            'output_tokens'  => $outputTokens,
            'error'          => null,
        ];
    }

    public static function calcCreditCost(int $chars, float $costPerChar): float
    {
        if ($costPerChar <= 0 || $chars <= 0) return 0.0;
        return round($chars * $costPerChar, 6);
    }

    /**
     * Build messages for OpenRouter.
     * 
     * Files (PDF, doc, txt, etc.) are sent as public URLs if they start with 'http'.
     * Images are sent as data:image URIs.
     */
    public static function buildMessagesFromHistory(array $rows, string $newUserText = null, string $fileContent = null, string $fileType = null): array
    {
        $messages = [];

        $messages[] = [
            'role' => 'system',
            'content' => 'شما یک دستیار هوش مصنوعی مفید هستید. به زبان فارسی پاسخ دهید.'
        ];

        foreach ($rows as $row) {
            $role = $row['role'];
            if ($role === 'system') continue;

            if (!empty($row['file_type']) && !empty($row['file_content'])) {
                $parts = [];
                $caption = trim($row['content'] ?? '');
                if (!empty($caption)) {
                    $parts[] = ['type' => 'text', 'text' => $caption];
                }

                $fileTypeVal = $row['file_type'];
                $fileContentVal = $row['file_content'];

                // Detect if the file content is an image URL (http/https ending in image extension)
                $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                $isImageUrl = $fileTypeVal === 'image' ||
                    (str_starts_with($fileContentVal, 'http') && in_array(strtolower(pathinfo(parse_url($fileContentVal, PHP_URL_PATH), PATHINFO_EXTENSION)), $imageExts));

                if ($fileTypeVal === 'input_audio') {
                    // Audio as base64 data — OpenRouter expects raw base64, NOT data URI
                    // Extract format and raw base64 from data URI (e.g. data:audio/wav;base64,SUQz...)
                    $format = 'wav';
                    $rawBase64 = $fileContentVal;
                    if (preg_match('/^data:audio\/(\w+);base64,(.+)$/s', $fileContentVal, $m)) {
                        $format = $m[1];
                        $rawBase64 = $m[2];
                    }
                    $parts[] = [
                        'type' => 'input_audio',
                        'input_audio' => [
                            'data' => $rawBase64,
                            'format' => $format
                        ]
                    ];
                } elseif ($fileTypeVal === 'video_url') {
                    // Video as URL (public HTTP URL or base64 data URI)
                    $parts[] = [
                        'type' => 'video_url',
                        'video_url' => ['url' => $fileContentVal]
                    ];
                } elseif ($isImageUrl) {
                    // Image as URL (data URI or public HTTP URL)
                    $parts[] = [
                        'type' => 'image_url',
                        'image_url' => ['url' => $fileContentVal]
                    ];
                } elseif (str_starts_with($fileContentVal, 'http')) {
                    // File as public URL - use file type with URL
                    $mimeMap = [
                        'pdf' => 'application/pdf',
                        'txt' => 'text/plain',
                        'doc' => 'application/msword',
                        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    ];
                    $mime = $mimeMap[$fileTypeVal] ?? 'application/octet-stream';
                    $parts[] = [
                        'type' => 'file',
                        'file' => [
                            'filename' => 'document.' . $fileTypeVal,
                            'file_data' => $fileContentVal
                        ]
                    ];
                } else {
                    // Data URI file
                    $parts[] = [
                        'type' => 'file',
                        'file' => [
                            'filename' => 'document.' . $fileTypeVal,
                            'file_data' => $fileContentVal
                        ]
                    ];
                }

                $messages[] = ['role' => $role, 'content' => $parts];
            } else {
                $messages[] = ['role' => $role, 'content' => $row['content']];
            }
        }

        if ($newUserText !== null) {
            if ($fileContent !== null && $fileType !== null) {
                $parts = [];
                if (!empty(trim($newUserText))) {
                    $parts[] = ['type' => 'text', 'text' => $newUserText];
                }

                if ($fileType === 'image') {
                    $parts[] = [
                        'type' => 'image_url',
                        'image_url' => ['url' => $fileContent]
                    ];
                } elseif (str_starts_with($fileContent, 'http')) {
                    // Public URL file
                    $mimeMap = [
                        'pdf' => 'application/pdf',
                        'txt' => 'text/plain',
                        'doc' => 'application/msword',
                        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    ];
                    $mime = $mimeMap[$fileType] ?? 'application/octet-stream';
                    $parts[] = [
                        'type' => 'file',
                        'file' => [
                            'filename' => 'document.' . $fileType,
                            'file_data' => $fileContent
                        ]
                    ];
                } else {
                    $parts[] = [
                        'type' => 'file',
                        'file' => [
                            'filename' => 'document.' . $fileType,
                            'file_data' => $fileContent
                        ]
                    ];
                }

                $messages[] = ['role' => 'user', 'content' => $parts];
            } else {
                $messages[] = ['role' => 'user', 'content' => $newUserText];
            }
        }

        return $messages;
    }

    public static function estimateFileChars(string $fileType, string $fileContent): int
    {
        if ($fileType === 'image') return 1000;
        if (in_array($fileType, ['pdf', 'PDF'])) return 2000;
        // Audio files are sent as base64 data URI — the string length is NOT the content length
        // OpenRouter charges by token, not by byte. Use a fixed estimate: ~30 chars per second for 30 sec = 900
        if ($fileType === 'input_audio') return 900;
        // Video files are sent as URL — no content to measure, charge for duration
        if ($fileType === 'video_url') return 1000;
        if (str_starts_with($fileType, 'audio/')) return 900;
        // Fallback: if the content is a URL or data URI, don't charge for it
        if (str_starts_with($fileContent, 'http') || str_starts_with($fileContent, 'data:')) return 1000;
        return mb_strlen($fileContent);
    }

    private function providerCall(string $endpoint, array $payload, string $apiKey, array $extraHeaders = []): array
    {
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ];
        foreach ($extraHeaders as $h) {
            $headers[] = $h;
        }

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        $body     = curl_exec($ch);
        $errno    = curl_errno($ch);
        $error    = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) return ['error' => 'خطای اتصال: ' . $error, 'http_code' => $httpCode, 'raw_body' => $body];
        if ($httpCode >= 400) return ['error' => "HTTP {$httpCode}: " . mb_substr($body, 0, 500), 'http_code' => $httpCode, 'raw_body' => $body];

        $r = json_decode($body, true);
        if (!is_array($r)) return ['error' => 'پاسخ نامعتبر از API', 'http_code' => $httpCode, 'raw_body' => $body];

        if (isset($r['error'])) {
            $msg = is_array($r['error']) ? ($r['error']['message'] ?? json_encode($r['error'])) : $r['error'];
            return ['error' => $msg, 'http_code' => $httpCode, 'raw_body' => $body];
        }

        $text = '';
        $choices = $r['choices'] ?? [];
        foreach ($choices as $choice) {
            $text .= $choice['message']['content'] ?? '';
        }

        if (empty(trim($text))) return ['error' => 'پاسخ خالی از API دریافت شد', 'http_code' => $httpCode, 'raw_body' => $body];

        $costUsd = 0.0;
        if (isset($r['usage']['cost'])) {
            $costUsd = (float)$r['usage']['cost'];
        } elseif (isset($r['usage']['cost_details']['upstream_inference_cost'])) {
            $costUsd = (float)$r['usage']['cost_details']['upstream_inference_cost'];
        }

        $inputTokens = 0;
        $outputTokens = 0;
        if (isset($r['usage']['prompt_tokens'])) {
            $inputTokens = (int)$r['usage']['prompt_tokens'];
        }
        if (isset($r['usage']['completion_tokens'])) {
            $outputTokens = (int)$r['usage']['completion_tokens'];
        }

        return [
            'response' => $text,
            'http_code' => $httpCode,
            'raw_body' => $body,
            'cost_usd' => $costUsd,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
        ];
    }
}