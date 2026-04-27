<?php
require_once __DIR__ . '/../../init.php';

$pageTitle = 'مدیریت مدل‌های AI';
$activeMenu = 'models';

use Modules\Admin\ModelManager;

$modelManager = new ModelManager();
$message = '';
$editMode = false;
$editModel = null;

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        if ($action === 'create') {
            $modelManager->createModel([
                'name'          => trim($_POST['name']),
                'provider'      => trim($_POST['provider']),
                'cost_per_image'=> (int) $_POST['cost_per_image'],
                'is_active'     => isset($_POST['is_active']) ? 1 : 0,
            ]);
            $message = '✅ مدل جدید با موفقیت اضافه شد.';
        } elseif ($action === 'update' && isset($_POST['id'])) {
            $modelManager->updateModel((int) $_POST['id'], [
                'name'          => trim($_POST['name']),
                'provider'      => trim($_POST['provider']),
                'cost_per_image'=> (int) $_POST['cost_per_image'],
                'is_active'     => isset($_POST['is_active']) ? 1 : 0,
            ]);
            $message = '✅ مدل با موفقیت بروزرسانی شد.';
        } elseif ($action === 'toggle' && isset($_POST['id'])) {
            $modelManager->toggleModel((int) $_POST['id']);
            $message = '✅ وضعیت مدل تغییر کرد.';
        } elseif ($action === 'delete' && isset($_POST['id'])) {
            $modelManager->deleteModel((int) $_POST['id']);
            $message = '✅ مدل حذف شد.';
        }
    } catch (\Throwable $e) {
        $message = '❌ خطا: ' . $e->getMessage();
    }
}

// Edit mode (GET)
if (isset($_GET['edit'])) {
    $editModel = $modelManager->getById((int) $_GET['edit']);
    if ($editModel) {
        $editMode = true;
    }
}

$models = $modelManager->getAllModels();

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
            <h5><?php echo $editMode ? '✏️ ویرایش مدل' : '➕ افزودن مدل جدید'; ?></h5>
            <form method="POST">
                <input type="hidden" name="action" value="<?php echo $editMode ? 'update' : 'create'; ?>">
                <?php if ($editMode): ?>
                    <input type="hidden" name="id" value="<?php echo $editModel['id']; ?>">
                <?php endif; ?>

                <div class="mb-3">
                    <label class="form-label">نام مدل:</label>
                    <input type="text" name="name" class="form-control" required
                           value="<?php echo $editMode ? htmlspecialchars($editModel['name']) : ''; ?>"
                           placeholder="مثلاً: dall-e-3">
                </div>

                <div class="mb-3">
                    <label class="form-label">ارائه‌دهنده (Provider):</label>
                    <select name="provider" class="form-select">
                        <option value="gapgpt" <?php echo ($editMode && $editModel['provider'] === 'gapgpt') ? 'selected' : ''; ?>>GapGPT</option>
                        <option value="custom" <?php echo ($editMode && $editModel['provider'] === 'custom') ? 'selected' : ''; ?>>Custom</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label">هزینه هر تصویر (اعتبار):</label>
                    <input type="number" name="cost_per_image" class="form-control" required min="1"
                           value="<?php echo $editMode ? $editModel['cost_per_image'] : '2'; ?>">
                </div>

                <div class="mb-3 form-check">
                    <input type="checkbox" name="is_active" class="form-check-input" id="modelActive"
                           <?php echo $editMode ? ($editModel['is_active'] ? 'checked' : '') : 'checked'; ?>>
                    <label class="form-check-label" for="modelActive">فعال</label>
                </div>

                <button type="submit" class="btn btn-primary">
                    <?php echo $editMode ? 'بروزرسانی مدل' : 'ذخیره مدل'; ?>
                </button>
                <?php if ($editMode): ?>
                    <a href="models.php" class="btn btn-secondary">انصراف</a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <div class="col-md-7">
        <div class="table-container">
            <h5>📋 لیست مدل‌ها</h5>
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>نام</th>
                        <th>ارائه‌دهنده</th>
                        <th>هزینه (اعتبار)</th>
                        <th>وضعیت</th>
                        <th>تاریخ ساخت</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($models)): ?>
                        <tr><td colspan="7" class="text-center text-muted">هیچ مدلی یافت نشد.</td></tr>
                    <?php else: ?>
                        <?php foreach ($models as $m): ?>
                        <tr>
                            <td><?php echo $m['id']; ?></td>
                            <td><?php echo htmlspecialchars($m['name']); ?></td>
                            <td><code><?php echo htmlspecialchars($m['provider'] ?? 'gapgpt'); ?></code></td>
                            <td><?php echo number_format($m['cost_per_image']); ?></td>
                            <td>
                                <?php if ($m['is_active']): ?>
                                    <span class="badge-active">✅ فعال</span>
                                <?php else: ?>
                                    <span class="badge-inactive">❌ غیرفعال</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:0.85rem;"><?php echo $m['created_at']; ?></td>
                            <td>
                                <a href="models.php?edit=<?php echo $m['id']; ?>" class="btn btn-sm btn-outline-primary">✏️</a>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('آیا مطمئن هستید؟');">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?php echo $m['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-warning">🔄</button>
                                </form>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('آیا مطمئن هستید؟');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $m['id']; ?>">
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