<?php
/**
 * Manage video models (ai_video_models) with full capability support.
 * Pricing: cost_per_second only (no base cost_per_video).
 */
require_once __DIR__ . '/../../init.php';

$pageTitle = 'مدیریت مدل‌های ویدئو';
$activeMenu = 'models';

use Modules\Admin\ModelManager;
use Admin\ModelHelper;

$modelType = 'video';
$modelManager = new ModelManager();
$message = '';
$messageType = 'success';
$editMode = false;
$editModel = null;

function parseCsvToArray(?string $csv): array {
    if (empty($csv)) return [];
    $items = explode(',', $csv);
    return array_filter(array_map('trim', $items));
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = trim($_POST['action'] ?? '');

        $data = [
            'name' => trim($_POST['name'] ?? ''),
            'display_name' => trim($_POST['display_name'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'provider' => trim($_POST['provider'] ?? 'openrouter'),
            'cost_per_second' => (int) ($_POST['cost_per_second'] ?? 1),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ];

        if (empty($data['name'])) throw new \InvalidArgumentException('نام مدل الزامی است.');
        if ($data['cost_per_second'] < 1) throw new \InvalidArgumentException('هزینه هر ثانیه باید حداقل 1 باشد.');

        $data['supported_resolutions'] = trim($_POST['supported_resolutions'] ?? '480p,720p,1080p');
        $data['supported_sizes'] = trim($_POST['supported_sizes'] ?? '854x480,1280x720,1920x1080');
        $data['supported_aspect_ratios'] = trim($_POST['supported_aspect_ratios'] ?? '16:9,9:16,1:1');

        $durationsArr = [];
        for ($i = 3; $i <= 30; $i++) {
            if (isset($_POST['dur_' . $i])) {
                $durationsArr[] = $i;
            }
        }
        $data['supported_durations'] = !empty($durationsArr) ? implode(',', $durationsArr) : '5,10,15';

        $data['allow_first_frame'] = isset($_POST['allow_first_frame']) ? 1 : 0;
        $data['allow_last_frame'] = isset($_POST['allow_last_frame']) ? 1 : 0;
        $data['allow_input_references'] = isset($_POST['allow_input_references']) ? 1 : 0;
        $data['allow_generate_audio'] = isset($_POST['allow_generate_audio']) ? 1 : 0;
        $data['allow_img2video'] = isset($_POST['allow_img2video']) ? 1 : 0;

        $pricingJson = [];
        $resolutions = parseCsvToArray($data['supported_resolutions']);
        foreach ($resolutions as $res) {
            $priceKey = 'price_' . str_replace(['.', ':'], '_', $res);
            $priceVal = (int) ($_POST[$priceKey] ?? 0);
            if ($priceVal > 0) {
                $pricingJson[$res] = $priceVal;
            }
        }
        $data['pricing_json'] = !empty($pricingJson) ? json_encode($pricingJson, JSON_UNESCAPED_UNICODE) : '{}';

        $db = \Database\Database::getInstance();

        if ($action === 'create') {
            $db->query("INSERT INTO ai_video_models (name, display_name, description, provider, cost_per_second, is_active,
                supported_resolutions, supported_sizes, supported_aspect_ratios, supported_durations,
                allow_first_frame, allow_last_frame, allow_input_references, allow_generate_audio, allow_img2video, pricing_json)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
                $data['name'], $data['display_name'], $data['description'], $data['provider'],
                $data['cost_per_second'], $data['is_active'],
                $data['supported_resolutions'], $data['supported_sizes'], $data['supported_aspect_ratios'],
                $data['supported_durations'],
                $data['allow_first_frame'], $data['allow_last_frame'], $data['allow_input_references'],
                $data['allow_generate_audio'], $data['allow_img2video'], $data['pricing_json']
            ]);
            $message = '✅ مدل ویدئو اضافه شد (هزینه: ' . number_format($data['cost_per_second']) . ' اعتبار/ثانیه).';
        } elseif ($action === 'update' && isset($_POST['id'])) {
            $id = (int) $_POST['id'];
            $db->query("UPDATE ai_video_models SET name=?, display_name=?, description=?, provider=?, cost_per_second=?, is_active=?,
                supported_resolutions=?, supported_sizes=?, supported_aspect_ratios=?, supported_durations=?,
                allow_first_frame=?, allow_last_frame=?, allow_input_references=?, allow_generate_audio=?, allow_img2video=?, pricing_json=?
                WHERE id=?", [
                $data['name'], $data['display_name'], $data['description'], $data['provider'],
                $data['cost_per_second'], $data['is_active'],
                $data['supported_resolutions'], $data['supported_sizes'], $data['supported_aspect_ratios'],
                $data['supported_durations'],
                $data['allow_first_frame'], $data['allow_last_frame'], $data['allow_input_references'],
                $data['allow_generate_audio'], $data['allow_img2video'], $data['pricing_json'], $id
            ]);
            $message = '✅ مدل ویدئو بروزرسانی شد (هزینه: ' . number_format($data['cost_per_second']) . ' اعتبار/ثانیه).';
        } elseif ($action === 'toggle' && isset($_POST['id'])) {
            $id = (int) $_POST['id'];
            $row = $db->query("SELECT is_active FROM ai_video_models WHERE id=?", [$id])->fetch();
            $newActive = $row ? (1 - (int)$row['is_active']) : 1;
            $db->query("UPDATE ai_video_models SET is_active=? WHERE id=?", [$newActive, $id]);
            $message = '✅ وضعیت مدل تغییر کرد.';
        } elseif ($action === 'delete' && isset($_POST['id'])) {
            $id = (int) $_POST['id'];
            $db->query("DELETE FROM ai_video_models WHERE id=?", [$id]);
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

// Edit mode
if (isset($_GET['edit'])) {
    $db = \Database\Database::getInstance();
    $row = $db->query("SELECT * FROM ai_video_models WHERE id=?", [(int)$_GET['edit']])->fetch();
    if ($row) {
        $editModel = $row;
        $editMode = true;
    }
}

// List all video models
$db = \Database\Database::getInstance();
$models = $db->query("SELECT * FROM ai_video_models ORDER BY id ASC")->fetchAll();

ob_start();
?>
<style>
.duration-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(60px, 1fr)); gap: 4px; }
.duration-grid label { display: flex; align-items: center; gap: 4px; font-size: 0.85rem; }
.cap-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 8px; }
</style>

