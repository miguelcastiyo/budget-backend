CREATE TABLE csv_export_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  status ENUM('started', 'completed', 'failed') NOT NULL DEFAULT 'started',
  date_from DATE NULL,
  date_to DATE NULL,
  total_rows INT UNSIGNED NOT NULL DEFAULT 0,
  error_summary VARCHAR(1000) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_csv_export_runs_user_created (user_id, created_at),
  CONSTRAINT fk_csv_export_runs_user
    FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
