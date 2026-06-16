<div class="row">
    <div class="col-12">
        <div class="table-container mb-4">
            <h5 style="font-weight:bold;margin-bottom:20px;">مدیریت ویژگی‌ها</h5>
            <div class="row g-3">
                <?php foreach ($features as $feature => $enabled): ?>
                <div class="col-md-4">
                    <div class="d-flex justify-content-between align-items-center p-3" style="background:#f8f9fa;border-radius:10px;">
                        <div>
                            <strong><?php 
                                $featureNames = ['payment_gateway'=>'درگاه پرداخت','sms'=>'پیامک','pipelines'=>'پایپ لاین','reports'=>'گزارشات','activity_log'=>'لاگ فعالیت'];
                                echo $featureNames[$feature] ?? $feature; 
                            ?></strong>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input feature-toggle" type="checkbox" data-feature="<?php echo $feature; ?>" <?php echo $enabled ? 'checked' : ''; ?>>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="col-12">
        <form method="POST" action="<?php echo $config['url']; ?>/settings/update">
            <div class="table-container">
                <h5 style="font-weight:bold;margin-bottom:20px;">تنظیمات سیستم</h5>
                <?php foreach ($groupedSettings as $group => $settings): ?>
                <?php if ($group === 'features') continue; ?>
                <div class="mb-4">
                    <h6 style="color:var(--primary);font-weight:bold;margin-bottom:15px;">
                        <?php 
                            $groupNames = ['general'=>'عمومی','payment'=>'درگاه پرداخت','sms'=>'سرویس پیامک'];
                            echo $groupNames[$group] ?? $group;
                        ?>
                    </h6>
                    <?php foreach ($settings as $s): ?>
                    <div class="mb-3">
                        <label class="form-label"><?php echo htmlspecialchars($s->description ?: $s->setting_key); ?></label>
                        <?php if (strpos($s->setting_key, 'feature_') === 0): ?>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="settings[<?php echo $s->setting_key; ?>]" value="1" <?php echo $s->setting_value == '1' ? 'checked' : ''; ?>>
                            </div>
                        <?php elseif (strpos($s->setting_key, 'sms_') === 0 || strpos($s->setting_key, 'zibal_') === 0): ?>
                            <input type="text" name="settings[<?php echo $s->setting_key; ?>]" class="form-control" value="<?php echo htmlspecialchars($s->setting_value); ?>">
                        <?php else: ?>
                            <input type="text" name="settings[<?php echo $s->setting_key; ?>]" class="form-control" value="<?php echo htmlspecialchars($s->setting_value); ?>">
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
                <button type="submit" class="btn btn-primary">ذخیره تنظیمات</button>
            </div>
        </form>
    </div>
</div>

<script>
$('.feature-toggle').on('change', function() {
    var feature = $(this).data('feature');
    var enabled = $(this).is(':checked') ? 1 : 0;
    $.ajax({
        url: '<?php echo $config['url']; ?>/settings/toggle-feature',
        method: 'POST',
        data: { feature: feature, enabled: enabled },
        success: function(response) {
            if (response.success) {
                location.reload();
            }
        }
    });
});
</script>