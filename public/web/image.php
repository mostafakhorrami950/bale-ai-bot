<?php
$pageTitle = 'تولید تصویر';
require_once __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

$botUserId = getBotUserId($webUser['id']);
$models = [];
try {
    $db = Database::getInstance();
    $models = $db->query("SELECT * FROM ai_image_models WHERE is_active = 1")->fetchAll();
} catch (\Throwable $e) {}
$defaultModel = $models[0]['name'] ?? 'gpt-image-1';
?>
<div class="max-w-3xl mx-auto">
    <h1 class="text-xl font-bold text-white mb-4">تولید تصویر با هوش مصنوعی</h1>
    <div class="glass rounded-2xl p-5 mb-4">
        <div class="mb-4">
            <label class="block text-gray-300 text-sm mb-2">مدل</label>
            <select id="imageModel" class="input-field w-full rounded-xl px-4 py-2.5 text-sm">
                <?php foreach ($models as $m): ?>
                <option value="<?= htmlspecialchars($m['name']) ?>">
                    <?= htmlspecialchars($m['display_name'] ?: $m['name']) ?> (<?= $m['cost_per_image'] ?> اعتبار)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-4">
            <label class="block text-gray-300 text-sm mb-2">توضیحات تصویر (Prompt)</label>
            <textarea id="prompt" rows="4" class="input-field w-full rounded-xl px-4 py-3 text-sm resize-none" placeholder="تصویری از ..."></textarea>
        </div>
        <button onclick="generateImage()" class="btn-primary w-full rounded-xl py-3 text-white font-bold text-sm">
            <i data-lucide="image" class="w-4 h-4 inline"></i> تولید تصویر
        </button>
    </div>
    <div id="resultArea" class="hidden">
        <div class="glass rounded-2xl p-5 text-center" id="loadingArea">
            <p class="text-gray-400">در حال تولید تصویر...</p>
        </div>
        <div id="imageResult" class="hidden glass rounded-2xl p-5 text-center">
            <img id="generatedImage" class="max-w-full rounded-xl mx-auto" alt="Generated">
            <button onclick="downloadImage()" class="btn-primary rounded-xl px-5 py-2 text-white text-sm mt-4 inline-flex items-center gap-2">
                <i data-lucide="download" class="w-4 h-4"></i> دانلود
            </button>
        </div>
    </div>
</div>
<script>
let lastImageUrl = '';
function generateImage() {
    const prompt = document.getElementById('prompt').value.trim();
    if (!prompt) { alert('لطفاً توضیحات را وارد کنید'); return; }
    const model = document.getElementById('imageModel').value;
    document.getElementById('resultArea').classList.remove('hidden');
    document.getElementById('imageResult').classList.add('hidden');
    document.getElementById('loadingArea').classList.remove('hidden');
    fetch('/web/ajax/generate_image.php', {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({prompt, model})
    }).then(r => r.json()).then(d => {
        document.getElementById('loadingArea').classList.add('hidden');
        if (d.url) {
            lastImageUrl = d.url;
            document.getElementById('imageResult').classList.remove('hidden');
            document.getElementById('generatedImage').src = d.url;
        } else {
            alert(d.error || 'خطا در تولید');
        }
    }).catch(() => alert('خطا در ارتباط'));
}
function downloadImage() {
    if (lastImageUrl) window.open(lastImageUrl, '_blank');
}
</script>
<?php require __DIR__ . '/footer.php'; ?>
</main></div></body></html>