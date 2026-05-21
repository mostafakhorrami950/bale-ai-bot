<?php
require_once __DIR__ . '/../../init.php';

$pageTitle = 'مکالمات کاربران';
$activeMenu = 'chat_list';

use Database\Database;

$db = Database::getInstance();
$message = '';
$convs = [];
$total = 0;
$totalPages = 1;

// AJAX handler: return messages for a conversation
if (isset($_GET['action']) && $_GET['action'] === 'get_messages') {
    $convId = (int)($_GET['conv_id'] ?? 0);
    if ($convId <= 0) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid conversation ID']);
        exit;
    }
    try {
        $messages = $db->query(
            "SELECT id, role, content, file_type, file_content, input_chars, output_chars, cost_input_credits, cost_output_credits, created_at
             FROM chat_messages
             WHERE conversation_id = ?
             ORDER BY id ASC",
            [$convId]
        )->fetchAll();
        header('Content-Type: application/json');
        echo json_encode($messages, JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        header('Content-Type: application/json');
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// Check if chat_conversations table exists
try {
    $db->query("SELECT 1 FROM chat_conversations LIMIT 1");
} catch (\Throwable $e) {
    ob_start();
    ?>
    <div class="table-container">
        <h5>💬 لیست مکالمات</h5>
        <p class="text-muted text-center py-4">جدول chat_conversations هنوز ایجاد نشده است. لطفاً ابتدا از <a href="repair_db.php">صفحه تعمیر دیتابیس</a> استفاده کنید.</p>
    </div>
    <?php
    $pageContent = ob_get_clean();
    require __DIR__ . '/../../views/admin/layout.php';
    return;
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_conv'])) {
    $db->query("DELETE FROM chat_conversations WHERE id = ?", [(int)$_POST['delete_conv']]);
    $message = '✅ مکالمه حذف شد.';
}

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

try {
    $total = $db->query("SELECT COUNT(*) as c FROM chat_conversations")->fetch()['c'] ?? 0;
} catch (\Throwable $e) {
    $total = 0;
}
$totalPages = max(1, ceil($total / $perPage));

try {
    $convs = $db->query(
        "SELECT c.*, u.bale_user_id, up.username, up.first_name, up.last_name
         FROM chat_conversations c
         LEFT JOIN users u ON c.user_id = u.id
         LEFT JOIN user_profiles up ON up.user_id = u.id
         ORDER BY c.id DESC
         LIMIT ? OFFSET ?",
        [$perPage, $offset]
    )->fetchAll();
} catch (\Throwable $e) {
    $convs = [];
}

ob_start();
?>
<?php if (isset($message)): ?>
    <div class="alert alert-success alert-dismissible fade show"><?php echo $message; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="table-container">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5>💬 لیست مکالمات (<?php echo number_format($total); ?>)</h5>
    </div>
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>ID</th>
                <th>کاربر</th>
                <th>مدل</th>
                <th>عنوان</th>
                <th>وضعیت</th>
                <th>تاریخ</th>
                <th>عملیات</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($convs)): ?>
                <tr><td colspan="7" class="text-center text-muted">مکالمه‌ای یافت نشد.</td></tr>
            <?php else: ?>
                <?php foreach ($convs as $c): ?>
                <tr>
                    <td><?php echo $c['id']; ?></td>
                    <td>
                        <a href="user_detail.php?id=<?php echo $c['user_id']; ?>" class="text-decoration-none">
                            <?php 
                            $displayName = '';
                            if (!empty($c['first_name'])) {
                                $displayName = $c['first_name'] . (!empty($c['last_name']) ? ' ' . $c['last_name'] : '');
                            } elseif (!empty($c['username'])) {
                                $displayName = $c['username'];
                            } else {
                                $displayName = 'User#' . $c['user_id'];
                            }
                            echo htmlspecialchars($displayName);
                            ?>
                        </a>
                    </td>
                    <td><code style="font-size:0.8rem;"><?php echo htmlspecialchars(mb_substr($c['model'] ?? '?', 0, 30)); ?></code></td>
                    <td style="max-width:150px; overflow:hidden; text-overflow:ellipsis;">
                        <?php echo htmlspecialchars(mb_substr($c['title'] ?? 'بدون عنوان', 0, 40)); ?>
                    </td>
                    <td>
                        <?php if (($c['status'] ?? '') === 'active'): ?>
                            <span class="badge-active">✅ فعال</span>
                        <?php else: ?>
                            <span class="badge-inactive">📁 بایگانی</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:0.8rem;"><?php echo substr($c['created_at'] ?? '', 0, 16); ?></td>
                    <td>
                        <button type="button" class="btn btn-sm btn-outline-info" onclick="showConversation(<?php echo $c['id']; ?>, '<?php echo htmlspecialchars(str_replace("'", "\\'", $c['title'] ?? 'بدون عنوان'), ENT_QUOTES); ?>')">
                            👁 مشاهده
                        </button>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('حذف شود؟');">
                            <input type="hidden" name="delete_conv" value="<?php echo $c['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">🗑️</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if ($totalPages > 1): ?>
    <nav>
        <ul class="pagination pagination-sm justify-content-center">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                    <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                </li>
            <?php endfor; ?>
        </ul>
    </nav>
    <?php endif; ?>
</div>

<!-- Modal for viewing conversation messages -->
<div class="modal fade" id="conversationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="conversationModalTitle">💬 مشاهده مکالمه</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="conversationMessages" style="max-height:70vh; overflow-y:auto; direction:ltr; text-align:left;">
                <div class="text-center text-muted py-4" id="conversationLoading">در حال بارگذاری...</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">بستن</button>
            </div>
        </div>
    </div>
</div>

<style>
.msg-bubble {
    max-width:85%;
    padding:10px 14px;
    border-radius:16px;
    margin-bottom:10px;
    word-wrap:break-word;
    white-space:pre-wrap;
    font-size:0.9rem;
    line-height:1.6;
    box-shadow:0 1px 3px rgba(0,0,0,0.08);
}
.msg-user {
    background:#d1e7ff;
    align-self:flex-end;
    margin-left:auto;
    border-bottom-right-radius:4px;
}
.msg-assistant {
    background:#f0f0f0;
    align-self:flex-start;
    margin-right:auto;
    border-bottom-left-radius:4px;
}
.msg-system {
    background:#fff3cd;
    align-self:center;
    margin:0 auto;
    border-radius:8px;
    font-style:italic;
    font-size:0.85rem;
}
.msg-meta {
    font-size:0.7rem;
    color:#888;
    margin-top:4px;
}
.msg-file {
    background:#e8f5e9;
    border:1px dashed #81c784;
    padding:8px 12px;
    border-radius:8px;
    margin-bottom:10px;
}
</style>

<script>
function showConversation(convId, title) {
    document.getElementById('conversationModalTitle').textContent = '💬 مکالمه #' + convId + ' - ' + title;
    document.getElementById('conversationMessages').innerHTML = '<div class="text-center text-muted py-4">در حال بارگذاری...</div>';
    
    var modal = new bootstrap.Modal(document.getElementById('conversationModal'));
    modal.show();
    
    fetch('chat_list.php?action=get_messages&conv_id=' + convId)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var container = document.getElementById('conversationMessages');
            if (data.error) {
                container.innerHTML = '<div class="text-center text-danger py-4">خطا: ' + data.error + '</div>';
                return;
            }
            if (!data || data.length === 0) {
                container.innerHTML = '<div class="text-center text-muted py-4">پیامی در این مکالمه وجود ندارد.</div>';
                return;
            }
            var html = '<div style="display:flex;flex-direction:column;">';
            for (var i = 0; i < data.length; i++) {
                var m = data[i];
                var role = m.role || 'unknown';
                var content = m.content || '';
                var createdAt = m.created_at || '';
                var fileType = m.file_type || '';
                var fileContent = m.file_content || '';
                var inputChars = m.input_chars || 0;
                var outputChars = m.output_chars || 0;
                
                var bubbleClass = 'msg-bubble';
                var roleLabel = '';
                if (role === 'user') {
                    bubbleClass += ' msg-user';
                    roleLabel = '👤 کاربر';
                } else if (role === 'assistant') {
                    bubbleClass += ' msg-assistant';
                    roleLabel = '🤖 دستیار';
                } else {
                    bubbleClass += ' msg-system';
                    roleLabel = '⚙️ سیستم';
                }
                
                html += '<div class="' + bubbleClass + '">';
                html += '<div style="font-size:0.75rem;color:#666;margin-bottom:4px;">' + roleLabel + '</div>';
                
                // Escape HTML entities
                var escapedContent = content
                    .replace(/&/g, '&')
                    .replace(/</g, '<')
                    .replace(/>/g, '>');
                html += '<div>' + escapedContent + '</div>';
                
                // Show file attachment if exists
                if (fileType && fileContent) {
                    var escapedFile = fileContent
                        .replace(/&/g, '&')
                        .replace(/</g, '<')
                        .replace(/>/g, '>');
                    html += '<div class="msg-file">📎 ' + fileType + ': ' + escapedFile + '</div>';
                }
                
                // Cost info
                if (inputChars > 0 || outputChars > 0) {
                    html += '<div class="msg-meta">📊 ' + inputChars + ' ورودی / ' + outputChars + ' خروجی کاراکتر</div>';
                }
                if (createdAt) {
                    html += '<div class="msg-meta">🕐 ' + createdAt + '</div>';
                }
                html += '</div>';
            }
            html += '</div>';
            container.innerHTML = html;
        })
        .catch(function(err) {
            document.getElementById('conversationMessages').innerHTML = '<div class="text-center text-danger py-4">خطا در دریافت پیام‌ها</div>';
        });
}
</script>

<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../../views/admin/layout.php';