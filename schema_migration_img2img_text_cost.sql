-- ============================================================
-- PHASE 17: Add text cost columns to image/edit model tables
-- ============================================================

ALTER TABLE ai_image_models 
  ADD COLUMN cost_per_input_char DECIMAL(10,6) DEFAULT 0.000001 AFTER cost_per_image,
  ADD COLUMN cost_per_output_char DECIMAL(10,6) DEFAULT 0.000002 AFTER cost_per_input_char;

ALTER TABLE ai_edit_models 
  ADD COLUMN cost_per_input_char DECIMAL(10,6) DEFAULT 0.000001 AFTER cost_per_edit,
  ADD COLUMN cost_per_output_char DECIMAL(10,6) DEFAULT 0.000002 AFTER cost_per_input_char;