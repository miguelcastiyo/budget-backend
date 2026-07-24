-- Budget App v1 Schema (MySQL 8+)
-- Aligned to project_info.md + api_v1.md + openapi.yaml.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(255) NOT NULL,
  display_name VARCHAR(120) NOT NULL,
  avatar_url VARCHAR(512) NULL,
  user_preferences JSON NULL,
  auth_provider ENUM('password', 'google') NOT NULL,
  password_hash VARCHAR(255) NULL,
  google_sub VARCHAR(128) NULL,
  email_verified TINYINT(1) NOT NULL DEFAULT 0,
  role ENUM('owner', 'admin', 'member') NOT NULL DEFAULT 'member',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_google_sub (google_sub),
  CONSTRAINT chk_users_auth_provider CHECK (
    (auth_provider = 'password' AND password_hash IS NOT NULL AND google_sub IS NULL)
    OR
    (auth_provider = 'google' AND google_sub IS NOT NULL AND password_hash IS NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invitations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  invite_id VARCHAR(64) NOT NULL,
  invite_token_hash CHAR(64) NOT NULL COMMENT 'sha256(invite token)',
  invitee_name VARCHAR(120) NOT NULL,
  email VARCHAR(255) NOT NULL,
  role ENUM('admin', 'member') NOT NULL DEFAULT 'member',
  auth_method ENUM('google_or_password') NOT NULL DEFAULT 'google_or_password',
  invited_by_user_id BIGINT UNSIGNED NOT NULL,
  email_subject VARCHAR(160) NOT NULL,
  email_body TEXT NOT NULL,
  status ENUM('pending', 'accepted', 'expired', 'revoked') NOT NULL DEFAULT 'pending',
  expires_at DATETIME NOT NULL,
  accepted_by_user_id BIGINT UNSIGNED NULL,
  accepted_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_invitations_invite_id (invite_id),
  UNIQUE KEY uq_invitations_token_hash (invite_token_hash),
  KEY idx_invitations_email_status (email, status),
  KEY idx_invitations_inviter (invited_by_user_id),
  CONSTRAINT fk_invitations_invited_by_user
    FOREIGN KEY (invited_by_user_id) REFERENCES users (id),
  CONSTRAINT fk_invitations_accepted_by_user
    FOREIGN KEY (accepted_by_user_id) REFERENCES users (id),
  CONSTRAINT chk_invitations_acceptance CHECK (
    (status = 'accepted' AND accepted_by_user_id IS NOT NULL AND accepted_at IS NOT NULL)
    OR
    (status <> 'accepted' AND accepted_by_user_id IS NULL AND accepted_at IS NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id VARCHAR(64) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  session_secret_hash CHAR(64) NOT NULL COMMENT 'sha256(session secret)',
  csrf_token_hash CHAR(64) NULL COMMENT 'sha256(csrf token)',
  client_type ENUM('web', 'native') NOT NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  last_seen_at DATETIME NULL,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_sessions_session_id (session_id),
  UNIQUE KEY uq_user_sessions_secret_hash (session_secret_hash),
  KEY idx_user_sessions_user (user_id),
  KEY idx_user_sessions_expiry (expires_at),
  KEY idx_user_sessions_user_revoked (user_id, revoked_at),
  CONSTRAINT fk_user_sessions_user
    FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_change_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id VARCHAR(64) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  new_email VARCHAR(255) NOT NULL,
  verification_code_hash CHAR(64) NOT NULL COMMENT 'sha256(verification code)',
  status ENUM('verification_pending', 'verified', 'expired', 'cancelled') NOT NULL DEFAULT 'verification_pending',
  expires_at DATETIME NOT NULL,
  verified_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_email_change_requests_request_id (request_id),
  KEY idx_email_change_requests_user_status (user_id, status),
  KEY idx_email_change_requests_new_email (new_email),
  CONSTRAINT fk_email_change_requests_user
    FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT chk_email_change_requests_verified CHECK (
    (status = 'verified' AND verified_at IS NOT NULL)
    OR
    (status <> 'verified' AND verified_at IS NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE password_reset_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id VARCHAR(64) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  reset_token_hash CHAR(64) NOT NULL COMMENT 'sha256(reset token)',
  status ENUM('pending', 'used', 'expired', 'cancelled') NOT NULL DEFAULT 'pending',
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_password_reset_requests_request_id (request_id),
  UNIQUE KEY uq_password_reset_requests_token_hash (reset_token_hash),
  KEY idx_password_reset_requests_user_status (user_id, status),
  KEY idx_password_reset_requests_expires_at (expires_at),
  CONSTRAINT fk_password_reset_requests_user
    FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT chk_password_reset_requests_used CHECK (
    (status = 'used' AND used_at IS NOT NULL)
    OR
    (status <> 'used' AND used_at IS NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE master_api_keys (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  key_id VARCHAR(64) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  key_prefix VARCHAR(32) NOT NULL,
  key_hash CHAR(64) NOT NULL COMMENT 'sha256(full api key)',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_used_at DATETIME NULL,
  expires_at DATETIME NULL,
  revoked_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_master_api_keys_key_id (key_id),
  UNIQUE KEY uq_master_api_keys_hash (key_hash),
  KEY idx_master_api_keys_user_active (user_id, is_active),
  CONSTRAINT fk_master_api_keys_user
    FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT chk_master_api_keys_revoked CHECK (
    (revoked_at IS NULL)
    OR
    (is_active = 0)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id VARCHAR(64) NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  actor_auth_type ENUM('session', 'api_key', 'system') NOT NULL DEFAULT 'system',
  action VARCHAR(80) NOT NULL,
  target_type VARCHAR(80) NOT NULL,
  target_id VARCHAR(120) NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  metadata JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_audit_logs_event_id (event_id),
  KEY idx_audit_logs_created_at (created_at),
  KEY idx_audit_logs_actor_created (actor_user_id, created_at),
  KEY idx_audit_logs_action_created (action, created_at),
  KEY idx_audit_logs_target (target_type, target_id),
  CONSTRAINT fk_audit_logs_actor_user
    FOREIGN KEY (actor_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tags (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  icon_key VARCHAR(64) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  deleted_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tags_user_name (user_id, name),
  UNIQUE KEY uq_tags_id_user (id, user_id),
  KEY idx_tags_user_active (user_id, is_active),
  CONSTRAINT fk_tags_user
    FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cards (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  is_favorite TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  deleted_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cards_user_name (user_id, name),
  UNIQUE KEY uq_cards_id_user (id, user_id),
  KEY idx_cards_user_active (user_id, is_active),
  KEY idx_cards_user_favorite_sort (user_id, is_active, deleted_at, is_favorite, name, id),
  CONSTRAINT fk_cards_user
    FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contexts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  icon_key VARCHAR(64) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  deleted_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_contexts_user_name (user_id, name),
  UNIQUE KEY uq_contexts_id_user (id, user_id),
  KEY idx_contexts_user_active (user_id, is_active),
  CONSTRAINT fk_contexts_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE funds (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  fund_id VARCHAR(64) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  fund_type ENUM('goal', 'emergency', 'buffer', 'debt', 'investment', 'other') NOT NULL DEFAULT 'goal',
  goal_amount DECIMAL(12,2) NULL,
  target_month DATE NULL COMMENT 'first day of month, YYYY-MM-01',
  notes TEXT NULL,
  status ENUM('active', 'archived') NOT NULL DEFAULT 'active',
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  archived_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_funds_fund_id (fund_id),
  UNIQUE KEY uq_funds_user_name (user_id, name),
  UNIQUE KEY uq_funds_id_user (id, user_id),
  KEY idx_funds_user_status_sort (user_id, status, sort_order, name, id),
  CONSTRAINT fk_funds_user
    FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT chk_funds_goal_amount_positive CHECK (
    goal_amount IS NULL OR goal_amount > 0.00
  ),
  CONSTRAINT chk_funds_target_month_first_day CHECK (
    target_month IS NULL OR DAYOFMONTH(target_month) = 1
  ),
  CONSTRAINT chk_funds_archived_at CHECK (
    (status = 'archived' AND archived_at IS NOT NULL)
    OR
    (status = 'active' AND archived_at IS NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE recurring_expenses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  series_id VARCHAR(64) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  expense VARCHAR(160) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  category ENUM('needs', 'wants', 'savings') NOT NULL,
  tag_id BIGINT UNSIGNED NOT NULL,
  card_id BIGINT UNSIGNED NULL,
  billing_type ENUM('day_of_month', 'last_day') NOT NULL DEFAULT 'day_of_month',
  billing_day TINYINT UNSIGNED NULL,
  starts_month DATE NOT NULL COMMENT 'first day of the month (YYYY-MM-01)',
  ends_month DATE NULL COMMENT 'first day of the month (YYYY-MM-01)',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  deleted_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_recurring_expenses_user_active (user_id, is_active),
  KEY idx_recurring_expenses_user_series (user_id, series_id),
  KEY idx_recurring_expenses_user_series_window (user_id, series_id, starts_month, ends_month),
  KEY idx_recurring_expenses_user_window (user_id, starts_month, ends_month),
  UNIQUE KEY uq_recurring_expenses_user_series_start (user_id, series_id, starts_month),
  CONSTRAINT fk_recurring_expenses_user
    FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_recurring_expenses_tag
    FOREIGN KEY (tag_id, user_id) REFERENCES tags (id, user_id),
  CONSTRAINT fk_recurring_expenses_card
    FOREIGN KEY (card_id, user_id) REFERENCES cards (id, user_id),
  CONSTRAINT chk_recurring_expenses_amount_positive CHECK (amount > 0.00),
  CONSTRAINT chk_recurring_expenses_billing CHECK (
    (billing_type = 'last_day' AND billing_day IS NULL)
    OR
    (billing_type = 'day_of_month' AND billing_day BETWEEN 1 AND 31)
  ),
  CONSTRAINT chk_recurring_expenses_month_window CHECK (
    ends_month IS NULL OR ends_month >= starts_month
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE budget_settings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  monthly_income DECIMAL(12,2) NOT NULL,
  income_source_type ENUM('monthly', 'hourly') NOT NULL DEFAULT 'monthly',
  primary_monthly_income DECIMAL(12,2) NULL,
  primary_hourly_rate DECIMAL(12,2) NULL,
  primary_weekly_hours DECIMAL(5,2) NULL,
  side_income_type ENUM('none', 'monthly', 'hourly') NOT NULL DEFAULT 'none',
  side_income_label VARCHAR(80) NULL,
  side_monthly_income DECIMAL(12,2) NULL,
  side_hourly_rate DECIMAL(12,2) NULL,
  side_weekly_hours DECIMAL(5,2) NULL,
  allocation_mode ENUM('percent', 'amount') NOT NULL DEFAULT 'percent',
  needs_percent DECIMAL(5,2) NULL,
  wants_percent DECIMAL(5,2) NULL,
  savings_percent DECIMAL(5,2) NULL,
  needs_amount DECIMAL(12,2) NULL,
  wants_amount DECIMAL(12,2) NULL,
  savings_amount DECIMAL(12,2) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_budget_settings_user (user_id),
  CONSTRAINT fk_budget_settings_user
    FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT chk_budget_settings_income_nonnegative CHECK (monthly_income >= 0.00),
  CONSTRAINT chk_budget_settings_primary_income_nonnegative CHECK (
    primary_monthly_income IS NULL OR primary_monthly_income >= 0.00
  ),
  CONSTRAINT chk_budget_settings_primary_hourly_positive CHECK (
    income_source_type <> 'hourly'
    OR (
      primary_hourly_rate IS NOT NULL
      AND primary_hourly_rate > 0.00
      AND primary_weekly_hours IS NOT NULL
      AND primary_weekly_hours > 0.00
    )
  ),
  CONSTRAINT chk_budget_settings_side_income CHECK (
    (
      side_income_type = 'none'
      AND side_monthly_income IS NULL
      AND side_hourly_rate IS NULL
      AND side_weekly_hours IS NULL
    )
    OR (
      side_income_type = 'monthly'
      AND side_monthly_income IS NOT NULL
      AND side_monthly_income > 0.00
      AND side_hourly_rate IS NULL
      AND side_weekly_hours IS NULL
    )
    OR (
      side_income_type = 'hourly'
      AND side_monthly_income IS NULL
      AND side_hourly_rate IS NOT NULL
      AND side_hourly_rate > 0.00
      AND side_weekly_hours IS NOT NULL
      AND side_weekly_hours > 0.00
    )
  ),
  CONSTRAINT chk_budget_settings_percent_mode CHECK (
    allocation_mode <> 'percent'
    OR (
      needs_percent IS NOT NULL
      AND wants_percent IS NOT NULL
      AND savings_percent IS NOT NULL
      AND needs_amount IS NULL
      AND wants_amount IS NULL
      AND savings_amount IS NULL
      AND (needs_percent + wants_percent + savings_percent = 100.00)
    )
  ),
  CONSTRAINT chk_budget_settings_amount_mode CHECK (
    allocation_mode <> 'amount'
    OR (
      needs_amount IS NOT NULL
      AND wants_amount IS NOT NULL
      AND savings_amount IS NOT NULL
      AND needs_percent IS NULL
      AND wants_percent IS NULL
      AND savings_percent IS NULL
      AND (needs_amount + wants_amount + savings_amount = monthly_income)
    )
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE budget_settings_versions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  effective_month DATE NOT NULL,
  monthly_income DECIMAL(12,2) NOT NULL,
  income_source_type ENUM('monthly', 'hourly') NOT NULL DEFAULT 'monthly',
  primary_monthly_income DECIMAL(12,2) NULL,
  primary_hourly_rate DECIMAL(12,2) NULL,
  primary_weekly_hours DECIMAL(5,2) NULL,
  side_income_type ENUM('none', 'monthly', 'hourly') NOT NULL DEFAULT 'none',
  side_income_label VARCHAR(80) NULL,
  side_monthly_income DECIMAL(12,2) NULL,
  side_hourly_rate DECIMAL(12,2) NULL,
  side_weekly_hours DECIMAL(5,2) NULL,
  allocation_mode ENUM('percent', 'amount') NOT NULL DEFAULT 'percent',
  needs_percent DECIMAL(5,2) NULL,
  wants_percent DECIMAL(5,2) NULL,
  savings_percent DECIMAL(5,2) NULL,
  needs_amount DECIMAL(12,2) NULL,
  wants_amount DECIMAL(12,2) NULL,
  savings_amount DECIMAL(12,2) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_budget_settings_versions_user_month (user_id, effective_month),
  CONSTRAINT fk_budget_settings_versions_user
    FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT chk_budget_settings_versions_income_nonnegative CHECK (monthly_income >= 0.00),
  CONSTRAINT chk_budget_settings_versions_primary_income_nonnegative CHECK (
    primary_monthly_income IS NULL OR primary_monthly_income >= 0.00
  ),
  CONSTRAINT chk_budget_settings_versions_primary_hourly_positive CHECK (
    income_source_type <> 'hourly'
    OR (
      primary_hourly_rate IS NOT NULL
      AND primary_hourly_rate > 0.00
      AND primary_weekly_hours IS NOT NULL
      AND primary_weekly_hours > 0.00
    )
  ),
  CONSTRAINT chk_budget_settings_versions_side_income CHECK (
    (
      side_income_type = 'none'
      AND side_monthly_income IS NULL
      AND side_hourly_rate IS NULL
      AND side_weekly_hours IS NULL
    )
    OR (
      side_income_type = 'monthly'
      AND side_monthly_income IS NOT NULL
      AND side_monthly_income > 0.00
      AND side_hourly_rate IS NULL
      AND side_weekly_hours IS NULL
    )
    OR (
      side_income_type = 'hourly'
      AND side_monthly_income IS NULL
      AND side_hourly_rate IS NOT NULL
      AND side_hourly_rate > 0.00
      AND side_weekly_hours IS NOT NULL
      AND side_weekly_hours > 0.00
    )
  ),
  CONSTRAINT chk_budget_settings_versions_percent_mode CHECK (
    allocation_mode <> 'percent'
    OR (
      needs_percent IS NOT NULL
      AND wants_percent IS NOT NULL
      AND savings_percent IS NOT NULL
      AND needs_amount IS NULL
      AND wants_amount IS NULL
      AND savings_amount IS NULL
      AND (needs_percent + wants_percent + savings_percent = 100.00)
    )
  ),
  CONSTRAINT chk_budget_settings_versions_amount_mode CHECK (
    allocation_mode <> 'amount'
    OR (
      needs_amount IS NOT NULL
      AND wants_amount IS NOT NULL
      AND savings_amount IS NOT NULL
      AND needs_percent IS NULL
      AND wants_percent IS NULL
      AND savings_percent IS NULL
      AND (needs_amount + wants_amount + savings_amount = monthly_income)
    )
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE monthly_closeouts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  closeout_id VARCHAR(64) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  month DATE NOT NULL COMMENT 'first day of month, YYYY-MM-01',
  status ENUM('closed', 'reopened') NOT NULL DEFAULT 'closed',
  result_type ENUM('surplus', 'deficit', 'balanced') NOT NULL,
  budget_effective_month DATE NULL COMMENT 'resolved budget version effective month used for closeout',
  budget_allocation_mode ENUM('percent', 'amount') NOT NULL,
  monthly_income_snapshot DECIMAL(12,2) NOT NULL,
  planned_needs DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  planned_wants DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  planned_savings DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  planned_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  actual_needs DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  actual_wants DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  actual_savings DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  actual_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  surplus_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  deficit_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  spending_surplus_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  spending_deficit_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  calculation_hash CHAR(64) NOT NULL,
  notes TEXT NULL,
  closed_at DATETIME NOT NULL,
  reopened_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_monthly_closeouts_closeout_id (closeout_id),
  UNIQUE KEY uq_monthly_closeouts_user_month (user_id, month),
  KEY idx_monthly_closeouts_user_month_status (user_id, month, status),
  KEY idx_monthly_closeouts_user_status_month (user_id, status, month),
  CONSTRAINT fk_monthly_closeouts_user
    FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT chk_monthly_closeouts_month_first_day
    CHECK (DAYOFMONTH(month) = 1),
  CONSTRAINT chk_monthly_closeouts_income_nonnegative
    CHECK (monthly_income_snapshot >= 0.00),
  CONSTRAINT chk_monthly_closeouts_planned_nonnegative CHECK (
    planned_needs >= 0.00
    AND planned_wants >= 0.00
    AND planned_savings >= 0.00
    AND planned_total >= 0.00
  ),
  CONSTRAINT chk_monthly_closeouts_actual_nonnegative CHECK (
    actual_needs >= 0.00
    AND actual_wants >= 0.00
    AND actual_savings >= 0.00
    AND actual_total >= 0.00
  ),
  CONSTRAINT chk_monthly_closeouts_result_amounts CHECK (
    (
      result_type = 'surplus'
      AND surplus_amount > 0.00
      AND deficit_amount = 0.00
    )
    OR (
      result_type = 'deficit'
      AND deficit_amount > 0.00
      AND surplus_amount = 0.00
    )
    OR (
      result_type = 'balanced'
      AND surplus_amount = 0.00
      AND deficit_amount = 0.00
    )
  ),
  CONSTRAINT chk_monthly_closeouts_reopened_at CHECK (
    (status = 'reopened' AND reopened_at IS NOT NULL)
    OR
    (status = 'closed' AND reopened_at IS NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE monthly_closeout_allocations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  allocation_id VARCHAR(64) NOT NULL,
  closeout_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  allocation_type ENUM(
    'fund',
    'savings',
    'investment',
    'debt',
    'rollover',
    'buffer',
    'covered_by_buffer',
    'ignored',
    'other'
  ) NOT NULL,
  fund_id BIGINT UNSIGNED NULL,
  label VARCHAR(120) NULL,
  amount DECIMAL(12,2) NOT NULL,
  target_month DATE NULL COMMENT 'first day of month for rollover allocations',
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_monthly_closeout_allocations_allocation_id (allocation_id),
  KEY idx_monthly_closeout_allocations_closeout (closeout_id),
  KEY idx_monthly_closeout_allocations_user_type (user_id, allocation_type),
  KEY idx_monthly_closeout_allocations_user_fund (user_id, fund_id),
  KEY idx_monthly_closeout_allocations_user_target_month (user_id, target_month),
  CONSTRAINT fk_monthly_closeout_allocations_closeout
    FOREIGN KEY (closeout_id) REFERENCES monthly_closeouts (id),
  CONSTRAINT fk_monthly_closeout_allocations_user
    FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_monthly_closeout_allocations_fund
    FOREIGN KEY (fund_id, user_id) REFERENCES funds (id, user_id),
  CONSTRAINT chk_monthly_closeout_allocations_amount_positive
    CHECK (amount > 0.00),
  CONSTRAINT chk_monthly_closeout_allocations_target_month_first_day CHECK (
    target_month IS NULL OR DAYOFMONTH(target_month) = 1
  ),
  CONSTRAINT chk_monthly_closeout_allocations_fund_target CHECK (
    (allocation_type = 'fund' AND fund_id IS NOT NULL)
    OR
    (allocation_type <> 'fund' AND fund_id IS NULL)
  ),
  CONSTRAINT chk_monthly_closeout_allocations_rollover_target CHECK (
    (allocation_type = 'rollover' AND target_month IS NOT NULL)
    OR
    (allocation_type <> 'rollover' AND target_month IS NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE transactions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  transaction_date DATE NOT NULL,
  expense VARCHAR(160) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  category ENUM('needs', 'wants', 'savings') NOT NULL,
  tag_id BIGINT UNSIGNED NOT NULL,
  context_id BIGINT UNSIGNED NULL,
  card_id BIGINT UNSIGNED NULL,
  is_split TINYINT(1) NOT NULL DEFAULT 0,
  notes VARCHAR(255) NULL,
  source ENUM('manual', 'import') NOT NULL DEFAULT 'manual',
  import_fingerprint CHAR(64) NULL COMMENT 'sha256(date|amount|lower(trim(expense))|category|is_split|tag|card)',
  csv_import_run_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_transactions_import_dedupe (user_id, import_fingerprint),
  KEY idx_transactions_user_date (user_id, transaction_date),
  KEY idx_transactions_user_deleted_date_id (user_id, deleted_at, transaction_date, id),
  KEY idx_transactions_user_expense (user_id, expense),
  KEY idx_transactions_user_category (user_id, category),
  KEY idx_transactions_user_tag (user_id, tag_id),
  KEY idx_transactions_user_context (user_id, context_id),
  KEY idx_transactions_user_card (user_id, card_id),
  KEY idx_transactions_user_split (user_id, is_split),
  KEY idx_transactions_user_import_run (user_id, csv_import_run_id),
  CONSTRAINT fk_transactions_user
    FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_transactions_tag
    FOREIGN KEY (tag_id, user_id) REFERENCES tags (id, user_id),
  CONSTRAINT fk_transactions_context
    FOREIGN KEY (context_id, user_id) REFERENCES contexts (id, user_id),
  CONSTRAINT fk_transactions_card
    FOREIGN KEY (card_id, user_id) REFERENCES cards (id, user_id),
  CONSTRAINT chk_transactions_amount_positive CHECK (amount > 0.00),
  CONSTRAINT chk_transactions_source_import_fingerprint CHECK (
    source <> 'import' OR import_fingerprint IS NOT NULL
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE recurring_expense_occurrences (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  recurring_expense_id BIGINT UNSIGNED NOT NULL,
  occurrence_month DATE NOT NULL COMMENT 'first day of month (YYYY-MM-01)',
  due_date DATE NOT NULL,
  transaction_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_recurring_occurrence_month (user_id, recurring_expense_id, occurrence_month),
  KEY idx_recurring_occurrences_user_month (user_id, occurrence_month),
  KEY idx_recurring_occurrences_transaction (transaction_id),
  CONSTRAINT fk_recurring_occurrences_user
    FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_recurring_occurrences_recurring_expense
    FOREIGN KEY (recurring_expense_id) REFERENCES recurring_expenses (id),
  CONSTRAINT fk_recurring_occurrences_transaction
    FOREIGN KEY (transaction_id) REFERENCES transactions (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fund_entries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  fund_entry_id VARCHAR(64) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  fund_id BIGINT UNSIGNED NOT NULL,
  entry_date DATE NOT NULL,
  entry_type ENUM(
    'contribution',
    'withdrawal',
    'adjustment',
    'starting_balance'
  ) NOT NULL,
  direction ENUM('in', 'out') NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  source_type ENUM(
    'manual',
    'transaction',
    'month_closeout',
    'starting_balance',
    'correction'
  ) NOT NULL,
  source_transaction_id BIGINT UNSIGNED NULL,
  source_closeout_id BIGINT UNSIGNED NULL,
  source_closeout_allocation_id BIGINT UNSIGNED NULL,
  note TEXT NULL,
  voided_at DATETIME NULL,
  void_reason ENUM(
    'closeout_reopened',
    'allocation_replaced',
    'transaction_deleted',
    'manual_void',
    'correction'
  ) NULL,
  deleted_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fund_entries_entry_id (fund_entry_id),
  UNIQUE KEY uq_fund_entries_transaction_source (user_id, source_transaction_id),
  UNIQUE KEY uq_fund_entries_closeout_allocation_source (user_id, source_closeout_allocation_id),
  KEY idx_fund_entries_user_fund_active (user_id, fund_id, deleted_at, voided_at),
  KEY idx_fund_entries_user_date (user_id, entry_date, id),
  KEY idx_fund_entries_user_source_type (user_id, source_type, entry_date),
  KEY idx_fund_entries_source_closeout (source_closeout_id),
  CONSTRAINT fk_fund_entries_user
    FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_fund_entries_fund
    FOREIGN KEY (fund_id, user_id) REFERENCES funds (id, user_id),
  CONSTRAINT fk_fund_entries_transaction
    FOREIGN KEY (source_transaction_id) REFERENCES transactions (id),
  CONSTRAINT fk_fund_entries_closeout
    FOREIGN KEY (source_closeout_id) REFERENCES monthly_closeouts (id),
  CONSTRAINT fk_fund_entries_closeout_allocation
    FOREIGN KEY (source_closeout_allocation_id) REFERENCES monthly_closeout_allocations (id),
  CONSTRAINT chk_fund_entries_amount_positive CHECK (amount > 0.00),
  CONSTRAINT chk_fund_entries_voided_reason CHECK (
    (voided_at IS NULL AND void_reason IS NULL)
    OR
    (voided_at IS NOT NULL AND void_reason IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE csv_import_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  mode ENUM('dry_run', 'commit') NOT NULL,
  status ENUM('completed', 'failed') NOT NULL DEFAULT 'completed',
  source_filename VARCHAR(255) NULL,
  total_rows INT UNSIGNED NOT NULL DEFAULT 0,
  valid_rows INT UNSIGNED NOT NULL DEFAULT 0,
  imported_rows INT UNSIGNED NOT NULL DEFAULT 0,
  duplicate_rows INT UNSIGNED NOT NULL DEFAULT 0,
  invalid_rows INT UNSIGNED NOT NULL DEFAULT 0,
  skipped_rows INT UNSIGNED NOT NULL DEFAULT 0,
  skipped_blank_amount_rows INT UNSIGNED NOT NULL DEFAULT 0,
  rolled_back_at DATETIME NULL,
  rolled_back_rows INT UNSIGNED NOT NULL DEFAULT 0,
  error_summary VARCHAR(1000) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_csv_import_runs_user_id_id (user_id, id),
  KEY idx_csv_import_runs_user_created (user_id, created_at),
  CONSTRAINT fk_csv_import_runs_user
    FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE transactions
  ADD CONSTRAINT fk_transactions_csv_import_run
    FOREIGN KEY (user_id, csv_import_run_id) REFERENCES csv_import_runs (user_id, id);

CREATE TABLE csv_export_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  status ENUM('started', 'completed', 'failed') NOT NULL DEFAULT 'started',
  date_from DATE NULL,
  date_to DATE NULL,
  total_rows INT UNSIGNED NOT NULL DEFAULT 0,
  error_summary VARCHAR(1000) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_csv_export_runs_user_created (user_id, created_at),
  CONSTRAINT fk_csv_export_runs_user
    FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
