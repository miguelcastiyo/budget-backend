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
    'savings',
    'investment',
    'debt',
    'rollover',
    'buffer',
    'covered_by_buffer',
    'ignored',
    'other'
  ) NOT NULL,
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
  KEY idx_monthly_closeout_allocations_user_target_month (user_id, target_month),
  CONSTRAINT fk_monthly_closeout_allocations_closeout
    FOREIGN KEY (closeout_id) REFERENCES monthly_closeouts (id),
  CONSTRAINT fk_monthly_closeout_allocations_user
    FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT chk_monthly_closeout_allocations_amount_positive
    CHECK (amount > 0.00),
  CONSTRAINT chk_monthly_closeout_allocations_target_month_first_day CHECK (
    target_month IS NULL OR DAYOFMONTH(target_month) = 1
  ),
  CONSTRAINT chk_monthly_closeout_allocations_rollover_target CHECK (
    (allocation_type = 'rollover' AND target_month IS NOT NULL)
    OR
    (allocation_type <> 'rollover' AND target_month IS NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
