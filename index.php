<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>موبیکس بات | اعتماد الکترونیکی</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Vazirmatn', 'sans-serif'],
                    }
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

        .enamad-card {
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(15px);
            -webkit-backdrop-filter: blur(15px);
            border: 1px solid rgba(255, 255, 255, 0.15);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .enamad-card:hover {
            background: rgba(255, 255, 255, 0.12);
            border-color: rgba(255, 255, 255, 0.25);
            transform: translateY(-4px);
            box-shadow: 0 20px 40px rgba(79, 70, 229, 0.2);
        }

        .bot-card {
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(15px);
            -webkit-backdrop-filter: blur(15px);
            border: 1px solid rgba(255, 255, 255, 0.15);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .bot-card:hover {
            background: rgba(255, 255, 255, 0.12);
            border-color: rgba(52, 211, 153, 0.4);
            transform: translateY(-4px);
            box-shadow: 0 20px 40px rgba(52, 211, 153, 0.15);
        }

        .bot-btn {
            background: linear-gradient(135deg, #34d399 0%, #10b981 50%, #059669 100%);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .bot-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: 0.5s;
        }

        .bot-btn:hover::before {
            left: 100%;
        }

        .bot-btn:hover {
            transform: scale(1.05);
            box-shadow: 0 10px 30px rgba(52, 211, 153, 0.4);
        }

        .floating-orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(80px);
            opacity: 0.3;
            animation: float 8s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0) scale(1); }
            50% { transform: translateY(-30px) scale(1.1); }
        }

        @keyframes pulse-ring {
            0% { transform: scale(0.8); opacity: 0.5; }
            50% { transform: scale(1); opacity: 0.8; }
            100% { transform: scale(0.8); opacity: 0.5; }
        }

        .pulse-dot {
            animation: pulse-ring 2s ease-in-out infinite;
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(40px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .animate-slide-up {
            animation: slideUp 0.8s ease-out forwards;
        }

        .animate-slide-up-delay {
            animation: slideUp 0.8s ease-out 0.2s forwards;
            opacity: 0;
        }

        .animate-slide-up-delay-2 {
            animation: slideUp 0.8s ease-out 0.4s forwards;
            opacity: 0;
        }

        .trust-badge-glow {
            box-shadow: 0 0 30px rgba(79, 70, 229, 0.15);
        }

        .stars {
            position: fixed;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 0;
        }

        .star {
            position: absolute;
            width: 2px;
            height: 2px;
            background: white;
            border-radius: 50%;
            animation: twinkle 3s ease-in-out infinite;
        }

        @keyframes twinkle {
            0%, 100% { opacity: 0.2; }
            50% { opacity: 0.8; }
        }
    </style>
</head>
<body class="relative overflow-hidden">

    <!-- Stars Background -->
    <div class="stars" id="stars"></div>

    <!-- Floating Orbs -->
    <div class="floating-orb w-72 h-72 bg-indigo-600 top-10 -right-20" style="animation-delay: 0s;"></div>
    <div class="floating-orb w-96 h-96 bg-emerald-500 -bottom-20 -left-32" style="animation-delay: 3s;"></div>
    <div class="floating-orb w-64 h-64 bg-purple-600 top-1/2 left-1/3" style="animation-delay: 5s;"></div>

    <!-- Main Content -->
    <div class="relative z-10 min-h-screen flex flex-col items-center justify-center px-4 py-12">

        <!-- Header -->
        <div class="text-center mb-12 animate-slide-up">
            <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full glass-card mb-6">
                <span class="w-2 h-2 rounded-full bg-emerald-400 pulse-dot"></span>
                <span class="text-emerald-300 text-sm font-medium">آنلاین و فعال</span>
            </div>
            <h1 class="text-4xl md:text-5xl lg:text-6xl font-extrabold text-white mb-4 leading-tight">
                <span class="bg-gradient-to-l from-indigo-400 via-purple-400 to-emerald-400 bg-clip-text text-transparent">موبیکس‌بات</span>
            </h1>
            <p class="text-lg md:text-xl text-gray-300 max-w-xl mx-auto leading-relaxed">
                ربات همه‌کاره هوش مصنوعی در بله
            </p>
        </div>

        <!-- Cards Container -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-8 w-full max-w-4xl">

            <!-- Enamad Card -->
            <div class="enamad-card rounded-3xl p-8 flex flex-col items-center text-center animate-slide-up-delay">
                <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center mb-6">
                    <i data-lucide="shield-check" class="w-8 h-8 text-white"></i>
                </div>
                <h2 class="text-xl md:text-2xl font-bold text-white mb-3">نماد اعتماد الکترونیکی</h2>
                <p class="text-gray-400 text-sm md:text-base mb-8 leading-relaxed">
                    این وب‌سایت دارای نماد اعتماد الکترونیکی (اینماد) بوده و دارای تاییدیه از وزارت صنعت، معدن و تجارت است.
                </p>
                <div class="bg-white rounded-2xl p-5 trust-badge-glow transition-all duration-300 hover:shadow-lg hover:shadow-indigo-500/20">
<a referrerpolicy='origin' target='_blank' href='https://trustseal.enamad.ir/?id=658208&Code=9BcX9Kl4jxp4xhkJ4nZRHvA3XXN54lEz'><img referrerpolicy='origin' src='https://trustseal.enamad.ir/logo.aspx?id=658208&Code=9BcX9Kl4jxp4xhkJ4nZRHvA3XXN54lEz' alt='' style='cursor:pointer' code='9BcX9Kl4jxp4xhkJ4nZRHvA3XXN54lEz'></a>
                </div>
                <div class="flex items-center gap-2 mt-6 text-gray-500 text-xs">
                    <i data-lucide="external-link" class="w-3 h-3"></i>
                    <span>برای مشاهده جزئیات کلیک کنید</span>
                </div>
            </div>

            <!-- Bale Bot Card -->
            <div class="bot-card rounded-3xl p-8 flex flex-col items-center text-center animate-slide-up-delay-2">
                <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-emerald-400 to-teal-600 flex items-center justify-center mb-6">
                    <i data-lucide="bot" class="w-8 h-8 text-white"></i>
                </div>
                <h2 class="text-xl md:text-2xl font-bold text-white mb-3">ربات هوش مصنوعی بله</h2>
                <p class="text-gray-400 text-sm md:text-base mb-6 leading-relaxed">
                    ربات همه‌کاره هوش مصنوعی با قابلیت‌های متنوع شامل چت هوشمند، تولید محتوا، پاسخگویی به سوالات و بسیاری امکانات دیگر
                </p>

                <!-- Features mini list -->
                <div class="grid grid-cols-2 gap-3 w-full mb-8">
                    <div class="glass-card rounded-xl px-3 py-2 flex items-center gap-2">
                        <i data-lucide="message-square" class="w-4 h-4 text-emerald-400 shrink-0"></i>
                        <span class="text-gray-300 text-xs md:text-sm">چت هوشمند</span>
                    </div>
                    <div class="glass-card rounded-xl px-3 py-2 flex items-center gap-2">
                        <i data-lucide="file-text" class="w-4 h-4 text-emerald-400 shrink-0"></i>
                        <span class="text-gray-300 text-xs md:text-sm">تولید محتوا</span>
                    </div>
                    <div class="glass-card rounded-xl px-3 py-2 flex items-center gap-2">
                        <i data-lucide="brain" class="w-4 h-4 text-emerald-400 shrink-0"></i>
                        <span class="text-gray-300 text-xs md:text-sm">هوش مصنوعی</span>
                    </div>
                    <div class="glass-card rounded-xl px-3 py-2 flex items-center gap-2">
                        <i data-lucide="zap" class="w-4 h-4 text-emerald-400 shrink-0"></i>
                        <span class="text-gray-300 text-xs md:text-sm">پاسخ سریع</span>
                    </div>
                </div>

                <!-- Bot Avatar -->
                <div class="relative mb-8">
                    <div class="w-28 h-28 rounded-full bg-gradient-to-br from-emerald-400 via-teal-500 to-green-600 flex items-center justify-center shadow-lg shadow-emerald-500/20">
                        <i data-lucide="bot" class="w-14 h-14 text-white"></i>
                    </div>
                    <div class="absolute -bottom-1 -right-1 w-7 h-7 rounded-full bg-emerald-400 border-4 border-[#1a1a3e] flex items-center justify-center">
                        <i data-lucide="sparkles" class="w-3 h-3 text-[#1a1a3e]"></i>
                    </div>
                </div>

                <!-- CTA Button -->
                <a href="https://ble.ir/mobixbot" target="_blank" class="bot-btn inline-flex items-center gap-3 px-8 py-4 rounded-2xl text-white font-bold text-base md:text-lg no-underline">
                    <i data-lucide="send" class="w-5 h-5"></i>
                    <span>ورود به ربات بله</span>
                </a>

                <div class="flex items-center gap-2 mt-6 text-gray-500 text-xs">
                    <i data-lucide="smartphone" class="w-3 h-3"></i>
                    <span>نیاز به اپلیکیشن بله دارید</span>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="mt-16 text-center animate-slide-up-delay-2">
            <div class="glass-card rounded-full px-6 py-3 inline-flex items-center gap-3">
                <span class="w-2 h-2 rounded-full bg-emerald-400 pulse-dot"></span>
                <span class="text-gray-400 text-sm">موبیکس‌بات — قدرتمند، هوشمند، قابل اعتماد</span>
            </div>
        </div>
    </div>

    <script>
        // Create Stars
        const starsContainer = document.getElementById('stars');
        for (let i = 0; i < 60; i++) {
            const star = document.createElement('div');
            star.className = 'star';
            star.style.left = Math.random() * 100 + '%';
            star.style.top = Math.random() * 100 + '%';
            star.style.animationDelay = Math.random() * 3 + 's';
            star.style.animationDuration = (2 + Math.random() * 3) + 's';
            star.style.width = (1 + Math.random() * 2) + 'px';
            star.style.height = star.style.width;
            starsContainer.appendChild(star);
        }

        // Initialize Lucide Icons
        lucide.createIcons();
    </script>
</body>
</html>