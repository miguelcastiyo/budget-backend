# Budget API v1 Contract (Session-Based)

This contract is derived from `project_info.md` and is intended for a PHP + MySQL backend.

## 1) API Conventions
- Base path: `/api/v1`
- Content type: `application/json` (except multipart import and CSV export download)
- Date format: `YYYY-MM-DD`
- Money format: decimal string with 2 places (example: `"123.45"`)
- Timezone: store UTC, return ISO-8601 timestamps

### Health Endpoints

`GET /health`

Purpose:
- Liveness check for the PHP API process.
- Does not require authentication.
- Does not open a database connection.

Response `200`:
```json
{
  "ok": true,
  "service": "budget-api",
  "check": "health",
  "time": "2026-05-04T12:00:00+00:00"
}
```

`GET /ready`

Purpose:
- Readiness check for dependencies required to serve normal API traffic.
- Does not require authentication.
- Checks MariaDB by opening a connection and running `SELECT 1`.

Response `200` when ready:
```json
{
  "ok": true,
  "service": "budget-api",
  "check": "ready",
  "dependencies": {
    "database": "ok"
  },
  "time": "2026-05-04T12:00:00+00:00"
}
```

Response `503` when not ready:
```json
{
  "ok": false,
  "service": "budget-api",
  "check": "ready",
  "dependencies": {
    "database": "error"
  },
  "time": "2026-05-04T12:00:00+00:00"
}
```

The frontend also proxies these as `/api/v1/health` and `/api/v1/ready` from the frontend domain.

### Fixed Category Enum
- `needs`
- `wants`
- `savings`

Categories are fixed in v1 (not user-editable). Debt payments use `needs` plus a normal user-managed `Debt` tag.

## 2) Authentication & Session Model

### Goals
- Invite-only access, no public sign-up.
- Support Google and email/password.
- Session-based auth for web now and iOS later with minimal backend change.

### Session Transport
- Web: secure, httpOnly cookie `sid`.
- Native (future iOS): `Authorization: Session <session_token>`.
- Both map to the same `user_sessions` table (single session domain model).
- API testing: `X-API-Key: bgtm_...` (non-session master API key).

### CSRF
- Cookie sessions require `X-CSRF-Token` on non-GET requests.
- CSRF token is returned as `session.csrf_token` on session creation/sign-in responses.
- Missing/invalid CSRF token for cookie-session writes returns `403 FORBIDDEN`.
- CSRF is not required for `Authorization: Session <session_token>` or `X-API-Key` requests.

### Master API Key Rules
- Master API keys are for testing and are non-session credentials.
- Only owner/admin can generate/list/revoke master API keys.
- Raw key value is shown only once at creation and only the hash is stored.
- Master API keys can access protected `/me/*` routes except key-management routes.

### Session Object
```json
{
  "session_id": "ses_01J...",
  "user_id": "usr_123",
  "created_at": "2026-03-05T18:33:21Z",
  "expires_at": "2026-03-12T18:33:21Z"
}
```

## 3) Invite-Only Onboarding

### 3.1 Create Invite
`POST /auth/invitations`

Auth required: inviter must be owner.

Request:
```json
{
  "invitee_name": "New User",
  "email": "newuser@example.com",
  "role": "member",
  "expires_at": "2026-03-12T00:00:00Z",
  "email_subject": "You are invited to Budget App",
  "email_body": "I sent you an invite to join Budget."
}
```

Response `201`:
```json
{
  "invite_id": "inv_123",
  "invitee_name": "New User",
  "email": "newuser@example.com",
  "role": "member",
  "status": "pending",
  "expires_at": "2026-03-12T00:00:00Z",
  "created_at": "2026-03-05T00:00:00Z",
  "accepted_at": null
}
```

### 3.1.1 List Invites
`GET /auth/invitations`

Auth required: user must be owner.

Response `200`:
```json
{
  "items": [
    {
      "invite_id": "inv_123",
      "invitee_name": "New User",
      "email": "newuser@example.com",
      "role": "member",
      "status": "pending",
      "expires_at": "2026-03-12T00:00:00Z",
      "created_at": "2026-03-05T00:00:00Z",
      "accepted_at": null
    }
  ]
}
```

### 3.2 Accept Invite with Email/Password
`POST /auth/invitations/accept-password`

Request:
```json
{
  "invite_token": "...",
  "display_name": "Miguel",
  "password": "StrongPassword123!"
}
```

Response `201`:
```json
{
  "user": {
    "id": "usr_123",
    "email": "newuser@example.com",
    "display_name": "Miguel",
    "avatar_url": null,
    "auth_provider": "password",
    "onboarding_complete": true,
    "user_preferences": {
      "appearance": {
        "theme": "system"
      }
    }
  },
  "session": {
    "session_id": "ses_...",
    "expires_at": "2026-03-12T18:33:21Z",
    "csrf_token": "2c6d4f..."
  }
}
```

### 3.3 Accept Invite with Google
`POST /auth/invitations/accept-google`

Request:
```json
{
  "invite_token": "...",
  "google_id_token": "...",
  "display_name": "Miguel"
}
```

