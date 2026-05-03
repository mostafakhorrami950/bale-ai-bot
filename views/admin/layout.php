<?php
/**
 * Shared admin layout.
 * Every admin page should include this file at the top.
 * Sections:
 *   $pageTitle — browser tab title
 *   $pageContent — the main HTML content (rendered after the sidebar opens)
 *   $activeMenu — which sidebar item is highlighted
 */
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle ?? 'پنل مدیریت'); ?> | مدیریت ربات بله</title>
    <!-- Bootstrap 5 RTL CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body {
            font-family: Tahoma, Arial, sans-serif;
            background: #f4f6f9;
            overflow-x: hidden;
        }
        .sidebar {
            position: fixed;
            top: 0;
            right: 0;
            width: 250px;
            height: 100vh;
            background: #2d3436;
            color: #fff;
            z-index: 1000;
            overflow-y: auto;
            transition: all 0.3s;
        }
        .sidebar-brand {
            padding: 20px;
            font-size: 1.2rem;
            font-weight: bold;
            border-bottom: 1px solid #404b4d;
            text-align: center;
            background: #1e272e;
        }
        .sidebar-brand small {
            display: block;
            font-size: 0.75rem;
            color: #adb5bd;
            font-weight: normal;
            margin-top: 4px;
        }
        .sidebar .nav-link {
            color: #b2bec3;
            padding: 12px 20px;
            border-bottom: 1px solid #404b4d;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .sidebar .nav-link:hover {
            background: #404b4d;
            color: #fff;
        }
        .sidebar .nav-link.active {
            background: #0984e3;
            color: #fff;
            border-color: #0984e3;
        }
        .sidebar .nav-link i {
            width: 20px;
            text-align: center;
        }
        .main-content {
            margin-right: 250px;
            padding: 20px;
            min-height: 100vh;
        }
        .topbar {
            background: #fff;
            padding: 15px 25px;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.04);
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .topbar h4 {
            margin: 0;
            color: #2d3436;
        }
        .topbar .admin-info {
            color: #636e72;
            font-size: 0.9rem;
        }
        .stat-card {
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            transition: transform 0.2s;
            height: 100%;
            border-right: 4px solid #0984e3;
        }
        .stat-card:hover {
            transform: translateY(-3px);
        }
        .stat-card .stat-icon {
            font-size: 2rem;
            margin-bottom: 10px;
        }
        .stat-card .stat-number {
            font-size: 1.8rem;
            font-weight: bold;
            color: #2d3436;
        }
        .stat-card .stat-label {
            color: #636e72;
            font-size: 0.9rem;
        }
        .table-container {
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        .table-container h5 {
            margin-bottom: 15px;
            color: #2d3436;
            border-bottom: 2px solid #f1f2f6;
            padding-bottom: 10px;
        }
        .badge-active {
            background: #00b894;
            color: #fff;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
        }
        .badge-inactive {
            background: #d63031;
            color: #fff;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
        }
        .page-title {
            margin-bottom: 20px;
            color: #2d3436;
        }
        .page-title h3 {
            margin: 0;
        }
        .page-title .breadcrumb {
            background: none;
            padding: 0;
            margin: 5px 0 0;
        }
        .btn-sm-icon {
            padding: 2px 8px;
            font-size: 0.8rem;
        }
        .footer-note {
            text-align: center;
            color: #b2bec3;
            padding: 20px 0;
            font-size: 0.85rem;
        }
        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
            }
            .main-content {
                margin-right: 0;
            }
        }
    </style>
</head>
<body>

