<?php

namespace Modules\AI;

use Core\Config;
use Database\Database;
use Database\Logger;

/**
 * ChatService — handles multi-provider chat completions (text models only).
 * Supports: OpenRouter, GapGPT (OpenAI-compatible), MetisAI Wrapper.
 *
 * Provider is determined from ai_models.provider, not hardcoded.
 * API keys come from .env (OPENROUTER_API_KEY, GAPGPT_API_KEY, METISAI_API_KEY).
 */
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
            'endpoint' => '/wrapper/',  // + {provider_name}/chat/completions
        ],
    ];

    /**
     * Build the appropriate API endpoint URL based on provider.
     */
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

    /**
     * Get the API key for a given provider.
     */
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

    /**
     * Send a chat prompt and get the AI response.
     */
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

        // Add plugins for PDF processing (OpenRouter only)
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
                    ['id' => 'file-parser', 'pdf' => ['engine' => 'cloudflare-ai']]
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

    /**
     * Calculate credit cost for input/output chars based on model settings.
     * Returns float for sub-credit precision (per-character billing).
     */
    public static function calcCreditCost(int $chars, float $costPerChar): float
    {
        if ($costPerChar <= 0 || $chars <= 0) return 0.0;
        return round($chars * $costPerChar, 6);
    }

    /**
     * Build OpenRouter-compatible messages from DB chat_messages rows.
     * 
     * IMPORTANT: According to OpenRouter multimodal docs:
     * - Images: use type 'image_url' with base64 data URI
     * - PDFs: use type 'file' with filename + file_data (base64 data:application/pdf;base64,...)
     * - Other files (doc, txt, etc.): use type 'file' 
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
                
                // Always add text caption first (even if empty, provide context)
                $caption = trim($row['content'] ?? '');
                if (!empty($caption)) {
                    $parts[] = ['type' => 'text', 'text' => $caption];
                }
                
                $fileTypeVal = $row['file_type'];
                $fileContentVal = $row['file_content'];
                
                if ($fileTypeVal === 'image') {
                    // Image: use image_url content type with data URI
                    $parts[] = [
                        'type' => 'image_url',
                        'image_url' => ['url' => $fileContentVal]
                    ];
                } elseif (in_array($fileTypeVal, ['pdf', 'PDF'])) {
                    // PDF: use file content type with filename + base64 data URI
                    $parts[] = [
                        'type' => 'file',
                        'file' => [
                            'filename' => 'document.pdf',
                            'file_data' => $fileContentVal // should be data:application/pdf;base64,...
                        ]
                    ];
                } else {
                    // Other files (doc, txt, etc.): use file content type
                    // Ensure it has proper data URI prefix
                    $fileData = $fileContentVal;
                    if (!str_starts_with($fileData, 'data:')) {
                        $mimeMap = [
                            'txt' => 'text/plain',
                            'doc' => 'application/msword',
                            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            'pdf' => 'application/pdf',
                        ];
                        $mime = $mimeMap[$fileTypeVal] ?? 'application/octet-stream';
                        $fileData = 'data:' . $mime . ';base64,' . base64_encode($fileData);
                    }
                    $parts[] = [
                        'type' => 'file',
                        'file' => [
                            'filename' => 'file.' . $fileTypeVal,
                            'file_data' => $fileData
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
                
                // Caption/text always goes first as text part
                if (!empty(trim($newUserText))) {
                    $parts[] = ['type' => 'text', 'text' => $newUserText];
                }
                
                if ($fileType === 'image') {
                    // Image: use image_url
                    $parts[] = [
                        'type' => 'image_url',
                        'image_url' => ['url' => $fileContent]
                    ];
                } elseif (in_array($fileType, ['pdf', 'PDF'])) {
                    // PDF: use file type with proper data URI
                    $parts[] = [
                        'type' => 'file',
                        'file' => [
                            'filename' => 'document.pdf',
                            'file_data' => $fileContent
                        ]
                    ];
                } else {
                    // Other files: use file type
                    $fileData = $fileContent;
                    if (!str_starts_with($fileData, 'data:')) {
                        $mimeMap = [
                            'txt' => 'text/plain',
                            'doc' => 'application/msword',
                            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            'pdf' => 'application/pdf',
                        ];
                        $mime = $mimeMap[$fileType] ?? 'application/octet-stream';
                        $fileData = 'data:' . $mime . ';base64,' . base64_encode($fileData);
                    }
                    $parts[] = [
                        'type' => 'file',
                        'file' => [
                            'filename' => 'file.' . $fileType,
                            'file_data' => $fileData
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

    /**
     * Estimate file character cost for billing (used only as fallback).
     * Primary billing uses actual tokens from OpenRouter API response.
     */
    public static function estimateFileChars(string $fileType, string $fileContent): int
    {
        if ($fileType === 'image') return 1000;
        if (in_array($fileType, ['pdf', 'PDF'])) return 2000;
        return mb_strlen($fileContent);
    }

    /**
     * Make the actual cURL call to the provider's API.
     */
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

        // Extract actual cost from OpenRouter's usage.cost field
        $costUsd = 0.0;
        if (isset($r['usage']['cost'])) {
            $costUsd = (float)$r['usage']['cost'];
        } elseif (isset($r['usage']['cost_details']['upstream_inference_cost'])) {
            $costUsd = (float)$r['usage']['cost_details']['upstream_inference_cost'];
        }

        // Extract ACTUAL token counts from API response (not estimated!)
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