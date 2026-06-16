<div class="row g-4">
    <!-- Stat Cards -->
    <div class="col-md-3">
        <div class="stat-card">
            <div class="icon" style="background: #e8f5e9; color: #10B981;">
                <i class="bi bi-briefcase"></i>
            </div>
            <h5 style="color: #666; font-size: 13px;">معاملات فعال</h5>
            <h3 style="font-weight: bold;"><?php echo number_format($totalDeals->count ?? 0); ?></h3>
            <small style="color: #999;"><?php echo number_format($totalDeals->total ?? 0); ?> ریال</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card">
            <div class="icon" style="background: #fce4ec; color: #EF4444;">
                <i class="bi bi-check-circle"></i>
            </div>
            <h5 style="color: #666; font-size: 13px;">معاملات موفق</h5>
            <h3 style="font-weight: bold;"><?php echo number_format($wonDeals->count ?? 0); ?></h3>
            <small style="color: #999;"><?php echo number_format($wonDeals->total ?? 0); ?> ریال</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card">
            <div class="icon" style="background: #fff3e0; color: #F59E0B;">
                <i class="bi bi-people"></i>
            </div>
            <h5 style="color: #666; font-size: 13px;">مخاطبان</h5>
            <h3 style="font-weight: bold;"><?php echo number_format($totalContacts->count ?? 0); ?></h3>
            <small style="color: #999;">تعداد کل مخاطبان</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card">
            <div class="icon" style="background: #e3f2fd; color: #3B82F6;">
                <i class="bi bi-kanban"></i>
            </div>
            <h5 style="color: #666; font-size: 13px;">پایپ لاین‌ها</h5>
            <h3 style="font-weight: bold;"><?php echo number_format($totalPipelines->count ?? 0); ?></h3>
            <small style="color: #999;">فعال</small>
        </div>
    </div>

    <!-- Deals by Stage Chart -->
    <div class="col-md-6">
        <div class="table-container">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h5 style="margin: 0; font-weight: bold;">معاملات بر اساس مرحله</h5>
                <a href="<?php echo $config['url']; ?>/deals/create" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-lg"></i> معامله جدید
                </a>
            </div>
            <?php if (empty($dealsByStage)): ?>
                <div class="empty-state">
                    <i class="bi bi-inbox"></i>
                    <p>هنوز معامله‌ای ثبت نشده است.</p>
                </div>
            <?php else: ?>
                <?php foreach ($dealsByStage as $stage): ?>
                <div class="mb-3">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                        <span style="font-size: 13px;">
                            <span style="display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: <?php echo $stage->color; ?>; margin-left: 5px;"></span>
                            <?php echo $stage->name; ?>
                        </span>
                        <span style="font-size: 13px; color: #666;"><?php echo $stage->count; ?> معامله</span>
                    </div>
                    <div class="progress" style="height: 8px; border-radius: 4px;">
                        <div class="progress-bar" style="width: <?php echo $stage->count > 0 ? ($stage->count / array_sum(array_column($dealsByStage, 'count')) * 100) : 0; ?>%; background: <?php echo $stage->color; ?>;"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Upcoming Follow-ups -->
    <div class="col-md-6">
        <div class="table-container">
            <h5 style="font-weight: bold; margin-bottom: 20px;">پیگیری‌های پیش رو</h5>
            <?php if (empty($upcomingFollowUps)): ?>
                <div class="empty-state">
                    <i class="bi bi-calendar-check"></i>
                    <p>پیگیری برنامه‌ریزی شده‌ای وجود ندارد.</p>
                </div>
            <?php else: ?>
                <?php foreach ($upcomingFollowUps as $follow): ?>
                <div class="d-flex align-items-center mb-3 p-2" style="background: #f8f9fa; border-radius: 10px;">
                    <div style="width: 40px; height: 40px; background: #e3f2fd; border-radius: 10px; display: flex; align-items: center; justify-content: center; margin-left: 12px;">
                        <i class="bi bi-bell" style="color: #3B82F6;"></i>
                    </div>
                    <div style="flex: 1;">
                        <strong style="font-size: 13px;"><?php echo htmlspecialchars($follow->deal_title); ?></strong>
                        <br>
                        <small style="color: #888;"><?php echo htmlspecialchars($follow->subject); ?></small>
                    </div>
                    <small style="color: #F59E0B;">
                        <?php echo $follow->reminder_at ? date('Y/m/d H:i', strtotime($follow->reminder_at)) : ''; ?>
                    </small>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Deals -->
    <div class="col-12">
        <div class="table-container">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h5 style="margin: 0; font-weight: bold;">آخرین معاملات</h5>
                <a href="<?php echo $config['url']; ?>/deals" class="btn btn-outline-primary btn-sm">مشاهده همه</a>
            </div>
            
            <?php if (empty($recentDeals)): ?>
                <div class="empty-state">
                    <i class="bi bi-briefcase"></i>
                    <p>هنوز معامله‌ای ثبت نشده است.</p>
                    <a href="<?php echo $config['url']; ?>/deals/create" class="btn btn-primary">ایجاد اولین معامله</a>
                </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>عنوان</th>
                            <th>مخاطب</th>
                            <th>مرحله</th>
                            <th>مسئول</th>
                            <th>مبلغ</th>
                            <th>تاریخ</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentDeals as $deal): ?>
                        <tr>
                            <td>
                                <a href="<?php echo $config['url']; ?>/deals/view/<?php echo $deal->id; ?>" style="color: #333; text-decoration: none; font-weight: 500;">
                                    <?php echo htmlspecialchars($deal->title); ?>
                                </a>
                                <br><small style="color: #999;"><?php echo htmlspecialchars($deal->pipeline_name); ?></small>
                            </td>
                            <td><?php echo htmlspecialchars($deal->contact_name ?? '-'); ?></td>
                            <td>
                                <span class="badge-stage" style="background: <?php echo $deal->stage_color; ?>20; color: <?php echo $deal->stage_color; ?>; border: 1px solid <?php echo $deal->stage_color; ?>40;">
                                    <?php echo $deal->stage_name; ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($deal->assigned_name ?? '-'); ?></td>
                            <td><strong><?php echo number_format($deal->amount); ?></strong></td>
                            <td style="font-size: 12px; color: #888;"><?php echo date('Y/m/d', strtotime($deal->created_at)); ?></td>
                            <td>
                                <a href="<?php echo $config['url']; ?>/deals/view/<?php echo $deal->id; ?>" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-eye"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Activities -->
    <div class="col-12">
        <div class="table-container">
            <h5 style="font-weight: bold; margin-bottom: 20px;">آخرین فعالیت‌ها</h5>
            <?php if (empty($recentActivities)): ?>
                <div class="empty-state">
                    <i class="bi bi-activity"></i>
                    <p>هیچ فعالیتی ثبت نشده است.</p>
                </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>کاربر</th>
                            <th>عملیات</th>
                            <th>توضیحات</th>
                            <th>زمان</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentActivities as $log): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($log->user_name ?? 'سیستم'); ?></td>
                            <td><?php echo htmlspecialchars($log->action); ?></td>
                            <td style="font-size: 13px;"><?php echo htmlspecialchars($log->description ?? '-'); ?></td>
                            <td style="font-size: 12px; color: #888;">
                                <?php echo time_elapsed_string($log->created_at); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;

    $string = [
        'y' => 'سال',
        'm' => 'ماه',
        'w' => 'هفته',
        'd' => 'روز',
        'h' => 'ساعت',
        'i' => 'دقیقه',
        's' => 'ثانیه',
    ];
    
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v;
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' پیش' : 'همین الان';
}
?>