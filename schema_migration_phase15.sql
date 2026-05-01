-- ================================================================
-- PHASE 15: supported_formats + decimal credits + sort_order + default model + help
-- ================================================================

-- 1. Add supported_formats column to ai_text_models
ALTER TABLE ai_text_models
  ADD COLUMN supported_formats TEXT DEFAULT NULL AFTER free_model;

-- 2. Add sort_order for custom ordering
ALTER TABLE ai_text_models
  ADD COLUMN sort_order INT DEFAULT 0 AFTER supported_formats;

-- 3. Change users.credits from INT to DECIMAL for sub-credit precision
ALTER TABLE users
  MODIFY COLUMN credits DECIMAL(12,6) NOT NULL DEFAULT 0;

-- 4. Change credit_ledger.amount from INT to DECIMAL
ALTER TABLE credit_ledger
  MODIFY COLUMN amount DECIMAL(12,6) NOT NULL DEFAULT 0;

-- 4b. Change chat_conversations.total_cost_credits from INT to DECIMAL
ALTER TABLE chat_conversations
  MODIFY COLUMN total_cost_credits DECIMAL(12,6) NOT NULL DEFAULT 0;

-- 4c. Change chat_messages cost columns from INT to DECIMAL
ALTER TABLE chat_messages
  MODIFY COLUMN cost_input_credits DECIMAL(12,6) NOT NULL DEFAULT 0;
ALTER TABLE chat_messages
  MODIFY COLUMN cost_output_credits DECIMAL(12,6) NOT NULL DEFAULT 0;

-- 5. Add default_text_model setting
INSERT IGNORE INTO settings (key_name, value) VALUES ('default_text_model', '');

-- 6. Add help_text and help_image settings
INSERT IGNORE INTO settings (key_name, value) VALUES ('help_text', '🤖 **راهنمای ربات**\n\n🎨 **ساخت تصویر**: با استفاده از هوش مصنوعی تصویر بسازید.\n🖼 **ویرایش عکس**: عکس خود را آپلود کرده و با توضیحات ویرایش کنید.\n💬 **چت با هوش مصنوعی**: با مدل‌های مختلف گفتگو کنید.\n👤 **حساب کاربری**: موجودی و تاریخچه خود را مشاهده کنید.\n💳 **خرید اعتبار**: اعتبار خود را افزایش دهید.\n\n📞 پشتیبانی: @mobix_tube');
INSERT IGNORE INTO settings (key_name, value) VALUES ('help_image', '');

-- 7. Update ai_text_models: set default supported_formats
UPDATE ai_text_models SET supported_formats = 'txt,doc,pdf,jpg,jpeg,png,gif,webp' WHERE supported_formats IS NULL;