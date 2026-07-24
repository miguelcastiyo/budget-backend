CREATE TABLE monthly_savings_allocations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  month DATE NOT NULL COMMENT 'first day of month, YYYY-MM-01',
  fund_id BIGINT UNSIGNED NOT NULL,
  planned_amount DECIMAL(12,2) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_monthly_savings_allocations_user_month_fund (user_id, month, fund_id),
  KEY idx_monthly_savings_allocations_user_month (user_id, month),
  KEY idx_monthly_savings_allocations_user_fund_month (user_id, fund_id, month),
  CONSTRAINT fk_monthly_savings_allocations_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_monthly_savings_allocations_fund FOREIGN KEY (fund_id, user_id) REFERENCES funds (id, user_id),
  CONSTRAINT chk_monthly_savings_allocations_month_first_day CHECK (DAYOFMONTH(month) = 1),
  CONSTRAINT chk_monthly_savings_allocations_amount_positive CHECK (planned_amount > 0.00)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
