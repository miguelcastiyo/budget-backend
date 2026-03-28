# Budget API v1 Contract (Session-Based)

This contract is derived from `project_info.md` and is intended for a PHP + MySQL backend.

## 1) API Conventions
- Base path: `/api/v1`
- Content type: `application/json` (except multipart import and CSV export download)
- Date format: `YYYY-MM-DD`
- Money format: decimal string with 2 places (example: `"123.45"`)
- Timezone: store UTC, return ISO-8601 timestamps

### Fixed Category Enum
- `needs`
- `wants`
- `savings_debts`

Categories are fixed in v1 (not user-editable).

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

Auth required: inviter must be owner/admin.

Request:
```json
{
  "email": "newuser@example.com",
  "auth_method": "google_or_password",
  "expires_in_days": 7
}
```

Response `201`:
```json
{
  "invite_id": "inv_123",
  "email": "newuser@example.com",
  "status": "pending",
  "expires_at": "2026-03-12T00:00:00Z"
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
    "onboarding_complete": true
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

### 3.5 Sign Out
`DELETE /auth/sessions/current`

Invalidates current session.

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
  "onboarding_complete": true
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

### 4.3 Request Email Change (Password Users Only)
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

### 4.4 Verify Email Change
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

### 4.5 Convert Password Account To Google Sign-In
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
  "onboarding_complete": true
}
```

### 4.6 List Master API Keys
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
      "expires_at": null
    }
  ]
}
```

### 4.6 Create Master API Key
`POST /me/master-api-keys`

Auth required: session auth only.

Request:
```json
{
  "name": "local-postman",
  "expires_at": null
}
```

Response `201`:
```json
{
  "id": "mak_123",
  "name": "local-postman",
  "api_key": "bgtm_live_2M4...full_secret...",
  "key_prefix": "bgtm_live_2M4x",
  "created_at": "2026-03-05T19:12:00Z",
  "expires_at": null
}
```

### 4.7 Revoke Master API Key
`DELETE /me/master-api-keys/{api_key_id}`

Auth required: session auth only.

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

### 5.3 Update Tag
`PATCH /me/tags/{tag_id}`

Request:
```json
{
  "name": "Dining Out",
  "icon_key": "coffee"
}
```

### 5.4 Delete Tag
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

### 8.2 Upsert Budget Settings
`PUT /me/budget-settings`

Request (percent mode):
```json
{
  "monthly_income": "6200.00",
  "allocation_mode": "percent",
  "needs_percent": "50.00",
  "wants_percent": "30.00",
  "savings_debts_percent": "20.00"
}
```

Request (amount mode):
```json
{
  "monthly_income": "6200.00",
  "allocation_mode": "amount",
  "needs_amount": "3100.00",
  "wants_amount": "1860.00",
  "savings_debts_amount": "1240.00"
}
```

Validation:
- `percent` mode must total `100.00`.
- `amount` mode must total `monthly_income`.

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
  "total_items": 132
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
      "category": "savings_debts",
      "budget_amount": "1240.00",
      "actual_spend": "900.00",
      "percent_used": "72.58"
    }
  ]
}
```

### 9.3 Insights Aggregation (Date Range)
`GET /me/metrics/insights`

Query params:
- `date_from=2025-01-01`
- `date_to=2025-12-31`

Rules:
- `date_from` and `date_to` are both required.
- Date format is `YYYY-MM-DD`.
- Recurring expenses are generated for months in range before aggregation.

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
    { "category": "savings_debts", "spend": "5148.06", "percent_of_total_spend": "18.00" }
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

### 10.1 Export Transactions CSV
`GET /me/transactions/export.csv`

Supports the same filters as `GET /me/transactions`.
Includes `is_split` as a CSV column (`true|false`) for round-trip imports.

Examples:
- Month: `/me/transactions/export.csv?preset=last_month`
- Quarter: `/me/transactions/export.csv?preset=quarter_to_date`
- Custom: `/me/transactions/export.csv?date_from=2026-01-01&date_to=2026-03-31&tag_ids=12`

Response:
- `200 text/csv` file download.

## 12) CSV Import

### 11.1 Import Transactions CSV
`POST /me/transactions/import.csv`

Request:
- `multipart/form-data`
- field `file`: csv file
- field `mode`: `dry_run | commit`

Rules:
- `dry_run` validates and returns parse/mapping results without writing.
- `commit` writes valid rows.
- Optional `is_split` CSV column is supported (`true|false|1|0|yes|no`), defaults to `false` when absent.
- Duplicate detection key (per user): `date + amount + normalized_expense + category + is_split + tag + card`.

Response `200`:
```json
{
  "mode": "dry_run",
  "total_rows": 120,
  "valid_rows": 110,
  "imported_rows": 0,
  "duplicate_rows": 7,
  "invalid_rows": 3,
  "errors": [
    {
      "row": 14,
      "field": "category",
      "message": "must be one of needs,wants,savings_debts"
    }
  ]
}
```

## 13) Standard Errors

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

## 14) Authorization Rules
- All `/me/*` resources are scoped to the authenticated user only.
- Users can never access another user’s tags, cards, transactions, metrics, imports, or exports.
- Only owner/admin can create invites.
- Only owner/admin can generate/list/revoke master API keys.
- Master API key auth can call protected routes for the key owner, except `/me/master-api-keys*` management routes.

## 15) Non-Goals (v1)
- Public self-signup
- Bank aggregation integrations
- Shared household budgets
- Native iOS-specific endpoints (same contract, different session transport)
