<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="table-container">
            <h5 style="font-weight:bold;margin-bottom:20px;">ایجاد پایپ لاین جدید</h5>
            <form method="POST" action="<?php echo $config['url']; ?>/pipelines/store" id="pipelineForm">
                <div class="mb-3">
                    <label class="form-label">نام پایپ لاین *</label>
                    <input type="text" name="name" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">توضیحات</label>
                    <textarea name="description" class="form-control" rows="2"></textarea>
                </div>
                <hr>
                <h6 style="font-weight:bold;margin-bottom:15px;">مراحل (Stages)</h6>
                <div id="stagesContainer">
                    <div class="row g-2 mb-2 stage-row">
                        <div class="col-md-5"><input type="text" name="stages[0][name]" class="form-control" placeholder="نام مرحله" required></div>
                        <div class="col-md-3"><input type="color" name="stages[0][color]" class="form-control form-control-color" value="#6B7280" style="padding:3px;"></div>
                        <div class="col-md-3">
                            <button type="button" class="btn btn-success btn-sm add-stage"><i class="bi bi-plus-lg"></i></button>
                            <button type="button" class="btn btn-danger btn-sm remove-stage"><i class="bi bi-trash"></i></button>
                        </div>
                    </div>
                </div>
                <hr>
                <button type="submit" class="btn btn-primary">ایجاد پایپ لاین</button>
                <a href="<?php echo $config['url']; ?>/pipelines" class="btn btn-secondary">انصراف</a>
            </form>
        </div>
    </div>
</div>
<script>
let stageIndex = 1;
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