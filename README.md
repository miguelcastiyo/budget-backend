# Budget API

## Current documentation

- [Architecture](docs/architecture.md)
- [Privacy model](docs/privacy.md)
- [Encryption architecture](docs/encryption.md)
- [Privacy migration](docs/migration.md)
- [Backend testing](docs/testing.md)
- [Backend operations](docs/operations.md)
- [API contract](../openapi.yaml)
- [Human-readable API context](../api_v1.md)

Financial data is application data, not operational telemetry. Never log
request/response bodies or financial field values; operational events must use
the approved metadata allow-list. Cryptographic keys, recovery material,
authorization/session secrets, and ciphertext blobs must never be logged.

New accounts are encrypted by default: they begin in `vault_setup_required`,
complete Vault and Recovery Code setup, and transition directly to `encrypted`.
Existing legacy accounts retain the separate migration path.

## What is implemented

The public backend surface is limited to account, security, operational, and
encrypted-record concerns:

- Invite-only authentication, sessions, password reset, and account deletion.
- Profile, preferences, onboarding status, email changes, and Google conversion.
- Vault setup, passphrase and recovery metadata, migration status, Quick Unlock,
  device lifecycle, and privacy-safe operational status.
- Session-authenticated audit-log access.
- Encrypted financial record create, update, delete, batch, sync, revision, and
  conflict handling.

Financial product behavior—transactions, taxonomy, recurring items, budgets,
funds, savings plans, closeouts, metrics, and CSV contents—is owned by the
encrypted frontend authority. The former plaintext HTTP routes are no longer
registered. Legacy tables and implementation remain temporarily for Phase 4
retirement and explicitly isolated operator repair tooling; they are not part
of the supported product API.

## Funds notes

- Funds are implemented as durable goals or envelopes backed by `fund_entries`, not a mutable balance field on `funds`.
- A Fund is a container for intentionally saved money; a goal is optional and derived from `goal_amount`.
- `fund_type` is a deprecated compatibility field. New clients can omit it, and `target_month` is only valid when `goal_amount` is present.
- Transaction-linked fund contributions create or link a real `savings` transaction and keep the linked fund entry in sync on transaction update or delete.
- Closeout fund allocations create fund entries without creating synthetic transactions.
- Replacing closeout allocations or reopening a closeout voids the old closeout-linked fund entries so active fund balances stay correct.

## Savings Plan notes

- Savings Plans persist only monthly allocation intent in `monthly_savings_allocations`; they do not move money or mutate budgets, transactions, Funds, or closeouts.
- Plan progress is derived from qualifying transaction-linked and closeout-linked Fund entries. Fund balances remain ledger-backed.
- Closeout Fund allocations count toward Fund-plan progress but do not increase monthly Savings transaction totals.
- A closed month is read-only for Savings Plan replacement; reopening the closeout makes it editable again.

## Auth modes
- Cookie session: `sid` cookie (`session_id.secret`)
- Session header: `Authorization: Session <session_id.secret>`
- Cookie-session writes (`POST`, `PUT`, `PATCH`, `DELETE`) require `X-CSRF-Token` from `session.csrf_token`.
- Missing/invalid CSRF token on cookie-session writes returns `403 FORBIDDEN`.

## Invite acceptance UX requirement
- Invite links must open an invite-acceptance experience, not a plain sign-in screen.
- When the frontend receives an `invite_token`, it must keep the user in invite mode until the invite is accepted or rejected.
- The frontend should call `GET /api/v1/auth/invitations/preview?invite_token=...` before rendering the invite form.
- The preview response is the source of truth for the initial invite branch:
- invited `gmail.com` / `googlemail.com` addresses map to `preferred_auth_provider: "google"`
- all other addresses map to `preferred_auth_provider: "password"`
- Keep the non-primary branch secondary so Gmail and non-Gmail invitees do not see the same mixed setup UI by default.
- The invite screen must support both branches:
- `Continue with Google` calls `POST /api/v1/auth/invitations/accept-google`.
- `Set password` calls `POST /api/v1/auth/invitations/accept-password`.
- New non-Google invitees must never be asked to sign in with an existing password before account creation.
- The password branch should prefill and lock the invited email, collect `display_name`, `password`, and `client_type`, and explain that this creates the account.
- The visual treatment should match the app's existing sign-in look and feel rather than sending users to a separate utilitarian form.
- The invite screen must be mobile optimized, including iPhone Safari and installed iOS web app viewport behavior:
- no clipped CTA or keyboard-covered submit button
- no horizontal overflow
- safe-area aware bottom spacing
- large tap targets and a single-column layout
- the primary action remains visible and usable on narrow screens

