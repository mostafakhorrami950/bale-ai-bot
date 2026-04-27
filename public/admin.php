<?php

require_once __DIR__ . '/../init.php';

use Modules\Bot\BaleClient;
use Core\Config;

$client = new BaleClient();
$action = $_GET['action'] ?? null;
$message = '';

if ($action === 'set_webhook') {
    $url = Config::get('PUBLIC_BASE_URL') . Config::get('BALE_WEBHOOK_PATH');
    $res = $client->setWebhook($url);
    $message = "Set Webhook result: " . json_encode($res);
} elseif ($action === 'delete_webhook') {
    $res = $client->deleteWebhook();
    $message = "Delete Webhook result: " . json_encode($res);
}

$webhookInfo = $client->getWebhookInfo();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>مدیریت ربات</title>
    <style>
        body { font-family: Tahoma, sans-serif; padding: 20px; background: #f4f4f4; }
        .card { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .btn { padding: 10px 20px; cursor: pointer; border: none; border-radius: 4px; color: #fff; }
        .btn-blue { background: #007bff; }
        .btn-red { background: #dc3545; }
        pre { background: #eee; padding: 10px; border-radius: 4px; direction: ltr; }
    </style>
</head>
<body>
    <div class="card">
        <h1>پنل مدیریت سریع ربات</h1>
        
        <?php if ($message): ?>
            <div style="background: #d4edda; padding: 10px; margin-bottom: 15px;">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <h3>وضعیت وبهوک فعلی:</h3>
        <pre><?php echo json_encode($webhookInfo, JSON_PRETTY_PRINT); ?></pre>

        <hr>
        <h3>عملیات:</h3>
        <a href="?action=set_webhook"><button class="btn btn-blue">تنظیم وبهوک به دامین اصلی</button></a>
        <a href="?action=delete_webhook"><button class="btn btn-red">حذف وبهوک</button></a>
        
        <p><small>دامین تنظیم شده در تنظیمات: <?php echo Config::get('PUBLIC_BASE_URL'); ?></small></p>
    </div>
</body>
</html>