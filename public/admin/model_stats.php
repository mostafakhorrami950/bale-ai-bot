<?php
require_once __DIR__ . '/../../init.php';

$pageTitle = 'آمار مدل‌ها';
$activeMenu = 'model_stats';

use Database\Database;

$db = Database::getInstance();

// ─── Most used models by total tokens (input + output) ───
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

// ─── Most used models by total USD cost ───
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

// ─── Most used models by total credits ───
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

// ─── Overall totals ───
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

ob_start();
?>
<div class="row mb-4">
    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-icon">💵</div>
            <div class="stat-number">$<?php echo number_format((float)($overall['grand_total_usd'] ?? 0), 6); ?></div>
            <div class="stat-label">مجموع هزینه دلاری</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card" style="border-right-color:#00b894;">
            <div class="stat-icon">🔤</div>
            <div class="stat-number"><?php echo number_format((int)($overall['grand_total_tokens'] ?? 0)); ?></div>
            <div class="stat-label">مجموع توکن‌ها</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card" style="border-right-color:#fdcb6e;">
            <div class="stat-icon">💎</div>
            <div class="stat-number"><?php echo number_format((float)($overall['grand_total_credits'] ?? 0), 4); ?></div>
            <div class="stat-label">مجموع کردیت مصرفی</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card" style="border-right-color:#e17055;">
            <div class="stat-icon">📨</div>
            <div class="stat-number"><?php echo number_format((int)($overall['grand_total_messages'] ?? 0)); ?></div>
            <div class="stat-label">مجموع پیام‌ها</div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-4">
        <div class="table-container">
            <h5>🔝 پرمصرف‌ترین مدل‌ها بر اساس توکن</h5>
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
            <h5>🔝 پرمصرف‌ترین مدل‌ها بر اساس هزینه ($)</h5>
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
            <h5>🔝 پرمصرف‌ترین مدل‌ها بر اساس کردیت</h5>
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

<p class="mt-3">
    <a href="dashboard.php" class="btn btn-outline-secondary">← بازگشت به داشبورد</a>
</p>

<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../../views/admin/layout.php';