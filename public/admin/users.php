<?php
require_once __DIR__ . '/../../init.php';

$pageTitle = 'مدیریت کاربران';
$activeMenu = 'users';

use Database\Database;
use Modules\Bot\CreditService;

$db = Database::getInstance();
$message = '';

// Search
$search = $_GET['search'] ?? '';
$searchParam = "%{$search}%";

$users = [];
if (!empty($search)) {
    $users = $db->query(
        "SELECT * FROM users WHERE bale_id LIKE ? OR username LIKE ? OR phone_number LIKE ? ORDER BY last_active_at DESC",
        [$searchParam, $searchParam, $searchParam]
    )->fetchAll();
} else {
    $users = $db->query("SELECT * FROM users ORDER BY last_active_at DESC")->fetchAll();
}

ob_start();
?>
<form method="GET" class="mb-3">
    <div class="input-group">
        <input type="text" name="search" class="form-control" placeholder="جستجو با شناسه بله، نام کاربری یا شماره تلفن..."
               value="<?php echo htmlspecialchars($search); ?>">
        <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i> جستجو</button>
        <?php if (!empty($search)): ?>
            <a href="users.php" class="btn btn-secondary">پاک کردن فیلتر</a>
        <?php endif; ?>
    </div>
</form>

<div class="table-container">
    <h5>👥 لیست کاربران (<?php echo count($users); ?> کاربر)</h5>
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>ID</th>
                <th>شناسه بله</th>
                <th>نام</th>
                <th>نام کاربری</th>
                <th>تلفن</th>
                <th>اعتبار</th>
                <th>وضعیت</th>
                <th>آخرین فعالیت</th>
                <th>عملیات</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($users)): ?>
                <tr><td colspan="9" class="text-center text-muted">کاربری یافت نشد.</td></tr>
            <?php else: ?>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td><?php echo $u['id']; ?></td>
                    <td style="font-family:monospace;"><?php echo $u['bale_id']; ?></td>
                    <td><?php echo htmlspecialchars(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')); ?></td>
                    <td><?php echo htmlspecialchars($u['username'] ?? '-'); ?></td>
                    <td dir="ltr"><?php echo htmlspecialchars($u['phone_number'] ?? '-'); ?></td>
                    <td><strong><?php echo number_format((int)($u['credits'] ?? 0)); ?></strong></td>
                    <td>
                        <?php if ($u['is_registered']): ?>
                            <span class="badge-active">✅ فعال</span>
                        <?php else: ?>
                            <span class="badge-inactive">❌ غیرفعال</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:0.85rem;"><?php echo $u['last_active_at'] ?? '-'; ?></td>
                    <td>
                        <a href="user_detail.php?id=<?php echo $u['id']; ?>" class="btn btn-sm btn-outline-info">👁️</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../../views/admin/layout.php';