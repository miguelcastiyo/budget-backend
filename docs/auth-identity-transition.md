# Auth identity transition: Piece 1

Piece 1 is an additive storage migration. It does not change any authentication
route, response shape, session secret, session owner, Vault/device owner, or
encrypted financial owner.

During this transition:

```text
users.auth_provider / users.password_hash / users.google_sub
    = current runtime authentication authority

auth_identities / password_credentials
    = exact, backfilled future authority; validation only
```

`auth_identities.provider_subject` is a binary/case-sensitive provider key.
Provider email is metadata only. Password hashes are copied exactly and no
historical last-used/password-changed timestamps are invented.

`user_sessions.last_authenticated_at` is nullable and is intentionally not
populated for pre-existing sessions in this piece. Existing sessions therefore
keep their current validity and ownership semantics.

## Operator verification

Apply the normal migration, then run:

```bash
php scripts/verify_auth_identity_backfill.php
```

The command emits counts and failure counts only; it never outputs password
hashes, session secrets, Google subjects, Vault data, or encrypted payloads.

For the designated production Google account, supply its existing user ID via
the deployment/preflight mechanism:

```bash
php scripts/verify_auth_identity_backfill.php --user-id=123
```

This proves one exact Google mapping on the same user ID and reports only
per-table ownership row counts for all `users.user_id` foreign-key tables.
Compare those counts with the pre-migration record for the same user ID. It is
a continuity check only; the command never rewrites ownership data.

Do not proceed to Piece 2 when the command returns non-zero.

## Piece 2 migration map

These are the direct current dependencies on legacy auth fields. They are
intentionally unchanged in Piece 1 and identify the future authority switch
points.

| Area | File and functions | Legacy dependency |
| --- | --- | --- |
| Session context | `src/Auth/AuthService.php`, `authenticateSessionToken` | loads `google_sub` and `auth_provider` into `AuthContext` |
| Invite acceptance | `src/Auth/AuthApplicationService.php`, `acceptInvitationPassword`, `acceptInvitationGoogle` | creates legacy password/Google user records |
| Password sign-in | `src/Auth/AuthApplicationService.php`, `signInPassword` | looks up `users.auth_provider` and `users.password_hash` |
| Google sign-in | `src/Auth/AuthApplicationService.php`, `signInGoogle` | looks up `users.auth_provider` and `users.google_sub`; conflict lookup uses `auth_provider` |
| Reauthentication | `src/Auth/AuthApplicationService.php`, `reauthenticateCurrentSession` | provider selection, password hash, and Google subject comparison |
| Password reset | `src/Auth/AuthApplicationService.php`, `requestPasswordReset`, `confirmPasswordReset` | password-only checks and legacy hash update |
| Auth responses | `src/Auth/AuthApplicationService.php`, `buildAuthResponse` | serializes `auth_provider` |
| Email change / conversion | `src/Controllers/ProfileController.php`, `requestEmailChange`, `verifyEmailChange`, `convertAccountToGoogle`, `fetchProfile`, `profileFromAuth` | password-only authorization, Google-sub lookup/conversion, response serialization |
| Google verifier contract | `src/Auth/GoogleTokenVerifier.php`, `verifyIdToken`; `scripts/smoke_google_verifier.php` | uses the `google_sub` claim name before storage |
| Seed and test fixtures | `scripts/seed_owner.php`, `scripts/seed_encrypted_default_browser_account.php`, `scripts/test_session_reauthentication.php`, `scripts/test_vault_foundations.php`, `scripts/test_encrypted_by_default_lifecycle.php`, `scripts/test_encrypted_record_substrate.php`, `scripts/test_encrypted_record_batch.php` | create legacy password users |
| API/docs | `openapi.yaml`, `api_v1.md`, `README.md` | retain public `auth_provider` semantics and auth route documentation |

## Piece 2 runtime authority

Piece 2 changes authority without changing the public auth contract:

```text
auth_identities / password_credentials = runtime authority
users auth columns                     = synchronized rollback mirror
```

`AuthIdentityRepository` and `PasswordCredentialRepository` are the only
new-table access points. `LegacyAuthCompatibilityService` is explicitly
temporary: it can repair a missing new row only when valid legacy state is
unambiguous; it never chooses between conflicting representations. It must be
deleted with the legacy columns in the later retirement piece.

Google/password sign-in, invite account creation, password resets, conversion
to Google, password-only email-change eligibility, and reauthentication now
read the new authority. Auth response serialization continues reading
`users.auth_provider` as an API compatibility field. Invite creation, resets,
and conversion write both representations atomically.

Successful new sessions receive `last_authenticated_at`; ordinary request
traffic never changes that value. New identity/credential rows record
`last_used_at` on successful sign-in.

The reconciliation migration runs immediately before the switch. The existing
verifier remains the production drift report. Runtime exceptional paths emit
the privacy-safe structured events `auth_legacy_fallback_used` and
`auth_identity_state_drift`; they include only permitted internal IDs/action
categories, never provider subjects, hashes, tokens, or passwords.

Remaining legacy references are intentional category B compatibility writes,
category C response/legacy UX serialization, or category D migration/test
diagnostics. No category A runtime lookup should use a legacy hash or Google
subject once the new authority row exists. Apple and multi-provider product
behavior remain out of scope.