## Local setup
1. Copy env file:
```bash
cp .env.example .env
```

2. Configure DB credentials in `.env`.
Use `DB_CONNECT_TIMEOUT_SECONDS` to keep failed database connections bounded:
```bash
DB_DSN=mysql:host=127.0.0.1;port=3306;dbname=budget;charset=utf8mb4
DB_USER=root
DB_PASS=
DB_CONNECT_TIMEOUT_SECONDS=5
```

Also configure Google client IDs used by your app:
```bash
GOOGLE_CLIENT_IDS=your-web-client-id.apps.googleusercontent.com,your-ios-client-id.apps.googleusercontent.com
GOOGLE_CERTS_CACHE_PATH=storage/google-certs-cache.json
GOOGLE_ID_TOKEN_CLOCK_SKEW_SECONDS=300
```
Configure account recovery:
```bash
PASSWORD_RESET_TOKEN_TTL_MINUTES=30
```
Configure runtime/security flags:
```bash
APP_ENV=local # set to production in prod
APP_DEBUG=true # set to false in prod
SESSION_COOKIE_SECURE= # true|false, empty = auto by APP_ENV
TRUST_PROXY_HEADERS=false # true behind trusted reverse proxy
```
Configure WebAuthn Quick Unlock explicitly:
```bash
WEBAUTHN_RP_ID=budget.example.com
WEBAUTHN_RP_NAME=Budget
WEBAUTHN_ALLOWED_ORIGINS=https://budget.example.com
WEBAUTHN_CHALLENGE_TTL_SECONDS=60
```
Production requires a non-wildcard HTTPS origin whose host is the RP ID or a
subdomain of it. The backend fails closed when these values are missing or
invalid; it never derives trust from request headers. Local development may
use the localhost values in `.env.example`.
Configure backend error reporting:
```bash
ERROR_ALERT_WEBHOOK_URL= # optional Slack, Discord, or generic webhook URL for 5xx exceptions
ERROR_ALERT_WEBHOOK_FORMAT=json # json|slack|discord
ERROR_ALERT_TIMEOUT_SECONDS=2
```
Configure email delivery:
```bash
MAIL_TRANSPORT=log # use `resend` for real delivery
MAIL_FROM_EMAIL=no-reply@example.com
MAIL_FROM_NAME=Budget App
MAIL_LOG_PATH=storage/mail.log
RESEND_API_KEY=
```
Configure auth/invite rate limiting:
```bash
RATE_LIMIT_AUTH_MAX=10
RATE_LIMIT_AUTH_WINDOW_SECONDS=60
RATE_LIMIT_INVITE_ACCEPT_MAX=10
RATE_LIMIT_INVITE_ACCEPT_WINDOW_SECONDS=60
RATE_LIMIT_INVITE_CREATE_MAX=10
RATE_LIMIT_INVITE_CREATE_WINDOW_SECONDS=3600
RATE_LIMIT_PASSWORD_RESET_REQUEST_MAX=5
RATE_LIMIT_PASSWORD_RESET_REQUEST_WINDOW_SECONDS=600
RATE_LIMIT_PASSWORD_RESET_CONFIRM_MAX=10
RATE_LIMIT_PASSWORD_RESET_CONFIRM_WINDOW_SECONDS=600
RATE_LIMIT_EMAIL_CHANGE_REQUEST_MAX=5
RATE_LIMIT_EMAIL_CHANGE_REQUEST_WINDOW_SECONDS=600
RATE_LIMIT_EMAIL_CHANGE_VERIFY_MAX=10
RATE_LIMIT_EMAIL_CHANGE_VERIFY_WINDOW_SECONDS=600
RATE_LIMIT_AUTH_CONVERT_MAX=5
RATE_LIMIT_AUTH_CONVERT_WINDOW_SECONDS=600
RATE_LIMIT_PROFILE_CHANGE_MAX=30
RATE_LIMIT_PROFILE_CHANGE_WINDOW_SECONDS=3600
RATE_LIMIT_STORAGE_PATH=storage/rate-limit
```
Configure CSV import guardrails:
```bash
CSV_IMPORT_MAX_BYTES=5242880
CSV_IMPORT_MAX_ROWS=5000
CSV_IMPORT_MAX_ERRORS=100
```
CSV import preview returns headers, sample rows, date profiles, and capped column value profiles without writing. Dry run and commit use explicit header mapping so users can map arbitrary CSV headers to Budget fields, apply one selected year to yearless dates with `date_strategy`, translate external category labels into fixed Budget categories with `category_strategy`, and map source tag values to existing or new user tags with `tag_strategy`. Debt payments should be imported as `needs` with the `Debt` tag. Spending-only bank CSVs can use `amount_strategy.blank_mapped_amount=skip`; skipped blank-amount rows are counted but do not create transactions, duplicates, or validation errors. Missing selected tags/cards are created only during commit; imported tags receive an inferred icon key. Rollback soft-deletes transactions for imports committed after per-row import-run tracking was added; tags/cards stay in place.

