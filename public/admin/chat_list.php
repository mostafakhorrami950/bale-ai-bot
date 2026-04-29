<?php
require_once __DIR__ . '/../../init.php';

$pageTitle = 'مکالمات کاربران';
$activeMenu = 'chat_list';

use Database\Database;

$db = Database::getInstance();
$message = '';
$convs = [];
$total = 0;
$totalPages = 1;

// Check if chat_conversations table exists
try {
    $db->query("SELECT 1 FROM chat_conversations LIMIT 1");
} catch (\Throwable $e) {
    ob_start();
    ?>
    <div class="table-container">
        <h5>💬 لیست مکالمات</h5>
        <p class="text-muted text-center py-4">جدول chat_conversations هنوز ایجاد نشده است. لطفاً ابتدا از <a href="repair_db.php">صفحه تعمیر دیتابیس</a> استفاده کنید.</p>
    </div>
    <?php
    $pageContent = ob_get_clean();
    require __DIR__ . '/../../views/admin/layout.php';
    return;
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_conv'])) {
    $db->query("DELETE FROM chat_conversations WHERE id = ?", [(int)$_POST['delete_conv']]);
    $message = '✅ مکالمه حذف شد.';
}

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

try {
    $total = $db->query("SELECT COUNT(*) as c FROM chat_conversations")->fetch()['c'] ?? 0;
} catch (\Throwable $e) {
    $total = 0;
}
$totalPages = max(1, ceil($total / $perPage));

try {
    $convs = $db->query(
        "SELECT c.*, u.bale_user_id, u.username
         FROM chat_conversations c
         LEFT JOIN users u ON c.user_id = u.id
         ORDER BY c.id DESC
         LIMIT ? OFFSET ?",
        [$perPage, $offset]
    )->fetchAll();
} catch (\Throwable $e) {
    $convs = [];
}

ob_start();
?>
<?php if (isset($message)): ?>
    <div class="alert alert-success alert-dismissible fade show"><?php echo $message; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="table-container">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5>💬 لیست مکالمات (<?php echo number_format($total); ?>)</h5>
    </div>
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>ID</th>
                <th>کاربر</th>
                <th>مدل</th>
                <th>عنوان</th>
                <th>وضعیت</th>
                <th>تاریخ</th>
                <th>عملیات</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($convs)): ?>
                <tr><td colspan="7" class="text-center text-muted">مکالمه‌ای یافت نشد.</td></tr>
            <?php else: ?>
                <?php foreach ($convs as $c): ?>
                <tr>
                    <td><?php echo $c['id']; ?></td>
                    <td>
                        <a href="user_detail.php?id=<?php echo $c['user_id']; ?>" class="text-decoration-none">
                            <?php echo htmlspecialchars($c['username'] ?? 'User#' . $c['user_id']); ?>
                        </a>
                    </td>
                    <td><code style="font-size:0.8rem;"><?php echo htmlspecialchars(mb_substr($c['model'] ?? '?', 0, 30)); ?></code></td>
                    <td style="max-width:150px; overflow:hidden; text-overflow:ellipsis;">
                        <?php echo htmlspecialchars(mb_substr($c['title'] ?? 'بدون عنوان', 0, 40)); ?>
                    </td>
                    <td>
                        <?php if (($c['status'] ?? '') === 'active'): ?>
                            <span class="badge-active">✅ فعال</span>
                        <?php else: ?>
                            <span class="badge-inactive">📁 بایگانی</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:0.8rem;"><?php echo substr($c['created_at'] ?? '', 0, 16); ?></td>
                    <td>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('حذف شود؟');">
                            <input type="hidden" name="delete_conv" value="<?php echo $c['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">🗑️</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

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
</div>

<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../../views/admin/layout.php';