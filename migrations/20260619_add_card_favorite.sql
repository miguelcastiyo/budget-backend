ALTER TABLE cards
  ADD COLUMN is_favorite TINYINT(1) NOT NULL DEFAULT 0 AFTER name,
  ADD KEY idx_cards_user_favorite_sort (user_id, is_active, deleted_at, is_favorite, name, id);
