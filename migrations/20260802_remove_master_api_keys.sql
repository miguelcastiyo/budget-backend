-- Retire master API-key credentials after runtime support has been removed.
-- Historical audit rows remain in audit_logs, including actor_auth_type=api_key.
DROP TABLE IF EXISTS master_api_keys;
