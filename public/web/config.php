<?php
/**
 * Web version configuration for Bale AI Bot
 * This file is separate from the bot's config — does NOT modify init.php or .env
 */

// ---- SMS API (IranPayamak) ----
define('SMS_API_KEY', 'DnWwTsp9TLDZRZKURyGxQbobs9JzPKFoPSmJzGgCoEbZSoG5so');
define('SMS_PATTERN_CODE', 'Umx6a0OAKA');
define('SMS_LINE_NUMBER', '50002178584000');

// ---- OTP Settings ----
define('OTP_EXPIRE_SECONDS', 180); // OTP valid for 3 minutes
define('OTP_RESEND_SECONDS', 90);  // Can resend after 90 seconds

// ---- Session ----
define('SESSION_DURATION_HOURS', 24); // Auto-logout after 24h