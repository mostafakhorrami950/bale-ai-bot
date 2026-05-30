<?php
/**
 * Web Dashboard Layout — Header + Sidebar
 * Include this in all dashboard pages.
 * 
 * Usage: require __DIR__ . '/layout.php'; (must be called after init.php)
 */
$webUser = requireAuth();
$botUserId = getBotUserId($webUser['id']);

// Get user credits
$credits = 0;
$userName = 'کاربر';
try {
    $db = Database::getInstance();
    $botUser = $db->query("SELECT * FROM users WHERE bale_user_id = ?", [(int)($webUser['bale_user_id'] ?? 0)])->fetch();
    if ($botUser) {
        $credits = (float)($botUser['credits'] ?? 0);
        $userName = $botUser['first_name'] ?: ($webUser['name'] ?: 'کاربر');
    } else {
        // Try by phone
        $botUser = $db->query("SELECT * FROM users WHERE phone_number = ?", [$webUser['phone']])->fetch();
        if ($botUser) {
            $credits = (float)($botUser['credits'] ?? 0);
            $userName = $botUser['first_name'] ?: ($webUser['name'] ?: 'کاربر');
        }
    }
} catch (\Throwable $e) {}

$currentPage = basename($_SERVER['SCRIPT_NAME']);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? 'داشبورد' ?> | موبیکس‌بات</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Vazirmatn', 'sans-serif'] },
                }
            }
        }
    </script>
    <style>
        * { font-family: 'Vazirmatn', sans-serif; }
        body {
            background: #0f0c29;
            min-height: 100vh;
        }
        .glass {
            background: rgba(255,255,255,0.05);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.1);
        }
        .glass-hover:hover {
            background: rgba(255,255,255,0.08);
            border-color: rgba(52,211,153,0.3);
        }
        .sidebar-item {
            transition: all 0.2s;
            border-radius: 12px;
        }
        .sidebar-item:hover, .sidebar-item.active {
            background: rgba(52,211,153,0.1);
            border-color: rgba(52,211,153,0.3);
        }
        .sidebar-item.active i {
            color: #34d399;
        }
        .btn-primary {
            background: linear-gradient(135deg,#34d399 0%,#10b981 50%,#059669 100%);
            transition: all 0.3s;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(52,211,153,0.3);
        }
        .input-field {
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.15);
            color: white;
            transition: all 0.3s;
        }
        .input-field:focus {
            border-color: rgba(52,211,153,0.5);
            box-shadow: 0 0 20px rgba(52,211,153,0.1);
            outline: none;
        }
        .msg-user {
            background: rgba(52,211,153,0.1);
            border: 1px solid rgba(52,211,153,0.2);
        }
        .msg-bot {
            background: rgba(99,102,241,0.1);
            border: 1px solid rgba(99,102,241,0.2);
        }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.2); }
        .loading-dots::after {
            content: '';
            animation: dots 1.5s infinite;
        }
        @keyframes dots {
            0% { content: ''; }
            25% { content: '.'; }
            50% { content: '..'; }
            75% { content: '...'; }
        }
    </style>
