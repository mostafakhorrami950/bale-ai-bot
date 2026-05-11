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

// Handle delete action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'delete_old') {
            $deletedAi = AILogger::purgeOldLogs();
            $deletedBot = AILogger::purgeBotLogs();
            $message = "🧹 پاکسازی انجام شد: {$deletedAi} لاگ AI + {$deletedBot} لاگ ربات حذف شد.";
        } elseif ($action === 'delete_all') {
            if (!empty($_POST['confirm']) && $_POST['confirm'] === 'yes') {
                $db->query("TRUNCATE TABLE ai_logs");
                $message = '🗑️ تمام لاگ‌های AI حذف شدند.';
            } else {
                throw new \Exception('لطفاً تأیید کنید');
            }
        } elseif ($action === 'delete_all_bot') {
            if (!empty($_POST['confirm']) && $_POST['confirm'] === 'yes') {
                $db->query("TRUNCATE TABLE bot_logs");
                $message = '🗑️ تمام لاگ‌های ربات حذف شدند.';
            } else {
                throw new \Exception('لطفاً تأیید کنید');
            }
        }
    } catch (\Throwable $e) {
        $message = '❌ خطا: ' . $e->getMessage();
        $messageType = 'danger';
    }
}

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Get total count
$countRow = $db->query("SELECT COUNT(*) as c FROM ai_logs")->fetch();
$total = (int)($countRow['c'] ?? 0);
$totalPages = max(1, ceil($total / $perPage));

// Get logs
$logs = $db->query(
    "SELECT id, event, data, created_at FROM ai_logs ORDER BY id DESC LIMIT ? OFFSET ?",
    [$perPage, $offset]
)->fetchAll();

// Get bot logs count
$botLogsCount = 0;
try {
    $botRow = $db->query("SELECT COUNT(*) as c FROM bot_logs")->fetch();
    $botLogsCount = (int)($botRow['c'] ?? 0);
} catch (\Throwable $e) {}

ob_start();
?>

<?php if ($message): ?>
<div class="alert alert-<?php echo $messageType === 'danger' ? 'danger' : 'success'; ?> alert-dismissible fade show">
    <?php echo htmlspecialchars($message); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">📋 لاگ‌های هوش مصنوعی</h4>
    <div class="btn-group">
        <form method="POST" style="display:inline;" onsubmit="return confirm('پاکسازی خودکار انجام شود؟ (لاگ‌های قدیمی‌تر از ۷ روز حذف می‌شوند)');">
            <input type="hidden" name="action" value="delete_old">
            <button type="submit" class="btn btn-outline-warning btn-sm">🧹 پاکسازی خودکار</button>
        </form>
        <form method="POST" style="display:inline;" onsubmit="return confirm('⚠️ تمام لاگ‌های AI حذف شوند؟ این عمل غیرقابل بازگشت است!');">
            <input type="hidden" name="action" value="delete_all">
            <input type="hidden" name="confirm" value="yes">
            <button type="submit" class="btn btn-outline-danger btn-sm">🗑️ حذف همه لاگ‌های AI</button>
        </form>
        <form method="POST" style="display:inline;" onsubmit="return confirm('⚠️ تمام لاگ‌های ربات حذف شوند؟ این عمل غیرقابل بازگشت است!');">
            <input type="hidden" name="action" value="delete_all_bot">
            <input type="hidden" name="confirm" value="yes">
            <button type="submit" class="btn btn-outline-danger btn-sm">🗑️ حذف لاگ‌های ربات</button>
        </form>
    </div>
</div>

<div class="row mb-3">
    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-icon">📝</div>
            <div class="stat-number"><?php echo number_format($total); ?></div>
            <div class="stat-label">تعداد لاگ‌های AI</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card" style="border-right-color:#e17055;">
            <div class="stat-icon">🤖</div>
            <div class="stat-number"><?php echo number_format($botLogsCount); ?></div>
            <div class="stat-label">لاگ‌های ربات (bot_logs)</div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="stat-card" style="border-right-color:#00b894;">
            <div class="stat-icon">⏰</div>
            <div class="stat-number">۷ روز</div>
            <div class="stat-label">مدت نگهداری خودکار لاگ‌ها (پس از آن پاک می‌شوند)</div>
        </div>
    </div>
