<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="table-container">
            <h5 style="font-weight:bold;margin-bottom:20px;">ویرایش معامله</h5>
            <form method="POST" action="<?php echo $config['url']; ?>/deals/update/<?php echo $deal->id; ?>">
                <div class="row g-3">
                    <div class="col-12"><label class="form-label">عنوان معامله *</label><input type="text" name="title" class="form-control" value="<?php echo htmlspecialchars($deal->title); ?>" required></div>
                    <div class="col-md-6"><label class="form-label">پایپ لاین</label><select name="pipeline_id" class="form-select" id="editPipelineSelect"><?php foreach ($pipelines as $p): ?><option value="<?php echo $p->id; ?>" <?php echo $p->id == $deal->pipeline_id ? 'selected' : ''; ?>><?php echo htmlspecialchars($p->name); ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-6"><label class="form-label">مرحله</label><select name="stage_id" class="form-select" id="editStageSelect"><?php foreach ($stages as $s): ?><option value="<?php echo $s->id; ?>" <?php echo $s->id == $deal->stage_id ? 'selected' : ''; ?>><?php echo htmlspecialchars($s->name); ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-6"><label class="form-label">مبلغ (ریال)</label><input type="text" name="amount" class="form-control" data-format="number" value="<?php echo number_format($deal->amount); ?>"></div>
                    <div class="col-md-6"><label class="form-label">مخاطب</label><select name="contact_id" class="form-select"><option value="">انتخاب مخاطب</option><?php foreach ($contacts as $c): ?><option value="<?php echo $c->id; ?>" <?php echo $c->id == $deal->contact_id ? 'selected' : ''; ?>><?php echo htmlspecialchars($c->full_name); ?> (<?php echo htmlspecialchars($c->phone); ?>)</option><?php endforeach; ?></select></div>
                    <div class="col-md-6"><label class="form-label">مسئول</label><select name="assigned_to" class="form-select"><option value="">انتخاب کنید</option><?php foreach ($users as $u): ?><option value="<?php echo $u->id; ?>" <?php echo $u->id == $deal->assigned_to ? 'selected' : ''; ?>><?php echo htmlspecialchars($u->full_name); ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-6"><label class="form-label">نحوه آشنایی</label><input type="text" name="source" class="form-control" value="<?php echo htmlspecialchars($deal->source ?? ''); ?>"></div>
                    <div class="col-md-6"><label class="form-label">درصد احتمال موفقیت</label><input type="number" name="probability" class="form-control" min="0" max="100" value="<?php echo $deal->probability; ?>"></div>
                    <div class="col-md-6"><label class="form-label">تاریخ پیش‌بینی بسته شدن</label><input type="date" name="expected_close_date" class="form-control" value="<?php echo $deal->expected_close_date; ?>"></div>
                    <div class="col-12"><label class="form-label">دلیل عدم موفقیت</label><textarea name="lost_reason" class="form-control" rows="2"><?php echo htmlspecialchars($deal->lost_reason ?? ''); ?></textarea></div>
                    <div class="col-12"><label class="form-label">توضیحات</label><textarea name="description" class="form-control" rows="3"><?php echo htmlspecialchars($deal->description ?? ''); ?></textarea></div>
                    <div class="col-12"><button type="submit" class="btn btn-primary">بروزرسانی معامله</button><a href="<?php echo $config['url']; ?>/deals/view/<?php echo $deal->id; ?>" class="btn btn-secondary ms-2">انصراف</a></div>
                </div>
            </form>
        </div>
    </div>
</div>