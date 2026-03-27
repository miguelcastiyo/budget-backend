CREATE TABLE IF NOT EXISTS recurring_expenses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  expense VARCHAR(160) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  category ENUM('needs', 'wants', 'savings_debts') NOT NULL,
  tag_id BIGINT UNSIGNED NOT NULL,
  card_id BIGINT UNSIGNED NULL,
  billing_type ENUM('day_of_month', 'last_day') NOT NULL DEFAULT 'day_of_month',
  billing_day TINYINT UNSIGNED NULL,
  starts_month DATE NOT NULL COMMENT 'first day of the month (YYYY-MM-01)',
  ends_month DATE NULL COMMENT 'first day of the month (YYYY-MM-01)',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  deleted_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_recurring_expenses_user_active (user_id, is_active),
  KEY idx_recurring_expenses_user_window (user_id, starts_month, ends_month),
  CONSTRAINT fk_recurring_expenses_user
    FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_recurring_expenses_tag
    FOREIGN KEY (tag_id, user_id) REFERENCES tags (id, user_id),
  CONSTRAINT fk_recurring_expenses_card
    FOREIGN KEY (card_id, user_id) REFERENCES cards (id, user_id),
  CONSTRAINT chk_recurring_expenses_amount_positive CHECK (amount > 0.00),
  CONSTRAINT chk_recurring_expenses_billing CHECK (
    (billing_type = 'last_day' AND billing_day IS NULL)
    OR
    (billing_type = 'day_of_month' AND billing_day BETWEEN 1 AND 31)
  ),
  CONSTRAINT chk_recurring_expenses_month_window CHECK (
    ends_month IS NULL OR ends_month >= starts_month
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recurring_expense_occurrences (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  recurring_expense_id BIGINT UNSIGNED NOT NULL,
  occurrence_month DATE NOT NULL COMMENT 'first day of month (YYYY-MM-01)',
  due_date DATE NOT NULL,
  transaction_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_recurring_occurrence_month (user_id, recurring_expense_id, occurrence_month),
  KEY idx_recurring_occurrences_user_month (user_id, occurrence_month),
  KEY idx_recurring_occurrences_transaction (transaction_id),
  CONSTRAINT fk_recurring_occurrences_user
    FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_recurring_occurrences_recurring_expense
    FOREIGN KEY (recurring_expense_id) REFERENCES recurring_expenses (id),
  CONSTRAINT fk_recurring_occurrences_transaction
    FOREIGN KEY (transaction_id) REFERENCES transactions (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
