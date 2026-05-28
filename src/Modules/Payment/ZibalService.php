<?php

namespace Modules\Payment;

use Core\Config;
use Database\Logger;

class ZibalService
{
    private string $merchantCode;
    private string $callbackUrl;
    private string $apiBase;
    private int $timeout = 30;

    public function __construct()
    {
        $this->merchantCode = Config::get('ZIBAL_MERCHANT_CODE', '');
        $this->callbackUrl  = Config::get('ZIBAL_CALLBACK_URL', '');
        $this->apiBase      = rtrim(Config::get('ZIBAL_API_BASE', 'https://gateway.zibal.ir/v1'), '/');
    }

    /**
     * Request a payment from Zibal.
     *
     * @param int    $amount      Amount in Rials
     * @param string $orderId     Unique order identifier
     * @param string $description Description shown to user
     *
     * @return array ['success' => true, 'trackId' => '...'] or ['success' => false, 'error' => '...']
     */
    public function requestPayment(int $amount, string $orderId, string $description): array
    {
        $payload = [
            'merchant'    => $this->merchantCode,
            'amount'      => $amount,
            'callbackUrl' => $this->callbackUrl,
            'description' => $description,
            'orderId'     => $orderId,
        ];

        $response = $this->callApi('/request', $payload);

        // Log the request/response
        $this->logPayment('request', $payload, $response, $response['result'] ?? 0);

        if (isset($response['result']) && $response['result'] === 100) {
            $trackId = $response['trackId'] ?? '';
            if (empty($trackId)) {
                return ['success' => false, 'error' => 'عدم دریافت شناسه تراکنش از زیبال'];
            }
            Logger::info('Zibal::requestPayment success', [
                'trackId' => $trackId,
                'amount'  => $amount,
                'orderId' => $orderId,
            ]);
            return ['success' => true, 'trackId' => $trackId];
        }

        $errorMsg = $response['message'] ?? 'خطای ناشناخته در درخواست پرداخت';
        Logger::error('Zibal::requestPayment failed', [
            'result'  => $response['result'] ?? 'unknown',
            'message' => $errorMsg,
            'payload' => $payload,
        ]);
        return ['success' => false, 'error' => $errorMsg];
    }

    /**
     * Verify a payment after user returns from Zibal gateway.
     *
     * @param string $trackId Track ID from Zibal
     *
     * @return array ['success' => true, 'amount' => int, 'refNumber' => '...'] or ['success' => false, 'error' => '...']
     */
    public function verifyPayment(string $trackId): array
    {
        $payload = [
            'merchant' => $this->merchantCode,
            'trackId'  => $trackId,
        ];

        $response = $this->callApi('/verify', $payload);

        // Log the request/response
        $this->logPayment('verify', $payload, $response, $response['result'] ?? 0, $trackId);

        if (isset($response['result']) && $response['result'] === 100) {
            Logger::info('Zibal::verifyPayment success', [
                'trackId'   => $trackId,
                'amount'    => $response['amount'] ?? 0,
                'refNumber' => $response['refNumber'] ?? '',
            ]);
            return [
                'success'   => true,
                'amount'    => (int) ($response['amount'] ?? 0),
                'refNumber' => (string) ($response['refNumber'] ?? ''),
            ];
        }

        $errorMsg = $response['message'] ?? 'تأیید پرداخت ناموفق بود';
        Logger::error('Zibal::verifyPayment failed', [
            'trackId' => $trackId,
            'result'  => $response['result'] ?? 'unknown',
            'message' => $errorMsg,
        ]);
        return ['success' => false, 'error' => $errorMsg];
    }

    /**
     * Generate the redirect URL for the user to pay.
     */
    public function generatePaymentUrl(string $trackId): string
    {
        return "https://gateway.zibal.ir/start/{$trackId}";
    }

