<?php
require_once __DIR__ . '/../../init.php';

$pageTitle = 'مدیریت کلیدهای API';
$activeMenu = 'apikeys';

use Modules\Admin\ApiManager;

$apiManager = new ApiManager();
$message = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        if ($action === 'add') {
            $apiManager->addKey(trim($_POST['api_key']), trim($_POST['provider'] ?? 'gapgpt'));
            $message = '✅ کلید API جدید اضافه شد.';
        } elseif ($action === 'activate' && isset($_POST['id'])) {
            $apiManager->setActive((int) $_POST['id']);
            $message = '✅ کلید فعال شد.';
        } elseif ($action === 'delete' && isset($_POST['id'])) {
            $apiManager->deleteKey((int) $_POST['id']);
            $message = '✅ کلید حذف شد.';
        }
    } catch (\Throwable $e) {
        $message = '❌ خطا: ' . $e->getMessage();
    }
}

$keys = $apiManager->getAllKeys();

ob_start();
?>
<?php if ($message): ?>
    <div class="alert <?php echo strpos($message, '❌') !== false ? 'alert-danger' : 'alert-success'; ?> alert-dismissible fade show">
        <?php echo $message; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row">
    <div class="col-md-5">
        <div class="table-container">
            <h5>➕ افزودن کلید API جدید</h5>
            <form method="POST">
                <input type="hidden" name="action" value="add">

                <div class="mb-3">
                    <label class="form-label">ارائه‌دهنده (Provider):</label>
                    <select name="provider" class="form-select">
                        <option value="gapgpt">GapGPT</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label">کلید API:</label>
                    <input type="text" name="api_key" class="form-control" required
                           placeholder="کلید دریافتی از پنل GapGPT را وارد کنید"
                           style="direction:ltr; text-align:left; font-family:monospace;">
                </div>

                <button type="submit" class="btn btn-primary">ذخیره کلید</button>
            </form>
        </div>
    </div>

    <div class="col-md-7">
        <div class="table-container">
            <h5>🔑 لیست کلیدهای API</h5>
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i> تنها یک کلید می‌تواند در هر لحظه فعال باشد.
            </div>
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>ارائه‌دهنده</th>
                        <th>کلید</th>
                        <th>وضعیت</th>
                        <th>تاریخ ثبت</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($keys)): ?>
                        <tr><td colspan="6" class="text-center text-muted">هیچ کلیدی ثبت نشده است.</td></tr>
                    <?php else: ?>
                        <?php foreach ($keys as $k): ?>
                        <tr>
                            <td><?php echo $k['id']; ?></td>
                            <td><code><?php echo htmlspecialchars($k['provider'] ?? 'gapgpt'); ?></code></td>
                            <td style="font-family:monospace; direction:ltr; text-align:left;">
                                <?php echo htmlspecialchars(substr($k['api_key'], 0, 8) . '...' . substr($k['api_key'], -4)); ?>
                            </td>
                            <td>
                                <?php if ($k['is_active']): ?>
                                    <span class="badge-active">✅ فعال</span>
                                <?php else: ?>
                                    <span class="badge-inactive">❌ غیرفعال</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:0.85rem;"><?php echo $k['created_at']; ?></td>
                            <td>
                                <?php if (!$k['is_active']): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="activate">
                                        <input type="hidden" name="id" value="<?php echo $k['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-success">فعال‌سازی</button>
                                    </form>
                                <?php endif; ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('آیا مطمئن هستید؟');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $k['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">🗑️</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../../views/admin/layout.php';