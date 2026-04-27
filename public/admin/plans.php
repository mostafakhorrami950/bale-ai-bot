<?php
require_once __DIR__ . '/../../init.php';

$pageTitle = 'مدیریت پلن‌های پرداخت';
$activeMenu = 'plans';

use Modules\Admin\PlanManager;

$planManager = new PlanManager();
$message = '';
$editMode = false;
$editPlan = null;

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        if ($action === 'create') {
            $planManager->create([
                'plan_id'    => trim($_POST['plan_id']),
                'name'       => trim($_POST['name']),
                'credits'    => (int) $_POST['credits'],
                'price_rial' => (int) $_POST['price_rial'],
                'is_active'  => isset($_POST['is_active']) ? 1 : 0,
            ]);
            $message = '✅ پلن جدید با موفقیت ایجاد شد.';
        } elseif ($action === 'update' && isset($_POST['id'])) {
            $planManager->update((int) $_POST['id'], [
                'name'       => trim($_POST['name']),
                'credits'    => (int) $_POST['credits'],
                'price_rial' => (int) $_POST['price_rial'],
                'is_active'  => isset($_POST['is_active']) ? 1 : 0,
            ]);
            $message = '✅ پلن با موفقیت بروزرسانی شد.';
        } elseif ($action === 'toggle' && isset($_POST['id'])) {
            $planManager->toggleActive((int) $_POST['id']);
            $message = '✅ وضعیت پلن تغییر کرد.';
        } elseif ($action === 'delete' && isset($_POST['id'])) {
            $planManager->delete((int) $_POST['id']);
            $message = '✅ پلن حذف شد.';
        }
    } catch (\Throwable $e) {
        $message = '❌ خطا: ' . $e->getMessage();
    }
}

// If edit request (GET)
if (isset($_GET['edit'])) {
    $editPlan = $planManager->getById((int) $_GET['edit']);
    if ($editPlan) {
        $editMode = true;
    }
}

$plans = $planManager->getAll();

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
            <h5><?php echo $editMode ? '✏️ ویرایش پلن' : '➕ افزودن پلن جدید'; ?></h5>
            <form method="POST">
                <input type="hidden" name="action" value="<?php echo $editMode ? 'update' : 'create'; ?>">
                <?php if ($editMode): ?>
                    <input type="hidden" name="id" value="<?php echo $editPlan['id']; ?>">
                <?php endif; ?>

                <div class="mb-3">
                    <label class="form-label">شناسه پلن (unique):</label>
                    <input type="text" name="plan_id" class="form-control"
                           value="<?php echo $editMode ? htmlspecialchars($editPlan['plan_id']) : ''; ?>"
                           <?php echo $editMode ? 'readonly' : 'required'; ?>
                           placeholder="مثلاً: basic, standard, premium">
                </div>

                <div class="mb-3">
                    <label class="form-label">نام پلن:</label>
                    <input type="text" name="name" class="form-control" required
                           value="<?php echo $editMode ? htmlspecialchars($editPlan['name']) : ''; ?>"
                           placeholder="مثلاً: پایه">
                </div>

                <div class="mb-3">
                    <label class="form-label">تعداد اعتبار:</label>
                    <input type="number" name="credits" class="form-control" required min="1"
                           value="<?php echo $editMode ? $editPlan['credits'] : '50'; ?>">
                </div>

                <div class="mb-3">
                    <label class="form-label">قیمت (ریال):</label>
                    <input type="number" name="price_rial" class="form-control" required min="1"
                           value="<?php echo $editMode ? $editPlan['price_rial'] : '49000'; ?>">
                </div>

                <div class="mb-3 form-check">
                    <input type="checkbox" name="is_active" class="form-check-input" id="planActive"
                           <?php echo $editMode ? ($editPlan['is_active'] ? 'checked' : '') : 'checked'; ?>>
                    <label class="form-check-label" for="planActive">فعال</label>
                </div>

                <button type="submit" class="btn btn-primary">
                    <?php echo $editMode ? 'بروزرسانی' : 'ذخیره پلن'; ?>
                </button>
                <?php if ($editMode): ?>
                    <a href="plans.php" class="btn btn-secondary">انصراف</a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <div class="col-md-7">
        <div class="table-container">
            <h5>📋 لیست پلن‌ها</h5>
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>شناسه</th>
                        <th>نام</th>
                        <th>اعتبار</th>
                        <th>قیمت (ریال)</th>
                        <th>وضعیت</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($plans)): ?>
                        <tr><td colspan="7" class="text-center text-muted">هیچ پلنی یافت نشد.</td></tr>
                    <?php else: ?>
                        <?php foreach ($plans as $p): ?>
                        <tr>
                            <td><?php echo $p['id']; ?></td>
                            <td><code><?php echo htmlspecialchars($p['plan_id']); ?></code></td>
                            <td><?php echo htmlspecialchars($p['name']); ?></td>
                            <td><?php echo number_format($p['credits']); ?></td>
                            <td><?php echo number_format($p['price_rial']); ?></td>
                            <td>
                                <?php if ($p['is_active']): ?>
                                    <span class="badge-active">✅ فعال</span>
                                <?php else: ?>
                                    <span class="badge-inactive">❌ غیرفعال</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="plans.php?edit=<?php echo $p['id']; ?>" class="btn btn-sm btn-outline-primary">✏️</a>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('آیا مطمئن هستید؟');">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-warning">🔄</button>
                                </form>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('آیا مطمئن هستید؟');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
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