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
    <!-- Vazir Font (modern Persian font) -->
    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet">
    <style>
        :root {
            --sidebar-bg: #1a1a2e;
            --sidebar-hover: #16213e;
            --sidebar-active: #0f3460;
            --accent: #e94560;
            --radius: 12px;
        }
        body {
            font-family: 'Vazirmatn', Tahoma, Arial, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            background-attachment: fixed;
            min-height: 100vh;
            overflow-x: hidden;
        }
        /* ─── Sidebar ─── */
        .sidebar {
            position: fixed;
            top: 0;
            right: 0;
            width: 260px;
            height: 100vh;
            background: var(--sidebar-bg);
            color: #e0e0e0;
            z-index: 1000;
            overflow-y: auto;
            transition: all 0.35s cubic-bezier(.4,.25,.3,1);
            box-shadow: -4px 0 15px rgba(0,0,0,0.15);
            scrollbar-width: thin;
        }
        .sidebar::-webkit-scrollbar { width: 5px; }
        .sidebar::-webkit-scrollbar-track { background: transparent; }
        .sidebar::-webkit-scrollbar-thumb { background: var(--accent); border-radius: 10px; }
        .sidebar-brand {
            padding: 25px 20px 20px;
            font-size: 1.3rem;
            font-weight: bold;
            text-align: center;
            background: linear-gradient(135deg, var(--sidebar-bg), var(--sidebar-active));
            border-bottom: 2px solid var(--accent);
            color: #fff;
        }
        .sidebar-brand small {
            display: block;
            font-size: 0.7rem;
            color: #8899aa;
            margin-top: 6px;
        }
        .sidebar .nav-link {
            color: #b0b8c8;
            padding: 13px 22px;
            border-bottom: 1px solid rgba(255,255,255,0.03);
            transition: all 0.25s;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 0.9rem;
        }
        .sidebar .nav-link:hover {
            background: var(--sidebar-hover);
            color: #fff;
            padding-right: 28px;
        }
        .sidebar .nav-link.active {
            background: var(--sidebar-active);
            color: #fff;
            border-right: 4px solid var(--accent);
        }
        /* ─── Main ─── */
        .main-content {
            margin-right: 260px;
            padding: 25px;
            min-height: 100vh;
            transition: margin 0.35s;
        }
        /* ─── Topbar ─── */
        .topbar {
            background: rgba(255,255,255,0.9);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            padding: 15px 25px;
            border-radius: var(--radius);
            box-shadow: 0 4px 15px rgba(0,0,0,0.06);
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 1px solid rgba(255,255,255,0.8);
        }
        .topbar h4 { margin: 0; color: var(--sidebar-bg); font-weight: 700; font-size: 1.2rem; }
        .topbar .admin-info {
            color: #666;
            background: rgba(0,0,0,0.03);
            padding: 6px 15px;
            border-radius: 30px;
            font-size: 0.85rem;
        }
        /* ─── Stat Card ─── */
        .stat-card {
            background: #fff;
            border-radius: var(--radius);
            padding: 22px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            transition: all 0.3s;
            height: 100%;
            border-right: 4px solid var(--accent);
        }
        .stat-card:hover { transform:translateY(-5px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
        .stat-card .stat-icon { font-size: 2rem; margin-bottom: 10px; }
        .stat-card .stat-number { font-size: 2rem; font-weight: 800; color: var(--sidebar-bg); }
        .stat-card .stat-label { color: #888; font-size: 0.85rem; }
        /* ─── Table Container ─── */
        .table-container {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(6px);
            border-radius: var(--radius);
            padding: 22px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.04);
            margin-bottom: 20px;
        }
        .table-container h5 { margin-bottom: 15px; color: var(--sidebar-bg); border-bottom: 2px solid #f1f2f6; padding-bottom: 12px; font-weight: 700; }
        .table { margin-bottom: 0; }
        .table thead.table-dark { background: var(--sidebar-bg) !important; }
        .table thead.table-dark th { border: none; font-weight: 600; font-size: 0.85rem; }
        .badge-active { background: #00b894; color: #fff; padding: 5px 12px; border-radius: 20px; font-size: 0.75rem; }
        .badge-inactive { background: #d63031; color: #fff; padding: 5px 12px; border-radius: 20px; font-size: 0.75rem; }
        .page-title { margin-bottom: 20px; color: var(--sidebar-bg); }
        .page-title h3 { margin: 0; font-weight: 800; }
        .btn { border-radius: 8px; font-weight: 600; }
        .btn-primary { background: var(--sidebar-active); border: none; }
        .btn-primary:hover { background: var(--accent); transform: translateY(-1px); }
        .btn-outline-danger { border-color: #d63031; color: #d63031; }
        .btn-outline-danger:hover { background: #d63031; color: #fff; }
        .btn-sm-icon { padding: 4px 10px; font-size: 0.75rem; }
        .footer-note { text-align: center; color: #999; padding: 25px 0 10px; font-size: 0.8rem; border-top: 1px solid rgba(0,0,0,0.05); }
        .form-control, .form-select { border-radius: 8px; border: 1px solid #dfe6e9; padding: 10px 14px; }
        .form-control:focus, .form-select:focus { border-color: var(--sidebar-active); box-shadow: 0 0 0 3px rgba(15,52,96,0.1); }
        .progress { border-radius: 10px; }
        .progress-bar { border-radius: 10px; }
        .alert { border-radius: var(--radius); border: none; }

        /* ─── Responsive ─── */
        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                height: auto;
                max-height: 60px;
                overflow: hidden;
                position: relative;
                transition: max-height 0.4s;
            }
            .sidebar:hover, .sidebar:focus-within { max-height: 100vh; overflow-y: auto; }
            .sidebar-brand { padding: 15px; }
            .sidebar-brand::after { content:' ☰ منو'; display: block; font-size: 0.8rem; color: var(--accent); }
            .main-content { margin-right: 0; padding: 15px; }
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
        <a class="nav-link <?php echo ($activeMenu ?? '') === 'deep_links' ? 'active' : ''; ?>" href="deep_links.php">
            <i class="bi bi-link-45deg"></i> 🔗 دیپ لینک‌ها
        </a>
        <a class="nav-link <?php echo ($activeMenu ?? '') === 'bot_texts' ? 'active' : ''; ?>" href="bot_texts.php">
            <i class="bi bi-file-text"></i> 📝 مدیریت متن‌ها
        </a>
        <a class="nav-link <?php echo ($activeMenu ?? '') === 'ai_logs' ? 'active' : ''; ?>" href="ai_logs.php">
            <i class="bi bi-journal-code"></i> 📋 لاگ‌های هوش مصنوعی
        </a>
        <a class="nav-link <?php echo ($activeMenu ?? '') === 'error_logs' ? 'active' : ''; ?>" href="error_logs.php">
            <i class="bi bi-exclamation-triangle"></i> ⚠️ خطاهای مهم
        </a>
        <a class="nav-link <?php echo ($activeMenu ?? '') === 'temp_files' ? 'active' : ''; ?>" href="temp_files.php">
            <i class="bi bi-folder2-open"></i> 🗂 مدیریت فایل‌ها
        </a>
        <a class="nav-link <?php echo ($activeMenu ?? '') === 'generated_files' ? 'active' : ''; ?>" href="generated_files.php">
            <i class="bi bi-file-earmark-image"></i> 📁 فایل‌های تولید شده
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
        <i class="bi bi-robot"></i> ربات هوش مصنوعی    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>