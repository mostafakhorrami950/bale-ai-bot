-- ============================================================
-- Broadcast v2: Add filter columns + user delete metadata
-- ============================================================

-- 1. Add filter columns to broadcast_jobs
ALTER TABLE broadcast_jobs
  ADD COLUMN IF NOT EXISTS filter_type VARCHAR(32) DEFAULT 'all' 
    COMMENT 'all|registered|unregistered|deep_link_payload|deep_link_registered|deep_link_unregistered',
  ADD COLUMN IF NOT EXISTS filter_value VARCHAR(128) DEFAULT NULL 
    COMMENT 'deep_link payload value if filter_type uses deep_link';

-- 2. Index for faster user delete cascade reference
ALTER TABLE broadcast_log
  ADD INDEX IF NOT EXISTS idx_user_delete (user_id);