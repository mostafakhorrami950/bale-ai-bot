<?php
require_once __DIR__ . '/../../init.php';

$pageTitle = 'مدیریت مدل‌های AI';
$activeMenu = 'models';

use Modules\Admin\ModelManager;
use Core\AILogger;

$modelManager = new ModelManager();
$message = '';
$messageType = 'success';
$editMode = false;
$editModel = null;

// ────────────────────────────────────────────────────────────
// Handle POST actions with full validation and atomic logging
// ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = trim($_POST['action'] ?? '');

        // Validate common fields
        $name = trim($_POST['name'] ?? '');
        if (empty($name)) {
            throw new \InvalidArgumentException('نام مدل الزامی است');
        }
        if (mb_strlen($name) > 200) {
            throw new \InvalidArgumentException('نام مدل حداکثر ۲۰۰ کاراکتر مجاز است');
        }

        $provider = trim($_POST['provider'] ?? 'gapgpt');
        $allowedProviders = ['gapgpt', 'openrouter', 'metisai', 'custom'];
        if (!in_array($provider, $allowedProviders, true)) {
            throw new \InvalidArgumentException('ارائه‌دهنده نامعتبر است');
        }

        $modelType = trim($_POST['model_type'] ?? 'image_generation');
        $allowedTypes = ['text', 'image_generation', 'image_editing', 'video'];
        if (!in_array($modelType, $allowedTypes, true)) {
            throw new \InvalidArgumentException('نوع مدل نامعتبر است');
        }

        // Cost validation — atomic: must be positive integer
        $rawCost = $_POST['cost_per_image'] ?? '';
        if ($rawCost === '' || !ctype_digit(ltrim((string)$rawCost, '-')) || (int)$rawCost < 1) {
            throw new \InvalidArgumentException('هزینه باید یک عدد صحیح مثبت باشد');
        }
        $costPerImage = (int)$rawCost;

        $modelConfig = [];

        if ($provider === 'metisai') {
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
        } elseif ($provider === 'openrouter') {
            $modelConfig['openrouter'] = [
                'aspect_ratio' => trim($_POST['or_aspect_ratio'] ?? '1:1'),
                'image_size'   => trim($_POST['or_image_size'] ?? '1K'),
            ];
        }

        $baseData = [
            'name'                 => $name,
            'provider'             => $provider,
            'model_type'           => $modelType,
            'cost_per_image'       => $costPerImage,
            'is_active'            => isset($_POST['is_active']) ? 1 : 0,
            'cost_per_input_char'  => (float) ($_POST['cost_per_input_char'] ?? 0.000001),
            'cost_per_output_char' => (float) ($_POST['cost_per_output_char'] ?? 0.000002),
            'free_model'           => isset($_POST['free_model']) ? 1 : 0,
            'model_config'         => json_encode($modelConfig, JSON_UNESCAPED_UNICODE),
        ];

        AILogger::log('MODEL_FORM_SUBMIT', [
            'action'    => $action,
            'name'      => $name,
            'type'      => $modelType,
            'cost'      => $costPerImage,
            'provider'  => $provider,
        ]);

        if ($action === 'create') {
            $modelManager->createModel($baseData);
            $message = '✅ مدل جدید با موفقیت اضافه شد (هزینه: ' . number_format($costPerImage) . ' اعتبار).';
            AILogger::log('MODEL_CREATED_OK', ['name' => $name, 'cost' => $costPerImage]);
        } elseif ($action === 'update' && isset($_POST['id'])) {
            $modelId = (int) $_POST['id'];
            $modelManager->updateModel($modelId, $baseData);
            $message = '✅ مدل با موفقیت بروزرسانی شد (هزینه: ' . number_format($costPerImage) . ' اعتبار).';
            AILogger::log('MODEL_UPDATED_OK', ['id' => $modelId, 'name' => $name, 'cost' => $costPerImage]);
        } elseif ($action === 'toggle' && isset($_POST['id'])) {
            $modelManager->toggleModel((int) $_POST['id']);
            $message = '✅ وضعیت مدل تغییر کرد.';
            AILogger::log('MODEL_TOGGLED', ['id' => (int)$_POST['id']]);
        } elseif ($action === 'delete' && isset($_POST['id'])) {
            $modelManager->deleteModel((int) $_POST['id']);
            $message = '✅ مدل حذف شد.';
            AILogger::log('MODEL_DELETED', ['id' => (int)$_POST['id']]);
        } else {
            throw new \InvalidArgumentException('عملیات نامعتبر');
        }
    } catch (\InvalidArgumentException $e) {
        $message = '❌ ' . $e->getMessage();
        $messageType = 'danger';
        AILogger::log('MODEL_VALIDATION_ERROR', ['error' => $e->getMessage(), 'post' => $_POST]);
    } catch (\Throwable $e) {
        $message = '❌ خطا: ' . $e->getMessage();
        $messageType = 'danger';
        AILogger::log('MODEL_SYSTEM_ERROR', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    }
}

