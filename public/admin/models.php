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
        // Build model_config JSON from individual fields
        $modelConfig = [];
        if (!empty($_POST['mc_model_name']) || !empty($_POST['mc_model_model'])) {
            $modelConfig['metisai'] = [
                'model_name'     => trim($_POST['mc_model_name'] ?? 'openai'),
                'model_model'    => trim($_POST['mc_model_model'] ?? ''),
                'image_param'    => trim($_POST['mc_image_param'] ?? 'image'),
                'supports_image' => isset($_POST['mc_supports_image']) ? true : false,
                'supports_mask'  => isset($_POST['mc_supports_mask']) ? true : false,
                'size'           => trim($_POST['mc_size'] ?? 'auto'),
                'quality'        => trim($_POST['mc_quality'] ?? 'medium'),
                'output_format'  => trim($_POST['mc_output_format'] ?? 'png'),
            ];
        }

        $action = $_POST['action'] ?? '';
        if ($action === 'create') {
            $modelManager->createModel([
                'name'          => trim($_POST['name']),
                'provider'      => trim($_POST['provider']),
                'cost_per_image'=> (int) $_POST['cost_per_image'],
                'is_active'     => isset($_POST['is_active']) ? 1 : 0,
                'model_config'  => json_encode($modelConfig, JSON_UNESCAPED_UNICODE),
            ]);
            $message = '✅ مدل جدید با موفقیت اضافه شد.';
        } elseif ($action === 'update' && isset($_POST['id'])) {
            $modelManager->updateModel((int) $_POST['id'], [
                'name'          => trim($_POST['name']),
                'provider'      => trim($_POST['provider']),
                'cost_per_image'=> (int) $_POST['cost_per_image'],
                'is_active'     => isset($_POST['is_active']) ? 1 : 0,
                'model_config'  => json_encode($modelConfig, JSON_UNESCAPED_UNICODE),
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

// Decode model_config for edit mode
$editConfig = [];
if ($editMode && !empty($editModel['model_config'])) {
    $raw = is_string($editModel['model_config']) ? json_decode($editModel['model_config'], true) : $editModel['model_config'];
    if (is_array($raw)) {
        $editConfig = $raw['metisai'] ?? [];
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
                           placeholder="مثلاً: gpt-image-2">
                </div>

                <div class="mb-3">
                    <label class="form-label">ارائه‌دهنده (Provider):</label>
                    <select name="provider" class="form-select" id="providerSelect" onchange="toggleMetisConfig()">
                        <option value="gapgpt" <?php echo ($editMode && $editModel['provider'] === 'gapgpt') ? 'selected' : ''; ?>>GapGPT</option>
                        <option value="metisai" <?php echo ($editMode && $editModel['provider'] === 'metisai') ? 'selected' : ''; ?>>MetisAI</option>
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

                <hr>
                <h6 id="metisHeader" style="color: #0984e3;">⚙️ تنظیمات MetisAI API</h6>
                <div id="metisConfigSection">
                    <div class="mb-2">
                        <label class="form-label" style="font-size:0.9rem;">model_name (provider name):</label>
                        <input type="text" name="mc_model_name" class="form-control form-control-sm"
                               value="<?php echo htmlspecialchars($editConfig['model_name'] ?? 'openai'); ?>"
                               placeholder="openai">
                    </div>
                    <div class="mb-2">
                        <label class="form-label" style="font-size:0.9rem;">model_model (actual model):</label>
                        <input type="text" name="mc_model_model" class="form-control form-control-sm"
                               value="<?php echo htmlspecialchars($editConfig['model_model'] ?? ($editMode ? $editModel['name'] : '')); ?>"
                               placeholder="gpt-image-2">
                    </div>
                    <div class="mb-2">
                        <label class="form-label" style="font-size:0.9rem;">image_param (image / image_input):</label>
                        <input type="text" name="mc_image_param" class="form-control form-control-sm"
                               value="<?php echo htmlspecialchars($editConfig['image_param'] ?? 'image'); ?>"
                               placeholder="image">
                    </div>
                    <div class="mb-2">
                        <label class="form-label" style="font-size:0.9rem;">size (سایز - auto یا 1024x1024):</label>
                        <input type="text" name="mc_size" class="form-control form-control-sm"
                               value="<?php echo htmlspecialchars($editConfig['size'] ?? 'auto'); ?>"
                               placeholder="auto">
                    </div>
                    <div class="mb-2">
                        <label class="form-label" style="font-size:0.9rem;">quality (کیفیت):</label>
                        <input type="text" name="mc_quality" class="form-control form-control-sm"
                               value="<?php echo htmlspecialchars($editConfig['quality'] ?? 'medium'); ?>"
                               placeholder="medium">
                    </div>
                    <div class="mb-2">
                        <label class="form-label" style="font-size:0.9rem;">output_format (png / jpeg):</label>
                        <input type="text" name="mc_output_format" class="form-control form-control-sm"
                               value="<?php echo htmlspecialchars($editConfig['output_format'] ?? 'png'); ?>"
                               placeholder="png">
                    </div>
                    <div class="mb-2 form-check">
                        <input type="checkbox" name="mc_supports_image" class="form-check-input" id="mcSupportsImage" value="1"
                               <?php echo ($editConfig['supports_image'] ?? true) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="mcSupportsImage" style="font-size:0.9rem;">پشتیبانی از ویرایش تصویر (supports_image)</label>
                    </div>
                    <div class="mb-2 form-check">
                        <input type="checkbox" name="mc_supports_mask" class="form-check-input" id="mcSupportsMask" value="1"
                               <?php echo ($editConfig['supports_mask'] ?? false) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="mcSupportsMask" style="font-size:0.9rem;">پشتیبانی از ماسک (supports_mask)</label>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary mt-3">
                    <?php echo $editMode ? 'بروزرسانی مدل' : 'ذخیره مدل'; ?>
                </button>
                <?php if ($editMode): ?>
                    <a href="models.php" class="btn btn-secondary mt-3">انصراف</a>
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

<script>
function toggleMetisConfig() {
    var provider = document.getElementById('providerSelect').value;
    var section = document.getElementById('metisConfigSection');
    var header = document.getElementById('metisHeader');
    if (provider === 'metisai') {
        section.style.display = 'block';
        header.style.display = 'block';
    } else {
        section.style.display = 'none';
        header.style.display = 'none';
    }
}
toggleMetisConfig();
</script>

<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../../views/admin/layout.php';