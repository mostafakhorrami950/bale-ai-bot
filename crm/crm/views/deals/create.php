<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="table-container">
            <h5 style="font-weight: bold; margin-bottom: 25px;">ایجاد معامله جدید</h5>
            <form method="POST" action="<?php echo $config['url']; ?>/deals/store">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">عنوان معامله *</label>
                        <input type="text" name="title" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">پایپ لاین *</label>
                        <select name="pipeline_id" class="form-select" id="pipelineSelect" required>
                            <?php foreach ($pipelines as $p): ?>
                            <option value="<?php echo $p->id; ?>"><?php echo htmlspecialchars($p->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">مرحله *</label>
                        <select name="stage_id" class="form-select" id="stageSelect" required>
                            <?php foreach ($stages as $s): ?>
                            <option value="<?php echo $s->id; ?>"><?php echo htmlspecialchars($s->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">مبلغ (ریال)</label>
                        <input type="text" name="amount" class="form-control" data-format="number" placeholder="مثلاً ۵,۰۰۰,۰۰۰">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">مخاطب</label>
                        <select name="contact_id" class="form-select">
                            <option value="">انتخاب مخاطب</option>
                            <?php foreach ($contacts as $c): ?>
                            <option value="<?php echo $c->id; ?>"><?php echo htmlspecialchars($c->full_name); ?> (<?php echo htmlspecialchars($c->phone); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">مسئول</label>
                        <select name="assigned_to" class="form-select">
                            <option value="">انتخاب کنید</option>
                            <?php foreach ($users as $u): ?>
                            <option value="<?php echo $u->id; ?>"><?php echo htmlspecialchars($u->full_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">نحوه آشنایی</label>
                        <input type="text" name="source" class="form-control" placeholder="مثلاً اینستاگرام، دوستان، وبسایت">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">تاریخ پیش‌بینی بسته شدن</label>
                        <input type="date" name="expected_close_date" class="form-control">
                    </div>
                    <div class="col-12">
                        <label class="form-label">توضیحات</label>
                        <textarea name="description" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">ذخیره معامله</button>
                        <a href="<?php echo $config['url']; ?>/deals" class="btn btn-secondary">انصراف</a>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Load stages when pipeline changes
$('#pipelineSelect').on('change', function() {
    var pipelineId = $(this).val();
    // For simplicity, we'll load stages via a simple approach
    // In production, you'd use AJAX to load stages dynamically
});
</script>