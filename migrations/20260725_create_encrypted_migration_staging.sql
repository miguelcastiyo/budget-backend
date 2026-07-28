CREATE TABLE encrypted_migration_manifests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  migration_id VARCHAR(64) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  manifest_version VARCHAR(32) NOT NULL,
  snapshot_schema_version VARCHAR(32) NOT NULL,
  source_financial_revision BIGINT UNSIGNED NOT NULL,
  target_count INT UNSIGNED NOT NULL,
  relationship_count INT UNSIGNED NOT NULL DEFAULT 0,
  manifest_json JSON NOT NULL,
  manifest_hash CHAR(64) NOT NULL,
  finalized_at DATETIME NULL,
  verified_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_encrypted_migration_manifests_migration (migration_id),
  CONSTRAINT fk_encrypted_migration_manifests_migration FOREIGN KEY (migration_id) REFERENCES financial_privacy_migrations (migration_id),
  CONSTRAINT fk_encrypted_migration_manifests_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE encrypted_migration_records (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  migration_id VARCHAR(64) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  target_record_id VARCHAR(160) NOT NULL,
  record_family VARCHAR(64) NOT NULL,
  record_schema_version VARCHAR(32) NOT NULL,
  envelope_version TINYINT UNSIGNED NOT NULL,
  iv VARBINARY(64) NOT NULL,
  ciphertext MEDIUMBLOB NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_encrypted_migration_records_target (migration_id, target_record_id),
  KEY idx_encrypted_migration_records_migration (migration_id, record_family),
  CONSTRAINT fk_encrypted_migration_records_migration FOREIGN KEY (migration_id) REFERENCES financial_privacy_migrations (migration_id),
  CONSTRAINT fk_encrypted_migration_records_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
