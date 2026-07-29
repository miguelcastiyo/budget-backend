CREATE TABLE IF NOT EXISTS encrypted_record_sync_state (
  user_id BIGINT UNSIGNED NOT NULL,
  next_sync_sequence BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_encrypted_sync_state_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS encrypted_financial_records (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  vault_id VARCHAR(64) NOT NULL,
  record_id VARCHAR(96) NOT NULL,
  envelope_version TINYINT UNSIGNED NOT NULL,
  record_revision BIGINT UNSIGNED NOT NULL,
  iv VARBINARY(64) NULL,
  ciphertext MEDIUMBLOB NULL,
  sync_sequence BIGINT UNSIGNED NOT NULL,
  is_deleted TINYINT(1) NOT NULL DEFAULT 0,
  last_mutation_id VARCHAR(128) NOT NULL,
  last_mutation_digest CHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_encrypted_records_user_record (user_id, record_id),
  UNIQUE KEY uq_encrypted_records_user_sequence (user_id, sync_sequence),
  KEY ix_encrypted_records_user_sequence (user_id, sync_sequence),
  CONSTRAINT fk_encrypted_records_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_encrypted_records_vault FOREIGN KEY (vault_id) REFERENCES user_financial_vaults (vault_id),
  CONSTRAINT chk_encrypted_records_version CHECK (envelope_version = 1),
  CONSTRAINT chk_encrypted_records_revision CHECK (record_revision >= 1),
  CONSTRAINT chk_encrypted_records_deleted_payload CHECK ((is_deleted = 1 AND iv IS NULL AND ciphertext IS NULL) OR (is_deleted = 0 AND iv IS NOT NULL AND ciphertext IS NOT NULL)),
  CONSTRAINT chk_encrypted_records_ciphertext_size CHECK (ciphertext IS NULL OR OCTET_LENGTH(ciphertext) <= 262144),
  CONSTRAINT chk_encrypted_records_iv_size CHECK (iv IS NULL OR OCTET_LENGTH(iv) = 12)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
