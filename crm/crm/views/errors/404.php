<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>صفحه مورد نظر یافت نشد</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Tahoma', sans-serif;
        }
        .error-container {
            background: #fff;
            border-radius: 20px;
            padding: 60px;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            text-align: center;
        }
        .error-code {
            font-size: 120px;
            font-weight: bold;
            background: linear-gradient(135deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            line-height: 1;
        }
        h2 { color: #333; margin: 20px 0 10px; }
        p { color: #666; margin-bottom: 30px; }
        .btn-home {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: #fff;
            border: none;
            padding: 12px 30px;
            border-radius: 10px;
            text-decoration: none;
            display: inline-block;
        }
        .btn-home:hover { color: #fff; transform: translateY(-2px); box-shadow: 0 5px 15px rgba(102,126,234,0.4); }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-code">404</div>
        <h2>صفحه مورد نظر یافت نشد</h2>
        <p>صفحه‌ای که به دنبال آن هستید وجود ندارد یا حذف شده است.</p>
        <a href="<?php echo $config['url']; ?>/dashboard" class="btn-home">
            <i class="bi bi-house-door"></i> بازگشت به داشبورد
        </a>
    </div>
</body>
</html>