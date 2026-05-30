<?php
$pageTitle = 'چت هوشمند';
require_once __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

$botUserId = getBotUserId($webUser['id']);
$models = [];
$conversations = [];
$currentConv = null;

try {
    $db = Database::getInstance();
    $models = $db->query("SELECT * FROM ai_text_models WHERE is_active = 1 ORDER BY sort_order ASC, id ASC")->fetchAll();
    if ($botUserId) {
        $conversations = $db->query("SELECT * FROM chat_conversations WHERE user_id = ? AND status = 'active' ORDER BY updated_at DESC LIMIT 20", [$botUserId])->fetchAll();
        $convId = (int)($_GET['conv'] ?? 0);
        if ($convId) {
            $currentConv = $db->query("SELECT * FROM chat_conversations WHERE id = ? AND user_id = ?", [$convId, $botUserId])->fetch();
        }
    }
} catch (\Throwable $e) {}
$defaultModel = $models[0]['name'] ?? 'google/gemini-2.5-flash-image';
?>
<div class="max-w-4xl mx-auto h-full flex flex-col">
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-xl font-bold text-white">چت هوشمند</h1>
        <div class="flex items-center gap-2">
            <select id="modelSelect" class="input-field rounded-xl px-3 py-2 text-sm">
                <?php foreach ($models as $m): ?>
                <option value="<?= htmlspecialchars($m['name']) ?>" <?= $m['name'] === $defaultModel ? 'selected' : '' ?>>
                    <?= htmlspecialchars($m['display_name'] ?: $m['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?php if ($botUserId): ?>
            <button onclick="newConversation()" class="glass rounded-xl px-3 py-2 text-sm text-emerald-400 hover:bg-white/10 transition" title="مکالمه جدید">
                <i data-lucide="plus" class="w-4 h-4 inline"></i> جدید
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Messages -->
    <div id="chatMessages" class="flex-1 overflow-y-auto space-y-3 mb-4 px-1">
        <div class="text-center text-gray-500 py-10">
            <i data-lucide="message-square" class="w-12 h-12 mx-auto mb-3 opacity-30"></i>
            <p>مکالمه جدیدی شروع کنید</p>
        </div>
    </div>

    <!-- Input -->
    <div class="glass rounded-2xl p-3">
        <form id="chatForm" onsubmit="sendChatMessage(event)" class="flex gap-2">
            <input type="text" id="chatInput" placeholder="پیام خود را بنویسید..." class="input-field flex-1 rounded-xl px-4 py-3 text-sm" autocomplete="off">
            <button type="submit" class="btn-primary rounded-xl px-5 py-3 text-white font-bold text-sm flex items-center gap-2">
                <i data-lucide="send" class="w-4 h-4"></i>
                <span class="hidden md:inline">ارسال</span>
            </button>
        </form>
    </div>
</div>

<script>
let currentConvId = <?= ($convId ?? 0) ?>;
let botUserId = <?= (int)($botUserId ?? 0) ?>;

<?php if ($currentConv && $botUserId): ?>
// Load existing conversation
loadConversation(<?= $currentConv['id'] ?>);
<?php endif; ?>

function loadConversation(convId) {
    fetch('/web/ajax/chat_history.php?conv=' + convId)
    .then(r => r.json())
    .then(data => {
        if (data.messages) {
            const container = document.getElementById('chatMessages');
            container.innerHTML = '';
            data.messages.forEach(msg => addMessage(msg.role, msg.content, false));
            container.scrollTop = container.scrollHeight;
            currentConvId = convId;
        }
    });
}

function newConversation() {
    document.getElementById('chatMessages').innerHTML = '<div class="text-center text-gray-500 py-10"><i data-lucide="message-square" class="w-12 h-12 mx-auto mb-3 opacity-30"></i><p>مکالمه جدیدی شروع کنید</p></div>';
    lucide.createIcons();
    currentConvId = 0;
}

function addMessage(role, text, save = true) {
    const container = document.getElementById('chatMessages');
    const div = document.createElement('div');
    div.className = role === 'user' ? 'msg-user rounded-2xl p-4 max-w-[85%] mr-auto' : 'msg-bot rounded-2xl p-4 max-w-[85%]';
    div.innerHTML = '<p class="text-gray-200 text-sm leading-relaxed whitespace-pre-wrap">' + escapeHtml(text) + '</p>';
    container.appendChild(div);
    container.scrollTop = container.scrollHeight;
    lucide.createIcons();
}

function escapeHtml(text) {
    const d = document.createElement('div');
    d.textContent = text;
    return d.innerHTML;
}

function sendChatMessage(e) {
    e.preventDefault();
    const input = document.getElementById('chatInput');
    const text = input.value.trim();
    if (!text) return;

    const model = document.getElementById('modelSelect').value;
    addMessage('user', text);
    input.value = '';

    // Show loading
    const container = document.getElementById('chatMessages');
    const loadingDiv = document.createElement('div');
    loadingDiv.className = 'msg-bot rounded-2xl p-4 max-w-[85%]';
    loadingDiv.innerHTML = '<p class="text-gray-400 text-sm"><span class="loading-dots">در حال پاسخ</span></p>';
    container.appendChild(loadingDiv);
    container.scrollTop = container.scrollHeight;

    fetch('/web/ajax/chat_send.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            message: text,
            model: model,
            conversation_id: currentConvId
        })
    })
    .then(r => r.json())
    .then(data => {
        loadingDiv.remove();
        if (data.response) {
            addMessage('assistant', data.response);
            if (data.conversation_id) {
                currentConvId = data.conversation_id;
            }
        } else {
            addMessage('assistant', '⚠️ ' + (data.error || 'خطا در ارتباط با هوش مصنوعی'));
        }
    })
    .catch(() => {
        loadingDiv.remove();
        addMessage('assistant', '⚠️ خطا در ارتباط با سرور');
    });
}
</script>
<?php require __DIR__ . '/footer.php'; ?>
</main></div></body></html>