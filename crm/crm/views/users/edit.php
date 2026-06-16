<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="table-container">
            <h5 style="font-weight:bold;margin-bottom:20px;">ویرایش کاربر: <?php echo htmlspecialchars($user->username); ?></h5>
            <form method="POST" action="<?php echo $config['url']; ?>/users/update/<?php echo $user->id; ?>">
                <div class="mb-3"><label class="form-label">نام کامل *</label><input type="text" name="full_name" class="form-control" value="<?php echo htmlspecialchars($user->full_name); ?>" required></div>
                <div class="mb-3"><label class="form-label">ایمیل</label><input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($user->email ?? ''); ?>"></div>
                <div class="mb-3"><label class="form-label">تلفن</label><input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($user->phone ?? ''); ?>"></div>
                <div class="mb-3"><label class="form-label">نقش</label><select name="role_id" class="form-select"><?php foreach ($roles as $r): ?><option value="<?php echo $r->id; ?>" <?php echo $r->id == $user->role_id ? 'selected' : ''; ?>><?php echo htmlspecialchars($r->name); ?></option><?php endforeach; ?></select></div>
                <div class="mb-3"><label class="form-label">رمز عبور جدید (اختیاری)</label><input type="password" name="password" class="form-control"></div>
                <div class="mb-3"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" value="1" <?php echo $user->is_active ? 'checked' : ''; ?>><label class="form-check-label">فعال</label></div></div>
                <button type="submit" class="btn btn-primary">بروزرسانی</button>
                <a href="<?php echo $config['url']; ?>/users" class="btn btn-secondary">انصراف</a>
            </form>
        </div>
    </div>
</div>