<?php
/**
 * مدیریت فایل‌های موقت (uploads/tmp/)
 * 
 * ادمین می‌تواند فایل‌های قدیمی را انتخاب و پاک کند:
 * - یک روز قبل
 * - هفت روز قبل
 * - یک ماه قبل
 * - همه فایل‌ها
 */
require_once __DIR__ . '/../../init.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

use Database\Database;

$pageTitle = 'مدیریت فایل‌های موقت';
$activeMenu = 'temp_files';

$message = '';
$messageType = 'info';
$stats = [];
$files = [];

// Define temp directory
$tempDir = BASE_PATH . '/uploads/tmp/';

// ─── Handle cleanup actions ───
$action = $_POST['action'] ?? '';
$csrfToken = $_POST['csrf_token'] ?? '';
$expectedToken = $_SESSION['csrf_token_temp_files'] ?? '';

if ($action && $csrfToken === $expectedToken) {
    if (!is_dir($tempDir)) {
        $message = '❌ پوشه فایل‌های موقت وجود ندارد.';
        $messageType = 'danger';
    } else {
        $cutoffTime = 0;
        $label = '';

        switch ($action) {
            case 'delete_1day':
                $cutoffTime = strtotime('-1 day');
                $label = 'بیشتر از یک روز';
                break;
            case 'delete_7days':
                $cutoffTime = strtotime('-7 days');
                $label = 'بیشتر از هفت روز';
                break;
            case 'delete_30days':
                $cutoffTime = strtotime('-30 days');
                $label = 'بیشتر از یک ماه';
                break;
            case 'delete_all':
                $cutoffTime = PHP_INT_MAX; // all files
                $label = 'همه فایل‌ها';
                break;
            default:
                $message = '⚠️ عملیات نامعتبر.';
                $messageType = 'warning';
        }

        if ($cutoffTime > 0 && $label) {
            $deletedCount = 0;
            $totalSize = 0;
            $errors = 0;

            $dh = opendir($tempDir);
            if ($dh) {
                while (($file = readdir($dh)) !== false) {
                    if ($file === '.' || $file === '..' || $file === '.htaccess') continue;

                    $filePath = $tempDir . $file;
                    if (!is_file($filePath)) continue;

                    $fileTime = filemtime($filePath);
                    $fileSize = filesize($filePath);

                    // delete_all: delete regardless of time
                    // other: delete if file is older than cutoff
                    $shouldDelete = ($action === 'delete_all') ? true : ($fileTime < $cutoffTime);

                    if ($shouldDelete) {
                        if (@unlink($filePath)) {
                            $deletedCount++;
                            $totalSize += $fileSize;
                        } else {
                            $errors++;
                        }
                    }
                }
                closedir($dh);
            }

            if ($deletedCount > 0) {
                $sizeFormatted = $totalSize > 1048576
                    ? round($totalSize / 1048576, 2) . ' مگابایت'
                    : ($totalSize > 1024 ? round($totalSize / 1024, 2) . ' کیلوبایت' : $totalSize . ' بایت');
                $message = "✅ {$deletedCount} فایل ({$sizeFormatted}) با موفقیت حذف شدند.";
                $messageType = 'success';

                // Log the action
                try {
                    $db = Database::getInstance();
                    $db->query(
                        "INSERT INTO admin_actions (admin_username, action, target_type, details) VALUES (?, 'temp_files_cleanup', 'file', ?)",
                        [$_SESSION['admin_username'] ?? 'admin', "{$label}: {$deletedCount} فایل - {$sizeFormatted}"]
                    );
                } catch (\Throwable $e) {
                    // Logging failure is non-critical
                }
            } else {
                $message = ($action === 'delete_all')
                    ? 'ℹ️ پوشه فایل‌های موقت خالی است.'
                    : "ℹ️ هیچ فایلی با شرط «{$label}» یافت نشد.";
                $messageType = 'info';
            }

            if ($errors > 0) {
                $message .= " ⚠️ {$errors} فایل به دلیل خطای دسترسی حذف نشدند.";
            }
        }
    }
}

// Generate new CSRF token
$_SESSION['csrf_token_temp_files'] = bin2hex(random_bytes(32));
$csrfToken = $_SESSION['csrf_token_temp_files'];

// ─── Gather current stats ───
$totalFiles = 0;
$totalSizeBytes = 0;
$oldestFile = PHP_INT_MAX;
$newestFile = 0;
$fileExtensions = [];

