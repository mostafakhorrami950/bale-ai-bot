<?php
/**
 * Admin broadcast: send a message + optional image to ALL users with delay.
 */
require_once __DIR__ . '/../../init.php';

$pageTitle = 'ارسال پیام همگانی';
$activeMenu = 'broadcast';

use Database\Database;
use Database\Logger;

const BATCH_SIZE = 10;   // users per batch
const DELAY_MS = 200;    // delay between batches (milliseconds)

$message = '';
$messageType = 'success';
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $text = trim($_POST['text'] ?? '');
        $image = $_FILES['image'] ?? null;
        $imageUrl = trim($_POST['image_url'] ?? '');

        if (empty($text)) {
            throw new \InvalidArgumentException('متن پیام الزامی است.');
        }

        // Validate image
        $finalImage = null;
        if ($image && $image['error'] === UPLOAD_ERR_OK) {
            $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (!in_array($image['type'], $allowedMimes)) {
                throw new \InvalidArgumentException('فرمت تصویر مجاز نیست (jpeg, png, gif, webp).');
            }
            $ext = pathinfo($image['name'], PATHINFO_EXTENSION);
            $dest = __DIR__ . '/../uploads/broadcast_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            move_uploaded_file($image['tmp_name'], $dest);
            $finalImage = $dest;
        } elseif (!empty($imageUrl)) {
            if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                throw new \InvalidArgumentException('لینک تصویر معتبر نیست.');
            }
            $finalImage = $imageUrl;
        }

        // Get all user IDs
        $db = Database::getInstance();
        $users = $db->query("SELECT id, bale_user_id FROM users WHERE status = 'active' ORDER BY id ASC")->fetchAll();

        if (empty($users)) {
            throw new \InvalidArgumentException('هیچ کاربر فعالی یافت نشد.');
        }

        $total = count($users);
        $sent = 0;
        $failed = 0;
        $startTime = microtime(true);

        // Send in batches
        $batches = array_chunk($users, BATCH_SIZE);
        $botToken = \Core\Config::get('BALE_BOT_TOKEN');
        $apiBase = "https://tapi.bale.ai/bot{$botToken}/";

        foreach ($batches as $batchIndex => $batch) {
            foreach ($batch as $user) {
                $chatId = (int) $user['bale_user_id'];
                if ($chatId <= 0) {
                    $failed++;
                    continue;
                }

                try {
                    if ($finalImage !== null) {
                        // Send photo with caption
                        $caption = mb_substr($text, 0, 200);
                        $params = [
                            'chat_id' => $chatId,
                            'caption' => $caption,
                            'parse_mode' => 'HTML',
                        ];

                        if (file_exists($finalImage)) {
                            // Local file — upload via multipart
                            $params['photo'] = new \CURLFile($finalImage);
                            $ch = curl_init($apiBase . 'sendPhoto');
                            curl_setopt_array($ch, [
                                CURLOPT_POST => true,
                                CURLOPT_POSTFIELDS => $params,
                                CURLOPT_RETURNTRANSFER => true,
                                CURLOPT_TIMEOUT => 15,
                            ]);
                        } else {
                            // URL
                            $params['photo'] = $finalImage;
                            $ch = curl_init($apiBase . 'sendPhoto');
                            curl_setopt_array($ch, [
                                CURLOPT_POST => true,
                                CURLOPT_POSTFIELDS => json_encode($params),
                                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                                CURLOPT_RETURNTRANSFER => true,
                                CURLOPT_TIMEOUT => 15,
                            ]);
                        }

                        $resp = curl_exec($ch);
                        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        curl_close($ch);
                        $response = json_decode($resp, true);

                        if (!$response || !isset($response['ok']) || $response['ok'] !== true) {
                            // If photo fails, send just text
                            $params2 = [
                                'chat_id' => $chatId,
                                'text' => $text,
                                'parse_mode' => 'HTML',
                            ];
                            $ch2 = curl_init($apiBase . 'sendMessage');
                            curl_setopt_array($ch2, [
                                CURLOPT_POST => true,
                                CURLOPT_POSTFIELDS => json_encode($params2),
                                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                                CURLOPT_RETURNTRANSFER => true,
                                CURLOPT_TIMEOUT => 10,
                            ]);
                            curl_exec($ch2);
                            curl_close($ch2);
                        }
                    } else {
                        // Just text
                        $params = [
                            'chat_id' => $chatId,
                            'text' => $text,
                            'parse_mode' => 'HTML',
                        ];
                        $ch = curl_init($apiBase . 'sendMessage');
                        curl_setopt_array($ch, [
                            CURLOPT_POST => true,
                            CURLOPT_POSTFIELDS => json_encode($params),
                            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_TIMEOUT => 10,
                        ]);
                        curl_exec($ch);
                        curl_close($ch);
                    }

                    $sent++;
                } catch (\Throwable $e) {
                    $failed++;
                    Logger::error('Broadcast send failed', ['chat_id' => $chatId, 'error' => $e->getMessage()]);
                }
            }

            // Delay between batches to avoid rate limits
            if ($batchIndex < count($batches) - 1) {
                usleep(DELAY_MS * 1000);
            }
        }

        $elapsed = round(microtime(true) - $startTime, 2);

        // Clean up temp file
        if ($finalImage && file_exists($finalImage) && !filter_var($finalImage, FILTER_VALIDATE_URL)) {
            @unlink($finalImage);
        }

        $result = [
            'total' => $total,
            'sent' => $sent,
            'failed' => $failed,
            'elapsed' => $elapsed,
        ];
        $message = "✅ ارسال انجام شد: {$sent} موفق / {$failed} ناموفق از {$total} کاربر (مدت: {$elapsed} ثانیه)";

    } catch (\InvalidArgumentException $e) {
        $message = '❌ ' . $e->getMessage();
        $messageType = 'danger';
    } catch (\Throwable $e) {
        $message = '❌ خطا: ' . $e->getMessage();
        $messageType = 'danger';
    }
}

