ALTER TABLE monthly_closeout_allocations
  ADD COLUMN superseded_at DATETIME NULL AFTER notes,
  ADD KEY idx_monthly_closeout_allocations_current (closeout_id, superseded_at, id);