Rules:
- Google email must match invite email.
- When Google provides a profile picture, backend stores it in `users.avatar_url` and returns it as `avatar_url` in auth/profile responses.

Response `201`: same shape as password accept, with `auth_provider: "google"`.

### 3.4 Sign In
`POST /auth/sessions/password`

Request:
```json
{
  "email": "newuser@example.com",
  "password": "StrongPassword123!",
  "client_type": "web"
}
```

`POST /auth/sessions/google`

Request:
```json
{
  "google_id_token": "...",
  "client_type": "web"
}
```

Rules:
- User must already exist from invite acceptance.
- No self-registration from login endpoints.

For `client_type: "web"`, backend sets `sid` cookie.
For `client_type: "native"`, response includes `session_token`.
All successful auth responses include `session.csrf_token`.
All successful auth responses also include `user.user_preferences`.

### 3.5 Sign Out
`DELETE /auth/sessions/current`

Invalidates current session.

### 3.6 Request Password Reset
`POST /auth/password-reset/request`

Request:
```json
{
  "email": "newuser@example.com"
}
```

Response `202` is intentionally generic:
```json
{
  "status": "accepted",
  "message": "If a password account exists for that email, a reset link has been sent."
}
```

Rules:
- Only active email/password accounts receive reset emails.
- Unknown emails and Google-only accounts receive the same generic response.
- Reset tokens are hashed at rest and expire after `PASSWORD_RESET_TOKEN_TTL_MINUTES` (default `30`).

### 3.7 Confirm Password Reset
`POST /auth/password-reset/confirm`

Request:
```json
{
  "reset_token": "...",
  "password": "NewStrongPassword123!"
}
```

Response `200`:
```json
{
  "status": "completed",
  "message": "Password has been reset. Sign in with your new password."
}
```

Rules:
- Reset tokens are one-time use.
- New passwords must be at least 8 characters.
- Completing a reset revokes existing sessions for that user.
- Password reset request and completion events are audit logged.

## 4) Profile & Account

### 4.1 Get Profile
`GET /me`

Response:
```json
{
  "id": "usr_123",
  "email": "newuser@example.com",
  "display_name": "Miguel",
  "avatar_url": "https://lh3.googleusercontent.com/a/...",
  "auth_provider": "password",
  "email_verified": true,
  "created_at": "2026-03-05T18:33:21Z",
  "onboarding_complete": true,
  "user_preferences": {
    "appearance": {
      "theme": "system"
    }
  }
}
```

### 4.2 Update Display Name
`PATCH /me`

Request:
```json
{
  "display_name": "Miguel Castillo"
}
```

### 4.3 Get User Preferences
`GET /me/preferences`

Response:
```json
{
  "appearance": {
    "theme": "dark"
  }
}
```

Notes:
- Preferences are stored at the account level on the `users` record.
- Unknown or invalid preference keys are rejected by the backend.
- Currently supported:
  - `appearance.theme`: `light | dark | system`

### 4.4 Update User Preferences
`PATCH /me/preferences`

Request:
```json
{
  "appearance": {
    "theme": "dark"
  }
}
```

Response `200`:
```json
{
  "appearance": {
    "theme": "dark"
  }
}
```

Notes:
- Updates are merged server-side into the existing preference document.
- The current frontend uses this endpoint to persist dark mode across sessions and devices.

### 4.5 Settings Summary
`GET /me/settings-summary`

Purpose:
- Returns the Settings landing-page summary in a single request.
- Avoids loading every transaction into the frontend to calculate account-level stats.

Response `200`:
```json
{
  "monthly_income": "6500.00",
  "tags_count": 12,
  "cards_count": 4,
  "recurring_count": 5,
  "recurring_committed_total": "1800.00",
  "avg_monthly_spend": "2430.22"
}
```

Notes:
- `monthly_income` is `null` when the user has not saved budget settings yet.
- `tags_count` and `cards_count` count active, non-deleted records.
- `recurring_count` counts non-deleted recurring rules.
- `recurring_committed_total` is the active committed recurring total for the current UTC month.
- `avg_monthly_spend` is calculated server-side from non-deleted transactions grouped by transaction month.

### 4.6 Request Email Change (Password Users Only)
`POST /me/email-change/request`

Request:
```json
{
  "new_email": "new.address@example.com"
}
```

Rules:
- Allowed only when `auth_provider = password`.
- Sends verification code to `new_email`.
- Does not update email yet.
- Rate limited.

Response `202`:
```json
{
  "email_change_id": "emc_123",
  "status": "verification_pending"
}
```

### 4.7 Verify Email Change
`POST /me/email-change/verify`

Request:
```json
{
  "email_change_id": "emc_123",
  "verification_code": "123456"
}
```

Rules:
- Email is updated only on successful code verification.
- Rate limited.

Response `200`:
```json
{
  "email": "new.address@example.com",
  "email_verified": true
}
```

### 4.8 Convert Password Account To Google Sign-In
`POST /me/auth/convert-google`

Auth required: session auth only.

Request:
```json
{
  "google_id_token": "..."
}
```

