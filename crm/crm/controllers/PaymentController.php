<?php
namespace Controllers;

use Core\Auth;
use Core\Database;
use Core\Session;
use Core\View;
use Core\ActivityLog;

class PaymentController
{
    public function create(array $params): void
    {
        $db = Database::getInstance();
        $deal = $db->fetch(
            "SELECT d.*, c.full_name as contact_name, c.phone as contact_phone, c.email as contact_email
             FROM deals d 
             LEFT JOIN contacts c ON d.contact_id = c.id 
             WHERE d.id = :id",
            [':id' => $params['deal_id']]
        );

        if (!$deal) {
            Session::setFlash('danger', 'معامله مورد نظر یافت نشد.');
            View::redirect('/deals');
        }

        View::render('payment/create', [
            'title' => 'ایجاد لینک پرداخت',
            'deal' => $deal,
        ]);
    }

    public function requestPayment(): void
    {
        $dealId = (int)($_POST['deal_id'] ?? 0);
        $amount = (int)str_replace(',', '', $_POST['amount'] ?? '0');
        $description = trim($_POST['description'] ?? '');
        $mobile = trim($_POST['mobile'] ?? '');

        if ($amount < 1000) {
            Session::setFlash('danger', 'مبلغ باید حداقل ۱۰۰۰ ریال باشد.');
            View::redirect('/payment/create/' . $dealId);
        }

        $config = $GLOBALS['app_config'];
        $merchant = $config['zibal']['merchant'];
        $callbackUrl = $config['zibal']['callback_url'];

        // Request payment from Zibal
        $data = [
            'merchant' => $merchant,
            'amount' => $amount,
            'callbackUrl' => $callbackUrl,
            'description' => $description ?: 'پرداخت معامله',
            'orderId' => 'DEAL-' . $dealId . '-' . time(),
        ];

        if ($mobile) {
            $data['mobile'] = $mobile;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://gateway.zibal.ir/v1/request');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = json_decode($response);

        if ($result && isset($result->trackId) && $result->result == 100) {
            // Save payment record
            $db = Database::getInstance();
            $paymentId = $db->insert('payments', [
                'deal_id' => $dealId,
                'amount' => $amount,
                'payment_type' => 'online',
                'status' => 'pending',
                'track_id' => $result->trackId,
                'description' => $description,
                'created_by' => Auth::id(),
            ]);

            ActivityLog::log('create_payment', 'payment', $paymentId, "لینک پرداخت به مبلغ " . number_format($amount) . " ریال ایجاد شد");

            // Redirect to Zibal payment gateway
            header('Location: https://gateway.zibal.ir/start/' . $result->trackId);
            exit;
        } else {
            $errorMsg = 'خطا در اتصال به درگاه پرداخت';
            if ($result && isset($result->message)) {
                $errorMsg = $result->message;
            }
            Session::setFlash('danger', $errorMsg);
            View::redirect('/payment/create/' . $dealId);
        }
    }

    public function verify(): void
    {
        $trackId = $_GET['trackId'] ?? $_POST['trackId'] ?? '';
        $success = $_GET['success'] ?? '0';
        $status = $_GET['status'] ?? '';

        if (empty($trackId)) {
            Session::setFlash('danger', 'اطلاعات پرداخت نامعتبر است.');
            View::redirect('/deals');
        }

        // Verify with Zibal
        $config = $GLOBALS['app_config'];
        $merchant = $config['zibal']['merchant'];

        $data = [
            'merchant' => $merchant,
            'trackId' => $trackId,
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://gateway.zibal.ir/v1/verify');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        curl_close($ch);

        $result = json_decode($response);

        $db = Database::getInstance();
        $payment = $db->fetch("SELECT * FROM payments WHERE track_id = :track_id", [':track_id' => $trackId]);

        if ($result && $result->result == 100) {
            // Payment successful
            if ($payment) {
                $db->update('payments', [
                    'status' => 'success',
                    'ref_number' => $result->refNumber ?? '',
                    'card_number' => $result->cardNumber ?? '',
                    'paid_at' => date('Y-m-d H:i:s'),
                ], 'id = :id', [':id' => $payment->id]);

                // Update deal if exists
                if ($payment->deal_id) {
                    $db->update('deals', [
                        'is_won' => 1,
                        'closed_at' => date('Y-m-d H:i:s'),
                    ], 'id = :id', [':id' => $payment->deal_id]);

                    ActivityLog::log('payment_success', 'payment', $payment->id, "پرداخت معامله با موفقیت انجام شد. کد پیگیری: {$trackId}");
                }

                Session::setFlash('success', 'پرداخت با موفقیت انجام شد. کد پیگیری: ' . $trackId);
                if ($payment->deal_id) {
                    View::redirect('/deals/view/' . $payment->deal_id);
                }
            }
        } else {
            // Payment failed
            if ($payment) {
                $db->update('payments', ['status' => 'failed'], 'id = :id', [':id' => $payment->id]);
            }

            $errorMsg = 'پرداخت ناموفق بود.';
            if ($result && isset($result->message)) {
                $errorMsg = $result->message;
            }
            Session::setFlash('danger', $errorMsg);
            if ($payment && $payment->deal_id) {
                View::redirect('/deals/view/' . $payment->deal_id);
            }
        }

        View::redirect('/payment/history');
    }

    public function callback(): void
    {
        // Handle POST callback from Zibal (Lazy method)
        $input = json_decode(file_get_contents('php://input'), true);
        
        if ($input && isset($input['trackId'])) {
            $this->verifyCallback($input['trackId']);
        }
        
        echo json_encode(['status' => 'ok']);
        exit;
    }

    private function verifyCallback(string $trackId): void
    {
        $config = $GLOBALS['app_config'];
        $merchant = $config['zibal']['merchant'];

        $data = [
            'merchant' => $merchant,
            'trackId' => $trackId,
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://gateway.zibal.ir/v1/verify');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        curl_close($ch);

        $result = json_decode($response);

        if ($result && $result->result == 100) {
            $db = Database::getInstance();
            $payment = $db->fetch("SELECT * FROM payments WHERE track_id = :track_id", [':track_id' => $trackId]);
            
            if ($payment && $payment->status !== 'success') {
                $db->update('payments', [
                    'status' => 'success',
                    'ref_number' => $result->refNumber ?? '',
                    'card_number' => $result->cardNumber ?? '',
                    'paid_at' => date('Y-m-d H:i:s'),
                ], 'id = :id', [':id' => $payment->id]);

                if ($payment->deal_id) {
                    $db->update('deals', ['is_won' => 1, 'closed_at' => date('Y-m-d H:i:s')], 'id = :id', [':id' => $payment->deal_id]);
                }
            }
        }
    }

    public function inquiry(array $params): void
    {
        $config = $GLOBALS['app_config'];
        $merchant = $config['zibal']['merchant'];

        $data = [
            'merchant' => $merchant,
            'trackId' => $params['track_id'],
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://gateway.zibal.ir/v1/inquiry');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        curl_close($ch);

        $result = json_decode($response);
        View::json($result);
    }

    public function history(): void
    {
        $db = Database::getInstance();
        $user = Auth::user();
        
        $payments = $db->fetchAll(
            "SELECT p.*, d.title as deal_title, c.full_name as contact_name
             FROM payments p 
             LEFT JOIN deals d ON p.deal_id = d.id 
             LEFT JOIN contacts c ON d.contact_id = c.id 
             ORDER BY p.created_at DESC"
        );

        View::render('payment/history', [
            'title' => 'تاریخچه پرداخت‌ها',
            'payments' => $payments,
        ]);
    }
}