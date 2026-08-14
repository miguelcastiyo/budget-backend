# Auth identity architecture: Phase 4

`users` is the permanent Budget account/profile record. Authentication is
solely represented by `auth_identities`, `password_credentials`, and
`user_sessions`; the former singular auth columns on `users` are retired.

`GET /me/auth-methods` is the supported, read-only provider-neutral method
inventory. Existing sign-in, invite acceptance, password reset, email-change,
and password-to-Google conversion flows use the authoritative auth tables.
Multi-method mutations and Apple support remain intentionally unavailable.

## Deployment boundary

Phase 4A removed legacy fallback/repair and dual-writing while the old columns
were still present. Phase 4B is the destructive forward migration
`20260815_retire_legacy_auth_representation.sql`.

Before Phase 4B: run authoritative verification, take the normal production
snapshot, migrate, then rerun verification. After Phase 4B, do not deploy a
pre-retirement backend against the database; recovery is a forward fix or a
matching pre-migration snapshot restore.

```bash
php scripts/verify_auth_identity_retirement.php --user-id=YOUR_GOOGLE_USER_ID
php scripts/migrate.php
php scripts/verify_auth_identity_retirement.php --user-id=YOUR_GOOGLE_USER_ID
```

The production deployment script performs that verifier automatically before
and after the Phase 4 retirement migration when it is pending. Confirm the
normal production snapshot immediately before deploying that destructive
release; the deployment script cannot create or attest to an infrastructure
snapshot itself.

The verifier reports only counts and internal IDs/categories. It never emits
password hashes, provider subjects, tokens, sessions secrets, Vault keys, or
encrypted financial content. It blocks on active accounts with zero or multiple
methods, orphaned auth/session rows, blank password hashes, and duplicate
identity mappings.

The known Google account must remain active with exactly one nonblank Google
identity, no password credential under the current singular product behavior,
and unchanged session ownership on the same `users.id`.

## Residual legacy references

After Phase 4B, retired-column names may appear only in historical migrations
and transition documentation. Runtime source, current schema, fixtures,
verifiers, and current API/frontend contracts must not depend on them.
