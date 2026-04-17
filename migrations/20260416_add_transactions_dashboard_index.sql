ALTER TABLE transactions
  ADD KEY idx_transactions_user_deleted_date_id (user_id, deleted_at, transaction_date, id);
