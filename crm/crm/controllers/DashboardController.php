<?php
namespace Controllers;

use Core\Auth;
use Core\Database;
use Core\View;

class DashboardController
{
    public function index(): void
    {
        $db = Database::getInstance();
        $user = Auth::user();

        // Statistics
        $totalDeals = $db->fetch("SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as total FROM deals WHERE is_lost = 0");
        $wonDeals = $db->fetch("SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as total FROM deals WHERE is_won = 1");
        $lostDeals = $db->fetch("SELECT COUNT(*) as count FROM deals WHERE is_lost = 1");
        $totalContacts = $db->fetch("SELECT COUNT(*) as count FROM contacts");
        $totalPipelines = $db->fetch("SELECT COUNT(*) as count FROM pipelines WHERE is_active = 1");

        // Recent deals
        if ($user->role_slug === 'operator') {
            $recentDeals = $db->fetchAll(
                "SELECT d.*, s.name as stage_name, s.color as stage_color, c.full_name as contact_name, p.name as pipeline_name
                 FROM deals d 
                 JOIN stages s ON d.stage_id = s.id 
                 JOIN pipelines p ON d.pipeline_id = p.id 
                 LEFT JOIN contacts c ON d.contact_id = c.id 
                 WHERE d.assigned_to = :user_id OR d.created_by = :user_id2
                 ORDER BY d.created_at DESC LIMIT 10",
                [':user_id' => $user->id, ':user_id2' => $user->id]
            );
        } else {
            $recentDeals = $db->fetchAll(
                "SELECT d.*, s.name as stage_name, s.color as stage_color, c.full_name as contact_name, p.name as pipeline_name,
                        u.full_name as assigned_name
                 FROM deals d 
                 JOIN stages s ON d.stage_id = s.id 
                 JOIN pipelines p ON d.pipeline_id = p.id 
                 LEFT JOIN contacts c ON d.contact_id = c.id 
                 LEFT JOIN users u ON d.assigned_to = u.id 
                 ORDER BY d.created_at DESC LIMIT 10"
            );
        }

        // Deals by stage for chart
        $dealsByStage = $db->fetchAll(
            "SELECT s.name, s.color, COUNT(d.id) as count, COALESCE(SUM(d.amount), 0) as total
             FROM stages s
             LEFT JOIN deals d ON d.stage_id = s.id AND d.is_lost = 0
             WHERE s.is_active = 1
             GROUP BY s.id, s.name, s.color, s.order_index
             ORDER BY s.order_index"
        );

        // Recent activities
        $recentActivities = $db->fetchAll(
            "SELECT al.*, u.full_name as user_name 
             FROM activity_logs al 
             LEFT JOIN users u ON al.user_id = u.id 
             ORDER BY al.created_at DESC LIMIT 10"
        );

        // Upcoming follow-ups
        $upcomingFollowUps = $db->fetchAll(
            "SELECT da.*, d.title as deal_title, c.full_name as contact_name
             FROM deal_activities da
             JOIN deals d ON da.deal_id = d.id
             LEFT JOIN contacts c ON d.contact_id = c.id
             WHERE da.is_done = 0 AND da.reminder_at IS NOT NULL
             ORDER BY da.reminder_at ASC LIMIT 5"
        );

        View::render('dashboard/index', [
            'title' => 'داشبورد',
            'totalDeals' => $totalDeals,
            'wonDeals' => $wonDeals,
            'lostDeals' => $lostDeals,
            'totalContacts' => $totalContacts,
            'totalPipelines' => $totalPipelines,
            'recentDeals' => $recentDeals,
            'dealsByStage' => $dealsByStage,
            'recentActivities' => $recentActivities,
            'upcomingFollowUps' => $upcomingFollowUps,
        ]);
    }
}