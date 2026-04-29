-- Add model_type column to ai_models (if not exists)
ALTER TABLE ai_models ADD COLUMN IF NOT EXISTS model_type VARCHAR(30) DEFAULT 'image_generation' AFTER provider;

-- Migration: existing chat models need to be updated manually via admin panel
-- This is just informational