Rules:
- Allowed only when `auth_provider = password`.
- The Google account email must match the current account email.
- The account is converted in place on the same user record.
- Password sign-in stops working after a successful conversion.

Response `200`:
```json
{
  "id": "usr_123",
  "email": "newuser@example.com",
  "display_name": "Miguel",
  "avatar_url": "https://lh3.googleusercontent.com/a/...",
  "auth_provider": "google",
  "email_verified": true,
  "created_at": "2026-03-05T18:33:21Z",
  "onboarding_complete": true,
  "user_preferences": {
    "appearance": {
      "theme": "system"
    }
  }
}
```

### 4.9 List Master API Keys
`GET /me/master-api-keys`

Auth required: session auth only.

Response:
```json
{
  "items": [
    {
      "id": "mak_123",
      "name": "local-postman",
      "key_prefix": "bgtm_live_7fA9",
      "created_at": "2026-03-05T19:12:00Z",
      "last_used_at": "2026-03-05T19:30:44Z",
      "expires_at": null,
      "status": "active"
    }
  ]
}
```

### 4.10 Create Master API Key
`POST /me/master-api-keys`

Auth required: session auth only.

Request:
```json
{
  "name": "local-postman",
  "expires_at": null
}
```

`expires_at` may be `null` for a non-expiring key. When provided, it must be a valid future date-time within `MASTER_API_KEY_MAX_TTL_DAYS` (default `365`). Expired keys are rejected during API key authentication.

Response `201`:
```json
{
  "id": "mak_123",
  "name": "local-postman",
  "api_key": "bgtm_live_2M4...full_secret...",
  "key_prefix": "bgtm_live_2M4x",
  "created_at": "2026-03-05T19:12:00Z",
  "expires_at": null,
  "status": "active"
}
```

### 4.11 Revoke Master API Key
`DELETE /me/master-api-keys/{api_key_id}`

Auth required: session auth only.

### 4.12 List Audit Logs
`GET /me/audit-logs?limit=50`

Auth required: session auth only. User must be `owner` or `admin`.

Audit logs are written for security-sensitive account events:
- `invitation.created`
- `invitation.accepted`
- `master_api_key.created`
- `master_api_key.revoked`
- `profile.updated`
- `profile.preferences_updated`
- `profile.email_change_requested`
- `profile.email_changed`
- `profile.auth_provider_changed`
- `profile.password_reset_requested`
- `profile.password_reset_completed`

Response:
```json
{
  "items": [
    {
      "event_id": "aud_123",
      "actor_user_id": "1",
      "actor_email": "owner@example.com",
      "actor_auth_type": "session",
      "action": "master_api_key.created",
      "target_type": "master_api_key",
      "target_id": "mak_123",
      "ip_address": "127.0.0.1",
      "user_agent": "Mozilla/5.0",
      "metadata": {
        "name": "local-postman",
        "key_prefix": "bgtm_live_2M4x",
        "expires_at": null
      },
      "created_at": "2026-03-05 19:12:00"
    }
  ]
}
```

## 5) Tags

### 5.1 List Tags
`GET /me/tags`

Response item shape:
```json
{
  "id": "12",
  "name": "Groceries",
  "icon_key": "shopping_cart"
}
```

### 5.2 Create Tag
`POST /me/tags`

Request:
```json
{
  "name": "Groceries",
  "icon_key": "shopping_cart"
}
```

### 5.3 Tag Quick Picks
`GET /me/tags/quick-picks`

Query params:
- `limit=5` (optional, clamps to `1..10`, default `5`)

Rules:
- Returns active tags for the authenticated user.
- Tags used in transaction history are ranked by transaction count, then most recent use, then tag name.
- If history returns fewer than `limit` tags, remaining slots are filled from active tags alphabetically.
- Deleted transactions and inactive/deleted tags are excluded.

Response:
```json
{
  "items": [
    {
      "id": "12",
      "name": "Groceries",
      "icon_key": "shopping_cart"
    }
  ]
}
```

### 5.4 Update Tag
`PATCH /me/tags/{tag_id}`

Request:
```json
{
  "name": "Dining Out",
  "icon_key": "coffee"
}
```

### 5.5 Delete Tag
`DELETE /me/tags/{tag_id}`

Rules:
- Tag names are unique per user (case-insensitive).
- Soft delete recommended in DB.
- `icon_key` is optional (`null` is allowed). Clients may use `null` to mean auto-icon by tag name.
- Allowed `icon_key` values: `home`, `shopping_cart`, `car`, `plane`, `receipt`, `coffee`, `smartphone`, `credit_card`, `piggy_bank`, `trending_up`, `briefcase`, `heart`, `dumbbell`, `book_open`, `film`, `gamepad`, `gift`, `shield`, `lightbulb`, `wrench`, `wallet`, `tag`.

## 6) Cards

### 6.1 List Cards
`GET /me/cards`

### 6.2 Create Card
`POST /me/cards`

Request:
```json
{
  "name": "Chase Sapphire"
}
```

### 6.3 Update Card
`PATCH /me/cards/{card_id}`

Request:
```json
{
  "name": "Chase Sapphire Reserve"
}
```

