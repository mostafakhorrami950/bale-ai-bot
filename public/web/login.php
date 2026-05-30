<?php
require_once __DIR__ . '/init.php';

// If already logged in, redirect to dashboard
if (getWebUser()) {
    header('Location: /web/dashboard.php');
    exit;
}

$error = $_SESSION['flash_error'] ?? '';
$success = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_error'], $_SESSION['flash_success']);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ورود | موبیکس‌بات</title>
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
            background: linear-gradient(135deg, #0f0c29 0%, #1a1a3e 40%, #24243e 100%);
            min-height: 100vh;
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .input-field {
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: white;
            transition: all 0.3s;
        }
        .input-field:focus {
            border-color: rgba(52, 211, 153, 0.5);
            box-shadow: 0 0 20px rgba(52, 211, 153, 0.1);
            outline: none;
        }
        .btn-primary {
            background: linear-gradient(135deg, #34d399 0%, #10b981 50%, #059669 100%);
            transition: all 0.3s;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(52, 211, 153, 0.3);
        }
        .btn-primary:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen p-4">
    <div class="w-full max-w-md animate__animated animate__fadeIn">
        <!-- Logo -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-gradient-to-br from-emerald-400 to-teal-600 mb-4">
                <i data-lucide="bot" class="w-8 h-8 text-white"></i>
            </div>
            <h1 class="text-2xl font-bold text-white">موبیکس‌بات</h1>
            <p class="text-gray-400 text-sm mt-1">نسخه تحت وب</p>
        </div>

        <?php if ($error): ?>
        <div class="glass-card rounded-xl p-4 mb-4 border border-red-500/30 bg-red-500/10">
            <p class="text-red-300 text-sm text-center"><?= htmlspecialchars($error) ?></p>
        </div>
        <?php endif; ?>
        <?php if ($success): ?>
        <div class="glass-card rounded-xl p-4 mb-4 border border-emerald-500/30 bg-emerald-500/10">
            <p class="text-emerald-300 text-sm text-center"><?= htmlspecialchars($success) ?></p>
        </div>
        <?php endif; ?>

        <!-- Step 1: Phone Number -->
        <div id="step1" class="glass-card rounded-2xl p-6">
            <h2 class="text-lg font-bold text-white mb-4">ورود / ثبت‌نام</h2>
            <p class="text-gray-400 text-sm mb-4">شماره موبایل خود را وارد کنید. کد تایید برای شما ارسال می‌شود.</p>
            <div class="mb-4">
                <label class="block text-gray-300 text-sm mb-2">شماره موبایل</label>
                <input type="tel" id="phone" maxlength="11" placeholder="09123456789"
                       class="input-field w-full rounded-xl px-4 py-3 text-lg text-center tracking-widest"
                       oninput="this.value=this.value.replace(/[^0-9]/g,'')">
            </div>
            <button id="sendOtpBtn" onclick="sendOtp()"
                    class="btn-primary w-full rounded-xl py-3 text-white font-bold text-base">
                دریافت کد تایید
            </button>
            <p id="sendOtpStatus" class="text-center text-xs mt-3 hidden"></p>
            <p id="timerText" class="text-center text-xs mt-2 text-gray-500 hidden"></p>
        </div>

        <!-- Step 2: OTP Code -->
        <div id="step2" class="glass-card rounded-2xl p-6 hidden">
            <h2 class="text-lg font-bold text-white mb-4">کد تایید</h2>
            <p class="text-gray-400 text-sm mb-4">کد ۶ رقمی ارسال شده به شماره <span id="displayPhone" class="text-emerald-400"></span> را وارد کنید.</p>
            <div class="mb-4">
                <input type="text" id="otp" maxlength="6" placeholder="000000"
                       class="input-field w-full rounded-xl px-4 py-3 text-2xl text-center tracking-[0.5em]" 
                       oninput="if(this.value.length===6)verifyOtp()">
            </div>
            <button id="verifyOtpBtn" onclick="verifyOtp()" disabled
                    class="btn-primary w-full rounded-xl py-3 text-white font-bold text-base">
                تایید و ورود
            </button>
            <p id="verifyOtpStatus" class="text-center text-xs mt-3 hidden"></p>
            <button onclick="backToPhone()" class="text-gray-500 text-sm mt-4 hover:text-gray-300 transition w-full text-center">
                تغییر شماره
            </button>
        </div>
    </div>

    <script>
    let phone = '';
    let otpTimer = null;
    let otpCooldown = false;

    function sendOtp() {
        phone = document.getElementById('phone').value.trim();
        if (phone.length < 10) {
            document.getElementById('sendOtpStatus').className = 'text-center text-xs mt-3 text-red-400';
            document.getElementById('sendOtpStatus').textContent = 'لطفاً شماره موبایل معتبر وارد کنید.';
            document.getElementById('sendOtpStatus').classList.remove('hidden');
            return;
        }

        const btn = document.getElementById('sendOtpBtn');
        btn.disabled = true;
        btn.textContent = 'در حال ارسال...';
        document.getElementById('sendOtpStatus').classList.add('hidden');

        fetch('/web/ajax/send_otp.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ phone: phone })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.getElementById('step1').classList.add('hidden');
                document.getElementById('step2').classList.remove('hidden');
                document.getElementById('displayPhone').textContent = phone;
                startOtpTimer();
            } else {
                document.getElementById('sendOtpStatus').className = 'text-center text-xs mt-3 text-red-400';
                document.getElementById('sendOtpStatus').textContent = data.error || 'خطا در ارسال کد';
                document.getElementById('sendOtpStatus').classList.remove('hidden');
                btn.disabled = false;
                btn.textContent = 'دریافت کد تایید';
            }
        })
        .catch(() => {
            document.getElementById('sendOtpStatus').className = 'text-center text-xs mt-3 text-red-400';
            document.getElementById('sendOtpStatus').textContent = 'خطا در ارتباط با سرور';
            document.getElementById('sendOtpStatus').classList.remove('hidden');
            btn.disabled = false;
            btn.textContent = 'دریافت کد تایید';
        });
    }

    function verifyOtp() {
        const code = document.getElementById('otp').value.trim();
        if (code.length !== 6) return;

        const btn = document.getElementById('verifyOtpBtn');
        btn.disabled = true;
        btn.textContent = 'در حال بررسی...';
        document.getElementById('verifyOtpStatus').classList.add('hidden');

        fetch('/web/ajax/verify_otp.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ phone: phone, code: code })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                window.location.href = '/web/dashboard.php';
            } else {
                document.getElementById('verifyOtpStatus').className = 'text-center text-xs mt-3 text-red-400';
                document.getElementById('verifyOtpStatus').textContent = data.error || 'کد نامعتبر است';
                document.getElementById('verifyOtpStatus').classList.remove('hidden');
                btn.disabled = false;
                btn.textContent = 'تایید و ورود';
            }
        })
        .catch(() => {
            document.getElementById('verifyOtpStatus').className = 'text-center text-xs mt-3 text-red-400';
            document.getElementById('verifyOtpStatus').textContent = 'خطا در ارتباط با سرور';
            document.getElementById('verifyOtpStatus').classList.remove('hidden');
            btn.disabled = false;
            btn.textContent = 'تایید و ورود';
        });
    }

    function startOtpTimer() {
        otpCooldown = true;
        let remaining = <?= OTP_RESEND_SECONDS ?>;
        const timerEl = document.getElementById('timerText');
        timerEl.classList.remove('hidden');
        timerEl.className = 'text-center text-xs mt-2 text-gray-500';

        if (otpTimer) clearInterval(otpTimer);
        otpTimer = setInterval(() => {
            remaining--;
            if (remaining <= 0) {
                clearInterval(otpTimer);
                otpCooldown = false;
                document.getElementById('sendOtpBtn').disabled = false;
                document.getElementById('sendOtpBtn').textContent = 'ارسال مجدد کد';
                timerEl.classList.add('hidden');
            } else {
                timerEl.textContent = `می‌توانید پس از ${remaining} ثانیه دوباره درخواست دهید`;
            }
        }, 1000);
    }

    function backToPhone() {
        document.getElementById('step2').classList.add('hidden');
        document.getElementById('step1').classList.remove('hidden');
        document.getElementById('sendOtpBtn').disabled = false;
        document.getElementById('sendOtpBtn').textContent = 'دریافت کد تایید';
        document.getElementById('phone').value = phone;
    }

    // Auto-focus phone input
    document.getElementById('phone').focus();
    </script>
</body>
</html>