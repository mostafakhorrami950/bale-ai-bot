<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="table-container">
            <h5 style="font-weight:bold;margin-bottom:20px;">ارسال پیامک</h5>
            <div class="mb-4 p-3" style="background:#f8f9fa;border-radius:10px;">
                <strong><?php echo htmlspecialchars($deal->title); ?></strong><br>
                <small style="color:#888;"><?php echo htmlspecialchars($deal->contact_name ?? ''); ?> | <?php echo htmlspecialchars($deal->contact_phone ?? ''); ?></small>
            </div>
            <form method="POST" action="<?php echo $config['url']; ?>/sms/send">
                <input type="hidden" name="deal_id" value="<?php echo $deal->id; ?>">
                <input type="hidden" name="contact_id" value="<?php echo $deal->contact_id ?? 0; ?>">
                <div class="mb-3">
                    <label class="form-label">شماره گیرنده *</label>
                    <input type="text" name="recipient" class="form-control" value="<?php echo htmlspecialchars($deal->contact_phone ?? ''); ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">کد پترن (اختیاری)</label>
                    <input type="text" name="pattern_code" class="form-control" placeholder="مثلاً xxxxxxxxxxxxxxx">
                </div>
                <div class="mb-3">
                    <label class="form-label">متن پیام</label>
                    <textarea name="message" class="form-control" rows="4" placeholder="متن پیام خود را وارد کنید..."></textarea>
                </div>
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-send"></i> ارسال پیامک
                </button>
            </form>
        </div>
    </div>
</div>