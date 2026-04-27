<?php
require_once '../../init.php';

use Database\Database;
use Database\Bootstrap;
use Core\Config;
use Modules\Bot\RateLimiter;

$error = '';

// N8: Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Check if database needs bootstrap
try {
    $db = Database::getInstance();
    $bootstrap = new Bootstrap($db);
    $bootstrap->run();
} catch (Exception $e) {
    $error = "خطا در اتصال به پایگاه داده: " . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    // N8: Validate CSRF token
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $submittedToken)) {
        $error = 'درخواست نامعتبر. لطفاً دوباره تلاش کنید.';
    } else {
        // N5: Rate limit admin login attempts by IP
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $rateKey = 'admin_login:' . $ip;
        if (!RateLimiter::check($rateKey, 5, 60)) {
            $error = 'تعداد تلاش‌های ناموفق بیش از حد مجاز. لطفاً ۶۰ ثانیه صبر کنید.';
        } else {
            $username = $_POST['username'] ?? '';
            $password = $_POST['password'] ?? '';

            $adminUser = Config::get('ADMIN_USERNAME', 'admin');
            $adminPassHash = Config::get('ADMIN_PASSWORD', '');

            if ($username === $adminUser && !empty($adminPassHash)) {
                if (strpos($adminPassHash, '$2y$') === 0) {
                    $valid = password_verify($password, $adminPassHash);
                } else {
                    $valid = ($password === $adminPassHash);
                }

                if ($valid) {
                    // Regenerate session on login for security
                    session_regenerate_id(true);
                    $_SESSION['admin_logged_in'] = true;
                    header('Location: dashboard.php');
                    exit;
                }
            }
            $error = 'نام کاربری یا رمز عبور اشتباه است.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ورود به پنل مدیریت</title>
    <style>
        body { font-family: Tahoma, Arial, sans-serif; background-color: #f4f4f4; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .login-container { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); width: 100%; max-width: 400px; }
        h2 { text-align: center; color: #333; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; color: #666; }
        input { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
        button { width: 100%; padding: 10px; background-color: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
        button:hover { background-color: #0056b3; }
        .error { color: red; text-align: center; margin-bottom: 10px; }
    </style>
</head>
<body>
    <div class="login-container">
        <h2>ورود به مدیریت</h2>
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <div class="form-group">
                <label for="username">نام کاربری:</label>
                <input type="text" id="username" name="username" required>
            </div>
            <div class="form-group">
                <label for="password">رمز عبور:</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit" name="login">ورود</button>
        </form>
    </div>
</body>
</html>