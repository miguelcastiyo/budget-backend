-- Piece 2 pre-switch reconciliation. Missing mirrors are repaired; conflicts stop deployment.
SET @auth_piece2_conflicts = (
  SELECT COUNT(*) FROM users
  WHERE auth_provider NOT IN ('google', 'password')
     OR (auth_provider = 'google' AND (google_sub IS NULL OR password_hash IS NOT NULL))
     OR (auth_provider = 'password' AND (password_hash IS NULL OR google_sub IS NOT NULL))
) + (
  SELECT COUNT(*) FROM auth_identities ai
  WHERE NOT EXISTS (SELECT 1 FROM users u WHERE u.id = ai.user_id AND u.auth_provider = 'google' AND BINARY u.google_sub = BINARY ai.provider_subject)
) + (
  SELECT COUNT(*) FROM password_credentials pc
  WHERE NOT EXISTS (SELECT 1 FROM users u WHERE u.id = pc.user_id AND u.auth_provider = 'password' AND BINARY u.password_hash = BINARY pc.password_hash)
);
SET @auth_piece2_conflict_sql = IF(@auth_piece2_conflicts = 0, 'SELECT 1', 'SELECT * FROM auth_piece2_reconciliation_failed');
PREPARE auth_piece2_conflict_statement FROM @auth_piece2_conflict_sql;
EXECUTE auth_piece2_conflict_statement;
DEALLOCATE PREPARE auth_piece2_conflict_statement;

INSERT IGNORE INTO auth_identities (user_id, provider, provider_subject, provider_email, provider_email_verified)
SELECT id, 'google', google_sub, email, email_verified FROM users WHERE auth_provider = 'google';

INSERT IGNORE INTO password_credentials (user_id, password_hash)
SELECT id, password_hash FROM users WHERE auth_provider = 'password';

SET @auth_piece2_missing = (
  SELECT COUNT(*) FROM users u LEFT JOIN auth_identities ai ON ai.user_id = u.id AND ai.provider = 'google' AND BINARY ai.provider_subject = BINARY u.google_sub WHERE u.auth_provider = 'google' AND ai.id IS NULL
) + (
  SELECT COUNT(*) FROM users u LEFT JOIN password_credentials pc ON pc.user_id = u.id AND BINARY pc.password_hash = BINARY u.password_hash WHERE u.auth_provider = 'password' AND pc.user_id IS NULL
);
SET @auth_piece2_missing_sql = IF(@auth_piece2_missing = 0, 'SELECT 1', 'SELECT * FROM auth_piece2_reconciliation_incomplete');
PREPARE auth_piece2_missing_statement FROM @auth_piece2_missing_sql;
EXECUTE auth_piece2_missing_statement;
DEALLOCATE PREPARE auth_piece2_missing_statement;
