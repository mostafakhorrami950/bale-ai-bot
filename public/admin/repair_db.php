<?php
/**
 * Database Repair Utility
 * 
 * Only accessible by logged-in admin. Checks all required tables/columns
 * and creates any that are missing. Shows results in Persian.
 */
require_once __DIR__ . '/../../init.php';

// Only admin can access this page
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

use Database\DatabaseRepairService;

$repairer = new DatabaseRepairService();
$messages = $repairer->repairAll();

// Count successful vs warning messages
$successCount = 0;
$warnCount = 0;
foreach ($messages as $msg) {
    if (strpos($msg, '✅') !== false || strpos($msg, 'ایجاد') !== false || strpos($msg, 'اضافه') !== false) {
        $successCount++;
    } else {
        $warnCount++;
    }
}
$totalCount = count($messages);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>بررسی و تعمیر دیتابیس</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <style>
        body {
            font-family: Tahoma, Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .card {
            background: white;
            border-radius: 20px;
            padding: 40px;
            max-width: 700px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        h1 {
            font-size: 24px;
            color: #333;
            margin-bottom: 10px;
            text-align: center;
        }
        .stats {
            text-align: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .stats .count {
            font-size: 2rem;
            font-weight: bold;
            color: #0984e3;
        }
        .message-list {
            max-height: 400px;
            overflow-y: auto;
            margin-bottom: 20px;
        }
        .message-item {
            padding: 8px 12px;
            border-bottom: 1px solid #eee;
            font-size: 0.95rem;
        }
        .message-item.success { background: #d4edda; color: #155724; border-radius: 5px; margin: 3px 0; }
        .message-item.warn { background: #fff3cd; color: #856404; border-radius: 5px; margin: 3px 0; }
        .btn {
            display: inline-block;
            padding: 12px 30px;
            border-radius: 50px;
            text-decoration: none;
            font-size: 16px;
            font-weight: bold;
            transition: all 0.2s;
            margin: 5px;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .btn-secondary {
            background: #636e72;
            color: white;
        }
        .btn:hover { transform: translateY(-2px); }
    </style>
</head>
<body>
    <div class="card">
        <h1>🔧 بررسی و تعمیر دیتابیس</h1>
        <p style="text-align:center; color:#636e72; margin-bottom:20px;">
            این ابزار جداول و ستون‌های مورد نیاز را بررسی و در صورت نیاز ایجاد می‌کند.
        </p>

        <div class="stats">
            <div class="count"><?php echo $totalCount; ?></div>
            <div>عملیات انجام شده</div>
            <div style="margin-top:10px;">
                <span class="badge bg-success"><?php echo $successCount; ?> موفق</span>
                <span class="badge bg-warning text-dark"><?php echo $warnCount; ?> هشدار</span>
            </div>
        </div>

        <div class="message-list">
            <?php if (empty($messages)): ?>
                <div class="message-item" style="text-align:center; color:#636e72;">
                    ✅ همه چیز درست است. هیچ تغییری نیاز نبود.
                </div>
            <?php else: ?>
                <?php foreach ($messages as $msg): ?>
                    <div class="message-item <?php echo strpos($msg, '⚠️') !== false ? 'warn' : 'success'; ?>">
                        <?php echo htmlspecialchars($msg); ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div style="text-align:center;">
            <a href="repair_db.php" class="btn btn-primary">🔄 بررسی مجدد</a>
            <a href="../admin.php" class="btn btn-secondary">← بازگشت به پنل مدیریت</a>
        </div>

        <p style="text-align:center; color:#b2bec3; margin-top:20px; font-size:0.85rem;">
            <i class="bi bi-info-circle"></i> اگر خطایی مشاهده می‌کنید، دکمه "بررسی مجدد" را بزنید.
            <br>این ابزار جداول و ستون‌های زیر را بررسی می‌کند:
            <br>📦 جداول: required_channels, payment_plans, payments, settings, bot_logs,
            ai_requests, credit_ledger, uploaded_files, chat_conversations, chat_messages,
            ai_image_models, ai_edit_models, ai_text_models, ai_video_models, user_profiles,
            user_memories, conversation_summaries
            <br>🧠 ماژول حافظه (Memory) و تنظیمات پرداخت نیز بررسی می‌شود.
        </p>
    </div>
</body>
</html>