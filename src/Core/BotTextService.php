<?php

namespace Core;

use Database\Database;

/**
 * Manages all bot text strings stored in the database.
 * Allows admin to modify any text without changing code.
 */
class BotTextService
{
    private static ?array $cache = null;

    /**
     * Get a text by key from database, with optional fallback default.
     */
    public static function get(string $key, array $replacements = []): string
    {
        $text = self::getDefaults()[$key] ?? '';
        
        try {
            $db = Database::getInstance();
            $stmt = $db->query("SELECT text_value FROM bot_texts WHERE text_key = ?", [$key]);
            $row = $stmt->fetch();
            if ($row && !empty($row['text_value'])) {
                $text = $row['text_value'];
            }
        } catch (\Throwable $e) {
            // Fallback to default on DB error
        }

        // Apply replacements: {key} => value
        if (!empty($replacements)) {
            foreach ($replacements as $k => $v) {
                $text = str_replace('{' . $k . '}', (string)$v, $text);
            }
        }

        return $text;
    }

    /**
     * Set a text value in the database.
     */
    public static function set(string $key, string $value): void
    {
        try {
            $db = Database::getInstance();
            $db->query(
                "INSERT INTO bot_texts (text_key, text_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE text_value = ?",
                [$key, $value, $value]
            );
            self::$cache = null; // invalidate cache
        } catch (\Throwable $e) {
            // Silently fail
        }
    }