3. Create local storage paths if missing:

```bash
mkdir -p storage/rate-limit
touch storage/mail.log
```

4. Install committed PHP dependencies:

```bash
composer install --no-interaction
```

5. Apply schema:
```bash
php scripts/migrate.php
```

6. Seed first owner user:
```bash
php scripts/seed_owner.php you@example.com "Your Name" "StrongPassword123!"
```

7. Run server:
```bash
php -S localhost:8000 -t public
```

Health check:
```bash
curl http://localhost:8000/api/v1/health
```

Readiness check:
```bash
curl http://localhost:8000/api/v1/ready
```

## Production monitoring
- A scheduled production monitor lives at `.github/workflows/monitor-production.yml`.
- It checks:
  - direct backend liveness at `https://api-budget.miguelcastillo.info/api/v1/health`
  - direct backend readiness at `https://api-budget.miguelcastillo.info/api/v1/ready`
  - frontend proxy liveness at `https://budget.miguelcastillo.info/api/v1/health`
  - frontend proxy readiness at `https://budget.miguelcastillo.info/api/v1/ready`
  - Google sign-in reachability by expecting a fast `422` from `POST /api/v1/auth/sessions/google` with an empty JSON body
- The check script is `scripts/check_production_health.sh`.
- By default the workflow runs every 10 minutes and opens or closes a GitHub issue titled `Production health check failing`.
- Override production URLs with GitHub Actions variables:
  - `PROD_FRONTEND_URL`
  - `PROD_BACKEND_URL`
- Use the workspace runbook at `../docs/operations/production-health.md` to triage `curl 28` timeouts, frontend infinite loading, and Lightsail service failures before rebooting the instance when possible.
- `/api/v1/health` is a lightweight liveness check and does not connect to MariaDB.
- `/api/v1/ready` is a readiness check and returns `503` if MariaDB cannot be reached.
- Backend 5xx exceptions are written as structured JSON logs with `event=server_error`, a stable `fingerprint`, and `request_id`.
- API responses, including health/readiness responses, include `X-Request-ID`; use that value to find the matching structured log entry.
- Set `ERROR_ALERT_WEBHOOK_URL` in production to alert on backend 5xx exceptions independently of scheduled health checks. Use `ERROR_ALERT_WEBHOOK_FORMAT=slack`, `discord`, or `json` to match the target webhook.

