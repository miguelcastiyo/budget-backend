ALTER TABLE users
  ADD COLUMN user_preferences JSON NULL AFTER avatar_url;

UPDATE users
SET user_preferences = JSON_OBJECT()
WHERE user_preferences IS NULL;
