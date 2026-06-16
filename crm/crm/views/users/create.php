<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="table-container">
            <h5 style="font-weight:bold;margin-bottom:20px;">ایجاد کاربر جدید</h5>
            <form method="POST" action="<?php echo $config['url']; ?>/users/store">
                <div class="mb-3">
                    <label class="form-label">نام کاربری *</label>
                    <input type="text" name="username" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">رمز عبور *</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">نام کامل *</label>
                    <input type="text" name="full_name" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">ایمیل</label>
                    <input type="email" name="email" class="form-control">
                </div>
                <div class="mb-3">
                    <label class="form-label">تلفن</label>
                    <input type="text" name="phone" class="form-control">
                </div>
                <div class="mb-3">
                    <label class="form-label">نقش *</label>
                    <select name="role_id" class="form-select" required>
                        <option value="">انتخاب نقش</option>
                        <?php foreach ($roles as $r): ?>
                        <option value="<?php echo $r->id; ?>"><?php echo htmlspecialchars($r->name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">ایجاد کاربر</button>
                <a href="<?php echo $config['url']; ?>/users" class="btn btn-secondary">انصراف</a>
            </form>
        </div>
    </div>
</div>