## Google auth
Google endpoints now require a real Google ID token (`google_id_token`).
The backend verifies the JWT locally against cached Google signing certificates and checks `GOOGLE_CLIENT_IDS` for audience validation.
The certificate cache defaults to `storage/google-certs-cache.json`.

## Email delivery
- `MAIL_TRANSPORT=log`: writes emails to `storage/mail.log` (local dev default)
- `MAIL_TRANSPORT=resend`: sends real emails via Resend API (`RESEND_API_KEY` required)
- Invite creation and email-change requests both send email as part of request handling.
- Invite creation and invite listing require the `owner` role. Invites can assign `admin` or `member`.

## Notes
- The schema and migrations use `utf8mb4_unicode_ci` so they work cleanly on MariaDB as well as MySQL-compatible setups.
- `scripts/migrate.php` is safe for repeat runs. On an empty database it applies `schema.sql`; on an existing database it baselines migration history and only applies new migration files after that.
- Tokens/codes are not returned in API responses.
- Session create/sign-in responses include `session.csrf_token` for cookie-session CSRF protection.
- Session cookie is always `HttpOnly` + `SameSite=Lax`; `Secure` is enabled when `SESSION_COOKIE_SECURE=true` (or auto-enabled in production when unset).
- Auth, invitation, profile-change, email-change, and account-conversion endpoints are rate limited and return `429 RATE_LIMITED` when exceeded.
- Password reset emails are generic for unknown emails to avoid account enumeration. Completed resets revoke existing sessions and are audit logged.
- Audit logs are recorded for invite acceptance/creation, profile updates, email changes, and account conversion to Google sign-in. Historical API-key audit rows remain readable. Owner/admin sessions can read recent events with `GET /me/audit-logs`.
- CSV imports are bounded by file size, row count, and returned row-error limits. Exports stream CSV rows and escape spreadsheet formula prefixes before writing cells.
- Security headers are attached to API responses (`X-Content-Type-Options`, `X-Frame-Options`, CSP, etc.). HSTS is added on HTTPS requests.
- Backend server errors use structured JSON logs and can send optional webhook alerts when `ERROR_ALERT_WEBHOOK_URL` is configured.
- In local development with `MAIL_TRANSPORT=log`, check `storage/mail.log` for invite tokens and verification codes.
- Tag payloads/responses include optional `icon_key` (`null` allowed) with an allow-list enforced by backend validation.
- Context payloads/responses include an optional `icon_key` (`null` allowed) from the separately curated Context palette. A few semantically useful keys overlap with Tags, and name-based fallback is used when omitted.
- Transaction payloads/responses include `is_split` (boolean, default `false`); list/export support `is_split=split|not_split`.
- `GET /me/transactions` returns paginated rows plus `summary` aggregates for the full filtered result set so clients do not need to load every page for stats.
- Google sign-in/accept stores Google `picture` claim into `users.avatar_url` when available, and returns `avatar_url` on auth + `/me` responses.
- Auth and profile responses now include `user_preferences`; the current supported account-level preference is `appearance.theme` with `light`, `dark`, or `system`.
- `GET /me/preferences` and `PATCH /me/preferences` are the dedicated account-preferences endpoints. The frontend dark mode toggle now persists through this API instead of browser-only storage.
- `GET /me/settings-summary` returns the Settings landing-page summary in one request so the frontend does not load all transactions to calculate account stats.
- Recurring expense rules are generated once per month into normal transaction rows (month-based generation, current/past months only), with billing date clamped for short months.
- API/model changes must update `openapi.yaml` in the same change set; `api_v1.md` is secondary human-readable backend context.
- Contract/spec docs live in:
  - `project_info.md`
  - `api_v1.md`
  - `openapi.yaml`
- Production deployment steps for the frontend + backend stack live at `../docs/operations/deployment.md`.
