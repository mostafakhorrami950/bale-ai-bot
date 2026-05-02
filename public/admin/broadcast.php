<?php
/**
 * Admin broadcast: async message + optional image to ALL users.
 * Stores broadcast request in DB, then cron/worker picks it up.
 * Admin does NOT wait — just creates the job.
 */
require_once __DIR__ . '/../../init.php';

$pageTitle = 'ارسال پیام همگانی';
$activeMenu = 'broadcast';

use Database\Database;
use Database\Logger;

const JOBS_PER_PAGE = 10;
$message = '';
$messageType = 'success';

// Create a new broadcast job
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'create_broadcast') {
        try {
            $text = trim($_POST['text'] ?? '');
            if (empty($text)) {
                throw new \InvalidArgumentException('متن پیام الزامی است.');
            }

            $imagePath = null;
            $imageUploaded = $_FILES['image'] ?? null;
            $imageUrl = trim($_POST['image_url'] ?? '');

            if ($imageUploaded && $imageUploaded['error'] === UPLOAD_ERR_OK) {
                $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                if (!in_array($imageUploaded['type'], $allowed)) {
                    throw new \InvalidArgumentException('فرمت تصویر مجاز نیست.');
                }
                $ext = pathinfo($imageUploaded['name'], PATHINFO_EXTENSION);
                $dest = __DIR__ . '/../uploads/broadcast_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                move_uploaded_file($imageUploaded['tmp_name'], $dest);
                $imagePath = $dest;
            } elseif (!empty($imageUrl)) {
                if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                    throw new \InvalidArgumentException('لینک تصویر معتبر نیست.');
                }
                $imagePath = $imageUrl;
            }

            $db = Database::getInstance();
            $userId = $_SESSION['admin_user_id'] ?? 0;
            
            $db->query(
                "INSERT INTO broadcast_jobs (admin_id, message_text, image_path, total_users, sent_count, failed_count, status, created_at) 
                 VALUES (?, ?, ?, 0, 0, 0, 'pending', NOW())",
                [$userId, $text, $imagePath]
            );
            $jobId = $db->lastInsertId();

            // Count users
            $countRow = $db->query("SELECT COUNT(*) as c FROM users")->fetch();
            $totalUsers = (int)($countRow['c'] ?? 0);

            $db->query("UPDATE broadcast_jobs SET total_users = ? WHERE id = ?", [$totalUsers, $jobId]);

            $message = "✅ ارسال همگانی با شناسه #{$jobId} ثبت شد. {$totalUsers} کاربر در صف ارسال.";
        } catch (\InvalidArgumentException $e) {
            $message = '❌ ' . $e->getMessage();
            $messageType = 'danger';
        } catch (\Throwable $e) {
            $message = '❌ خطا: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }
}

