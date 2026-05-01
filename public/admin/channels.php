<?php
require_once __DIR__ . '/../../init.php';

$pageTitle = 'مدیریت کانال‌ها';
$activeMenu = 'channels';

use Database\Database;

$db = Database::getInstance();
$message = '';

// Handle add
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        if ($_POST['action'] === 'add') {
            $channelId = trim($_POST['channel_id'] ?? '');
            $title = trim($_POST['title'] ?? '');
            $inviteLink = trim($_POST['invite_link'] ?? '');
            if (empty($channelId)) {
                throw new \Exception('شناسه کانال الزامی است');
            }
            $db->query(
                "INSERT INTO required_channels (channel_id, title, invite_link) VALUES (?, ?, ?)",
                [$channelId, $title, $inviteLink]
            );
            $message = '✅ کانال با موفقیت اضافه شد.';
        } elseif ($_POST['action'] === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            $row = $db->query("SELECT id, is_active FROM required_channels WHERE id = ?", [$id])->fetch();
            if ($row) {
                $newStatus = $row['is_active'] ? 0 : 1;
                $db->query("UPDATE required_channels SET is_active = ? WHERE id = ?", [$newStatus, $id]);
                $message = $newStatus ? '✅ کانال فعال شد.' : '⛔ کانال غیرفعال شد.';
            }
        } elseif ($_POST['action'] === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $db->query("DELETE FROM required_channels WHERE id = ?", [$id]);
            $message = '🗑️ کانال حذف شد.';
        } elseif ($_POST['action'] === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            $channelId = trim($_POST['channel_id'] ?? '');
            $title = trim($_POST['title'] ?? '');
            $inviteLink = trim($_POST['invite_link'] ?? '');
            if (empty($channelId)) {
                throw new \Exception('شناسه کانال الزامی است');
            }
            $db->query(
                "UPDATE required_channels SET channel_id = ?, title = ?, invite_link = ? WHERE id = ?",
                [$channelId, $title, $inviteLink, $id]
            );
            $message = '✅ کانال ویرایش شد.';
        }
    } catch (\Throwable $e) {
        $message = '❌ خطا: ' . $e->getMessage();
    }
}

$channels = $db->query("SELECT * FROM required_channels ORDER BY id ASC")->fetchAll();

ob_start();
?>
<?php if ($message): ?>
    <div class="alert <?php echo strpos($message, '❌') !== false ? 'alert-danger' : 'alert-success'; ?> alert-dismissible fade show">
        <?php echo $message; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-8">
        <div class="table-container">
            <h5>📢 کانال‌های اجباری</h5>
            <p class="text-muted">کاربران برای استفاده از ربات باید در این کانال‌ها عضو باشند. عضویت قبل از هر اقدام هوش مصنوعی بررسی می‌شود.</p>
            
            <form method="POST" class="mb-3" id="addForm">
                <input type="hidden" name="action" value="add">
                <div class="row g-2">
                    <div class="col-md-4">
                        <input type="text" name="channel_id" class="form-control" placeholder="شناسه کانال (مثلاً @mobix_tube)" required>
                    </div>
                    <div class="col-md-3">
                        <input type="text" name="title" class="form-control" placeholder="عنوان (اختیاری)">
                    </div>
                    <div class="col-md-3">
                        <input type="text" name="invite_link" class="form-control" placeholder="لینک دعوت (اختیاری)">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-success w-100">➕ افزودن</button>
                    </div>
                </div>
            </form>

            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>شناسه</th>
                        <th>عنوان</th>
                        <th>لینک</th>
                        <th>وضعیت</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($channels as $ch): ?>
                    <tr>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="id" value="<?php echo $ch['id']; ?>">
                            <td>
                                <input type="text" name="channel_id" class="form-control form-control-sm" 
                                       value="<?php echo htmlspecialchars($ch['channel_id']); ?>" 
                                       style="width:150px; direction:ltr;">
                            </td>
                            <td>
                                <input type="text" name="title" class="form-control form-control-sm" 
                                       value="<?php echo htmlspecialchars($ch['title'] ?? ''); ?>">
                            </td>
                            <td>
                                <input type="text" name="invite_link" class="form-control form-control-sm" 
                                       value="<?php echo htmlspecialchars($ch['invite_link'] ?? ''); ?>"
                                       style="direction:ltr;">
                            </td>
                            <td>
                                <span class="badge bg-<?php echo $ch['is_active'] ? 'success' : 'secondary'; ?>">
                                    <?php echo $ch['is_active'] ? 'فعال' : 'غیرفعال'; ?>
                                </span>
                            </td>
                            <td class="d-flex gap-1">
                                <button type="submit" name="action" value="edit" class="btn btn-sm btn-primary">💾</button>
                                <button type="submit" name="action" value="toggle" class="btn btn-sm btn-warning">
                                    <?php echo $ch['is_active'] ? '⛔' : '✅'; ?>
                                </button>
                                <button type="submit" name="action" value="delete" class="btn btn-sm btn-danger" 
                                        onclick="return confirm('کانال حذف شود؟');">🗑️</button>
                            </td>
                        </form>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($channels)): ?>
                    <tr><td colspan="5" class="text-center text-muted">هیچ کانالی ثبت نشده است.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="table-container">
            <h5>📖 راهنما</h5>
            <ul class="small">
                <li><strong>شناسه کانال:</strong> آیدی کانال مانند <code>@mobix_tube</code></li>
                <li><strong>عنوان:</strong> نام نمایشی کانال (اختیاری)</li>
                <li><strong>لینک دعوت:</strong> لینک عضویت در کانال (اختیاری)</li>
                <li><strong>فعال/غیرفعال:</strong> با کلیک روی دکمه وضعیت را تغییر دهید</li>
            </ul>
            <p class="small text-warning mt-3">
                ⚠️ توجه: برای تشخیص عضویت کاربران، ربات باید در کانال ادمین باشد.
            </p>
        </div>
    </div>
</div>

<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../../views/admin/layout.php';