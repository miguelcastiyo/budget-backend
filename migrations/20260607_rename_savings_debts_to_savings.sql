ALTER TABLE budget_settings
  DROP CONSTRAINT chk_budget_settings_percent_mode,
  DROP CONSTRAINT chk_budget_settings_amount_mode;

ALTER TABLE budget_settings_versions
  DROP CONSTRAINT chk_budget_settings_versions_percent_mode,
  DROP CONSTRAINT chk_budget_settings_versions_amount_mode;

ALTER TABLE budget_settings
  CHANGE COLUMN savings_debts_percent savings_percent DECIMAL(5,2) NULL,
  CHANGE COLUMN savings_debts_amount savings_amount DECIMAL(12,2) NULL;

ALTER TABLE budget_settings_versions
  CHANGE COLUMN savings_debts_percent savings_percent DECIMAL(5,2) NULL,
  CHANGE COLUMN savings_debts_amount savings_amount DECIMAL(12,2) NULL;

ALTER TABLE budget_settings
  ADD CONSTRAINT chk_budget_settings_percent_mode CHECK (
    allocation_mode <> 'percent'
    OR (
      needs_percent IS NOT NULL
      AND wants_percent IS NOT NULL
      AND savings_percent IS NOT NULL
      AND needs_amount IS NULL
      AND wants_amount IS NULL
      AND savings_amount IS NULL
      AND (needs_percent + wants_percent + savings_percent = 100.00)
    )
  ),
  ADD CONSTRAINT chk_budget_settings_amount_mode CHECK (
    allocation_mode <> 'amount'
    OR (
      needs_amount IS NOT NULL
      AND wants_amount IS NOT NULL
      AND savings_amount IS NOT NULL
      AND needs_percent IS NULL
      AND wants_percent IS NULL
      AND savings_percent IS NULL
      AND (needs_amount + wants_amount + savings_amount = monthly_income)
    )
  );

ALTER TABLE budget_settings_versions
  ADD CONSTRAINT chk_budget_settings_versions_percent_mode CHECK (
    allocation_mode <> 'percent'
    OR (
      needs_percent IS NOT NULL
      AND wants_percent IS NOT NULL
      AND savings_percent IS NOT NULL
      AND needs_amount IS NULL
      AND wants_amount IS NULL
      AND savings_amount IS NULL
      AND (needs_percent + wants_percent + savings_percent = 100.00)
    )
  ),
  ADD CONSTRAINT chk_budget_settings_versions_amount_mode CHECK (
    allocation_mode <> 'amount'
    OR (
      needs_amount IS NOT NULL
      AND wants_amount IS NOT NULL
      AND savings_amount IS NOT NULL
      AND needs_percent IS NULL
      AND wants_percent IS NULL
      AND savings_percent IS NULL
      AND (needs_amount + wants_amount + savings_amount = monthly_income)
    )
  );

UPDATE tags
SET is_active = 1,
    deleted_at = NULL,
    icon_key = COALESCE(icon_key, 'credit_card'),
    updated_at = CURRENT_TIMESTAMP
WHERE LOWER(name) = 'debt';

INSERT INTO tags (user_id, name, icon_key)
SELECT u.id, 'Debt', 'credit_card'
FROM users u
WHERE NOT EXISTS (
  SELECT 1
  FROM tags t
  WHERE t.user_id = u.id
    AND LOWER(t.name) = 'debt'
);

ALTER TABLE transactions
  MODIFY COLUMN category ENUM('needs', 'wants', 'savings_debts', 'savings') NOT NULL;

ALTER TABLE recurring_expenses
  MODIFY COLUMN category ENUM('needs', 'wants', 'savings_debts', 'savings') NOT NULL;

UPDATE transactions
SET category = 'savings'
WHERE category = 'savings_debts';

UPDATE recurring_expenses re
JOIN tags t ON t.id = re.tag_id AND t.user_id = re.user_id
SET re.category = 'needs'
WHERE re.category = 'savings_debts'
  AND LOWER(t.name) = 'debt';

UPDATE recurring_expenses
SET category = 'savings'
WHERE category = 'savings_debts';

ALTER TABLE transactions
  MODIFY COLUMN category ENUM('needs', 'wants', 'savings') NOT NULL;

ALTER TABLE recurring_expenses
  MODIFY COLUMN category ENUM('needs', 'wants', 'savings') NOT NULL;
