ALTER TABLE budget_settings
  ADD COLUMN income_source_type ENUM('monthly', 'hourly') NOT NULL DEFAULT 'monthly' AFTER monthly_income,
  ADD COLUMN primary_monthly_income DECIMAL(12,2) NULL AFTER income_source_type,
  ADD COLUMN primary_hourly_rate DECIMAL(12,2) NULL AFTER primary_monthly_income,
  ADD COLUMN primary_weekly_hours DECIMAL(5,2) NULL AFTER primary_hourly_rate,
  ADD COLUMN side_income_type ENUM('none', 'monthly', 'hourly') NOT NULL DEFAULT 'none' AFTER primary_weekly_hours,
  ADD COLUMN side_income_label VARCHAR(80) NULL AFTER side_income_type,
  ADD COLUMN side_monthly_income DECIMAL(12,2) NULL AFTER side_income_label,
  ADD COLUMN side_hourly_rate DECIMAL(12,2) NULL AFTER side_monthly_income,
  ADD COLUMN side_weekly_hours DECIMAL(5,2) NULL AFTER side_hourly_rate;

UPDATE budget_settings
SET
  income_source_type = 'monthly',
  primary_monthly_income = monthly_income,
  primary_hourly_rate = NULL,
  primary_weekly_hours = NULL,
  side_income_type = 'none',
  side_income_label = NULL,
  side_monthly_income = NULL,
  side_hourly_rate = NULL,
  side_weekly_hours = NULL;

ALTER TABLE budget_settings
  ADD CONSTRAINT chk_budget_settings_primary_income_nonnegative CHECK (
    primary_monthly_income IS NULL OR primary_monthly_income >= 0.00
  ),
  ADD CONSTRAINT chk_budget_settings_primary_hourly_positive CHECK (
    income_source_type <> 'hourly'
    OR (
      primary_hourly_rate IS NOT NULL
      AND primary_hourly_rate > 0.00
      AND primary_weekly_hours IS NOT NULL
      AND primary_weekly_hours > 0.00
    )
  ),
  ADD CONSTRAINT chk_budget_settings_side_income CHECK (
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
  );
