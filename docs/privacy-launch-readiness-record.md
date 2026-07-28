# Privacy launch readiness record

Updated 2026-07-28. This is an evidence record, not a production-release approval.

## Local verification

- Backend core, privacy infrastructure, parity fixtures, and coverage checks pass.
- Phase 0D parity coverage is 24/24 groups, 25/25 scenarios, and 80/80 high-priority invariants.
- Frontend typecheck, helper tests, lint, and production build pass.
- Vault crypto tests pass in Chromium and WebKit: 4/4.
- Migration/cutover browser matrix passes after clean rerun: 18/18 across Chromium and WebKit.
- The browser matrix exercises setup, migration staging, refresh relock/resume, cutover, cleanup preservation, encrypted Transactions/Funds mutation, conflict rollback, and idempotent retry against Docker-backed parity MariaDB.

## Implemented safeguards

- New Vault passphrases reject short, repeated, whitespace-only, and common-pattern values while preserving compatibility for existing passphrases.
- Setup-required and migration lifecycle states remain blocked from financial reads/writes until the encrypted authority is available.
- The development migration-validation route remains available only for validation; production financial routes remain behind the encrypted boundary.
- Operational documentation records auth recovery versus Vault recovery, device revocation limits, snapshot retention implications, cleanup ownership, and offline-guessing limits.

## Not yet verified; do not mark PASS

The following require evidence outside this local repository audit:

- real iPhone Safari and installed iOS PWA acceptance;
- cross-device sync and conflict proof on real deployed clients;
- non-production Lightsail snapshot restore drill;
- historical-copy inventory and cleanup verification;
- deployed security-header verification;
- production cleanup-worker ownership, scheduling, alerts, and failure proof;
- production preflight state-count query and final threat-model sign-off.

Until those gates are recorded, the release status must not be changed to `privacy_release_ready`.

