<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="table-container">
            <h5 style="font-weight:bold;margin-bottom:20px;">ویرایش نقش: <?php echo htmlspecialchars($role->name); ?></h5>
            <form method="POST" action="<?php echo $config['url']; ?>/roles/update/<?php echo $role->id; ?>">
                <div class="mb-3">
                    <label class="form-label">نام نقش *</label>
                    <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($role->name); ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">توضیحات</label>
                    <textarea name="description" class="form-control" rows="2"><?php echo htmlspecialchars($role->description ?? ''); ?></textarea>
                </div>
                <hr>
                <h6 style="font-weight:bold;margin-bottom:15px;">دسترسی‌ها</h6>
                <?php foreach ($permissionsByGroup as $group => $perms): ?>
                <div class="mb-3">
                    <strong style="color:var(--primary);font-size:13px;"><?php echo htmlspecialchars($group); ?></strong>
                    <div class="row g-2 mt-1">
                        <?php foreach ($perms as $p): ?>
                        <div class="col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="permissions[]" value="<?php echo $p->slug; ?>" id="perm_<?php echo $p->id; ?>" <?php echo in_array($p->slug, $rolePerms) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="perm_<?php echo $p->id; ?>" style="font-size:13px;"><?php echo htmlspecialchars($p->name); ?></label>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <button type="submit" class="btn btn-primary">بروزرسانی نقش</button>
                <a href="<?php echo $config['url']; ?>/roles" class="btn btn-secondary">انصراف</a>
            </form>
        </div>
    </div>
</div>