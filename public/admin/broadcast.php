<?php
/**
 * Broadcast v2 — Auto-send with filters + user delete
 */
require_once __DIR__ . '/../../init.php';

$pageTitle = 'ارسال پیام همگانی';
$activeMenu = 'broadcast';

use Database\Database;
use Core\Config;

$message = '';
$messageType = 'success';
$db = Database::getInstance();

/**
 * Get filtered list of target users for broadcast.
 */
function getFilteredUsers($db, string $filterType, ?string $filterValue): array {
    // users table has phone_number (not phone), use it as display name
    switch ($filterType) {
        case 'all':
            return $db->query("SELECT u.id as internal_id, u.bale_user_id, COALESCE(u.phone_number,'') as first_name FROM users u ORDER BY u.id ASC")->fetchAll();
        case 'registered':
            return $db->query("SELECT u.id as internal_id, u.bale_user_id, COALESCE(u.phone_number,'') as first_name FROM users u WHERE u.registered = 1 ORDER BY u.id ASC")->fetchAll();
        case 'unregistered':
            return $db->query("SELECT u.id as internal_id, u.bale_user_id, COALESCE(u.phone_number,'') as first_name FROM users u WHERE u.registered = 0 ORDER BY u.id ASC")->fetchAll();
        case 'deep_link_all':
            return $db->query("SELECT u.id as internal_id, u.bale_user_id, COALESCE(u.phone_number,'') as first_name FROM users u INNER JOIN deep_link_entries dle ON dle.registered_user_id = u.id WHERE dle.payload = ? GROUP BY u.id ORDER BY u.id ASC", [$filterValue])->fetchAll();
        case 'deep_link_registered':
            return $db->query("SELECT u.id as internal_id, u.bale_user_id, COALESCE(u.phone_number,'') as first_name FROM users u INNER JOIN deep_link_entries dle ON dle.registered_user_id = u.id WHERE dle.payload = ? AND dle.is_registered = 1 GROUP BY u.id ORDER BY u.id ASC", [$filterValue])->fetchAll();
        case 'deep_link_unregistered':
            return $db->query("SELECT NULL as internal_id, dle.bale_user_id, COALESCE(dle.first_name,'') as first_name FROM deep_link_entries dle WHERE dle.payload = ? AND dle.is_registered = 0 AND dle.bale_user_id IS NOT NULL GROUP BY dle.bale_user_id ORDER BY dle.id ASC", [$filterValue])->fetchAll();
        case 'deep_link_returning':
            // کاربر تکراری: کاربری که قبلاً ثبت‌نام کرده و حالا مجدداً از طریق دیپ لینک وارد ربات شده
            // u.created_at < dle.clicked_at یعنی کاربر قبل از کلیک روی دیپ لینک ثبت‌نام کرده بوده
            return $db->query("SELECT u.id as internal_id, u.bale_user_id, COALESCE(u.phone_number,'') as first_name FROM users u INNER JOIN deep_link_entries dle ON dle.registered_user_id = u.id WHERE dle.payload = ? AND u.created_at < dle.clicked_at AND dle.is_registered = 1 GROUP BY u.id ORDER BY u.id ASC", [$filterValue])->fetchAll();
        default:
            return [];
    }
}

// ─── Delete user + all related data ───
if (isset($_GET['delete_user'])) {
    $userId = (int)$_GET['delete_user'];
    try {
        // Delete ALL user-related data from ALL relevant tables
        // Note: 'transactions' doesn't exist — use actual table names
        foreach (['user_profiles', 'ai_requests', 'user_memories', 'user_memory_settings', 'bot_state', 'broadcast_log', 'conversation_summaries'] as $t) {
            $db->query("DELETE FROM {$t} WHERE user_id = ?", [$userId]);
        }
        // payments uses user_id (Bale user ID, not internal ID)
        $db->query("DELETE FROM payments WHERE user_id = ?", [$userId]);
        // credit_ledger uses user_id (internal ID)
        $db->query("DELETE FROM credit_ledger WHERE user_id = ?", [$userId]);
        // uploaded_files uses user_id
        $db->query("DELETE FROM uploaded_files WHERE user_id = ?", [$userId]);
        // Delete chat conversations and their messages
        $convIds = $db->query("SELECT id FROM chat_conversations WHERE user_id = ?", [$userId])->fetchAll();
        foreach ($convIds as $conv) {
            $db->query("DELETE FROM chat_messages WHERE conversation_id = ?", [(int)$conv['id']]);
        }
        $db->query("DELETE FROM chat_conversations WHERE user_id = ?", [$userId]);
        $db->query("UPDATE deep_link_entries SET registered_user_id = NULL, is_registered = 0 WHERE registered_user_id = ?", [$userId]);
        $db->query("DELETE FROM users WHERE id = ?", [$userId]);
        $message = "✅ کاربر #{$userId} و تمام اطلاعات وابسته حذف شد.";
    } catch (\Throwable $e) {
        $message = '❌ خطا: ' . $e->getMessage();
        $messageType = 'danger';
    }
}

