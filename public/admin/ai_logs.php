<?php
/**
 * View AI logs from the database.
 * Can view and delete logs.
 */
require_once __DIR__ . '/../../init.php';

$pageTitle = 'لاگ‌های هوش مصنوعی';
$activeMenu = 'ai_logs';

use Database\Database;
use Core\AILogger;

$db = Database::getInstance();

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        if ($action === 'delete_old') {
            $deletedAi = AILogger::purgeOldLogs();
            $deletedBot = AILogger::purgeBotLogs();
            $message = "پاکسازی انجام شد: {$deletedAi} لاگ AI + {$deletedBot} لاگ ربات";
        } elseif ($action === 'delete_all') {
            if (!empty($_POST['confirm']) && $_POST['confirm'] === 'yes') {
                $db->query("TRUNCATE TABLE ai_logs");
                $message = 'تمام لاگ‌های AI حذف شدند.';
            }
        } elseif ($action === 'delete_all_bot') {
            if (!empty($_POST['confirm']) && $_POST['confirm'] === 'yes') {
                $db->query("TRUNCATE TABLE bot_logs");
                $message = 'تمام لاگ‌های ربات حذف شدند.';
            }
        }
    } catch (\Throwable $e) {
        $message = 'خطا: ' . $e->getMessage();
        $messageType = 'danger';
    }
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$countRow = $db->query("SELECT COUNT(*) as c FROM ai_logs")->fetch();
$total = (int)($countRow['c'] ?? 0);

$logs = $db->query("SELECT id, event, data, created_at FROM ai_logs ORDER BY id DESC LIMIT ? OFFSET ?", [$perPage, $offset])->fetchAll();

$botLogsCount = 0;
try {
    $botRow = $db->query("SELECT COUNT(*) as c FROM bot_logs")->fetch();
    $botLogsCount = (int)($botRow['c'] ?? 0);
} catch (\Throwable $e) {}

$botPage = max(1, (int)($_GET['bot_page'] ?? 1));
$botPerPage = 50;
$botOffset = ($botPage - 1) * $botPerPage;

$botLogs = [];
if ($botLogsCount > 0) {
    try {
        $botLogs = $db->query("SELECT id, level, message, context, created_at FROM bot_logs ORDER BY id DESC LIMIT ? OFFSET ?", [$botPerPage, $botOffset])->fetchAll();
    } catch (\Throwable $e) {}
}

ob_start();
?>
<style>
.stat-card { background:#fff; border-radius:12px; padding:20px; box-shadow:0 2px 8px rgba(0,0,0,0.05); height:100%; border-right:4px solid #0984e3; }
.stat-icon { font-size:2rem; margin-bottom:10px; }
.stat-number { font-size:1.8rem; font-weight:bold; color:#2d3436; }
.stat-label { color:#636e72; font-size:0.9rem; }
.table-container { background:#fff; border-radius:12px; padding:20px; box-shadow:0 2px 8px rgba(0,0,0,0.05); margin-bottom:20px; }
</style>

<?php if ($message): ?>
<div class="alert alert-<?php echo $messageType === 'danger' ? 'danger' : 'success'; ?>"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<div class="row mb-3">
    <div class="col-md-3"><div class="stat-card"><div class="stat-icon">&#x1F4DD;</div><div class="stat-number"><?php echo number_format($total); ?></div><div class="stat-label">تعداد لاگ‌های AI</div></div></div>
    <div class="col-md-3"><div class="stat-card" style="border-right-color:#e17055;"><div class="stat-icon">&#x1F916;</div><div class="stat-number"><?php echo number_format($botLogsCount); ?></div><div class="stat-label">لاگ‌های ربات</div></div></div>
    <div class="col-md-6"><div class="stat-card" style="border-right-color:#00b894;"><div class="stat-icon">&#x23F0;</div><div class="stat-number">۷ روز</div><div class="stat-label">مدت نگهداری خودکار</div></div></div>
</div>

<div class="table-container mb-3">
    <h5>🧹 مدیریت لاگ‌ها</h5>
    <div class="d-flex flex-wrap gap-2">
        <!-- Delete old logs (auto) -->
        <form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="delete_old">
            <button type="submit" class="btn btn-outline-warning btn-sm" onclick="return confirm('لاگ‌های قدیمی پاک شوند؟')">
                🗑️ حذف لاگ‌های قدیمی (خودکار)
            </button>
        </form>
        <!-- Delete all AI logs -->
        <form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="delete_all">
            <input type="hidden" name="confirm" value="yes">
            <button type="submit" class="btn btn-outline-danger btn-sm" onclick="return confirm('⚠️ تمام لاگ‌های AI حذف شوند؟')">
                🗑️ حذف تمام لاگ‌های AI (دستی)
            </button>
        </form>
        <!-- Delete all bot logs -->
        <form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="delete_all_bot">
            <input type="hidden" name="confirm" value="yes">
            <button type="submit" class="btn btn-outline-danger btn-sm" onclick="return confirm('⚠️ تمام لاگ‌های ربات حذف شوند؟')">
                🗑️ حذف تمام لاگ‌های ربات (دستی)
            </button>
        </form>
    </div>
</div>

<h5>لاگ‌های AI</h5>
<table class="table table-sm table-hover">
<thead><tr><th>#</th><th>رویداد</th><th>داده</th><th>زمان</th></tr></thead>
<tbody>
<?php if (empty($logs)): ?>
<tr><td colspan="4" class="text-muted text-center">هیچ لاگی یافت نشد.</td></tr>
<?php else: $i = $offset + 1; foreach ($logs as $log): ?>
<tr>
<td><?php echo $i++; ?></td>
<td><?php echo htmlspecialchars($log['event']); ?></td>
<td><div style="max-height:80px;overflow:auto;font-family:monospace;font-size:0.85rem;"><?php echo htmlspecialchars(mb_substr($log['data'],0,200)); ?></div></td>
<td><?php echo htmlspecialchars($log['created_at']); ?></td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>

<hr>

<h5>لاگ‌های ربات</h5>
<table class="table table-sm table-hover">
<thead><tr><th>#</th><th>سطح</th><th>پیام</th><th>داده</th><th>زمان</th></tr></thead>
<tbody>
<?php if (empty($botLogs)): ?>
<tr><td colspan="5" class="text-muted text-center">هیچ لاگی یافت نشد.</td></tr>
<?php else: $bi = $botOffset + 1; foreach ($botLogs as $log): ?>
<tr>
<td><?php echo $bi++; ?></td>
<td><?php echo htmlspecialchars($log['level']); ?></td>
<td><?php echo htmlspecialchars(mb_substr($log['message'],0,100)); ?></td>
<td style="font-family:monospace;font-size:0.85rem;"><?php echo htmlspecialchars(mb_substr($log['context'],0,100)); ?></td>
<td><?php echo htmlspecialchars($log['created_at']); ?></td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>

<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../../views/admin/layout.php';