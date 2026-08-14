-- Piece 1: additive identity foundation and safe legacy backfill.
-- Legacy users.auth_provider/password_hash/google_sub remain runtime authority.

CREATE TABLE IF NOT EXISTS auth_identities (
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

CREATE TABLE IF NOT EXISTS password_credentials (
  user_id BIGINT UNSIGNED NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  last_used_at DATETIME NULL,
  password_changed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_password_credentials_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @auth_identity_add_session_timestamp = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE user_sessions ADD COLUMN last_authenticated_at DATETIME NULL AFTER last_seen_at',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'user_sessions'
    AND COLUMN_NAME = 'last_authenticated_at'
);
PREPARE auth_identity_add_session_timestamp_statement FROM @auth_identity_add_session_timestamp;
EXECUTE auth_identity_add_session_timestamp_statement;
DEALLOCATE PREPARE auth_identity_add_session_timestamp_statement;

-- Validate legacy state and any rows left by a prior interrupted attempt.
SET @auth_identity_preflight_failures = (
  SELECT COUNT(*) FROM users
  WHERE auth_provider NOT IN ('google', 'password')
     OR (auth_provider = 'google' AND (google_sub IS NULL OR password_hash IS NOT NULL))
     OR (auth_provider = 'password' AND (password_hash IS NULL OR google_sub IS NOT NULL))
) + (
  SELECT COUNT(*) FROM (
    SELECT google_sub FROM users WHERE google_sub IS NOT NULL GROUP BY google_sub HAVING COUNT(*) > 1
  ) duplicate_google_subjects
) + (
  SELECT COUNT(*) FROM auth_identities ai
  WHERE NOT EXISTS (
    SELECT 1 FROM users u
    WHERE u.id = ai.user_id
      AND u.auth_provider = 'google'
      AND ai.provider = 'google'
      AND BINARY ai.provider_subject = BINARY u.google_sub
  )
) + (
  SELECT COUNT(*) FROM password_credentials pc
  WHERE NOT EXISTS (
    SELECT 1 FROM users u
    WHERE u.id = pc.user_id
      AND u.auth_provider = 'password'
      AND BINARY pc.password_hash = BINARY u.password_hash
  )
);
SET @auth_identity_preflight_sql = IF(
  @auth_identity_preflight_failures = 0,
  'SELECT 1',
  'SELECT * FROM auth_identity_backfill_preflight_failed'
);
PREPARE auth_identity_preflight_statement FROM @auth_identity_preflight_sql;
EXECUTE auth_identity_preflight_statement;
DEALLOCATE PREPARE auth_identity_preflight_statement;

-- Exact, idempotent mirrors. No auth timestamp is invented for legacy data.
INSERT IGNORE INTO auth_identities (
  user_id, provider, provider_subject, provider_email, provider_email_verified
)
SELECT id, 'google', google_sub, email, email_verified
FROM users
WHERE auth_provider = 'google';

INSERT IGNORE INTO password_credentials (user_id, password_hash)
SELECT id, password_hash
FROM users
WHERE auth_provider = 'password';

-- Validate exact cardinality and mappings before allowing this migration to be recorded.
SET @auth_identity_validation_failures = (
  SELECT ABS(
    (SELECT COUNT(*) FROM users WHERE auth_provider = 'google') -
    (SELECT COUNT(*) FROM auth_identities WHERE provider = 'google')
  )
) + (
  SELECT ABS(
    (SELECT COUNT(*) FROM users WHERE auth_provider = 'password') -
    (SELECT COUNT(*) FROM password_credentials)
  )
) + (
  SELECT COUNT(*) FROM users u
  LEFT JOIN auth_identities ai
    ON ai.user_id = u.id AND ai.provider = 'google' AND BINARY ai.provider_subject = BINARY u.google_sub
  WHERE u.auth_provider = 'google' AND ai.id IS NULL
) + (
  SELECT COUNT(*) FROM users u
  LEFT JOIN password_credentials pc ON pc.user_id = u.id AND BINARY pc.password_hash = BINARY u.password_hash
  WHERE u.auth_provider = 'password' AND pc.user_id IS NULL
) + (
  SELECT COUNT(*) FROM auth_identities ai
  WHERE ai.provider <> 'google'
     OR NOT EXISTS (SELECT 1 FROM users u WHERE u.id = ai.user_id AND u.auth_provider = 'google' AND BINARY u.google_sub = BINARY ai.provider_subject)
) + (
  SELECT COUNT(*) FROM password_credentials pc
  WHERE NOT EXISTS (SELECT 1 FROM users u WHERE u.id = pc.user_id AND u.auth_provider = 'password' AND BINARY u.password_hash = BINARY pc.password_hash)
);
SET @auth_identity_validation_sql = IF(
  @auth_identity_validation_failures = 0,
  'SELECT 1',
  'SELECT * FROM auth_identity_backfill_validation_failed'
);
PREPARE auth_identity_validation_statement FROM @auth_identity_validation_sql;
EXECUTE auth_identity_validation_statement;
DEALLOCATE PREPARE auth_identity_validation_statement;