// ─── Create broadcast job ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_broadcast') {
    try {
        $text = trim($_POST['text'] ?? '');
        if (empty($text)) throw new \InvalidArgumentException('متن پیام الزامی است.');

        $filterType = $_POST['filter_type'] ?? 'all';
        $filterValue = null;
        if (in_array($filterType, ['deep_link_all', 'deep_link_registered', 'deep_link_unregistered', 'deep_link_returning'])) {
            $filterValue = trim($_POST['filter_payload'] ?? '');
            if (empty($filterValue)) throw new \InvalidArgumentException('لطفاً نام کمپین دیپ لینک را وارد کنید.');
        }

        $imagePath = null;
        $upload = $_FILES['image'] ?? null;
        $imageUrl = trim($_POST['image_url'] ?? '');
        if ($upload && $upload['error'] === UPLOAD_ERR_OK) {
            $ext = pathinfo($upload['name'], PATHINFO_EXTENSION);
            $dest = __DIR__ . '/../../uploads/broadcast_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            move_uploaded_file($upload['tmp_name'], $dest);
            $imagePath = $dest;
        } elseif (!empty($imageUrl) && filter_var($imageUrl, FILTER_VALIDATE_URL)) {
            $imagePath = $imageUrl;
        }

        $adminId = (int)($_SESSION['admin_user_id'] ?? 0);
        $targetUsers = getFilteredUsers($db, $filterType, $filterValue);
        $totalUsers = count($targetUsers);

        $db->query(
            "INSERT INTO broadcast_jobs (admin_id, message_text, image_path, filter_type, filter_value, total_users, sent_count, failed_count, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, 0, 'pending', NOW())",
            [$adminId, $text, $imagePath, $filterType, $filterValue, $totalUsers]
        );
        $jobId = (int)$db->lastInsertId();

        echo "<script>location.href='broadcast.php?auto_process={$jobId}';</script>";
        exit;
    } catch (\Throwable $e) {
        $message = '❌ ' . $e->getMessage();
        $messageType = 'danger';
    }
}

