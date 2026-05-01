-- Phase 16: Video Model Capabilities
-- Adds full capability columns to ai_video_models

-- 1. Add capability columns
ALTER TABLE ai_video_models 
  ADD COLUMN display_name VARCHAR(200) DEFAULT NULL AFTER name,
  ADD COLUMN description TEXT DEFAULT NULL AFTER display_name,
  ADD COLUMN supported_resolutions TEXT DEFAULT NULL AFTER cost_per_video,
  ADD COLUMN supported_sizes TEXT DEFAULT NULL AFTER supported_resolutions,
  ADD COLUMN supported_aspect_ratios TEXT DEFAULT NULL AFTER supported_sizes,
  ADD COLUMN supported_durations TEXT DEFAULT NULL AFTER supported_aspect_ratios,
  ADD COLUMN pricing_json JSON DEFAULT NULL AFTER supported_durations,
  ADD COLUMN allow_first_frame TINYINT(1) DEFAULT 0 AFTER pricing_json,
  ADD COLUMN allow_last_frame TINYINT(1) DEFAULT 0 AFTER allow_first_frame,
  ADD COLUMN allow_input_references TINYINT(1) DEFAULT 0 AFTER allow_last_frame,
  ADD COLUMN allow_generate_audio TINYINT(1) DEFAULT 1 AFTER allow_input_references,
  ADD COLUMN allow_img2video TINYINT(1) DEFAULT 0 AFTER allow_generate_audio;

-- 2. Update existing models with default capabilities
UPDATE ai_video_models SET 
  display_name = name WHERE display_name IS NULL;
UPDATE ai_video_models SET 
  supported_resolutions = '480p,720p,1080p' WHERE supported_resolutions IS NULL;
UPDATE ai_video_models SET 
  supported_sizes = '854x480,1280x720,1920x1080' WHERE supported_sizes IS NULL;
UPDATE ai_video_models SET 
  supported_aspect_ratios = '16:9,9:16,1:1' WHERE supported_aspect_ratios IS NULL;
UPDATE ai_video_models SET 
  supported_durations = '5,10,15' WHERE supported_durations IS NULL;
UPDATE ai_video_models SET 
  pricing_json = '{}' WHERE pricing_json IS NULL;

-- 3. Register a default video model if none exists
INSERT IGNORE INTO ai_video_models (name, display_name, provider, cost_per_video, supported_resolutions, supported_sizes, supported_aspect_ratios, supported_durations, allow_first_frame, allow_last_frame, allow_input_references, allow_generate_audio, allow_img2video, is_active)
VALUES ('google/veo-3.1', 'Google Veo 3.1', 'openrouter', 5,
  '480p,720p,1080p',
  '854x480,1280x720,1920x1080',
  '16:9,9:16,1:1',
  '5,10,15',
  1, 1, 1, 1, 0,
  1);