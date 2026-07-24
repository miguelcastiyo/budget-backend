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

Frontend requirement:
- Invite links must enter an invite-acceptance flow, not a plain sign-in form.
- If the frontend receives an `invite_token`, it must preserve that token through the acceptance flow and keep the user in invite mode until account creation succeeds or the invite is rejected as invalid/expired.
- The acceptance UI must present two explicit choices when appropriate:
- `Continue with Google` -> `POST /auth/invitations/accept-google`
- `Set password` -> `POST /auth/invitations/accept-password`
- New invitees must not be asked to sign in with an existing password before they have completed password setup.
- The password acceptance form should prefill and lock the invited email, collect `display_name`, `password`, and `client_type`, and clearly communicate that submitting the form creates the account and signs the user in.
- The invite acceptance experience should reuse the main auth screen's visual language and be optimized for narrow mobile viewports and iOS installed-web-app behavior.

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

Revoked invites remain visible for account history and can no longer be accepted.

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

### 3.1.2 Revoke Invite
`DELETE /auth/invitations/{invite_id}`

Auth required: user must be owner.

Behavior:
- Only pending, non-expired invites can be revoked.
- Revoked invites remain visible in list results with `status: "revoked"`.
- Revoked invite links stop working and follow the existing inactive invite failure path.

Response `204`:
- No body

Response `404`:
- Invite does not exist or is no longer revocable because it was already accepted, expired, or revoked.

### 3.1.3 Preview Invite Acceptance Path
`GET /auth/invitations/preview?invite_token=...`

Public route used by invite-acceptance clients before rendering the setup UI.

Frontend behavior:
- Call this route as soon as an invite link is opened.
- Use `preferred_auth_provider` as the initial branch selector for the invite screen.
- Classification rule is intentionally simple and centralized here:
- invited `gmail.com` / `googlemail.com` addresses return `preferred_auth_provider: "google"`
- every other address returns `preferred_auth_provider: "password"`
- Frontends may still offer the other branch as a secondary fallback, but should not mix both paths equally by default.

Response `200`:
```json
{
  "invite_id": "inv_123",
  "invitee_name": "New User",
  "email": "new.user@gmail.com",
  "preferred_auth_provider": "google"
}
```

### 3.2 Accept Invite with Email/Password
`POST /auth/invitations/accept-password`