### 6.4 Delete Card
`DELETE /me/cards/{card_id}`

Rules:
- Card names are unique per user (case-insensitive).
- Soft delete recommended in DB.

## 7) Recurring Expenses

Recurring rules are used for committed monthly expenses (rent, subscriptions, insurance, etc.).

Generation behavior:
- Rules are materialized into normal transactions once per month.
- Generation is month-based (not due-date-triggered) so committed spend appears early in the dashboard.
- Transaction date is set to the rule's billing date for that month.
- Future months are not pre-generated.
- Day clamp behavior:
  - `billing_type=day_of_month` with `billing_day=31` becomes the last valid day for shorter months.
  - `billing_type=last_day` always uses the month's last day.

### 7.1 List Recurring Expenses
`GET /me/recurring-expenses?month=YYYY-MM`

Response:
```json
{
  "month": "2026-03",
  "committed_total": "1450.00",
  "items_count": 2,
  "items": [
    {
      "id": "1",
      "expense": "Rent",
      "amount": "1200.00",
      "category": "needs",
      "tag": { "id": "12", "name": "Housing", "icon_key": "home" },
      "card": null,
      "billing_type": "last_day",
      "billing_day": null,
      "projected_date_for_month": "2026-03-31",
      "starts_month": "2026-01",
      "ends_month": null,
      "is_active": true,
      "generated_for_month": true
    }
  ]
}
```

### 7.2 Create Recurring Expense
`POST /me/recurring-expenses`

Request:
```json
{
  "expense": "Spotify",
  "amount": "11.99",
  "category": "wants",
  "tag_id": "14",
  "card_id": "4",
  "seed_transaction_id": "987",
  "billing_type": "day_of_month",
  "billing_day": 12,
  "starts_month": "2026-03",
  "ends_month": null,
  "is_active": true
}
```

### 7.3 Update Recurring Expense
`PATCH /me/recurring-expenses/{recurring_expense_id}`

Any field from create can be updated. Updates only affect future generated months.

### 7.4 Delete Recurring Expense
`DELETE /me/recurring-expenses/{recurring_expense_id}`

Rules:
- Delete stops future generation. Existing transactions are unchanged.
- `billing_day` is required only for `billing_type=day_of_month`.
- `starts_month`/`ends_month` use `YYYY-MM` and `ends_month >= starts_month` when present.
- `seed_transaction_id` is optional and can link the current month occurrence to an already-created transaction to avoid duplicates.

## 8) Budget Settings (Monthly Income + 3 Buckets)

### 8.1 Get Budget Settings
`GET /me/budget-settings`

Optional query params:
- `month=2026-06`

Without `month`, the endpoint returns the flat latest/current budget settings shape for backward compatibility.

With `month`, the endpoint resolves the budget version effective for that month:
```json
{
  "requested_month": "2026-06",
  "resolved_effective_month": "2026-04",
  "is_exact_match": false,
  "settings": {
    "monthly_income": "6200.00",
    "income_source_type": "monthly",
    "primary_monthly_income": "6200.00",
    "primary_hourly_rate": null,
    "primary_weekly_hours": null,
    "side_income_type": "none",
    "side_income_label": null,
    "side_monthly_income": null,
    "side_hourly_rate": null,
    "side_weekly_hours": null,
    "allocation_mode": "percent",
    "needs_percent": "50.00",
    "wants_percent": "30.00",
    "savings_percent": "20.00",
    "needs_amount": null,
    "wants_amount": null,
    "savings_amount": null
  }
}
```

### 8.2 Upsert Budget Settings
`PUT /me/budget-settings`

Request (percent mode):
```json
{
  "effective_month": "2026-06",
  "monthly_income": "6200.00",
  "income_source_type": "monthly",
  "primary_monthly_income": "6200.00",
  "primary_hourly_rate": null,
  "primary_weekly_hours": null,
  "side_income_type": "none",
  "side_income_label": null,
  "side_monthly_income": null,
  "side_hourly_rate": null,
  "side_weekly_hours": null,
  "allocation_mode": "percent",
  "needs_percent": "50.00",
  "wants_percent": "30.00",
  "savings_percent": "20.00"
}
```

Request (amount mode):
```json
{
  "monthly_income": "6200.00",
  "income_source_type": "hourly",
  "primary_monthly_income": null,
  "primary_hourly_rate": "30.00",
  "primary_weekly_hours": "40.00",
  "side_income_type": "monthly",
  "side_income_label": "Tutoring",
  "side_monthly_income": "1000.00",
  "side_hourly_rate": null,
  "side_weekly_hours": null,
  "allocation_mode": "amount",
  "needs_amount": "3100.00",
  "wants_amount": "1860.00",
  "savings_amount": "1240.00"
}
```

Validation:
- `effective_month` is optional and uses `YYYY-MM`; when omitted, the backend uses the current UTC month.
- Income breakdown fields are optional for backward compatibility. If omitted, `monthly_income` is treated as the primary monthly income with no side income.
- `hourly` income is converted with `hourly_rate * weekly_hours * 52 / 12`.
- When income breakdown fields are present, they must compute to `monthly_income` after rounding to cents.
- `percent` mode must total `100.00`.
- `amount` mode must total `monthly_income`.

