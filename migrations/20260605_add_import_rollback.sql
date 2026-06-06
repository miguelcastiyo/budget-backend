ALTER TABLE csv_import_runs
  ADD COLUMN rolled_back_at DATETIME NULL AFTER invalid_rows,
  ADD COLUMN rolled_back_rows INT UNSIGNED NOT NULL DEFAULT 0 AFTER rolled_back_at,
  ADD UNIQUE KEY uq_csv_import_runs_user_id_id (user_id, id);

ALTER TABLE transactions
  ADD COLUMN csv_import_run_id BIGINT UNSIGNED NULL AFTER import_fingerprint,
  ADD KEY idx_transactions_user_import_run (user_id, csv_import_run_id),
  ADD CONSTRAINT fk_transactions_csv_import_run
    FOREIGN KEY (user_id, csv_import_run_id) REFERENCES csv_import_runs (user_id, id);
