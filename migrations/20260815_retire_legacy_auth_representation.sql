-- Phase 4B: destructive auth retirement. Run only after the Phase 4A soak and
-- authoritative-state preflight. This migration is forward-only.

SET @auth_retirement_zero_method_users = (
  SELECT COUNT(*) FROM users u
  WHERE u.is_active = 1
    AND (SELECT COUNT(*) FROM auth_identities ai WHERE ai.user_id = u.id)
      + (SELECT COUNT(*) FROM password_credentials pc WHERE pc.user_id = u.id) = 0
);
SET @auth_retirement_multi_method_users = (
  SELECT COUNT(*) FROM users u
  WHERE u.is_active = 1
    AND (SELECT COUNT(*) FROM auth_identities ai WHERE ai.user_id = u.id)
      + (SELECT COUNT(*) FROM password_credentials pc WHERE pc.user_id = u.id) > 1
);
SET @auth_retirement_orphans = (
  SELECT COUNT(*) FROM auth_identities ai LEFT JOIN users u ON u.id = ai.user_id WHERE u.id IS NULL
) + (
  SELECT COUNT(*) FROM password_credentials pc LEFT JOIN users u ON u.id = pc.user_id WHERE u.id IS NULL
) + (
  SELECT COUNT(*) FROM user_sessions s LEFT JOIN users u ON u.id = s.user_id WHERE u.id IS NULL
);
SET @auth_retirement_guard_sql = IF(
  @auth_retirement_zero_method_users = 0
  AND @auth_retirement_multi_method_users = 0
  AND @auth_retirement_orphans = 0,
  'SELECT 1',
  'SELECT * FROM auth_retirement_preflight_failed'
);
PREPARE auth_retirement_guard_statement FROM @auth_retirement_guard_sql;
EXECUTE auth_retirement_guard_statement;
DEALLOCATE PREPARE auth_retirement_guard_statement;

SET @auth_retirement_drop_check_sql = (
  SELECT IF(COUNT(*) > 0,
    IF(LOCATE('MariaDB', @@version) > 0,
      'ALTER TABLE users DROP CONSTRAINT chk_users_auth_provider',
      'ALTER TABLE users DROP CHECK chk_users_auth_provider'
    ),
    'SELECT 1'
  )
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
    AND CONSTRAINT_NAME = 'chk_users_auth_provider' AND CONSTRAINT_TYPE = 'CHECK'
);
PREPARE auth_retirement_drop_check_statement FROM @auth_retirement_drop_check_sql;
EXECUTE auth_retirement_drop_check_statement;
DEALLOCATE PREPARE auth_retirement_drop_check_statement;

SET @auth_retirement_drop_google_index_sql = (
  SELECT IF(COUNT(*) > 0, 'ALTER TABLE users DROP INDEX uq_users_google_sub', 'SELECT 1')
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'uq_users_google_sub'
);
PREPARE auth_retirement_drop_google_index_statement FROM @auth_retirement_drop_google_index_sql;
EXECUTE auth_retirement_drop_google_index_statement;
DEALLOCATE PREPARE auth_retirement_drop_google_index_statement;

ALTER TABLE users
  DROP COLUMN auth_provider,
  DROP COLUMN password_hash,
  DROP COLUMN google_sub;
