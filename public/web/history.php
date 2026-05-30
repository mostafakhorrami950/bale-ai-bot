<?php
$pageTitle = 'تاریخچه';
require_once __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

$botUserId = getBotUserId($webUser['id']);
$history = [];
if ($botUserId) {
    try {
        $db = Database::getInstance();
        $history = $db->query("
            SELECT cr.id, cr.title, cr.model, cr.created_at, cr.updated_at,
                   (SELECT COUNT(*) FROM chat_messages WHERE conversation_id = cr.id) as msg_count
            FROM chat_conversations cr WHERE cr.user_id = ? AND cr.status = 'active'
            ORDER BY cr.updated_at DESC LIMIT 50
        ", [$botUserId])->fetchAll();
    } catch (\Throwable $e) {}
}
?>
<div class="max-w-3xl mx-auto">
    <h1 class="text-xl font-bold text-white mb-4">تاریخچه مکالمات</h1>
    <?php if (empty($history)): ?>
    <div class="text-center text-gray-500 py-10">
        <i data-lucide="clock" class="w-12 h-12 mx-auto mb-3 opacity-30"></i>
        <p>مکالماتی وجود ندارد</p>
    </div>
    <?php else: ?>
    <div class="space-y-2">
        <?php foreach ($history as $h): ?>
        <a href="/web/chat.php?conv=<?= $h['id'] ?>" class="glass glass-hover rounded-xl p-4 flex items-center justify-between no-underline">
            <div class="flex items-center gap-3">
                <i data-lucide="message-square" class="w-5 h-5 text-emerald-400"></i>
                <div>
                    <p class="text-white text-sm"><?= htmlspecialchars(mb_substr($h['title'] ?: 'مکالمه', 0, 50)) ?></p>
                    <p class="text-gray-500 text-xs"><?= $h['model'] ?> • <?= $h['msg_count'] ?> پیام</p>
                </div>
            </div>
            <span class="text-gray-500 text-xs"><?= date('Y/m/d H:i', strtotime($h['updated_at'])) ?></span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/footer.php'; ?>
</main></div></body></html>