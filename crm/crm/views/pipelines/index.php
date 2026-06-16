<div class="d-flex justify-content-between align-items-center mb-4">
    <h5 style="margin: 0; font-weight: bold;">مدیریت پایپ لاین‌ها</h5>
    <?php if (\Core\Auth::hasPermission('pipelines.create')): ?>
    <a href="<?php echo $config['url']; ?>/pipelines/create" class="btn btn-primary">
        <i class="bi bi-plus-lg"></i> پایپ لاین جدید
    </a>
    <?php endif; ?>
</div>

<div class="table-container">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>نام پایپ لاین</th>
                    <th>توضیحات</th>
                    <th>مراحل</th>
                    <th>معاملات</th>
                    <th>وضعیت</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($pipelines)): ?>
                <tr>
                    <td colspan="6" class="text-center py-4">هیچ پایپ لاینی یافت نشد.</td>
                </tr>
                <?php else: ?>
                <?php foreach ($pipelines as $p): ?>
                <tr>
                    <td>
                        <strong><?php echo htmlspecialchars($p->name); ?></strong>
                        <?php if ($p->is_default): ?>
                        <span class="badge bg-primary">پیش‌فرض</span>
                        <?php endif; ?>
                    </td>
                    <td><small><?php echo htmlspecialchars($p->description ?? '-'); ?></small></td>
                    <td><span class="badge bg-info"><?php echo $p->stages_count; ?> مرحله</span></td>
                    <td><?php echo $p->deals_count; ?> معامله</td>
                    <td>
                        <span class="badge bg-<?php echo $p->is_active ? 'success' : 'secondary'; ?>">
                            <?php echo $p->is_active ? 'فعال' : 'غیرفعال'; ?>
                        </span>
                    </td>
                    <td>
                        <div class="btn-group btn-group-sm">
                            <a href="<?php echo $config['url']; ?>/pipelines/kanban/<?php echo $p->id; ?>" class="btn btn-outline-primary" title="نمایش کانبان">
                                <i class="bi bi-kanban"></i>
                            </a>
                            <?php if (\Core\Auth::hasPermission('pipelines.edit')): ?>
                            <a href="<?php echo $config['url']; ?>/pipelines/edit/<?php echo $p->id; ?>" class="btn btn-outline-warning">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <?php endif; ?>
                            <?php if (\Core\Auth::hasPermission('pipelines.delete')): ?>
                            <form method="POST" action="<?php echo $config['url']; ?>/pipelines/delete/<?php echo $p->id; ?>" style="display:inline;" onsubmit="return confirm('آیا از حذف این پایپ لاین اطمینان دارید؟')">
                                <button type="submit" class="btn btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>