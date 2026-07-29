UPDATE vault_quick_unlock_credentials q
JOIN user_sessions s ON s.session_id = q.device_id
SET q.device_id = s.device_id
WHERE q.user_id = s.user_id;
