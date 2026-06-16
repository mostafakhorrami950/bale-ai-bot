<div class="filter-section">
    <form method="GET" class="row g-3">
        <div class="col-md-3"><label class="form-label">از تاریخ</label><input type="date" name="date_from" class="form-control" value="<?php echo $dateFrom; ?>"></div>
        <div class="col-md-3"><label class="form-label">تا تاریخ</label><input type="date" name="date_to" class="form-control" value="<?php echo $dateTo; ?>"></div>
        <div class="col-md-3"><label class="form-label">کاربر</label><select name="user_id" class="form-select"><option value="">همه</option><?php foreach ($users as $u): ?><option value="<?php echo $u->id; ?>" <?php echo $selectedUser == $u->id ? 'selected' : ''; ?>><?php echo htmlspecialchars($u->full_name); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label">&nbsp;</label><button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i> فیلتر</button></div>
    </form>
</div>
<div class="row g-4">
    <div class="col-md-4">
        <div class="table-container">
            <h6 style="font-weight:bold;margin-bottom:15px;">خلاصه فعالیت‌ها</h6>
            <?php foreach ($activitySummary as $as): ?>
            <div class="d-flex justify-content-between mb-2 pb-2" style="border-bottom:1px solid #eee;">
                <span><?php echo $as->type == 'call' ? 'تماس' : ($as->type == 'meeting' ? 'جلسه' : ($as->type == 'sms' ? 'پیامک' : 'یادداشت')); ?></span>
                <span class="badge bg-info"><?php echo $as->count; ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="col-md-8">
        <div class="table-container">
            <h6 style="font-weight:bold;margin-bottom:15px;">لیست فعالیت‌ها</h6>
            <div class="table-responsive"><table class="table table-sm">
                <thead><tr><th>کاربر</th><th>نوع</th><th>موضوع</th><th>معامله</th><th>تاریخ</th></tr></thead>
                <tbody><?php foreach ($activities as $a): ?><tr><td><?php echo htmlspecialchars($a->user_name ?? '-'); ?></td><td><?php echo $a->type; ?></td><td><?php echo htmlspecialchars($a->subject ?? '-'); ?></td><td><?php echo htmlspecialchars($a->deal_title ?? '-'); ?></td><td><small><?php echo date('Y/m/d', strtotime($a->created_at)); ?></small></td></tr><?php endforeach; ?></tbody>
            </table></div>
        </div>
    </div>
</div>