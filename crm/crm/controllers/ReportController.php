<?php
namespace Controllers;

use Core\Auth;
use Core\Database;
use Core\View;

class ReportController
{
    public function index(): void
    {
        $db = Database::getInstance();
        
        // Summary statistics
        $totalDeals = $db->fetch("SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as total FROM deals WHERE is_lost = 0");
        $wonDeals = $db->fetch("SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as total FROM deals WHERE is_won = 1");
        $conversionRate = $totalDeals->count > 0 ? round(($wonDeals->count / $totalDeals->count) * 100) : 0;
        
        // Monthly sales
        $monthlySales = $db->fetchAll(
            "SELECT DATE_FORMAT(closed_at, '%Y-%m') as month, 
                    COUNT(*) as deals_count, 
                    COALESCE(SUM(amount), 0) as total
             FROM deals 
             WHERE is_won = 1 AND closed_at IS NOT NULL
             GROUP BY DATE_FORMAT(closed_at, '%Y-%m')
             ORDER BY month DESC LIMIT 12"
        );

        // Sales by pipeline
        $salesByPipeline = $db->fetchAll(
            "SELECT p.name, COUNT(d.id) as deals_count, COALESCE(SUM(d.amount), 0) as total
             FROM pipelines p
             LEFT JOIN deals d ON d.pipeline_id = p.id AND d.is_won = 1
             GROUP BY p.id, p.name"
        );

        // Top performing users
        $topUsers = $db->fetchAll(
            "SELECT u.full_name, COUNT(d.id) as deals_count, COALESCE(SUM(d.amount), 0) as total
             FROM users u
             JOIN deals d ON d.assigned_to = u.id AND d.is_won = 1
             GROUP BY u.id, u.full_name
             ORDER BY total DESC LIMIT 10"
        );

        // Lost reasons
        $lostReasons = $db->fetchAll(
            "SELECT lost_reason, COUNT(*) as count, COALESCE(SUM(amount), 0) as total
             FROM deals 
             WHERE is_lost = 1 AND lost_reason IS NOT NULL AND lost_reason != ''
             GROUP BY lost_reason
             ORDER BY count DESC"
        );

        View::render('reports/index', [
            'title' => 'گزارشات',
            'totalDeals' => $totalDeals,
            'wonDeals' => $wonDeals,
            'conversionRate' => $conversionRate,
            'monthlySales' => $monthlySales,
            'salesByPipeline' => $salesByPipeline,
            'topUsers' => $topUsers,
            'lostReasons' => $lostReasons,
        ]);
    }

    public function sales(): void
    {
        $db = Database::getInstance();
        
        $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
        $dateTo = $_GET['date_to'] ?? date('Y-m-d');
        $pipelineId = $_GET['pipeline_id'] ?? '';

        $where = "WHERE d.created_at BETWEEN :date_from AND :date_to 23:59:59";
        $params = [':date_from' => $dateFrom . ' 00:00:00', ':date_to' => $dateTo];

        if ($pipelineId) {
            $where .= " AND d.pipeline_id = :pipeline_id";
            $params[':pipeline_id'] = $pipelineId;
        }

        $deals = $db->fetchAll(
            "SELECT d.*, s.name as stage_name, c.full_name as contact_name, 
                    u.full_name as assigned_name, p.name as pipeline_name
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

        View::render('reports/sales', [
            'title' => 'گزارش فروش',
            'deals' => $deals,
            'pipelines' => $pipelines,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'selectedPipeline' => $pipelineId,
        ]);
    }

    public function pipeline(): void
    {
        $db = Database::getInstance();
        
        $pipelineAnalysis = $db->fetchAll(
            "SELECT p.name as pipeline_name, s.name as stage_name, s.color,
                    COUNT(d.id) as deals_count, COALESCE(SUM(d.amount), 0) as total_amount
             FROM pipelines p
             CROSS JOIN stages s ON s.pipeline_id = p.id
             LEFT JOIN deals d ON d.stage_id = s.id AND d.is_lost = 0
             WHERE p.is_active = 1 AND s.is_active = 1
             GROUP BY p.id, p.name, s.id, s.name, s.color, s.order_index
             ORDER BY p.name, s.order_index"
        );

        $pipelineData = [];
        foreach ($pipelineAnalysis as $row) {
            $pipelineData[$row->pipeline_name][] = $row;
        }

        View::render('reports/pipeline', [
            'title' => 'گزارش پایپ لاین',
            'pipelineData' => $pipelineData,
        ]);
    }

    public function activities(): void
    {
        $db = Database::getInstance();
        
        $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
        $dateTo = $_GET['date_to'] ?? date('Y-m-d');
        $userId = $_GET['user_id'] ?? '';

        $where = "WHERE da.created_at BETWEEN :date_from AND :date_to 23:59:59";
        $params = [':date_from' => $dateFrom . ' 00:00:00', ':date_to' => $dateTo];

        if ($userId) {
            $where .= " AND da.user_id = :user_id";
            $params[':user_id'] = $userId;
        }

        $activities = $db->fetchAll(
            "SELECT da.*, u.full_name as user_name, d.title as deal_title
             FROM deal_activities da 
             LEFT JOIN users u ON da.user_id = u.id 
             LEFT JOIN deals d ON da.deal_id = d.id 
             {$where}
             ORDER BY da.created_at DESC",
            $params
        );

        $users = $db->fetchAll("SELECT id, full_name FROM users WHERE is_active = 1");

        // Activity summary
        $activitySummary = $db->fetchAll(
            "SELECT da.type, COUNT(*) as count
             FROM deal_activities da
             {$where}
             GROUP BY da.type
             ORDER BY count DESC",
            $params
        );

        View::render('reports/activities', [
            'title' => 'گزارش فعالیت‌ها',
            'activities' => $activities,
            'users' => $users,
            'activitySummary' => $activitySummary,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'selectedUser' => $userId,
        ]);
    }

    public function contacts(): void
    {
        $db = Database::getInstance();
        
        $contacts = $db->fetchAll(
            "SELECT c.*, 
                    (SELECT COUNT(*) FROM deals WHERE contact_id = c.id) as deals_count,
                    (SELECT COUNT(*) FROM deals WHERE contact_id = c.id AND is_won = 1) as won_deals,
                    (SELECT COALESCE(SUM(amount), 0) FROM deals WHERE contact_id = c.id AND is_won = 1) as total_purchases,
                    (SELECT MAX(created_at) FROM deals WHERE contact_id = c.id) as last_deal_date
             FROM contacts c
             ORDER BY total_purchases DESC"
        );

        // Source analysis
        $sources = $db->fetchAll(
            "SELECT source, COUNT(*) as count 
             FROM contacts 
             WHERE source IS NOT NULL AND source != ''
             GROUP BY source 
             ORDER BY count DESC"
        );

        View::render('reports/contacts', [
            'title' => 'گزارش مخاطبان',
            'contacts' => $contacts,
            'sources' => $sources,
        ]);
    }
}