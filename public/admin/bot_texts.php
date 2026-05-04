<?php
/**
 * Manage bot texts (all hardcoded strings moved to database).
 */
require_once __DIR__ . '/../../init.php';

$pageTitle = 'مدیریت متن‌های ربات';
$activeMenu = 'bot_texts';

use Core\BotTextService;

$message = '';
$messageType = 'success';

// Handle POST: update or reset
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'update' && isset($_POST['text_key']) && isset($_POST['text_value'])) {
            $key = trim($_POST['text_key']);
            $value = $_POST['text_value'];
            BotTextService::set($key, $value);
            $message = '✅ متن با موفقیت بروزرسانی شد.';
        } elseif ($action === 'reset' && isset($_POST['text_key'])) {
            $key = trim($_POST['text_key']);
            BotTextService::resetToDefault($key);
            $message = '✅ متن به مقدار پیش‌فرض بازنشانی شد.';
        } elseif ($action === 'reset_all') {
            BotTextService::seedDefaults();
            $message = '✅ تمام متن‌ها به مقادیر پیش‌فرض بازنشانی شدند.';
        } else {
            throw new \Exception('عملیات نامعتبر');
        }
    } catch (\Throwable $e) {
        $message = '❌ خطا: ' . $e->getMessage();
        $messageType = 'danger';
    }
}

$texts = BotTextService::getAll();
$defaults = BotTextService::getDefaults();

// Build a lookup of DB values
$dbValues = [];
foreach ($texts as $t) {
    $dbValues[$t['text_key']] = $t['text_value'];
}

// Merge all keys (both from DB and defaults)
$allKeys = array_unique(array_merge(array_keys($defaults), array_column($texts, 'text_key')));
sort($allKeys);

// Group by prefix for better organization
$groups = [];
foreach ($allKeys as $key) {
    $prefix = explode('_', $key)[0] ?? 'other';
    $groups[$prefix][] = $key;
}

