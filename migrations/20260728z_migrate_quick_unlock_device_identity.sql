UPDATE vault_quick_unlock_credentials q
JOIN user_sessions s
  ON CONVERT(s.session_id USING utf8mb4) COLLATE utf8mb4_unicode_ci
   = CONVERT(q.device_id USING utf8mb4) COLLATE utf8mb4_unicode_ci
SET q.device_id = s.device_id
WHERE q.user_id = s.user_id;
