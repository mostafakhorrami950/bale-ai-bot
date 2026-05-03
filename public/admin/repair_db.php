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
use Database\Database;

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

// ─── Database Debug Info ───
$db = Database::getInstance();
$conn = $db->getConnection();

// Get chat_messages table structure
$chatMessagesStructure = '';
try {
    $stmt = $conn->query("SHOW CREATE TABLE chat_messages");
    $row = $stmt->fetch();
    $chatMessagesStructure = $row['Create Table'] ?? 'جدول وجود ندارد';
} catch (\Throwable $e) {
    $chatMessagesStructure = 'خطا: ' . $e->getMessage();
}

// Get chat_messages columns
$chatMessagesColumns = [];
try {
    $stmt = $conn->query("SHOW COLUMNS FROM chat_messages");
    $chatMessagesColumns = $stmt->fetchAll();
} catch (\Throwable $e) {
    $chatMessagesColumns = [];
}

// Get chat_conversations structure
$chatConversationsStructure = '';
try {
    $stmt = $conn->query("SHOW CREATE TABLE chat_conversations");
    $row = $stmt->fetch();
    $chatConversationsStructure = $row['Create Table'] ?? 'جدول وجود ندارد';
} catch (\Throwable $e) {
    $chatConversationsStructure = 'خطا: ' . $e->getMessage();
}

