<!-- Pipeline Selector -->
<div class="pipeline-selector">
    <div class="row align-items-center">
        <div class="col-md-6">
            <h5 style="margin: 0; font-weight: bold;">
                <i class="bi bi-kanban" style="color: var(--primary);"></i>
                <?php echo htmlspecialchars($pipeline->name); ?>
            </h5>
        </div>
        <div class="col-md-6">
            <div class="d-flex gap-2 justify-content-end">
                <select class="form-select form-select-sm" style="width: auto;" onchange="location.href='<?php echo $config['url']; ?>/pipelines/kanban/'+this.value">
                    <?php foreach ($pipelines as $p): ?>
                    <option value="<?php echo $p->id; ?>" <?php echo $p->id == $pipeline->id ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($p->name); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php if (\Core\Auth::hasPermission('deals.create')): ?>
                <a href="<?php echo $config['url']; ?>/deals/create?pipeline=<?php echo $pipeline->id; ?>" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-lg"></i> معامله جدید
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Kanban Board -->
<div class="kanban-board" id="kanbanBoard">
    <?php foreach ($stages as $stage): ?>
    <div class="kanban-column" data-stage-id="<?php echo $stage->id; ?>">
        <div class="kanban-column-header" style="border-bottom-color: <?php echo $stage->color; ?>;">
            <div>
                <strong><?php echo htmlspecialchars($stage->name); ?></strong>
                <span class="badge bg-secondary ms-1"><?php echo count($deals[$stage->id] ?? []); ?></span>
            </div>
            <?php if (\Core\Auth::hasPermission('deals.create')): ?>
            <button class="btn btn-sm btn-outline-primary add-deal-btn" 
                    data-pipeline="<?php echo $pipeline->id; ?>" 
                    data-stage="<?php echo $stage->id; ?>"
                    title="افزودن سریع معامله">
                <i class="bi bi-plus"></i>
            </button>
            <?php endif; ?>
        </div>

        <div class="kanban-cards" style="min-height: 100px;">
            <?php foreach (($deals[$stage->id] ?? []) as $deal): ?>
            <div class="kanban-card" draggable="true" data-deal-id="<?php echo $deal->id; ?>"
                 onclick="location.href='<?php echo $config['url']; ?>/deals/view/<?php echo $deal->id; ?>'">
                <div style="font-weight: 500; margin-bottom: 8px; font-size: 14px;">
                    <?php echo htmlspecialchars($deal->title); ?>
                </div>
                <?php if ($deal->contact_name): ?>
                <div style="font-size: 12px; color: #666; margin-bottom: 5px;">
                    <i class="bi bi-person"></i> <?php echo htmlspecialchars($deal->contact_name); ?>
                </div>
                <?php endif; ?>
                <?php if ($deal->contact_phone): ?>
                <div style="font-size: 12px; color: #666; margin-bottom: 5px;">
                    <i class="bi bi-telephone"></i> <?php echo htmlspecialchars($deal->contact_phone); ?>
                </div>
                <?php endif; ?>
                <?php if ($deal->amount > 0): ?>
                <div class="amount">
                    <?php echo number_format($deal->amount); ?> ریال
                </div>
                <?php endif; ?>
                <?php if ($deal->assigned_name): ?>
                <div style="font-size: 11px; color: #999; margin-top: 5px;">
                    <i class="bi bi-person-badge"></i> <?php echo htmlspecialchars($deal->assigned_name); ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Quick Deal Modal -->
<div class="modal fade" id="quickDealModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="quickDealForm">
                <div class="modal-header">
                    <h5 class="modal-title">ایجاد معامله سریع</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="pipeline_id" class="pipeline-id" value="<?php echo $pipeline->id; ?>">
                    <input type="hidden" name="stage_id" class="stage-id">
                    
                    <div class="mb-3">
                        <label class="form-label">عنوان معامله *</label>
                        <input type="text" name="title" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">نام مخاطب</label>
                        <input type="text" name="contact_name" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">شماره تماس</label>
                        <input type="text" name="contact_phone" class="form-control" placeholder="09120000000">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">لغو</button>
                    <button type="submit" class="btn btn-primary">ایجاد معامله</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Drag and Drop for Kanban
$(document).ready(function() {
    let dragSrcEl = null;

    function handleDragStart(e) {
        dragSrcEl = this;
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/html', this.outerHTML);
        this.classList.add('dragging');
    }

    function handleDragOver(e) {
        if (e.preventDefault) {
            e.preventDefault();
        }
        e.dataTransfer.dropEffect = 'move';
        return false;
    }

    function handleDragEnter(e) {
        this.classList.add('over');
    }

    function handleDragLeave(e) {
        this.classList.remove('over');
    }

    function handleDrop(e) {
        if (e.stopPropagation) {
            e.stopPropagation();
        }

        if (dragSrcEl != this) {
            const dealId = dragSrcEl.dataset.dealId;
            const stageId = this.closest('.kanban-column').dataset.stageId;
            
            if (dealId && stageId) {
                $.ajax({
                    url: '<?php echo $config['url']; ?>/pipelines/update-stage',
                    method: 'POST',
                    data: {
                        deal_id: dealId,
                        stage_id: stageId
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        }
                    }
                });
            }
        }
        return false;
    }

    function handleDragEnd(e) {
        this.classList.remove('dragging');
        document.querySelectorAll('.kanban-column').forEach(col => {
            col.classList.remove('over');
        });
    }

    // Enable drag and drop on kanban cards
    document.querySelectorAll('.kanban-card').forEach(card => {
        card.addEventListener('dragstart', handleDragStart, false);
        card.addEventListener('dragenter', handleDragEnter, false);
        card.addEventListener('dragover', handleDragOver, false);
        card.addEventListener('dragleave', handleDragLeave, false);
        card.addEventListener('drop', handleDrop, false);
        card.addEventListener('dragend', handleDragEnd, false);
    });

    // Quick deal form
    $('#quickDealForm').on('submit', function(e) {
        e.preventDefault();
        var form = $(this);
        $.ajax({
            url: '<?php echo $config['url']; ?>/deals/convert',
            method: 'POST',
            data: form.serialize(),
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(response.message);
                }
            }
        });
    });
});
</script>