// Process pending broadcast jobs (small batches, called via this page or cron)
if (isset($_GET['process'])) {
    try {
        $db = Database::getInstance();
        $job = $db->query(
            "SELECT * FROM broadcast_jobs WHERE status = 'pending' OR (status = 'processing' AND started_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)) ORDER BY id ASC LIMIT 1"
        )->fetch();

        if (!$job) {
            $message = "✅ هیچ کار ارسال در انتظاری وجود ندارد.";
        } else {
            $jobId = (int)$job['id'];
            $text = $job['message_text'];
            $imagePath = $job['image_path'];
            
            $db->query("UPDATE broadcast_jobs SET status = 'processing', started_at = NOW() WHERE id = ?", [$jobId]);
            
            // Get users not yet processed for this job
            $processedUsers = $db->query("SELECT user_id FROM broadcast_log WHERE job_id = ?", [$jobId])->fetchAll();
            $processedIds = array_column($processedUsers, 'user_id');
            
            $batchSize = 10;
            if (!empty($processedIds)) {
                $placeholders = implode(',', array_fill(0, count($processedIds), '?'));
                $users = $db->query(
                    "SELECT id, bale_user_id FROM users WHERE id NOT IN ($placeholders) ORDER BY id ASC LIMIT ?",
                    array_merge($processedIds, [$batchSize])
                )->fetchAll();
            } else {
                $users = $db->query(
                    "SELECT id, bale_user_id FROM users ORDER BY id ASC LIMIT ?",
                    [$batchSize]
                )->fetchAll();
            }

            if (empty($users)) {
                $db->query("UPDATE broadcast_jobs SET status = 'completed', completed_at = NOW() WHERE id = ?", [$jobId]);
                $message = "✅ کار #{$jobId} کامل شد.";
            } else {
                $botToken = \Core\Config::get('BALE_BOT_TOKEN');
                $apiBase = "https://tapi.bale.ai/bot{$botToken}/";
                $sent = 0;
                $failed = 0;
                
                foreach ($users as $user) {
                    $chatId = (int)$user['bale_user_id'];
                    $internalId = (int)$user['id'];
                    $success = false;
                    
                    try {
                        if ($chatId > 0) {
                            if ($imagePath !== null && !empty($imagePath)) {
                                // Try sending photo first
                                $caption = mb_substr($text, 0, 200);
                                if (file_exists($imagePath)) {
                                    $params = [
                                        'chat_id' => $chatId,
                                        'photo' => new \CURLFile($imagePath),
                                        'caption' => $caption,
                                        'parse_mode' => 'HTML',
                                    ];
                                    $ch = curl_init($apiBase . 'sendPhoto');
                                    curl_setopt_array($ch, [
                                        CURLOPT_POST => true,
                                        CURLOPT_POSTFIELDS => $params,
                                        CURLOPT_RETURNTRANSFER => true,
                                        CURLOPT_TIMEOUT => 15,
                                    ]);
                                } else {
                                    $params = [
                                        'chat_id' => $chatId,
                                        'photo' => $imagePath,
                                        'caption' => $caption,
                                        'parse_mode' => 'HTML',
                                    ];
                                    $ch = curl_init($apiBase . 'sendPhoto');
                                    curl_setopt_array($ch, [
                                        CURLOPT_POST => true,
                                        CURLOPT_POSTFIELDS => json_encode($params),
                                        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                                        CURLOPT_RETURNTRANSFER => true,
                                        CURLOPT_TIMEOUT => 15,
                                    ]);
                                }
                                curl_exec($ch);
                                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                                curl_close($ch);
                                
                                if ($httpCode === 200) {
                                    $success = true;
                                } else {
                                    // Fallback: send only text
                                    $params = [
                                        'chat_id' => $chatId,
                                        'text' => $text,
                                        'parse_mode' => 'HTML',
                                    ];
                                    $ch2 = curl_init($apiBase . 'sendMessage');
                                    curl_setopt_array($ch2, [
                                        CURLOPT_POST => true,
                                        CURLOPT_POSTFIELDS => json_encode($params2 ?? $params),
                                        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                                        CURLOPT_RETURNTRANSFER => true,
                                        CURLOPT_TIMEOUT => 10,
                                    ]);
                                    curl_exec($ch2);
                                    curl_close($ch2);
                                    $success = true;
                                }
                            } else {
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
                                $success = true;
                            }
                        }
                    } catch (\Throwable $e) {
                        Logger::error('Broadcast send error', ['user_id' => $internalId, 'error' => $e->getMessage()]);
                    }
                    
                    // Log each attempt
                    $status = $success ? 'sent' : 'failed';
                    $db->query(
                        "INSERT INTO broadcast_log (job_id, user_id, status, created_at) VALUES (?, ?, ?, NOW())",
                        [$jobId, $internalId, $status]
                    );
                    
                    if ($success) $sent++;
                    else $failed++;
                }
                
                // Update job counters
                $db->query(
                    "UPDATE broadcast_jobs SET sent_count = sent_count + ?, failed_count = failed_count + ? WHERE id = ?",
                    [$sent, $failed, $jobId]
                );
                
                // Check if all done
                $totalLogs = $db->query("SELECT COUNT(*) as c FROM broadcast_log WHERE job_id = ?", [$jobId])->fetch()['c'] ?? 0;
                $totalUsers2 = (int)$job['total_users'];
                if ($totalLogs >= $totalUsers2) {
                    $db->query("UPDATE broadcast_jobs SET status = 'completed', completed_at = NOW() WHERE id = ?", [$jobId]);
                }
                
                $message = "✅ کار #{$jobId}: {$sent} ارسال موفق، {$failed} ناموفق (پیشرفت: {$totalLogs}/{$totalUsers2})";
            }
        }
    } catch (\Throwable $e) {
        $message = '❌ خطا در پردازش: ' . $e->getMessage();
        $messageType = 'danger';
    }
}

// Delete a job
if (isset($_GET['delete'])) {
    try {
        $db = Database::getInstance();
        $db->query("DELETE FROM broadcast_jobs WHERE id = ?", [(int)$_GET['delete']]);
        $db->query("DELETE FROM broadcast_log WHERE job_id = ?", [(int)$_GET['delete']]);
        $message = "✅ کار ارسال حذف شد.";
    } catch (\Throwable $e) {
        $message = '❌ خطا: ' . $e->getMessage();
        $messageType = 'danger';
    }
}

