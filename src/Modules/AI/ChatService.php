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
    private string $logFile;

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

    public function __construct()
    {
        $this->logFile = Config::get('AI_LOG_FILE', BASE_PATH . '/logs_ai.txt');
    }

    private function aiLog(string $level, string $message, array $ctx = []): void
    {
        $ts = date('Y-m-d H:i:s');
        $c = !empty($ctx) ? ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
        @file_put_contents($this->logFile, "[{$ts}] [{$level}] {$message}{$c}\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Build the appropriate API endpoint URL based on provider.
     */
    private function buildEndpoint(string $provider, array $modelData): string
    {
        $provider = strtolower($provider);
        $cfg = self::PROVIDERS[$provider] ?? self::PROVIDERS['openrouter'];

        if ($provider === 'metisai') {
            // MetisAI uses wrapper: /wrapper/{provider_name}/chat/completions
            // provider_name comes from model_config.metisai.model_name or defaults to 'openai'
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

        // Also check provider-specific env vars like METISAI_API_KEY, GAPGPT_API_KEY
        $key = Config::get($envKey, '');

        if (empty($key)) {
            // Fallback to OPENROUTER_API_KEY for backward compatibility
            $key = Config::get('OPENROUTER_API_KEY', '');
        }

        return $key;
    }

    /**
     * Send a chat prompt and get the AI response.
     *
     * @param array  $messages   Full message history (OpenRouter-compatible format)
     * @param string $model      Model name (e.g. "google/gemini-2.5-flash-image", "deepseek-chat")
     * @param array  $modelData  Full model row from ai_models (provider, model_config, etc.)
     * @return array ['response' => string, 'input_chars' => int, 'output_chars' => int, 'error' => string|null]
     */
    public function chat(array $messages, string $model, array $modelData): array
    {
        $provider = strtolower($modelData['provider'] ?? 'openrouter');
        $apiKey = $this->getApiKey($provider);

        if (empty($apiKey)) {
            $msg = "API Key برای ارائه‌دهنده «{$provider}» تنظیم نشده است.";
            $this->aiLog('ERROR', $msg, ['provider' => $provider]);
            return ['error' => $msg];
        }

        // Count input chars
        $inputChars = $this->countMessageChars($messages);

        $payload = [
            'model'      => $model,
            'messages'   => $messages,
            'stream'     => false,
        ];

        // OpenRouter-specific: pass referrer
        $extraHeaders = [];
        if ($provider === 'openrouter') {
            $extraHeaders[] = 'HTTP-Referer: https://mobixai.ir';
            $extraHeaders[] = 'X-OpenRouter-Title: MobixAI Bot';
        }

        // GapGPT / MetisAI: optional size/quality from model_config
        if (in_array($provider, ['gapgpt', 'metisai'])) {
            $mc = json_decode($modelData['model_config'] ?? '{}', true);
            if ($provider === 'metisai') {
                $mcfg = $mc['metisai'] ?? [];
                if (!empty($mcfg['model_model'])) {
                    $payload['model'] = $mcfg['model_model']; // override model name for MetisAI
                }
                if (!empty($mcfg['size'])) {
                    $payload['size'] = $mcfg['size'];
                }
            }
        }

        $endpoint = $this->buildEndpoint($provider, $modelData);

        $this->aiLog('INFO', 'ChatService request', [
            'provider' => $provider,
            'model' => $payload['model'],
            'endpoint' => $endpoint,
            'msg_count' => count($messages),
            'input_chars' => $inputChars,
        ]);

        $result = $this->providerCall($endpoint, $payload, $apiKey, $extraHeaders);

        if (isset($result['error'])) {
            return $result;
        }

        $responseText = $result['response'] ?? '';
        $outputChars = mb_strlen($responseText);

        return [
            'response'     => $responseText,
            'input_chars'  => $inputChars,
            'output_chars' => $outputChars,
            'error'        => null,
        ];
    }

    /**
     * Calculate credit cost for input/output chars based on model settings.
     */
    public static function calcCreditCost(int $chars, float $costPerChar): int
    {
        if ($costPerChar <= 0 || $chars <= 0) return 0;
        $cost = (int) ceil($chars * $costPerChar);
        return max(0, $cost);
    }

    /**
     * Count total characters in an array of messages.
     */
    private function countMessageChars(array $messages): int
    {
        $total = 0;
        foreach ($messages as $msg) {
            $content = $msg['content'] ?? '';
            if (is_string($content)) {
                $total += mb_strlen($content);
            } elseif (is_array($content)) {
                foreach ($content as $part) {
                    if (($part['type'] ?? '') === 'text') {
                        $total += mb_strlen($part['text'] ?? '');
                    }
                    if (($part['type'] ?? '') === 'image_url') {
                        $total += 1000;
                    }
                }
            }
        }
        return $total;
    }

    /**
     * Build OpenRouter-compatible messages from DB chat_messages rows.
     */
    public static function buildMessagesFromHistory(array $rows, string $newUserText = null, string $fileContent = null, string $fileType = null): array
    {
        $messages = [];

        // System message
        $messages[] = [
            'role' => 'system',
            'content' => 'شما یک دستیار هوش مصنوعی مفید هستید. به زبان فارسی پاسخ دهید.'
        ];

        foreach ($rows as $row) {
            $role = $row['role'];
            if ($role === 'system') continue;

            if (!empty($row['file_type']) && !empty($row['file_content'])) {
                $parts = [['type' => 'text', 'text' => $row['content']]];
                if ($row['file_type'] === 'image') {
                    $parts[] = ['type' => 'image_url', 'image_url' => ['url' => $row['file_content']]];
                } else {
                    $parts[0]['text'] .= "\n\n[فایل ضمیمه: {$row['file_type']}]\n{$row['file_content']}";
                }
                $messages[] = ['role' => $role, 'content' => $parts];
            } else {
                $messages[] = ['role' => $role, 'content' => $row['content']];
            }
        }

        if ($newUserText !== null) {
            if ($fileContent !== null && $fileType !== null) {
                $parts = [['type' => 'text', 'text' => $newUserText]];
                if ($fileType === 'image') {
                    $parts[] = ['type' => 'image_url', 'image_url' => ['url' => $fileContent]];
                } else {
                    $parts[0]['text'] .= "\n\n[فایل ضمیمه]\n{$fileContent}";
                }
                $messages[] = ['role' => 'user', 'content' => $parts];
            } else {
                $messages[] = ['role' => 'user', 'content' => $newUserText];
            }
        }

        return $messages;
    }

    /**
     * Estimate file character cost for billing.
     */
    public static function estimateFileChars(string $fileType, string $fileContent): int
    {
        if ($fileType === 'image') return 1000;
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

        $this->aiLog('INFO', 'ChatService API', [
            'http'     => $httpCode,
            'errno'    => $errno,
            'error'    => $error,
            'endpoint' => $endpoint,
            'body'     => mb_substr($body ?? '', 0, 2000),
        ]);

        if ($errno) return ['error' => 'خطای اتصال: ' . $error];
        if ($httpCode >= 400) return ['error' => "HTTP {$httpCode}: " . mb_substr($body, 0, 500)];

        $r = json_decode($body, true);
        if (!is_array($r)) return ['error' => 'پاسخ نامعتبر از API'];

        if (isset($r['error'])) {
            $msg = is_array($r['error']) ? ($r['error']['message'] ?? json_encode($r['error'])) : $r['error'];
            return ['error' => $msg];
        }

        $text = '';
        $choices = $r['choices'] ?? [];
        foreach ($choices as $choice) {
            $text .= $choice['message']['content'] ?? '';
        }

        if (empty(trim($text))) return ['error' => 'پاسخ خالی از API دریافت شد'];

        return ['response' => $text];
    }
}