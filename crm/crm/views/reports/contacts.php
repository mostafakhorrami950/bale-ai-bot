<div class="row g-4">
    <div class="col-md-4">
        <div class="table-container">
            <h6 style="font-weight:bold;margin-bottom:15px;">منابع آشنایی</h6>
            <?php foreach ($sources as $s): ?>
            <div class="d-flex justify-content-between mb-2 pb-2" style="border-bottom:1px solid #eee;">
                <span><?php echo htmlspecialchars($s->source ?: 'نامشخص'); ?></span>
                <span class="badge bg-info"><?php echo $s->count; ?> نفر</span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="col-md-8">
        <div class="table-container">
            <h6 style="font-weight:bold;margin-bottom:15px;">لیست مخاطبان با ارزش</h6>
            <div class="table-responsive"><table class="table table-sm">
                <thead><tr><th>نام</th><th>تلفن</th><th>معاملات</th><th>موفق</th><th>مجموع خرید</th><th>آخرین معامله</th></tr></thead>
                <tbody><?php foreach ($contacts as $c): ?><tr><td><?php echo htmlspecialchars($c->full_name); ?></td><td><?php echo htmlspecialchars($c->phone ?? '-'); ?></td><td><?php echo $c->deals_count; ?></td><td><?php echo $c->won_deals; ?></td><td><strong><?php echo number_format($c->total_purchases); ?></strong></td><td><small><?php echo $c->last_deal_date ? date('Y/m/d', strtotime($c->last_deal_date)) : '-'; ?></small></td></tr><?php endforeach; ?></tbody>
            </table></div>
        </div>
    </div>
</div>