// Edit mode (GET)
if (isset($_GET['edit'])) {
    $editModel = $modelManager->getById((int) $_GET['edit']);
    if ($editModel) $editMode = true;
}

// Decode model_config
$editMetisConfig = [];
$editOrConfig    = [];
if ($editMode && !empty($editModel['model_config'])) {
    $raw = is_string($editModel['model_config']) ? json_decode($editModel['model_config'], true) : $editModel['model_config'];
    if (is_array($raw)) {
        $editMetisConfig = $raw['metisai'] ?? [];
        $editOrConfig    = $raw['openrouter'] ?? [];
    }
}

$editModelType = $editMode ? ($editModel['model_type'] ?? 'image_generation') : 'image_generation';
$editProvider = $editMode ? ($editModel['provider'] ?? 'gapgpt') : 'gapgpt';

$models = $modelManager->getAllModels();

ob_start();
?>
<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show">
        <?php echo $message; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<style>
.model-type-section { display: none; padding: 10px 0; }
.model-type-section.active { display: block; }
</style>

<div class="row">
    <div class="col-md-5">
        <div class="table-container">
            <h5><?php echo $editMode ? '✏️ ویرایش مدل' : '➕ افزودن مدل جدید'; ?></h5>
            <form method="POST" id="modelForm">
                <input type="hidden" name="action" value="<?php echo $editMode ? 'update' : 'create'; ?>">
                <?php if ($editMode): ?>
                    <input type="hidden" name="id" value="<?php echo $editModel['id']; ?>">
                <?php endif; ?>

                <div class="mb-3">
                    <label class="form-label">نام مدل:</label>
                    <input type="text" name="name" class="form-control" required maxlength="200"
                           value="<?php echo $editMode ? htmlspecialchars($editModel['name']) : ''; ?>"
                           placeholder="مثلاً: google/gemini-2.5-flash-image">
                </div>

                <div class="mb-3">
                    <label class="form-label">ارائه‌دهنده (Provider):</label>
                    <select name="provider" class="form-select" id="providerSelect" onchange="toggleConfigSections()">
                        <option value="gapgpt" <?php echo $editProvider === 'gapgpt' ? 'selected' : ''; ?>>GapGPT</option>
                        <option value="openrouter" <?php echo $editProvider === 'openrouter' ? 'selected' : ''; ?>>OpenRouter</option>
                        <option value="metisai" <?php echo $editProvider === 'metisai' ? 'selected' : ''; ?>>MetisAI</option>
                        <option value="custom" <?php echo $editProvider === 'custom' ? 'selected' : ''; ?>>Custom</option>
                    </select>
                    <div class="form-text text-muted" style="font-size:0.8rem;">Provider و نوع مدل مستقل هستند.</div>
                </div>

                <!-- Model Type (Independent of Provider) -->
                <div class="mb-3">
                    <label class="form-label">نوع مدل:</label>
                    <select name="model_type" class="form-select" id="modelTypeSelect" onchange="toggleModelTypeSections()">
                        <option value="text" <?php echo $editModelType === 'text' ? 'selected' : ''; ?>>📝 متنی</option>
                        <option value="image_generation" <?php echo $editModelType === 'image_generation' ? 'selected' : ''; ?>>🎨 ساخت تصویر</option>
                        <option value="image_editing" <?php echo $editModelType === 'image_editing' ? 'selected' : ''; ?>>🖼 ویرایش تصویر</option>
                        <option value="video" <?php echo $editModelType === 'video' ? 'selected' : ''; ?>>🎬 ویدئو</option>
                    </select>
                </div>

                <!-- Common: Active -->
                <div class="mb-3 form-check">
                    <input type="checkbox" name="is_active" class="form-check-input" id="modelActive"
                           <?php echo $editMode ? ($editModel['is_active'] ? 'checked' : '') : 'checked'; ?>>
                    <label class="form-check-label" for="modelActive">فعال</label>
                </div>

                <!-- Text Model -->
                <div class="model-type-section <?php echo $editModelType === 'text' ? 'active' : ''; ?>" id="section_text">
                    <hr>
                    <h6>📝 تنظیمات مدل متنی</h6>
                    <p class="text-muted" style="font-size:0.85rem;">هزینه بر اساس کاراکتر</p>
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
                        <input type="checkbox" name="free_model" class="form-check-input" id="modelFree" value="1"
                               <?php echo $editMode ? (($editModel['free_model'] ?? 0) ? 'checked' : '') : ''; ?>>
                        <label class="form-check-label" for="modelFree">🆓 رایگان</label>
                    </div>
                </div>

                <!-- Image Generation -->
                <div class="model-type-section <?php echo $editModelType === 'image_generation' ? 'active' : ''; ?>" id="section_image_generation">
                    <hr>
                    <h6>🎨 تنظیمات ساخت تصویر</h6>
                    <div class="mb-3">
                        <label class="form-label">هزینه هر تصویر (اعتبار):</label>
                        <input type="number" name="cost_per_image" class="form-control" min="1" required
                               value="<?php echo $editMode ? ($editModel['cost_per_image'] ?? 2) : '2'; ?>"
                               oninvalid="this.setCustomValidity('هزینه باید یک عدد صحیح مثبت باشد')"
                               oninput="this.setCustomValidity('')">
                        <div class="form-text text-muted">عدد صحیح مثبت وارد کنید (مثلاً: ۱۵)</div>
                    </div>
                </div>

                <!-- Image Editing -->
                <div class="model-type-section <?php echo $editModelType === 'image_editing' ? 'active' : ''; ?>" id="section_image_editing">
                    <hr>
                    <h6>🖼 تنظیمات ویرایش تصویر</h6>
                    <div class="mb-3">
                        <label class="form-label">هزینه هر ویرایش (اعتبار):</label>
                        <input type="number" name="cost_per_image" class="form-control" min="1" required
                               value="<?php echo $editMode ? ($editModel['cost_per_image'] ?? 2) : '2'; ?>"
                               oninvalid="this.setCustomValidity('هزینه باید یک عدد صحیح مثبت باشد')"
                               oninput="this.setCustomValidity('')">
                        <div class="form-text text-muted">عدد صحیح مثبت وارد کنید (مثلاً: ۱۵)</div>
                    </div>
                </div>

                <!-- Video -->
                <div class="model-type-section <?php echo $editModelType === 'video' ? 'active' : ''; ?>" id="section_video">
                    <hr>
                    <h6>🎬 تنظیمات ویدئو</h6>
                    <div class="mb-3">
                        <label class="form-label">هزینه هر ویدئو (اعتبار):</label>
                        <input type="number" name="cost_per_image" class="form-control" min="1" required
                               value="<?php echo $editMode ? ($editModel['cost_per_image'] ?? 5) : '5'; ?>"
                               oninvalid="this.setCustomValidity('هزینه باید یک عدد صحیح مثبت باشد')"
                               oninput="this.setCustomValidity('')">
                        <div class="form-text text-muted">عدد صحیح مثبت وارد کنید (مثلاً: ۱۵)</div>
                    </div>
                </div>

                <!-- MetisAI Config -->
                <div id="metisConfigSection" style="display:none;">
                    <hr>
                    <h6 style="color: #0984e3;">⚙️ MetisAI</h6>
                    <div class="mb-2">
                        <label style="font-size:0.9rem;">model_name:</label>
                        <input type="text" name="mc_model_name" class="form-control form-control-sm"
                               value="<?php echo htmlspecialchars($editMetisConfig['model_name'] ?? 'openai'); ?>">
                    </div>
                    <div class="mb-2">
                        <label style="font-size:0.9rem;">model_model:</label>
                        <input type="text" name="mc_model_model" class="form-control form-control-sm"
                               value="<?php echo htmlspecialchars($editMetisConfig['model_model'] ?? ($editMode ? $editModel['name'] : '')); ?>">
                    </div>
                    <div class="mb-2">
                        <label style="font-size:0.9rem;">image_param:</label>
                        <input type="text" name="mc_image_param" class="form-control form-control-sm"
                               value="<?php echo htmlspecialchars($editMetisConfig['image_param'] ?? 'image'); ?>">
                    </div>
                    <div class="mb-2">
                        <label style="font-size:0.9rem;">size:</label>
                        <input type="text" name="mc_size" class="form-control form-control-sm"
                               value="<?php echo htmlspecialchars($editMetisConfig['size'] ?? 'auto'); ?>">
                    </div>
                    <div class="mb-2">
                        <label style="font-size:0.9rem;">quality:</label>
                        <input type="text" name="mc_quality" class="form-control form-control-sm"
                               value="<?php echo htmlspecialchars($editMetisConfig['quality'] ?? 'medium'); ?>">
                    </div>
                    <div class="mb-2">
                        <label style="font-size:0.9rem;">output_format:</label>
                        <input type="text" name="mc_output_format" class="form-control form-control-sm"
                               value="<?php echo htmlspecialchars($editMetisConfig['output_format'] ?? 'png'); ?>">
                    </div>
                    <div class="mb-2 form-check">
                        <input type="checkbox" name="mc_supports_image" class="form-check-input" value="1"
                               <?php echo ($editMetisConfig['supports_image'] ?? true) ? 'checked' : ''; ?>>
                        <label class="form-check-label" style="font-size:0.9rem;">پشتیبانی از ویرایش تصویر</label>
                    </div>
                    <div class="mb-2 form-check">
                        <input type="checkbox" name="mc_supports_mask" class="form-check-input" value="1"
                               <?php echo ($editMetisConfig['supports_mask'] ?? false) ? 'checked' : ''; ?>>
                        <label class="form-check-label" style="font-size:0.9rem;">پشتیبانی از ماسک</label>
                    </div>
                </div>

                <!-- OpenRouter Config -->
                <div id="orConfigSection" style="display:none;">
                    <hr>
                    <h6 style="color: #e17055;">🔗 OpenRouter</h6>
                    <div class="mb-2">
                        <label style="font-size:0.9rem;">aspect_ratio:</label>
                        <select name="or_aspect_ratio" class="form-select form-select-sm">
                            <option value="1:1" <?php echo ($editOrConfig['aspect_ratio'] ?? '1:1') === '1:1' ? 'selected' : ''; ?>>1:1</option>
                            <option value="16:9" <?php echo ($editOrConfig['aspect_ratio'] ?? '') === '16:9' ? 'selected' : ''; ?>>16:9</option>
                            <option value="9:16" <?php echo ($editOrConfig['aspect_ratio'] ?? '') === '9:16' ? 'selected' : ''; ?>>9:16</option>
                            <option value="4:3" <?php echo ($editOrConfig['aspect_ratio'] ?? '') === '4:3' ? 'selected' : ''; ?>>4:3</option>
                            <option value="3:4" <?php echo ($editOrConfig['aspect_ratio'] ?? '') === '3:4' ? 'selected' : ''; ?>>3:4</option>
                            <option value="2:3" <?php echo ($editOrConfig['aspect_ratio'] ?? '') === '2:3' ? 'selected' : ''; ?>>2:3</option>
                            <option value="3:2" <?php echo ($editOrConfig['aspect_ratio'] ?? '') === '3:2' ? 'selected' : ''; ?>>3:2</option>
                            <option value="4:5" <?php echo ($editOrConfig['aspect_ratio'] ?? '') === '4:5' ? 'selected' : ''; ?>>4:5</option>
                            <option value="5:4" <?php echo ($editOrConfig['aspect_ratio'] ?? '') === '5:4' ? 'selected' : ''; ?>>5:4</option>
                            <option value="21:9" <?php echo ($editOrConfig['aspect_ratio'] ?? '') === '21:9' ? 'selected' : ''; ?>>21:9</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label style="font-size:0.9rem;">image_size:</label>
                        <select name="or_image_size" class="form-select form-select-sm">
                            <option value="auto" <?php echo ($editOrConfig['image_size'] ?? 'auto') === 'auto' ? 'selected' : ''; ?>>auto (پیشنهادی)</option>
                            <option value="1K" <?php echo ($editOrConfig['image_size'] ?? '') === '1K' ? 'selected' : ''; ?>>1K</option>
                            <option value="2K" <?php echo ($editOrConfig['image_size'] ?? '') === '2K' ? 'selected' : ''; ?>>2K</option>
                            <option value="4K" <?php echo ($editOrConfig['image_size'] ?? '') === '4K' ? 'selected' : ''; ?>>4K</option>
                        </select>
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
            <h5>📋 لیست مدل‌ها (<?php echo count($models); ?>)</h5>
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>نام</th>
                        <th>ارائه‌دهنده</th>
                        <th>نوع</th>
                        <th>هزینه (اعتبار)</th>
                        <th>وضعیت</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($models)): ?>
                        <tr><td colspan="7" class="text-center text-muted">هیچ مدلی یافت نشد.</td></tr>
                    <?php else: ?>
                        <?php foreach ($models as $m): ?>
                        <?php
                            $typeLabels = [
                                'text' => '📝 متنی',
                                'image_generation' => '🎨 تصویرساز',
                                'image_editing' => '🖼 ویرایش',
                                'video' => '🎬 ویدئو',
                            ];
                            $tl = $typeLabels[$m['model_type'] ?? 'image_generation'] ?? '🎨 تصویرساز';
                            // ModelManager returns 'cost' as alias, normalize to cost_per_image
                            $displayCost = $m['cost'] ?? $m['cost_per_image'] ?? 0;
                        ?>
                        <tr>
                            <td><?php echo $m['id']; ?></td>
                            <td><?php echo htmlspecialchars($m['name']); ?></td>
                            <td><code><?php echo htmlspecialchars($m['provider'] ?? 'gapgpt'); ?></code></td>
                            <td><span class="badge bg-secondary"><?php echo $tl; ?></span></td>
                            <td><?php echo number_format((int)$displayCost); ?></td>
                            <td>
                                <?php if ($m['is_active']): ?>
                                    <span class="badge-active">✅ فعال</span>
                                <?php else: ?>
                                    <span class="badge-inactive">❌ غیرفعال</span>
                                <?php endif; ?>
                            </td>
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
function toggleConfigSections() {
    var p = document.getElementById('providerSelect').value;
    document.getElementById('metisConfigSection').style.display = p === 'metisai' ? 'block' : 'none';
    document.getElementById('orConfigSection').style.display = p === 'openrouter' ? 'block' : 'none';
}
function toggleModelTypeSections() {
    var t = document.getElementById('modelTypeSelect').value;
    document.querySelectorAll('.model-type-section').forEach(function(s) { s.classList.remove('active'); });
    var el = document.getElementById('section_' + t);
    if (el) el.classList.add('active');
}
toggleConfigSections();
toggleModelTypeSections();
</script>

<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../../views/admin/layout.php';