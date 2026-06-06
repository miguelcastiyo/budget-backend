CREATE TABLE IF NOT EXISTS budget_settings_versions (
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
  savings_debts_percent DECIMAL(5,2) NULL,
  needs_amount DECIMAL(12,2) NULL,
  wants_amount DECIMAL(12,2) NULL,
  savings_debts_amount DECIMAL(12,2) NULL,
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
      AND savings_debts_percent IS NOT NULL
      AND needs_amount IS NULL
      AND wants_amount IS NULL
      AND savings_debts_amount IS NULL
      AND (needs_percent + wants_percent + savings_debts_percent = 100.00)
    )
  ),
  CONSTRAINT chk_budget_settings_versions_amount_mode CHECK (
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

INSERT IGNORE INTO budget_settings_versions (
  user_id,
  effective_month,
  monthly_income,
  income_source_type,
  primary_monthly_income,
  primary_hourly_rate,
  primary_weekly_hours,
  side_income_type,
  side_income_label,
  side_monthly_income,
  side_hourly_rate,
  side_weekly_hours,
  allocation_mode,
  needs_percent,
  wants_percent,
  savings_debts_percent,
  needs_amount,
  wants_amount,
  savings_debts_amount,
  created_at,
  updated_at
)
SELECT
  bs.user_id,
  COALESCE(DATE_FORMAT(MIN(t.transaction_date), '%Y-%m-01'), DATE_FORMAT(UTC_DATE(), '%Y-%m-01')) AS effective_month,
  bs.monthly_income,
  bs.income_source_type,
  bs.primary_monthly_income,
  bs.primary_hourly_rate,
  bs.primary_weekly_hours,
  bs.side_income_type,
  bs.side_income_label,
  bs.side_monthly_income,
  bs.side_hourly_rate,
  bs.side_weekly_hours,
  bs.allocation_mode,
  bs.needs_percent,
  bs.wants_percent,
  bs.savings_debts_percent,
  bs.needs_amount,
  bs.wants_amount,
  bs.savings_debts_amount,
  bs.created_at,
  bs.updated_at
FROM budget_settings bs
LEFT JOIN transactions t
  ON t.user_id = bs.user_id
  AND t.deleted_at IS NULL
GROUP BY
  bs.user_id,
  bs.monthly_income,
  bs.income_source_type,
  bs.primary_monthly_income,
  bs.primary_hourly_rate,
  bs.primary_weekly_hours,
  bs.side_income_type,
  bs.side_income_label,
  bs.side_monthly_income,
  bs.side_hourly_rate,
  bs.side_weekly_hours,
  bs.allocation_mode,
  bs.needs_percent,
  bs.wants_percent,
  bs.savings_debts_percent,
  bs.needs_amount,
  bs.wants_amount,
  bs.savings_debts_amount,
  bs.created_at,
  bs.updated_at;
