<?php
$pageTitle = 'حساب کاربری';
require_once __DIR__ . '/init.php';
require __DIR__ . '/layout.php';
$botUserId = getBotUserId($webUser['id']);
$botUser = null;
if ($botUserId) {
    try {
        $db = Database::getInstance();
        $botUser = $db->query("SELECT * FROM users WHERE id = ?", [$botUserId])->fetch();
    } catch (\Throwable $e) {}
}
?>
<div class="max-w-2xl mx-auto">
    <h1 class="text-xl font-bold text-white mb-4">حساب کاربری</h1>
    <div class="glass rounded-2xl p-5 space-y-4">
        <div>
            <span class="text-gray-400 text-sm">شماره موبایل</span>
            <p class="text-white font-bold"><?= htmlspecialchars($webUser['phone']) ?></p>
        </div>
        <div>
            <span class="text-gray-400 text-sm">اعتبار</span>
            <p class="text-emerald-400 font-bold text-lg"><?= number_format($credits) ?></p>
        </div>
        <?php if ($botUser): ?>
        <div>
            <span class="text-gray-400 text-sm">نام کاربری در ربات</span>
            <p class="text-white"><?= htmlspecialchars($botUser['first_name'] ?: '--') ?></p>
        </div>
        <div>
            <span class="text-gray-400 text-sm">شناسه بله</span>
            <p class="text-white"><?= htmlspecialchars($botUser['bale_user_id'] ?? 0) ?></p>
        </div>
        <div>
            <span class="text-gray-400 text-sm">تاریخ ثبت‌نام</span>
            <p class="text-white"><?= $botUser['created_at'] ?? '--' ?></p>
        </div>
        <?php endif; ?>
    </div>
    <a href="/web/logout.php" class="block mt-4 text-center text-red-400 hover:text-red-300 no-underline">خروج از حساب</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
</main></div></body></html>