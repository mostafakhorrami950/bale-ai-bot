<?php
namespace Controllers;

use Core\Auth;
use Core\Database;
use Core\Session;
use Core\View;
use Core\ActivityLog;

class DealController
{
    public function index(): void
    {
        $db = Database::getInstance();
        $user = Auth::user();
        
        $search = $_GET['search'] ?? '';
        $stageId = $_GET['stage_id'] ?? '';
        $pipelineId = $_GET['pipeline_id'] ?? '';
        $assignedTo = $_GET['assigned_to'] ?? '';
        $status = $_GET['status'] ?? '';
        
        $where = "WHERE 1=1";
        $params = [];
        
        if ($user->role_slug === 'operator') {
            $where .= " AND (d.assigned_to = :user_id OR d.created_by = :user_id2)";
            $params[':user_id'] = $user->id;
            $params[':user_id2'] = $user->id;
        }
        
        if ($search) {
            $where .= " AND (d.title LIKE :search OR c.full_name LIKE :search2)";
            $params[':search'] = "%{$search}%";
            $params[':search2'] = "%{$search}%";
        }
        if ($stageId) {
            $where .= " AND d.stage_id = :stage_id";
            $params[':stage_id'] = $stageId;
        }
        if ($pipelineId) {
            $where .= " AND d.pipeline_id = :pipeline_id";
            $params[':pipeline_id'] = $pipelineId;
        }
        if ($assignedTo) {
            $where .= " AND d.assigned_to = :assigned_to";
            $params[':assigned_to'] = $assignedTo;
        }
        if ($status === 'won') {
            $where .= " AND d.is_won = 1";
        } elseif ($status === 'lost') {
            $where .= " AND d.is_lost = 1";
        } elseif ($status === 'open') {
            $where .= " AND d.is_won = 0 AND d.is_lost = 0";
        }

        $deals = $db->fetchAll(
            "SELECT d.*, s.name as stage_name, s.color as stage_color, 
                    c.full_name as contact_name, c.phone as contact_phone,
                    p.name as pipeline_name, u.full_name as assigned_name
             FROM deals d 
             JOIN stages s ON d.stage_id = s.id 
             JOIN pipelines p ON d.pipeline_id = p.id 
             LEFT JOIN contacts c ON d.contact_id = c.id 
             LEFT JOIN users u ON d.assigned_to = u.id 
             {$where}
             ORDER BY d.created_at DESC",
            $params
        );

        $pipelines = $db->fetchAll("SELECT id, name FROM pipelines WHERE is_active = 1");
        $users = $db->fetchAll("SELECT id, full_name FROM users WHERE is_active = 1");
        $stages = $db->fetchAll("SELECT s.*, p.name as pipeline_name FROM stages s JOIN pipelines p ON s.pipeline_id = p.id WHERE s.is_active = 1 ORDER BY p.name, s.order_index");

        View::render('deals/index', [
            'title' => 'مدیریت معاملات',
            'deals' => $deals,
            'pipelines' => $pipelines,
            'users' => $users,
            'stages' => $stages,
            'search' => $search,
            'selectedStage' => $stageId,
            'selectedPipeline' => $pipelineId,
            'selectedAssigned' => $assignedTo,
            'selectedStatus' => $status,
        ]);
    }

    public function create(): void
    {
        $db = Database::getInstance();
        $pipelines = $db->fetchAll("SELECT * FROM pipelines WHERE is_active = 1");
        $contacts = $db->fetchAll("SELECT id, full_name, phone FROM contacts ORDER BY full_name");
        $users = $db->fetchAll("SELECT id, full_name FROM users WHERE is_active = 1");
        
        // Get default pipeline stages
        $defaultPipeline = $db->fetch("SELECT id FROM pipelines WHERE is_default = 1");
        $stages = [];
        if ($defaultPipeline) {
            $stages = $db->fetchAll("SELECT * FROM stages WHERE pipeline_id = :id AND is_active = 1 ORDER BY order_index", [':id' => $defaultPipeline->id]);
        }

        View::render('deals/create', [
            'title' => 'ایجاد معامله جدید',
            'pipelines' => $pipelines,
            'contacts' => $contacts,
            'users' => $users,
            'stages' => $stages,
        ]);
    }

