<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="table-container">
            <h5 style="font-weight:bold;margin-bottom:20px;">ویرایش پایپ لاین: <?php echo htmlspecialchars($pipeline->name); ?></h5>
            <form method="POST" action="<?php echo $config['url']; ?>/pipelines/update/<?php echo $pipeline->id; ?>" id="pipelineForm">
                <div class="mb-3"><label class="form-label">نام پایپ لاین *</label><input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($pipeline->name); ?>" required></div>
                <div class="mb-3"><label class="form-label">توضیحات</label><textarea name="description" class="form-control" rows="2"><?php echo htmlspecialchars($pipeline->description ?? ''); ?></textarea></div>
                <hr>
                <h6 style="font-weight:bold;margin-bottom:15px;">مراحل</h6>
                <div id="stagesContainer">
                    <?php foreach ($stages as $index => $stage): ?>
                    <div class="row g-2 mb-2 stage-row">
                        <div class="col-md-5"><input type="text" name="stages[<?php echo $index; ?>][name]" class="form-control" value="<?php echo htmlspecialchars($stage->name); ?>" required></div>
                        <div class="col-md-3"><input type="color" name="stages[<?php echo $index; ?>][color]" class="form-control form-control-color" value="<?php echo $stage->color; ?>" style="padding:3px;"></div>
                        <div class="col-md-3">
                            <button type="button" class="btn btn-success btn-sm add-stage"><i class="bi bi-plus-lg"></i></button>
                            <button type="button" class="btn btn-danger btn-sm remove-stage"><i class="bi bi-trash"></i></button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <hr>
                <button type="submit" class="btn btn-primary">بروزرسانی</button>
                <a href="<?php echo $config['url']; ?>/pipelines" class="btn btn-secondary">انصراف</a>
            </form>
        </div>
    </div>
</div>
<script>
let stageIndex = <?php echo count($stages); ?>;
$('.add-stage').on('click', function() {
    const html = `<div class="row g-2 mb-2 stage-row">
        <div class="col-md-5"><input type="text" name="stages[${stageIndex}][name]" class="form-control" placeholder="نام مرحله" required></div>
        <div class="col-md-3"><input type="color" name="stages[${stageIndex}][color]" class="form-control form-control-color" value="#6B7280" style="padding:3px;"></div>
        <div class="col-md-3">
            <button type="button" class="btn btn-success btn-sm add-stage"><i class="bi bi-plus-lg"></i></button>
            <button type="button" class="btn btn-danger btn-sm remove-stage"><i class="bi bi-trash"></i></button>
        </div>
    </div>`;
    $('#stagesContainer').append(html);
    stageIndex++;
});
$(document).on('click', '.remove-stage', function() {
    if ($('.stage-row').length > 1) $(this).closest('.stage-row').remove();
});
</script>