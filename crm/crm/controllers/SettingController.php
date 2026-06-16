<?php
namespace Controllers;

use Core\Auth;
use Core\Database;
use Core\Session;
use Core\View;
use Core\ActivityLog;

class SettingController
{
    public function index(): void
    {
        $db = Database::getInstance();
        $settings = $db->fetchAll("SELECT * FROM settings ORDER BY setting_group, setting_key");
        
        $groupedSettings = [];
        foreach ($settings as $s) {
            $groupedSettings[$s->setting_group][] = $s;
        }
        
        // Get all features status
        $features = [];
        $featureSettings = $db->fetchAll("SELECT * FROM settings WHERE setting_group = 'features'");
        foreach ($featureSettings as $fs) {
            $featureName = str_replace('feature_', '', $fs->setting_key);
            $features[$featureName] = $fs->setting_value == '1';
        }

        View::render('settings/index', [
            'title' => 'تنظیمات سیستم',
            'groupedSettings' => $groupedSettings,
            'features' => $features,
        ]);
    }

    public function update(): void
    {
        $settings = $_POST['settings'] ?? [];

        if (empty($settings)) {
            Session::setFlash('danger', 'هیچ مقداری برای ذخیره وجود ندارد.');
            View::redirect('/settings');
        }

        $db = Database::getInstance();
        
        foreach ($settings as $key => $value) {
            $db->update('settings', ['setting_value' => $value], 'setting_key = :key', [':key' => $key]);
        }

        ActivityLog::log('update_settings', 'settings', 0, 'تنظیمات سیستم به‌روزرسانی شد');
        Session::setFlash('success', 'تنظیمات با موفقیت ذخیره شد.');
        View::redirect('/settings');
    }

    public function toggleFeature(): void
    {
        $feature = $_POST['feature'] ?? '';
        $enabled = isset($_POST['enabled']) ? 1 : 0;

        if (empty($feature)) {
            echo json_encode(['success' => false, 'message' => 'ویژگی نامعتبر است']);
            exit;
        }

        $db = Database::getInstance();
        $key = 'feature_' . $feature;
        
        $db->update('settings', ['setting_value' => $enabled], 'setting_key = :key', [':key' => $key]);
        
        ActivityLog::log('toggle_feature', 'settings', 0, "ویژگی {$feature} " . ($enabled ? 'فعال' : 'غیرفعال') . " شد");
        
        echo json_encode(['success' => true, 'message' => 'تغییرات اعمال شد']);
        exit;
    }
}