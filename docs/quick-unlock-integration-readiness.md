# Quick Unlock integration readiness

Status: `implementation_complete_manual_acceptance_pending`

This record closes the code-level Piece 4 integration pass. Quick Unlock
remains an optional client-side wrapper around the existing Vault key. It does
not add a Vault key, financial re-encryption, an authentication provider, or a
second Vault runtime.

## Verified in the repository

- WebAuthn registration and assertion are session-only and device-scoped.
- Challenge consumption is atomic, one-time, purpose-bound, user-bound, and
  session-bound; expiry is stored and compared in UTC.
- Production WebAuthn configuration fails closed unless RP ID and exact HTTPS
  origins are configured. Wildcards, request-header-derived trust, localhost
  production origins, and invalid origins are rejected.
- The server stores only credential metadata, public credential records, PRF
  input, and an opaque wrapped Vault key. It never receives the PRF output,
  Quick Unlock KEK, passphrase, Recovery Code, Vault key, or financial
  plaintext.
- Device removal is centralized and transactional: all sessions and active or
  pending Quick Unlock credentials for the opaque device ID are revoked
  together. The current-device client clears its private runtime and local
  device identity after removal.
- Sign-out does not remove device authorization. Disabling Quick Unlock does
  not revoke the Budget device/session. Passphrase and Recovery Code remain
  the durable fallback paths.
- The frontend Quick Unlock module has explicit WebAuthn request/response
  allow-lists and does not persist cryptographic material.
- `schema.sql`, migrations, OpenAPI, frontend API types, and human docs use the
  device-scoped contract.

## Automated evidence

Run from the backend repository:

```bash
php scripts/test_all.php
```

The suite includes the Quick Unlock contract canaries, privacy logging scan,
operational privacy canaries, and the existing backend regression suite. The
frontend suite remains:

```bash
npm test
npm run test:vault-crypto
```

The Quick Unlock browser crypto suite must be run in both Chromium and WebKit
when browser dependencies are available.

## Manual acceptance still required

This repository pass cannot prove hardware-backed behavior. Before recording
the final feature markers, execute the existing owner-account matrix on the
production-target iPhone/iOS version in both Safari and the installed Home
Screen PWA:

- enrollment, cancellation, failure fallback, cold/relocked launch, and
  passphrase/Recovery Code fallback;
- sign-out/sign-in, disable/re-enable, remote device removal, and current
  device removal;
- foreground/background, runtime eviction, PWA relaunch, temporary network
  loss, session expiry, and Log Transaction intent preservation;
- VoiceOver, keyboard/focus, Dynamic Type, reduced motion, and safe-area
  spacing.

Also perform the documented post-deployment smoke and privacy checks. Do not
record credential IDs, wrapper values, PRF material, Vault material, or
financial plaintext in evidence.

## Operational configuration

Production must set:

```text
APP_ENV=production
APP_DEBUG=false
WEBAUTHN_RP_ID=<production RP ID>
WEBAUTHN_RP_NAME=Budget
WEBAUTHN_ALLOWED_ORIGINS=https://<exact-production-origin>
WEBAUTHN_CHALLENGE_TTL_SECONDS=60
```

The allowed-origin list must contain exact HTTPS origins only. Keep Quick
Unlock enrollment opt-in. A frontend rollback may hide Quick Unlock while
passphrase and Recovery Code continue to work; do not delete Vault keys or
rotate wrappers as part of a UI rollback.

## Completion markers

The final markers are intentionally not asserted by this code-only pass:

```text
quick_unlock_foundation_ready       prerequisite from Piece 1
quick_unlock_vault_ui_ready         prerequisite from Piece 2
quick_unlock_device_lifecycle_ready prerequisite from Piece 3
quick_unlock_integration_ready      pending physical Safari/PWA evidence
quick_unlock_ready                  pending integration marker
```

Once the manual matrix and production smoke evidence pass, update this record
with the evidence date and set the final two markers. No production-account
cutover or automatic enrollment is required.
