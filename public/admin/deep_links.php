<?php
/**
 * Deep Link Management — پنل مدیریت دیپ لینک‌ها و کمپین‌های ورودی
 */
$pageTitle = 'مدیریت دیپ لینک‌ها';
$activeMenu = 'deep_links';
require_once __DIR__ . '/../../init.php';

use Database\Database;
use Core\Config;

// Session + login check
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$db = Database::getInstance();
$message = '';
$messageType = 'success';

// ─── Handle actions ───
$action = $_GET['action'] ?? '';
$campaignId = (int)($_GET['id'] ?? 0);

if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = trim($_POST['payload'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $welcomeText = trim($_POST['welcome_text'] ?? '');

    if ($payload && $title) {
        try {
            $db->query(
                "INSERT INTO deep_link_campaigns (payload, title, welcome_text) VALUES (?, ?, ?)",
                [$payload, $title, $welcomeText ?: null]
            );
            $message = "✅ کمپین «{$title}» با موفقیت ایجاد شد.";
        } catch (\Exception $e) {
            $message = "❌ خطا: " . $e->getMessage();
            $messageType = 'danger';
        }
    } else {
        $message = "❌ لطفاً payload و عنوان را وارد کنید.";
        $messageType = 'danger';
    }
}

if ($action === 'toggle' && $campaignId) {
    try {
        $row = $db->query("SELECT id, is_active FROM deep_link_campaigns WHERE id = ?", [$campaignId])->fetch();
        if ($row) {
            $newStatus = $row['is_active'] ? 0 : 1;
            $db->query("UPDATE deep_link_campaigns SET is_active = ? WHERE id = ?", [$newStatus, $campaignId]);
            $message = $newStatus ? "✅ کمپین فعال شد." : "⏸️ کمپین غیرفعال شد.";
        }
    } catch (\Exception $e) {
        $message = "❌ خطا: " . $e->getMessage();
        $messageType = 'danger';
    }
}

if ($action === 'edit' && $campaignId && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $payload = trim($_POST['payload'] ?? '');
    $welcomeText = trim($_POST['welcome_text'] ?? '');
    try {
        $db->query(
            "UPDATE deep_link_campaigns SET title = ?, payload = ?, welcome_text = ? WHERE id = ?",
            [$title, $payload, $welcomeText ?: null, $campaignId]
        );
        $message = "✅ کمپین ویرایش شد.";
    } catch (\Exception $e) {
        $message = "❌ خطا: " . $e->getMessage();
        $messageType = 'danger';
    }
}

if ($action === 'delete' && $campaignId) {
    try {
        $db->query("DELETE FROM deep_link_entries WHERE campaign_id = ?", [$campaignId]);
        $db->query("DELETE FROM deep_link_campaigns WHERE id = ?", [$campaignId]);
        $message = "🗑️ کمپین و تمام ورودی‌های آن حذف شد.";
    } catch (\Exception $e) {
        $message = "❌ خطا: " . $e->getMessage();
        $messageType = 'danger';
    }
}

// ─── Fetch data ───
$campaigns = $db->query("SELECT * FROM deep_link_campaigns ORDER BY created_at DESC")->fetchAll();

// ─── Statistics ───
$totalEntries = $db->query("SELECT COUNT(*) as cnt FROM deep_link_entries")->fetch()['cnt'];
$totalRegistered = $db->query("SELECT COUNT(*) as cnt FROM deep_link_entries WHERE is_registered = 1")->fetch()['cnt'];
$totalUnregistered = $totalEntries - $totalRegistered;

// ─── Entries per campaign ───
$entriesPerCampaign = [];
if (!empty($campaigns)) {
    foreach ($campaigns as $c) {
        $stats = $db->query(
            "SELECT 
                COUNT(*) as total, 
                SUM(is_registered) as reg,
                SUM(CASE WHEN u.created_at < e.clicked_at THEN 1 ELSE 0 END) as returning 
             FROM deep_link_entries e 
             LEFT JOIN users u ON u.id = e.registered_user_id 
             WHERE e.campaign_id = ?",
            [$c['id']]
        )->fetch();
        $entriesPerCampaign[$c['id']] = [
            'total' => (int)$stats['total'],
            'registered' => (int)($stats['reg'] ?? 0),
            'returning' => (int)($stats['returning'] ?? 0),
        ];
    }
}

$pageContent = <<<HTML
<div class="page-title">
    <h3>🔗 مدیریت دیپ لینک‌ها (کمپین‌های ورودی)</h3>
    <p class="text-muted">لینک‌های مخصوص کمپین‌های تبلیغاتی، اینفلوئنسرها و کانال‌ها</p>
</div>

HTML;

if ($message) {
    $pageContent .= <<<HTML
<div class="alert alert-{$messageType} alert-dismissible fade show">{$message}
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
HTML;
}

// ─── Stats Cards ───
$pageContent .= <<<HTML
<div class="row mb-4">
    <div class="col-md-4">
        <div class="stat-card" style="border-right-color: #0984e3;">
            <div class="stat-icon">👥</div>
            <div class="stat-number">{$totalEntries}</div>
            <div class="stat-label">کل ورودی‌ها</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card" style="border-right-color: #00b894;">
            <div class="stat-icon">✅</div>
            <div class="stat-number">{$totalRegistered}</div>
            <div class="stat-label">ثبت‌نام کرده</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card" style="border-right-color: #d63031;">
            <div class="stat-icon">⏳</div>
            <div class="stat-number">{$totalUnregistered}</div>
            <div class="stat-label">هنوز ثبت‌نام نکرده</div>
        </div>
    </div>
</div>
HTML;

// ─── Campaigns List ───
$pageContent .= <<<HTML
<div class="table-container">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">📋 لیست کمپین‌ها</h5>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addModal">
            <i class="bi bi-plus-circle"></i> کمپین جدید
        </button>
    </div>
    <div class="table-responsive">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Payload</th>
                    <th>عنوان</th>
                    <th>وضعیت</th>
                    <th>ورودی‌ها</th>
                    <th>جدید</th>
                    <th>تکراری (قبلاً عضو)</th>
                    <th>نرخ تبدیل</th>
                    <th>لینک نمونه</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
HTML;

if (empty($campaigns)) {
    $pageContent .= '<tr><td colspan="10" class="text-center text-muted">هیچ کمپینی تعریف نشده است. اولین کمپین را ایجاد کنید.</td></tr>';
} else {
    foreach ($campaigns as $c) {
        $badge = $c['is_active'] ? 'badge-active' : 'badge-inactive';
        $statusText = $c['is_active'] ? 'فعال ✅' : 'غیرفعال ⏸️';
        $toggleUrl = "?action=toggle&id={$c['id']}";
        $deleteUrl = "?action=delete&id={$c['id']}";
        $editUrl = "?action=edit_form&id={$c['id']}";
        $sampleLink = "https://ble.ir/mobixbot?start={$c['payload']}";
        
        $entryStats = $entriesPerCampaign[$c['id']] ?? ['total' => 0, 'registered' => 0, 'returning' => 0];
        $conversionRate = $entryStats['total'] > 0 ? round(($entryStats['registered'] / $entryStats['total']) * 100) . '%' : '—';
        
        $shortWelcome = mb_substr($c['welcome_text'] ?? 'پیش‌فرض', 0, 40);

        $pageContent .= <<<HTML
                <tr>
                    <td>{$c['id']}</td>
                    <td><code>{$c['payload']}</code></td>
                    <td><strong>{$c['title']}</strong></td>
                    <td><span class="{$badge}">{$statusText}</span></td>
                    <td>{$entryStats['total']}</td>
                    <td>{$entryStats['registered']}</td>
                    <td>{$entryStats['returning']}</td>
                    <td>{$conversionRate}</td>
                    <td><a href="{$sampleLink}" target="_blank" class="text-truncate d-inline-block" style="max-width:150px;" title="{$sampleLink}">{$sampleLink}</a></td>
                    <td>
                        <a href="{$deleteUrl}" class="btn btn-danger btn-sm-icon" onclick="return confirm('حذف شود؟')" title="حذف">🗑️</a>
                        <a href="{$editUrl}" class="btn btn-warning btn-sm-icon" title="ویرایش متن خوش‌آمدگویی">✏️</a>
                    </td>
                </tr>
HTML;
    }
}

$pageContent .= <<<HTML
            </tbody>
        </table>
    </div>
</div>
HTML;

// ─── Recent entries ───
$recentEntries = $db->query(
    "SELECT e.*, c.title as campaign_title FROM deep_link_entries e
     LEFT JOIN deep_link_campaigns c ON e.campaign_id = c.id
     ORDER BY e.clicked_at DESC LIMIT 20"
)->fetchAll();

$pageContent .= <<<HTML
<div class="table-container mt-4">
    <h5>🕐 آخرین ورودی‌ها (۲۰ عدد آخر)</h5>
    <div class="table-responsive">
        <table class="table table-sm table-hover">
            <thead>
                <tr>
                    <th>زمان</th>
                    <th>Payload</th>
                    <th>کمپین</th>
                    <th>کاربر بله</th>
                    <th>نام</th>
                    <th>ثبت‌نام</th>
                    <th>زمان ثبت‌نام</th>
                </tr>
            </thead>
            <tbody>
HTML;

if (empty($recentEntries)) {
    $pageContent .= '<tr><td colspan="7" class="text-center text-muted">هنوز ورودی‌ای ثبت نشده است.</td></tr>';
} else {
    foreach ($recentEntries as $e) {
        $regBadge = $e['is_registered'] ? '<span class="badge bg-success">✅ بله</span>' : '<span class="badge bg-warning">⏳ خیر</span>';
        $campaignName = $e['campaign_title'] ?? '<em>بدون کمپین</em>';
        $registeredAt = $e['registered_at'] ?? '—';
        
        $pageContent .= <<<HTML
                <tr>
                    <td style="direction:ltr;font-size:0.85rem;">{$e['clicked_at']}</td>
                    <td><code>{$e['payload']}</code></td>
                    <td>{$campaignName}</td>
                    <td>{$e['bale_user_id']}</td>
                    <td>{$e['first_name']} @{$e['username']}</td>
                    <td>{$regBadge}</td>
                    <td style="direction:ltr;font-size:0.85rem;">{$registeredAt}</td>
                </tr>
HTML;
    }
}

$pageContent .= <<<HTML
            </tbody>
        </table>
    </div>
</div>

<!-- ─── Modal: Add Campaign ─── -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" action="?action=add">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">➕ کمپین جدید</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Payload (کلید یکتا، مثلاً instagram):</label>
                        <input type="text" name="payload" class="form-control" required pattern="[a-zA-Z0-9_-]+" 
                               placeholder="instagram" dir="ltr">
                        <div class="form-text">فقط حروف انگلیسی، اعداد، زیرخط و خط تیره مجاز است.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">عنوان کمپین:</label>
                        <input type="text" name="title" class="form-control" required placeholder="مثلاً اینستاگرام">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">متن خوش‌آمدگویی (اختیاری):</label>
                        <textarea name="welcome_text" class="form-control" rows="4" placeholder="اگر خالی بماند، متن پیش‌فرض نمایش داده می‌شود."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
                    <button type="submit" class="btn btn-primary">ایجاد کمپین</button>
                </div>
            </div>
        </form>
    </div>
</div>
HTML;

// ─── Edit Modal ───
if ($action === 'edit_form' && $campaignId) {
    $editRow = $db->query("SELECT * FROM deep_link_campaigns WHERE id = ?", [$campaignId])->fetch();
    if ($editRow) {
        $pageContent .= <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function() {
    var editModal = new bootstrap.Modal(document.getElementById('editModal'));
    editModal.show();
});
</script>
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" action="?action=edit&id={$campaignId}">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">✏️ ویرایش کمپین</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Payload:</label>
                        <input type="text" name="payload" class="form-control" required value="{$editRow['payload']}">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">عنوان:</label>
                        <input type="text" name="title" class="form-control" required value="{$editRow['title']}">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">متن خوش‌آمدگویی:</label>
                        <textarea name="welcome_text" class="form-control" rows="4">{$editRow['welcome_text']}</textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <a href="?" class="btn btn-secondary">انصراف</a>
                    <button type="submit" class="btn btn-primary">ذخیره</button>
                </div>
            </div>
        </form>
    </div>
</div>
HTML;
    }
}

require_once __DIR__ . '/../../views/admin/layout.php';