Versioning rules:
- `budget_settings_versions` is the source of truth for month-aware budget resolution.
- For a requested month, the backend selects the latest budget version where `effective_month <= requested month`.
- Saving a budget for a selected month creates or replaces that month's version.
- A saved version applies from its effective month forward until superseded by a later version.
- Editing an inherited month creates a new version for the selected month; it does not mutate the inherited source version.
- `budget_settings` remains as a compatibility/current-settings bridge during rollout.

## 9) Transactions (Expenses)

`transaction` fields:
- `id`
- `date` (required)
- `expense` (required free text)
- `amount` (required)
- `category` (required enum)
- `is_split` (optional boolean, default `false`)
- `tag_id` (required)
- `card_id` (optional)
- `created_at`, `updated_at`

### 9.1 Create Transaction
`POST /me/transactions`

Request (existing tag/card):
```json
{
  "date": "2026-03-04",
  "expense": "Trader Joe's",
  "amount": "72.43",
  "category": "needs",
  "is_split": false,
  "tag_id": "12",
  "card_id": "4"
}
```

Request (Notion-style inline create for tag and optional card):
```json
{
  "date": "2026-03-04",
  "expense": "Coffee",
  "amount": "5.25",
  "category": "wants",
  "is_split": true,
  "tag": { "name": "Coffee Shops" },
  "card": { "name": "Amex Gold" }
}
```

Rules:
- `tag_id` or `tag.name` required.
- `card_id` or `card.name` optional.
- When inline name does not exist, backend creates it and links it.

### 9.2 Update Transaction
`PATCH /me/transactions/{transaction_id}`

Any field from create can be updated.

### 9.3 Delete Transaction
`DELETE /me/transactions/{transaction_id}`

### 9.4 List Transactions (Range + Presets + Multi-Filters)
`GET /me/transactions`

Query params:
- `date_from=2026-03-01`
- `date_to=2026-03-31`
- `preset=last_7_days|last_30_days|month_to_date|last_month|quarter_to_date`
- `q=rent`
- `categories=needs,wants`
- `tag_ids=1,2`
- `card_ids=1,4`
- `is_split=split|not_split`
- `page=1`
- `page_size=50`
- `sort=date_desc|date_asc`

Rules:
- Custom range: provide `date_from` + `date_to`.
- Preset and custom range cannot be used together.
- Filters are AND-ed together.
- Within one filter type, values are OR-ed.
- `q` matches `expense`, `tag.name`, and `card.name`.
- `summary` is calculated across the full filtered result set, not just the returned page.

Response `200`:
```json
{
  "items": [
    {
      "id": "txn_1",
      "date": "2026-03-04",
      "expense": "Trader Joe's",
      "amount": "72.43",
      "category": "needs",
      "is_split": false,
      "tag": { "id": "12", "name": "Groceries" },
      "card": { "id": "4", "name": "Chase Sapphire" }
    }
  ],
  "page": 1,
  "page_size": 50,
  "total_items": 132,
  "summary": {
    "total_spent": "4821.30",
    "count": 132,
    "avg_transaction": "36.52",
    "split_count": 7
  }
}
```

### 9.5 Transaction Suggestions
`GET /me/transactions/suggestions`

Query params:
- `q=trader` (required, 2-80 characters)
- `limit=5` (optional, 1-10, default `5`)

Rules:
- Suggestions are derived from the authenticated user's prior non-deleted transactions.
- Matching checks normalized expense text and prefers exact matches, then prefix matches, then contains matches.
- Suggested category, tag, card, and split state come from the most common prior setup, with recency as the tiebreaker.
- Inactive or deleted tags/cards are not returned.
- No AI, templates, or new merchant table are used for v1 suggestions.

Response `200`:
```json
{
  "items": [
    {
      "expense": "Trader Joe's",
      "category": "needs",
      "tag": {
        "id": "12",
        "name": "Groceries",
        "icon_key": "shopping_cart"
      },
      "card": {
        "id": "4",
        "name": "Chase Sapphire"
      },
      "is_split": false,
      "confidence": "high",
      "last_used_at": "2026-05-22",
      "usage_count": 8
    }
  ]
}
```

## 10) Metrics

### 9.1 Tag Spend Metrics (Monthly)
`GET /me/metrics/tags`

Query params:
- `month=2026-03`

Response:
```json
{
  "month": "2026-03",
  "total_spend": "2400.00",
  "tags": [
    {
      "tag_id": "12",
      "tag_name": "Groceries",
      "icon_key": "shopping_cart",
      "spend": "640.00",
      "percent_of_monthly_spend": "26.67"
    }
  ]
}
```

### 9.2 Category Budget vs Actual (Monthly)
`GET /me/metrics/categories`

Query params:
- `month=2026-03`

