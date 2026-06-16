<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="table-container">
            <h5 style="font-weight:bold;margin-bottom:20px;">ویرایش مخاطب</h5>
            <form method="POST" action="<?php echo $config['url']; ?>/contacts/update/<?php echo $contact->id; ?>">
                <div class="row g-3">
                    <div class="col-12"><label class="form-label">نام کامل *</label><input type="text" name="full_name" class="form-control" value="<?php echo htmlspecialchars($contact->full_name); ?>" required></div>
                    <div class="col-md-6"><label class="form-label">تلفن</label><input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($contact->phone ?? ''); ?>"></div>
                    <div class="col-md-6"><label class="form-label">ایمیل</label><input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($contact->email ?? ''); ?>"></div>
                    <div class="col-md-6"><label class="form-label">کد ملی</label><input type="text" name="national_code" class="form-control" value="<?php echo htmlspecialchars($contact->national_code ?? ''); ?>"></div>
                    <div class="col-md-6"><label class="form-label">شماره پاسپورت</label><input type="text" name="passport_number" class="form-control" value="<?php echo htmlspecialchars($contact->passport_number ?? ''); ?>"></div>
                    <div class="col-12"><label class="form-label">آدرس</label><textarea name="address" class="form-control" rows="2"><?php echo htmlspecialchars($contact->address ?? ''); ?></textarea></div>
                    <div class="col-md-6"><label class="form-label">شرکت</label><input type="text" name="company" class="form-control" value="<?php echo htmlspecialchars($contact->company ?? ''); ?>"></div>
                    <div class="col-md-6"><label class="form-label">نحوه آشنایی</label><input type="text" name="source" class="form-control" value="<?php echo htmlspecialchars($contact->source ?? ''); ?>"></div>
                    <div class="col-12"><label class="form-label">برچسب‌ها</label><input type="text" name="tags" class="form-control" value="<?php echo htmlspecialchars($contact->tags ?? ''); ?>"></div>
                    <div class="col-12"><label class="form-label">یادداشت</label><textarea name="notes" class="form-control" rows="2"><?php echo htmlspecialchars($contact->notes ?? ''); ?></textarea></div>
                    <div class="col-12"><button type="submit" class="btn btn-primary">بروزرسانی</button><a href="<?php echo $config['url']; ?>/contacts" class="btn btn-secondary ms-2">انصراف</a></div>
                </div>
            </form>
        </div>
    </div>
</div>