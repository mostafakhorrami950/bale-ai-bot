<?php
require_once __DIR__ . '/../../init.php';

$pageTitle = 'لاگ پرداخت‌ها';
$activeMenu = 'payment_logs';

use Database\Database;

$db = Database::getInstance();

// Filters
$statusFilter = $_GET['status'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

$where = [];
$params = [];

if (!empty($statusFilter)) {
    $where[] = "status = ?";
    $params[] = $statusFilter;
}
if (!empty($dateFrom)) {
    $where[] = "created_at >= ?";
    $params[] = $dateFrom . ' 00:00:00';
}
if (!empty($dateTo)) {
    $where[] = "created_at <= ?";
    $params[] = $dateTo . ' 23:59:59';
}

$whereClause = '';
if (!empty($where)) {
    $whereClause = 'WHERE ' . implode(' AND ', $where);
}

// Get request logs
$logs = $db->query(
    "SELECT * FROM payment_logs {$whereClause} ORDER BY created_at DESC LIMIT 100",
    $params
)->fetchAll();

// Get payment records
$payments = [];
try {
    $paymentsRaw = $db->query(
        "SELECT p.*, u.first_name, u.last_name FROM payments p LEFT JOIN users u ON p.user_id = u.id ORDER BY p.created_at DESC LIMIT 50"
    )->fetchAll();
} catch (\Throwable $e) {
    // If JOIN fails (missing columns), fallback to payments only
    \Database\Logger::warning('payment_logs user join failed: ' . $e->getMessage());
    $paymentsRaw = $db->query(
        "SELECT p.* FROM payments p ORDER BY p.created_at DESC LIMIT 50"
    )->fetchAll();
}

// If trackId is specified, show detail
$detailLog = null;
if (isset($_GET['detail'])) {
    $detailLog = $db->query(
        "SELECT * FROM payment_logs WHERE track_id = ? OR id = ? ORDER BY created_at DESC",
        [$_GET['detail'], (int) $_GET['detail']]
    )->fetchAll();
}

ob_start();
?>

<ul class="nav nav-tabs mb-3" id="logTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="api-tab" data-bs-toggle="tab" data-bs-target="#api" type="button">📡 لاگ API زیبال</button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="payments-tab" data-bs-toggle="tab" data-bs-target="#payments" type="button">💳 تراکنش‌ها</button>
    </li>
</ul>

<div class="tab-content">
    <!-- API Logs Tab -->
    <div class="tab-pane fade show active" id="api">
        <div class="table-container">
            <h5>📡 فیلتر لاگ API</h5>
            <form method="GET" class="row g-3 mb-3">
                <div class="col-md-3">
                    <select name="status" class="form-select">
                        <option value="">همه وضعیت‌ها</option>
                        <option value="success" <?php echo $statusFilter === 'success' ? 'selected' : ''; ?>>موفق</option>
                        <option value="failed" <?php echo $statusFilter === 'failed' ? 'selected' : ''; ?>>ناموفق</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <input type="date" name="date_from" class="form-control" value="<?php echo $dateFrom; ?>" placeholder="از تاریخ">
                </div>
                <div class="col-md-3">
                    <input type="date" name="date_to" class="form-control" value="<?php echo $dateTo; ?>" placeholder="تا تاریخ">
                </div>
                <div class="col-md-3">
                    <button class="btn btn-primary" type="submit">فیلتر</button>
                    <a href="payment_logs.php" class="btn btn-secondary">پاک کردن</a>
                </div>
            </form>

            <table class="table table-sm table-hover">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>کد پیگیری</th>
                        <th>عملیات</th>
                        <th>وضعیت</th>
                        <th>تاریخ</th>
                        <th>جزئیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr><td colspan="6" class="text-center text-muted">هیچ لاگی یافت نشد.</td></tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?php echo $log['id']; ?></td>
                            <td style="font-family:monospace; font-size:0.85rem;">
                                <?php echo htmlspecialchars($log['track_id'] ?? '-'); ?>
                            </td>
                            <td><?php echo $log['action'] === 'request' ? 'درخواست پرداخت' : 'تأیید پرداخت'; ?></td>
                            <td>
                                <?php if ($log['status'] === 'success'): ?>
                                    <span class="badge-active">✅ موفق</span>
                                <?php else: ?>
                                    <span class="badge-inactive">❌ ناموفق</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:0.85rem;"><?php echo $log['created_at']; ?></td>
                            <td>
                                <a href="payment_logs.php?detail=<?php echo urlencode($log['track_id'] ?? $log['id']); ?>" class="btn btn-sm btn-outline-info">📄</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($detailLog): ?>
        <div class="table-container">
            <h5>📄 جزئیات کامل لاگ</h5>
            <?php foreach ($detailLog as $dl): ?>
                <div class="card mb-3">
                    <div class="card-header">
                        <strong>عملیات:</strong> <?php echo $dl['action']; ?> |
                        <strong>وضعیت:</strong> <?php echo $dl['status']; ?> |
                        <strong>کد پیگیری:</strong> <?php echo htmlspecialchars($dl['track_id'] ?? '-'); ?>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6>📤 درخواست ارسالی:</h6>
                                <pre style="background:#f8f9fa; padding:10px; border-radius:5px; font-size:0.8rem; direction:ltr; text-align:left; max-height:300px; overflow:auto;"><?php echo json_encode(json_decode($dl['request_data'] ?? '{}', true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE); ?></pre>
                            </div>
                            <div class="col-md-6">
                                <h6>📥 پاسخ دریافتی:</h6>
                                <pre style="background:#f8f9fa; padding:10px; border-radius:5px; font-size:0.8rem; direction:ltr; text-align:left; max-height:300px; overflow:auto;"><?php echo json_encode(json_decode($dl['response_data'] ?? '{}', true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE); ?></pre>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            <a href="payment_logs.php" class="btn btn-secondary">بستن جزئیات</a>
        </div>
        <?php endif; ?>
    </div>

    <!-- Payments Tab -->
    <div class="tab-pane fade" id="payments">
        <div class="table-container">
            <h5>💳 تراکنش‌های پرداخت</h5>
            <table class="table table-sm table-hover">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>کاربر</th>
                        <th>کد پیگیری</th>
                        <th>مبلغ (ریال)</th>
                        <th>اعتبار</th>
                        <th>طرح</th>
                        <th>وضعیت</th>
                        <th>شماره مرجع</th>
                        <th>تاریخ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($paymentsRaw)): ?>
                        <tr><td colspan="9" class="text-center text-muted">تراکنشی یافت نشد.</td></tr>
                    <?php else: ?>
                        <?php foreach ($paymentsRaw as $p): ?>
                        <tr>
                            <td><?php echo $p['id']; ?></td>
                            <td><?php echo htmlspecialchars($p['first_name'] ?? '') . ' ' . htmlspecialchars($p['last_name'] ?? ''); ?></td>
                            <td style="font-family:monospace; font-size:0.8rem;"><?php echo htmlspecialchars($p['track_id']); ?></td>
                            <td><?php echo number_format($p['amount_rial']); ?></td>
                            <td><?php echo number_format($p['credits']); ?></td>
                            <td><?php echo htmlspecialchars($p['plan_id'] ?? '-'); ?></td>
                            <td>
                                <?php if ($p['status'] === 'verified'): ?>
                                    <span class="badge-active">✅ تأیید</span>
                                <?php elseif ($p['status'] === 'pending'): ?>
                                    <span class="badge bg-warning">⏳ در انتظار</span>
                                <?php else: ?>
                                    <span class="badge-inactive">❌ ناموفق</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-family:monospace; font-size:0.8rem;"><?php echo htmlspecialchars($p['ref_number'] ?? '-'); ?></td>
                            <td style="font-size:0.85rem;"><?php echo $p['created_at']; ?></td>
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