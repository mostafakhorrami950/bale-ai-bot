<?php

namespace Core;

/**
 * AILogger — writes all AI requests/responses to a dedicated log file.
 * Log path: /home/tvhwswck/public_html/ai_debug.log
 */
class AILogger
{
    private static string $logFile = '/home/tvhwswck/public_html/ai_debug.log';

    public static function setLogFile(string $path): void
    {
        self::$logFile = $path;
    }

    /**
     * Log an AI event with full context.
     */
    public static function log(string $event, array $data = []): void
    {
        $ts = date('Y-m-d H:i:s');
        $line = "[{$ts}] [{$event}] " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        @file_put_contents(self::$logFile, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Log an AI request (before API call).
     */
    public static function request(string $provider, string $endpoint, array $payload): void
    {
        self::log('REQUEST', [
            'provider' => $provider,
            'endpoint' => $endpoint,
            'payload'  => self::sanitizePayload($payload),
        ]);
    }

    /**
     * Log an AI response (after API call).
     */
    public static function response(string $provider, int $httpCode, ?string $body, float $duration): void
    {
        self::log('RESPONSE', [
            'provider'  => $provider,
            'http_code' => $httpCode,
            'duration'  => round($duration, 2) . 's',
            'body'      => mb_substr($body ?? '', 0, 5000),
        ]);
    }

    /**
     * Log an AI error.
     */
    public static function error(string $provider, string $message, array $context = []): void
    {
        self::log('ERROR', array_merge([
            'provider' => $provider,
            'message'  => $message,
        ], $context));
    }

    /**
     * Log image generation result.
     */
    public static function imageResult(int $userId, string $type, string $model, int $cost, bool $success, ?string $error = null): void
    {
        self::log('IMAGE_RESULT', [
            'user_id'  => $userId,
            'type'     => $type,
            'model'    => $model,
            'cost'     => $cost,
            'success'  => $success,
            'error'    => $error,
        ]);
    }

    /**
     * Log chat message exchange.
     */
    public static function chatExchange(int $userId, int $convId, string $model, int $inputChars, int $outputChars, int $cost, bool $success, ?string $error = null): void
    {
        self::log('CHAT_EXCHANGE', [
            'user_id'     => $userId,
            'conv_id'     => $convId,
            'model'       => $model,
            'input_chars' => $inputChars,
            'output_chars'=> $outputChars,
            'cost'        => $cost,
            'success'     => $success,
            'error'       => $error,
        ]);
    }

    /**
     * Log credit operation.
     */
    public static function creditOp(int $userId, int $amount, string $type, string $refId, bool $success, ?string $error = null): void
    {
        self::log('CREDIT', [
            'user_id'  => $userId,
            'amount'   => $amount,
            'type'     => $type,
            'ref_id'   => $refId,
            'success'  => $success,
            'error'    => $error,
        ]);
    }

    /**
     * Log database operation.
     */
    public static function dbQuery(string $query, array $params, ?string $error = null): void
    {
        self::log('DB', [
            'query'  => $query,
            'params' => $params,
            'error'  => $error,
        ]);
    }

    /**
     * Remove sensitive data from payload before logging.
     */
    private static function sanitizePayload(array $payload): array
    {
        $safe = $payload;
        // Remove base64 image data (too large)
        if (isset($safe['messages'])) {
            foreach ($safe['messages'] as &$msg) {
                if (is_array($msg['content'] ?? null)) {
                    foreach ($msg['content'] as &$part) {
                        if (($part['type'] ?? '') === 'image_url' && isset($part['image_url']['url'])) {
                            $url = $part['image_url']['url'];
                            if (strlen($url) > 200) {
                                $part['image_url']['url'] = '[base64:' . strlen($url) . ' chars]';
                            }
                        }
                    }
                }
            }
        }
        return $safe;
    }
}