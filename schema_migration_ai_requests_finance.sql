-- ============================================================
-- PHASE 18: Add financial tracking columns to ai_requests
-- ============================================================

-- Add columns for tracking costs on image/edit requests
ALTER TABLE ai_requests
  ADD COLUMN model_name VARCHAR(200) DEFAULT NULL AFTER model_id,
  ADD COLUMN actual_cost_usd DECIMAL(16,8) DEFAULT 0 AFTER model_name,
  ADD COLUMN input_chars INT DEFAULT 0 AFTER actual_cost_usd,
  ADD COLUMN output_chars INT DEFAULT 0 AFTER input_chars,
  ADD COLUMN cost_charged DECIMAL(12,6) DEFAULT 0 AFTER output_chars;

-- Add index for faster queries
ALTER TABLE ai_requests
  ADD INDEX idx_model_name (model_name),
  ADD INDEX idx_created_at (created_at);