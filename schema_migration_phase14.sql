-- ================================================================
-- PHASE 14: Separate model admin pages + display_name/description
-- ================================================================

-- 1. Add display_name and description columns to all model tables
ALTER TABLE ai_text_models
  ADD COLUMN display_name VARCHAR(200) DEFAULT NULL AFTER name,
  ADD COLUMN description TEXT DEFAULT NULL AFTER display_name;

ALTER TABLE ai_image_models
  ADD COLUMN display_name VARCHAR(200) DEFAULT NULL AFTER name,
  ADD COLUMN description TEXT DEFAULT NULL AFTER display_name,
  ADD COLUMN size VARCHAR(20) DEFAULT 'auto' AFTER cost_per_image,
  ADD COLUMN aspect_ratio VARCHAR(10) DEFAULT 'auto' AFTER size;

ALTER TABLE ai_edit_models
  ADD COLUMN display_name VARCHAR(200) DEFAULT NULL AFTER name,
  ADD COLUMN description TEXT DEFAULT NULL AFTER display_name,
  ADD COLUMN size VARCHAR(20) DEFAULT 'auto' AFTER cost_per_edit,
  ADD COLUMN aspect_ratio VARCHAR(10) DEFAULT 'auto' AFTER size;

ALTER TABLE ai_video_models
  ADD COLUMN display_name VARCHAR(200) DEFAULT NULL AFTER name,
  ADD COLUMN description TEXT DEFAULT NULL AFTER display_name;

-- 2. Copy existing names to display_name if null
UPDATE ai_text_models SET display_name = name WHERE display_name IS NULL;
UPDATE ai_image_models SET display_name = name WHERE display_name IS NULL;
UPDATE ai_edit_models SET display_name = name WHERE display_name IS NULL;
UPDATE ai_video_models SET display_name = name WHERE display_name IS NULL;