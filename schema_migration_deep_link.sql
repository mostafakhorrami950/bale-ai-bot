-- ============================================================
-- Deep Link (دیپ لینک / کمپین‌های ورودی)
-- ============================================================

-- 1. کمپین‌های دیپ لینک (قابل مدیریت در پنل ادمین)
CREATE TABLE IF NOT EXISTS deep_link_campaigns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payload VARCHAR(64) NOT NULL UNIQUE COMMENT 'مقدار payload (مثلا instagram, webinar)',
    title VARCHAR(128) NOT NULL COMMENT 'عنوان کمپین برای نمایش در پنل',
    welcome_text TEXT DEFAULT NULL COMMENT 'متن خوش‌آمدگویی اختصاصی (اگر null باشد، پیش‌فرض استفاده می‌شود)',
    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '۱=فعال، ۰=غیرفعال',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_payload (payload),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. ورودی‌های دیپ لینک (لاگ هر بار کلیک روی لینک)
CREATE TABLE IF NOT EXISTS deep_link_entries (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT DEFAULT NULL COMMENT 'FK به deep_link_campaigns.id',
    payload VARCHAR(64) NOT NULL COMMENT 'payload دریافتی',
    bale_user_id BIGINT DEFAULT NULL COMMENT 'شناسه کاربر در بله (قبل از ثبت‌نام)',
    registered_user_id INT DEFAULT NULL COMMENT 'FK به users.id بعد از ثبت‌نام',
    is_registered TINYINT(1) NOT NULL DEFAULT 0 COMMENT '۱=ثبت‌نام کرده، ۰=هنوز ثبت‌نام نکرده',
    first_name VARCHAR(128) DEFAULT NULL,
    username VARCHAR(128) DEFAULT NULL,
    clicked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'زمان کلیک روی لینک',
    registered_at TIMESTAMP NULL DEFAULT NULL COMMENT 'زمان ثبت‌نام (اگر انجام شده باشد)',
    INDEX idx_payload (payload),
    INDEX idx_campaign (campaign_id),
    INDEX idx_registered (is_registered),
    INDEX idx_bale_user (bale_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. کمپین‌های پیش‌فرض (اختیاری)
INSERT IGNORE INTO deep_link_campaigns (payload, title, welcome_text) VALUES
('instagram', 'اینستاگرام', '👋 به ربات خوش آمدید!\n\nشما از طریق **اینستاگرام** به ما پیوستید.\nلطفاً برای شروع، شماره خود را ثبت کنید.'),
('telegram', 'کانال تلگرام', '👋 خوش آمدید!\n\nاز طریق **کانال تلگرام** آمدید.\nلطفاً برای شروع شماره خود را ارسال کنید.'),
('youtube', 'یوتیوب', '👋 سلام!\n\nاز **یوتیوب** به ما ملحق شدید.\nلطفاً شماره خود را ارسال کنید.'),
('webinar', 'وبینار', '👋 خوش آمدید!\n\nبرای شرکت در **وبینار** ثبت‌نام کنید.\nلطفاً شماره خود را ارسال کنید.'),
('ads', 'تبلیغات کلیکی', '👋 سلام!\n\nاز طریق **تبلیغات** به ما پیوستید.\nلطفاً شماره خود را ثبت کنید.');