    public function store(): void
    {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $amount = str_replace(',', '', $_POST['amount'] ?? '0');
        $pipelineId = (int)($_POST['pipeline_id'] ?? 0);
        $stageId = (int)($_POST['stage_id'] ?? 0);
        $contactId = (int)($_POST['contact_id'] ?? 0) ?: null;
        $assignedTo = (int)($_POST['assigned_to'] ?? 0) ?: null;
        $source = trim($_POST['source'] ?? '');
        $expectedCloseDate = $_POST['expected_close_date'] ?? null;

        if (empty($title) || empty($pipelineId) || empty($stageId)) {
            Session::setFlash('danger', 'لطفا فیلدهای ضروری را پر کنید.');
            View::redirect('/deals/create');
        }

        $db = Database::getInstance();
        $dealId = $db->insert('deals', [
            'title' => $title,
            'description' => $description,
            'amount' => $amount,
            'pipeline_id' => $pipelineId,
            'stage_id' => $stageId,
            'contact_id' => $contactId,
            'assigned_to' => $assignedTo,
            'source' => $source,
            'expected_close_date' => $expectedCloseDate,
            'created_by' => Auth::id(),
        ]);

        ActivityLog::log('create_deal', 'deal', $dealId, "معامله {$title} ایجاد شد");
        Session::setFlash('success', 'معامله با موفقیت ایجاد شد.');
        View::redirect('/deals/view/' . $dealId);
    }

    public function view(array $params): void
    {
        $db = Database::getInstance();
        $deal = $db->fetch(
            "SELECT d.*, s.name as stage_name, s.color as stage_color, s.pipeline_id,
                    p.name as pipeline_name,
                    c.full_name as contact_name, c.phone as contact_phone, c.email as contact_email,
                    c.national_code, c.passport_number, c.address, c.company,
                    u.full_name as assigned_name, cr.full_name as creator_name
             FROM deals d 
             JOIN stages s ON d.stage_id = s.id 
             JOIN pipelines p ON d.pipeline_id = p.id 
             LEFT JOIN contacts c ON d.contact_id = c.id 
             LEFT JOIN users u ON d.assigned_to = u.id 
             LEFT JOIN users cr ON d.created_by = cr.id 
             WHERE d.id = :id",
            [':id' => $params['id']]
        );

        if (!$deal) {
            Session::setFlash('danger', 'معامله مورد نظر یافت نشد.');
            View::redirect('/deals');
        }

        $activities = $db->fetchAll(
            "SELECT da.*, u.full_name as user_name 
             FROM deal_activities da 
             LEFT JOIN users u ON da.user_id = u.id 
             WHERE da.deal_id = :id 
             ORDER BY da.activity_date DESC",
            [':id' => $params['id']]
        );

        $payments = $db->fetchAll(
            "SELECT * FROM payments WHERE deal_id = :id ORDER BY created_at DESC",
            [':id' => $params['id']]
        );

        $smsHistory = $db->fetchAll(
            "SELECT * FROM sms_history WHERE deal_id = :id ORDER BY created_at DESC",
            [':id' => $params['id']]
        );

        $logs = ActivityLog::getByEntity('deal', $params['id']);

        View::render('deals/view', [
            'title' => "معامله: {$deal->title}",
            'deal' => $deal,
            'activities' => $activities,
            'payments' => $payments,
            'smsHistory' => $smsHistory,
            'logs' => $logs,
        ]);
    }

    public function edit(array $params): void
    {
        $db = Database::getInstance();
        $deal = $db->fetch("SELECT * FROM deals WHERE id = :id", [':id' => $params['id']]);
        if (!$deal) {
            Session::setFlash('danger', 'معامله مورد نظر یافت نشد.');
            View::redirect('/deals');
        }

        $pipelines = $db->fetchAll("SELECT * FROM pipelines WHERE is_active = 1");
        $stages = $db->fetchAll("SELECT * FROM stages WHERE pipeline_id = :id AND is_active = 1 ORDER BY order_index", [':id' => $deal->pipeline_id]);
        $contacts = $db->fetchAll("SELECT id, full_name, phone FROM contacts ORDER BY full_name");
        $users = $db->fetchAll("SELECT id, full_name FROM users WHERE is_active = 1");

        View::render('deals/edit', [
            'title' => 'ویرایش معامله',
            'deal' => $deal,
            'pipelines' => $pipelines,
            'stages' => $stages,
            'contacts' => $contacts,
            'users' => $users,
        ]);
    }

