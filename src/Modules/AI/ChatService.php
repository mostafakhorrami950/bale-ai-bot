<?php

namespace Modules\AI;

use Core\Config;
use Database\Database;
use Database\Logger;

/**
 * ChatService — handles OpenRouter chat completions with per-character billing.
 */
class ChatService
{
    private string $apiKey;
    private string $baseUrl;
    private string $logFile;

    public function __construct()
    {
        $this->apiKey = Config::get('OPENROUTER_API_KEY', '');
        $this->baseUrl = 'https://openrouter.ai/api/v1';
        $this->logFile = Config::get('AI_LOG_FILE', BASE_PATH . '/logs_ai.txt');
    }

    private function aiLog(string $level, string $message, array $ctx = []): void
    {
        $ts = date('Y-m-d H:i:s');
        $c = !empty($ctx) ? ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
        @file_put_contents($this->logFile, "[{$ts}] [{$level}] {$message}{$c}\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Send a chat prompt and get the AI response.
     *
     * @param array  $messages   Full message history for OpenRouter
     * @param string $model      Model name (e.g. "google/gemini-2.5-flash-image")
     * @param array  $modelData  Full model row from ai_models
     * @return array ['response' => string, 'input_chars' => int, 'output_chars' => int, 'error' => string|null]
     */
    public function chat(array $messages, string $model, array $modelData): array
    {
        if (empty($this->apiKey)) {
            return ['error' => 'OpenRouter API Key تنظیم نشده است.'];
        }

        // Count input chars from all messages
        $inputChars = $this->countMessageChars($messages);

        $payload = [
            'model'      => $model,
            'messages'   => $messages,
            'stream'     => false,
        ];

        // Some models work best with modalities
        if (str_contains($model, 'gemini')) {
            $payload['modalities'] = ['image', 'text'];
        }

        $this->aiLog('INFO', 'ChatService request', [
            'model' => $model,
            'msg_count' => count($messages),
            'input_chars' => $inputChars,
        ]);

        $result = $this->openrouterCall($payload);

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
     *
     * @param int   $chars
     * @param float $costPerChar  From ai_models.cost_per_input_char or cost_per_output_char
     * @return int  Credits (rounded up, minimum 0)
     */
    public static function calcCreditCost(int $chars, float $costPerChar): int
    {
        if ($costPerChar <= 0 || $chars <= 0) return 0;
        $cost = (int) ceil($chars * $costPerChar);
        return max(0, $cost);
    }

    /**
     * Count total characters in an array of OpenRouter messages.
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
                    // image parts are counted as 1000 chars equivalent
                    if (($part['type'] ?? '') === 'image_url') {
                        $total += 1000;
                    }
                }
            }
        }
        return $total;
    }

    /**
     * Build OpenRouter messages array from DB chat_messages rows.
     */
    public static function buildMessagesFromHistory(array $rows, string $newUserText = null, string $fileContent = null, string $fileType = null): array
    {
        $messages = [];

        // System message
        $messages[] = [
            'role' => 'system',
            'content' => 'شما یک دستیار هوش مصنوعی مفید هستید. به زبان فارسی پاسخ دهید.'
        ];

        // History
        foreach ($rows as $row) {
            $role = $row['role'];
            if ($role === 'system') continue; // skip old system msgs

            if (!empty($row['file_type']) && !empty($row['file_content'])) {
                // Message with file attachment
                $parts = [
                    ['type' => 'text', 'text' => $row['content']],
                ];
                if ($row['file_type'] === 'image') {
                    $parts[] = ['type' => 'image_url', 'image_url' => ['url' => $row['file_content']]];
                } else {
                    // text file - append content to text
                    $parts[0]['text'] .= "\n\n[فایل ضمیمه: {$row['file_type']}]\n{$row['file_content']}";
                }
                $messages[] = ['role' => $role, 'content' => $parts];
            } else {
                $messages[] = ['role' => $role, 'content' => $row['content']];
            }
        }

        // New user message
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
     * Get the estimated file character cost for billing.
     */
    public static function estimateFileChars(string $fileType, string $fileContent): int
    {
        if ($fileType === 'image') {
            return 1000; // fixed cost for image
        }
        return mb_strlen($fileContent);
    }

    private function openrouterCall(array $payload): array
    {
        $ch = curl_init($this->baseUrl . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
                'HTTP-Referer: https://mobixai.ir',
                'X-OpenRouter-Title: MobixAI Bot',
            ],
        ]);
        $body   = curl_exec($ch);
        $errno  = curl_errno($ch);
        $error  = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->aiLog('INFO', 'ChatService API', [
            'http' => $httpCode,
            'errno' => $errno,
            'error' => $error,
            'body' => mb_substr($body ?? '', 0, 2000),
        ]);

        if ($errno) return ['error' => 'خطای اتصال: ' . $error];
        if ($httpCode >= 400) return ['error' => "OpenRouter HTTP {$httpCode}: " . mb_substr($body, 0, 500)];

        $r = json_decode($body, true);
        if (!is_array($r)) return ['error' => 'پاسخ نامعتبر از OpenRouter'];

        if (isset($r['error'])) {
            $msg = is_array($r['error']) ? ($r['error']['message'] ?? json_encode($r['error'])) : $r['error'];
            return ['error' => $msg];
        }

        $text = '';
        $choices = $r['choices'] ?? [];
        foreach ($choices as $choice) {
            $text .= $choice['message']['content'] ?? '';
        }

        if (empty(trim($text))) return ['error' => 'پاسخ خالی از OpenRouter دریافت شد'];

        return ['response' => $text];
    }
}