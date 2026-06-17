ALTER TABLE recurring_expenses
  ADD COLUMN series_id VARCHAR(64) NULL AFTER id;

UPDATE recurring_expenses
SET series_id = CONCAT('rser_', id)
WHERE series_id IS NULL;

ALTER TABLE recurring_expenses
  MODIFY series_id VARCHAR(64) NOT NULL;

CREATE INDEX idx_recurring_expenses_user_series
  ON recurring_expenses (user_id, series_id);

CREATE INDEX idx_recurring_expenses_user_series_window
  ON recurring_expenses (user_id, series_id, starts_month, ends_month);

CREATE UNIQUE INDEX uq_recurring_expenses_user_series_start
  ON recurring_expenses (user_id, series_id, starts_month);
