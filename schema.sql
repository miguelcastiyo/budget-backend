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
  email VARCHAR(255) NOT NULL,
  auth_method ENUM('google_or_password') NOT NULL DEFAULT 'google_or_password',
  invited_by_user_id BIGINT UNSIGNED NOT NULL,
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
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  deleted_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cards_user_name (user_id, name),
  UNIQUE KEY uq_cards_id_user (id, user_id),
  KEY idx_cards_user_active (user_id, is_active),
  CONSTRAINT fk_cards_user
    FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE recurring_expenses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  expense VARCHAR(160) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  category ENUM('needs', 'wants', 'savings_debts') NOT NULL,
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
  KEY idx_recurring_expenses_user_window (user_id, starts_month, ends_month),
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
  allocation_mode ENUM('percent', 'amount') NOT NULL DEFAULT 'percent',
  needs_percent DECIMAL(5,2) NULL,
  wants_percent DECIMAL(5,2) NULL,
  savings_debts_percent DECIMAL(5,2) NULL,
  needs_amount DECIMAL(12,2) NULL,
  wants_amount DECIMAL(12,2) NULL,
  savings_debts_amount DECIMAL(12,2) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_budget_settings_user (user_id),
  CONSTRAINT fk_budget_settings_user
    FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT chk_budget_settings_income_nonnegative CHECK (monthly_income >= 0.00),
  CONSTRAINT chk_budget_settings_percent_mode CHECK (
    allocation_mode <> 'percent'
    OR (
      needs_percent IS NOT NULL
      AND wants_percent IS NOT NULL
      AND savings_debts_percent IS NOT NULL
      AND needs_amount IS NULL
      AND wants_amount IS NULL
      AND savings_debts_amount IS NULL
      AND (needs_percent + wants_percent + savings_debts_percent = 100.00)
    )
  ),
  CONSTRAINT chk_budget_settings_amount_mode CHECK (
    allocation_mode <> 'amount'
    OR (
      needs_amount IS NOT NULL
      AND wants_amount IS NOT NULL
      AND savings_debts_amount IS NOT NULL
      AND needs_percent IS NULL
      AND wants_percent IS NULL
      AND savings_debts_percent IS NULL
      AND (needs_amount + wants_amount + savings_debts_amount = monthly_income)
    )
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE transactions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  transaction_date DATE NOT NULL,
  expense VARCHAR(160) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  category ENUM('needs', 'wants', 'savings_debts') NOT NULL,
  tag_id BIGINT UNSIGNED NOT NULL,
  card_id BIGINT UNSIGNED NULL,
  is_split TINYINT(1) NOT NULL DEFAULT 0,
  source ENUM('manual', 'import') NOT NULL DEFAULT 'manual',
  import_fingerprint CHAR(64) NULL COMMENT 'sha256(date|amount|lower(trim(expense))|category|is_split|tag|card)',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_transactions_import_dedupe (user_id, import_fingerprint),
  KEY idx_transactions_user_date (user_id, transaction_date),
  KEY idx_transactions_user_deleted_date_id (user_id, deleted_at, transaction_date, id),
  KEY idx_transactions_user_category (user_id, category),
  KEY idx_transactions_user_tag (user_id, tag_id),
  KEY idx_transactions_user_card (user_id, card_id),
  KEY idx_transactions_user_split (user_id, is_split),
  CONSTRAINT fk_transactions_user
    FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_transactions_tag
    FOREIGN KEY (tag_id, user_id) REFERENCES tags (id, user_id),
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
  error_summary VARCHAR(1000) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_csv_import_runs_user_created (user_id, created_at),
  CONSTRAINT fk_csv_import_runs_user
    FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