// Get sample data from chat_messages (last 5)
$sampleMessages = [];
try {
    $stmt = $conn->query("
        SELECT cm.id, cm.conversation_id, cm.role, LEFT(cm.content, 100) as content_preview, 
               cm.model_name, cm.actual_cost_usd, cm.input_tokens, cm.output_tokens,
               cm.cost_input_credits, cm.cost_output_credits, cm.created_at
        FROM chat_messages cm 
        ORDER BY cm.id DESC LIMIT 5
    ");
    $sampleMessages = $stmt->fetchAll();
} catch (\Throwable $e) {
    $sampleMessages = [];
}

// Get credit_ledger structure
$creditLedgerStructure = '';
try {
    $stmt = $conn->query("SHOW CREATE TABLE credit_ledger");
    $row = $stmt->fetch();
    $creditLedgerStructure = $row['Create Table'] ?? 'جدول وجود ندارد';
} catch (\Throwable $e) {
    $creditLedgerStructure = 'خطا: ' . $e->getMessage();
}

// Get PHP error log (last 20 lines)
$errorLogLines = [];
$errorLogFile = ini_get('error_log');
if ($errorLogFile && file_exists($errorLogFile)) {
    $lines = @file($errorLogFile);
    if ($lines) {
        $errorLogLines = array_slice($lines, -20);
    }
}

// Get debug.txt (error_handler.php in public/ redirects all errors here)
$debugTxtLines = [];
$debugTxtFile = dirname(__DIR__) . '/debug.txt'; // public/debug.txt
if (file_exists($debugTxtFile)) {
    $lines = @file($debugTxtFile);
    if ($lines) {
        $debugTxtLines = array_slice($lines, -30);
    }
}

// Get ai_debug.log (last 20 lines)
$aiDebugLines = [];
$aiDebugFile = defined('BASE_PATH') ? BASE_PATH . '/ai_debug.log' : __DIR__ . '/../../ai_debug.log';
if (file_exists($aiDebugFile)) {
    $lines = @file($aiDebugFile);
    if ($lines) {
        $aiDebugLines = array_slice($lines, -20);
    }
}

// Get logs_ai.txt (last 20 lines)
$logsAiLines = [];
$logsAiFile = defined('BASE_PATH') ? BASE_PATH . '/logs_ai.txt' : __DIR__ . '/../../logs_ai.txt';
if (file_exists($logsAiFile)) {
    $lines = @file($logsAiFile);
    if ($lines) {
        $logsAiLines = array_slice($lines, -20);
    }
}
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
            padding: 20px;
        }
        .card {
            background: white;
            border-radius: 20px;
            padding: 40px;
            max-width: 900px;
            width: 100%;
            margin: 0 auto 20px;
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
        .debug-section {
            margin-top: 30px;
            border-top: 2px solid #eee;
            padding-top: 20px;
        }
        .debug-section h3 {
            color: #2d3436;
            margin-bottom: 15px;
        }
        .sql-box {
            background: #1e272e;
            color: #00b894;
            padding: 15px;
            border-radius: 8px;
            font-family: 'Courier New', monospace;
            font-size: 0.8rem;
            overflow-x: auto;
            white-space: pre-wrap;
            word-break: break-all;
            max-height: 300px;
            overflow-y: auto;
        }
        .error-box {
            background: #fff5f5;
            color: #c0392b;
            padding: 10px;
            border-radius: 5px;
            font-family: monospace;
            font-size: 0.8rem;
            max-height: 200px;
            overflow-y: auto;
            white-space: pre-wrap;
        }
        .log-box {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            font-family: monospace;
            font-size: 0.75rem;
            max-height: 300px;
            overflow-y: auto;
            white-space: pre-wrap;
        }
        table.debug-table {
            font-size: 0.85rem;
        }
        .badge-exists { background: #00b894; color: white; padding: 2px 8px; border-radius: 10px; font-size: 0.75rem; }
        .badge-missing { background: #d63031; color: white; padding: 2px 8px; border-radius: 10px; font-size: 0.75rem; }
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
    </div>

    <!-- ════════════════════════════════════════════ -->
    <!--   DATABASE DEBUG SECTION                    -->
    <!-- ════════════════════════════════════════════ -->
    <div class="card debug-section">
        <h3>🔍 دیباگ دیتابیس — ساختار جداول</h3>

        <h5 class="mt-3">📋 ستون‌های جدول chat_messages</h5>
        <table class="table table-sm table-bordered debug-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>نام ستون</th>
                    <th>نوع</th>
                    <th>Null</th>
                    <th>پیش‌فرض</th>
                    <th>Extra</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($chatMessagesColumns)): ?>
                    <tr><td colspan="6" class="text-danger">❌ جدول chat_messages وجود ندارد یا خطا در خواندن</td></tr>
                <?php else: ?>
                    <?php $i = 1; foreach ($chatMessagesColumns as $col): ?>
                    <tr>
                        <td><?php echo $i++; ?></td>
                        <td><strong><?php echo htmlspecialchars($col['Field']); ?></strong></td>
                        <td><?php echo htmlspecialchars($col['Type']); ?></td>
                        <td><?php echo $col['Null'] === 'YES' ? '✅' : '❌'; ?></td>
                        <td><?php echo htmlspecialchars($col['Default'] ?? 'NULL'); ?></td>
                        <td><?php echo htmlspecialchars($col['Extra']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <h5 class="mt-3">📋 SQL ساختار chat_messages</h5>
        <div class="sql-box"><?php echo htmlspecialchars($chatMessagesStructure); ?></div>

        <h5 class="mt-3">📋 SQL ساختار chat_conversations</h5>
        <div class="sql-box"><?php echo htmlspecialchars($chatConversationsStructure); ?></div>

        <h5 class="mt-3">📋 SQL ساختار credit_ledger</h5>
        <div class="sql-box"><?php echo htmlspecialchars($creditLedgerStructure); ?></div>

        <h5 class="mt-3">📊 نمونه داده‌های آخرین پیام‌ها (۵ مورد آخر)</h5>
        <table class="table table-sm table-bordered debug-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>conv_id</th>
                    <th>role</th>
                    <th>متن (خلاصه)</th>
                    <th>model_name</th>
                    <th>actual_cost_usd</th>
                    <th>input_tokens</th>
                    <th>output_tokens</th>
                    <th>credits</th>
                    <th>تاریخ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($sampleMessages)): ?>
                    <tr><td colspan="10" class="text-muted text-center">هیچ پیامی یافت نشد.</td></tr>
                <?php else: ?>
                    <?php foreach ($sampleMessages as $m): ?>
                    <tr>
                        <td><?php echo $m['id']; ?></td>
                        <td><?php echo $m['conversation_id']; ?></td>
                        <td><?php echo $m['role']; ?></td>
                        <td style="max-width:150px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?php echo htmlspecialchars($m['content_preview'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($m['model_name'] ?? '-'); ?></td>
                        <td><?php echo $m['actual_cost_usd'] ?? 'NULL'; ?></td>
                        <td><?php echo $m['input_tokens'] ?? 'NULL'; ?></td>
                        <td><?php echo $m['output_tokens'] ?? 'NULL'; ?></td>
                        <td><?php echo ($m['cost_input_credits'] ?? 0) + ($m['cost_output_credits'] ?? 0); ?></td>
                        <td style="font-size:0.75rem;"><?php echo $m['created_at']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- ════════════════════════════════════════════ -->
    <!--   ERROR LOG DEBUG SECTION                   -->
    <!-- ════════════════════════════════════════════ -->
    <div class="card debug-section">
        <h3>📋 لاگ‌های خطا</h3>

        <h5 class="mt-3">🐘 PHP Error Log (<?php echo htmlspecialchars(basename($errorLogFile ?: 'نامشخص')); ?>)</h5>
        <div class="error-box">
            <?php if (empty($errorLogLines)): ?>
                <span class="text-muted">هیچ خطایی یافت نشد یا فایل لاگ وجود ندارد.</span>
            <?php else: ?>
                <?php foreach ($errorLogLines as $line): ?>
                    <?php echo htmlspecialchars($line); ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <h5 class="mt-3">📄 debug.txt (error_handler.php — تمام خطاهای PHP در اینجا ذخیره می‌شوند)</h5>
        <div class="error-box" style="max-height:400px;">
            <?php if (empty($debugTxtLines)): ?>
                <span class="text-muted">فایل debug.txt خالی است یا وجود ندارد.</span>
            <?php else: ?>
                <?php foreach ($debugTxtLines as $line): ?>
                    <?php echo htmlspecialchars($line); ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <h5 class="mt-3">🤖 AI Debug Log (ai_debug.log)</h5>
        <div class="log-box">
            <?php if (empty($aiDebugLines)): ?>
                <span class="text-muted">فایل ai_debug.log خالی است یا وجود ندارد.</span>
            <?php else: ?>
                <?php foreach ($aiDebugLines as $line): ?>
                    <?php echo htmlspecialchars($line); ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <h5 class="mt-3">📝 Logs AI (logs_ai.txt)</h5>
        <div class="log-box">
            <?php if (empty($logsAiLines)): ?>
                <span class="text-muted">فایل logs_ai.txt خالی است یا وجود ندارد.</span>
            <?php else: ?>
                <?php foreach ($logsAiLines as $line): ?>
                    <?php echo htmlspecialchars($line); ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div style="text-align:center; margin-bottom:40px;">
        <a href="repair_db.php" class="btn btn-primary">🔄 بررسی مجدد</a>
        <a href="../admin.php" class="btn btn-secondary">← بازگشت به پنل مدیریت</a>
    </div>
</body>
</html>