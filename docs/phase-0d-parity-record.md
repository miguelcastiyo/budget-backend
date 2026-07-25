# Phase 0D parity record

## Second foundational adapter batch

- Verified fixture entries: 10, including two scenarios in FIX-TAX-001.
- Verified fixture groups: 9.
- Blocked fixture groups: 15.
- High-priority invariants covered: 22/80.
- Deterministic regeneration: two clean runs matched byte-for-byte.
- Database: budget_privacy_parity_test.
- Database reset: performed before each bound scenario.
- Discrepancies: none; details are in phase-0d-parity-discrepancies.md.

The second foundational batch had 15 verified scenarios and 15 blocked groups before the Recurring, Overview, and Insights batch.

## Recurring, Overview, and Insights adapter batch

- Logical groups: 24.
- Fixture scenarios: 25.
- Verified scenarios: 15.
- Blocked scenarios: 10.
- Verified groups: 14/24.
- High-priority invariants covered: 37/80.
- Newly verified: INV-TXN-014, INV-REC-001 through INV-REC-006, INV-REC-008 through INV-REC-010, and INV-OVR-001 through INV-OVR-005.
- Deterministic regeneration: two complete clean runs matched byte-for-byte.
- Clock seams: optional fixed UTC clock injection in RecurringExpenseService and MonthOverviewService; production defaults unchanged.
- Discrepancies: none.

## Funds, Savings Plans, and Closeouts adapter batch

- Logical groups: 24.
- Fixture scenarios: 25.
- Verified scenarios: 20.
- Blocked scenarios: 5.
- Verified groups: 19/24.
- High-priority invariants covered: 56/80.
- Newly verified: INV-FUND-001 through INV-FUND-011, INV-FUND-013, INV-SAVINGS-001 through INV-SAVINGS-005, INV-SAVINGS-007, and INV-SAVINGS-008.
- Deterministic regeneration: two complete clean runs matched byte-for-byte.
- Database: budget_privacy_parity_test, reset before every bound scenario.
- Clock seam: optional fixed UTC clock injection added to MonthCloseoutService; default production construction remains unchanged. RecurringExpenseService and MonthOverviewService retain their previously documented optional fixed-clock seams.
- Discrepancy: the closeout foreign-key defect was discovered and subsequently resolved.

## Closeout defect resolution

- Persistence model: historical `monthly_closeout_allocations` rows are retained and marked with `superseded_at`; current reads use only NULL values.
- Production regression: replacement, repeated close, reopen, and re-close preserve historical source allocation IDs, void old entries, and create new current entries without foreign-key failures.
- Closeout fixture result: FIX-CLOSE-001 and FIX-CLOSE-002 verified.
- High-priority coverage after resolution: 68/80 before the final CSV/cross-domain batch.
- Discrepancy status: resolved; the root cause and migration are documented in phase-0d-parity-discrepancies.md.

## Final Phase 0D closure

- Status: `completed`.
- Recommendation: `parity_baseline_ready`.
- Logical fixture groups: 24/24 verified.
- Fixture scenarios: 25.
- Verified scenarios: 25.
- Blocked groups: 0.
- Total invariants: 90.
- High-priority invariants: 80/80 verified (100%).
- Database authority: isolated MariaDB 11.4 `budget_privacy_parity_test`; `schema.sql` is canonical, while historical migrations are recorded rather than replayed during parity bootstrap.
- Production seams: optional fixed UTC clocks in RecurringExpenseService, MonthOverviewService, and MonthCloseoutService; production defaults remain unchanged.
- Production defect: closeout allocations are retired with `superseded_at`, preserving historical source linkage while active reads exclude superseded rows.
- Safety: synthetic financial data only, isolated database only, no production credentials or data, no real CSV files, and temporary CSV resources cleaned up.
- Determinism: two complete clean regenerations matched byte-for-byte.
