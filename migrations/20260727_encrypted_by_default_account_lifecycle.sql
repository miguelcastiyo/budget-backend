ALTER TABLE users
  DROP CONSTRAINT chk_users_financial_privacy_state,
  ALTER COLUMN financial_privacy_state SET DEFAULT 'vault_setup_required',
  ADD CONSTRAINT chk_users_financial_privacy_state CHECK (
    financial_privacy_state IN ('vault_setup_required', 'legacy_plaintext', 'migration_in_progress', 'migration_failed', 'encrypted')
  );
