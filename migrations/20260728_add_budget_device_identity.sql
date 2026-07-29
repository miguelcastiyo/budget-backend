ALTER TABLE user_sessions ADD COLUMN IF NOT EXISTS device_id VARCHAR(64) NULL AFTER user_id;
UPDATE user_sessions SET device_id = CONCAT('dev_', LPAD(id, 16, '0')) WHERE device_id IS NULL;
ALTER TABLE user_sessions MODIFY device_id VARCHAR(64) NOT NULL;
ALTER TABLE user_sessions ADD KEY IF NOT EXISTS idx_user_sessions_device (user_id, device_id, revoked_at);
