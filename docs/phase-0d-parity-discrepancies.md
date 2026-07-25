# Phase 0D parity discrepancies

## Second foundational adapter batch

Executed against the isolated Docker-backed budget_privacy_parity_test MariaDB database with a reset before every scenario.

Bound groups:

- FIX-TXN-002, FIX-TXN-003, FIX-TXN-004, FIX-TXN-005
- additional FIX-TAX-001 historical-relationship scenario
- FIX-BUD-002, FIX-BUD-003

No PHP-versus-invariant discrepancy was found in the exercised scenarios. The authoritative implementation required:

- the CSV import tag strategy to be explicitly supplied;
- hourly income totals to match the controller's current cent-rounding behavior.

These are represented by the generated fixtures and are not duplicated in adapter calculations.

INV-TXN-014 remains blocked because its authoritative source is recurring occurrence identity, which is explicitly out of scope for this batch.

No Recurring, Overview, Insights, Savings Plans, Closeouts, CSV fixture groups, or general cross-domain groups were executed.

## Recurring, Overview, and Insights adapter batch

Executed against budget_privacy_parity_test with a reset before every scenario. No PHP-versus-invariant discrepancy was found.

The only production testability seams added are optional fixed-clock constructor arguments on RecurringExpenseService and MonthOverviewService. Existing production construction remains wall-clock based.

The recurring captures exercise day-31 clamping, February and leap-year dates, last-day billing, windows, future non-generation, version overlap, future schedule changes, deletion, retries, seed transactions, and generated/manual linkage. Overview and Insights captures exercise selected-month boundaries, inherited budgets, category/tag totals, progress, cards, recurring totals, recent ordering/limits, range totals, weekday distributions, largest/tied rows, recurring versus variable spend, and empty zero-safe behavior.

No Funds, Savings Plans, Closeouts, CSV, or cross-domain fixture groups were executed.

## Funds, Savings Plans, and Closeouts adapter batch

Funds and Savings Plans were executed against the Docker-backed `budget_privacy_parity_test` database with a reset before each scenario. No PHP-versus-invariant discrepancy was found for the exercised Fund and Savings Plan invariants.

Fund coverage used the authoritative FundController/FundService/FundBalanceService/FundTransactionIntegrationService paths, including zero starting state, directional balance changes, exact and over-goal balances, multiple Funds, archive/restore restrictions, manual and starting-balance sources, and closeout linkage. Savings coverage used SavingsPlanController/SavingsPlanService for configuration, budget rejection, transaction-directed contributions, goal pacing, month boundaries, and Fund integration.

Closeout execution initially exposed a production discrepancy. On the second execution of the same closeout, MonthCloseoutService correctly reached the replacement path and FundCloseoutIntegrationService voided the active ledger entries, but MonthCloseoutRepository then deleted the referenced `monthly_closeout_allocations` rows. MariaDB rejected that delete through `fk_fund_entries_closeout_allocation` because the voided historical entries retained their source allocation relationship. The same failure blocked replacement updates.

Resolution: `monthly_closeout_allocations` now has nullable `superseded_at`. Replacement marks current rows superseded inside the existing transaction, inserts new rows, and creates new linked ledger entries. Ordinary reads and Fund closeout summaries filter to `superseded_at IS NULL`; historical rows and Fund ledger source IDs remain intact. Repeated close, replacement, reopen, and re-close now execute successfully. No discrepancy remains for the closeout invariants.

The optional fixed UTC clock seam was added to MonthCloseoutService; existing production construction omits it and retains wall-clock behavior.

## Final closure

The CSV and cross-domain adapters executed without PHP-versus-invariant discrepancies. The closeout discrepancy described above was surgically resolved with historical allocation retirement and regression coverage. Unexplained discrepancies: 0.