Response:
```json
{
  "month": "2026-03",
  "monthly_income": "6200.00",
  "categories": [
    {
      "category": "needs",
      "budget_amount": "3100.00",
      "actual_spend": "2800.00",
      "percent_used": "90.32"
    },
    {
      "category": "wants",
      "budget_amount": "1860.00",
      "actual_spend": "2100.00",
      "percent_used": "112.90"
    },
    {
      "category": "savings",
      "budget_amount": "1240.00",
      "actual_spend": "900.00",
      "percent_used": "72.58"
    }
  ]
}
```

### 9.3 Dashboard Summary (Monthly)
`GET /me/dashboard`

Query params:
- `month=2026-03`

Response:
```json
{
  "month": "2026-03",
  "category_metrics": {
    "month": "2026-03",
    "monthly_income": "6200.00",
    "categories": [
      {
        "category": "needs",
        "budget_amount": "3100.00",
        "actual_spend": "2800.00",
        "percent_used": "90.32"
      }
    ]
  },
  "tag_metrics": {
    "month": "2026-03",
    "total_spend": "2400.00",
    "tags": [
      {
        "tag_id": "12",
        "tag_name": "Groceries",
        "icon_key": "shopping_cart",
        "spend": "640.00",
        "percent_of_monthly_spend": "26.67"
      }
    ]
  },
  "recent_transactions": [
    {
      "id": "401",
      "date": "2026-03-25",
      "expense": "Trader Joe's",
      "amount": "84.13",
      "category": "needs",
      "is_split": false,
      "tag": { "id": "12", "name": "Groceries", "icon_key": "shopping_cart" },
      "card": { "id": "4", "name": "Chase Sapphire" },
      "created_at": "2026-03-25T20:14:00Z",
      "updated_at": "2026-03-25T20:14:00Z"
    }
  ]
}
```

Rules:
- Uses the same month input and recurring-generation semantics as the monthly metrics endpoints.
- Budget targets resolve from the budget version effective for the requested month.
- Returns the exact data needed by the current homepage in one response.

### 9.4 Insights Aggregation (Date Range)
`GET /me/metrics/insights`

Query params:
- `date_from=2025-01-01`
- `date_to=2025-12-31`

Rules:
- `date_from` and `date_to` are both required.
- Date format is `YYYY-MM-DD`.
- Recurring expenses are generated for months in range before aggregation.
- Budget-vs-actual targets resolve each month independently and then sum the monthly targets across the range.

Response:
```json
{
  "date_from": "2025-01-01",
  "date_to": "2025-12-31",
  "months_in_range": 12,
  "total_spend": "28600.35",
  "total_transactions": 314,
  "monthly_spend_trend": [
    { "month": "2025-01", "total_spend": "2100.00" },
    { "month": "2025-02", "total_spend": "2350.00" }
  ],
  "category_breakdown": [
    { "category": "needs", "spend": "14872.18", "percent_of_total_spend": "52.00" },
    { "category": "wants", "spend": "8580.11", "percent_of_total_spend": "30.00" },
    { "category": "savings", "spend": "5148.06", "percent_of_total_spend": "18.00" }
  ],
  "category_budget_vs_actual": [
    { "category": "needs", "budget_amount": "18600.00", "actual_spend": "14872.18", "percent_used": "79.96" }
  ],
  "tag_breakdown": [
    { "tag_id": "12", "tag_name": "Groceries", "icon_key": "shopping_cart", "spend": "3200.00", "percent_of_total_spend": "11.19" }
  ],
  "day_of_week_spend": [
    { "day": "monday", "avg_spend": "120.00", "total_spend": "4300.00", "transactions_count": 36 }
  ],
  "largest_transactions": [
    {
      "transaction_id": "901",
      "date": "2025-04-01",
      "expense": "Rent",
      "amount": "1800.00",
      "category": "needs",
      "is_split": false,
      "tag": { "id": "2", "name": "Housing", "icon_key": "home" },
      "card_name": "Chase Sapphire"
    }
  ],
  "recurring_vs_variable": {
    "recurring": "1500.00",
    "variable": "900.00",
    "recurring_percent": "62.50",
    "variable_percent": "37.50"
  }
}
```

## 11) CSV Export

### 11.1 Export Transactions CSV
`GET /me/transactions/export.csv`

Supports the same filters as `GET /me/transactions`.
Includes `is_split` as a CSV column (`true|false`) for round-trip imports.
The response streams rows as CSV instead of buffering the full export first.
Exported cells are escaped when they start with spreadsheet formula prefixes (`=`, `+`, `-`, `@`, tab, carriage return, or leading whitespace before a formula prefix).

Examples:
- Month: `/me/transactions/export.csv?preset=last_month`
- Quarter: `/me/transactions/export.csv?preset=quarter_to_date`
- Custom: `/me/transactions/export.csv?date_from=2026-01-01&date_to=2026-03-31&tag_ids=12`

Response:
- `200 text/csv` file download.

## 12) CSV Import

### 12.1 Import Transactions CSV
`POST /me/transactions/import.csv`

