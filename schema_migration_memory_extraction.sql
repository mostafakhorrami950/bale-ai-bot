-- ================================================================
-- PHASE: Memory extraction model + dollar rate + profit margin settings
-- ================================================================

-- 1. Add memory_extraction_model setting (separate model for memory extraction)
INSERT IGNORE INTO settings (key_name, value) VALUES ('memory_extraction_model', '');

-- 2. Add dollar_rate setting for converting USD cost to Toman
INSERT IGNORE INTO settings (key_name, value) VALUES ('dollar_rate', '231000');

-- 3. Add profit_margin_percent setting
INSERT IGNORE INTO settings (key_name, value) VALUES ('profit_margin_percent', '25');