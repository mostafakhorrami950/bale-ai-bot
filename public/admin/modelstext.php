<?php
/**
 * Manage text models (ai_text_models).
 */
require_once __DIR__ . '/../../init.php';

$pageTitle = 'مدیریت مدل‌های متنی';
$activeMenu = 'models';

use Modules\Admin\ModelManager;
use Admin\ModelHelper;

$modelType = 'text';
$modelManager = new ModelManager();
$message = '';
$messageType = 'success';
$editMode = false;
$editModel = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $data = ModelHelper::validateAndSanitize($_POST);
        $data['model_type'] = $modelType;
        $action = trim($_POST['action'] ?? '');
        ModelHelper::logAction('FORM_SUBMIT_TEXT', ['action' => $action, 'name' => $data['name']]);
        if ($action === 'create') {
            $modelManager->createModel($data);
            $message = '✅ مدل متنی اضافه شد.';
        } elseif ($action === 'update' && isset($_POST['id'])) {
            $modelManager->updateModel((int)$_POST['id'], $data);
            $message = '✅ مدل متنی بروزرسانی شد.';
        } elseif ($action === 'toggle' && isset($_POST['id'])) {
            $modelManager->toggleModel((int)$_POST['id'], $modelType);
            $message = '✅ وضعیت مدل تغییر کرد.';
        } elseif ($action === 'delete' && isset($_POST['id'])) {
            $modelManager->deleteModel((int)$_POST['id'], $modelType);
            $message = '✅ مدل حذف شد.';
        } else {
            throw new \InvalidArgumentException('عملیات نامعتبر');
        }
    } catch (\InvalidArgumentException $e) {
        $message = '❌ ' . $e->getMessage(); $messageType = 'danger';
    } catch (\Throwable $e) {
        $message = '❌ خطا: ' . $e->getMessage(); $messageType = 'danger';
    }
}

if (isset($_GET['edit'])) {
    $editModel = $modelManager->getById((int)$_GET['edit'], $modelType);
    if ($editModel) $editMode = true;
}

$models = $modelManager->getAllModels();
$models = array_filter($models, fn($m) => ($m['model_type'] ?? '') === $modelType);

