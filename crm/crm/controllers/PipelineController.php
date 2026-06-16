<?php
namespace Controllers;

use Core\Auth;
use Core\Database;
use Core\Session;
use Core\View;
use Core\ActivityLog;

class PipelineController
{
    public function index(): void
    {
        $db = Database::getInstance();
        $pipelines = $db->fetchAll(
            "SELECT p.*, (SELECT COUNT(*) FROM stages WHERE pipeline_id = p.id) as stages_count,
                    (SELECT COUNT(*) FROM deals WHERE pipeline_id = p.id) as deals_count
             FROM pipelines p ORDER BY p.created_at DESC"
        );
        View::render('pipelines/index', ['title' => 'مدیریت پایپ لاین‌ها', 'pipelines' => $pipelines]);
    }

    public function create(): void
    {
        View::render('pipelines/create', ['title' => 'ایجاد پایپ لاین جدید']);
    }

    public function store(): void
    {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $stages = $_POST['stages'] ?? [];

        if (empty($name) || empty($stages)) {
            Session::setFlash('danger', 'لطفا نام و حداقل یک مرحله وارد کنید.');
            View::redirect('/pipelines/create');
        }

        $db = Database::getInstance();
        $db->beginTransaction();

        try {
            $pipelineId = $db->insert('pipelines', [
                'name' => $name,
                'description' => $description,
            ]);

            foreach ($stages as $index => $stage) {
                if (!empty(trim($stage['name']))) {
                    $db->insert('stages', [
                        'pipeline_id' => $pipelineId,
                        'name' => trim($stage['name']),
                        'color' => $stage['color'] ?? '#6B7280',
                        'order_index' => $index + 1,
                    ]);
                }
            }

            $db->commit();
            ActivityLog::log('create_pipeline', 'pipeline', $pipelineId, "پایپ لاین {$name} ایجاد شد");
            Session::setFlash('success', 'پایپ لاین با موفقیت ایجاد شد.');
        } catch (\Exception $e) {
            $db->rollback();
            Session::setFlash('danger', 'خطا در ایجاد پایپ لاین: ' . $e->getMessage());
        }

        View::redirect('/pipelines');
    }

    public function edit(array $params): void
    {
        $db = Database::getInstance();
        $pipeline = $db->fetch("SELECT * FROM pipelines WHERE id = :id", [':id' => $params['id']]);
        if (!$pipeline) {
            Session::setFlash('danger', 'پایپ لاین مورد نظر یافت نشد.');
            View::redirect('/pipelines');
        }
        $stages = $db->fetchAll("SELECT * FROM stages WHERE pipeline_id = :id ORDER BY order_index", [':id' => $params['id']]);
        View::render('pipelines/edit', ['title' => 'ویرایش پایپ لاین', 'pipeline' => $pipeline, 'stages' => $stages]);
    }

    public function update(array $params): void
    {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $stages = $_POST['stages'] ?? [];

        $db = Database::getInstance();
        $db->beginTransaction();

        try {
            $db->update('pipelines', ['name' => $name, 'description' => $description], 'id = :id', [':id' => $params['id']]);

            // Delete existing stages and re-insert
            $db->delete('stages', 'pipeline_id = :id', [':id' => $params['id']]);
            
            foreach ($stages as $index => $stage) {
                if (!empty(trim($stage['name']))) {
                    $db->insert('stages', [
                        'pipeline_id' => $params['id'],
                        'name' => trim($stage['name']),
                        'color' => $stage['color'] ?? '#6B7280',
                        'order_index' => $index + 1,
                    ]);
                }
            }

            $db->commit();
            ActivityLog::log('update_pipeline', 'pipeline', $params['id'], "پایپ لاین {$name} ویرایش شد");
            Session::setFlash('success', 'پایپ لاین با موفقیت ویرایش شد.');
        } catch (\Exception $e) {
            $db->rollback();
            Session::setFlash('danger', 'خطا در ویرایش: ' . $e->getMessage());
        }

        View::redirect('/pipelines');
    }

