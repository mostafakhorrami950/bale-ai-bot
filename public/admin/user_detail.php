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

$user = $db->query("SELECT * FROM users WHERE id = ?", [$userId])->fetch();
if (!$user) {
    header('Location: users.php');
    exit;
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
            // Log admin action
            $db->query(
                "INSERT INTO admin_actions (admin_username, action, target_type, target_id, details) VALUES (?, 'credit_adjust', 'user', ?, ?)",
                [
                    $_SESSION['admin_username'] ?? 'admin',
                    $userId,
                    json_encode(['amount' => $amount, 'reason' => $reason], JSON_UNESCAPED_UNICODE),
                ]
            );
            $message = '✅ اعتبار با موفقیت ' . ($amount > 0 ? 'افزودن' : 'کسر') . ' شد.';
        } else {
            $message = '❌ عملیات ناموفق. موجودی کافی نیست یا خطایی رخ داده.';
        }
    } catch (\Throwable $e) {
        $message = '❌ خطا: ' . $e->getMessage();
    }
}

// Refresh user data after adjustment
$user = $db->query("SELECT * FROM users WHERE id = ?", [$userId])->fetch();

// Fetch credit ledger
$ledger = $db->query(
    "SELECT * FROM credit_ledger WHERE user_id = ? ORDER BY created_at DESC LIMIT 20",
    [$userId]
)->fetchAll();

// Fetch payment history
$payments = $db->query(
    "SELECT * FROM payments WHERE user_id = ? ORDER BY created_at DESC LIMIT 20",
    [$userId]
)->fetchAll();

// Fetch AI request history
$aiRequests = $db->query(
    "SELECT ar.*, am.name as model_name FROM ai_requests ar LEFT JOIN ai_models am ON ar.model_id = am.id WHERE ar.user_id = ? ORDER BY ar.created_at DESC LIMIT 20",
    [$userId]
)->fetchAll();

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
                <tr><td style="width:150px;">شناسه بله:</td><td style="font-family:monospace;"><?php echo $user['bale_id']; ?></td></tr>
                <tr><td>نام:</td><td><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></td></tr>
                <tr><td>نام کاربری:</td><td><?php echo htmlspecialchars($user['username'] ?? '-'); ?></td></tr>
                <tr><td>شماره تلفن:</td><td dir="ltr"><?php echo htmlspecialchars($user['phone_number'] ?? '-'); ?></td></tr>
                <tr><td>اعتبار فعلی:</td><td><strong class="text-success"><?php echo number_format((int) $user['credits']); ?></strong> اعتبار</td></tr>
                <tr><td>وضعیت ثبت‌نام:</td><td><?php echo $user['is_registered'] ? '✅ ثبت‌نام شده' : '❌ ثبت‌نام نشده'; ?></td></tr>
                <tr><td>تاریخ ثبت‌نام:</td><td><?php echo $user['created_at']; ?></td></tr>
                <tr><td>آخرین فعالیت:</td><td><?php echo $user['last_active_at']; ?></td></tr>
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
                            <td><?php echo $r['image_type'] === 'text2img' ? 'متن به تصویر' : 'تصویر به تصویر'; ?></td>
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