# Budget API

Minimal PHP API scaffold for auth + identity flows from `project_info.md`.

## What is implemented
- Invite-only auth flows:
  - `POST /api/v1/auth/invitations`
  - `POST /api/v1/auth/invitations/accept-password`
  - `POST /api/v1/auth/invitations/accept-google`
  - `POST /api/v1/auth/sessions/password`
  - `POST /api/v1/auth/sessions/google`
  - `DELETE /api/v1/auth/sessions/current`
- Profile flows:
  - `GET /api/v1/me`
  - `PATCH /api/v1/me`
  - `POST /api/v1/me/email-change/request`
  - `POST /api/v1/me/email-change/verify`
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
  - `POST /api/v1/me/transactions/import.csv` (`mode=dry_run|commit`, multipart with `file`)
- Metrics flows:
  - `GET /api/v1/me/metrics/tags?month=YYYY-MM`
  - `GET /api/v1/me/metrics/categories?month=YYYY-MM`
  - `GET /api/v1/me/metrics/insights?date_from=YYYY-MM-DD&date_to=YYYY-MM-DD`

## Auth modes
- Cookie session: `sid` cookie (`session_id.secret`)
- Session header: `Authorization: Session <session_id.secret>`
- Master API key: `X-API-Key: bgtm_live_...`
- Cookie-session writes (`POST`, `PUT`, `PATCH`, `DELETE`) require `X-CSRF-Token` from `session.csrf_token`.
- Missing/invalid CSRF token on cookie-session writes returns `403 FORBIDDEN`.

Master API keys are blocked from `/me/master-api-keys*` management routes.

## Local setup
1. Copy env file:
```bash
cp .env.example .env
```

2. Configure DB credentials in `.env`.
Also configure Google client IDs used by your app:
```bash
GOOGLE_CLIENT_IDS=your-web-client-id.apps.googleusercontent.com,your-ios-client-id.apps.googleusercontent.com
```
Configure runtime/security flags:
```bash
APP_ENV=local # set to production in prod
APP_DEBUG=true # set to false in prod
SESSION_COOKIE_SECURE= # true|false, empty = auto by APP_ENV
TRUST_PROXY_HEADERS=false # true behind trusted reverse proxy
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
RATE_LIMIT_EMAIL_CHANGE_REQUEST_MAX=5
RATE_LIMIT_EMAIL_CHANGE_REQUEST_WINDOW_SECONDS=600
RATE_LIMIT_EMAIL_CHANGE_VERIFY_MAX=10
RATE_LIMIT_EMAIL_CHANGE_VERIFY_WINDOW_SECONDS=600
RATE_LIMIT_STORAGE_PATH=storage/rate-limit
```

3. Apply schema:
```bash
php scripts/migrate.php
```

4. Seed first owner user:
```bash
php scripts/seed_owner.php you@example.com "Your Name" "StrongPassword123!"
```

5. Run server:
```bash
php -S localhost:8000 -t public
```

Health check:
```bash
curl http://localhost:8000/api/v1/health
```

## Google auth
Google endpoints now require a real Google ID token (`google_id_token`).
The token is validated against Google and `GOOGLE_CLIENT_IDS` (audience check).

## Email delivery
- `MAIL_TRANSPORT=log`: writes emails to `storage/mail.log` (local dev default)
- `MAIL_TRANSPORT=resend`: sends real emails via Resend API (`RESEND_API_KEY` required)
- Invite creation and email-change requests both send email as part of request handling.

## Notes
- Tokens/codes are not returned in API responses.
- Session create/sign-in responses include `session.csrf_token` for cookie-session CSRF protection.
- Session cookie is always `HttpOnly` + `SameSite=Lax`; `Secure` is enabled when `SESSION_COOKIE_SECURE=true` (or auto-enabled in production when unset).
- Auth, invitation-accept, and email-change verification endpoints are rate limited and return `429 RATE_LIMITED` when exceeded.
- Security headers are attached to API responses (`X-Content-Type-Options`, `X-Frame-Options`, CSP, etc.). HSTS is added on HTTPS requests.
- In local development with `MAIL_TRANSPORT=log`, check `storage/mail.log` for invite tokens and verification codes.
- Tag payloads/responses include optional `icon_key` (`null` allowed) with an allow-list enforced by backend validation.
- Transaction payloads/responses include `is_split` (boolean, default `false`); list/export support `is_split=split|not_split`.
- Google sign-in/accept stores Google `picture` claim into `users.avatar_url` when available, and returns `avatar_url` on auth + `/me` responses.
- Recurring expense rules are generated once per month into normal transaction rows (month-based generation, current/past months only), with billing date clamped for short months.
- API/model changes must update `api_v1.md` and `openapi.yaml` in the same change set.
- Contract/spec docs live in:
  - `project_info.md`
  - `api_v1.md`
  - `openapi.yaml`
- Production deployment steps for the frontend + backend stack live in the workspace root at `DEPLOYMENT.md`.
