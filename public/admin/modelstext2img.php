<?php
/**
 * Manage text-to-image models (ai_image_models).
 */
require_once __DIR__ . '/../../init.php';

$pageTitle = 'مدیریت مدل‌های ساخت تصویر';
$activeMenu = 'models';

use Modules\Admin\ModelManager;
use Admin\ModelHelper;

$modelType = 'image_generation';
$modelManager = new ModelManager();
$message = '';
$messageType = 'success';
$editMode = false;
$editModel = null;

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $data = ModelHelper::validateAndSanitize($_POST);
        $data['model_type'] = $modelType;

        $action = trim($_POST['action'] ?? '');

        ModelHelper::logAction('FORM_SUBMIT_TEXT2IMG', ['action' => $action, 'name' => $data['name'], 'cost' => $data['cost_per_image']]);

        if ($action === 'create') {
            $modelManager->createModel($data);
            $message = '✅ مدل ساخت تصویر با موفقیت اضافه شد (هزینه: ' . number_format($data['cost_per_image']) . ' اعتبار).';
        } elseif ($action === 'update' && isset($_POST['id'])) {
            $modelManager->updateModel((int)$_POST['id'], $data);
            $message = '✅ مدل ساخت تصویر با موفقیت بروزرسانی شد (هزینه: ' . number_format($data['cost_per_image']) . ' اعتبار).';
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
        $message = '❌ ' . $e->getMessage();
        $messageType = 'danger';
        ModelHelper::logAction('VALIDATION_ERROR', ['error' => $e->getMessage()]);
    } catch (\Throwable $e) {
        $message = '❌ خطا: ' . $e->getMessage();
        $messageType = 'danger';
        ModelHelper::logAction('SYSTEM_ERROR', ['error' => $e->getMessage()]);
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
            <h5><?php echo $editMode ? '✏️ ویرایش مدل ساخت تصویر' : '➕ افزودن مدل ساخت تصویر'; ?></h5>
            <form method="POST">
                <input type="hidden" name="action" value="<?php echo $editMode ? 'update' : 'create'; ?>">
                <input type="hidden" name="model_type" value="<?php echo $modelType; ?>">
                <?php if ($editMode): ?><input type="hidden" name="id" value="<?php echo $editModel['id']; ?>"><?php endif; ?>

                <div class="mb-3">
                    <label class="form-label">نام مدل (شناسه API):</label>
                    <input type="text" name="name" class="form-control" required maxlength="200"
                           value="<?php echo $editMode ? htmlspecialchars($editModel['name'] ?? '') : ''; ?>"
                           placeholder="مثلاً: google/gemini-3.1-flash-image-preview">
                </div>

                <div class="mb-3">
                    <label class="form-label">نام نمایشی (در ربات):</label>
                    <input type="text" name="display_name" class="form-control" maxlength="200"
                           value="<?php echo $editMode ? htmlspecialchars($editModel['display_name'] ?? $editModel['name'] ?? '') : ''; ?>"
                           placeholder="مثلاً: جمینای 3.1 فلش">
                </div>

                <div class="mb-3">
                    <label class="form-label">توضیحات (اختیاری):</label>
                    <textarea name="description" class="form-control" rows="2" maxlength="500"
                              placeholder="توضیح مختصر درباره مدل"><?php echo $editMode ? htmlspecialchars($editModel['description'] ?? '') : ''; ?></textarea>
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
                <h6>🎨 تنظیمات تصویر</h6>
                <div class="mb-3">
                    <label class="form-label">هزینه هر تصویر (اعتبار):</label>
                    <input type="number" name="cost_per_image" class="form-control" min="1" required
                           value="<?php echo $editMode ? ($editModel['cost_per_image'] ?? 2) : '2'; ?>">
                    <div class="form-text">عدد صحیح مثبت (مثلاً: ۱۰)</div>
                </div>

                <div class="mb-3">
                    <label class="form-label">سایز تصویر:</label>
                    <select name="size" class="form-select">
                        <?php foreach (ModelHelper::allowedSizes() as $s): ?>
                        <option value="<?php echo $s; ?>" <?php echo ($editMode ? ($editModel['size'] ?? 'auto') : 'auto') === $s ? 'selected' : ''; ?>><?php echo ModelHelper::sizeLabel($s); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label">نسبت تصویر:</label>
                    <select name="aspect_ratio" class="form-select">
                        <?php foreach (ModelHelper::allowedAspectRatios() as $ar): ?>
                        <option value="<?php echo $ar; ?>" <?php echo ($editMode ? ($editModel['aspect_ratio'] ?? 'auto') : 'auto') === $ar ? 'selected' : ''; ?>><?php echo ModelHelper::aspectRatioLabel($ar); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <hr>
                <h6>📝 تنظیمات هزینه کاراکتر (Fallback متنی)</h6>
                <p class="text-muted small">زمانی که مدل به جای تصویر، متن برمی‌گرداند، هزینه بر اساس کاراکتر محاسبه می‌شود.</p>
                <div class="mb-3">
                    <label class="form-label">هزینه هر کاراکتر ورودی (اعتبار):</label>
                    <input type="number" name="cost_per_input_char" class="form-control" step="0.0000001" min="0"
                           value="<?php echo $editMode ? ($editModel['cost_per_input_char'] ?? 0.000001) : '0.000001'; ?>">
                    <div class="form-text">مقدار اعشاری (مثلاً: 0.000001)</div>
                </div>
                <div class="mb-3">
                    <label class="form-label">هزینه هر کاراکتر خروجی (اعتبار):</label>
                    <input type="number" name="cost_per_output_char" class="form-control" step="0.0000001" min="0"
                           value="<?php echo $editMode ? ($editModel['cost_per_output_char'] ?? 0.000002) : '0.000002'; ?>">
                    <div class="form-text">مقدار اعشاری (مثلاً: 0.000002)</div>
                </div>

                <button type="submit" class="btn btn-primary mt-3"><?php echo $editMode ? 'بروزرسانی' : 'ذخیره'; ?></button>
                <?php if ($editMode): ?><a href="modelstext2img.php" class="btn btn-secondary mt-3">انصراف</a><?php endif; ?>
            </form>
        </div>
    </div>

    <div class="col-md-7">
        <div class="table-container">
            <h5>📋 لیست مدل‌های ساخت تصویر (<?php echo count($models); ?>)</h5>
            <table class="table table-hover align-middle">
                <thead><tr><th>ID</th><th>نام نمایشی</th><th>نام مدل</th><th>هزینه</th><th>هزینه/کاراکتر ورودی</th><th>هزینه/کاراکتر خروجی</th><th>سایز</th><th>نسبت</th><th>وضعیت</th><th>عملیات</th></tr></thead>
                <tbody>
                    <?php if (empty($models)): ?>
                        <tr><td colspan="10" class="text-center text-muted">هیچ مدلی یافت نشد.</td></tr>
                    <?php else: foreach ($models as $m): ?>
                        <tr>
                            <td><?php echo $m['id']; ?></td>
                            <td><strong><?php echo htmlspecialchars($m['display_name'] ?? $m['name'] ?? '—'); ?></strong></td>
                            <td><code><?php echo htmlspecialchars($m['name']); ?></code></td>
                            <td><?php echo $m['cost']; ?> اعتبار</td>
                            <td><?php echo number_format((float)($m['cost_per_input_char'] ?? 0), 8); ?></td>
                            <td><?php echo number_format((float)($m['cost_per_output_char'] ?? 0), 8); ?></td>
                            <td><?php echo $m['size'] ?? 'auto'; ?></td>
                            <td><?php echo $m['aspect_ratio'] ?? 'auto'; ?></td>
                            <td><?php echo $m['is_active'] ? '<span class="badge-active">✅ فعال</span>' : '<span class="badge-inactive">❌ غیرفعال</span>'; ?></td>
                            <td>
                                <a href="modelstext2img.php?edit=<?php echo $m['id']; ?>" class="btn btn-sm btn-outline-primary">✏️</a>
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