</head>
<body>
    <div class="flex h-screen overflow-hidden">
        <!-- Sidebar -->
        <aside class="glass w-64 md:w-72 shrink-0 flex flex-col h-full hidden md:flex">
            <!-- Logo -->
            <div class="p-4 border-b border-white/10">
                <a href="/web/dashboard.php" class="flex items-center gap-3 no-underline">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-400 to-teal-600 flex items-center justify-center">
                        <i data-lucide="bot" class="w-5 h-5 text-white"></i>
                    </div>
                    <div>
                        <h1 class="text-white font-bold text-sm">موبیکس‌بات</h1>
                        <p class="text-gray-500 text-xs">نسخه تحت وب</p>
                    </div>
                </a>
            </div>

            <!-- Credits -->
            <div class="px-4 py-3 border-b border-white/10">
                <div class="glass rounded-xl p-3 flex items-center justify-between">
                    <span class="text-gray-400 text-sm">اعتبار</span>
                    <span class="text-emerald-400 font-bold text-lg" id="webCredits"><?= number_format($credits) ?></span>
                </div>
            </div>

            <!-- Nav Items -->
            <nav class="flex-1 p-3 space-y-1 overflow-y-auto">
                <?php
                $navItems = [
                    'dashboard.php' => ['icon' => 'layout-dashboard', 'label' => 'داشبورد'],
                    'chat.php' => ['icon' => 'message-square', 'label' => 'چت هوشمند'],
                    'image.php' => ['icon' => 'image', 'label' => 'تولید تصویر'],
                    'img2img.php' => ['icon' => 'edit-3', 'label' => 'ویرایش تصویر'],
                    'video.php' => ['icon' => 'video', 'label' => 'تولید ویدیو'],
                    'plans.php' => ['icon' => 'shopping-cart', 'label' => 'خرید اعتبار'],
                    'history.php' => ['icon' => 'clock', 'label' => 'تاریخچه'],
                    'profile.php' => ['icon' => 'user', 'label' => 'حساب کاربری'],
                ];
                foreach ($navItems as $file => $item):
                    $active = ($currentPage === $file);
                ?>
                <a href="/web/<?= $file ?>" class="sidebar-item flex items-center gap-3 px-3 py-2.5 text-sm no-underline <?= $active ? 'active border border-emerald-500/20' : 'text-gray-400 hover:text-white border border-transparent' ?>">
                    <i data-lucide="<?= $item['icon'] ?>" class="w-5 h-5 <?= $active ? 'text-emerald-400' : '' ?>"></i>
                    <span><?= $item['label'] ?></span>
                </a>
                <?php endforeach; ?>
            </nav>

            <!-- Logout -->
            <div class="p-3 border-t border-white/10">
                <a href="/web/logout.php" class="sidebar-item flex items-center gap-3 px-3 py-2.5 text-sm text-red-400 hover:text-red-300 no-underline">
                    <i data-lucide="log-out" class="w-5 h-5"></i>
                    <span>خروج</span>
                </a>
            </div>
        </aside>

        <!-- Mobile Header -->
        <div class="md:hidden fixed top-0 left-0 right-0 glass z-50 px-4 py-3 flex items-center justify-between">
            <button onclick="toggleMobileMenu()" class="text-white">
                <i data-lucide="menu" class="w-6 h-6"></i>
            </button>
            <div class="flex items-center gap-2">
                <span class="text-emerald-400 font-bold text-sm"><?= number_format($credits) ?></span>
                <i data-lucide="diamond" class="w-4 h-4 text-emerald-400"></i>
            </div>
        </div>

        <!-- Mobile Menu Overlay -->
        <div id="mobileMenu" class="fixed inset-0 z-50 hidden">
            <div class="absolute inset-0 bg-black/60" onclick="toggleMobileMenu()"></div>
            <div class="glass w-72 h-full p-4 relative z-10">
                <div class="flex items-center justify-between mb-6">
                    <span class="text-white font-bold">منو</span>
                    <button onclick="toggleMobileMenu()" class="text-gray-400">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <nav class="space-y-1">
                    <?php foreach ($navItems as $file => $item): ?>
                    <a href="/web/<?= $file ?>" class="flex items-center gap-3 px-3 py-2.5 text-sm text-gray-400 hover:text-white no-underline rounded-xl hover:bg-white/5">
                        <i data-lucide="<?= $item['icon'] ?>" class="w-5 h-5"></i>
                        <span><?= $item['label'] ?></span>
                    </a>
                    <?php endforeach; ?>
                    <hr class="border-white/10 my-3">
                    <a href="/web/logout.php" class="flex items-center gap-3 px-3 py-2.5 text-sm text-red-400 hover:text-red-300 no-underline rounded-xl hover:bg-white/5">
                        <i data-lucide="log-out" class="w-5 h-5"></i>
                        <span>خروج</span>
                    </a>
                </nav>
            </div>
        </div>

        <!-- Main Content -->
        <main class="flex-1 overflow-y-auto p-4 md:p-6 mt-14 md:mt-0">
            <script>
                lucide.createIcons();
                function toggleMobileMenu() {
                    document.getElementById('mobileMenu').classList.toggle('hidden');
                }
            </script>
<?php
// Page content continues after this file
?>