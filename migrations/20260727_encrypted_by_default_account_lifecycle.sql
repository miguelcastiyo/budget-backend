UPDATE users
SET financial_privacy_state = 'legacy_plaintext', financial_revision = 0
WHERE financial_privacy_state IS NULL OR financial_revision IS NULL;

SET @drop_financial_privacy_state_check = (
  SELECT IF(
    COUNT(*) > 0,
    'ALTER TABLE users DROP CHECK chk_users_financial_privacy_state',
    'SELECT 1'
  )
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND CONSTRAINT_NAME = 'chk_users_financial_privacy_state'
    AND CONSTRAINT_TYPE = 'CHECK'
);
PREPARE drop_financial_privacy_state_check FROM @drop_financial_privacy_state_check;
EXECUTE drop_financial_privacy_state_check;
DEALLOCATE PREPARE drop_financial_privacy_state_check;

ALTER TABLE users
  MODIFY COLUMN financial_privacy_state VARCHAR(32) NOT NULL DEFAULT 'vault_setup_required',
  ADD CONSTRAINT chk_users_financial_privacy_state CHECK (
    financial_privacy_state IN ('vault_setup_required', 'legacy_plaintext', 'migration_in_progress', 'migration_failed', 'encrypted')
  );
