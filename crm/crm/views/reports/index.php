<div class="row g-4">
    <div class="col-md-3">
        <div class="stat-card">
            <div class="icon" style="background:#e8f5e9;color:#10B981;"><i class="bi bi-briefcase"></i></div>
            <h5 style="color:#666;font-size:13px;">کل معاملات</h5>
            <h3 style="font-weight:bold;"><?php echo number_format($totalDeals->count ?? 0); ?></h3>
            <small style="color:#999;"><?php echo number_format($totalDeals->total ?? 0); ?> ریال</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card">
            <div class="icon" style="background:#fce4ec;color:#EF4444;"><i class="bi bi-check-circle"></i></div>
            <h5 style="color:#666;font-size:13px;">موفق</h5>
            <h3 style="font-weight:bold;"><?php echo number_format($wonDeals->count ?? 0); ?></h3>
            <small style="color:#999;"><?php echo number_format($wonDeals->total ?? 0); ?> ریال</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card">
            <div class="icon" style="background:#fff3e0;color:#F59E0B;"><i class="bi bi-arrow-up-right"></i></div>
            <h5 style="color:#666;font-size:13px;">نرخ تبدیل</h5>
            <h3 style="font-weight:bold;"><?php echo $conversionRate; ?>%</h3>
            <small style="color:#999;">معاملات موفق به کل</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card">
            <div class="icon" style="background:#e3f2fd;color:#3B82F6;"><i class="bi bi-person"></i></div>
            <h5 style="color:#666;font-size:13px;">کاربران برتر</h5>
            <h3 style="font-weight:bold;"><?php echo count($topUsers); ?></h3>
            <small style="color:#999;">کاربر فعال</small>
        </div>
    </div>

    <div class="col-md-6">
        <div class="table-container">
            <h5 style="font-weight:bold;margin-bottom:15px;">فروش ماهانه</h5>
            <div class="table-responsive"><table class="table table-sm">
                <thead><tr><th>ماه</th><th>تعداد</th><th>مبلغ</th></tr></thead>
                <tbody><?php foreach ($monthlySales as $m): ?><tr><td><?php echo $m->month; ?></td><td><?php echo $m->deals_count; ?></td><td><strong><?php echo number_format($m->total); ?></strong></td></tr><?php endforeach; ?></tbody>
            </table></div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="table-container">
            <h5 style="font-weight:bold;margin-bottom:15px;">فروش بر اساس پایپ لاین</h5>
            <div class="table-responsive"><table class="table table-sm">
                <thead><tr><th>پایپ لاین</th><th>تعداد</th><th>مبلغ</th></tr></thead>
                <tbody><?php foreach ($salesByPipeline as $s): ?><tr><td><?php echo htmlspecialchars($s->name); ?></td><td><?php echo $s->deals_count; ?></td><td><strong><?php echo number_format($s->total); ?></strong></td></tr><?php endforeach; ?></tbody>
            </table></div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="table-container">
            <h5 style="font-weight:bold;margin-bottom:15px;">کاربران برتر فروش</h5>
            <div class="table-responsive"><table class="table table-sm">
                <thead><tr><th>کاربر</th><th>تعداد</th><th>مبلغ</th></tr></thead>
                <tbody><?php foreach ($topUsers as $t): ?><tr><td><?php echo htmlspecialchars($t->full_name); ?></td><td><?php echo $t->deals_count; ?></td><td><strong><?php echo number_format($t->total); ?></strong></td></tr><?php endforeach; ?></tbody>
            </table></div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="table-container">
            <h5 style="font-weight:bold;margin-bottom:15px;">دلایل عدم موفقیت</h5>
            <div class="table-responsive"><table class="table table-sm">
                <thead><tr><th>دلیل</th><th>تعداد</th><th>مبلغ از دست رفته</th></tr></thead>
                <tbody><?php foreach ($lostReasons as $l): ?><tr><td><?php echo htmlspecialchars($l->lost_reason); ?></td><td><?php echo $l->count; ?></td><td><strong><?php echo number_format($l->total); ?></strong></td></tr><?php endforeach; ?></tbody>
            </table></div>
        </div>
    </div>
</div>