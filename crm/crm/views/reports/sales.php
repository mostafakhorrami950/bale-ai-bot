<div class="filter-section">
    <form method="GET" class="row g-3">
        <div class="col-md-3"><label class="form-label">از تاریخ</label><input type="date" name="date_from" class="form-control" value="<?php echo $dateFrom; ?>"></div>
        <div class="col-md-3"><label class="form-label">تا تاریخ</label><input type="date" name="date_to" class="form-control" value="<?php echo $dateTo; ?>"></div>
        <div class="col-md-3"><label class="form-label">پایپ لاین</label><select name="pipeline_id" class="form-select"><option value="">همه</option><?php foreach ($pipelines as $p): ?><option value="<?php echo $p->id; ?>" <?php echo $selectedPipeline == $p->id ? 'selected' : ''; ?>><?php echo htmlspecialchars($p->name); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label">&nbsp;</label><button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i> فیلتر</button></div>
    </form>
</div>
<div class="table-container">
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>عنوان</th><th>مخاطب</th><th>مرحله</th><th>مسئول</th><th>مبلغ</th><th>تاریخ</th><th>وضعیت</th></tr></thead>
            <tbody><?php foreach ($deals as $d): ?><tr><td><?php echo htmlspecialchars($d->title); ?></td><td><?php echo htmlspecialchars($d->contact_name ?? '-'); ?></td><td><?php echo htmlspecialchars($d->stage_name); ?></td><td><?php echo htmlspecialchars($d->assigned_name ?? '-'); ?></td><td><strong><?php echo number_format($d->amount); ?></strong></td><td><small><?php echo date('Y/m/d', strtotime($d->created_at)); ?></small></td><td><?php if($d->is_won):?><span class="badge bg-success">موفق</span><?php elseif($d->is_lost):?><span class="badge bg-danger">ناموفق</span><?php else:?><span class="badge bg-warning">در جریان</span><?php endif;?></td></tr><?php endforeach; ?></tbody>
        </table>
    </div>
</div>