ob_start();
?>

<div class="row">
    <div class="col-md-8 mx-auto">
        <div class="table-container">
            <h5>📢 ارسال پیام همگانی به کاربران</h5>
            
            <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show">
                <?php echo $message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if ($result): ?>
            <div class="alert alert-success">
                <strong>📊 گزارش ارسال:</strong>
                <ul class="mb-0 mt-2">
                    <li>👥 کل کاربران: <?php echo $result['total']; ?></li>
                    <li>✅ ارسال موفق: <?php echo $result['sent']; ?></li>
                    <li>❌ ناموفق: <?php echo $result['failed']; ?></li>
                    <li>⏱ مدت زمان: <?php echo $result['elapsed']; ?> ثانیه</li>
                </ul>
            </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data" onsubmit="return confirm('آیا مطمئن هستید؟ این پیام برای همه کاربران ارسال می‌شود.');">
                <div class="mb-3">
                    <label class="form-label">متن پیام <span class="text-danger">*</span>:</label>
                    <textarea name="text" class="form-control" rows="5" required 
                              placeholder="متن پیام خود را بنویسید. می‌توانید از HTML هم استفاده کنید (مثلاً <b>متن پررنگ</b>)."></textarea>
                </div>

                <div class="mb-3">
                    <label class="form-label">تصویر (اختیاری):</label>
                    <input type="file" name="image" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
                    <small class="text-muted">فرمت‌های مجاز: jpeg, png, gif, webp</small>
                </div>

                <div class="mb-3">
                    <label class="form-label">یا لینک تصویر (اختیاری):</label>
                    <input type="url" name="image_url" class="form-control" placeholder="https://example.com/image.jpg">
                    <small class="text-muted">اگر فایل آپلود نکردید، می‌توانید لینک مستقیم تصویر را وارد کنید.</small>
                </div>

                <hr>
                <div class="alert alert-warning">
                    <strong>⚠️ توجه:</strong>
                    <ul class="mb-0">
                        <li>این پیام برای <strong>همه کاربران فعال</strong> ارسال می‌شود.</li>
                        <li>ارسال به صورت دسته‌های <?php echo BATCH_SIZE; ?> تایی با <?php echo DELAY_MS; ?> میلی‌ثانیه فاصله انجام می‌شود.</li>
                        <li>در صورت وجود تصویر، ابتدا تصویر + متن (200 کاراکتر اول) ارسال می‌شود، در غیر این صورت فقط متن.</li>
                        <li>فرآیند ممکن است چند دقیقه طول بکشد.</li>
                    </ul>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-send"></i> ارسال به همه کاربران
                </button>
            </form>
        </div>
    </div>
</div>

<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../../views/admin/layout.php';