<?php
/**
 * Installation Script - Creates database tables automatically
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

// Bootstrap
require_once __DIR__ . '/index.php';

use Core\Migration;
use Core\Session;

// Only allow if not installed
$config = $GLOBALS['app_config'];

$installed = false;
try {
    $pdo = new PDO(
        "mysql:host={$config['db']['host']};port={$config['db']['port']};dbname={$config['db']['name']};charset=utf8mb4",
        $config['db']['user'],
        $config['db']['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
    if ($stmt->rowCount() > 0) {
        $installed = true;
    }
} catch (\PDOException $e) {}

// Handle installation
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$installed) {
    try {
        $migration = new Migration();
        ob_start();
        $migration->run();
        $output = ob_get_clean();
        $message = 'نصب با موفقیت انجام شد! اطلاعات زیر ثبت شد:';
        $installed = true;
    } catch (\Exception $e) {
        $error = 'خطا در نصب: ' . $e->getMessage();
    }
}

// Copy .env.example to .env if not exists
$envPath = __DIR__ . '/../.env';
if (!file_exists($envPath)) {
    copy(__DIR__ . '/../.env.example', $envPath);
}

?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>نصب سیستم CRM آژانس مسافرتی</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Tahoma', sans-serif; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            direction: rtl;
        }
        .install-container {
            background: white;
            border-radius: 20px;
            padding: 40px;
            max-width: 600px;
            width: 90%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        h1 { color: #333; margin-bottom: 10px; font-size: 24px; text-align: center; }
        p { color: #666; margin-bottom: 20px; text-align: center; line-height: 1.8; }
        .info-box {
            background: #f0f7ff;
            border: 1px solid #b3d4fc;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            font-size: 13px;
            line-height: 2;
        }
        .info-box strong { color: #1a73e8; }
        .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 15px 40px;
            border-radius: 10px;
            font-size: 16px;
            cursor: pointer;
            width: 100%;
            font-weight: bold;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 10px 20px rgba(102, 126, 234, 0.4); }
        .btn:disabled { opacity: 0.6; cursor: not-allowed; }
        .message { 
            background: #d4edda; 
            border: 1px solid #c3e6cb; 
            color: #155724; 
            padding: 15px; 
            border-radius: 10px; 
            margin-bottom: 20px;
            line-height: 2;
        }
        .error { 
            background: #f8d7da; 
            border: 1px solid #f5c6cb; 
            color: #721c24; 
            padding: 15px; 
            border-radius: 10px; 
            margin-bottom: 20px;
        }
        .success-icon { text-align: center; font-size: 48px; margin-bottom: 10px; }
        .login-info {
            background: #e8f5e9;
            border: 1px solid #c8e6c9;
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
            text-align: center;
        }
        .login-info code {
            background: #fff;
            padding: 5px 10px;
            border-radius: 5px;
            border: 1px solid #ddd;
            font-size: 14px;
        }
        .login-link {
            display: inline-block;
            margin-top: 15px;
            padding: 12px 30px;
            background: #1a73e8;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: bold;
        }
        .login-link:hover { background: #1557b0; }
    </style>
</head>
<body>
    <div class="install-container">
        <h1>🚀 نصب CRM آژانس مسافرتی</h1>
        <p>سیستم مدیریت ارتباط با مشتریان برای آژانس‌های هواپیمایی</p>
        
        <?php if ($message): ?>
            <div class="success-icon">✅</div>
            <div class="message">
                <?php echo nl2br(htmlspecialchars($message)); ?>
                <br>
                دیتابیس و جداول با موفقیت ایجاد شدند.
            </div>
            <div class="login-info">
                <strong>اطلاعات ورود به سیستم:</strong><br><br>
                <span>نام کاربری: <code>admin</code></span><br>
                <span>رمز عبور: <code>admin123</code></span><br><br>
                <a href="login" class="login-link">ورود به سیستم</a>
            </div>
        <?php elseif ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <button class="btn" onclick="location.reload()">تلاش مجدد</button>
        <?php elseif ($installed): ?>
            <div class="message">سیستم قبلاً نصب شده است.</div>
            <a href="login" class="btn" style="display: block; text-align: center; text-decoration: none;">ورود به سیستم</a>
        <?php else: ?>
            <div class="info-box">
                <strong>قابلیت‌های سیستم:</strong><br>
                ✅ مدیریت پایپ لاین و مراحل فروش<br>
                ✅ مدیریت معاملات و مشتریان<br>
                ✅ ایجاد لینک پرداخت (درگاه زیبال)<br>
                ✅ ارسال پیامک (پنل IPPanel)<br>
                ✅ سیستم نقش‌های کاربری و دسترسی<br>
                ✅ داشبورد و گزارشات<br>
                ✅ قابلیت شخصی‌سازی و توسعه ماژولار
            </div>
            
            <form method="POST">
                <p style="color: #888; font-size: 13px;">
                    پس از کلیک روی دکمه نصب، دیتابیس و تمام جداول به صورت خودکار ایجاد می‌شوند.
                    اطمینان حاصل کنید که اطلاعات دیتابیس در فایل <code>.env</code> صحیح است.
                </p>
                <button type="submit" class="btn">🌟 شروع نصب سیستم</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>