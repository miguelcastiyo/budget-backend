# Transition-only backend tooling

These commands are not part of the supported encrypted financial request path. They exist for migration, cutover, cleanup, repair, parity, and evidence work.

Plaintext financial HTTP routes are retired. The transition namespace is the
only supported home for remaining server-readable migration or repair work.

## Operator-only commands

- scripts/migrate.php — schema and migration status/application.
- scripts/run_privacy_cleanup.php — privacy cleanup worker.
- scripts/diagnose_privacy_state.php — aggregate privacy-state evidence.
- scripts/assert_phase5_browser_account.php — migration-browser assertion.
- scripts/assert_phase6_cutover_account.php — cutover and cleanup assertion.
- scripts/test_migration_staging.php — migration staging contract.
- scripts/test_phase6_cutover.php — cutover contract.
- scripts/test_privacy_foundations.php — privacy lifecycle foundation contract.
- scripts/reset_privacy_parity_database.php — dedicated parity database reset.
- scripts/setup_privacy_parity_database.php — dedicated parity database setup.
- scripts/generate_privacy_parity_fixtures.php and scripts/check_privacy_parity_fixtures.php — synthetic parity fixtures.
- scripts/diagnose_recurring_regression.php — recurring regression diagnostic.
- scripts/report_privacy_state_counts.php — aggregate privacy-state report.
- scripts/verify_privacy_parity_database.php and scripts/validate_privacy_parity_coverage.php — parity evidence checks.

These commands must be run only against an explicitly selected environment. Parity and lifecycle tests already require dedicated test-database markers. The production diagnostic additionally requires PRIVACY_EVIDENCE_CONFIRM=1 and emits aggregate values only.

## Boundary rules

- Normal controllers and encrypted financial services must not import scripts.
- No transition command may be registered as a normal user route.
- Operator commands must document the tables/services they read, whether they
  read plaintext, expected removal condition, and rollback behavior before
  production execution.
- Supported encrypted accounts must not enter migration, cutover, cleanup, or legacy rollback flows through normal financial screens.
- Applied migration files and historical evidence remain in source control.
- Retained repair operations require an owner and a restricted runbook.

The files remain under the existing scripts path in this phase to preserve CI and operator entry points. This directory is the explicit transition namespace and documentation boundary; physical relocation is a later isolated change.
