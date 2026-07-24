CREATE TABLE contexts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  deleted_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_contexts_user_name (user_id, name),
  UNIQUE KEY uq_contexts_id_user (id, user_id),
  KEY idx_contexts_user_active (user_id, is_active),
  CONSTRAINT fk_contexts_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE transactions
  ADD COLUMN context_id BIGINT UNSIGNED NULL AFTER tag_id,
  ADD KEY idx_transactions_user_context (user_id, context_id),
  ADD CONSTRAINT fk_transactions_context
    FOREIGN KEY (context_id, user_id) REFERENCES contexts (id, user_id);
