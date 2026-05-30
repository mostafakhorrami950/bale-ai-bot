<?php
$pageTitle = 'داشبورد';
require_once __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

// Get stats
$totalChats = 0;
$totalImages = 0;
try {
    $db = Database::getInstance();
    $botUserId = getBotUserId($webUser['id']);
    if ($botUserId) {
        $totalChats = (int)$db->query("SELECT COUNT(*) as c FROM chat_conversations WHERE user_id = ?", [$botUserId])->fetch()['c'];
        $totalImages = (int)$db->query("SELECT COUNT(*) as c FROM ai_requests WHERE user_id = ? AND image_type IN ('text2img','img2img')", [$botUserId])->fetch()['c'];
    }
} catch (\Throwable $e) {}
?>
<div class="max-w-4xl mx-auto">
    <h1 class="text-2xl font-bold text-white mb-1">خوش آمدید، <?= htmlspecialchars($userName) ?> 👋</h1>
    <p class="text-gray-400 mb-8">به نسخه تحت وب موبیکس‌بات خوش آمدید</p>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
        <div class="glass rounded-2xl p-5">
            <div class="flex items-center gap-3 mb-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-400 to-teal-600 flex items-center justify-center">
                    <i data-lucide="diamond" class="w-5 h-5 text-white"></i>
                </div>
                <span class="text-gray-400 text-sm">اعتبار فعلی</span>
            </div>
            <span class="text-3xl font-bold text-emerald-400"><?= number_format($credits) ?></span>
        </div>
        <div class="glass rounded-2xl p-5">
            <div class="flex items-center gap-3 mb-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-400 to-purple-600 flex items-center justify-center">
                    <i data-lucide="message-square" class="w-5 h-5 text-white"></i>
                </div>
                <span class="text-gray-400 text-sm">چت‌ها</span>
            </div>
            <span class="text-3xl font-bold text-indigo-400"><?= number_format($totalChats) ?></span>
        </div>
        <div class="glass rounded-2xl p-5">
            <div class="flex items-center gap-3 mb-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-pink-400 to-rose-600 flex items-center justify-center">
                    <i data-lucide="image" class="w-5 h-5 text-white"></i>
                </div>
                <span class="text-gray-400 text-sm">تصاویر ساخته شده</span>
            </div>
            <span class="text-3xl font-bold text-pink-400"><?= number_format($totalImages) ?></span>
        </div>
    </div>

    <!-- Quick Actions -->
    <h2 class="text-lg font-bold text-white mb-4">دسترسی سریع</h2>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <a href="/web/chat.php" class="glass glass-hover rounded-2xl p-5 flex items-center gap-4 no-underline group">
            <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-emerald-400 to-teal-600 flex items-center justify-center shrink-0 transition-transform group-hover:scale-110">
                <i data-lucide="message-square" class="w-7 h-7 text-white"></i>
            </div>
            <div>
                <h3 class="text-white font-bold">چت هوشمند</h3>
                <p class="text-gray-400 text-sm">مکالمه با هوش مصنوعی</p>
            </div>
        </a>
        <a href="/web/image.php" class="glass glass-hover rounded-2xl p-5 flex items-center gap-4 no-underline group">
            <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-indigo-400 to-purple-600 flex items-center justify-center shrink-0 transition-transform group-hover:scale-110">
                <i data-lucide="image" class="w-7 h-7 text-white"></i>
            </div>
            <div>
                <h3 class="text-white font-bold">تولید تصویر</h3>
                <p class="text-gray-400 text-sm">ساخت تصویر با هوش مصنوعی</p>
            </div>
        </a>
        <a href="/web/video.php" class="glass glass-hover rounded-2xl p-5 flex items-center gap-4 no-underline group">
            <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-orange-400 to-red-600 flex items-center justify-center shrink-0 transition-transform group-hover:scale-110">
                <i data-lucide="video" class="w-7 h-7 text-white"></i>
            </div>
            <div>
                <h3 class="text-white font-bold">تولید ویدیو</h3>
                <p class="text-gray-400 text-sm">ساخت ویدیو با هوش مصنوعی</p>
            </div>
        </a>
        <a href="/web/plans.php" class="glass glass-hover rounded-2xl p-5 flex items-center gap-4 no-underline group">
            <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-emerald-400 to-teal-600 flex items-center justify-center shrink-0 transition-transform group-hover:scale-110">
                <i data-lucide="shopping-cart" class="w-7 h-7 text-white"></i>
            </div>
            <div>
                <h3 class="text-white font-bold">خرید اعتبار</h3>
                <p class="text-gray-400 text-sm">افزایش اعتبار حساب</p>
            </div>
        </a>
    </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
</main></div></body></html>