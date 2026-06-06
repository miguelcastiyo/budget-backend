ALTER TABLE csv_import_runs
  ADD COLUMN skipped_rows INT UNSIGNED NOT NULL DEFAULT 0 AFTER invalid_rows,
  ADD COLUMN skipped_blank_amount_rows INT UNSIGNED NOT NULL DEFAULT 0 AFTER skipped_rows;