</div>

<!-- Filter by event type -->
<div class="mb-3">
    <a href="?page=1" class="btn btn-sm btn-outline-secondary <?php echo empty($_GET['event']) ? 'active' : ''; ?>">همه</a>
    <?php 
    $eventTypes = $db->query("SELECT DISTINCT event FROM ai_logs ORDER BY event")->fetchAll();
    foreach ($eventTypes as $e): 
        $active = ($_GET['event'] ?? '') === $e['event'] ? 'active' : '';
    ?>
        <a href="?event=<?php echo urlencode($e['event']); ?>&page=1" class="btn btn-sm btn-outline-secondary <?php echo $active; ?>"><?php echo htmlspecialchars($e['event']); ?></a>
    <?php endforeach; ?>
</div>

<div class="table-container">
    <table class="table table-sm table-hover">
        <thead>
            <tr>
                <th style="width:60px;">#</th>
                <th style="width:120px;">رویداد</th>
                <th>داده (JSON)</th>
                <th style="width:160px;">زمان</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($logs)): ?>
                <tr><td colspan="4" class="text-muted text-center">هیچ لاگی یافت نشد.</td></tr>
            <?php else: ?>
                <?php $i = $offset + 1; foreach ($logs as $log): 
                    $eventClass = match($log['event']) {
                        'ERROR' => 'text-danger',
                        'WARNING' => 'text-warning',
                        'REQUEST' => 'text-info',
                        'RESPONSE' => 'text-success',
                        default => ''
                    };
                    $dataPreview = $log['data'];
                    // Truncate long JSON
                    if (mb_strlen($dataPreview) > 200) {
                        $dataPreview = mb_substr($dataPreview, 0, 200) . '...';
                    }
                ?>
                <tr>
                    <td><?php echo $i++; ?></td>
                    <td><strong class="<?php echo $eventClass; ?>"><?php echo htmlspecialchars($log['event']); ?></strong></td>
                    <td>
                        <div style="max-height:80px;overflow-y:auto;font-family:monospace;font-size:0.85rem;direction:ltr;text-align:left;">
                            <?php echo htmlspecialchars($dataPreview); ?>
                        </div>
                        <button type="button" class="btn btn-sm btn-link p-0" onclick="toggleFull(<?php echo $log['id']; ?>)">نمایش کامل</button>
                        <pre id="full_<?php echo $log['id']; ?>" style="display:none;font-family:monospace;font-size:0.85rem;direction:ltr;text-align:left;max-height:300px;overflow-y:auto;background:#f8f9fa;padding:10px;border-radius:5px;"><?php 
                            // Pretty-print JSON
                            $decoded = json_decode($log['data'], true);
                            echo htmlspecialchars(json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                        ?></pre>
                    </td>
                    <td style="font-size:0.85rem;"><?php echo htmlspecialchars($log['created_at']); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <nav>
        <ul class="pagination pagination-sm justify-content-center">
            <?php for ($p = 1; $p <= $totalPages; $p++): 
                $eventFilter = !empty($_GET['event']) ? '&event=' . urlencode($_GET['event']) : '';
            ?>
                <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                    <a class="page-link" href="?page=<?php echo $p . $eventFilter; ?>"><?php echo $p; ?></a>
                </li>
            <?php endfor; ?>
        </ul>
    </nav>
    <?php endif; ?>
</div>

<style>
.stat-card {
    background: #fff;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    height: 100%;
    border-right: 4px solid #0984e3;
}
.stat-card .stat-icon { font-size: 2rem; margin-bottom: 10px; }
.stat-card .stat-number { font-size: 1.8rem; font-weight: bold; color: #2d3436; }
.stat-card .stat-label { color: #636e72; font-size: 0.9rem; }
.table-container {
    background: #fff;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    margin-bottom: 20px;
}
</style>

<script>
function toggleFull(id) {
    var pre = document.getElementById('full_' + id);
    if (pre.style.display === 'none') {
        pre.style.display = 'block';
    } else {
        pre.style.display = 'none';
    }
}
</script>

<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../../views/admin/layout.php';