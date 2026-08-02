-- Phase 4: encrypted-only schema retirement.
-- This migration is intentionally forward-only. Historical migrations remain
-- unchanged, and the drops are limited to tables with no current runtime or
-- retained operator consumer.

SET @phase4_legacy_accounts = (
  SELECT COUNT(*)
  FROM users
  WHERE financial_privacy_state NOT IN ('vault_setup_required', 'encrypted')
);

SET @phase4_guard = IF(
  @phase4_legacy_accounts = 0,
  'SELECT 1',
  'SELECT * FROM phase4_schema_guard_failure'
);
PREPARE phase4_guard_statement FROM @phase4_guard;
EXECUTE phase4_guard_statement;
DEALLOCATE PREPARE phase4_guard_statement;

-- Remove transition staging and cleanup data first.
DROP TABLE IF EXISTS encrypted_migration_records;
DROP TABLE IF EXISTS encrypted_migration_manifests;
DROP TABLE IF EXISTS financial_privacy_cleanup_jobs;
DROP TABLE IF EXISTS financial_privacy_migrations;

-- Remove dependent plaintext financial tables before their parent tables.
DROP TABLE IF EXISTS fund_entries;
DROP TABLE IF EXISTS recurring_expense_occurrences;
DROP TABLE IF EXISTS monthly_closeout_allocations;
DROP TABLE IF EXISTS transactions;
DROP TABLE IF EXISTS csv_import_runs;
DROP TABLE IF EXISTS csv_export_runs;
DROP TABLE IF EXISTS monthly_closeouts;
DROP TABLE IF EXISTS monthly_savings_allocations;
DROP TABLE IF EXISTS recurring_expenses;
DROP TABLE IF EXISTS budget_settings_versions;
DROP TABLE IF EXISTS budget_settings;
DROP TABLE IF EXISTS funds;
DROP TABLE IF EXISTS contexts;
DROP TABLE IF EXISTS cards;
DROP TABLE IF EXISTS tags;

-- Encrypted-only account states are the only supported current values.
SET @phase4_drop_state_check = (
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
PREPARE phase4_drop_state_check_statement FROM @phase4_drop_state_check;
EXECUTE phase4_drop_state_check_statement;
DEALLOCATE PREPARE phase4_drop_state_check_statement;

ALTER TABLE users
  MODIFY COLUMN financial_privacy_state VARCHAR(32) NOT NULL DEFAULT 'vault_setup_required',
  ADD CONSTRAINT chk_users_financial_privacy_state CHECK (
    financial_privacy_state IN ('vault_setup_required', 'encrypted')
  );
