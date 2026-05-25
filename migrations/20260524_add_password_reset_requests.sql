CREATE TABLE password_reset_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id VARCHAR(64) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  reset_token_hash CHAR(64) NOT NULL COMMENT 'sha256(reset token)',
  status ENUM('pending', 'used', 'expired', 'cancelled') NOT NULL DEFAULT 'pending',
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_password_reset_requests_request_id (request_id),
  UNIQUE KEY uq_password_reset_requests_token_hash (reset_token_hash),
  KEY idx_password_reset_requests_user_status (user_id, status),
  KEY idx_password_reset_requests_expires_at (expires_at),
  CONSTRAINT fk_password_reset_requests_user
    FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT chk_password_reset_requests_used CHECK (
    (status = 'used' AND used_at IS NOT NULL)
    OR
    (status <> 'used' AND used_at IS NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
