<?php
require_once __DIR__ . '/../../init.php';

$pageTitle = 'آمار مدل‌ها';
$activeMenu = 'model_stats';

use Database\Database;

$db = Database::getInstance();

// ─── Most used text models by total tokens (input + output) ───
$byTokens = [];
try {
    $byTokens = $db->query("
        SELECT 
            cm.model_name,
            COUNT(*) as message_count,
            COALESCE(SUM(cm.input_tokens), 0) as total_input_tokens,
            COALESCE(SUM(cm.output_tokens), 0) as total_output_tokens,
            COALESCE(SUM(cm.input_tokens + cm.output_tokens), 0) as total_tokens,
            COALESCE(SUM(cm.actual_cost_usd), 0) as total_cost_usd,
            COALESCE(SUM(cm.cost_input_credits + cm.cost_output_credits), 0) as total_credits
        FROM chat_messages cm
        WHERE cm.role = 'assistant' AND cm.model_name IS NOT NULL AND cm.model_name != ''
        GROUP BY cm.model_name
        ORDER BY total_tokens DESC
        LIMIT 20
    ")->fetchAll();
} catch (\Throwable $e) {
    $byTokens = [];
}

// ─── Most used text models by total USD cost ───
$byUsd = [];
try {
    $byUsd = $db->query("
        SELECT 
            cm.model_name,
            COUNT(*) as message_count,
            COALESCE(SUM(cm.actual_cost_usd), 0) as total_cost_usd,
            COALESCE(SUM(cm.input_tokens + cm.output_tokens), 0) as total_tokens,
            COALESCE(SUM(cm.cost_input_credits + cm.cost_output_credits), 0) as total_credits
        FROM chat_messages cm
        WHERE cm.role = 'assistant' AND cm.model_name IS NOT NULL AND cm.model_name != ''
        GROUP BY cm.model_name
        ORDER BY total_cost_usd DESC
        LIMIT 20
    ")->fetchAll();
} catch (\Throwable $e) {
    $byUsd = [];
}

// ─── Most used text models by total credits ───
$byCredits = [];
try {
    $byCredits = $db->query("
        SELECT 
            cm.model_name,
            COUNT(*) as message_count,
            COALESCE(SUM(cm.cost_input_credits + cm.cost_output_credits), 0) as total_credits,
            COALESCE(SUM(cm.actual_cost_usd), 0) as total_cost_usd,
            COALESCE(SUM(cm.input_tokens + cm.output_tokens), 0) as total_tokens
        FROM chat_messages cm
        WHERE cm.role = 'assistant' AND cm.model_name IS NOT NULL AND cm.model_name != ''
        GROUP BY cm.model_name
        ORDER BY total_credits DESC
        LIMIT 20
    ")->fetchAll();
} catch (\Throwable $e) {
    $byCredits = [];
}

// ─── Most used image/edit models (from ai_requests) ───
$imageModelStats = [];
try {
    $imageModelStats = $db->query("
        SELECT 
            ar.model_name,
            COUNT(*) as request_count,
            SUM(CASE WHEN ar.status = 'success' THEN 1 ELSE 0 END) as success_count,
            SUM(CASE WHEN ar.status = 'failed' THEN 1 ELSE 0 END) as failed_count,
            COALESCE(SUM(ar.actual_cost_usd), 0) as total_cost_usd,
            COALESCE(SUM(ar.cost_charged), 0) as total_cost_credits,
            COALESCE(SUM(ar.input_chars), 0) as total_input_chars,
            COALESCE(SUM(ar.output_chars), 0) as total_output_chars,
            ar.image_type
        FROM ai_requests ar
        WHERE ar.model_name IS NOT NULL AND ar.model_name != ''
        GROUP BY ar.model_name, ar.image_type
        ORDER BY total_cost_usd DESC
        LIMIT 20
    ")->fetchAll();
} catch (\Throwable $e) {
    $imageModelStats = [];
}

// ─── Grouped totals ───
$imageTotals = [];
try {
    $imageTotals = $db->query("
        SELECT
            COALESCE(SUM(actual_cost_usd), 0) as total_usd,
            COALESCE(SUM(cost_charged), 0) as total_credits,
            COUNT(*) as total_requests,
            SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success_count,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count
        FROM ai_requests
        WHERE model_name IS NOT NULL AND model_name != ''
    ")->fetch();
} catch (\Throwable $e) {
    $imageTotals = ['total_usd' => 0, 'total_credits' => 0, 'total_requests' => 0, 'success_count' => 0, 'failed_count' => 0];
}

// ─── Overall totals (text + image combined) ───
$overall = [];
try {
    $overall = $db->query("
        SELECT 
            COALESCE(SUM(actual_cost_usd), 0) as grand_total_usd,
            COALESCE(SUM(input_tokens + output_tokens), 0) as grand_total_tokens,
            COALESCE(SUM(cost_input_credits + cost_output_credits), 0) as grand_total_credits,
            COUNT(*) as grand_total_messages
        FROM chat_messages
        WHERE role = 'assistant' AND model_name IS NOT NULL AND model_name != ''
    ")->fetch();
} catch (\Throwable $e) {
    $overall = ['grand_total_usd' => 0, 'grand_total_tokens' => 0, 'grand_total_credits' => 0, 'grand_total_messages' => 0];
}

// Add image totals to overall
$combinedUsd = (float)($overall['grand_total_usd'] ?? 0) + (float)($imageTotals['total_usd'] ?? 0);
$combinedCredits = (float)($overall['grand_total_credits'] ?? 0) + (float)($imageTotals['total_credits'] ?? 0);
$combinedMessages = (int)($overall['grand_total_messages'] ?? 0) + (int)($imageTotals['total_requests'] ?? 0);

ob_start();
?>
<div class="row mb-4">
    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-icon">💵</div>
            <div class="stat-number">$<?php echo number_format($combinedUsd, 6); ?></div>
            <div class="stat-label">مجموع هزینه دلاری (همه)</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card" style="border-right-color:#00b894;">
            <div class="stat-icon">💎</div>
            <div class="stat-number"><?php echo number_format($combinedCredits, 4); ?></div>
            <div class="stat-label">مجموع کردیت مصرفی (همه)</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card" style="border-right-color:#fdcb6e;">
            <div class="stat-icon">📨</div>
            <div class="stat-number"><?php echo number_format($combinedMessages); ?></div>
            <div class="stat-label">مجموع درخواست‌ها (همه)</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card" style="border-right-color:#e17055;">
            <div class="stat-icon">📊</div>
            <div class="stat-number"><?php echo number_format((int)($imageTotals['success_count'] ?? 0) + (int)($imageTotals['failed_count'] ?? 0)); ?></div>
            <div class="stat-label">درخواست‌های تصویری</div>
        </div>
    </div>
</div>

<!-- Most used models stats for TEXT models -->
<div class="row mt-4">
    <div class="col-md-4">
        <div class="table-container">
            <h5>🔝 پرمصرف‌ترین مدل‌های متنی بر اساس توکن</h5>
            <table class="table table-sm table-hover">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>مدل</th>
                        <th>توکن ورودی</th>
                        <th>توکن خروجی</th>
                        <th>مجموع توکن</th>
                        <th>هزینه ($)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($byTokens)): ?>
                        <tr><td colspan="6" class="text-muted text-center">داده‌ای موجود نیست.</td></tr>
                    <?php else: ?>
                        <?php $i = 1; foreach ($byTokens as $r): ?>
                        <tr>
                            <td><?php echo $i++; ?></td>
                            <td><strong><?php echo htmlspecialchars($r['model_name']); ?></strong></td>
                            <td><?php echo number_format((int)$r['total_input_tokens']); ?></td>
                            <td><?php echo number_format((int)$r['total_output_tokens']); ?></td>
                            <td><strong><?php echo number_format((int)$r['total_tokens']); ?></strong></td>
                            <td>$<?php echo number_format((float)$r['total_cost_usd'], 8); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="col-md-4">
        <div class="table-container">
            <h5>🔝 پرمصرف‌ترین مدل‌های متنی بر اساس هزینه ($)</h5>
            <table class="table table-sm table-hover">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>مدل</th>
                        <th>هزینه ($)</th>
                        <th>مجموع توکن</th>
                        <th>کردیت</th>
                        <th>پیام‌ها</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($byUsd)): ?>
                        <tr><td colspan="6" class="text-muted text-center">داده‌ای موجود نیست.</td></tr>
                    <?php else: ?>
                        <?php $i = 1; foreach ($byUsd as $r): ?>
                        <tr>
                            <td><?php echo $i++; ?></td>
                            <td><strong><?php echo htmlspecialchars($r['model_name']); ?></strong></td>
                            <td><strong class="text-info">$<?php echo number_format((float)$r['total_cost_usd'], 8); ?></strong></td>
                            <td><?php echo number_format((int)$r['total_tokens']); ?></td>
                            <td><?php echo number_format((float)$r['total_credits'], 4); ?></td>
                            <td><?php echo number_format((int)$r['message_count']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="col-md-4">
        <div class="table-container">
            <h5>🔝 پرمصرف‌ترین مدل‌های متنی بر اساس کردیت</h5>
            <table class="table table-sm table-hover">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>مدل</th>
                        <th>کردیت</th>
                        <th>هزینه ($)</th>
                        <th>مجموع توکن</th>
                        <th>پیام‌ها</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($byCredits)): ?>
                        <tr><td colspan="6" class="text-muted text-center">داده‌ای موجود نیست.</td></tr>
                    <?php else: ?>
                        <?php $i = 1; foreach ($byCredits as $r): ?>
                        <tr>
                            <td><?php echo $i++; ?></td>
                            <td><strong><?php echo htmlspecialchars($r['model_name']); ?></strong></td>
                            <td><strong class="text-warning"><?php echo number_format((float)$r['total_credits'], 4); ?></strong></td>
                            <td>$<?php echo number_format((float)$r['total_cost_usd'], 8); ?></td>
                            <td><?php echo number_format((int)$r['total_tokens']); ?></td>
                            <td><?php echo number_format((int)$r['message_count']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Image/Edit model stats section -->
<div class="row mt-4">
    <div class="col-12">
        <div class="table-container">
            <h5>🎨 آمار مدل‌های تصویری (تولید و ویرایش)</h5>
            <div class="row mb-3">
                <div class="col-md-3">
                    <div class="stat-card-small">
                        <div class="small-label">مجموع هزینه تصاویر ($)</div>
                        <div class="small-value">$<?php echo number_format((float)($imageTotals['total_usd'] ?? 0), 8); ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card-small">
                        <div class="small-label">مجموع کردیت تصاویر</div>
                        <div class="small-value"><?php echo number_format((float)($imageTotals['total_credits'] ?? 0), 4); ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card-small">
                        <div class="small-label">موفق</div>
                        <div class="small-value text-success"><?php echo number_format((int)($imageTotals['success_count'] ?? 0)); ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card-small">
                        <div class="small-label">ناموفق</div>
                        <div class="small-value text-danger"><?php echo number_format((int)($imageTotals['failed_count'] ?? 0)); ?></div>
                    </div>
                </div>
            </div>

            <table class="table table-sm table-hover">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>مدل</th>
                        <th>نوع</th>
                        <th>تعداد کل</th>
                        <th>موفق</th>
                        <th>ناموفق</th>
                        <th>کاراکتر ورودی</th>
                        <th>کاراکتر خروجی</th>
                        <th>هزینه ($)</th>
                        <th>کردیت</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($imageModelStats)): ?>
                        <tr><td colspan="10" class="text-muted text-center">داده‌ای موجود نیست.</td></tr>
                    <?php else: ?>
                        <?php $i = 1; foreach ($imageModelStats as $r): ?>
                        <?php
                            $typeLabel = $r['image_type'] === 'text2img' ? '🎨 متن به تصویر' : '🖼 تصویر به تصویر';
                            $successRate = $r['request_count'] > 0 ? round(($r['success_count'] / $r['request_count']) * 100, 1) : 0;
                        ?>
                        <tr>
                            <td><?php echo $i++; ?></td>
                            <td><strong><?php echo htmlspecialchars($r['model_name']); ?></strong></td>
                            <td><span class="badge bg-secondary"><?php echo $typeLabel; ?></span></td>
                            <td><?php echo number_format((int)$r['request_count']); ?></td>
                            <td><span class="text-success"><?php echo number_format((int)$r['success_count']); ?></span></td>
                            <td><span class="text-danger"><?php echo number_format((int)$r['failed_count']); ?></span></td>
                            <td><?php echo number_format((int)$r['total_input_chars']); ?></td>
                            <td><?php echo number_format((int)$r['total_output_chars']); ?></td>
                            <td><strong class="text-info">$<?php echo number_format((float)$r['total_cost_usd'], 8); ?></strong></td>
                            <td><strong class="text-warning"><?php echo number_format((float)$r['total_cost_credits'], 4); ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<p class="mt-3">
    <a href="dashboard.php" class="btn btn-outline-secondary">← بازگشت به داشبورد</a>
</p>

<style>
.stat-card-small {
    background: #f8f9fa;
    border-radius: 10px;
    padding: 10px 15px;
    text-align: center;
    border-right: 3px solid #0984e3;
    margin-bottom: 10px;
}
.stat-card-small .small-label {
    font-size: 0.8rem;
    color: #636e72;
}
.stat-card-small .small-value {
    font-size: 1.2rem;
    font-weight: bold;
}
</style>

<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../../views/admin/layout.php';