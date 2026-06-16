<div class="row">
    <div class="col-md-8">
        <!-- Deal Info -->
        <div class="table-container mb-4">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <h4 style="font-weight: bold;"><?php echo htmlspecialchars($deal->title); ?></h4>
                    <span class="badge-stage" style="background: <?php echo $deal->stage_color; ?>20; color: <?php echo $deal->stage_color; ?>;">
                        <?php echo htmlspecialchars($deal->stage_name); ?>
                    </span>
                    <span class="badge bg-secondary ms-1"><?php echo htmlspecialchars($deal->pipeline_name); ?></span>
                </div>
                <div class="text-start">
                    <?php if ($deal->is_won): ?><span class="badge bg-success" style="font-size:16px;padding:8px 20px;">✓ موفق</span>
                    <?php elseif ($deal->is_lost): ?><span class="badge bg-danger" style="font-size:16px;padding:8px 20px;">✗ ناموفق</span>
                    <?php else: ?><span class="badge bg-warning" style="font-size:16px;padding:8px 20px;">در جریان</span>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="row g-3 mb-3">
                <div class="col-md-4"><small class="text-muted">مبلغ</small><br><strong class="amount-display"><?php echo number_format($deal->amount); ?> ریال</strong></div>
                <div class="col-md-4"><small class="text-muted">مسئول</small><br><strong><?php echo htmlspecialchars($deal->assigned_name ?? 'تعیین نشده'); ?></strong></div>
                <div class="col-md-4"><small class="text-muted">ایجاد کننده</small><br><strong><?php echo htmlspecialchars($deal->creator_name ?? ''); ?></strong></div>
                <div class="col-md-4"><small class="text-muted">نحوه آشنایی</small><br><strong><?php echo htmlspecialchars($deal->source ?? '-'); ?></strong></div>
                <div class="col-md-4"><small class="text-muted">تاریخ ایجاد</small><br><strong><?php echo date('Y/m/d', strtotime($deal->created_at)); ?></strong></div>
                <?php if ($deal->expected_close_date): ?>
                <div class="col-md-4"><small class="text-muted">تاریخ پیش‌بینی</small><br><strong><?php echo date('Y/m/d', strtotime($deal->expected_close_date)); ?></strong></div>
                <?php endif; ?>
            </div>
            <?php if ($deal->description): ?>
            <hr>
            <p><strong>توضیحات:</strong><br><?php echo nl2br(htmlspecialchars($deal->description)); ?></p>
            <?php endif; ?>
        </div>

        <!-- Contact Info -->
        <?php if ($deal->contact_name): ?>
        <div class="table-container mb-4">
            <h5 style="font-weight: bold; margin-bottom: 15px;">اطلاعات مخاطب</h5>
            <div class="row g-3">
                <div class="col-md-4"><small class="text-muted">نام</small><br><strong><?php echo htmlspecialchars($deal->contact_name); ?></strong></div>
                <div class="col-md-4"><small class="text-muted">تلفن</small><br><strong><?php echo htmlspecialchars($deal->contact_phone ?? '-'); ?></strong></div>
                <div class="col-md-4"><small class="text-muted">ایمیل</small><br><strong><?php echo htmlspecialchars($deal->contact_email ?? '-'); ?></strong></div>
                <?php if ($deal->national_code): ?><div class="col-md-4"><small class="text-muted">کد ملی</small><br><strong><?php echo htmlspecialchars($deal->national_code); ?></strong></div><?php endif; ?>
                <?php if ($deal->passport_number): ?><div class="col-md-4"><small class="text-muted">شماره پاسپورت</small><br><strong><?php echo htmlspecialchars($deal->passport_number); ?></strong></div><?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Activities -->
        <div class="table-container mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 style="font-weight: bold; margin:0;">فعالیت‌ها</h5>
                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#activityModal">
                    <i class="bi bi-plus-lg"></i> ثبت فعالیت
                </button>
            </div>
            <?php if (empty($activities)): ?>
            <div class="empty-state"><i class="bi bi-list-check"></i><p>هیچ فعالیتی ثبت نشده است.</p></div>
            <?php else: ?>
            <div class="timeline">
                <?php foreach ($activities as $act): ?>
                <div class="d-flex mb-3 p-2" style="background:#f8f9fa;border-radius:10px;">
                    <div style="width:35px;height:35px;background:#e3f2fd;border-radius:8px;display:flex;align-items:center;justify-content:center;margin-left:12px;">
                        <i class="bi bi-<?php echo $act->type == 'call' ? 'telephone' : ($act->type == 'meeting' ? 'people' : ($act->type == 'sms' ? 'chat-dots' : 'sticky')); ?>" style="color:#3B82F6;"></i>
                    </div>
                    <div style="flex:1;">
                        <strong style="font-size:13px;"><?php echo htmlspecialchars($act->subject ?? $act->type); ?></strong>
                        <br><small style="color:#888;"><?php echo htmlspecialchars($act->user_name ?? ''); ?> | <?php echo date('Y/m/d H:i', strtotime($act->created_at)); ?></small>
                        <?php if ($act->description): ?><p class="mt-1 mb-0" style="font-size:13px;"><?php echo nl2br(htmlspecialchars($act->description)); ?></p><?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-md-4">
        <!-- Quick Actions -->
        <div class="table-container mb-4">
            <h5 style="font-weight: bold; margin-bottom: 15px;">عملیات سریع</h5>
            <div class="d-grid gap-2">
                <?php if ($config['features']['payment_gateway'] && \Core\Auth::hasPermission('payments.create')): ?>
                <a href="<?php echo $config['url']; ?>/payment/create/<?php echo $deal->id; ?>" class="btn btn-primary">
                    <i class="bi bi-credit-card"></i> ایجاد لینک پرداخت
                </a>
                <?php endif; ?>
                <?php if ($config['features']['sms'] && \Core\Auth::hasPermission('sms.send')): ?>
                <a href="<?php echo $config['url']; ?>/sms/send/<?php echo $deal->id; ?>" class="btn btn-info text-white">
                    <i class="bi bi-chat-dots"></i> ارسال پیامک
                </a>
                <?php endif; ?>
                <?php if (\Core\Auth::hasPermission('deals.edit')): ?>
                <a href="<?php echo $config['url']; ?>/deals/edit/<?php echo $deal->id; ?>" class="btn btn-warning">
                    <i class="bi bi-pencil"></i> ویرایش معامله
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Payments -->
        <div class="table-container mb-4">
            <h5 style="font-weight: bold; margin-bottom: 15px;">پرداخت‌ها</h5>
            <?php if (empty($payments)): ?>
            <p style="color:#999;font-size:13px;">هیچ پرداختی ثبت نشده است.</p>
            <?php else: ?>
            <?php foreach ($payments as $p): ?>
            <div class="d-flex justify-content-between align-items-center mb-2 p-2" style="background:#f8f9fa;border-radius:8px;">
                <div>
                    <strong><?php echo number_format($p->amount); ?> ریال</strong>
                    <br><small style="color:#888;"><?php echo date('Y/m/d', strtotime($p->created_at)); ?></small>
                </div>
                <span class="badge bg-<?php echo $p->status == 'success' ? 'success' : ($p->status == 'pending' ? 'warning' : 'danger'); ?>">
                    <?php echo $p->status == 'success' ? 'موفق' : ($p->status == 'pending' ? 'در انتظار' : 'ناموفق'); ?>
                </span>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- SMS History -->
        <?php if ($config['features']['sms']): ?>
        <div class="table-container mb-4">
            <h5 style="font-weight: bold; margin-bottom: 15px;">پیامک‌ها</h5>
            <?php if (empty($smsHistory)): ?>
            <p style="color:#999;font-size:13px;">هیچ پیامکی ارسال نشده است.</p>
            <?php else: ?>
            <?php foreach ($smsHistory as $sms): ?>
            <div class="d-flex justify-content-between align-items-center mb-2 p-2" style="background:#f8f9fa;border-radius:8px;">
                <div>
                    <strong><?php echo htmlspecialchars($sms->recipient); ?></strong>
                    <br><small style="color:#888;"><?php echo date('Y/m/d', strtotime($sms->created_at)); ?></small>
                </div>
                <span class="badge bg-<?php echo $sms->status == 'sent' ? 'success' : 'danger'; ?>">
                    <?php echo $sms->status == 'sent' ? 'ارسال' : 'خطا'; ?>
                </span>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Activity Modal -->
<div class="modal fade" id="activityModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?php echo $config['url']; ?>/deals/add-activity/<?php echo $deal->id; ?>">
                <div class="modal-header">
                    <h5 class="modal-title">ثبت فعالیت جدید</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">نوع فعالیت</label>
                        <select name="type" class="form-select">
                            <option value="note">یادداشت</option>
                            <option value="call">تماس تلفنی</option>
                            <option value="meeting">جلسه</option>
                            <option value="email">ایمیل</option>
                            <option value="follow_up">پیگیری</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">موضوع</label>
                        <input type="text" name="subject" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">توضیحات</label>
                        <textarea name="description" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">تاریخ فعالیت</label>
                        <input type="datetime-local" name="activity_date" class="form-control" value="<?php echo date('Y-m-d\TH:i'); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">یادآوری (اختیاری)</label>
                        <input type="datetime-local" name="reminder_at" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">لغو</button>
                    <button type="submit" class="btn btn-primary">ثبت فعالیت</button>
                </div>
            </form>
        </div>
    </div>
</div>