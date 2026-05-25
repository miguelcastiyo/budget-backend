CREATE TABLE audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id VARCHAR(64) NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  actor_auth_type ENUM('session', 'api_key', 'system') NOT NULL DEFAULT 'system',
  action VARCHAR(80) NOT NULL,
  target_type VARCHAR(80) NOT NULL,
  target_id VARCHAR(120) NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  metadata JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_audit_logs_event_id (event_id),
  KEY idx_audit_logs_created_at (created_at),
  KEY idx_audit_logs_actor_created (actor_user_id, created_at),
  KEY idx_audit_logs_action_created (action, created_at),
  KEY idx_audit_logs_target (target_type, target_id),
  CONSTRAINT fk_audit_logs_actor_user
    FOREIGN KEY (actor_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