ob_start();
echo ModelHelper::alertHtml($message, $messageType);
?>
<div class="row">
    <div class="col-md-5">
        <div class="table-container">
            <h5><?php echo $editMode ? '✏️ ویرایش مدل متنی' : '➕ افزودن مدل متنی'; ?></h5>
            <form method="POST">
                <input type="hidden" name="action" value="<?php echo $editMode ? 'update' : 'create'; ?>">
                <input type="hidden" name="model_type" value="<?php echo $modelType; ?>">
                <?php if ($editMode): ?><input type="hidden" name="id" value="<?php echo $editModel['id']; ?>"><?php endif; ?>

                <div class="mb-3">
                    <label class="form-label">نام مدل (شناسه API):</label>
                    <input type="text" name="name" class="form-control" required maxlength="200"
                           value="<?php echo $editMode ? htmlspecialchars($editModel['name'] ?? '') : ''; ?>"
                           placeholder="مثلاً: google/gemini-2.5-flash">
                </div>
                <div class="mb-3">
                    <label class="form-label">نام نمایشی (در ربات):</label>
                    <input type="text" name="display_name" class="form-control" maxlength="200"
                           value="<?php echo $editMode ? htmlspecialchars($editModel['display_name'] ?? $editModel['name'] ?? '') : ''; ?>"
                           placeholder="مثلاً: جمینای 2.5 فلش">
                </div>
                <div class="mb-3">
                    <label class="form-label">توضیحات (اختیاری):</label>
                    <textarea name="description" class="form-control" rows="2" maxlength="500"><?php echo $editMode ? htmlspecialchars($editModel['description'] ?? '') : ''; ?></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label">ارائه‌دهنده:</label>
                    <select name="provider" class="form-select">
                        <option value="openrouter" <?php echo ($editMode ? $editModel['provider'] : 'openrouter') === 'openrouter' ? 'selected' : ''; ?>>OpenRouter</option>
                        <option value="custom" <?php echo ($editMode ? $editModel['provider'] : '') === 'custom' ? 'selected' : ''; ?>>Custom</option>
                    </select>
                </div>
                <div class="mb-3 form-check">
                    <input type="checkbox" name="is_active" class="form-check-input" id="ia"
                           <?php echo $editMode ? ($editModel['is_active'] ? 'checked' : '') : 'checked'; ?>>
                    <label class="form-check-label" for="ia">فعال</label>
                </div>
                <hr>
                <h6>📝 تنظیمات هزینه</h6>
                <div class="mb-2">
                    <label style="font-size:0.9rem;">هزینه ورودی (هر کاراکتر):</label>
                    <input type="number" name="cost_per_input_char" class="form-control form-control-sm" step="0.000001" min="0"
                           value="<?php echo $editMode ? ($editModel['cost_per_input_char'] ?? '0.000001') : '0.000001'; ?>">
                </div>
                <div class="mb-2">
                    <label style="font-size:0.9rem;">هزینه خروجی (هر کاراکتر):</label>
                    <input type="number" name="cost_per_output_char" class="form-control form-control-sm" step="0.000001" min="0"
                           value="<?php echo $editMode ? ($editModel['cost_per_output_char'] ?? '0.000002') : '0.000002'; ?>">
                </div>
                <div class="mb-2 form-check">
                    <input type="checkbox" name="free_model" class="form-check-input" id="fm" value="1"
                           <?php echo $editMode ? (($editModel['free_model'] ?? 0) ? 'checked' : '') : ''; ?>>
                    <label class="form-check-label" for="fm">🆓 رایگان</label>
                </div>
                <div class="mb-2">
                    <label style="font-size:0.9rem;">فرمت‌های پشتیبانی شده (با کاما جدا):</label>
                    <input type="text" name="supported_formats" class="form-control form-control-sm"
                           value="<?php echo $editMode ? htmlspecialchars($editModel['supported_formats'] ?? 'txt,doc,pdf,jpg,jpeg,png,gif,webp') : 'txt,doc,pdf,jpg,jpeg,png,gif,webp'; ?>"
                           placeholder="مثلاً: txt,doc,pdf,jpg,png">
                    <div class="form-text">فرمت‌هایی که این مدل پشتیبانی می‌کند. با کاما جدا کنید.</div>
                </div>
                <div class="mb-2">
                    <label style="font-size:0.9rem;">ترتیب نمایش:</label>
                    <input type="number" name="sort_order" class="form-control form-control-sm" min="0" step="1"
                           value="<?php echo $editMode ? (int)($editModel['sort_order'] ?? 0) : '0'; ?>"
                           placeholder="عدد کوچکتر = نمایش زودتر">
                    <div class="form-text">مدل‌ها بر اساس این عدد مرتب می‌شوند (صعودی).</div>
                </div>
                <button type="submit" class="btn btn-primary mt-3"><?php echo $editMode ? 'بروزرسانی' : 'ذخیره'; ?></button>
                <?php if ($editMode): ?><a href="modelstext.php" class="btn btn-secondary mt-3">انصراف</a><?php endif; ?>
            </form>
        </div>
    </div>
    <div class="col-md-7">
        <div class="table-container">
            <h5>📋 لیست مدل‌های متنی (<?php echo count($models); ?>)</h5>
            <table class="table table-hover align-middle">
                <thead><tr><th>ID</th><th>نام نمایشی</th><th>نام مدل</th><th>ورودی</th><th>خروجی</th><th>رایگان</th><th>وضعیت</th><th>عملیات</th></tr></thead>
                <tbody>
                    <?php if (empty($models)): ?>
                        <tr><td colspan="8" class="text-center text-muted">هیچ مدلی یافت نشد.</td></tr>
                    <?php else: foreach ($models as $m): ?>
                        <tr>
                            <td><?php echo $m['id']; ?></td>
                            <td><strong><?php echo htmlspecialchars($m['display_name'] ?? $m['name'] ?? '—'); ?></strong></td>
                            <td><code><?php echo htmlspecialchars($m['name']); ?></code></td>
                            <td><?php echo $m['cost_per_input_char'] ?? $m['cost'] ?? '—'; ?></td>
                            <td><?php echo $m['cost_per_output_char'] ?? '—'; ?></td>
                            <td><?php echo ($m['free_model'] ?? 0) ? '🆓' : '—'; ?></td>
                            <td><?php echo $m['is_active'] ? '<span class="badge-active">✅ فعال</span>' : '<span class="badge-inactive">❌ غیرفعال</span>'; ?></td>
                            <td>
                                <a href="modelstext.php?edit=<?php echo $m['id']; ?>" class="btn btn-sm btn-outline-primary">✏️</a>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('آیا مطمئن هستید؟');">
                                    <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?php echo $m['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-warning">🔄</button>
                                </form>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('آیا مطمئن هستید؟');">
                                    <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo $m['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">🗑️</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
            <a href="models.php" class="btn btn-sm btn-outline-secondary">🔙 بازگشت به لیست اصلی</a>
        </div>
    </div>
</div>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../../views/admin/layout.php';