-- The cleanup-jobs table is created by
-- 20260725_add_phase_1_privacy_foundations.sql, which sorts after this
-- filename. Keep this migration safe for both a fresh pending-migration run
-- and databases where the table already exists.
SET @cleanup_jobs_table_exists := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'financial_privacy_cleanup_jobs'
);

SET @cleanup_lease_column_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'financial_privacy_cleanup_jobs'
    AND COLUMN_NAME = 'lease_expires_at'
);

SET @sql := IF(
  @cleanup_jobs_table_exists = 1 AND @cleanup_lease_column_exists = 0,
  'ALTER TABLE financial_privacy_cleanup_jobs ADD COLUMN lease_expires_at DATETIME NULL AFTER started_at',
  'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
