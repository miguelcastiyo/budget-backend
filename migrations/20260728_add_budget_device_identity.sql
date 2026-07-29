ALTER TABLE user_sessions ADD COLUMN device_id VARCHAR(64) NULL AFTER user_id;
UPDATE user_sessions SET device_id = CONCAT('dev_', LPAD(id, 16, '0')) WHERE device_id IS NULL;
ALTER TABLE user_sessions MODIFY device_id VARCHAR(64) NOT NULL;
ALTER TABLE user_sessions ADD KEY idx_user_sessions_device (user_id, device_id, revoked_at);
UPDATE vault_quick_unlock_credentials q JOIN user_sessions s ON s.session_id = q.device_id SET q.device_id = s.device_id WHERE q.user_id = s.user_id;