if (is_dir($tempDir)) {
    $dh = opendir($tempDir);
    if ($dh) {
        while (($file = readdir($dh)) !== false) {
            if ($file === '.' || $file === '..' || $file === '.htaccess') continue;
            $filePath = $tempDir . $file;
            if (!is_file($filePath)) continue;

            $totalFiles++;
            $totalSizeBytes += filesize($filePath);
            $fileTime = filemtime($filePath);
            if ($fileTime < $oldestFile) $oldestFile = $fileTime;
            if ($fileTime > $newestFile) $newestFile = $fileTime;

            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $fileExtensions[$ext] = ($fileExtensions[$ext] ?? 0) + 1;
        }
        closedir($dh);
    }
}

$stats = [
    'total_files' => $totalFiles,
    'total_size' => $totalSizeBytes,
    'oldest' => $oldestFile !== PHP_INT_MAX ? date('Y-m-d H:i', $oldestFile) : '-',
    'newest' => $newestFile > 0 ? date('Y-m-d H:i', $newestFile) : '-',
    'extensions' => $fileExtensions,
];

// Calculate how many files would be deleted per option
$count1Day = 0;
$count7Days = 0;
$count30Days = 0;

if (is_dir($tempDir)) {
    $dh = opendir($tempDir);
    if ($dh) {
        $now = time();
        while (($file = readdir($dh)) !== false) {
            if ($file === '.' || $file === '..' || $file === '.htaccess') continue;
            $filePath = $tempDir . $file;
            if (!is_file($filePath)) continue;
            $fileTime = filemtime($filePath);
            if ($fileTime < $now - 86400) $count1Day++;
            if ($fileTime < $now - 604800) $count7Days++;
            if ($fileTime < $now - 2592000) $count30Days++;
        }
        closedir($dh);
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> | مدیریت ربات بله</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <meta name="robots" content="noindex, nofollow">
    <style>
        body {
            font-family: Tahoma, Arial, sans-serif;
            background: #f4f6f9;
            padding: 20px;
        }
        .card {
            background: #fff;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        .stat-box {
            text-align: center;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
            height: 100%;
        }
        .stat-box .number {
            font-size: 2rem;
            font-weight: bold;
            color: #0984e3;
        }
        .stat-box .label {
            color: #636e72;
            font-size: 0.9rem;
            margin-top: 5px;
        }
        .btn-cleanup {
            padding: 15px 25px;
            border-radius: 12px;
            font-weight: bold;
            width: 100%;
            text-align: center;
            border: none;
            transition: all 0.2s;
        }
        .btn-cleanup:hover {
            transform: translateY(-2px);
        }
        .btn-1day { background: #ffd32a; color: #333; }
        .btn-7days { background: #f0932b; color: #fff; }
        .btn-30days { background: #e17055; color: #fff; }
        .btn-all { background: #d63031; color: #fff; }
        .file-count { font-size: 0.85rem; color: #636e72; }
        .back-link {
            display: inline-block;
            margin-top: 15px;
        }
        .danger-zone {
            border: 2px solid #d63031;
            border-radius: 12px;
            padding: 20px;
            background: #fff5f5;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3><i class="bi bi-folder2-open"></i> مدیریت فایل‌های موقت</h3>
            <a href="../admin.php" class="btn btn-outline-secondary btn-sm">← بازگشت به پنل مدیریت</a>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="card">
            <h5>📊 آمار فایل‌های موقت</h5>
            <div class="row mt-3">
                <div class="col-md-3 mb-3">
                    <div class="stat-box">
                        <div class="number"><?php echo number_format($stats['total_files']); ?></div>
                        <div class="label">تعداد فایل‌ها</div>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="stat-box">
                        <div class="number">
                            <?php
                            $size = $stats['total_size'];
                            if ($size > 1048576) {
                                echo round($size / 1048576, 2) . ' MB';
                            } elseif ($size > 1024) {
                                echo round($size / 1024, 2) . ' KB';
                            } else {
                                echo $size . ' B';
                            }
                            ?>
                        </div>
                        <div class="label">حجم کل</div>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="stat-box">
                        <div class="number" style="font-size:1.2rem;"><?php echo htmlspecialchars($stats['oldest']); ?></div>
                        <div class="label">قدیمی‌ترین فایل</div>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="stat-box">
                        <div class="number" style="font-size:1.2rem;"><?php echo htmlspecialchars($stats['newest']); ?></div>
                        <div class="label">جدیدترین فایل</div>
                    </div>
                </div>
            </div>
            <?php if (!empty($stats['extensions'])): ?>
                <div class="mt-2 text-muted small">
                    <strong>پسوندها:</strong>
                    <?php foreach ($stats['extensions'] as $ext => $count): ?>
                        <span class="badge bg-secondary me-1">.<?php echo htmlspecialchars($ext); ?>: <?php echo $count; ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Cleanup Actions -->
        <div class="card">
            <h5>🧹 پاکسازی فایل‌ها</h5>
            <p class="text-muted small">
                <i class="bi bi-info-circle"></i>
                فایل‌های ارسالی کاربران در این پوشه ذخیره می‌شوند. برای حفظ امنیت و آزادسازی فضا،
                فایل‌های قدیمی را پاک کنید.
                <strong>توجه:</strong> اگر فایلی در تاریخچه مکالمات وجود داشته باشد، پس از حذف آن فایل از روی سرور،
                دیگر قابل دسترسی نخواهد بود.
            </p>

            <div class="row mt-3">
                <div class="col-md-3 mb-3">
                    <form method="post" onsubmit="return confirm('آیا از پاک کردن فایل‌های بیشتر از یک روز قبل اطمینان دارید؟');">
                        <input type="hidden" name="action" value="delete_1day">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <button type="submit" class="btn-cleanup btn-1day">
                            🗓 فایل‌های یک روز قبل
                            <div class="file-count"><?php echo $count1Day; ?> فایل برای حذف</div>
                        </button>
                    </form>
                </div>
                <div class="col-md-3 mb-3">
                    <form method="post" onsubmit="return confirm('آیا از پاک کردن فایل‌های بیشتر از هفت روز قبل اطمینان دارید؟');">
                        <input type="hidden" name="action" value="delete_7days">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <button type="submit" class="btn-cleanup btn-7days">
                            🗓 فایل‌های هفت روز قبل
                            <div class="file-count"><?php echo $count7Days; ?> فایل برای حذف</div>
                        </button>
                    </form>
                </div>
                <div class="col-md-3 mb-3">
                    <form method="post" onsubmit="return confirm('آیا از پاک کردن فایل‌های بیشتر از یک ماه قبل اطمینان دارید؟');">
                        <input type="hidden" name="action" value="delete_30days">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <button type="submit" class="btn-cleanup btn-30days">
                            🗓 فایل‌های یک ماه قبل
                            <div class="file-count"><?php echo $count30Days; ?> فایل برای حذف</div>
                        </button>
                    </form>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="danger-zone">
                        <form method="post" onsubmit="return confirm('⚠️ آیا از پاک کردن <strong>همه</strong> فایل‌ها اطمینان دارید؟ این عمل غیرقابل بازگشت است!');">
                            <input type="hidden" name="action" value="delete_all">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                            <button type="submit" class="btn-cleanup btn-all">
                                🗑 حذف همه فایل‌ها
                                <div class="file-count"><?php echo $stats['total_files']; ?> فایل</div>
                            </button>
                        </form>
                        <p class="text-danger small mt-2 mb-0">
                            <i class="bi bi-exclamation-triangle"></i>
                            این عملیات تمام فایل‌های موقت را برای همیشه حذف می‌کند.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- List of current files -->
        <div class="card">
            <h5>📄 لیست فایل‌های فعلی</h5>
            <?php
            $files = [];
            if (is_dir($tempDir)) {
                $dh = opendir($tempDir);
                if ($dh) {
                    while (($file = readdir($dh)) !== false) {
                        if ($file === '.' || $file === '..' || $file === '.htaccess') continue;
                        $filePath = $tempDir . $file;
                        if (!is_file($filePath)) continue;
                        $files[] = [
                            'name' => $file,
                            'size' => filesize($filePath),
                            'modified' => filemtime($filePath),
                        ];
                    }
                    closedir($dh);
                }
            }
            usort($files, fn($a, $b) => $b['modified'] - $a['modified']);
            ?>
            <?php if (empty($files)): ?>
                <p class="text-muted">هیچ فایلی در پوشه موقت وجود ندارد.</p>
            <?php else: ?>
                <div class="table-responsive" style="max-height:400px; overflow-y:auto;">
                    <table class="table table-sm table-hover">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>نام فایل</th>
                                <th>اندازه</th>
                                <th>تاریخ آپلود</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $i = 1; foreach ($files as $f): ?>
                            <tr>
                                <td><?php echo $i++; ?></td>
                                <td style="max-width:300px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; direction:ltr;">
                                    <?php echo htmlspecialchars($f['name']); ?>
                                </td>
                                <td>
                                    <?php
                                    $size = $f['size'];
                                    echo $size > 1048576
                                        ? round($size / 1048576, 2) . ' MB'
                                        : ($size > 1024 ? round($size / 1024, 2) . ' KB' : $size . ' B');
                                    ?>
                                </td>
                                <td><?php echo date('Y-m-d H:i', $f['modified']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="text-muted small mt-2">نمایش <?php echo count($files); ?> فایل (مرتب‌شده از جدیدترین به قدیمی‌ترین)</p>
            <?php endif; ?>
        </div>

        <div class="text-center">
            <a href="../admin.php" class="btn btn-secondary back-link">← بازگشت به پنل مدیریت</a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>