    public function delete(array $params): void
    {
        $db = Database::getInstance();
        $pipeline = $db->fetch("SELECT name FROM pipelines WHERE id = :id", [':id' => $params['id']]);
        
        if ($pipeline) {
            $db->delete('pipelines', 'id = :id', [':id' => $params['id']]);
            ActivityLog::log('delete_pipeline', 'pipeline', $params['id'], "پایپ لاین {$pipeline->name} حذف شد");
            Session::setFlash('success', 'پایپ لاین با موفقیت حذف شد.');
        }
        View::redirect('/pipelines');
    }

    public function kanban(array $params): void
    {
        $db = Database::getInstance();
        $pipeline = $db->fetch("SELECT * FROM pipelines WHERE id = :id AND is_active = 1", [':id' => $params['id']]);
        if (!$pipeline) {
            Session::setFlash('danger', 'پایپ لاین مورد نظر یافت نشد.');
            View::redirect('/pipelines');
        }

        $stages = $db->fetchAll("SELECT * FROM stages WHERE pipeline_id = :id AND is_active = 1 ORDER BY order_index", [':id' => $params['id']]);
        
        $user = Auth::user();
        $deals = [];
        foreach ($stages as $stage) {
            if ($user->role_slug === 'operator') {
                $deals[$stage->id] = $db->fetchAll(
                    "SELECT d.*, c.full_name as contact_name, c.phone as contact_phone, 
                            u.full_name as assigned_name
                     FROM deals d 
                     LEFT JOIN contacts c ON d.contact_id = c.id 
                     LEFT JOIN users u ON d.assigned_to = u.id 
                     WHERE d.stage_id = :stage_id AND d.is_lost = 0
                     AND (d.assigned_to = :user_id OR d.created_by = :user_id2)
                     ORDER BY d.updated_at DESC",
                    [':stage_id' => $stage->id, ':user_id' => $user->id, ':user_id2' => $user->id]
                );
            } else {
                $deals[$stage->id] = $db->fetchAll(
                    "SELECT d.*, c.full_name as contact_name, c.phone as contact_phone,
                            u.full_name as assigned_name
                     FROM deals d 
                     LEFT JOIN contacts c ON d.contact_id = c.id 
                     LEFT JOIN users u ON d.assigned_to = u.id 
                     WHERE d.stage_id = :stage_id AND d.is_lost = 0
                     ORDER BY d.updated_at DESC",
                    [':stage_id' => $stage->id]
                );
            }
        }

        $pipelines = $db->fetchAll("SELECT id, name FROM pipelines WHERE is_active = 1");

        View::render('pipelines/kanban', [
            'title' => "کانبان - {$pipeline->name}",
            'pipeline' => $pipeline,
            'stages' => $stages,
            'deals' => $deals,
            'pipelines' => $pipelines,
        ]);
    }

    public function updateStage(): void
    {
        $dealId = (int)($_POST['deal_id'] ?? 0);
        $stageId = (int)($_POST['stage_id'] ?? 0);

        if ($dealId && $stageId) {
            $db = Database::getInstance();
            $db->update('deals', ['stage_id' => $stageId], 'id = :id', [':id' => $dealId]);
            
            // Check if this stage is the "won" or "lost" stage for auto-update
            $stage = $db->fetch("SELECT s.name, p.name as pipeline_name FROM stages s JOIN pipelines p ON s.pipeline_id = p.id WHERE s.id = :id", [':id' => $stageId]);
            
            $isWon = stripos($stage->name, 'پرداخت شده') !== false || stripos($stage->name, 'موفق') !== false;
            $isLost = stripos($stage->name, 'لغو') !== false;
            
            if ($isWon) {
                $db->update('deals', ['is_won' => 1, 'closed_at' => date('Y-m-d H:i:s')], 'id = :id', [':id' => $dealId]);
            } elseif ($isLost) {
                $db->update('deals', ['is_lost' => 1, 'closed_at' => date('Y-m-d H:i:s')], 'id = :id', [':id' => $dealId]);
            }

            ActivityLog::log('move_deal', 'deal', $dealId, "معامله به مرحله {$stage->name} منتقل شد");
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'اطلاعات نامعتبر']);
        }
        exit;
    }
}