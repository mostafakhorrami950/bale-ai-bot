<div class="table-container">
    <h5 style="font-weight:bold;margin-bottom:20px;">تحلیل پایپ لاین</h5>
    <?php foreach ($pipelineData as $pipelineName => $stages): ?>
    <h6 style="color:var(--primary);font-weight:bold;margin:20px 0 10px;"><?php echo htmlspecialchars($pipelineName); ?></h6>
    <div class="table-responsive mb-4"><table class="table table-sm"><thead><tr><th>مرحله</th><th>تعداد معاملات</th><th>مجموع مبلغ</th></tr></thead>
        <tbody><?php foreach ($stages as $s): ?><tr><td><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?php echo $s->color; ?>;margin-left:5px;"></span><?php echo htmlspecialchars($s->stage_name); ?></td><td><?php echo $s->deals_count; ?></td><td><strong><?php echo number_format($s->total_amount); ?></strong></td></tr><?php endforeach; ?></tbody></table></div>
    <?php endforeach; ?>
</div>