Frontend behavior:
- This route is the required account-creation path for invited users who are not completing onboarding with Google.
- It is not a sign-in route and should be presented as password setup / account creation in the UI.

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
      },
      "onboarding": {
        "dismissed": false
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

Frontend behavior:
- When an invite token is present, Google continuation must use this invite-accept route rather than the normal Google sign-in route.
- The UI should preserve the same invite context and visual treatment used by the password acceptance branch.

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
    },
    "onboarding": {
      "dismissed": false
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
  },
  "onboarding": {
    "dismissed": false
  }
}
```

Notes:
- Preferences are stored at the account level on the `users` record.
- Unknown or invalid preference keys are rejected by the backend.
- Currently supported:
  - `appearance.theme`: `light | dark | system`
  - `onboarding.dismissed`: `true | false`

### 4.4 Update User Preferences
`PATCH /me/preferences`

Request:
```json
{
  "appearance": {
    "theme": "dark"
  },
  "onboarding": {
    "dismissed": true
  }
}
```

Response `200`:
```json
{
  "appearance": {
    "theme": "dark"
  },
  "onboarding": {
    "dismissed": true
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

### 4.6 Setup Status
`GET /me/setup-status`

Purpose:
- Returns the first-run setup state used by onboarding and the dashboard checklist.
- Keeps setup heuristics out of the frontend.

Response `200`:
```json
{
  "budget_profile_complete": true,
  "has_transactions": false,
  "has_recurring_expenses": false,
  "has_imported_data": false,
  "first_transaction_added": false,
  "first_recurring_expense_added": false,
  "first_import_completed": false,
  "onboarding_dismissed": false,
  "recommended_next_action": "add_first_transaction",
  "setup_tasks": [
    {
      "key": "add_first_transaction",
      "label": "Add your first transaction",
      "status": "available",
      "completed": false
    },
    {
      "key": "add_recurring_expenses",
      "label": "Add fixed monthly bills",
      "status": "available",
      "completed": false
    },
    {
      "key": "import_transactions",
      "label": "Import past transactions",
      "status": "available",
      "completed": false
    }
  ]
}
```

### 4.7 Update Onboarding State
`PATCH /me/onboarding-state`

Request:
```json
{
  "onboarding_dismissed": true
}
```

Response `200`:
```json
{
  "onboarding_dismissed": true
}
```

### 4.8 Request Email Change (Password Users Only)
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

### 4.9 Verify Email Change
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

### 4.10 Convert Password Account To Google Sign-In
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
    },
    "onboarding": {
      "dismissed": false
    }
  }
}
```

### 4.11 List Master API Keys
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

Returns active, non-deleted cards ordered by:
- `is_favorite DESC`
- `LOWER(name) ASC`
- `id ASC`

Example response:
```json
{
  "items": [
    {
      "id": "4",
      "name": "Apple Card",
      "is_favorite": true
    },
    {
      "id": "7",
      "name": "Chase Sapphire",
      "is_favorite": false
    }
  ]
}
```

### 6.2 Create Card
`POST /me/cards`

Request:
```json
{
  "name": "Chase Sapphire"
}
```

Response:
```json
{
  "id": "7",
  "name": "Chase Sapphire",
  "is_favorite": false
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

Favorite card:
```json
{
  "is_favorite": true
}
```

Clear favorite:
```json
{
  "is_favorite": false
}
```

Rename and favorite:
```json
{
  "name": "Chase Sapphire Reserve",
  "is_favorite": true
}
```

### 6.4 Delete Card
`DELETE /me/cards/{card_id}`

Rules:
- Card names are unique per user (case-insensitive).
- Soft delete recommended in DB.
- Only one active card per user may have `is_favorite = true`.
- Favoriting one card clears favorite status from any other active favorite card for that user.
- Clearing favorite uses `is_favorite: false` on the target card.

## 7) Contexts

Contexts are optional, user-owned transaction dimensions such as `Chicago 2/26` or `New Apartment`. They are independent of categories, tags, and cards. Contexts support the same optional icon keys and automatic name-based fallback used by tags.

### 7.1 List Contexts
`GET /me/contexts`

Returns active, non-deleted contexts ordered by name.

### 7.2 Create Context
`POST /me/contexts`

Request: `{ "name": "Chicago 2/26", "icon_key": "home" }`

Names are trimmed, required, limited to 120 characters, and unique per user. Creating a previously deleted name reactivates the same row; an active duplicate returns `409 CONFLICT`.

### 7.3 Update Context
`PATCH /me/contexts/{context_id}`

Renaming and optional `icon_key` updates are supported. The context must belong to the authenticated user and be active. `icon_key` may be `null` to restore automatic name-based icon selection. Allowed values are the same as tag icons: `home`, `shopping_cart`, `car`, `plane`, `receipt`, `coffee`, `smartphone`, `credit_card`, `piggy_bank`, `trending_up`, `briefcase`, `heart`, `dumbbell`, `book_open`, `film`, `gamepad`, `gift`, `shield`, `lightbulb`, `wrench`, `wallet`, and `tag`.

### 7.4 Delete Context
`DELETE /me/contexts/{context_id}`

Deletion is soft; existing transaction relationships remain available for historical responses.

## 8) Recurring Expenses

Recurring rules are used for committed monthly expenses (rent, subscriptions, insurance, etc.).

Generation behavior:
- Rules are materialized into normal transactions once per month.
- Generation is month-based (not due-date-triggered) so committed spend appears early in the dashboard.
- Transaction date is set to the rule's billing date for that month.
- `generated_for_month` indicates that the occurrence was materialized into the transaction ledger for the requested month. It does not indicate that the billing date has passed or that an external payment has posted.
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
      "series_id": "rser_1",
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

The backend creates `series_id` automatically. Clients do not send it during normal recurring creation.

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

Recurring expense versions in the same `series_id` may not overlap by month window.

### 7.4 Delete Recurring Expense
`DELETE /me/recurring-expenses/{recurring_expense_id}`

### 7.5 Get Recurring Expense Series History
`GET /me/recurring-expenses/{recurring_expense_id}/series`

Response:
```json
{
  "series_id": "rser_abc123",
  "items": [
    {
      "id": "123",
      "series_id": "rser_abc123",
      "expense": "AMC A-List",
      "amount": "27.99",
      "category": "wants",
      "tag": { "id": "12", "name": "Entertainment", "icon_key": "film" },
      "card": { "id": "4", "name": "Apple Card" },
      "billing_type": "day_of_month",
      "billing_day": 7,
      "projected_date_for_month": "2026-06-07",
      "starts_month": "2025-11",
      "ends_month": "2026-06",
      "is_active": true,
      "generated_for_month": true,
      "created_at": "2026-06-01T00:00:00Z",
      "updated_at": "2026-06-01T00:00:00Z"
    },
    {
      "id": "456",
      "series_id": "rser_abc123",
      "expense": "AMC A-List",
      "amount": "29.99",
      "category": "wants",
      "tag": { "id": "12", "name": "Entertainment", "icon_key": "film" },
      "card": { "id": "4", "name": "Apple Card" },
      "billing_type": "day_of_month",
      "billing_day": 7,
      "projected_date_for_month": "2026-06-07",
      "starts_month": "2026-07",
      "ends_month": null,
      "is_active": true,
      "generated_for_month": false,
      "created_at": "2026-06-16T00:00:00Z",
      "updated_at": "2026-06-16T00:00:00Z"
    }
  ]
}
```

### 7.6 Schedule Recurring Expense Change
`POST /me/recurring-expenses/{recurring_expense_id}/schedule-change`

Request:
```json
{
  "effective_month": "2026-07",
  "amount": "29.99",
  "generated_transaction_action": "reject"
}
```

Rules:
- `effective_month` is required and must be `YYYY-MM`.
- At least one change field is required. Current implementation supports amount, category, tag, card, and billing schedule fields.
- `effective_month` must be after the source row `starts_month`.
- If the source row has `ends_month`, `effective_month` must be `<= ends_month`.
- The source recurring expense must be active and owned by the caller.
- If the effective month already has a generated occurrence for the source recurring expense and `generated_transaction_action=reject` (default), the endpoint returns `409 CONFLICT`.
- `generated_transaction_action=update_linked_transaction` is reserved for future support and currently validates but is rejected by the service with a validation error.

Response:
```json
{
  "status": "scheduled",
  "series_id": "rser_abc123",
  "ended_rule": {
    "id": "123",
    "series_id": "rser_abc123",
    "expense": "AMC A-List",
    "amount": "27.99",
    "category": "wants",
    "tag": { "id": "12", "name": "Entertainment", "icon_key": "film" },
    "card": { "id": "4", "name": "Apple Card" },
    "billing_type": "day_of_month",
    "billing_day": 7,
    "projected_date_for_month": "2026-06-07",
    "starts_month": "2025-11",
    "ends_month": "2026-06",
    "is_active": true,
    "generated_for_month": false,
    "created_at": "2026-06-01T00:00:00Z",
    "updated_at": "2026-06-16T00:00:00Z"
  },
  "new_rule": {
    "id": "456",
    "series_id": "rser_abc123",
    "expense": "AMC A-List",
    "amount": "29.99",
    "category": "wants",
    "tag": { "id": "12", "name": "Entertainment", "icon_key": "film" },
    "card": { "id": "4", "name": "Apple Card" },
    "billing_type": "day_of_month",
    "billing_day": 7,
    "projected_date_for_month": "2026-06-07",
    "starts_month": "2026-07",
    "ends_month": null,
    "is_active": true,
    "generated_for_month": false,
    "created_at": "2026-06-16T00:00:00Z",
    "updated_at": "2026-06-16T00:00:00Z"
  },
  "series_items": [
    {
      "id": "123",
      "series_id": "rser_abc123",
      "expense": "AMC A-List",
      "amount": "27.99",
      "category": "wants",
      "tag": { "id": "12", "name": "Entertainment", "icon_key": "film" },
      "card": { "id": "4", "name": "Apple Card" },
      "billing_type": "day_of_month",
      "billing_day": 7,
      "projected_date_for_month": "2026-06-07",
      "starts_month": "2025-11",
      "ends_month": "2026-06",
      "is_active": true,
      "generated_for_month": false,
      "created_at": "2026-06-01T00:00:00Z",
      "updated_at": "2026-06-16T00:00:00Z"
    },
    {
      "id": "456",
      "series_id": "rser_abc123",
      "expense": "AMC A-List",
      "amount": "29.99",
      "category": "wants",
      "tag": { "id": "12", "name": "Entertainment", "icon_key": "film" },
      "card": { "id": "4", "name": "Apple Card" },
      "billing_type": "day_of_month",
      "billing_day": 7,
      "projected_date_for_month": "2026-06-07",
      "starts_month": "2026-07",
      "ends_month": null,
      "is_active": true,
      "generated_for_month": false,
      "created_at": "2026-06-16T00:00:00Z",
      "updated_at": "2026-06-16T00:00:00Z"
    }
  ]
}
```

Rules:
- Delete stops future generation. Existing transactions are unchanged.
- `billing_day` is required only for `billing_type=day_of_month`.
- `starts_month`/`ends_month` use `YYYY-MM` and `ends_month >= starts_month` when present.
- `seed_transaction_id` is optional and can link the current month occurrence to an already-created transaction to avoid duplicates.
- `series_id` groups multiple versions of the same commitment.

## 9) Budget Settings (Monthly Income + 3 Buckets)

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

### 8.3 List Budget Settings Versions
`GET /me/budget-settings/versions`

Returns the authenticated user's budget version timeline in ascending effective-month order.

Response:
```json
{
  "items": [
    {
      "effective_month": "2026-04",
      "applies_from_month": "2026-04",
      "applies_until_month": "2026-05",
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
      "savings_amount": null,
      "resolved_amounts": {
        "needs": "3100.00",
        "wants": "1860.00",
        "savings": "1240.00"
      },
      "created_at": "2026-06-01T10:00:00Z",
      "updated_at": "2026-06-01T10:00:00Z"
    }
  ]
}
```

Notes:
- `applies_until_month` is inclusive and is derived from the next version's effective month.
- The last version in the timeline uses `null` for `applies_until_month`.
- `resolved_amounts` is always present for frontend convenience.

## 10) Transactions (Expenses)

`transaction` fields:
- `id`
- `date` (required)
- `expense` (required free text)
- `amount` (required)
- `category` (required enum)
- `is_split` (optional boolean, default `false`)
- `tag_id` (required)
- `context_id` (optional)
- `card_id` (optional)
- `notes` (`string | null`, optional on write, always present on read)
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
  "context_id": "7",
  "card_id": "4",
  "notes": "Bought snacks for movie night"
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
- `context_id` or `context.name` optional; these forms are mutually exclusive.
- `notes` accepts `string`, `null`, or blank string. Blank and whitespace-only values are stored as `null`.
- `notes` is trimmed and must be 255 characters or fewer.
- When inline name does not exist, backend creates it and links it.
- Inline Context creation reactivates a matching deleted Context and is atomic with the transaction write.

### 9.2 Update Transaction
`PATCH /me/transactions/{transaction_id}`

Any field from create can be updated.
If `notes` is omitted on `PATCH`, the existing value is kept. Sending `null`, `""`, or whitespace clears it.

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
- `context_ids=7,12`
- `is_split=split|not_split`
- `page=1`
- `page_size=50`
- `sort=date_desc|date_asc`

Rules:
- Custom range: provide `date_from` + `date_to`.
- Preset and custom range cannot be used together.
- Filters are AND-ed together.
- Within one filter type, values are OR-ed.
- `q` matches `expense`, `tag.name`, `context.name`, and `card.name`.
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
      "notes": null,
      "tag": { "id": "12", "name": "Groceries" },
      "context": { "id": "7", "name": "Chicago 2/26", "icon_key": "home" },
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

## 11) Metrics

`GET /me/months/{month}/overview` is the primary endpoint for the homepage/month overview UI.
The older monthly metrics endpoints below remain available for compatibility but are deprecated for new frontend work.

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

Deprecated:
- Prefer `GET /me/months/{month}/overview` for homepage and month-level reads.

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

### 9.4 Month Overview
`GET /me/months/{month}/overview`

Path params:
- `month=2026-06`

Rules:
- `month` must be a valid `YYYY-MM` month.
- Authentication required.
- Read-only only; the endpoint does not generate recurring transactions.
- The response includes budget resolution, summary totals, month progress, category budget-vs-actual data, tag spend, recurring summary, recent transactions, and deterministic status cards.

Response:
```json
{
  "month": "2026-06",
  "budget": {
    "monthly_income": "6200.00",
    "resolved_effective_month": "2026-06",
    "is_exact_match": true,
    "has_budget": true
  },
  "summary": {
    "total_spent": "2400.00",
    "total_budget": "6200.00",
    "left_this_month": "3800.00",
    "percent_spent": "38.71"
  },
  "month_progress": {
    "status": "current",
    "days_in_month": 30,
    "day_of_month": 11,
    "days_elapsed": 11,
    "days_remaining": 19,
    "percent_elapsed": "36.67",
    "daily_available_remaining": "200.00",
    "projected_month_end_spend": "6545.45"
  },
  "categories": [
    {
      "category": "needs",
      "budget_amount": "3100.00",
      "actual_spend": "2800.00",
      "remaining_amount": "300.00",
      "percent_used": "90.32",
      "status": "near"
    }
  ],
  "tags": [
    {
      "tag_id": "tag_123",
      "tag_name": "Groceries",
      "icon_key": "cart",
      "spend": "420.00",
      "percent_of_monthly_spend": "17.50"
    }
  ],
  "recurring": {
    "committed_total": "120.00",
    "generated_total": "80.00",
    "upcoming_total": "40.00",
    "generated_count": 2,
    "upcoming_count": 1,
    "items_count": 3
  },
  "recent_transactions": [],
  "status_cards": [
    {
      "id": "month_pace",
      "tone": "warning",
      "title": "Behind pace",
      "value": "38.71% spent",
      "detail": "36.67% through the month."
    }
  ]
}
```

### 9.5 Insights Aggregation (Date Range)
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

## 12) Month Closeouts

Purpose:
- Month closeouts compare the resolved budget plan for a month against actual recorded transactions.
- Closeout records are stored separately from transactions. The backend does not create synthetic closeout transactions.
- A closed month can become stale if transactions or budget settings for that month change later.

Definitions:
- Full result: `planned_total - actual_total`
- Spending-only result: `(planned_needs + planned_wants) - (actual_needs + actual_wants)`

Lifecycle:
- `GET /me/month-closeouts/{month}` returns a computed snapshot plus any saved closeout row.
- `POST /me/month-closeouts/{month}/close` creates or re-closes a month using the current computed snapshot.
- `PATCH /me/month-closeouts/{month}` only updates `notes` and `allocations` against the stored snapshot.
- `POST /me/month-closeouts/{month}/reopen` marks a closed month reopened without deleting the saved snapshot.

Rules:
- Path/query month fields use `YYYY-MM`.
- A month is closeable only when it is before the current app month in `APP_TIMEZONE`.
- If no budget settings resolve for the requested month, reads return `status: "missing_budget"` and close attempts return `422 VALIDATION_ERROR`.
- Balanced closeouts cannot have allocations.
- Allocation sums must not exceed the stored surplus or deficit amount.
- `rollover` allocations require `target_month`; non-rollover allocations must omit it.
- Closed rows may return `is_stale: true` with `stale_reasons`.

### 11.1 Get One Month Closeout
`GET /me/month-closeouts/{month}`

Response states:
- `open`
- `future`
- `missing_budget`
- `ready_to_close`
- `closed`
- `reopened`

Example:
```json
{
  "month": "2026-05",
  "status": "closed",
  "is_closeable": true,
  "computed": {
    "budget_effective_month": "2026-05",
    "budget_allocation_mode": "percent",
    "monthly_income": "4970.00",
    "planned": {
      "needs": "2485.00",
      "wants": "1491.00",
      "savings": "994.00",
      "total": "4970.00"
    },
    "actual": {
      "needs": "2300.00",
      "wants": "1100.00",
      "savings": "900.00",
      "total": "4300.00"
    },
    "result_type": "surplus",
    "surplus_amount": "670.00",
    "deficit_amount": "0.00",
    "spending_surplus_amount": "576.00",
    "spending_deficit_amount": "0.00"
  },
  "closeout": {
    "id": "clo_123",
    "status": "closed",
    "result_type": "surplus",
    "surplus_amount": "670.00",
    "deficit_amount": "0.00",
    "allocated_amount": "500.00",
    "unallocated_amount": "170.00",
    "is_stale": false,
    "stale_reasons": [],
    "closed_at": "2026-06-01T08:40:00Z",
    "reopened_at": null,
    "notes": "Good month.",
    "allocations": []
  }
}
```

### 11.2 List Saved Month Closeouts
`GET /me/month-closeouts?date_from=2026-01&date_to=2026-12`

Filters:
- `date_from=YYYY-MM` optional
- `date_to=YYYY-MM` optional
- `status=closed|reopened` optional

### 11.3 Close Or Re-Close A Month
`POST /me/month-closeouts/{month}/close`

Request:
```json
{
  "notes": "Good month.",
  "allocations": [
    {
      "allocation_type": "savings",
      "label": "HYSA",
      "amount": "500.00"
    },
    {
      "allocation_type": "rollover",
      "label": "June cushion",
      "amount": "170.00",
      "target_month": "2026-06"
    }
  ]
}
```

### 11.4 Update Notes Or Allocations
`PATCH /me/month-closeouts/{month}`

Rules:
- The closeout row must already exist.
- The closeout status must be `closed`.
- The stored closeout math does not change on `PATCH`.

### 11.5 Reopen A Month
`POST /me/month-closeouts/{month}/reopen`

Non-goals:
- Automatic bank transfer tracking for closeouts
- Rollover mutating future month budgets
- Creating normal transactions from closeout allocations

Funds integration:
- Surplus closeouts can allocate to `allocation_type: "fund"` with `fund_id`.
- Fund-backed closeout allocations create fund ledger entries without creating synthetic transactions.
- Replacing closeout allocations voids prior closeout-linked fund entries with `void_reason = allocation_replaced`.
- Reopening a closeout voids active closeout-linked fund entries with `void_reason = closeout_reopened`.

## 13) Funds

Purpose:
- Funds are durable savings goals or envelopes.
- A Fund is a container for intentionally saved money. A goal is an optional property of a Fund and is determined by the presence of `goal_amount`.
- Fund balances come from the `fund_entries` ledger, not a mutable total on the fund row.
- Manual fund-only entries, transaction-linked entries, starting balances, corrections, and closeout-linked entries all contribute to fund progress through the same ledger model.

Endpoints:
- `GET /me/funds`
- `POST /me/funds`
- `GET /me/funds/{fund_id}`
- `PATCH /me/funds/{fund_id}`
- `POST /me/funds/{fund_id}/archive`
- `POST /me/funds/{fund_id}/restore`
- `GET /me/funds/{fund_id}/entries`
- `POST /me/funds/{fund_id}/entries`
- `PATCH /me/funds/{fund_id}/entries/{entry_id}`
- `DELETE /me/funds/{fund_id}/entries/{entry_id}`
- `GET /me/funds/closeout-summary?year=YYYY`

Rules:
- Funds are user-scoped and use public IDs like `fund_...` and `fent_...`.
- `fund_type` is a deprecated compatibility field and is no longer required for new clients.
- `target_month` is only valid when `goal_amount` is present. Clearing `goal_amount` clears goal metadata without affecting balance or contribution history.
- Fund balances exclude entries where `deleted_at IS NOT NULL` or `voided_at IS NOT NULL`.
- Archived funds remain readable and editable, but new entries and closeout allocations require an active fund.
- Transaction-linked contributions require `category = savings`.
- Direct edit or delete is rejected for closeout-linked and transaction-linked fund entries; those must be changed through the source workflow.

Entry modes:
- `budget_tracking = fund_only` creates a fund-only ledger entry and does not affect the monthly budget.
- `budget_tracking = create_transaction` creates a real `savings` transaction and a linked fund entry in one DB transaction.
- `budget_tracking = link_existing_transaction` links an existing qualifying `savings` transaction to a fund entry.

## 14) CSV Export

### 13.1 Export Transactions CSV
`GET /me/transactions/export.csv`

Supports the same filters as `GET /me/transactions`.
Includes `is_split` as a CSV column (`true|false`) for round-trip imports.
Includes `notes` as the final CSV column. Null notes export as a blank cell.
The response streams rows as CSV instead of buffering the full export first.
Exported cells are escaped when they start with spreadsheet formula prefixes (`=`, `+`, `-`, `@`, tab, carriage return, or leading whitespace before a formula prefix).

Examples:
- Month: `/me/transactions/export.csv?preset=last_month`
- Quarter: `/me/transactions/export.csv?preset=quarter_to_date`
- Custom: `/me/transactions/export.csv?date_from=2026-01-01&date_to=2026-03-31&tag_ids=12`

Response:
- `200 text/csv` file download.

## 14) CSV Import

### 13.1 Import Transactions CSV
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
  "is_split": "Split",
  "notes": "Memo"
}
```

Required mapped fields: `date`, `expense`, `amount`, and `tag`. `category` is required only for `category_strategy.mode=exact_column`. Optional mapped fields are `card`, `is_split`, and `notes`.
If mapped, `notes` is trimmed, blank values become `null`, and values longer than 255 characters fail row validation.

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

## 14) Data Runs

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

## 15) Standard Errors

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

## 16) Rate Limits

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

## 17) Authorization Rules
- All `/me/*` resources are scoped to the authenticated user only.
- Users can never access another user’s tags, cards, transactions, metrics, imports, or exports.
- Only owners can create/list invites.
- Only owner/admin can generate/list/revoke master API keys.
- Only owner/admin can list audit logs.
- Master API key auth can call protected routes for the key owner, except `/me/master-api-keys*` management routes.

## 17) Non-Goals (v1)
- Public self-signup
- Bank aggregation integrations
- Shared household budgets
- Native iOS-specific endpoints (same contract, different session transport)
