<?php
$pageTitle = 'خطاهای مهم';
$activeMenu = 'error_logs';

use Database\Database;

$db = Database::getInstance();

// Check if app_errors table exists
try {
    $db->query("SELECT 1 FROM app_errors LIMIT 1");
} catch (\Throwable $e) {
    ob_start();
    ?>
    <div class="table-container">
        <h5>⚠️ خطاهای مهم</h5>
        <p class="text-muted text-center py-4">جدول app_errors هنوز ایجاد نشده است. لطفاً ابتدا از <a href="repair_db.php">صفحه تعمیر دیتابیس</a> استفاده کنید.</p>
    </div>
    <?php
    $pageContent = ob_get_clean();
    require __DIR__ . '/../../views/admin/layout.php';
    return;
}

// Handle clear all
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_all'])) {
    try {
        $db->query("TRUNCATE TABLE app_errors");
        $message = '✅ تمام خطاها پاک شدند.';
    } catch (\Throwable $e) {
        $message = '❌ خطا: ' . $e->getMessage();
    }
}

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

try {
    $total = $db->query("SELECT COUNT(*) as c FROM app_errors")->fetch()['c'] ?? 0;
} catch (\Throwable $e) {
    $total = 0;
}
$totalPages = max(1, ceil($total / $perPage));

$errors = [];
try {
    $errors = $db->query(
        "SELECT * FROM app_errors ORDER BY id DESC LIMIT ? OFFSET ?",
        [$perPage, $offset]
    )->fetchAll();
} catch (\Throwable $e) {
    $errors = [];
}

ob_start();
?>
<?php if ($message): ?>
<div class="alert alert-success alert-dismissible fade show"><?php echo $message; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="table-container">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5>⚠️ خطاهای مهم (<?php echo number_format($total); ?>)</h5>
        <form method="POST" style="display:inline;" onsubmit="return confirm('همه خطاها پاک شوند؟');">
            <button type="submit" name="clear_all" class="btn btn-sm btn-outline-danger">🗑 پاک کردن همه</button>
        </form>
    </div>

    <?php if (empty($errors)): ?>
        <p class="text-muted text-center py-4">هیچ خطایی ثبت نشده است. ✅</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover table-sm">
                <thead class="table-dark">
                    <tr>
                        <th>#</th>
                        <th>نوع</th>
                        <th>پیام خطا</th>
                        <th>کاربر</th>
                        <th>تاریخ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($errors as $e): ?>
                    <tr>
                        <td><?php echo $e['id']; ?></td>
                        <td><code><?php echo htmlspecialchars($e['error_type'] ?? 'unknown'); ?></code></td>
                        <td style="max-width:400px;">
                            <div style="word-break:break-word;">
                                <strong><?php echo htmlspecialchars(mb_substr($e['error_message'] ?? '', 0, 200)); ?></strong>
                            </div>
                            <?php if (!empty($e['error_trace'])): ?>
                                <button class="btn btn-sm btn-link p-0 mt-1" onclick="alert('<?php echo htmlspecialchars(str_replace("'", "\\'", $e['error_trace']), ENT_QUOTES); ?>')">📋 مشاهده کامل</button>
                            <?php endif; ?>
                            <?php if (!empty($e['payload_data'])): ?>
                                <button class="btn btn-sm btn-link p-0 mt-1" onclick="alert('<?php echo htmlspecialchars(str_replace("'", "\\'", mb_substr($e['payload_data'], 0, 2000)), ENT_QUOTES); ?>')">📦 مشاهده payload</button>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($e['bale_user_id']): ?>
                                <a href="user_detail.php?bale_id=<?php echo $e['bale_user_id']; ?>"><?php echo $e['bale_user_id']; ?></a>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:0.8rem;"><?php echo $e['created_at']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <nav>
            <ul class="pagination pagination-sm justify-content-center">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../../views/admin/layout.php';