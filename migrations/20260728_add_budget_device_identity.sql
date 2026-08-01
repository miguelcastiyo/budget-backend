SET @add_device_id = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE user_sessions ADD COLUMN device_id VARCHAR(64) NULL AFTER user_id',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'user_sessions'
    AND COLUMN_NAME = 'device_id'
);
PREPARE add_device_id FROM @add_device_id;
EXECUTE add_device_id;
DEALLOCATE PREPARE add_device_id;

UPDATE user_sessions SET device_id = CONCAT('dev_', LPAD(id, 16, '0')) WHERE device_id IS NULL;
ALTER TABLE user_sessions MODIFY device_id VARCHAR(64) NOT NULL;

SET @add_device_index = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE user_sessions ADD KEY idx_user_sessions_device (user_id, device_id, revoked_at)',
    'SELECT 1'
  )
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'user_sessions'
    AND INDEX_NAME = 'idx_user_sessions_device'
);
PREPARE add_device_index FROM @add_device_index;
EXECUTE add_device_index;
DEALLOCATE PREPARE add_device_index;
