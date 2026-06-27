ALTER TABLE transactions
  ADD COLUMN notes VARCHAR(255) NULL AFTER is_split;