// List jobs
$db = Database::getInstance();
$page = max(0, (int)($_GET['page'] ?? 0));
$offset = $page * JOBS_PER_PAGE;
$totalJobsRow = $db->query("SELECT COUNT(*) as c FROM broadcast_jobs")->fetch();
$totalJobs = (int)($totalJobsRow['c'] ?? 0);
$totalPages = max(1, ceil($totalJobs / JOBS_PER_PAGE));
if ($page >= $totalPages) $page = $totalPages - 1;
$offset2 = $page * JOBS_PER_PAGE;

$jobs = $db->query("SELECT * FROM broadcast_jobs ORDER BY id DESC LIMIT ? OFFSET ?", [JOBS_PER_PAGE, $offset2])->fetchAll();

// Process button: single batch
$processUrl = 'broadcast.php?process=1';

ob_start();
?>
<div class="row">
    <div class="col-md-8 mx-auto">
        <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show">
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="table-container">
            <h5>📢 ارسال پیام همگانی — ثبت درخواست</h5>
            <form method="POST" enctype="multipart/form-data" onsubmit="return confirm('آیا مطمئن هستید؟');">
                <input type="hidden" name="action" value="create_broadcast">
                <div class="mb-3">
                    <label class="form-label">متن پیام <span class="text-danger">*</span>:</label>
                    <textarea name="text" class="form-control" rows="5" required placeholder="متن پیام... (HTML مجاز)"></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label">تصویر (آپلود):</label>
                    <input type="file" name="image" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
                </div>
                <div class="mb-3">
                    <label class="form-label">یا لینک تصویر:</label>
                    <input type="url" name="image_url" class="form-control" placeholder="https://...">
                </div>
                <button type="submit" class="btn btn-primary">📨 ثبت و شروع ارسال</button>
            </form>
        </div>

        <div class="table-container">
            <h5>📋 لیست درخواست‌های ارسال</h5>
            <p class="text-muted small">
                <a href="<?php echo $processUrl; ?>" class="btn btn-sm btn-success" onclick="return confirm('پردازش یک بسته جدید از صف؟');">▶️ پردازش یک بسته</a>
                &nbsp; — پس از کلیک، صفحه رفرش می‌شود و ۱۰ کاربر بعدی ارسال می‌شوند.
            </p>
            <div class="table-responsive">
                <table class="table table-sm table-hover">
                    <thead>
                        <tr><th>ID</th><th>متن</th><th>تصویر</th><th>کل</th><th>موفق</th><th>ناموفق</th><th>وضعیت</th><th>زمان</th><th>عملیات</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($jobs)): ?>
                        <tr><td colspan="9" class="text-center text-muted">هیچ درخواستی ثبت نشده.</td></tr>
                        <?php else: foreach ($jobs as $j): ?>
                        <tr>
                            <td><?php echo $j['id']; ?></td>
                            <td><small><?php echo htmlspecialchars(mb_substr($j['message_text'], 0, 50)); ?></small></td>
                            <td><?php echo $j['image_path'] ? '🖼️' : '—'; ?></td>
                            <td><?php echo $j['total_users']; ?></td>
                            <td class="text-success"><?php echo $j['sent_count']; ?></td>
                            <td class="text-danger"><?php echo $j['failed_count']; ?></td>
                            <td>
                                <?php
                                $badgeMap = [
                                    'pending' => 'secondary',
                                    'processing' => 'warning',
                                    'completed' => 'success',
                                ];
                                $badge = $badgeMap[$j['status']] ?? 'secondary';
                                ?>
                                <span class="badge bg-<?php echo $badge; ?>"><?php echo $j['status']; ?></span>
                            </td>
                            <td><small><?php echo substr($j['created_at'] ?? '', 0, 16); ?></small></td>
                            <td>
                                <a href="broadcast.php?delete=<?php echo $j['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف؟');">🗑️</a>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPages > 1): ?>
            <nav>
                <ul class="pagination pagination-sm">
                    <?php for ($i = 0; $i < $totalPages; $i++): ?>
                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                        <a class="page-link" href="broadcast.php?page=<?php echo $i; ?>"><?php echo $i + 1; ?></a>
                    </li>
                    <?php endfor; ?>
                </ul>
            </nav>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../../views/admin/layout.php';