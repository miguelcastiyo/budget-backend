SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'avatar_url'
);

SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE users ADD COLUMN avatar_url VARCHAR(512) NULL AFTER display_name',
  'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
