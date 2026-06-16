<div class="d-flex justify-content-between align-items-center mb-4">
    <h5 style="margin:0;font-weight:bold;">تاریخچه پیامک‌ها</h5>
</div>
<div class="table-container">
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>گیرنده</th><th>متن</th><th>معامله</th><th>ارسال کننده</th><th>وضعیت</th><th>تاریخ</th></tr></thead>
            <tbody>
                <?php if (empty($history)): ?><tr><td colspan="6" class="text-center py-4">پیامکی ارسال نشده است.</td></tr><?php endif; ?>
                <?php foreach ($history as $h): ?>
                <tr>
                    <td><?php echo htmlspecialchars($h->recipient); ?></td>
                    <td><small><?php echo htmlspecialchars(mb_substr($h->message ?? '', 0, 50)); ?>...</small></td>
                    <td><?php echo htmlspecialchars($h->deal_title ?? '-'); ?></td>
                    <td><?php echo htmlspecialchars($h->sent_by_name ?? '-'); ?></td>
                    <td><span class="badge bg-<?php echo $h->status == 'sent' ? 'success' : 'danger'; ?>"><?php echo $h->status == 'sent' ? 'ارسال' : 'خطا'; ?></span></td>
                    <td><small style="color:#888;"><?php echo date('Y/m/d H:i', strtotime($h->created_at)); ?></small></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>