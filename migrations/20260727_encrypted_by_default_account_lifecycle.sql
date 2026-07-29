UPDATE users
SET financial_privacy_state = 'legacy_plaintext', financial_revision = 0
WHERE financial_privacy_state IS NULL OR financial_revision IS NULL;

ALTER TABLE users
  DROP CONSTRAINT IF EXISTS chk_users_financial_privacy_state,
  ALTER COLUMN financial_privacy_state SET DEFAULT 'vault_setup_required',
  ADD CONSTRAINT chk_users_financial_privacy_state CHECK (
    financial_privacy_state IN ('vault_setup_required', 'legacy_plaintext', 'migration_in_progress', 'migration_failed', 'encrypted')
  );