    public function update(array $params): void
    {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $amount = str_replace(',', '', $_POST['amount'] ?? '0');
        $pipelineId = (int)($_POST['pipeline_id'] ?? 0);
        $stageId = (int)($_POST['stage_id'] ?? 0);
        $contactId = (int)($_POST['contact_id'] ?? 0) ?: null;
        $assignedTo = (int)($_POST['assigned_to'] ?? 0) ?: null;
        $source = trim($_POST['source'] ?? '');
        $expectedCloseDate = $_POST['expected_close_date'] ?? null;
        $probability = (int)($_POST['probability'] ?? 0);
        $lostReason = trim($_POST['lost_reason'] ?? '');

        $db = Database::getInstance();
        $db->update('deals', [
            'title' => $title,
            'description' => $description,
            'amount' => $amount,
            'pipeline_id' => $pipelineId,
            'stage_id' => $stageId,
            'contact_id' => $contactId,
            'assigned_to' => $assignedTo,
            'source' => $source,
            'expected_close_date' => $expectedCloseDate,
            'probability' => $probability,
            'lost_reason' => $lostReason,
        ], 'id = :id', [':id' => $params['id']]);

        ActivityLog::log('update_deal', 'deal', $params['id'], "معامله {$title} ویرایش شد");
        Session::setFlash('success', 'معامله با موفقیت ویرایش شد.');
        View::redirect('/deals/view/' . $params['id']);
    }

    public function delete(array $params): void
    {
        $db = Database::getInstance();
        $deal = $db->fetch("SELECT title FROM deals WHERE id = :id", [':id' => $params['id']]);
        if ($deal) {
            $db->delete('deals', 'id = :id', [':id' => $params['id']]);
            ActivityLog::log('delete_deal', 'deal', $params['id'], "معامله {$deal->title} حذف شد");
            Session::setFlash('success', 'معامله با موفقیت حذف شد.');
        }
        View::redirect('/deals');
    }

    public function addActivity(array $params): void
    {
        $type = $_POST['type'] ?? 'note';
        $subject = trim($_POST['subject'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $activityDate = $_POST['activity_date'] ?? date('Y-m-d H:i:s');
        $reminderAt = $_POST['reminder_at'] ?? null;

        $db = Database::getInstance();
        $db->insert('deal_activities', [
            'deal_id' => $params['id'],
            'user_id' => Auth::id(),
            'type' => $type,
            'subject' => $subject,
            'description' => $description,
            'activity_date' => $activityDate,
            'reminder_at' => $reminderAt ?: null,
        ]);

        ActivityLog::log('add_activity', 'deal', $params['id'], "فعالیت {$type} برای معامله ثبت شد");
        Session::setFlash('success', 'فعالیت با موفقیت ثبت شد.');
        View::redirect('/deals/view/' . $params['id']);
    }

    public function convertToDeal(): void
    {
        $db = Database::getInstance();
        // Quick deal creation from kanban
        $title = trim($_POST['title'] ?? '');
        $pipelineId = (int)($_POST['pipeline_id'] ?? 0);
        $stageId = (int)($_POST['stage_id'] ?? 0);
        $contactName = trim($_POST['contact_name'] ?? '');
        $contactPhone = trim($_POST['contact_phone'] ?? '');

        if (empty($title)) {
            echo json_encode(['success' => false, 'message' => 'عنوان معامله الزامی است']);
            exit;
        }

        $db->beginTransaction();
        try {
            $contactId = null;
            if ($contactPhone) {
                $contact = $db->fetch("SELECT id FROM contacts WHERE phone = :phone", [':phone' => $contactPhone]);
                if ($contact) {
                    $contactId = $contact->id;
                } elseif ($contactName) {
                    $contactId = $db->insert('contacts', [
                        'full_name' => $contactName,
                        'phone' => $contactPhone,
                        'created_by' => Auth::id(),
                    ]);
                }
            }

            $dealId = $db->insert('deals', [
                'title' => $title,
                'pipeline_id' => $pipelineId,
                'stage_id' => $stageId,
                'contact_id' => $contactId,
                'created_by' => Auth::id(),
            ]);

            $db->commit();
            echo json_encode(['success' => true, 'deal_id' => $dealId]);
        } catch (\Exception $e) {
            $db->rollback();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}