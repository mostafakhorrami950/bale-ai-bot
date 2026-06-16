<?php
namespace Controllers;

use Core\Auth;
use Core\Database;
use Core\Session;
use Core\View;
use Core\ActivityLog;

class SmsController
{
    public function showSendForm(array $params): void
    {
        $db = Database::getInstance();
        $deal = $db->fetch(
            "SELECT d.*, c.full_name as contact_name, c.phone as contact_phone
             FROM deals d 
             LEFT JOIN contacts c ON d.contact_id = c.id 
             WHERE d.id = :id",
            [':id' => $params['deal_id']]
        );

        if (!$deal) {
            Session::setFlash('danger', 'معامله مورد نظر یافت نشد.');
            View::redirect('/deals');
        }

        View::render('sms/send', [
            'title' => 'ارسال پیامک',
            'deal' => $deal,
        ]);
    }

    public function send(): void
    {
        $recipient = trim($_POST['recipient'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $dealId = (int)($_POST['deal_id'] ?? 0);
        $contactId = (int)($_POST['contact_id'] ?? 0) ?: null;
        $patternCode = trim($_POST['pattern_code'] ?? '');
        $patternParams = $_POST['pattern_params'] ?? [];

        if (empty($recipient)) {
            Session::setFlash('danger', 'لطفا شماره گیرنده را وارد کنید.');
            if ($dealId) {
                View::redirect('/sms/send/' . $dealId);
            }
            View::redirect('/sms/history');
        }

        // Format phone number - ensure it starts with +98
        if (substr($recipient, 0, 1) === '0') {
            $recipient = '+98' . substr($recipient, 1);
        } elseif (substr($recipient, 0, 1) !== '+') {
            $recipient = '+98' . $recipient;
        }

        $config = $GLOBALS['app_config'];
        $apiToken = $config['sms']['api_token'];
        $fromNumber = $config['sms']['from_number'];

        if (empty($apiToken)) {
            Session::setFlash('danger', 'توکن API پیامک تنظیم نشده است. لطفا ابتدا در تنظیمات مقداردهی کنید.');
            if ($dealId) {
                View::redirect('/sms/send/' . $dealId);
            }
            View::redirect('/settings');
        }

        // Build request payload
        $payload = [
            'sending_type' => 'pattern',
            'from_number' => $fromNumber,
            'recipients' => [$recipient],
        ];

        if ($patternCode) {
            $payload['code'] = $patternCode;
            $payload['params'] = $patternParams;
        } else {
            // For non-pattern (text message) - but the API uses pattern, so we use a generic pattern
            $payload['code'] = $patternCode ?: 'default';
            $payload['params'] = ['message' => $message];
        }

        // Use cURL to send SMS via IPPanel
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://edge.ippanel.com/v1/api/send');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: ' . $apiToken,
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = json_decode($response);
        $status = 'sent';
        $errorMessage = null;
        $outboxId = null;

        if ($result && isset($result->meta) && $result->meta->status === true) {
            $status = 'sent';
            $outboxId = $result->data->message_outbox_ids[0] ?? null;
        } else {
            $status = 'failed';
            $errorMessage = $result->meta->message ?? 'خطا در ارسال پیامک';
        }

        // Save to history
        $db = Database::getInstance();
        $db->insert('sms_history', [
            'deal_id' => $dealId ?: null,
            'contact_id' => $contactId,
            'recipient' => $recipient,
            'message' => $patternCode ? "الگو: {$patternCode}" : $message,
            'pattern_code' => $patternCode,
            'status' => $status,
            'message_outbox_id' => $outboxId,
            'error_message' => $errorMessage,
            'sent_by' => Auth::id(),
        ]);

        if ($status === 'sent') {
            ActivityLog::log('send_sms', 'sms', $dealId, "پیامک به {$recipient} ارسال شد");
            Session::setFlash('success', 'پیامک با موفقیت ارسال شد.');
        } else {
            Session::setFlash('danger', "خطا در ارسال پیامک: {$errorMessage}");
        }

        if ($dealId) {
            View::redirect('/deals/view/' . $dealId);
        }
        View::redirect('/sms/history');
    }

    public function sendBulk(): void
    {
        $recipients = $_POST['recipients'] ?? [];
        $message = trim($_POST['message'] ?? '');
        $patternCode = trim($_POST['pattern_code'] ?? '');

        if (empty($recipients) || (empty($message) && empty($patternCode))) {
            Session::setFlash('danger', 'لطفا گیرندگان و متن پیام را وارد کنید.');
            View::redirect('/sms/history');
        }

        $config = $GLOBALS['app_config'];
        $apiToken = $config['sms']['api_token'];
        $fromNumber = $config['sms']['from_number'];

        $successCount = 0;
        $failCount = 0;

        foreach ($recipients as $recipient) {
            $recipient = trim($recipient);
            if (empty($recipient)) continue;

            if (substr($recipient, 0, 1) === '0') {
                $recipient = '+98' . substr($recipient, 1);
            } elseif (substr($recipient, 0, 1) !== '+') {
                $recipient = '+98' . $recipient;
            }

            $payload = [
                'sending_type' => 'pattern',
                'from_number' => $fromNumber,
                'recipients' => [$recipient],
                'code' => $patternCode ?: 'default',
                'params' => ['message' => $message],
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'https://edge.ippanel.com/v1/api/send');
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: ' . $apiToken,
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            
            $response = curl_exec($ch);
            curl_close($ch);

            $result = json_decode($response);
            
            $status = 'sent';
            $errorMessage = null;
            if ($result && isset($result->meta) && $result->meta->status === true) {
                $successCount++;
            } else {
                $status = 'failed';
                $errorMessage = $result->meta->message ?? 'خطا';
                $failCount++;
            }

            $db = Database::getInstance();
            $db->insert('sms_history', [
                'recipient' => $recipient,
                'message' => $message,
                'pattern_code' => $patternCode,
                'status' => $status,
                'error_message' => $errorMessage,
                'sent_by' => Auth::id(),
            ]);
        }

        Session::setFlash('success', "{$successCount} پیامک با موفقیت ارسال شد. {$failCount} ناموفق.");
        View::redirect('/sms/history');
    }

    public function history(): void
    {
        $db = Database::getInstance();
        $history = $db->fetchAll(
            "SELECT sh.*, u.full_name as sent_by_name, d.title as deal_title
             FROM sms_history sh 
             LEFT JOIN users u ON sh.sent_by = u.id 
             LEFT JOIN deals d ON sh.deal_id = d.id 
             ORDER BY sh.created_at DESC 
             LIMIT 100"
        );

        View::render('sms/history', [
            'title' => 'تاریخچه پیامک‌ها',
            'history' => $history,
        ]);
    }
}