<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="table-container">
            <h5 style="font-weight:bold;margin-bottom:20px;">ایجاد لینک پرداخت</h5>
            <div class="mb-4 p-3" style="background:#f8f9fa;border-radius:10px;">
                <strong><?php echo htmlspecialchars($deal->title); ?></strong><br>
                <small style="color:#888;"><?php echo htmlspecialchars($deal->contact_name ?? ''); ?> | <?php echo htmlspecialchars($deal->contact_phone ?? ''); ?></small>
            </div>
            <form method="POST" action="<?php echo $config['url']; ?>/payment/request">
                <input type="hidden" name="deal_id" value="<?php echo $deal->id; ?>">
                <div class="mb-3">
                    <label class="form-label">مبلغ (ریال) *</label>
                    <input type="text" name="amount" class="form-control" data-format="number" value="<?php echo $deal->amount ?: 0; ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">شماره موبایل (برای نمایش کارت‌ها)</label>
                    <input type="text" name="mobile" class="form-control" placeholder="09120000000" value="<?php echo htmlspecialchars($deal->contact_phone ?? ''); ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">توضیحات</label>
                    <textarea name="description" class="form-control" rows="2" placeholder="توضیحات پرداخت"><?php echo htmlspecialchars($deal->title); ?></textarea>
                </div>
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-credit-card"></i> اتصال به درگاه پرداخت
                </button>
                <p class="text-muted mt-2" style="font-size:12px;text-align:center;">
                    <i class="bi bi-shield-check"></i> پرداخت امن توسط درگاه زیبال
                </p>
            </form>
        </div>
    </div>
</div>