    /**
     * Get all texts from database.
     */
    public static function getAll(): array
    {
        try {
            $db = Database::getInstance();
            $rows = $db->query("SELECT text_key, text_value, updated_at FROM bot_texts ORDER BY text_key ASC")->fetchAll();
            return $rows;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Reset a single key to its default value.
     */
    public static function resetToDefault(string $key): void
    {
        $defaults = self::getDefaults();
        if (isset($defaults[$key])) {
            self::set($key, $defaults[$key]);
        }
    }

    /**
     * Seed all default values into the database (idempotent).
     * Called from DatabaseRepairService.
     */
    public static function seedDefaults(): void
    {
        $defaults = self::getDefaults();
        try {
            $db = Database::getInstance();
            foreach ($defaults as $key => $value) {
                $db->query(
                    "INSERT IGNORE INTO bot_texts (text_key, text_value) VALUES (?, ?)",
                    [$key, $value]
                );
            }
        } catch (\Throwable $e) {
            // Silently fail
        }
    }

    /**
     * Get all default text values.
     * These are the fallback values used when DB is unavailable.
     */
    public static function getDefaults(): array
    {
        return [
            // ─── StartHandler ───
            'welcome_unregistered' => "سلام! 👋\n\nبه ربات هوش مصنوعی خوش آمدید.\nبرای استفاده از خدمات، لطفاً شماره خود را تأیید كنید.",
            'welcome_registered' => "🤖 خوش آمدید! لطفاً از منوی زیر گزینه مورد نظر را انتخاب کنید:",
            'error_general' => "متأسفانه مشکلی پیش آمد. لطفاً دوباره تلاش کنید.",
            'main_menu_prompt' => "🤖 خوش آمدید! لطفاً از منوی زیر گزینه مورد نظر را انتخاب کنید:",

            // ─── MessageHandler ───
            'fallback_menu' => "🤖 لطفاً از منوی زیر گزینه‌ای را انتخاب کنید:",
            'registration_success' => "✅ ثبت‌نام شما با موفقیت انجام شد!",
            'registration_welcome' => "🤖 به ربات خوش آمدید. لطفاً از منوی زیر استفاده کنید:",
            'registration_failed' => "❌ متأسفانه در ثبت اطلاعات مشکلی پیش آمد.",
            'help_default_text' => "🤖 **راهنمای ربات**\n\n"
                . "🎨 **ساخت تصویر**: با استفاده از هوش مصنوعی تصویر بسازید.\n"
                . "🖼 **ویرایش عکس**: عکس خود را آپلود کرده و با توضیحات ویرایش کنید.\n"
                . "💬 **چت با هوش مصنوعی**: با مدل‌های مختلف گفتگو کنید.\n"
                . "👤 **حساب کاربری**: موجودی و تاریخچه خود را مشاهده کنید.\n"
                . "💳 **خرید اعتبار**: اعتبار خود را افزایش دهید.\n\n"
                . "📞 پشتیبانی: @mobix_tube",
            'help_load_error' => "❌ خطا در بارگذاری راهنما.",

            // ─── CallbackHandler ───
            'help_callback' => "❓ راهنما:\nاین ربات به شما کمک می‌کند با هوش مصنوعی تصویر بسازید.",
            'membership_confirmed' => "✅ عضویت شما تأیید شد. از منوی زیر استفاده کنید:",

            // ─── ImageHandler ───
            'ai_processing_warning' => "⚠️ درخواست شما در حال پردازش توسط هوش مصنوعی است. در صورت لغو، هزینه عودت داده نمی‌شود و خروجی پس از آماده شدن ارسال خواهد شد.",
            'ai_processing_wait' => "⏳ لطفاً صبور باشید، درخواست قبلی شما در حال پردازش است...",
            'no_active_models' => "❌ در حال حاضر هیچ مدل فعالی یافت نشد.",
            'model_selection_title' => "🎯 لطفاً مدل هوش مصنوعی مورد نظر خود را انتخاب کنید:\n\n",
            'model_list_error' => "⚠️ خطا در دریافت لیست مدل‌ها.",
            'invalid_model' => "⚠️ مدل انتخاب شده معتبر نیست.",
            'model_selected_prompt' => "🎨 مدل «{model_name}» انتخاب شد.\n\nلطفاً متن تصویر مورد نظر خود را بنویسید:",
            'invalid_prompt' => "⚠️ لطفاً یک متن معتبر وارد کنید.",
            'model_not_found' => "❌ مدل یافت نشد. لطفاً دوباره انتخاب کنید.",
            'insufficient_credit' => "❌ اعتبار شما کافی نیست (نیاز به {cost} اعتبار).",
            'credit_deduct_error' => "⚠️ خطا در کسر اعتبار. لطفاً با پشتیبانی تماس بگیرید.",
            'generating_image' => "⏳ در حال ساخت تصویر توسط «{model_name}»... لطفاً چند لحظه صبر کنید.",
            'image_error' => "⚠️ خطا در تولید تصویر: {error}",
            'text_fallback_caption' => "مدل به جای تصویر، متن زیر را تولید کرد:\n\n{text}\n———\n💎 هزینه: {cost} اعتبار",
            'operation_complete' => "✨ عملیات با موفقیت پایان یافت.",
            'image_caption' => "✅ خروجی هوش مصنوعی\n💎 هزینه کسر شده: {cost} اعتبار",
            'image_fallback_menu' => "🤖 لطفاً از منوی زیر یکی از گزینه‌ها را انتخاب کنید:",

            // ─── Img2ImgHandler ───
            'edit_ai_processing_warning' => "⚠️ درخواست شما در حال پردازش است. در صورت لغو، هزینه عودت داده نمی‌شود و خروجی پس از آماده شدن ارسال خواهد شد.",
            'edit_ai_processing_wait' => "⏳ لطفاً صبور باشید...",
            'edit_no_active_models' => "❌ هیچ مدل فعالی یافت نشد.",
            'edit_model_selection_title' => "🎯 مدل مورد نظر را انتخاب کنید:\n\n",
            'edit_model_selected_photos' => "📸 مدل انتخاب شد. عکس‌ها را ارسال کنید (حداکثر {max_photos})\nسپس دکمه ✅ انجام شد را بزنید:",
            'edit_photo_prompt' => "📸 لطفاً عکس ارسال کنید (حداکثر {max_photos})\nسپس دکمه ✅ انجام شد را بزنید:",
            'edit_max_photos' => "⚠️ حداکثر {max_photos} عکس مجاز است.",
            'edit_photo_received' => "✅ عکس دریافت شد. می‌توانید عکس‌های بیشتری ارسال کنید یا دکمه ✅ انجام شد را بزنید.",
            'edit_min_photos' => "⚠️ حداقل ۱ عکس ارسال کنید.",
            'edit_downloading' => "⏳ در حال دریافت عکس‌ها از بله...",
            'edit_download_failed' => "⚠️ هیچ‌کدام از عکس‌ها قابل دریافت نبود.",
            'edit_photos_received' => "✏️ {count} عکس دریافت شد.",
            'edit_photos_partial' => " {failed} عکس قابل دریافت نبود.",
            'edit_enter_prompt' => " متن تغییرات (Prompt) را بنویسید:",
            'edit_state_error' => "❌ خطا در بازیابی اطلاعات. دوباره شروع کنید.",
            'edit_model_not_found' => "❌ مدل یافت نشد.",
            'edit_insufficient_credit' => "❌ اعتبار کافی ندارید (نیاز به {cost} اعتبار).",
            'edit_credit_deduct_error' => "⚠️ خطا در کسر اعتبار.",
            'edit_processing' => "⏳ در حال پردازش ویرایش عکس... لطفاً صبور باشید.",
            'edit_invalid_images' => "⚠️ تصاویر معتبر نیستند.",
            'edit_text_fallback_caption' => "مدل به جای تصویر، متن زیر را تولید کرد:\n\n{text}\n———\n💎 هزینه: {cost} اعتبار",
            'edit_image_caption' => "✅ ویرایش تصویر انجام شد\n💎 هزینه: {cost} اعتبار",
            'edit_complete' => "✨ انجام شد.",
            'edit_error' => "⚠️ خطا: {error}",
            'edit_fallback_menu' => "🤖 لطفاً یکی از گزینه‌های منو را انتخاب کنید:",

            // ─── ChatHandler ───
            'chat_menu_title' => "💬 گفتگوی هوش مصنوعی\n\n"
                . "آیا می‌خواهید از مدل پیش‌فرض استفاده کنید یا مدل دیگری انتخاب نمایید؟\n\n"
                . "📌 نکات:\n"
                . "• هزینه بر اساس تعداد کاراکتر محاسبه می‌شود\n"
                . "• می‌توانید عکس و فایل نیز ارسال کنید\n"
                . "• با /exit از مکالمه خارج شوید",
            'chat_user_not_found' => "❌ کاربر یافت نشد.",
            'chat_no_text_models' => "❌ هیچ مدل متنی فعالی یافت نشد. لطفاً ابتدا یک مدل اضافه کنید.",
            'chat_model_list_title' => "🎯 مدل مورد نظر را انتخاب کنید:\n\n",
            'chat_model_list_error' => "⚠️ خطا در دریافت لیست مدل‌ها.",
            'chat_model_not_found' => "❌ مدل یافت نشد.",
            'chat_conversation_started' => "✅ گفتگو با مدل «{display_name}» آغاز شد.\n\n"
                . "📊 هزینه:\n"
                . "  💰 ورودی: {in_cost} اعتبار/کاراکتر\n"
                . "  💰 خروجی: {out_cost} اعتبار/کاراکتر\n"
                . "{free_text}"
                . "\n📁 فرمت‌های پشتیبانی شده:\n"
                . "  {formats}\n"
                . "\n📝 پیام خود را بنویسید.\n"
                . "📸 می‌توانید عکس یا فایل با فرمت‌های مجاز ارسال کنید.\n"
                . "🚪 /exit برای خروج از مکالمه.",
            'chat_conversation_not_found' => "❌ مکالمه یافت نشد.",
            'chat_conversation_deleted' => "🗑️ مکالمه حذف شد.",
            'chat_exit_message' => "🚪 از مکالمه خارج شدید.\nبرای شروع دوباره از منوی اصلی استفاده کنید.",
            'chat_enter_message' => "📝 لطفاً پیام خود را بنویسید یا عکس/فایل ارسال کنید.\n/exit برای خروج.",
            'chat_processing' => "⏳ در حال دریافت پاسخ...",
            'chat_error' => "⚠️ خطایی رخ داد. مجدداً تلاش کنید.",
            'chat_credit_error' => "❌ اعتبار کافی ندارید (نیاز به {cost} اعتبار). لطفاً حساب خود را شارژ کنید.",
            'chat_credit_deduct_error' => "⚠️ خطا در کسر اعتبار.",
            'chat_state_error' => "❌ خطا در بازیابی مکالمه. دوباره شروع کنید.",
            'chat_history_empty' => "📋 تاریخچه گفتگوهای شما خالی است.\nاز منوی اصلی «💬 گفتگوی هوش» را انتخاب کنید.",
            'chat_history_title' => "📋 تاریخچه گفتگوهای شما (صفحه {page} از {total}):\n\n",
            'chat_file_format_error' => "❌ فرمت «{ext}» توسط این مدل پشتیبانی نمی‌شود.\n📁 فرمت‌های مجاز: {formats}\nلطفاً فایل با فرمت مجاز ارسال کنید.",
            'chat_photo_format_error' => "❌ این مدل از تصاویر پشتیبانی نمی‌کند.\n📁 فرمت‌های مجاز: {formats}\nلطفاً فایل با فرمت مجاز ارسال کنید.",
            'chat_photo_error' => "⚠️ خطا در دریافت تصویر.",
            'chat_download_error' => "⚠️ خطا در دریافت فایل.",
            'chat_file_caption' => "لطفاً این فایل را پردازش کن: {filename}",
            'chat_photo_caption' => "توضیحی برای این تصویر بنویسید.",
            'chat_resume_header' => "📋 ادامه مکالمه قبلی:\n━━━━━━━━━━━━━━\n",
            'chat_resume_footer' => "━━━━━━━━━━━━━━\n✏️ پیام خود را بنویسید.",
            'chat_cost_summary' => "\n💎 هزینه: {input_cost} ورودی + {output_cost} خروجی = {total_cost} اعتبار",

            // ─── AccountHandler ───
            'account_title' => "👤 **حساب کاربری شما**\n\n"
                . "{name_line}"
                . "📱 شماره: {phone}\n"
                . "💎 اعتبار: {credits} کردیت\n"
                . "🆔 شناسه: {user_id}\n"
                . "{memory_section}"
                . "\n🔹 برای افزایش اعتبار از گزینه «💳 خرید اعتبار» استفاده کنید.",
            'account_not_found' => "⚠️ حساب کاربری یافت نشد. لطفاً /start را بزنید.",
            'account_error' => "⚠️ متأسفانه مشکلی پیش آمد.",
            'account_memory_header' => "\n🧠 **حافظه**: {count} مورد ذخیره شده\n"
                . "━━━━━━━━━━━━━━━━━━\n"
                . "📌 **چگونه از حافظه استفاده کنم؟**\n\n"
                . "**➕ اضافه کردن اطلاعات:**\n"
                . "در حین گفتگو، این جمله‌ها را به ربات بگویید:\n"
                . "«یادت باشه اسم من علی است»\n"
                . "«به خاطر بسپار من برنامه‌نویس هستم»\n"
                . "«ذخیره کن رنگ مورد علاقه‌ام آبی است»\n"
                . "«فراموش نکن تولد من ۱۵ فروردین است»\n\n"
                . "🤖 ربات همچنین به طور خودکار اطلاعات مهم\n"
                . "(نام، سن، شغل، علایق و ...) را از\n"
                . "گفتگوهای شما استخراج و ذخیره می‌کند.\n\n"
                . "**👁️ مشاهده حافظه:**\n"
                . "دکمه «🧠 حافظه من» را بزنید\n\n"
                . "**🗑️ پاک کردن حافظه:**\n"
                . "دکمه «🗑️ پاک کردن حافظه» را بزنید\n\n"
                . "**💡 نکته:** حافظه در چت‌های بعدی به\n"
                . "هوش مصنوعی گفته می‌شود تا پاسخ‌های\n"
                . "شخصی‌سازی‌شده دریافت کنید.\n"
                . "━━━━━━━━━━━━━━━━━━\n",

            // ─── BuyCreditHandler ───
            'plans_load_error' => "⚠️ خطا در پایگاه داده پلن‌ها. لطفاً به پشتیبانی اطلاع دهید.",
            'no_active_plans' => "⚠️ هیچ پلن فعالی یافت نشد.",
            'plans_title' => "💰 **لطفاً یکی از پلن‌های زیر را انتخاب کنید:**\n\n",
            'invalid_plan' => "⚠️ پلن نامعتبر است.",
            'plan_not_found' => "⚠️ پلن مورد نظر یافت نشد.",
            'user_not_found' => "⚠️ کاربر یافت نشد.",
            'payment_method_selection' => "💳 لطفاً روش پرداخت را انتخاب کنید:",
            'no_payment_method' => "⚠️ هیچ روش پرداختی فعال نیست. لطفاً با پشتیبانی تماس بگیرید.",
            'zibal_connecting' => "⏳ در حال اتصال به درگاه زیبال...",
            'zibal_connection_error' => "⚠️ متأسفانه مشکلی در اتصال به درگاه زیبال پیش آمد.",
            'zibal_payment_message' => "💳 **پرداخت با زیبال - پلن: {plan_name}**\n\n"
                . "💰 مبلغ: {amount} تومان\n"
                . "💎 اعتبار: {credits} کردیت\n\n"
                . "🔗 لینک پرداخت:\n{payment_url}\n\n"
                . "⏳ پس از پرداخت، به صورت خودکار اعتبار به حساب شما اضافه می‌شود.",
            'zibal_general_error' => "⚠️ متأسفانه مشکلی پیش آمد. لطفاً دوباره تلاش کنید.",
            'bale_invoice_error' => "⚠️ خطا در ارسال صورتحساب: {error}",

            // ─── BaseHandler ───
            'membership_required' => "🔒 برای استفاده از ربات باید در کانال‌های زیر عضو شوید:\n\n",
            'membership_check_prompt' => "\n✅ پس از عضویت در تمام کانال‌ها، دکمه زیر را بزنید تا مجدداً بررسی شود.",
            'membership_check_button' => "✅ عضو شدم، بررسی کن",

            // ─── VideoHandler ───
            'video_no_active_models' => "❌ در حال حاضر هیچ مدل ویدئویی فعالی یافت نشد.",
            'video_model_selection_title' => "🎯 لطفاً مدل ویدئوساز مورد نظر خود را انتخاب کنید:\n\n",
            'video_model_list_error' => "⚠️ خطا در دریافت لیست مدل‌ها.",
            'video_invalid_model' => "⚠️ مدل انتخاب شده معتبر نیست.",
            'video_model_selected_prompt' => "🎬 مدل «{model_name}» انتخاب شد.\n\nلطفاً متن ویدئوی مورد نظر خود را بنویسید:",
            'video_invalid_prompt' => "⚠️ لطفاً یک متن معتبر وارد کنید.",
            'video_model_not_found' => "❌ مدل یافت نشد. لطفاً دوباره انتخاب کنید.",
            'video_insufficient_credit' => "❌ اعتبار شما کافی نیست (نیاز به {cost} اعتبار).",
            'video_credit_deduct_error' => "⚠️ خطا در کسر اعتبار. لطفاً با پشتیبانی تماس بگیرید.",
            'video_generating' => "⏳ در حال ساخت ویدئو توسط «{model_name}»... لطفاً چند لحظه صبر کنید.",
            'video_error' => "⚠️ خطا در تولید ویدئو: {error}",
            'video_complete' => "✨ عملیات با موفقیت پایان یافت.",
            'video_caption' => "✅ خروجی هوش مصنوعی\n💎 هزینه کسر شده: {cost} اعتبار",
            'video_fallback_menu' => "🤖 لطفاً از منوی زیر یکی از گزینه‌ها را انتخاب کنید:",

            // ─── MemoryCommandHandler ───
            'memory_show_empty' => "🧠 حافظه شما خالی است.\n\nبرای ذخیره اطلاعات، در حین گفتگو جمله‌هایی مثل:\n«یادت باشه اسم من علی است»\n«به خاطر بسپار من برنامه‌نویس هستم»\nرا بگویید.",
            'memory_show_list' => "🧠 **حافظه شما** ({count} مورد):\n\n{memories}",
            'memory_add_prompt' => "📝 لطفاً متنی که می‌خواهید به خاطر بسپارم را بنویسید:\n\nمثال: «اسم من علی است»",
            'memory_add_success' => "✅ اطلاعات با موفقیت ذخیره شد.",
            'memory_add_cancel' => "❌ عملیات ذخیره لغو شد.",
            'memory_clear_confirm' => "⚠️ آیا مطمئن هستید که می‌خواهید تمام حافظه خود را پاک کنید؟",
            'memory_clear_done' => "🗑️ تمام اطلاعات حافظه شما پاک شد.",
            'memory_clear_cancelled' => "✅ عملیات پاک‌سازی لغو شد.",
            'memory_toggle_disabled' => "🚫 حافظه برای شما غیرفعال شد.",
            'memory_toggle_enabled' => "✅ حافظه برای شما فعال شد.",
            'memory_error' => "⚠️ خطا در عملیات حافظه.",
        ];
    }
}