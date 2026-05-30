ALTER TABLE transactions
  ADD KEY idx_transactions_user_expense (user_id, expense);
