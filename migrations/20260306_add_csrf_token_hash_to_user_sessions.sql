-- Incremental migration for existing databases:
-- adds CSRF token hash storage for cookie-session CSRF protection.

ALTER TABLE user_sessions
  ADD COLUMN csrf_token_hash CHAR(64) NULL COMMENT 'sha256(csrf token)' AFTER session_secret_hash;
