ALTER TABLE financial_privacy_cleanup_jobs
  ADD COLUMN lease_expires_at DATETIME NULL AFTER started_at;
