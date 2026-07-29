# Quick Unlock foundation

Quick Unlock is a device-scoped convenience wrapper around the existing Vault
key. It is not account authentication and it does not create a second Vault or
re-encrypt financial records.

The browser generates the 32-byte PRF input and receives the PRF output from
WebAuthn. It derives a non-extractable AES-KW key locally, wraps the existing
non-extractable runtime Vault key, and sends only the opaque wrapper. During a
later assertion the API returns the same wrapper and the PRF input; the browser
derives the key and unwraps the Vault key locally. Raw Vault keys, PRF output,
derived keys, passphrases, recovery secrets, and financial plaintext never enter
the API.

Registration and revocation require a current interactive session and recent
authentication. Assertion is session-only and does not replace account login.
All ceremonies are short-lived, one-time, user/session/purpose-bound, and use
`userVerification=required`. WebAuthn verification is delegated to the pinned
`web-auth/webauthn-lib` package; the application does not implement ceremony
cryptography itself.

The current Privacy & Vault device identity is the opaque client-generated
Budget device identifier stored on `user_sessions.device_id`. All sessions on
that device share the lifecycle, while a new device receives a separate
authorization identity. Quick Unlock credentials are exposed only to the
current device context; a synced platform credential alone cannot retrieve an
old device's wrapper. This is not a claim that a credential cannot synchronize
through a platform credential manager.

If registration does not return a PRF result, the credential remains pending.
The assertion path can complete activation when the client still has the Vault
key available to create the wrapper. No final user-facing settings or unlock UI
is part of this foundation pass.
