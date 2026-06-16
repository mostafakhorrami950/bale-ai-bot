<div class="d-flex justify-content-between align-items-center mb-4">
    <h5 style="margin:0;font-weight:bold;">مدیریت نقش‌ها</h5>
    <a href="<?php echo $config['url']; ?>/roles/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> نقش جدید</a>
</div>
<div class="table-container">
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>نام نقش</th><th>توضیحات</th><th>تعداد کاربران</th><th>نوع</th><th>وضعیت</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($roles as $r): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($r->name); ?></strong></td>
                    <td><small><?php echo htmlspecialchars($r->description ?? '-'); ?></small></td>
                    <td><span class="badge bg-info"><?php echo $r->user_count; ?> کاربر</span></td>
                    <td><?php echo $r->is_system ? '<span class="badge bg-secondary">سیستمی</span>' : '<span class="badge bg-success">اختصاصی</span>'; ?></td>
                    <td><span class="badge bg-<?php echo $r->is_active ? 'success' : 'secondary'; ?>"><?php echo $r->is_active ? 'فعال' : 'غیرفعال'; ?></span></td>
                    <td>
                        <a href="<?php echo $config['url']; ?>/roles/edit/<?php echo $r->id; ?>" class="btn btn-sm btn-outline-warning"><i class="bi bi-pencil"></i></a>
                        <?php if (!$r->is_system): ?>
                        <form method="POST" action="<?php echo $config['url']; ?>/roles/delete/<?php echo $r->id; ?>" style="display:inline;" onsubmit="return confirm('حذف نقش؟')">
                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>