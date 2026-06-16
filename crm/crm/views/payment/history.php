<div class="d-flex justify-content-between align-items-center mb-4">
    <h5 style="margin:0;font-weight:bold;">تاریخچه پرداخت‌ها</h5>
</div>
<div class="table-container">
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>معامله</th><th>مبلغ</th><th>کد پیگیری</th><th>شماره مرجع</th><th>وضعیت</th><th>تاریخ</th></tr></thead>
            <tbody>
                <?php if (empty($payments)): ?><tr><td colspan="6" class="text-center py-4">هیچ پرداختی ثبت نشده است.</td></tr><?php endif; ?>
                <?php foreach ($payments as $p): ?>
                <tr>
                    <td><a href="<?php echo $config['url']; ?>/deals/view/<?php echo $p->deal_id; ?>"><?php echo htmlspecialchars($p->deal_title ?? '-'); ?></a></td>
                    <td><strong><?php echo number_format($p->amount); ?></strong></td>
                    <td><small><?php echo htmlspecialchars($p->track_id ?? '-'); ?></small></td>
                    <td><?php echo htmlspecialchars($p->ref_number ?? '-'); ?></td>
                    <td>
                        <span class="badge bg-<?php echo $p->status == 'success' ? 'success' : ($p->status == 'pending' ? 'warning' : 'danger'); ?>">
                            <?php echo $p->status == 'success' ? 'موفق' : ($p->status == 'pending' ? 'در انتظار' : 'ناموفق'); ?>
                        </span>
                    </td>
                    <td><small style="color:#888;"><?php echo date('Y/m/d H:i', strtotime($p->created_at)); ?></small></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>