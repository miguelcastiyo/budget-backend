# Budget API

## What is implemented
- Invite-only auth flows:
  - `GET /api/v1/auth/invitations`
  - `POST /api/v1/auth/invitations`
  - `POST /api/v1/auth/invitations/accept-password`
  - `POST /api/v1/auth/invitations/accept-google`
  - `POST /api/v1/auth/sessions/password`
  - `POST /api/v1/auth/sessions/google`
  - `DELETE /api/v1/auth/sessions/current`
  - `POST /api/v1/auth/password-reset/request`
  - `POST /api/v1/auth/password-reset/confirm`
- Profile flows:
  - `GET /api/v1/me`
  - `PATCH /api/v1/me`
  - `GET /api/v1/me/preferences`
  - `PATCH /api/v1/me/preferences`
  - `GET /api/v1/me/settings-summary`
  - `POST /api/v1/me/email-change/request`
  - `POST /api/v1/me/email-change/verify`
  - `POST /api/v1/me/auth/convert-google`
- Master API key flows:
  - `GET /api/v1/me/master-api-keys`
  - `POST /api/v1/me/master-api-keys`
  - `DELETE /api/v1/me/master-api-keys/{api_key_id}`
- Budget settings flows:
  - `GET /api/v1/me/budget-settings`
  - `PUT /api/v1/me/budget-settings`
- Tag flows:
  - `GET /api/v1/me/tags`
  - `POST /api/v1/me/tags`
  - `PATCH /api/v1/me/tags/{tag_id}`
  - `DELETE /api/v1/me/tags/{tag_id}`
- Card flows:
  - `GET /api/v1/me/cards`
  - `POST /api/v1/me/cards`
  - `PATCH /api/v1/me/cards/{card_id}`
  - `DELETE /api/v1/me/cards/{card_id}`
- Recurring expense flows:
  - `GET /api/v1/me/recurring-expenses`
  - `POST /api/v1/me/recurring-expenses`
  - `PATCH /api/v1/me/recurring-expenses/{recurring_expense_id}`
  - `DELETE /api/v1/me/recurring-expenses/{recurring_expense_id}`
- Transaction flows:
  - `GET /api/v1/me/transactions`
  - `POST /api/v1/me/transactions`
  - `PATCH /api/v1/me/transactions/{transaction_id}`
  - `DELETE /api/v1/me/transactions/{transaction_id}`
- CSV flows:
  - `GET /api/v1/me/transactions/export.csv`
  - `POST /api/v1/me/transactions/import.csv` (`mode=preview|dry_run|commit`, multipart with `file`; `mapping`, `date_strategy`, `category_strategy`, `tag_strategy`, and `amount_strategy` JSON for validation/commit)
  - `DELETE /api/v1/me/imports/{import_run_id}/transactions`
  - `GET /api/v1/me/data-runs?limit=50`
- Metrics flows:
  - `GET /api/v1/me/months/{month}/overview` (primary homepage/month endpoint)
  - `GET /api/v1/me/metrics/tags?month=YYYY-MM`
  - `GET /api/v1/me/metrics/categories?month=YYYY-MM`
  - `GET /api/v1/me/dashboard?month=YYYY-MM` (deprecated)
  - `GET /api/v1/me/metrics/insights?date_from=YYYY-MM-DD&date_to=YYYY-MM-DD`

## Auth modes
- Cookie session: `sid` cookie (`session_id.secret`)
- Session header: `Authorization: Session <session_id.secret>`
- Master API key: `X-API-Key: bgtm_live_...`
- Cookie-session writes (`POST`, `PUT`, `PATCH`, `DELETE`) require `X-CSRF-Token` from `session.csrf_token`.
- Missing/invalid CSRF token on cookie-session writes returns `403 FORBIDDEN`.

Master API keys are blocked from `/me/master-api-keys*` management routes.

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
Configure master API key expiry:
```bash
MASTER_API_KEY_MAX_TTL_DAYS=365
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
RATE_LIMIT_API_KEY_CREATE_MAX=5
RATE_LIMIT_API_KEY_CREATE_WINDOW_SECONDS=3600
RATE_LIMIT_API_KEY_REVOKE_MAX=20
RATE_LIMIT_API_KEY_REVOKE_WINDOW_SECONDS=3600
RATE_LIMIT_CSV_IMPORT_MAX=10
RATE_LIMIT_CSV_IMPORT_WINDOW_SECONDS=3600
RATE_LIMIT_CSV_EXPORT_MAX=30
RATE_LIMIT_CSV_EXPORT_WINDOW_SECONDS=3600
RATE_LIMIT_METRICS_MAX=120
RATE_LIMIT_METRICS_WINDOW_SECONDS=60
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

4. Apply schema:
```bash
php scripts/migrate.php
```

5. Seed first owner user:
```bash
php scripts/seed_owner.php you@example.com "Your Name" "StrongPassword123!"
```

6. Run server:
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
- Auth, invitation, API key, CSV import/export, profile-change, email-change, account-conversion, and metrics endpoints are rate limited and return `429 RATE_LIMITED` when exceeded.
- Password reset emails are generic for unknown emails to avoid account enumeration. Completed resets revoke existing sessions and are audit logged.
- Master API key auth rejects expired keys. New key expirations must be future-dated and within `MASTER_API_KEY_MAX_TTL_DAYS`; `expires_at=null` intentionally creates a non-expiring key.
- Audit logs are recorded for invite acceptance/creation, master API key lifecycle events, profile updates, email changes, and account conversion to Google sign-in. Owner/admin sessions can read recent events with `GET /me/audit-logs`.
- CSV imports are bounded by file size, row count, and returned row-error limits. Exports stream CSV rows and escape spreadsheet formula prefixes before writing cells.
- Security headers are attached to API responses (`X-Content-Type-Options`, `X-Frame-Options`, CSP, etc.). HSTS is added on HTTPS requests.
- Backend server errors use structured JSON logs and can send optional webhook alerts when `ERROR_ALERT_WEBHOOK_URL` is configured.
- In local development with `MAIL_TRANSPORT=log`, check `storage/mail.log` for invite tokens and verification codes.
- Tag payloads/responses include optional `icon_key` (`null` allowed) with an allow-list enforced by backend validation.
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
