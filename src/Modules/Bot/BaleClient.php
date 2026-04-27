<?php

namespace Modules\Bot;

use Core\Config;
use Database\Logger;

class BaleClient
{
    private $token;
    private $apiUrl;
    private $lastError = null;

    public function __construct()
    {
        $this->token = Config::get('BALE_BOT_TOKEN');
        $this->apiUrl = "https://tapi.bale.ai/bot{$this->token}/";
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function sendMessage(int $chatId, string $text, ?array $keyboard = null): bool
    {
        $params = [
            'chat_id' => $chatId,
            'text' => $text
        ];

        if ($keyboard) {
            // Keyboard is passed as array — it will be JSON-encoded in request()
            $params['reply_markup'] = $keyboard;
        }

        $response = $this->request('sendMessage', $params);
        
        // M6: Check response and log errors
        if (!isset($response['ok']) || $response['ok'] !== true) {
            $this->lastError = $response['description'] ?? 'Unknown sendMessage error';
            Logger::error('BaleClient::sendMessage failed', [
                'chat_id'   => $chatId,
                'error'     => $this->lastError,
                'response'  => $response,
            ]);
            return false;
        }
        return true;
    }

    public function getChatMember(string $chatId, int $userId)
    {
        $params = [
            'chat_id' => $chatId,
            'user_id' => $userId
        ];

        $response = $this->request('getChatMember', $params);
        return $response['result'] ?? null;
    }

    /**
     * Send a photo to a chat.
     * Returns the API response array on success, or ['ok'=>false,'error'=>'...'] on failure.
     */
    public function sendPhoto(int $chatId, string $photo, ?string $caption = null, $replyMarkup = null): array
    {
        $params = [
            'chat_id' => $chatId,
            'photo' => $photo
        ];

        if ($caption) {
            $params['caption'] = $caption;
        }

        if ($replyMarkup) {
            $params['reply_markup'] = $replyMarkup;
        }

        $response = $this->request('sendPhoto', $params);
        
        // I3: Return boolean success indicator alongside response
        if (!isset($response['ok']) || $response['ok'] !== true) {
            $this->lastError = $response['description'] ?? 'Unknown sendPhoto error';
            Logger::error('BaleClient::sendPhoto failed', [
                'chat_id'   => $chatId,
                'error'     => $this->lastError,
            ]);
        }
        return $response;
    }

    /**
     * Answer callback queries to remove loading state on user's client.
     */
    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null, bool $showAlert = false): bool
    {
        $params = [
            'callback_query_id' => $callbackQueryId
        ];

        if ($text) {
            $params['text'] = $text;
            $params['show_alert'] = $showAlert;
        }

        $response = $this->request('answerCallbackQuery', $params);
        
        // N7: Check response and log if false
        if (!isset($response['ok']) || $response['ok'] !== true) {
            $this->lastError = $response['description'] ?? 'Unknown answerCallbackQuery error';
            Logger::error('BaleClient::answerCallbackQuery failed', [
                'callback_query_id' => $callbackQueryId,
                'error'             => $this->lastError,
            ]);
            return false;
        }
        return true;
    }

    public function deleteMessage(int|string $chatId, int $messageId)
    {
        return $this->request('deleteMessage', [
            'chat_id' => $chatId,
            'message_id' => $messageId
        ]);
    }

    /**
     * Set Webhook URL.
     */
    public function setWebhook(string $url)
    {
        return $this->request('setWebhook', ['url' => $url]);
    }

    /**
     * Get Webhook Info.
     */
    public function getWebhookInfo()
    {
        return $this->request('getWebhookInfo', []);
    }

    /**
     * Delete Webhook.
     */
    public function deleteWebhook()
    {
        return $this->request('deleteWebhook', []);
    }

    /**
     * Get file info from Bale servers (for downloading photos).
     * Returns file_path which can be used to download: https://tapi.bale.ai/file/bot{token}/{file_path}
     */
    public function getFile(string $fileId): ?array
    {
        $result = $this->request('getFile', ['file_id' => $fileId]);
        if (isset($result['ok']) && $result['ok'] === true && isset($result['result'])) {
            return $result['result'];
        }
        $this->lastError = $result['description'] ?? 'Failed to get file';
        Logger::error('BaleClient::getFile failed', [
            'file_id' => $fileId,
            'error'   => $this->lastError,
        ]);
        return null;
    }

    /**
     * Download a file from Bale servers by file_path.
     * Returns raw binary content.
     */
    public function downloadFile(string $filePath): ?string
    {
        $url = "https://tapi.bale.ai/file/bot{$this->token}/{$filePath}";
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $content = curl_exec($ch);
        $error   = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error || $httpCode !== 200) {
            $this->lastError = $error ?: "HTTP $httpCode";
            Logger::error('BaleClient::downloadFile failed', [
                'file_path' => $filePath,
                'error'     => $this->lastError,
            ]);
            return null;
        }
        return $content;
    }

    /**
     * Send generic request to Bale API — uses JSON encoding as required by Bale API docs.
     * 
     * C1: Replaced http_build_query with json_encode for Bale API compatibility.
     * C4: Removed CURLOPT_SSL_VERIFYPEER => false for production security.
     * C5: This is the single private request method. call() and sendRequest() removed.
     */
    private function request(string $method, array $params = []): array
    {
        $this->lastError = null;
        $url = $this->apiUrl . $method;
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error) {
            $this->lastError = "cURL Error: " . $error;
            Logger::error("Bale API cURL Error", ['method' => $method, 'error' => $error]);
            return ['ok' => false, 'error' => $error];
        }

        if ($httpCode !== 200) {
            $this->lastError = "HTTP Error: " . $httpCode;
            Logger::error("Bale API HTTP Error", ['method' => $method, 'code' => $httpCode, 'response' => $response]);
        }

        $result = json_decode($response, true);
        error_log("DEBUG: Bale API Response: " . json_encode($result));

        if (!$result || !isset($result['ok'])) {
            $this->lastError = "Invalid JSON response";
            Logger::error("Bale API Invalid Response", ['method' => $method, 'raw' => $response]);
            return ['ok' => false, 'error' => 'Invalid JSON response'];
        }

        if (!$result['ok']) {
            $this->lastError = $result['description'] ?? 'Unknown API error';
            Logger::error("Bale API Error Response", [
                'method' => $method,
                'params' => $params,
                'response' => $result
            ]);
        }

        return $result;
    }
}