<!-- Sidebar -->
<div class="sidebar">
    <div class="sidebar-brand">
        🤖 مدیریت ربات
        <small>پنل ادمین</small>
    </div>
    <nav class="nav flex-column">
        <a class="nav-link <?php echo ($activeMenu ?? '') === 'dashboard' ? 'active' : ''; ?>" href="dashboard.php">
            <i class="bi bi-speedometer2"></i> 📊 داشبورد
        </a>
        <a class="nav-link <?php echo ($activeMenu ?? '') === 'models' ? 'active' : ''; ?>" href="models.php">
            <i class="bi bi-cpu"></i> 📋 همه مدل‌ها
        </a>
        <a class="nav-link <?php echo ($activeMenu ?? '') === 'model_stats' ? 'active' : ''; ?>" href="model_stats.php">
            <i class="bi bi-bar-chart"></i> 📊 آمار مدل‌ها
        </a>
        <a class="nav-link <?php echo ($activeMenu ?? '') === 'modelstext2img' ? 'active' : ''; ?>" href="modelstext2img.php" style="padding-right:40px;font-size:0.9rem;">
            <i class="bi bi-image"></i> 🎨 ساخت تصویر
        </a>
        <a class="nav-link <?php echo ($activeMenu ?? '') === 'modelsimg2img' ? 'active' : ''; ?>" href="modelsimg2img.php" style="padding-right:40px;font-size:0.9rem;">
            <i class="bi bi-pencil-square"></i> 🖼 ویرایش تصویر
        </a>
        <a class="nav-link <?php echo ($activeMenu ?? '') === 'modelstext' ? 'active' : ''; ?>" href="modelstext.php" style="padding-right:40px;font-size:0.9rem;">
            <i class="bi bi-chat-dots"></i> 📝 متنی
        </a>
        <a class="nav-link <?php echo ($activeMenu ?? '') === 'modelsvideo' ? 'active' : ''; ?>" href="modelsvideo.php" style="padding-right:40px;font-size:0.9rem;">
            <i class="bi bi-camera-reels"></i> 🎬 ویدئو
        </a>
        <a class="nav-link <?php echo ($activeMenu ?? '') === 'plans' ? 'active' : ''; ?>" href="plans.php">
            <i class="bi bi-credit-card"></i> 💰 پلن‌های پرداخت
        </a>
        <a class="nav-link <?php echo ($activeMenu ?? '') === 'apikeys' ? 'active' : ''; ?>" href="api_keys.php">
            <i class="bi bi-key"></i> 🔑 API Keyها
        </a>
        <a class="nav-link <?php echo ($activeMenu ?? '') === 'users' ? 'active' : ''; ?>" href="users.php">
            <i class="bi bi-people"></i> 👥 کاربران
        </a>
        <a class="nav-link <?php echo ($activeMenu ?? '') === 'channels' ? 'active' : ''; ?>" href="channels.php">
            <i class="bi bi-megaphone"></i> 📢 کانال‌ها
        </a>
        <a class="nav-link <?php echo ($activeMenu ?? '') === 'payment_logs' ? 'active' : ''; ?>" href="payment_logs.php">
            <i class="bi bi-journal-text"></i> 📋 لاگ پرداخت‌ها
        </a>
        <a class="nav-link <?php echo ($activeMenu ?? '') === 'chat_list' ? 'active' : ''; ?>" href="chat_list.php">
            <i class="bi bi-chat-dots"></i> 💬 مکالمات
        </a>
        <a class="nav-link <?php echo ($activeMenu ?? '') === 'broadcast' ? 'active' : ''; ?>" href="broadcast.php">
            <i class="bi bi-broadcast"></i> 📢 ارسال همگانی
        </a>
        <a class="nav-link <?php echo ($activeMenu ?? '') === 'settings' ? 'active' : ''; ?>" href="settings.php">
            <i class="bi bi-gear"></i> ⚙️ تنظیمات
        </a>
        <a class="nav-link <?php echo ($activeMenu ?? '') === 'repair' ? 'active' : ''; ?>" href="repair_db.php">
            <i class="bi bi-tools"></i> 🔧 تعمیر دیتابیس
        </a>
        <hr style="border-color:#404b4d; margin:10px 0;">
        <a class="nav-link" href="logout.php" style="color:#d63031;">
            <i class="bi bi-box-arrow-left"></i> 🚪 خروج
        </a>
    </nav>
</div>

<!-- Main Content -->
<div class="main-content">
    <!-- Top Bar -->
    <div class="topbar">
        <h4><?php echo htmlspecialchars($pageTitle ?? 'داشبورد'); ?></h4>
        <div class="admin-info">
            <i class="bi bi-person-circle"></i>
            <?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'مدیر'); ?>
        </div>
    </div>

    <!-- Page Content -->
    <?php echo $pageContent ?? ''; ?>

    <!-- Footer -->
    <div class="footer-note">
        <i class="bi bi-robot"></i> ربات هوش مصنوعی بله &mdash; پنل مدیریت
    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>