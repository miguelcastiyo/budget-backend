ALTER TABLE users
  ADD COLUMN IF NOT EXISTS financial_privacy_state VARCHAR(32) NULL,
  ADD COLUMN IF NOT EXISTS financial_revision BIGINT UNSIGNED NULL;

UPDATE users
SET financial_privacy_state = 'legacy_plaintext', financial_revision = 0
WHERE financial_privacy_state IS NULL OR financial_revision IS NULL;

ALTER TABLE users
  MODIFY COLUMN financial_privacy_state VARCHAR(32) NOT NULL DEFAULT 'legacy_plaintext',
  MODIFY COLUMN financial_revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
  ALTER COLUMN financial_privacy_state SET DEFAULT 'vault_setup_required';

SET @privacy_state_constraint_exists := (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND CONSTRAINT_NAME = 'chk_users_financial_privacy_state'
);

SET @sql := IF(
  @privacy_state_constraint_exists = 0,
  'ALTER TABLE users ADD CONSTRAINT chk_users_financial_privacy_state CHECK (financial_privacy_state IN (''vault_setup_required'', ''legacy_plaintext'', ''migration_in_progress'', ''migration_failed'', ''encrypted''))',
  'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS financial_privacy_migrations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  migration_id VARCHAR(64) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(24) NOT NULL,
  source_financial_revision BIGINT UNSIGNED NOT NULL,
  encrypted_schema_version VARCHAR(32) NULL,
  failure_code VARCHAR(80) NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  failed_at DATETIME NULL,
  completed_at DATETIME NULL,
  cancelled_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  active_user_slot TINYINT GENERATED ALWAYS AS (CASE WHEN status = 'active' THEN 1 ELSE NULL END) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_financial_privacy_migrations_id (migration_id),
  UNIQUE KEY uq_financial_privacy_migrations_active_user (user_id, active_user_slot),
  KEY idx_financial_privacy_migrations_user_created (user_id, created_at),
  CONSTRAINT fk_financial_privacy_migrations_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT chk_financial_privacy_migrations_status CHECK (status IN ('active', 'failed', 'completed', 'cancelled', 'expired')),
  CONSTRAINT chk_financial_privacy_migrations_failure CHECK ((status = 'failed' AND failure_code IS NOT NULL) OR status <> 'failed')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS financial_privacy_cleanup_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cleanup_job_id VARCHAR(64) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  migration_id VARCHAR(64) NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'pending',
  attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  lease_expires_at DATETIME NULL,
  completed_at DATETIME NULL,
  last_failure_code VARCHAR(80) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_financial_privacy_cleanup_jobs_id (cleanup_job_id),
  UNIQUE KEY uq_financial_privacy_cleanup_jobs_migration (migration_id),
  KEY idx_financial_privacy_cleanup_jobs_claim (status, next_attempt_at),
  CONSTRAINT fk_financial_privacy_cleanup_jobs_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_financial_privacy_cleanup_jobs_migration FOREIGN KEY (migration_id) REFERENCES financial_privacy_migrations (migration_id),
  CONSTRAINT chk_financial_privacy_cleanup_jobs_status CHECK (status IN ('pending', 'running', 'retry_pending', 'completed', 'failed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