    /**
     * Inquiry — check payment status without consuming (does NOT verify).
     * Use this to check if a user has paid before the callback arrives.
     *
     * @param string $trackId Track ID from Zibal
     *
     * @return array ['success' => true, 'paid' => true/false, 'amount' => int, 'refNumber' => '...', 'status' => int]
     *               or ['success' => false, 'error' => '...']
     */
    public function inquiryPayment(string $trackId): array
    {
        $payload = [
            'merchant' => $this->merchantCode,
            'trackId'  => $trackId,
        ];

        $response = $this->callApi('/inquiry', $payload);

        // Log the request/response
        $this->logPayment('inquiry', $payload, $response, $response['result'] ?? 0, $trackId);

        if (isset($response['result']) && $response['result'] === 100) {
            Logger::info('Zibal::inquiryPayment success', [
                'trackId'   => $trackId,
                'amount'    => $response['amount'] ?? 0,
                'refNumber' => $response['refNumber'] ?? '',
                'status'    => $response['status'] ?? 0,
            ]);
            return [
                'success'   => true,
                'paid'      => true,
                'amount'    => (int) ($response['amount'] ?? 0),
                'refNumber' => (string) ($response['refNumber'] ?? ''),
                'status'    => (int) ($response['status'] ?? 0),
            ];
        }

        // Zibal result codes for unpaid:
        // 101 = تراکنش قبلاً تایید شده
        // 102 = تراکنش یافت نشد (هنوز پرداخت نشده)
        // 103 = مرچنت کد یافت نشد
        // 104 = تراکنش هنوز پرداخت نشده
        if (in_array($response['result'] ?? 0, [101, 102, 104])) {
            Logger::info('Zibal::inquiryPayment not yet paid', [
                'trackId' => $trackId,
                'result'  => $response['result'] ?? 0,
                'message' => $response['message'] ?? '',
            ]);
            return [
                'success' => true,
                'paid'    => false,
                'status'  => (int) ($response['result'] ?? 0),
            ];
        }

        $errorMsg = $response['message'] ?? 'استعلام پرداخت ناموفق بود';
        Logger::error('Zibal::inquiryPayment failed', [
            'trackId' => $trackId,
            'result'  => $response['result'] ?? 'unknown',
            'message' => $errorMsg,
        ]);
        return ['success' => false, 'error' => $errorMsg];
    }

    /**
     * Execute an API call to Zibal.
     */
    private function callApi(string $endpoint, array $data): array
    {
        $url = $this->apiBase . $endpoint;
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);

        $body    = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno   = curl_errno($ch);
        $error   = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0) {
            $errMsg = 'خطای cURL در اتصال به زیبال: ' . $error;
            Logger::error('Zibal cURL error', [
                'endpoint' => $endpoint,
                'errno'    => $errno,
                'error'    => $error,
            ]);
            $this->logAppError('zibal_curl_error', "Endpoint: {$endpoint}, cURL errno: {$errno}, error: {$error}");
            return ['result' => 0, 'message' => $errMsg];
        }

        $result = json_decode($body, true);
        if (!is_array($result)) {
            $errMsg = 'پاسخ نامعتبر از درگاه زیبال (HTTP ' . $httpCode . ')';
            Logger::error('Zibal invalid JSON response', [
                'endpoint'  => $endpoint,
                'http_code' => $httpCode,
                'body'      => mb_substr($body, 0, 500),
            ]);
            $this->logAppError('zibal_invalid_response', "Endpoint: {$endpoint}, HTTP: {$httpCode}, Body: " . mb_substr($body, 0, 500));
            return ['result' => 0, 'message' => $errMsg];
        }

        // Check for non-100 result codes and log them
        if (isset($result['result']) && $result['result'] !== 100) {
            $errMsg = $result['message'] ?? 'خطای ناشناخته زیبال';
            $this->logAppError('zibal_api_error', "Endpoint: {$endpoint}, Result: {$result['result']}, Message: {$errMsg}");
        }

        return $result;
    }

    /**
     * Log an error to the app_errors table.
     */
    private function logAppError(string $errorType, string $errorMessage): void
    {
        try {
            $db = \Database\Database::getInstance();
            $db->query(
                "INSERT INTO app_errors (error_type, error_message) VALUES (?, ?)",
                [$errorType, $errorMessage]
            );
        } catch (\Throwable $ignored) {}
    }

    /**
     * Log payment API request/response to payment_logs table.
     */
    private function logPayment(string $action, array $requestData, array $responseData, int $statusCode, ?string $trackId = null): void
    {
        try {
            $db = \Database\Database::getInstance();
            $statusText = ($statusCode === 100) ? 'success' : 'failed';
            $db->query(
                "INSERT INTO payment_logs (track_id, action, request_data, response_data, status) VALUES (?, ?, ?, ?, ?)",
                [
                    $trackId ?? ($responseData['trackId'] ?? null),
                    $action,
                    json_encode($requestData, JSON_UNESCAPED_UNICODE),
                    json_encode($responseData, JSON_UNESCAPED_UNICODE),
                    $statusText,
                ]
            );
        } catch (\Throwable $e) {
            Logger::error('Zibal::logPayment failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Set cURL timeout in seconds.
     */
    public function setTimeout(int $seconds): void
    {
        $this->timeout = $seconds;
    }
}