// ─── AJAX auto-process handler ───
if (isset($_GET['ajax_process'])) {
    header('Content-Type: application/json');
    $jobId = (int)$_GET['ajax_process'];
    try {
        $job = $db->query("SELECT * FROM broadcast_jobs WHERE id = ?", [$jobId])->fetch();
        if (!$job || $job['status'] === 'completed') {
            echo json_encode(['done' => true]); exit;
        }
        $db->query("UPDATE broadcast_jobs SET status = 'processing', started_at = NOW() WHERE id = ? AND status != 'processing'", [$jobId]);

        $processed = $db->query("SELECT user_id FROM broadcast_log WHERE job_id = ?", [$jobId])->fetchAll();
        $pIds = array_column($processed, 'user_id');
        $targetUsers = getFilteredUsers($db, $job['filter_type'], $job['filter_value']);

        $batch = [];
        foreach ($targetUsers as $u) {
            if (count($batch) >= 10) break;
            if ($u['internal_id'] && in_array($u['internal_id'], $pIds)) continue;
            $batch[] = $u;
        }

        if (empty($batch)) {
            $db->query("UPDATE broadcast_jobs SET status = 'completed', completed_at = NOW() WHERE id = ?", [$jobId]);
            echo json_encode(['done' => true]); exit;
        }

        $sent = 0; $failed = 0;
        $token = Config::get('BALE_BOT_TOKEN');
        foreach ($batch as $user) {
            $chatId = (int)$user['bale_user_id'];
            $internalId = $user['internal_id'];
            $success = false;
            if ($chatId > 0) {
                try {
                    $params = ['chat_id' => $chatId, 'text' => $job['message_text']];
                    $ch = curl_init("https://tapi.bale.ai/bot{$token}/sendMessage");
                    curl_setopt_array($ch, [
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => json_encode($params),
                        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => 10,
                    ]);
                    curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    $success = ($httpCode === 200);
                } catch (\Throwable $e) {}
            }
            $status = $success ? 'sent' : 'failed';
            if ($internalId) {
                $db->query("INSERT INTO broadcast_log (job_id, user_id, status, created_at) VALUES (?, ?, ?, NOW())", [$jobId, $internalId, $status]);
            }
            if ($success) $sent++; else $failed++;
        }
        $db->query("UPDATE broadcast_jobs SET sent_count = sent_count + ?, failed_count = failed_count + ? WHERE id = ?", [$sent, $failed, $jobId]);

        echo json_encode(['done' => false, 'sent' => $sent, 'failed' => $failed]);
    } catch (\Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ─── Fetch data ───
$campaigns = $db->query("SELECT id, payload, title FROM deep_link_campaigns WHERE is_active = 1 ORDER BY title ASC")->fetchAll();
$jobs = $db->query("SELECT * FROM broadcast_jobs ORDER BY id DESC LIMIT 50")->fetchAll();

$page = max(0, (int)($_GET['user_page'] ?? 0));
$perPage = 20;
$search = trim($_GET['user_search'] ?? '');
if ($search) {
    $users = $db->query(
        "SELECT u.*, COALESCE(up.first_name,'') as fn, COALESCE(up.username,'') as un FROM users u LEFT JOIN user_profiles up ON up.user_id = u.id WHERE u.bale_user_id LIKE ? OR up.first_name LIKE ? OR up.username LIKE ? ORDER BY u.id DESC LIMIT ? OFFSET ?",
        ["%{$search}%", "%{$search}%", "%{$search}%", $perPage, $page * $perPage]
    )->fetchAll();
} else {
    $users = $db->query(
        "SELECT u.*, COALESCE(up.first_name,'') as fn, COALESCE(up.username,'') as un FROM users u LEFT JOIN user_profiles up ON up.user_id = u.id ORDER BY u.id DESC LIMIT ? OFFSET ?",
        [$perPage, $page * $perPage]
    )->fetchAll();
}

ob_start();
?>
<div class="row">
    <div class="col-12 col-lg-10 mx-auto">

        <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show">
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- ─── Auto process JS ─── -->
        <?php if (isset($_GET['auto_process'])): ?>
        <div class="table-container" id="progressContainer">
            <h5>⏳ در حال ارسال خودکار...</h5>
            <div class="progress mb-3" style="height:25px;">
                <div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-success" style="width:0%">0%</div>
            </div>
            <p id="progressText" class="text-muted">در حال شروع...</p>
        </div>
        <script>
        (function(){
            var jobId = <?php echo (int)$_GET['auto_process']; ?>;
            function tick(){
                fetch('broadcast.php?ajax_process='+jobId)
                .then(function(r){return r.json();})
                .then(function(d){
                    if(d.done){
                        var bar = document.getElementById('progressBar');
                        bar.style.width = '100%';
                        bar.textContent = '100%';
                        bar.className = 'progress-bar bg-success';
                        document.getElementById('progressText').textContent = '✅ ارسال کامل شد!';
                        return;
                    }
                    if(d.error){
                        document.getElementById('progressText').textContent = '❌ '+d.error;
                        return;
                    }
                    setTimeout(tick, 1000);
                })
                .catch(function(e){
                    document.getElementById('progressText').textContent = '❌ خطا: '+e;
                });
            }
            setTimeout(tick, 500);
        })();
        </script>
        <?php endif; ?>

        <!-- ═══════ FORM ═══════ -->
        <div class="table-container">
            <h5 class="mb-3">📢 ارسال همگانی</h5>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="create_broadcast">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">متن پیام <span class="text-danger">*</span></label>
                        <textarea name="text" class="form-control" rows="5" required placeholder="متن پیام..."></textarea>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">📁 فیلتر گیرندگان</label>
                        <select name="filter_type" class="form-select mb-2" id="filterType" onchange="toggleFilter(this)">
                            <option value="all">همه کاربران</option>
                            <option value="registered">فقط ثبت‌نام کرده</option>
                            <option value="unregistered">فقط ثبت‌نام نکرده</option>
                            <option value="deep_link_all">دیپ لینک (همه)</option>
                            <option value="deep_link_registered">دیپ لینک (ثبت‌نام کرده)</option>
                            <option value="deep_link_unregistered">دیپ لینک (ثبت‌نام نکرده)</option>
                            <option value="deep_link_returning">دیپ لینک (کاربر تکراری)</option>
                        </select>
                        <div id="filterPayloadGroup" style="display:none;">
                            <label class="form-label mt-2">Payload کمپین</label>
                            <select name="filter_payload" class="form-select">
                                <?php foreach ($campaigns as $c): ?>
                                <option value="<?php echo $c['payload']; ?>"><?php echo htmlspecialchars($c['title']); ?> (<?php echo $c['payload']; ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">تصویر (آپلود)</label>
                        <input type="file" name="image" class="form-control" accept="image/*">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">یا لینک تصویر</label>
                        <input type="url" name="image_url" class="form-control" placeholder="https://...">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary mt-2">📨 شروع ارسال خودکار</button>
            </form>
        </div>

        <script>
        function toggleFilter(el){
            var v = el.value;
            document.getElementById('filterPayloadGroup').style.display = v.indexOf('deep_link') === 0 ? 'block' : 'none';
        }
        </script>

        <!-- ═══════ JOBS ═══════ -->
        <div class="table-container mt-4">
            <h5 class="mb-3">📋 تاریخچه ارسال‌ها</h5>
            <div class="table-responsive">
                <table class="table table-hover table-sm">
                    <thead class="table-dark">
                        <tr><th>#</th><th>متن</th><th>فیلتر</th><th>وضعیت</th><th>کل</th><th>موفق</th><th>ناموفق</th><th>زمان</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($jobs)): ?>
                        <tr><td colspan="8" class="text-center text-muted">هیچ ارسالی ثبت نشده.</td></tr>
                        <?php else: foreach ($jobs as $j): ?>
                        <tr>
                            <td><?php echo $j['id']; ?></td>
                            <td><small><?php echo htmlspecialchars(mb_substr($j['message_text'] ?? '', 0, 40)); ?></small></td>
                            <td><code><?php echo $j['filter_type'] ?? 'all'; ?></code></td>
                            <td>
                                <?php
                                $b = 'bg-secondary';
                                if ($j['status'] === 'completed') $b = 'bg-success';
                                elseif ($j['status'] === 'processing') $b = 'bg-warning';
                                ?>
                                <span class="badge <?php echo $b; ?>"><?php echo $j['status']; ?></span>
                            </td>
                            <td><?php echo $j['total_users']; ?></td>
                            <td class="text-success"><?php echo $j['sent_count']; ?></td>
                            <td class="text-danger"><?php echo $j['failed_count']; ?></td>
                            <td><small><?php echo substr($j['created_at'] ?? '', 0, 16); ?></small></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ═══════ USER LIST ──────>
        <div class="table-container mt-4">
            <h5 class="mb-3">👥 لیست کاربران (برای حذف)</h5>
            <form class="row gx-2 mb-3">
                <div class="col">
                    <input type="text" name="user_search" class="form-control" placeholder="جستجوی کاربر..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-auto">
                    <button class="btn btn-outline-primary">🔍 جستجو</button>
                </div>
            </form>
            <div class="table-responsive">
                <table class="table table-sm table-hover">
                    <thead class="table-dark">
                        <tr><th>ID</th><th>بله ID</th><th>نام</th><th>Username</th><th class="text-center">وضعیت</th><th class="text-center">عملیات</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)): ?>
                        <tr><td colspan="6" class="text-center text-muted">کاربری یافت نشد.</td></tr>
                        <?php else: foreach ($users as $u): ?>
                        <tr>
                            <td><?php echo $u['id']; ?></td>
                            <td><code><?php echo $u['bale_user_id']; ?></code></td>
                            <td><?php echo htmlspecialchars($u['fn'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($u['un'] ?? ''); ?></td>
                            <td class="text-center">
                                <?php if ($u['registered'] ?? 0): ?>
                                <span class="badge bg-success">✅ ثبت‌نام</span>
                                <?php else: ?>
                                <span class="badge bg-warning">⏳ ثبت‌نام نکرده</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <a href="?delete_user=<?php echo $u['id']; ?>&user_page=<?php echo $page; ?><?php echo $search ? '&user_search='.urlencode($search) : ''; ?>"
                                   class="btn btn-sm btn-outline-danger"
                                   onclick="return confirm('⚠️ کاربر #<?php echo $u['id']; ?> و تمام اطلاعات وابسته حذف شود؟\nکاربر می‌تواند مجدداً ثبت‌نام کند.');">
                                   🗑️ حذف کامل
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../../views/admin/layout.php';