<div class="d-flex justify-content-between align-items-center mb-4">
    <h5 style="margin: 0; font-weight: bold;">مدیریت معاملات</h5>
    <?php if (\Core\Auth::hasPermission('deals.create')): ?>
    <a href="<?php echo $config['url']; ?>/deals/create" class="btn btn-primary">
        <i class="bi bi-plus-lg"></i> معامله جدید
    </a>
    <?php endif; ?>
</div>

<!-- Filters -->
<div class="filter-section">
    <form method="GET" class="row g-3">
        <div class="col-md-3">
            <input type="text" name="search" class="form-control" placeholder="جستجو در معاملات..." value="<?php echo htmlspecialchars($search); ?>">
        </div>
        <div class="col-md-2">
            <select name="pipeline_id" class="form-select">
                <option value="">همه پایپ لاین‌ها</option>
                <?php foreach ($pipelines as $p): ?>
                <option value="<?php echo $p->id; ?>" <?php echo $selectedPipeline == $p->id ? 'selected' : ''; ?>><?php echo htmlspecialchars($p->name); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <select name="stage_id" class="form-select">
                <option value="">همه مراحل</option>
                <?php foreach ($stages as $s): ?>
                <option value="<?php echo $s->id; ?>" <?php echo $selectedStage == $s->id ? 'selected' : ''; ?>><?php echo htmlspecialchars($s->name); ?> (<?php echo htmlspecialchars($s->pipeline_name); ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <select name="assigned_to" class="form-select">
                <option value="">همه کاربران</option>
                <?php foreach ($users as $u): ?>
                <option value="<?php echo $u->id; ?>" <?php echo $selectedAssigned == $u->id ? 'selected' : ''; ?>><?php echo htmlspecialchars($u->full_name); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-1">
            <select name="status" class="form-select">
                <option value="">همه</option>
                <option value="open" <?php echo $selectedStatus == 'open' ? 'selected' : ''; ?>>باز</option>
                <option value="won" <?php echo $selectedStatus == 'won' ? 'selected' : ''; ?>>موفق</option>
                <option value="lost" <?php echo $selectedStatus == 'lost' ? 'selected' : ''; ?>>ناموفق</option>
            </select>
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i> جستجو</button>
        </div>
    </form>
</div>

<div class="table-container">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>عنوان</th>
                    <th>مخاطب</th>
                    <th>پایپ لاین</th>
                    <th>مرحله</th>
                    <th>مسئول</th>
                    <th>مبلغ</th>
                    <th>تاریخ</th>
                    <th>وضعیت</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($deals)): ?>
                <tr><td colspan="9" class="text-center py-4">هیچ معامله‌ای یافت نشد.</td></tr>
                <?php else: ?>
                <?php foreach ($deals as $deal): ?>
                <tr>
                    <td><a href="<?php echo $config['url']; ?>/deals/view/<?php echo $deal->id; ?>" style="text-decoration:none;color:#333;font-weight:500;"><?php echo htmlspecialchars($deal->title); ?></a></td>
                    <td><?php echo htmlspecialchars($deal->contact_name ?? '-'); ?><br><small style="color:#999;"><?php echo htmlspecialchars($deal->contact_phone ?? ''); ?></small></td>
                    <td><?php echo htmlspecialchars($deal->pipeline_name); ?></td>
                    <td><span class="badge-stage" style="background:<?php echo $deal->stage_color; ?>20;color:<?php echo $deal->stage_color; ?>;"><?php echo htmlspecialchars($deal->stage_name); ?></span></td>
                    <td><?php echo htmlspecialchars($deal->assigned_name ?? '-'); ?></td>
                    <td><strong><?php echo number_format($deal->amount); ?></strong></td>
                    <td style="font-size:12px;color:#888;"><?php echo date('Y/m/d', strtotime($deal->created_at)); ?></td>
                    <td>
                        <?php if ($deal->is_won): ?><span class="badge bg-success">موفق</span>
                        <?php elseif ($deal->is_lost): ?><span class="badge bg-danger">ناموفق</span>
                        <?php else: ?><span class="badge bg-warning">در جریان</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?php echo $config['url']; ?>/deals/view/<?php echo $deal->id; ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a>
                        <?php if (\Core\Auth::hasPermission('deals.edit')): ?>
                        <a href="<?php echo $config['url']; ?>/deals/edit/<?php echo $deal->id; ?>" class="btn btn-sm btn-outline-warning"><i class="bi bi-pencil"></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>