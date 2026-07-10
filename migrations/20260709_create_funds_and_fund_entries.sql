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

ALTER TABLE monthly_closeout_allocations
  MODIFY allocation_type ENUM(
    'fund',
    'savings',
    'investment',
    'debt',
    'rollover',
    'buffer',
    'covered_by_buffer',
    'ignored',
    'other'
  ) NOT NULL;

ALTER TABLE monthly_closeout_allocations
  ADD COLUMN fund_id BIGINT UNSIGNED NULL AFTER allocation_type,
  ADD KEY idx_monthly_closeout_allocations_user_fund (user_id, fund_id),
  ADD CONSTRAINT fk_monthly_closeout_allocations_fund
    FOREIGN KEY (fund_id, user_id) REFERENCES funds (id, user_id),
  ADD CONSTRAINT chk_monthly_closeout_allocations_fund_target CHECK (
    (allocation_type = 'fund' AND fund_id IS NOT NULL)
    OR
    (allocation_type <> 'fund' AND fund_id IS NULL)
  );

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