<?php if ($message): ?>
<div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show">
    <?php echo $message; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row">
    <div class="col-md-6">
        <div class="table-container">
            <h5><?php echo $editMode ? '✏️ ویرایش مدل ویدئو' : '➕ افزودن مدل ویدئو'; ?></h5>
            <form method="POST">
                <input type="hidden" name="action" value="<?php echo $editMode ? 'update' : 'create'; ?>">
                <?php if ($editMode): ?>
                <input type="hidden" name="id" value="<?php echo $editModel['id']; ?>">
                <?php endif; ?>

                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">نام مدل (شناسه API):</label>
                        <input type="text" name="name" class="form-control" required maxlength="200"
                               value="<?php echo $editMode ? htmlspecialchars($editModel['name'] ?? '') : ''; ?>"
                               placeholder="مثلاً: google/veo-3.1">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">نام نمایشی:</label>
                        <input type="text" name="display_name" class="form-control" maxlength="200"
                               value="<?php echo $editMode ? htmlspecialchars($editModel['display_name'] ?? $editModel['name'] ?? '') : ''; ?>"
                               placeholder="مثلاً: گوگل ویو 3.1">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">توضیحات:</label>
                    <textarea name="description" class="form-control" rows="2" maxlength="500"><?php echo $editMode ? htmlspecialchars($editModel['description'] ?? '') : ''; ?></textarea>
                </div>

                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label">ارائه‌دهنده:</label>
                        <select name="provider" class="form-select">
                            <option value="openrouter" <?php echo ($editMode ? $editModel['provider'] : 'openrouter') === 'openrouter' ? 'selected' : ''; ?>>OpenRouter</option>
                            <option value="custom" <?php echo ($editMode ? $editModel['provider'] : '') === 'custom' ? 'selected' : ''; ?>>Custom</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">💰 هزینه هر ثانیه (اعتبار):</label>
                        <input type="number" name="cost_per_second" class="form-control" min="1" required
                               value="<?php echo $editMode ? ($editModel['cost_per_second'] ?? 1) : '1'; ?>">
                        <small class="text-muted">قیمت نهایی = مدت (ثانیه) × هزینه هر ثانیه</small>
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <div class="form-check">
                            <input type="checkbox" name="is_active" class="form-check-input" id="ia"
                                   <?php echo $editMode ? ($editModel['is_active'] ? 'checked' : '') : 'checked'; ?>>
                            <label class="form-check-label" for="ia">فعال</label>
                        </div>
                    </div>
                </div>

                <hr>
                <h6>📐 رزولوشن‌های پشتیبانی شده</h6>
                <div class="mb-3">
                    <label class="form-label">رزولوشن‌ها (با کاما جدا کنید):</label>
                    <input type="text" name="supported_resolutions" class="form-control"
                           value="<?php echo $editMode ? htmlspecialchars($editModel['supported_resolutions'] ?? '480p,720p,1080p') : '480p,720p,1080p'; ?>"
                           placeholder="مثلاً: 480p,720p,1080p" id="resInput">
                </div>

                <?php
                $editPricing = [];
                if ($editMode && !empty($editModel['pricing_json'])) {
                    $editPricing = json_decode($editModel['pricing_json'], true) ?: [];
                }
                $editResolutions = parseCsvToArray($editMode ? $editModel['supported_resolutions'] ?? '' : '');
                ?>
                <div class="mb-3" id="pricingSection">
                    <label class="form-label">قیمت جداگانه هر رزولوشن (صفر = استفاده از هزینه هر ثانیه):</label>
                    <div class="row">
                    <?php if (!empty($editResolutions)): ?>
                        <?php foreach ($editResolutions as $res): ?>
                        <div class="col-md-4 mb-2">
                            <label class="form-label small"><?php echo htmlspecialchars($res); ?>:</label>
                            <input type="number" name="<?php echo 'price_' . str_replace(['.', ':'], '_', $res); ?>" class="form-control" min="0"
                                   value="<?php echo $editPricing[$res] ?? 0; ?>">
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="col-12 text-muted small">ابتدا رزولوشن‌ها را وارد کنید و سپس ذخیره کنید.</div>
                    <?php endif; ?>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">سایزهای پشتیبانی شده (با کاما جدا کنید):</label>
                    <input type="text" name="supported_sizes" class="form-control"
                           value="<?php echo $editMode ? htmlspecialchars($editModel['supported_sizes'] ?? '854x480,1280x720,1920x1080') : '854x480,1280x720,1920x1080'; ?>"
                           placeholder="مثلاً: 854x480,1280x720,1920x1080">
                </div>

                <div class="mb-3">
                    <label class="form-label">نسبت‌های تصویر پشتیبانی شده (با کاما جدا کنید):</label>
                    <input type="text" name="supported_aspect_ratios" class="form-control"
                           value="<?php echo $editMode ? htmlspecialchars($editModel['supported_aspect_ratios'] ?? '16:9,9:16,1:1') : '16:9,9:16,1:1'; ?>"
                           placeholder="مثلاً: 16:9,9:16,1:1,4:3,21:9">
                </div>

                <hr>
                <h6>⏱️ مدت‌زمان‌های مجاز (3 تا 30 ثانیه)</h6>
                <p class="text-muted small">گزینه‌های مورد نظر را انتخاب کنید:</p>
                <div class="duration-grid mb-3">
                    <?php
                    $editDurations = [];
                    if ($editMode && !empty($editModel['supported_durations'])) {
                        $editDurations = array_map('intval', parseCsvToArray($editModel['supported_durations']));
                    }
                    for ($i = 3; $i <= 30; $i++):
                        $checked = in_array($i, $editDurations) ? 'checked' : '';
                    ?>
                    <label>
                        <input type="checkbox" name="dur_<?php echo $i; ?>" value="1" <?php echo $checked; ?>>
                        <?php echo $i; ?>s
                    </label>
                    <?php endfor; ?>
                </div>

                <hr>
                <h6>🔧 قابلیت‌های اضافی</h6>
                <div class="cap-grid mb-3">
                    <div class="form-check">
                        <input type="checkbox" name="allow_first_frame" class="form-check-input" id="aff"
                               <?php echo $editMode ? ($editModel['allow_first_frame'] ? 'checked' : '') : ''; ?>>
                        <label class="form-check-label" for="aff">first_frame (فریم اول)</label>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" name="allow_last_frame" class="form-check-input" id="alf"
                               <?php echo $editMode ? ($editModel['allow_last_frame'] ? 'checked' : '') : ''; ?>>
                        <label class="form-check-label" for="alf">last_frame (فریم آخر)</label>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" name="allow_input_references" class="form-check-input" id="air"
                               <?php echo $editMode ? ($editModel['allow_input_references'] ? 'checked' : '') : ''; ?>>
                        <label class="form-check-label" for="air">input_references (مرجع تصویری)</label>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" name="allow_generate_audio" class="form-check-input" id="aga"
                               <?php echo $editMode ? ($editModel['allow_generate_audio'] ? 'checked' : 'checked') : 'checked'; ?>>
                        <label class="form-check-label" for="aga">generate_audio (تولید صدا)</label>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" name="allow_img2video" class="form-check-input" id="aiv"
                               <?php echo $editMode ? ($editModel['allow_img2video'] ? 'checked' : '') : ''; ?>>
                        <label class="form-check-label" for="aiv">img2video (تصویر به ویدئو)</label>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary mt-3"><?php echo $editMode ? 'بروزرسانی' : 'ذخیره'; ?></button>
                <?php if ($editMode): ?><a href="modelsvideo.php" class="btn btn-secondary mt-3">انصراف</a><?php endif; ?>
            </form>
        </div>
    </div>
    <div class="col-md-6">
        <div class="table-container">
            <h5>📋 لیست مدل‌های ویدئو (<?php echo count($models); ?>)</h5>
            <div class="table-responsive">
                <table class="table table-hover align-middle table-sm">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>نام</th>
                            <th>💰/ثانیه</th>
                            <th>رزولوشن</th>
                            <th>مدت‌ها</th>
                            <th>قابلیت‌ها</th>
                            <th>وضعیت</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($models)): ?>
                            <tr><td colspan="8" class="text-center text-muted">هیچ مدلی یافت نشد.</td></tr>
                        <?php else: foreach ($models as $m): ?>
                            <tr>
                                <td><?php echo $m['id']; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($m['display_name'] ?? $m['name'] ?? '—'); ?></strong>
                                    <?php if ($m['description']): ?><br><small class="text-muted"><?php echo htmlspecialchars(mb_substr($m['description'], 0, 60)); ?></small><?php endif; ?>
                                </td>
                                <td><?php echo (int)($m['cost_per_second'] ?? 1); ?> /s</td>
                                <td><small><?php echo htmlspecialchars($m['supported_resolutions'] ?? '—'); ?></small></td>
                                <td><small><?php echo htmlspecialchars($m['supported_durations'] ?? '—'); ?>s</small></td>
                                <td>
                                    <?php
                                    $caps = [];
                                    if ($m['allow_first_frame']) $caps[] = 'first_frame';
                                    if ($m['allow_last_frame']) $caps[] = 'last_frame';
                                    if ($m['allow_input_references']) $caps[] = 'refs';
                                    if ($m['allow_generate_audio']) $caps[] = 'audio';
                                    if ($m['allow_img2video']) $caps[] = 'img2vid';
                                    echo '<small>' . (!empty($caps) ? implode(', ', $caps) : '—') . '</small>';
                                    ?>
                                </td>
                                <td><?php echo $m['is_active'] ? '<span class="badge bg-success">✅ فعال</span>' : '<span class="badge bg-secondary">❌ غیرفعال</span>'; ?></td>
                                <td>
                                    <a href="modelsvideo.php?edit=<?php echo $m['id']; ?>" class="btn btn-sm btn-outline-primary">✏️</a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('آیا مطمئن هستید؟');">
                                        <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?php echo $m['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-warning" title="تغییر وضعیت">🔄</button>
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
            </div>
            <a href="models.php" class="btn btn-sm btn-outline-secondary">🔙 بازگشت به لیست اصلی</a>
        </div>
    </div>
</div>

<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../../views/admin/layout.php';