ob_start();
?>
<style>
.text-key { font-family: monospace; font-size: 0.85em; direction: ltr; display: inline-block; }
.text-preview { max-height: 60px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.card-header .collapse-toggle { cursor: pointer; }
</style>

<?php if ($message): ?>
<div class="alert alert-<?php echo $messageType === 'danger' ? 'danger' : 'success'; ?> alert-dismissible fade show">
    <?php echo htmlspecialchars($message); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">📝 مدیریت متن‌های ربات</h4>
    <form method="POST" onsubmit="return confirm('آیا مطمئن هستید؟ تمام متن‌های سفارشی حذف شده و به حالت پیش‌فرض برمی‌گردند.');">
        <input type="hidden" name="action" value="reset_all">
        <button type="submit" class="btn btn-warning btn-sm">🔄 بازنشانی همه به پیش‌فرض</button>
    </form>
</div>

<p class="text-muted">در این بخش می‌توانید تمام متن‌های نمایش داده شده در ربات را ویرایش کنید. هر تغییری بلافاصله در ربات اعمال می‌شود.</p>

<div class="accordion" id="textsAccordion">
<?php 
$groupIndex = 0;
$groupLabels = [
    'welcome' => '👋 خوش‌آمدگویی',
    'error' => '❌ خطاها',
    'main' => '🏠 منوی اصلی',
    'fallback' => '🤖 پیام‌های پیش‌فرض',
    'help' => '❓ راهنما',
    'membership' => '🔒 عضویت اجباری',
    'registration' => '📝 ثبت‌نام',
    'ai' => '🤖 هوش مصنوعی',
    'model' => '🎯 مدل‌ها',
    'image' => '🎨 تصویرساز',
    'edit' => '🖼 ویرایش تصویر',
    'video' => '🎬 ویدئو',
    'chat' => '💬 چت',
    'account' => '👤 حساب کاربری',
    'plan' => '💰 پلن‌ها',
    'zibal' => '💳 زیبال',
    'bale' => '💳 بله',
    'memory' => '🧠 حافظه',
    'other' => '📦 سایر',
];

foreach ($groups as $prefix => $keys):
    $groupIndex++;
    $label = $groupLabels[$prefix] ?? "📦 {$prefix}";
    $isFirst = $groupIndex === 1;
?>
    <div class="accordion-item">
        <h2 class="accordion-header" id="heading<?php echo $groupIndex; ?>">
            <button class="accordion-button <?php echo $isFirst ? '' : 'collapsed'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#collapse<?php echo $groupIndex; ?>">
                <?php echo $label; ?> (<?php echo count($keys); ?> متن)
            </button>
        </h2>
        <div id="collapse<?php echo $groupIndex; ?>" class="accordion-collapse collapse <?php echo $isFirst ? 'show' : ''; ?>" data-bs-parent="#textsAccordion">
            <div class="accordion-body p-0">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th style="width:250px;">کلید</th>
                            <th>مقدار فعلی</th>
                            <th style="width:200px;">عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($keys as $key): 
                            $currentValue = $dbValues[$key] ?? $defaults[$key] ?? '';
                            $isModified = isset($dbValues[$key]) && $dbValues[$key] !== ($defaults[$key] ?? '');
                            $preview = mb_substr(strip_tags($currentValue), 0, 100);
                        ?>
                        <tr class="<?php echo $isModified ? 'table-warning' : ''; ?>">
                            <td>
                                <span class="text-key"><?php echo htmlspecialchars($key); ?></span>
                                <?php if ($isModified): ?>
                                    <span class="badge bg-warning text-dark">ویرایش شده</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="text-preview"><?php echo htmlspecialchars($preview); ?></div>
                            </td>
                            <td>
                                <button type="button" class="btn btn-sm btn-outline-primary" 
                                        onclick="editText('<?php echo htmlspecialchars($key); ?>', <?php echo htmlspecialchars(json_encode($currentValue)); ?>)">
                                    ✏️ ویرایش
                                </button>
                                <?php if ($isModified): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('بازنشانی به مقدار پیش‌فرض؟');">
                                    <input type="hidden" name="action" value="reset">
                                    <input type="hidden" name="text_key" value="<?php echo htmlspecialchars($key); ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-warning">↩️ پیش‌فرض</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="text_key" id="edit_key">
                <div class="modal-header">
                    <h5 class="modal-title">✏️ ویرایش متن</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">کلید:</label>
                        <code id="edit_key_display" class="form-control-plaintext"></code>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">مقدار:</label>
                        <textarea name="text_value" id="edit_value" class="form-control" rows="10" style="font-family:monospace;"></textarea>
                        <div class="form-text">
                            می‌توانید از متغیرهای {variable_name} استفاده کنید. 
                            <a href="#" onclick="showHelpVars(); return false;">مشاهده متغیرهای قابل استفاده</a>
                        </div>
                    </div>
                    <div id="helpVars" class="alert alert-info d-none">
                        <strong>متغیرهای قابل استفاده:</strong><br>
                        <code>{model_name}</code> — نام مدل<br>
                        <code>{cost}</code> — هزینه<br>
                        <code>{error}</code> — متن خطا<br>
                        <code>{count}</code> — تعداد<br>
                        <code>{max_photos}</code> — حداکثر تعداد عکس<br>
                        <code>{display_name}</code> — نام نمایشی مدل<br>
                        <code>{in_cost}</code> — هزینه ورودی<br>
                        <code>{out_cost}</code> — هزینه خروجی<br>
                        <code>{formats}</code> — فرمت‌های مجاز<br>
                        <code>{phone}</code> — شماره تلفن<br>
                        <code>{credits}</code> — اعتبار<br>
                        <code>{user_id}</code> — شناسه کاربر<br>
                        <code>{page}</code> — شماره صفحه<br>
                        <code>{total}</code> — تعداد کل صفحات<br>
                        <code>{ext}</code> — پسوند فایل<br>
                        <code>{filename}</code> — نام فایل<br>
                        <code>{plan_name}</code> — نام پلن<br>
                        <code>{amount}</code> — مبلغ<br>
                        <code>{payment_url}</code> — لینک پرداخت<br>
                        <code>{text}</code> — متن خروجی<br>
                        <code>{input_cost}</code> — هزینه ورودی<br>
                        <code>{output_cost}</code> — هزینه خروجی<br>
                        <code>{total_cost}</code> — مجموع هزینه<br>
                        <code>{free_text}</code> — متن رایگان<br>
                        <code>{name_line}</code> — خط نام<br>
                        <code>{memory_section}</code> — بخش حافظه<br>
                        <code>{memories}</code> — لیست خاطرات<br>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
                    <button type="submit" class="btn btn-primary">💾 ذخیره</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editText(key, value) {
    document.getElementById('edit_key').value = key;
    document.getElementById('edit_key_display').textContent = key;
    document.getElementById('edit_value').value = value;
    new bootstrap.Modal(document.getElementById('editModal')).show();
}

function showHelpVars() {
    document.getElementById('helpVars').classList.toggle('d-none');
}
</script>

<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../../views/admin/layout.php';