Request:
- `multipart/form-data`
- field `file`: csv file
- field `mode`: `preview | dry_run | commit`
- field `mapping`: JSON object required for `dry_run` and `commit`; maps Budget fields to CSV headers.
- field `date_strategy`: JSON object for `dry_run` and `commit`; applies an explicit year to dates that omit one.
- field `category_strategy`: JSON object for `dry_run` and `commit`; controls how external category labels become Budget categories.
- field `tag_strategy`: JSON object for `dry_run` and `commit`; maps imported tag values to existing or new tags.
- field `amount_strategy`: JSON object for `dry_run` and `commit`; controls blank mapped amount handling.

Mapping shape:
```json
{
  "date": "Posted Date",
  "expense": "Description",
  "amount": "Amount",
  "category": "Budget Category",
  "tag": "Tag",
  "card": "Account",
  "is_split": "Split"
}
```

Required mapped fields: `date`, `expense`, `amount`, and `tag`. `category` is required only for `category_strategy.mode=exact_column`. Optional mapped fields are `card` and `is_split`.

When `category_strategy.mode` is `value_map` or `default`, `category` may be omitted from `mapping`. Budget categories stay fixed as `needs`, `wants`, and `savings`; external category labels are translated, not created. Debt-like labels such as `Debt`, `Loan`, or `Credit Card Payment` should map to `needs`, with the tag mapped to `Debt`.

Category strategy examples:
```json
{ "mode": "exact_column" }
```

```json
{
  "mode": "value_map",
  "source_header": "Bank Category Guess",
  "value_map": {
    "Utilities": "needs",
    "Credit Card Payment": "needs",
    "Dining": "wants",
    "Savings": "savings"
  }
}
```

```json
{ "mode": "default", "default_category": "needs" }
```

Amount strategy example:
```json
{ "blank_mapped_amount": "skip" }
```

Date strategy example:
```json
{ "missing_year": "apply_year", "year": 2026 }
```

Tag strategy example:
```json
{
  "mode": "value_map",
  "value_map": {
    "Dining": { "mode": "existing", "tag_id": "12" },
    "Utilities": { "mode": "new", "name": "Utilities" }
  }
}
```

Rules:
- `preview` validates the upload envelope and returns headers, sample rows, date profiles, column profiles, inferred mapping, row count, and limits without writing.
- `dry_run` validates rows using the provided mapping and returns planned new tags/cards without writing.
- `commit` validates the file with the same mapping, then writes valid rows. Oversized files or too many rows are rejected before any transaction rows are inserted.
- Import limits are configurable with `CSV_IMPORT_MAX_BYTES` (default `5242880`), `CSV_IMPORT_MAX_ROWS` (default `5000`), and `CSV_IMPORT_MAX_ERRORS` (default `100`).
- Optional mapped `is_split` values support `true|false|1|0|yes|no` and default to `false` when absent.
- Duplicate detection key (per user): `date + amount + normalized_expense + category + is_split + tag + card`.
- Full dates support `YYYY-MM-DD`, `M/D/YYYY`, and `MM/DD/YYYY`. Yearless dates such as `03/12` require `date_strategy.missing_year=apply_year`.
- `tag_strategy` must map every unique nonblank source tag value to either an existing active tag owned by the authenticated user or a new tag name.
- If `amount_strategy.blank_mapped_amount` is `skip`, rows with a blank mapped amount are counted in `skipped_rows` and `skipped_blank_amount_rows`. They are not imported, duplicates, or validation errors. Nonblank invalid amounts still fail validation.
- `category_strategy.source_header` may use the same CSV header as another mapped field such as `tag`.
- Missing value-map entries fail row validation with a clear category error.
- Row-level errors are capped in the response. `errors_truncated=true` means additional rows failed but were not returned.
- Missing tags/cards are created only during `commit`. New tags receive an inferred `icon_key`; cards remain name-only.

Preview response `200`:
```json
{
  "mode": "preview",
  "headers": ["Posted Date", "Description", "Amount", "Budget Category", "Tag", "Account"],
  "sample_rows": [
    {
      "Posted Date": "2026-06-01",
      "Description": "Coffee Shop",
      "Amount": "6.25",
      "Budget Category": "wants",
      "Tag": "Coffee",
      "Account": "Amex Gold"
    }
  ],
  "column_profiles": [
    {
      "header": "Bank Category Guess",
      "blank_count": 0,
      "unique_values_truncated": false,
      "unique_values": [
        { "value": "Utilities", "count": 8 },
        { "value": "Dining", "count": 6 }
      ]
    }
  ],
  "date_profiles": [
    {
      "header": "Transaction Date",
      "full_date_count": 84,
      "yearless_date_count": 11,
      "yearless_examples": ["03/12", "01/21"],
      "invalid_examples": []
    }
  ],
  "suggested_mapping": {
    "date": "Posted Date",
    "expense": "Description",
    "amount": "Amount",
    "category": "Budget Category",
    "tag": "Tag",
    "card": "Account"
  },
  "total_rows": 120,
  "limits": {
    "max_bytes": 5242880,
    "max_rows": 5000,
    "max_returned_errors": 100
  }
}
```

