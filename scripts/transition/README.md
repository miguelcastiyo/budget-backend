# Transition-only backend tooling

These commands are not part of the supported encrypted financial request path. They exist for schema migration, privacy-safe evidence, parity, and deployment validation.

Plaintext financial HTTP routes are retired. The transition namespace is the
only supported home for remaining server-readable migration or repair work.

## Operator-only commands

- scripts/migrate.php — schema and migration status/application.
- scripts/preflight_phase4_retirement.php — aggregate, read-only retirement preflight.
- scripts/transition/prune_users.php — environment-bound, dry-run-first account pruning before schema retirement.
- scripts/diagnose_privacy_state.php — aggregate privacy-state evidence.
- scripts/generate_privacy_parity_fixtures.php and scripts/check_privacy_parity_fixtures.php — synthetic parity fixtures.
- scripts/report_privacy_state_counts.php — aggregate privacy-state report.
- scripts/validate_privacy_parity_coverage.php — parity evidence checks.

These commands must be run only against an explicitly selected environment. Parity and lifecycle tests already require dedicated test-database markers. The production diagnostic additionally requires PRIVACY_EVIDENCE_CONFIRM=1 and emits aggregate values only.

## Retained operator dependency inventory

The retained operator surface is limited to schema migration, aggregate
privacy evidence, encrypted-record substrate checks, deterministic client
parity fixtures, and the explicitly approved Phase 4A account-pruning
command. No command reads or writes plaintext financial tables, migration
staging, or cleanup tables. `/me/privacy` exposes account state only.

Phase 4A accepts only `production` with preserved user `1` or `local` with
preserved user `3`. It defaults to aggregate dry-run output and requires
`--confirm-delete` for mutation. It acquires an operator lock, validates the
preserved encrypted user and Vault state, deletes only rows attributable to
other users, verifies the preserved account, and rolls back on failure.

## Boundary rules

- Normal controllers and encrypted financial services must not import scripts.
- No transition command may be registered as a normal user route.
- Operator commands must document the tables/services they read, whether they
  read plaintext, expected removal condition, and rollback behavior before
  production execution.
- Supported encrypted accounts must not enter migration, cutover, cleanup, or legacy rollback flows through normal financial screens.
- Applied migration files and historical evidence remain in source control.
- Any future repair operation requires an owner, a restricted runbook, and an
  explicit schema-boundary review before it is added.

The files remain under the existing scripts path in this phase to preserve CI and operator entry points. This directory is the explicit transition namespace and documentation boundary; physical relocation is a later isolated change.
