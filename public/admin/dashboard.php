<?php
require_once __DIR__ . '/../../init.php';

// Use shared layout
$pageTitle = 'داشبورد';
$activeMenu = 'dashboard';

use Database\Database;
use Database\DatabaseRepairService;

// --- Auto-repair database ---
try {
    $repairer = new DatabaseRepairService();
    $repairer->repairAll();
} catch (\Throwable $e) {
    // Log but continue — dashboard should never crash
    \Database\Logger::error('Dashboard auto-repair failed: ' . $e->getMessage());
}

$db = Database::getInstance();

// --- Safe query helper: returns fallback value on column/table errors ---
function safeQuery(string $sql, array $params = [], $default = 0) {
    try {
        $result = Database::getInstance()->query($sql, $params)->fetch();
        return $result ? $result[array_key_first($result)] : $default;
    } catch (\Throwable $e) {
        \Database\Logger::warning('safeQuery failed: ' . $e->getMessage() . ' | SQL: ' . $sql);
        return $default;
    }
}

function safeFetch(string $sql, array $params = []) {
    try {
        return Database::getInstance()->query($sql, $params)->fetch();
    } catch (\Throwable $e) {
        \Database\Logger::warning('safeFetch failed: ' . $e->getMessage() . ' | SQL: ' . $sql);
        return ['cnt' => 0, 'sum' => 0];
    }
}

function safeFetchAll(string $sql, array $params = [], $default = []) {
    try {
        return Database::getInstance()->query($sql, $params)->fetchAll();
    } catch (\Throwable $e) {
        \Database\Logger::warning('safeFetchAll failed: ' . $e->getMessage() . ' | SQL: ' . $sql);
        return $default;
    }
}

// Stats queries with safe wrappers
$totalUsers      = safeQuery("SELECT COUNT(*) as c FROM users");
$activeModels    = safeQuery("SELECT COUNT(*) as c FROM ai_models WHERE is_active = 1");
$todayPayments   = safeFetch("SELECT COUNT(*) as cnt, COALESCE(SUM(amount_rial),0) as sum FROM payments WHERE DATE(created_at) = CURDATE() AND status = 'verified'");
$todayImages     = safeQuery("SELECT COUNT(*) as c FROM ai_requests WHERE DATE(created_at) = CURDATE() AND status = 'success'");
$pendingPayments = safeQuery("SELECT COUNT(*) as c FROM payments WHERE status = 'pending'");
$totalCreditsGiven = safeQuery("SELECT COALESCE(SUM(amount),0) as s FROM credit_ledger WHERE type = 'charge'");
$todayRegistrations = safeQuery("SELECT COUNT(*) as c FROM users WHERE DATE(created_at) = CURDATE()");

// Retry repair if tables were missing (so next page load works)
try {
    // Retry the repair for payments column specifically
    $conn = $db->getConnection();
    $check = $conn->query("SHOW COLUMNS FROM payments WHERE Field = 'amount_rial'");
    if ($check->fetch() === false) {
        $conn->exec("ALTER TABLE payments ADD COLUMN amount_rial INT NOT NULL AFTER track_id");
        \Database\Logger::info('Dashboard: amount_rial column added on retry');
    }
} catch (\Throwable $e) {
    // Table may not exist yet — ignore
}

ob_start();
?>
<div class="row g-4 mb-4">
    <div class="col-md-3">
        <div class="stat-card" style="border-right-color:#0984e3;">
            <div class="stat-icon">👥</div>
            <div class="stat-number"><?php echo number_format((int)$totalUsers); ?></div>
            <div class="stat-label">کاربران کل</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card" style="border-right-color:#00b894;">
            <div class="stat-icon">🤖</div>
            <div class="stat-number"><?php echo (int)$activeModels; ?></div>
            <div class="stat-label">مدل‌های فعال AI</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card" style="border-right-color:#fdcb6e;">
            <div class="stat-icon">💳</div>
            <div class="stat-number"><?php echo number_format((int)($todayPayments['cnt'] ?? 0)); ?></div>
            <div class="stat-label">پرداخت‌های امروز</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card" style="border-right-color:#e17055;">
            <div class="stat-icon">🎨</div>
            <div class="stat-number"><?php echo number_format((int)$todayImages); ?></div>
            <div class="stat-label">تصاویر ساخته‌شده امروز</div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-md-4">
        <div class="stat-card" style="border-right-color:#6c5ce7;">
            <div class="stat-icon">💰</div>
            <div class="stat-number"><?php echo number_format((int)($todayPayments['sum'] ?? 0)); ?></div>
            <div class="stat-label">درآمد امروز (ریال)</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card" style="border-right-color:#fd79a8;">
            <div class="stat-icon">⏳</div>
            <div class="stat-number"><?php echo (int)$pendingPayments; ?></div>
            <div class="stat-label">پرداخت‌های در انتظار</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card" style="border-right-color:#00cec9;">
            <div class="stat-icon">📝</div>
            <div class="stat-number"><?php echo number_format((int)$todayRegistrations); ?></div>
            <div class="stat-label">ثبت‌نام امروز</div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-md-6">
        <div class="table-container">
            <h5>🚀 دسترسی سریع</h5>
            <div class="d-grid gap-2">
                <a href="models.php" class="btn btn-outline-primary text-start">
                    <i class="bi bi-cpu"></i> 🤖 مدیریت مدل‌های AI
                </a>
                <a href="plans.php" class="btn btn-outline-success text-start">
                    <i class="bi bi-credit-card"></i> 💰 مدیریت پلن‌های پرداخت
                </a>
                <a href="users.php" class="btn btn-outline-info text-start">
                    <i class="bi bi-people"></i> 👥 مدیریت کاربران
                </a>
                <a href="payment_logs.php" class="btn btn-outline-warning text-start">
                    <i class="bi bi-journal-text"></i> 📋 مشاهده لاگ پرداخت‌ها
                </a>
                <a href="settings.php" class="btn btn-outline-secondary text-start">
                    <i class="bi bi-gear"></i> ⚙️ تنظیمات سیستم
                </a>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="table-container">
            <h5>📊 خلاصه سیستم</h5>
            <table class="table table-sm table-borderless">
                <tr>
                    <td>کل اعتبار شارژ شده:</td>
                    <td class="text-start"><strong><?php echo number_format((int)$totalCreditsGiven); ?></strong> اعتبار</td>
                </tr>
                <tr>
                    <td>کل کاربران:</td>
                    <td class="text-start"><strong><?php echo number_format((int)$totalUsers); ?></strong> کاربر</td>
                </tr>
                <tr>
                    <td>مدل‌های فعال AI:</td>
                    <td class="text-start"><strong><?php echo (int)$activeModels; ?></strong> مدل</td>
                </tr>
                <tr>
                    <td>پرداخت‌های امروز:</td>
                    <td class="text-start"><strong><?php echo (int)($todayPayments['cnt'] ?? 0); ?></strong> تراکنش</td>
                </tr>
                <tr>
                    <td>تصاویر ساخته‌شده امروز:</td>
                    <td class="text-start"><strong><?php echo number_format((int)$todayImages); ?></strong> تصویر</td>
                </tr>
            </table>
        </div>
    </div>
</div>

<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../../views/admin/layout.php';