<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $title ?? 'CRM Travel Agency'; ?> | CRM آژانس مسافرتی</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.min.css">
    <style>
        :root {
            --primary: #667eea;
            --secondary: #764ba2;
            --sidebar-width: 250px;
        }
        * { font-family: 'IRANSans', 'Tahoma', sans-serif; }
        body {
            background: #f4f6f9;
            min-height: 100vh;
        }
        
        /* Sidebar */
        .sidebar {
            position: fixed;
            right: 0;
            top: 0;
            bottom: 0;
            width: var(--sidebar-width);
            background: linear-gradient(180deg, #1a1a2e 0%, #16213e 100%);
            padding: 0;
            z-index: 1000;
            transition: all 0.3s;
            overflow-y: auto;
        }
        .sidebar-brand {
            padding: 20px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .sidebar-brand h5 {
            color: #fff;
            font-weight: bold;
            margin: 0;
        }
        .sidebar-brand small {
            color: rgba(255,255,255,0.6);
        }
        .nav-link {
            color: rgba(255,255,255,0.7) !important;
            padding: 12px 20px !important;
            border-right: 3px solid transparent;
            transition: all 0.2s;
            font-size: 14px;
        }
        .nav-link:hover, .nav-link.active {
            color: #fff !important;
            background: rgba(102, 126, 234, 0.15);
            border-right-color: var(--primary);
        }
        .nav-link i {
            margin-left: 10px;
            width: 20px;
            text-align: center;
        }
        .nav-section {
            color: rgba(255,255,255,0.4);
            font-size: 11px;
            text-transform: uppercase;
            padding: 15px 20px 5px;
            letter-spacing: 1px;
        }
        
        /* Main Content */
        .main-content {
            margin-right: var(--sidebar-width);
            padding: 20px 30px;
            min-height: 100vh;
        }
        
        /* Header */
        .top-header {
            background: #fff;
            padding: 15px 25px;
            border-radius: 15px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .top-header .page-title h4 {
            margin: 0;
            color: #333;
        }
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .user-info .dropdown-toggle {
            background: none;
            border: none;
            cursor: pointer;
            color: #555;
            font-size: 14px;
        }
        
        /* Cards */
        .stat-card {
            background: #fff;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-3px);
        }
        .stat-card .icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 15px;
        }
        
        /* Tables */
        .table-container {
            background: #fff;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .table th {
            border-top: none;
            color: #666;
            font-size: 12px;
            text-transform: uppercase;
        }
        .table td {
            vertical-align: middle;
        }
        
        /* Badges */
        .badge-stage {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: normal;
        }
        
        /* Flash Messages */
        .alert-container {
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 9999;
            max-width: 400px;
        }
        .alert-container .alert {
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
            border: none;
            border-radius: 10px;
            animation: slideIn 0.3s ease;
        }
        @keyframes slideIn {
            from { transform: translateX(100px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        /* Kanban */
        .kanban-board {
            display: flex;
            gap: 15px;
            overflow-x: auto;
            padding-bottom: 20px;
            min-height: 500px;
        }
        .kanban-column {
            min-width: 280px;
            max-width: 280px;
            background: #f4f6f9;
            border-radius: 12px;
            padding: 15px;
        }
        .kanban-column-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 3px solid;
        }
        .kanban-card {
            background: #fff;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            cursor: pointer;
            transition: all 0.2s;
            border-right: 4px solid var(--primary);
        }
        .kanban-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .kanban-card .amount {
            font-weight: bold;
            color: var(--primary);
        }
        
        /* Buttons */
        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border: none;
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, #5a6fd6, #6a3d9a);
            transform: translateY(-1px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .sidebar { right: -250px; }
            .sidebar.show { right: 0; }
            .main-content { margin-right: 0; }
            .kanban-column { min-width: 240px; max-width: 240px; }
        }
        
        /* Modal */
        .modal-content {
            border: none;
            border-radius: 15px;
        }
        .modal-header {
            border-bottom: 1px solid #eee;
            padding: 20px;
        }
        .modal-footer {
            border-top: 1px solid #eee;
            padding: 15px 20px;
        }
        
        /* Forms */
        .form-control, .form-select {
            border-radius: 10px;
            border: 2px solid #e8ecf1;
            padding: 10px 15px;
            font-size: 14px;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .form-label {
            font-size: 13px;
            color: #555;
            margin-bottom: 5px;
        }
        
        /* Color indicators for deal amounts */
        .text-success { color: #10B981 !important; }
        .text-warning { color: #F59E0B !important; }
        .text-danger { color: #EF4444 !important; }
        .text-info { color: #3B82F6 !important; }
        .text-purple { color: #8B5CF6 !important; }
        
        .amount-display {
            font-size: 24px;
            font-weight: bold;
            color: var(--primary);
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }
        .empty-state i {
            font-size: 64px;
            margin-bottom: 20px;
            color: #ddd;
        }
        
        .pipeline-selector {
            background: #fff;
            border-radius: 12px;
            padding: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        
        .filter-section {
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
    </style>
</head>
<body>
    <!-- Flash Messages -->
    <div class="alert-container">
        <?php 
        $flashes = \Core\Session::getFlashes();
        foreach ($flashes as $flash): 
        ?>
            <div class="alert alert-<?php echo $flash['type']; ?> alert-dismissible fade show">
                <?php echo htmlspecialchars($flash['message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <h5>CRM آژانس</h5>
            <small>مسافرتی</small>
        </div>
        
        <div class="nav-section">ناوبری اصلی</div>
        
        <a href="<?php echo $config['url']; ?>/dashboard" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/dashboard') !== false ? 'active' : ''; ?>">
            <i class="bi bi-speedometer2"></i> داشبورد
        </a>
        
        <?php if (\Core\Auth::hasPermission('pipelines.view')): ?>
        <a href="<?php echo $config['url']; ?>/pipelines" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/pipelines') !== false && strpos($_SERVER['REQUEST_URI'], '/pipelines/kanban') === false ? 'active' : ''; ?>">
            <i class="bi bi-kanban"></i> پایپ لاین‌ها
        </a>
        <?php endif; ?>
        
        <?php if (\Core\Auth::hasPermission('deals.view')): ?>
        <a href="<?php echo $config['url']; ?>/deals" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/deals') !== false ? 'active' : ''; ?>">
            <i class="bi bi-briefcase"></i> معاملات
        </a>
        <?php endif; ?>
        
        <?php if (\Core\Auth::hasPermission('contacts.view')): ?>
        <a href="<?php echo $config['url']; ?>/contacts" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/contacts') !== false ? 'active' : ''; ?>">
            <i class="bi bi-people"></i> مخاطبان
        </a>
        <?php endif; ?>
        
        <?php if ($config['features']['payment_gateway'] && \Core\Auth::hasPermission('payments.view')): ?>
        <a href="<?php echo $config['url']; ?>/payment/history" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/payment') !== false ? 'active' : ''; ?>">
            <i class="bi bi-credit-card"></i> پرداخت‌ها
        </a>
        <?php endif; ?>
        
        <?php if ($config['features']['sms'] && \Core\Auth::hasPermission('sms.send')): ?>
        <a href="<?php echo $config['url']; ?>/sms/history" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/sms') !== false ? 'active' : ''; ?>">
            <i class="bi bi-chat-dots"></i> پیامک‌ها
        </a>
        <?php endif; ?>
        
        <?php if (\Core\Auth::hasPermission('reports.view')): ?>
        <div class="nav-section">گزارشات</div>
        <a href="<?php echo $config['url']; ?>/reports" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/reports') !== false && strpos($_SERVER['REQUEST_URI'], '/reports/sales') === false && strpos($_SERVER['REQUEST_URI'], '/reports/pipeline') === false && strpos($_SERVER['REQUEST_URI'], '/reports/activities') === false && strpos($_SERVER['REQUEST_URI'], '/reports/contacts') === false ? 'active' : ''; ?>">
            <i class="bi bi-bar-chart"></i> داشبورد گزارشات
        </a>
        <a href="<?php echo $config['url']; ?>/reports/sales" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/reports/sales') !== false ? 'active' : ''; ?>">
            <i class="bi bi-graph-up"></i> فروش
        </a>
        <a href="<?php echo $config['url']; ?>/reports/pipeline" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/reports/pipeline') !== false ? 'active' : ''; ?>">
            <i class="bi bi-diagram-3"></i> پایپ لاین
        </a>
        <a href="<?php echo $config['url']; ?>/reports/activities" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/reports/activities') !== false ? 'active' : ''; ?>">
            <i class="bi bi-list-check"></i> فعالیت‌ها
        </a>
        <a href="<?php echo $config['url']; ?>/reports/contacts" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/reports/contacts') !== false ? 'active' : ''; ?>">
            <i class="bi bi-person-lines-fill"></i> مخاطبان
        </a>
        <?php endif; ?>
        
        <div class="nav-section">مدیریت</div>
        
        <?php if (\Core\Auth::hasPermission('users.manage')): ?>
        <a href="<?php echo $config['url']; ?>/users" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/users') !== false ? 'active' : ''; ?>">
            <i class="bi bi-person-gear"></i> کاربران
        </a>
        <?php endif; ?>
        
        <?php if (\Core\Auth::hasPermission('roles.manage')): ?>
        <a href="<?php echo $config['url']; ?>/roles" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/roles') !== false ? 'active' : ''; ?>">
            <i class="bi bi-shield-check"></i> نقش‌ها
        </a>
        <?php endif; ?>
        
        <?php if (\Core\Auth::hasPermission('settings.manage')): ?>
        <a href="<?php echo $config['url']; ?>/settings" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/settings') !== false ? 'active' : ''; ?>">
            <i class="bi bi-gear"></i> تنظیمات
        </a>
        <?php endif; ?>
        
        <div style="margin-top: 20px; padding: 20px;">
            <a href="<?php echo $config['url']; ?>/logout" class="nav-link" style="color: #ef4444 !important;">
                <i class="bi bi-box-arrow-right"></i> خروج
            </a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="top-header">
            <div class="page-title">
                <h4><?php echo $title ?? 'داشبورد'; ?></h4>
            </div>
            <div class="user-info">
                <span style="color: #888; font-size: 13px;">
                    <?php echo date('Y/m/d'); ?>
                </span>
                <div class="dropdown">
                    <button class="dropdown-toggle" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle" style="font-size: 18px;"></i>
                        <?php echo \Core\Auth::user()->full_name ?? ''; ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><span class="dropdown-item-text text-muted"><?php echo \Core\Auth::user()->role_name ?? ''; ?></span></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?php echo $config['url']; ?>/logout"><i class="bi bi-box-arrow-right"></i> خروج</a></li>
                    </ul>
                </div>
            </div>
        </div>

        <?php echo $content ?? ''; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script>
        // Auto-hide alerts after 5 seconds
        document.querySelectorAll('.alert').forEach(function(alert) {
            setTimeout(function() {
                alert.style.opacity = '0';
                setTimeout(function() { alert.remove(); }, 300);
            }, 5000);
        });

        // Format number inputs
        document.querySelectorAll('input[data-format="number"]').forEach(function(input) {
            input.addEventListener('input', function(e) {
                let value = this.value.replace(/[^0-9]/g, '');
                if (value) {
                    this.value = new Intl.NumberFormat('fa-IR').format(parseInt(value));
                }
            });
        });

        // Confirm dialogs
        document.querySelectorAll('[data-confirm]').forEach(function(el) {
            el.addEventListener('click', function(e) {
                if (!confirm(this.dataset.confirm || 'آیا اطمینان دارید؟')) {
                    e.preventDefault();
                }
            });
        });

        // Quick create deal from kanban
        $(document).on('click', '.add-deal-btn', function() {
            $('#quickDealModal').modal('show');
            $('#quickDealModal .pipeline-id').val($(this).data('pipeline'));
            $('#quickDealModal .stage-id').val($(this).data('stage'));
        });

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
    </script>
</body>
</html>