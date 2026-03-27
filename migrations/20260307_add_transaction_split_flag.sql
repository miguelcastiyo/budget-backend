SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'transactions'
    AND COLUMN_NAME = 'is_split'
);

SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE transactions ADD COLUMN is_split TINYINT(1) NOT NULL DEFAULT 0 AFTER card_id',
  'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'transactions'
    AND INDEX_NAME = 'idx_transactions_user_split'
);

SET @sql := IF(
  @idx_exists = 0,
  'ALTER TABLE transactions ADD INDEX idx_transactions_user_split (user_id, is_split)',
  'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
