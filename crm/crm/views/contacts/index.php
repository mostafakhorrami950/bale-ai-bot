<div class="d-flex justify-content-between align-items-center mb-4">
    <h5 style="margin:0;font-weight:bold;">مدیریت مخاطبان</h5>
    <a href="<?php echo $config['url']; ?>/contacts/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> مخاطب جدید</a>
</div>
<div class="filter-section">
    <form method="GET" class="row g-3">
        <div class="col-md-4"><input type="text" name="search" class="form-control" placeholder="جستجو در مخاطبان..." value="<?php echo htmlspecialchars($search); ?>"></div>
        <div class="col-md-2"><button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i> جستجو</button></div>
    </form>
</div>
<div class="table-container">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr><th>نام</th><th>تلفن</th><th>ایمیل</th><th>کد ملی/پاسپورت</th><th>معاملات</th><th>مجموع خرید</th><th>تاریخ ثبت</th><th></th></tr>
            </thead>
            <tbody>
                <?php if (empty($contacts)): ?><tr><td colspan="8" class="text-center py-4">مخاطبی یافت نشد.</td></tr><?php endif; ?>
                <?php foreach ($contacts as $c): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($c->full_name); ?></strong></td>
                    <td><?php echo htmlspecialchars($c->phone ?? '-'); ?></td>
                    <td><?php echo htmlspecialchars($c->email ?? '-'); ?></td>
                    <td><small><?php echo htmlspecialchars($c->national_code ?? ''); ?><?php echo $c->passport_number ? ' | ' . htmlspecialchars($c->passport_number) : ''; ?></small></td>
                    <td><span class="badge bg-info"><?php echo $c->deals_count; ?></span></td>
                    <td><strong><?php echo number_format($c->total_purchases ?? 0); ?></strong></td>
                    <td><small style="color:#888;"><?php echo date('Y/m/d', strtotime($c->created_at)); ?></small></td>
                    <td>
                        <a href="<?php echo $config['url']; ?>/contacts/edit/<?php echo $c->id; ?>" class="btn btn-sm btn-outline-warning"><i class="bi bi-pencil"></i></a>
                        <form method="POST" action="<?php echo $config['url']; ?>/contacts/delete/<?php echo $c->id; ?>" style="display:inline;" onsubmit="return confirm('حذف مخاطب؟')">
                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>