Dry-run/commit response `200`:
```json
{
  "status": "partial",
  "message": "Validated 110 row(s), but 3 row(s) failed validation.",
  "mode": "dry_run",
  "total_rows": 120,
  "valid_rows": 110,
  "imported_rows": 0,
  "duplicate_rows": 7,
  "invalid_rows": 3,
  "skipped_rows": 2,
  "skipped_blank_amount_rows": 2,
  "errors_truncated": false,
  "max_returned_errors": 100,
  "errors": [
    {
      "row": 14,
      "field": "category",
      "message": "must be one of needs,wants,savings"
    }
  ],
  "new_tags": [
    { "name": "Coffee", "icon_key": "coffee" }
  ],
  "new_cards": [
    { "name": "Amex Gold" }
  ]
}
```

## 13) Data Runs

### 13.1 List Recent Data Runs
`GET /me/data-runs?limit=50`

Returns recent committed CSV imports and CSV exports for the authenticated user. Dry-run imports are recorded internally but excluded from this activity list. Export records are activity-only and do not include saved CSV files or re-download links.

Query:
- `limit`: optional integer, default `50`, clamped to `1..100`.

Response `200`:
```json
{
  "items": [
    {
      "id": "import_123",
      "type": "import",
      "status": "completed",
      "created_at": "2026-05-29 12:00:00",
      "source_filename": "transactions.csv",
      "date_from": null,
      "date_to": null,
      "total_rows": 120,
      "valid_rows": 120,
      "imported_rows": 118,
      "duplicate_rows": 2,
      "invalid_rows": 0,
      "skipped_rows": 0,
      "skipped_blank_amount_rows": 0,
      "error_summary": null,
      "rollback_available": true,
      "rolled_back_at": null,
      "rolled_back_rows": 0,
      "rollback_unavailable_reason": null
    },
    {
      "id": "export_45",
      "type": "export",
      "status": "completed",
      "created_at": "2026-05-29 12:05:00",
      "source_filename": null,
      "date_from": "2026-01-01",
      "date_to": "2026-03-31",
      "total_rows": 240,
      "valid_rows": null,
      "imported_rows": null,
      "duplicate_rows": null,
      "invalid_rows": null,
      "skipped_rows": null,
      "skipped_blank_amount_rows": null,
      "error_summary": null,
      "rollback_available": false,
      "rolled_back_at": null,
      "rolled_back_rows": 0,
      "rollback_unavailable_reason": null
    }
  ]
}
```

### 13.2 Roll Back Imported Transactions
`DELETE /me/imports/{import_run_id}/transactions`

Soft-deletes the non-deleted transactions created by a committed CSV import. Tags and cards are not deleted.

Rules:
- Available only for imports committed after transaction rows started storing `csv_import_run_id`.
- Old imports return `409 ROLLBACK_UNAVAILABLE` with message `Rollback unavailable for imports before this feature.`
- Already rolled-back imports return the existing rolled-back result.
- Users can only roll back their own import runs.

Response `200`:
```json
{
  "status": "rolled_back",
  "import_run_id": "123",
  "deleted_rows": 118
}
```

## 14) Standard Errors

Error shape:
```json
{
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "Request validation failed",
    "details": [
      { "field": "date", "message": "is required" }
    ]
  }
}
```

Common codes:
- `UNAUTHENTICATED` (`401`)
- `FORBIDDEN` (`403`)
- `NOT_FOUND` (`404`)
- `CONFLICT` (`409`)
- `VALIDATION_ERROR` (`422`)
- `RATE_LIMITED` (`429`)
- `INTERNAL_ERROR` (`500`)

## 15) Rate Limits

Rate limited endpoints return `429 RATE_LIMITED`.

Public auth flows are limited by client address:
- `POST /auth/sessions/password`
- `POST /auth/sessions/google`
- `POST /auth/invitations/accept-password`
- `POST /auth/invitations/accept-google`
- `POST /auth/password-reset/request`
- `POST /auth/password-reset/confirm`

Sensitive authenticated flows are limited by credential/session identity plus a coarse client-address bucket:
- `POST /auth/invitations`
- `POST /me/master-api-keys`
- `DELETE /me/master-api-keys/{api_key_id}`
- `POST /me/transactions/import.csv`
- `DELETE /me/imports/{import_run_id}/transactions`
- `GET /me/transactions/export.csv`
- `GET /me/metrics/tags`
- `GET /me/metrics/categories`
- `GET /me/dashboard`
- `GET /me/metrics/insights`
- `PATCH /me`
- `PATCH /me/preferences`
- `POST /me/email-change/request`
- `POST /me/email-change/verify`
- `POST /me/auth/convert-google`

Rate limit defaults are configured with `RATE_LIMIT_*` environment variables in `.env.example`.

## 16) Authorization Rules
- All `/me/*` resources are scoped to the authenticated user only.
- Users can never access another user’s tags, cards, transactions, metrics, imports, or exports.
- Only owners can create/list invites.
- Only owner/admin can generate/list/revoke master API keys.
- Only owner/admin can list audit logs.
- Master API key auth can call protected routes for the key owner, except `/me/master-api-keys*` management routes.

## 16) Non-Goals (v1)
- Public self-signup
- Bank aggregation integrations
- Shared household budgets
- Native iOS-specific endpoints (same contract, different session transport)
