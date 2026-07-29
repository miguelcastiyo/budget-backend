# Quick Unlock device lifecycle

Budget uses one opaque client-generated `device_id` across the authenticated
sessions created by that device. The identifier is not a fingerprint and does
not contain hardware, location, or credential-manager data. Existing sessions
are assigned a fresh opaque identity during the migration; existing Quick
Unlock records are associated with that identity where their prior session
association is known.

The lifecycle semantics are deliberately distinct:

- Sign out revokes the current session only. It does not remove the Budget
  device or disable its Quick Unlock authorization.
- Disable Quick Unlock revokes the device's wrapper authorization while leaving
  the device and sessions active.
- Remove Device is a transactional operation that revokes every session and
  every active Quick Unlock credential for that device. Repeating the removal
  is idempotent.

Device removal requires a session-only request, recent authentication, and the
existing cookie-session CSRF protection. API keys cannot remove devices. The
device response exposes only coarse display metadata and `enabled` /
`not_enabled` Quick Unlock status; it never exposes credential IDs, public key
material, PRF input, wrapper bytes, or Vault material.

Removing a device revokes Budget authorization, not an external Apple,
iCloud, browser, or credential-manager passkey. A credential that remains
available elsewhere cannot retrieve the removed device's wrapper because
assertion completion is still bound to the active Budget device authorization.
An offline browser may retain already-decrypted runtime memory until it next
contacts the service; the backend makes no remote RAM-erasure claim.
