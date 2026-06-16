<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="table-container">
            <h5 style="font-weight:bold;margin-bottom:20px;">ایجاد مخاطب جدید</h5>
            <form method="POST" action="<?php echo $config['url']; ?>/contacts/store">
                <div class="row g-3">
                    <div class="col-12"><label class="form-label">نام کامل *</label><input type="text" name="full_name" class="form-control" required></div>
                    <div class="col-md-6"><label class="form-label">تلفن</label><input type="text" name="phone" class="form-control" placeholder="09120000000"></div>
                    <div class="col-md-6"><label class="form-label">ایمیل</label><input type="email" name="email" class="form-control"></div>
                    <div class="col-md-6"><label class="form-label">کد ملی</label><input type="text" name="national_code" class="form-control" maxlength="10"></div>
                    <div class="col-md-6"><label class="form-label">شماره پاسپورت</label><input type="text" name="passport_number" class="form-control"></div>
                    <div class="col-12"><label class="form-label">آدرس</label><textarea name="address" class="form-control" rows="2"></textarea></div>
                    <div class="col-md-6"><label class="form-label">شرکت</label><input type="text" name="company" class="form-control"></div>
                    <div class="col-md-6"><label class="form-label">نحوه آشنایی</label><input type="text" name="source" class="form-control"></div>
                    <div class="col-12"><label class="form-label">برچسب‌ها</label><input type="text" name="tags" class="form-control" placeholder="مثلاً VIP, مسافرت, تور"></div>
                    <div class="col-12"><label class="form-label">یادداشت</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
                    <div class="col-12"><button type="submit" class="btn btn-primary">ذخیره مخاطب</button><a href="<?php echo $config['url']; ?>/contacts" class="btn btn-secondary ms-2">انصراف</a></div>
                </div>
            </form>
        </div>
    </div>
</div>