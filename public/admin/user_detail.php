<?php
require_once __DIR__ . '/../../init.php';

$pageTitle = 'جزئیات کاربر';
$activeMenu = 'users';

use Database\Database;
use Modules\Bot\CreditService;

$db = Database::getInstance();
$userId = (int) ($_GET['id'] ?? 0);
$message = '';

if (!$userId) {
    header('Location: users.php');
    exit;
}

// Fetch user with profile info
$user = $db->query("
    SELECT u.*, up.first_name, up.last_name, up.username
    FROM users u
    LEFT JOIN user_profiles up ON up.user_id = u.id
    WHERE u.id = ?
", [$userId])->fetch();
if (!$user) {
    header('Location: users.php');
    exit;
}

// Fetch total USD spent by this user across all chat messages
$totalUsdSpent = 0.0;
try {
    $row = $db->query("
        SELECT COALESCE(SUM(actual_cost_usd), 0) as total_usd
        FROM chat_messages
        WHERE conversation_id IN (
            SELECT id FROM chat_conversations WHERE user_id = ?
        ) AND role = 'assistant'
    ", [$userId])->fetch();
    $totalUsdSpent = (float)($row['total_usd'] ?? 0);
} catch (\Throwable $e) {
    $totalUsdSpent = 0.0;
}

// Fetch total credits consumed by this user (from credit_ledger)
$totalCreditsConsumed = 0.0;
try {
    $row = $db->query("
        SELECT COALESCE(SUM(ABS(amount)), 0) as total_credits
        FROM credit_ledger
        WHERE user_id = ? AND type = 'deduction'
    ", [$userId])->fetch();
    $totalCreditsConsumed = (float)($row['total_credits'] ?? 0);
} catch (\Throwable $e) {
    $totalCreditsConsumed = 0.0;
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    try {
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $username = trim($_POST['username'] ?? '');

        // Check if user_profiles row exists
        $existing = $db->query("SELECT id FROM user_profiles WHERE user_id = ?", [$userId])->fetch();
        if ($existing) {
            $db->query("UPDATE user_profiles SET first_name = ?, last_name = ?, username = ? WHERE user_id = ?",
                [$firstName, $lastName, $username, $userId]
            );
        } else {
            $db->query("INSERT INTO user_profiles (user_id, first_name, last_name, username) VALUES (?, ?, ?, ?)",
                [$userId, $firstName, $lastName, $username]
            );
        }
        $message = '✅ پروفایل با موفقیت بروزرسانی شد.';
    } catch (\Throwable $e) {
        $message = '❌ خطا: ' . $e->getMessage();
    }
    // Refresh user data
    $user = $db->query("
        SELECT u.*, up.first_name, up.last_name, up.username
        FROM users u
        LEFT JOIN user_profiles up ON up.user_id = u.id
        WHERE u.id = ?
    ", [$userId])->fetch();
}

// Handle manual credit adjustment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adjust_credits'])) {
    try {
        $amount = (int) $_POST['amount'];
        $reason = trim($_POST['reason'] ?? '');

        if ($amount === 0) {
            throw new \Exception('مقدار اعتبار باید غیر از صفر باشد.');
        }
        if (empty($reason)) {
            throw new \Exception('لطفاً دلیل را وارد کنید.');
        }

        $referenceId = 'admin_' . ($_SESSION['admin_username'] ?? 'admin') . '_' . time() . '_' . $userId;

        if ($amount > 0) {
            $success = CreditService::addCredits($userId, $amount, $referenceId);
        } else {
            $success = CreditService::deduct($userId, abs($amount), $referenceId);
        }

        if ($success) {
            $details = json_encode(['amount' => $amount, 'reason' => $reason], JSON_UNESCAPED_UNICODE);
            try {
                $db->query(
                    "INSERT INTO admin_actions (admin_username, action, target_type, target_id, details) VALUES (?, 'credit_adjust', 'user', ?, ?)",
                    [$_SESSION['admin_username'] ?? 'admin', $userId, $details]
                );
            } catch (\Throwable $e) {
                // admin_actions table might not exist — ignore
            }
            $message = '✅ اعتبار با موفقیت ' . ($amount > 0 ? 'افزودن' : 'کسر') . ' شد.';
        } else {
            $message = '❌ عملیات ناموفق. موجودی کافی نیست یا خطایی رخ داده.';
        }
    } catch (\Throwable $e) {
        $message = '❌ خطا: ' . $e->getMessage();
    }
}

// Refresh user data after adjustment
$user = $db->query("
    SELECT u.*, up.first_name, up.last_name, up.username
    FROM users u
    LEFT JOIN user_profiles up ON up.user_id = u.id
    WHERE u.id = ?
", [$userId])->fetch();

// Fetch credit ledger (handle missing table gracefully)
$ledger = [];
try {
    $ledger = $db->query(
        "SELECT * FROM credit_ledger WHERE user_id = ? ORDER BY created_at DESC LIMIT 20",
        [$userId]
    )->fetchAll();
} catch (\Throwable $e) {
    $ledger = [];
}

// Fetch payment history
$payments = [];
try {
    $payments = $db->query(
        "SELECT * FROM payments WHERE user_id = ? ORDER BY created_at DESC LIMIT 20",
        [$userId]
    )->fetchAll();
} catch (\Throwable $e) {
    $payments = [];
}

// Fetch user memories
$userMemories = [];
try {
    $userMemories = $db->query(
        "SELECT id, memory_text, memory_type, importance, created_at 
         FROM user_memories 
         WHERE user_id = ? AND is_active = 1 
         ORDER BY importance DESC, created_at DESC 
         LIMIT 20",
        [$userId]
    )->fetchAll();
} catch (\Throwable $e) {
    $userMemories = [];
}

// Fetch AI request history (from ai_requests + chat_conversations)
$aiRequests = [];
try {
    $aiRequests = $db->query("
        (SELECT 
            ar.created_at,
            COALESCE(aim.name, aem.name, atm.name, 'نامشخص') as model_name,
            ar.image_type as type,
            ar.status,
            ar.prompt as prompt
        FROM ai_requests ar
        LEFT JOIN ai_image_models aim ON ar.model_id = aim.id
        LEFT JOIN ai_edit_models aem ON ar.model_id = aem.id
        LEFT JOIN ai_text_models atm ON ar.model_id = atm.id
        WHERE ar.user_id = ?
        ORDER BY ar.created_at DESC
        LIMIT 20)
        UNION ALL
        (SELECT 
            cc.created_at,
            cc.model as model_name,
            'chat' as type,
            'success' as status,
            cc.title as prompt
        FROM chat_conversations cc
        WHERE cc.user_id = ?
        ORDER BY cc.created_at DESC
        LIMIT 20)
        ORDER BY created_at DESC
        LIMIT 20
    ", [$userId, $userId])->fetchAll();
} catch (\Throwable $e) {
    $aiRequests = [];
}

ob_start();
?>
<?php if ($message): ?>
    <div class="alert <?php echo strpos($message, '❌') !== false ? 'alert-danger' : 'alert-success'; ?> alert-dismissible fade show">
        <?php echo $message; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row mb-4">
    <div class="col-md-8">
        <div class="table-container">
            <h5>👤 پروفایل کاربر #<?php echo $user['id']; ?></h5>
            <table class="table table-borderless">
                <tr><td style="width:150px;">شناسه بله:</td><td style="font-family:monospace;"><?php echo $user['bale_user_id']; ?></td></tr>
                <tr><td>نام:</td><td>
                    <form method="POST" class="d-inline" id="nameForm">
                        <input type="hidden" name="action" value="update_profile">
                        <input type="text" name="first_name" value="<?php echo htmlspecialchars($user['first_name'] ?? ''); ?>" 
                               class="form-control form-control-sm d-inline" style="width:150px;" placeholder="نام">
                        <input type="text" name="last_name" value="<?php echo htmlspecialchars($user['last_name'] ?? ''); ?>" 
                               class="form-control form-control-sm d-inline" style="width:150px;" placeholder="نام خانوادگی">
                        <input type="text" name="username" value="<?php echo htmlspecialchars($user['username'] ?? ''); ?>" 
                               class="form-control form-control-sm d-inline" style="width:150px;direction:ltr;" placeholder="username">
                        <button type="submit" class="btn btn-sm btn-primary">ذخیره</button>
                    </form>
                </td></tr>
                <tr><td>نام کاربری:</td><td><?php echo htmlspecialchars($user['username'] ?? '-'); ?></td></tr>
                <tr><td>شماره تلفن:</td><td dir="ltr"><?php echo htmlspecialchars($user['phone_number'] ?? '-'); ?></td></tr>
                <tr><td>اعتبار فعلی:</td><td><strong class="text-success"><?php echo number_format((int) ($user['credits'] ?? 0)); ?></strong> اعتبار</td></tr>
                <tr><td>وضعیت ثبت‌نام:</td><td><?php echo ($user['is_registered'] ?? 0) ? '✅ ثبت‌نام شده' : '❌ ثبت‌نام نشده'; ?></td></tr>
                <tr><td>تاریخ ثبت‌نام:</td><td><?php echo $user['created_at'] ?? '-'; ?></td></tr>
                <tr><td>آخرین فعالیت:</td><td><?php echo $user['last_active_at'] ?? '-'; ?></td></tr>
                <tr><td>💵 مجموع هزینه دلاری:</td><td><strong class="text-info">$<?php echo number_format($totalUsdSpent, 8); ?></strong></td></tr>
                <tr><td>📊 مجموع کردیت مصرفی:</td><td><strong class="text-warning"><?php echo number_format($totalCreditsConsumed, 4); ?></strong> کردیت</td></tr>
            </table>
        </div>
    </div>

    <div class="col-md-4">
        <div class="table-container">
            <h5>💰 تنظیم دستی اعتبار</h5>
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label">مقدار (عدد مثبت = افزایش، عدد منفی = کاهش):</label>
                    <input type="number" name="amount" class="form-control" required
                           placeholder="مثلاً: 50 یا -10">
                </div>
                <div class="mb-3">
                    <label class="form-label">دلیل:</label>
                    <input type="text" name="reason" class="form-control" required
                           placeholder="مثلاً: هدیه، جریمه، تصحیح">
                </div>
                <button type="submit" name="adjust_credits" class="btn btn-warning"
                        onclick="return confirm('آیا از انجام این عملیات اطمینان دارید؟');">
                    اعمال تغییرات
                </button>
            </form>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="table-container">
            <h5>📋 آخرین تراکنش‌های اعتبار</h5>
            <table class="table table-sm table-hover">
                <thead>
                    <tr>
                        <th>تاریخ</th>
                        <th>مقدار</th>
                        <th>نوع</th>
                        <th>مرجع</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($ledger)): ?>
                        <tr><td colspan="4" class="text-muted text-center">تراکنشی ثبت نشده.</td></tr>
                    <?php else: ?>
                        <?php foreach ($ledger as $l): ?>
                        <tr>
                            <td style="font-size:0.85rem;"><?php echo $l['created_at']; ?></td>
                            <td>
                                <span class="<?php echo $l['type'] === 'charge' ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo $l['type'] === 'charge' ? '+' : '-'; ?>
                                    <?php echo number_format(abs((int) $l['amount'])); ?>
                                </span>
                            </td>
                            <td><?php echo $l['type'] === 'charge' ? 'افزایش' : 'کاهش'; ?></td>
                            <td style="font-size:0.75rem; font-family:monospace;"><?php echo htmlspecialchars($l['reference_id'] ?? '-'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="col-md-6">
        <div class="table-container">
            <h5>💳 تاریخچه پرداخت‌ها</h5>
            <table class="table table-sm table-hover">
                <thead>
                    <tr>
                        <th>تاریخ</th>
                        <th>مبلغ (ریال)</th>
                        <th>اعتبار</th>
                        <th>وضعیت</th>
                        <th>کد پیگیری</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($payments)): ?>
                        <tr><td colspan="5" class="text-muted text-center">پرداختی ثبت نشده.</td></tr>
                    <?php else: ?>
                        <?php foreach ($payments as $p): ?>
                        <tr>
                            <td style="font-size:0.85rem;"><?php echo $p['created_at']; ?></td>
                            <td><?php echo number_format($p['amount_rial']); ?></td>
                            <td><?php echo number_format($p['credits']); ?></td>
                            <td>
                                <?php if ($p['status'] === 'verified'): ?>
                                    <span class="badge-active">✅ تأیید</span>
                                <?php elseif ($p['status'] === 'pending'): ?>
                                    <span class="badge bg-warning">⏳ در انتظار</span>
                                <?php else: ?>
                                    <span class="badge-inactive">❌ ناموفق</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:0.75rem; font-family:monospace;"><?php echo htmlspecialchars($p['track_id']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="table-container">
            <h5>🧠 حافظه کاربر (<?php echo count($userMemories); ?> مورد)</h5>
            <table class="table table-sm table-hover">
                <thead>
                    <tr>
                        <th>تاریخ</th>
                        <th>نوع</th>
                        <th>اهمیت</th>
                        <th>متن حافظه</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($userMemories)): ?>
                        <tr><td colspan="4" class="text-muted text-center">حافظه‌ای ثبت نشده.</td></tr>
                    <?php else: ?>
                        <?php foreach ($userMemories as $mem): ?>
                        <tr>
                            <td style="font-size:0.85rem;"><?php echo substr($mem['created_at'], 0, 16); ?></td>
                            <td><?php echo $mem['memory_type'] === 'explicit' ? '📝 دستی' : '🔍 خودکار'; ?></td>
                            <td><?php echo str_repeat('⭐', (int)ceil($mem['importance'] / 3)); ?></td>
                            <td style="max-width:300px;">
                                <?php echo htmlspecialchars(mb_substr($mem['memory_text'], 0, 100)); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="table-container">
            <h5>🎨 تاریخچه درخواست‌های AI</h5>
            <table class="table table-sm table-hover">
                <thead>
                    <tr>
                        <th>تاریخ</th>
                        <th>مدل</th>
                        <th>نوع</th>
                        <th>وضعیت</th>
                        <th>متن (خلاصه)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($aiRequests)): ?>
                        <tr><td colspan="5" class="text-muted text-center">درخواستی ثبت نشده.</td></tr>
                    <?php else: ?>
                        <?php foreach ($aiRequests as $r): ?>
                        <tr>
                            <td style="font-size:0.85rem;"><?php echo $r['created_at']; ?></td>
                            <td><?php echo htmlspecialchars($r['model_name'] ?? '-'); ?></td>
                            <td>
                                <?php 
                                $type = $r['type'] ?? $r['image_type'] ?? '';
                                if ($type === 'text2img') echo '🎨 متن به تصویر';
                                elseif ($type === 'img2img') echo '🖼 تصویر به تصویر';
                                elseif ($type === 'chat') echo '💬 چت با AI';
                                elseif ($type === 'video') echo '🎬 ساخت ویدئو';
                                else echo htmlspecialchars($type);
                                ?>
                            </td>
                            <td>
                                <?php if ($r['status'] === 'success'): ?>
                                    <span class="badge-active">✅ موفق</span>
                                <?php else: ?>
                                    <span class="badge-inactive">❌ ناموفق</span>
                                <?php endif; ?>
                            </td>
                            <td style="max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                <?php echo htmlspecialchars(mb_substr($r['prompt'] ?? '', 0, 80)); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<p class="mt-3">
    <a href="users.php" class="btn btn-outline-secondary">← بازگشت به لیست کاربران</a>
</p>

<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../../views/admin/layout.php';