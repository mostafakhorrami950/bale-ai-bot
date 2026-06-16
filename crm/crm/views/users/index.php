<div class="d-flex justify-content-between align-items-center mb-4">
    <h5 style="margin: 0; font-weight: bold;">مدیریت کاربران</h5>
    <a href="<?php echo $config['url']; ?>/users/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> کاربر جدید</a>
</div>
<div class="table-container">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr><th>نام کاربری</th><th>نام کامل</th><th>ایمیل</th><th>تلفن</th><th>نقش</th><th>وضعیت</th><th>آخرین ورود</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td><?php echo htmlspecialchars($u->username); ?></td>
                    <td><?php echo htmlspecialchars($u->full_name); ?></td>
                    <td><?php echo htmlspecialchars($u->email ?? '-'); ?></td>
                    <td><?php echo htmlspecialchars($u->phone ?? '-'); ?></td>
                    <td><span class="badge bg-info"><?php echo htmlspecialchars($u->role_name); ?></span></td>
                    <td><span class="badge bg-<?php echo $u->is_active ? 'success' : 'secondary'; ?>"><?php echo $u->is_active ? 'فعال' : 'غیرفعال'; ?></span></td>
                    <td><small><?php echo $u->last_login ? date('Y/m/d H:i', strtotime($u->last_login)) : '-'; ?></small></td>
                    <td>
                        <a href="<?php echo $config['url']; ?>/users/edit/<?php echo $u->id; ?>" class="btn btn-sm btn-outline-warning"><i class="bi bi-pencil"></i></a>
                        <form method="POST" action="<?php echo $config['url']; ?>/users/delete/<?php echo $u->id; ?>" style="display:inline;" onsubmit="return confirm('حذف کاربر؟')">
                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>