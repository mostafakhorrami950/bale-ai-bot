<?php
/** 
 * مدیریت فایل‌های تولیدشده توسط هوش مصنوعی
 * مشاهده، جستجو و پاک کردن فایل‌های قدیمی
 */
$pageTitle = 'مدیریت فایل‌های تولید شده';
$activeMenu = 'generated_files';
require_once __DIR__ . '/../../init.php';

use Database\Database;

session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$db = Database::getInstance();
$message = '';
$messageType = 'success';

// ─── Actions ───
if (isset($_GET['delete_all'])) {
    $generationId = $_GET['delete_all'];
    $files = $db->query("SELECT file_path FROM generated_files WHERE generation_id = ?", [$generationId])->fetchAll();
    foreach ($files as $f) {
        if (file_exists($f['file_path'])) @unlink($f['file_path']);
    }
    $db->query("DELETE FROM generated_files WHERE generation_id = ?", [$generationId]);
    $message = "✅ فایل‌های «{$generationId}» حذف شدند.";
}

if (isset($_GET['cleanup'])) {
    $period = $_GET['cleanup']; // 'day', 'week', 'month'
    $intervals = ['day' => '1 DAY', 'week' => '7 DAY', 'month' => '1 MONTH'];
    $interval = $intervals[$period] ?? '1 DAY';
    $files = $db->query("SELECT file_path FROM generated_files WHERE stored_at < NOW() - INTERVAL {$interval}")->fetchAll();
    $count = 0;
    foreach ($files as $f) {
        if (file_exists($f['file_path'])) { @unlink($f['file_path']); $count++; }
    }
    $db->query("DELETE FROM generated_files WHERE stored_at < NOW() - INTERVAL {$interval}");
    $labels = ['day' => 'روز', 'week' => 'هفته', 'month' => 'ماه'];
    $message = "✅ {$count} فایل قدیمی‌تر از یک {$labels[$period]} حذف شدند.";
}

// ─── Search / Pagination ───
$search = trim($_GET['search'] ?? '');
$page = max(0, (int)($_GET['p'] ?? 0));
$perPage = 20;
$where = '';
$params = [];
if ($search) {
    $where = 'WHERE generation_id LIKE ? OR model_name LIKE ? OR prompt LIKE ?';
    $params = ["%{$search}%", "%{$search}%", "%{$search}%"];
}
$totalRow = $db->query("SELECT COUNT(*) as c FROM generated_files {$where}", $params)->fetch();
$total = (int)($totalRow['c'] ?? 0);
$totalPages = max(1, (int)ceil($total / $perPage));
if ($page >= $totalPages) $page = max(0, $totalPages - 1);

$files = $db->query("SELECT f.*, COALESCE(u.phone_number,'') as user_phone FROM generated_files f LEFT JOIN users u ON u.id = f.user_id {$where} ORDER BY f.id DESC LIMIT ? OFFSET ?",
    array_merge($params, [$perPage, $page * $perPage])
)->fetchAll();

$totalCount = $db->query("SELECT COUNT(*) as c FROM generated_files")->fetch()['c'];
$totalSize = $db->query("SELECT COALESCE(SUM(file_size),0) as s FROM generated_files")->fetch()['s'];

ob_start();
?>
<div class="row">
    <div class="col-12 col-lg-12 mx-auto">
        <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show"><?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="stat-card" style="border-right-color: #0984e3;">
                    <div class="stat-icon">📁</div>
                    <div class="stat-number"><?php echo number_format($totalCount); ?></div>
                    <div class="stat-label">کل فایل‌ها</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card" style="border-right-color: #00b894;">
                    <div class="stat-icon">💾</div>
                    <div class="stat-number"><?php echo number_format($totalSize / 1024 / 1024, 2); ?> MB</div>
                    <div class="stat-label">حجم کل</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card p-3 text-center">
                    <h6>🧹 پاک‌سازی خودکار</h6>
                    <div class="d-flex gap-2 justify-content-center flex-wrap">
                        <a href="?cleanup=day" class="btn btn-sm btn-outline-warning" onclick="return confirm('فایل‌های قدیمی‌تر از ۱ روز پاک شوند؟')">🗑️ بیش از ۱ روز</a>
                        <a href="?cleanup=week" class="btn btn-sm btn-outline-danger" onclick="return confirm('فایل‌های قدیمی‌تر از ۱ هفته پاک شوند؟')">🗑️ بیش از ۱ هفته</a>
                        <a href="?cleanup=month" class="btn btn-sm btn-outline-danger" onclick="return confirm('فایل‌های قدیمی‌تر از ۱ ماه پاک شوند؟')">🗑️ بیش از ۱ ماه</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Search -->
        <form class="row gx-2 mb-3">
            <div class="col-md-6">
                <input type="text" name="search" class="form-control" placeholder="جستجوی Generation ID یا مدل..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-auto">
                <button class="btn btn-outline-primary">🔍 جستجو</button>
                <a href="?" class="btn btn-outline-secondary">❌ پاک کردن</a>
            </div>
        </form>

        <!-- Files Table -->
        <div class="table-container">
            <h5>📂 فایل‌های تولید شده</h5>
            <div class="table-responsive">
                <table class="table table-sm table-hover">
                    <thead class="table-dark">
                        <tr>
                            <th>ID</th>
                            <th>Generation ID</th>
                            <th>کاربر</th>
                            <th>مدل</th>
                            <th>نوع</th>
                            <th>Prompt</th>
                            <th>حجم</th>
                            <th>تاریخ</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($files)): ?>
                        <tr><td colspan="9" class="text-center text-muted">هیچ فایلی یافت نشد.</td></tr>
                        <?php else: foreach ($files as $f): ?>
                        <tr>
                            <td><?php echo $f['id']; ?></td>
                            <td><code><?php echo htmlspecialchars($f['generation_id']); ?></code></td>
                            <td><?php echo htmlspecialchars($f['user_phone'] ?? '#' . $f['user_id']); ?></td>
                            <td><?php echo htmlspecialchars($f['model_name']); ?></td>
                            <td><span class="badge <?php echo $f['file_type'] === 'image' ? 'bg-info' : 'bg-warning'; ?>"><?php echo $f['file_type']; ?></span></td>
                            <td><small><?php echo htmlspecialchars(mb_substr($f['prompt'] ?? '', 0, 40)); ?></small></td>
                            <td><?php echo number_format((int)$f['file_size'] / 1024, 1); ?> KB</td>
                            <td><small><?php echo substr($f['stored_at'] ?? '', 0, 16); ?></small></td>
                            <td>
                                <a href="?delete_all=<?php echo urlencode($f['generation_id']); ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف شود؟')">🗑️</a>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPages > 1): ?>
            <nav>
                <ul class="pagination justify-content-center">
                    <?php for ($i = 0; $i < $totalPages; $i++): ?>
                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                        <a class="page-link" href="?p=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>"><?php echo $i + 1; ?></a>
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