<?php
/**
 * Models overview page — shows all models and links to type-specific pages.
 */
require_once __DIR__ . '/../../init.php';

$pageTitle = 'مدیریت مدل‌های AI';
$activeMenu = 'models';

use Modules\Admin\ModelManager;
use Admin\ModelHelper;

$modelManager = new ModelManager();
$models = $modelManager->getAllModels();

ob_start();
?>

<div class="row">
    <div class="col-md-12 mb-4">
        <div class="table-container">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 style="margin-bottom:0;">📋 لیست همه مدل‌ها (<?php echo count($models); ?>)</h5>
                <div>
                    <a href="modelstext2img.php" class="btn btn-sm btn-outline-primary">➕ ساخت تصویر</a>
                    <a href="modelsimg2img.php" class="btn btn-sm btn-outline-primary">➕ ویرایش تصویر</a>
                    <a href="modelstext.php" class="btn btn-sm btn-outline-primary">➕ متنی</a>
                    <a href="modelsvideo.php" class="btn btn-sm btn-outline-primary">➕ ویدئو</a>
                </div>
            </div>
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>نام نمایشی</th>
                        <th>نام مدل</th>
                        <th>ارائه‌دهنده</th>
                        <th>نوع</th>
                        <th>هزینه</th>
                        <th>وضعیت</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($models)): ?>
                        <tr><td colspan="8" class="text-center text-muted">هیچ مدلی یافت نشد.</td></tr>
                    <?php else: ?>
                        <?php foreach ($models as $m): ?>
                        <?php
                            $type = $m['model_type'] ?? 'image_generation';
                            $tl = ModelHelper::typeLabel($type);
                            $displayName = $m['display_name'] ?? $m['name'] ?? '—';
                            $pageMap = [
                                'text' => 'modelstext',
                                'image_generation' => 'modelstext2img',
                                'image_editing' => 'modelsimg2img',
                                'video' => 'modelsvideo',
                            ];
                            $editPage = $pageMap[$type] ?? 'modelstext2img';
                        ?>
                        <tr>
                            <td><?php echo $m['id']; ?></td>
                            <td><strong><?php echo htmlspecialchars($displayName); ?></strong></td>
                            <td><code><?php echo htmlspecialchars($m['name']); ?></code></td>
                            <td><code><?php echo htmlspecialchars($m['provider'] ?? 'openrouter'); ?></code></td>
                            <td><span class="badge bg-secondary"><?php echo $tl; ?></span></td>
                            <td><?php echo $m['cost_label'] ?? ($m['cost'] ?? '—'); ?></td>
                            <td>
                                <?php if ($m['is_active']): ?>
                                    <span class="badge-active">✅ فعال</span>
                                <?php else: ?>
                                    <span class="badge-inactive">❌ غیرفعال</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?php echo $editPage; ?>.php?edit=<?php echo $m['id']; ?>" class="btn btn-sm btn-outline-primary">✏️</a>
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