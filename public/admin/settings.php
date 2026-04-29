<?php
require_once __DIR__ . '/../../init.php';

$pageTitle = 'تنظیمات سیستم';
$activeMenu = 'settings';

use Modules\Admin\SettingsManager;

$settingsManager = new SettingsManager();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        foreach ($_POST['settings'] as $key => $value) {
            $settingsManager->set($key, trim($value));
        }
        $message = '✅ تنظیمات با موفقیت بروزرسانی شد.';
    } catch (\Throwable $e) {
        $message = '❌ خطا: ' . $e->getMessage();
    }
}

// Handle file cleanup action
if (isset($_POST['action']) && $_POST['action'] === 'clean_uploads') {
    try {
        $uploadService = new \Modules\AI\UploadService();
        $deleted = $uploadService->cleanOldFiles(24);
        header('Location: settings.php?cleaned=' . $deleted);
        exit;
    } catch (\Throwable $e) {
        $message = '❌ خطا در پاکسازی: ' . $e->getMessage();
    }
}

$currentSettings = $settingsManager->getAll();

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
            <h5>⚙️ تنظیمات پیکربندی ربات</h5>
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label">شناسه کانال اجباری (required_channel_id):</label>
                    <input type="text" name="settings[required_channel_id]" class="form-control"
                           value="<?php echo htmlspecialchars($currentSettings['required_channel_id'] ?? '@mobix_tube'); ?>"
                           placeholder="مثلاً: @channel_id">
                    <div class="form-text">کاربران برای استفاده از ربات باید عضو این کانال باشند.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label">لینک کانال (required_channel_link):</label>
                    <input type="text" name="settings[required_channel_link]" class="form-control"
                           value="<?php echo htmlspecialchars($currentSettings['required_channel_link'] ?? 'https://t.me/mobix_tube'); ?>"
                           placeholder="لینک دعوت کانال">
                </div>

                <hr>

                <div class="mb-3">
                    <label class="form-label">محدودیت رایگان روزانه (free_daily_limit):</label>
                    <input type="number" name="settings[free_daily_limit]" class="form-control" min="0"
                           value="<?php echo htmlspecialchars($currentSettings['free_daily_limit'] ?? '1'); ?>">
                    <div class="form-text">تعداد تصاویر رایگان در روز برای هر کاربر (0 = غیرفعال).</div>
                </div>

                <div class="mb-3">
                    <label class="form-label">اعتبار رایگان اولیه (initial_credit):</label>
                    <input type="number" name="settings[initial_credit]" class="form-control" min="0"
                           value="<?php echo htmlspecialchars($currentSettings['initial_credit'] ?? '15'); ?>">
                    <div class="form-text">اعتبار هدیه برای کاربران جدید.</div>
                </div>

                <hr>

                <div class="mb-3">
                    <label class="form-label">پیام خوش‌آمدگویی (welcome_message):</label>
                    <textarea name="settings[welcome_message]" class="form-control" rows="4"
                              placeholder="متن پیام خوش‌آمدگویی در /start"><?php echo htmlspecialchars($currentSettings['welcome_message'] ?? ''); ?></textarea>
                </div>

                <div class="mb-3 form-check form-switch">
                    <?php $maintenance = $currentSettings['maintenance_mode'] ?? 'off'; ?>
                    <input type="hidden" name="settings[maintenance_mode]" value="off">
                    <input type="checkbox" name="settings[maintenance_mode]" class="form-check-input" role="switch"
                           id="maintenanceMode" value="on"
                           <?php echo $maintenance === 'on' ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="maintenanceMode">حالت تعمیرات (Maintenance Mode)</label>
                    <div class="form-text">در صورت فعال بودن، ربات به کاربران پیام تعمیرات نشان می‌دهد.</div>
                </div>

                <hr>

                <h6>🔗 تنظیمات API و درگاه</h6>
                <div class="mb-3">
                    <label class="form-label">توکن ربات بله:</label>
                    <input type="text" name="settings[bot_token]" class="form-control"
                           value="<?php echo htmlspecialchars($currentSettings['bot_token'] ?? ''); ?>"
                           placeholder="از BotFather دریافت کنید"
                           style="direction:ltr; font-family:monospace;">
                </div>

                <div class="mb-3">
                    <label class="form-label">کد مرچنت زیبال (Zibal merchant):</label>
                    <input type="text" name="settings[zibal_merchant]" class="form-control"
                           value="<?php echo htmlspecialchars($currentSettings['zibal_merchant'] ?? ''); ?>"
                           placeholder="zibal برای تست">
                </div>

                <div class="mb-3">
                    <label class="form-label">آدرس بازگشت پرداخت (Zibal callback URL):</label>
                    <input type="text" name="settings[zibal_callback_url]" class="form-control"
                           value="<?php echo htmlspecialchars($currentSettings['zibal_callback_url'] ?? 'https://mobixai.ir/payment/verify.php'); ?>"
                           placeholder="URL کامل">
                </div>

                <button type="submit" class="btn btn-primary btn-lg w-100">
                    <i class="bi bi-save"></i> ذخیره کلیه تنظیمات
                </button>
            </form>
        </div>

        <div class="table-container mt-4">
            <h5>🗑️ پاکسازی فایل‌های آپلود شده</h5>
            <?php
            use Modules\AI\UploadService;
            $uploadStats = (new UploadService())->getStats();
            ?>
            <p>تعداد فایل‌های ذخیره شده: <strong><?php echo number_format($uploadStats['count']); ?></strong></p>
            <p>حجم کل: <strong><?php echo number_format($uploadStats['total_size'] / 1024, 1); ?> KB</strong></p>
            <form method="POST" onsubmit="return confirm('فایل‌های قدیمی‌تر از ۲۴ ساعت حذف شوند؟');">
                <input type="hidden" name="action" value="clean_uploads">
                <button type="submit" class="btn btn-warning">
                    🗑️ پاکسازی فایل‌های قدیمی (بیشتر از ۲۴ ساعت)
                </button>
            </form>
            <?php if (isset($_GET['cleaned'])): ?>
                <div class="alert alert-success mt-2">✅ <?php echo (int)$_GET['cleaned']; ?> فایل پاکسازی شد.</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="table-container">
            <h5>📋 وضعیت فعلی تنظیمات</h5>
            <table class="table table-sm">
                <thead>
                    <tr><th>کلید</th><th>مقدار</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($currentSettings as $key => $value): ?>
                    <tr>
                        <td style="font-size:0.85rem; font-family:monospace;"><?php echo htmlspecialchars($key); ?></td>
                        <td style="font-size:0.85rem; max-width:150px; overflow:hidden; text-overflow:ellipsis;">
                            <?php echo htmlspecialchars(mb_substr((string) $value, 0, 40)); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../../views/admin/layout.php';