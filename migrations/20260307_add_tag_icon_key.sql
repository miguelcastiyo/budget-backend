SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tags'
    AND COLUMN_NAME = 'icon_key'
);

SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE tags ADD COLUMN icon_key VARCHAR(64) NULL AFTER name',
  'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
