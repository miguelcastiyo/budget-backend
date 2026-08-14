-- Budget App v1 Schema (MySQL 8+)
-- Aligned to project_info.md + api_v1.md + openapi.yaml.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(255) NOT NULL,
  display_name VARCHAR(120) NOT NULL,
  avatar_url VARCHAR(512) NULL,
  user_preferences JSON NULL,
  financial_privacy_state VARCHAR(32) NOT NULL DEFAULT 'vault_setup_required',
  auth_provider ENUM('password', 'google') NOT NULL,
  password_hash VARCHAR(255) NULL,
  google_sub VARCHAR(128) NULL,
  email_verified TINYINT(1) NOT NULL DEFAULT 0,
  role ENUM('owner', 'admin', 'member') NOT NULL DEFAULT 'member',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_google_sub (google_sub),
  CONSTRAINT chk_users_auth_provider CHECK (
    (auth_provider = 'password' AND password_hash IS NOT NULL AND google_sub IS NULL)
    OR
    (auth_provider = 'google' AND google_sub IS NOT NULL AND password_hash IS NULL)
  ),
  CONSTRAINT chk_users_financial_privacy_state CHECK (
    financial_privacy_state IN ('vault_setup_required', 'encrypted')
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Transitional identity foundation. The legacy auth columns on users remain
-- the runtime authority until the follow-up auth cutover.
CREATE TABLE auth_identities (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(32) NOT NULL,
  provider_subject VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  provider_email VARCHAR(255) NULL,
  provider_email_verified TINYINT(1) NULL,
  last_used_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_auth_identities_provider_subject (provider, provider_subject),
  UNIQUE KEY uq_auth_identities_user_provider (user_id, provider),
  KEY idx_auth_identities_user (user_id),
  CONSTRAINT fk_auth_identities_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE password_credentials (
  user_id BIGINT UNSIGNED NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  last_used_at DATETIME NULL,
  password_changed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_password_credentials_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invitations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  invite_id VARCHAR(64) NOT NULL,
  invite_token_hash CHAR(64) NOT NULL COMMENT 'sha256(invite token)',
  invitee_name VARCHAR(120) NOT NULL,
  email VARCHAR(255) NOT NULL,
  role ENUM('admin', 'member') NOT NULL DEFAULT 'member',
  auth_method ENUM('google_or_password') NOT NULL DEFAULT 'google_or_password',
  invited_by_user_id BIGINT UNSIGNED NOT NULL,
  email_subject VARCHAR(160) NOT NULL,
  email_body TEXT NOT NULL,
  status ENUM('pending', 'accepted', 'expired', 'revoked') NOT NULL DEFAULT 'pending',
  expires_at DATETIME NOT NULL,
  accepted_by_user_id BIGINT UNSIGNED NULL,
  accepted_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_invitations_invite_id (invite_id),
  UNIQUE KEY uq_invitations_token_hash (invite_token_hash),
  KEY idx_invitations_email_status (email, status),
  KEY idx_invitations_inviter (invited_by_user_id),
  CONSTRAINT fk_invitations_invited_by_user
    FOREIGN KEY (invited_by_user_id) REFERENCES users (id),
  CONSTRAINT fk_invitations_accepted_by_user
    FOREIGN KEY (accepted_by_user_id) REFERENCES users (id),
  CONSTRAINT chk_invitations_acceptance CHECK (
    (status = 'accepted' AND accepted_by_user_id IS NOT NULL AND accepted_at IS NOT NULL)
    OR
    (status <> 'accepted' AND accepted_by_user_id IS NULL AND accepted_at IS NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id VARCHAR(64) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  device_id VARCHAR(64) NOT NULL,
  session_secret_hash CHAR(64) NOT NULL COMMENT 'sha256(session secret)',
  csrf_token_hash CHAR(64) NULL COMMENT 'sha256(csrf token)',
  client_type ENUM('web', 'native') NOT NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  last_seen_at DATETIME NULL,
  last_authenticated_at DATETIME NULL,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_sessions_session_id (session_id),
  UNIQUE KEY uq_user_sessions_secret_hash (session_secret_hash),
  KEY idx_user_sessions_user (user_id),
  KEY idx_user_sessions_expiry (expires_at),
  KEY idx_user_sessions_user_revoked (user_id, revoked_at),
  KEY idx_user_sessions_device (user_id, device_id, revoked_at),
  CONSTRAINT fk_user_sessions_user
    FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_financial_vaults (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  vault_id VARCHAR(64) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  crypto_profile_version TINYINT UNSIGNED NOT NULL,
  passphrase_kdf VARCHAR(32) NOT NULL,
  passphrase_kdf_hash VARCHAR(32) NOT NULL,
  passphrase_kdf_iterations INT UNSIGNED NOT NULL,
  passphrase_wrap_algorithm VARCHAR(32) NOT NULL,
  passphrase_kdf_salt VARBINARY(64) NOT NULL,
  passphrase_wrapped_vault_key VARBINARY(512) NOT NULL,
  recovery_wrap_algorithm VARCHAR(32) NOT NULL,
  recovery_wrapped_vault_key VARBINARY(512) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_financial_vaults_vault_id (vault_id),
  UNIQUE KEY uq_user_financial_vaults_user (user_id),
  CONSTRAINT fk_user_financial_vaults_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT chk_user_financial_vaults_profile CHECK (crypto_profile_version = 1),
  CONSTRAINT chk_user_financial_vaults_kdf CHECK (passphrase_kdf = 'PBKDF2' AND passphrase_kdf_hash = 'SHA-256' AND passphrase_kdf_iterations >= 600000),
  CONSTRAINT chk_user_financial_vaults_wraps CHECK (passphrase_wrap_algorithm = 'AES-KW' AND recovery_wrap_algorithm = 'AES-KW')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE vault_quick_unlock_credentials (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  quick_unlock_id VARCHAR(64) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  device_id VARCHAR(64) NOT NULL,
  credential_id VARBINARY(512) NOT NULL,
  credential_record JSON NOT NULL,
  signature_counter BIGINT UNSIGNED NOT NULL DEFAULT 0,
  quick_unlock_profile_version TINYINT UNSIGNED NOT NULL DEFAULT 1,
  prf_input VARBINARY(64) NOT NULL,
  wrapped_vault_key VARBINARY(512) NOT NULL,
  status ENUM('pending', 'active', 'revoked') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  activated_at TIMESTAMP NULL,
  last_used_at TIMESTAMP NULL,
  revoked_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_quick_unlock_id (quick_unlock_id),
  UNIQUE KEY uq_quick_unlock_credential_id (credential_id),
  KEY idx_quick_unlock_user_device_status (user_id, device_id, status),
  CONSTRAINT fk_quick_unlock_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT chk_quick_unlock_profile CHECK (quick_unlock_profile_version = 1),
  CONSTRAINT chk_quick_unlock_prf_input CHECK (OCTET_LENGTH(prf_input) = 32),
  CONSTRAINT chk_quick_unlock_wrapper CHECK (OCTET_LENGTH(wrapped_vault_key) BETWEEN 40 AND 512)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE webauthn_challenges (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  session_id VARCHAR(64) NOT NULL,
  purpose ENUM('quick_unlock_registration', 'quick_unlock_registration_activation', 'quick_unlock_assertion') NOT NULL,
  quick_unlock_id VARCHAR(64) NULL,
  challenge VARBINARY(64) NOT NULL,
  options_json JSON NOT NULL,
  expires_at DATETIME NOT NULL,
  consumed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_webauthn_challenges_lookup (user_id, session_id, purpose, consumed_at, expires_at),
  CONSTRAINT fk_webauthn_challenges_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT chk_webauthn_challenge_length CHECK (OCTET_LENGTH(challenge) >= 16)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE encrypted_record_sync_state (
  user_id BIGINT UNSIGNED NOT NULL,
  next_sync_sequence BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_encrypted_sync_state_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE encrypted_record_batches (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  idempotency_key VARCHAR(128) NOT NULL,
  result_json MEDIUMTEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_encrypted_record_batches_user_key (user_id, idempotency_key),
  CONSTRAINT fk_encrypted_record_batches_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE encrypted_financial_records (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  vault_id VARCHAR(64) NOT NULL,
  record_id VARCHAR(96) NOT NULL,
  envelope_version TINYINT UNSIGNED NOT NULL,
  record_revision BIGINT UNSIGNED NOT NULL,
  iv VARBINARY(16) NULL,
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

CREATE TABLE encrypted_record_changes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  vault_id VARCHAR(64) NOT NULL,
  record_id VARCHAR(96) NOT NULL,
  envelope_version TINYINT UNSIGNED NOT NULL,
  record_revision BIGINT UNSIGNED NOT NULL,
  iv VARBINARY(16) NULL,
  ciphertext MEDIUMBLOB NULL,
  sync_sequence BIGINT UNSIGNED NOT NULL,
  is_deleted TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_encrypted_record_changes_user_sequence (user_id, sync_sequence),
  KEY ix_encrypted_record_changes_user_sequence (user_id, sync_sequence),
  CONSTRAINT fk_encrypted_record_changes_record FOREIGN KEY (user_id, record_id) REFERENCES encrypted_financial_records (user_id, record_id),
  CONSTRAINT chk_encrypted_record_changes_version CHECK (envelope_version = 1),
  CONSTRAINT chk_encrypted_record_changes_deleted_payload CHECK ((is_deleted = 1 AND iv IS NULL AND ciphertext IS NULL) OR (is_deleted = 0 AND iv IS NOT NULL AND ciphertext IS NOT NULL)),
  CONSTRAINT chk_encrypted_record_changes_ciphertext_size CHECK (ciphertext IS NULL OR OCTET_LENGTH(ciphertext) <= 262144),
  CONSTRAINT chk_encrypted_record_changes_iv_size CHECK (iv IS NULL OR OCTET_LENGTH(iv) = 12)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_change_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id VARCHAR(64) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  new_email VARCHAR(255) NOT NULL,
  verification_code_hash CHAR(64) NOT NULL COMMENT 'sha256(verification code)',
  status ENUM('verification_pending', 'verified', 'expired', 'cancelled') NOT NULL DEFAULT 'verification_pending',
  expires_at DATETIME NOT NULL,
  verified_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_email_change_requests_request_id (request_id),
  KEY idx_email_change_requests_user_status (user_id, status),
  KEY idx_email_change_requests_new_email (new_email),
  CONSTRAINT fk_email_change_requests_user
    FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT chk_email_change_requests_verified CHECK (
    (status = 'verified' AND verified_at IS NOT NULL)
    OR
    (status <> 'verified' AND verified_at IS NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id VARCHAR(64) NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  -- api_key is retained for historical audit rows only; runtime auth is session/system.
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
