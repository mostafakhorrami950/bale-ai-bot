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
        "SELECT u.*, up.first_name, up.last_name, up.username
         FROM users u
         LEFT JOIN user_profiles up ON up.user_id = u.id
         WHERE u.bale_user_id LIKE ? OR up.username LIKE ? OR u.phone_number LIKE ?
         ORDER BY u.last_active_at DESC",
        [$searchParam, $searchParam, $searchParam]
    )->fetchAll();
} else {
    $users = $db->query(
        "SELECT u.*, up.first_name, up.last_name, up.username
         FROM users u
         LEFT JOIN user_profiles up ON up.user_id = u.id
         ORDER BY u.last_active_at DESC"
    )->fetchAll();
}

// ─── Export phone numbers to Excel (CSV) ───
if (isset($_GET['export_phones'])) {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="phones.xls"');
    echo "\xEF\xBB\xBF"; // BOM for UTF-8
    foreach ($users as $u) {
        $phone = trim($u['phone_number'] ?? '');
        if ($phone !== '') {
            // Convert 989XXXXXXXXX to 09XXXXXXXXX
            if (strlen($phone) === 12 && substr($phone, 0, 3) === '989') {
                $phone = '0' . substr($phone, 2);
            }
            echo $phone . "\n";
        }
    }
    exit;
}

// ─── Collect all phone numbers ───
$allPhones = [];
foreach ($users as $u) {
    $phone = trim($u['phone_number'] ?? '');
    if ($phone !== '') {
        $allPhones[] = $phone;
    }
}
$allPhonesStr = htmlspecialchars(implode(",\n", $allPhones));

ob_start();
?>

<!-- 📞 Phone Numbers TextArea -->
<div class="table-container mb-4">
    <h5>📞 شماره تلفن تمام کاربران</h5>
    <textarea class="form-control" rows="6" id="phonesTextarea" readonly style="direction:ltr;font-size:0.9rem;" onclick="this.select()"><?php echo $allPhonesStr; ?></textarea>
    <div class="mt-2">
        <button class="btn btn-sm btn-outline-primary" onclick="copyPhones()">📋 کپی کردن همه شماره‌ها</button>
        <a href="?export_phones=1<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" class="btn btn-sm btn-success">📥 خروجی اکسل</a>
        <span class="text-muted small ms-2">(<?php echo count($allPhones); ?> شماره)</span>
    </div>
</div>
<script>
function copyPhones() {
    var ta = document.getElementById('phonesTextarea');
    ta.select();
    document.execCommand('copy');
    alert('✅ شماره‌ها کپی شدند!');
}
</script>

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
                    <td style="font-family:monospace;"><?php echo $u['bale_user_id']; ?></td>
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
