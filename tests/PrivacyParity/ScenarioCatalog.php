<?php

declare(strict_types=1);

namespace PrivacyParity;

final class ScenarioCatalog
{
    /** @return array<string, array<string, mixed>> */
    public static function groups(): array
    {
        $groups = [
            'FIX-TXN-001' => ['domain' => 'transactions', 'scenario_id' => 'transaction.crud-validation', 'description' => 'CRUD validation and normalized notes', 'source' => 'encrypted-domain fixture corpus', 'invariants' => ['INV-TXN-001', 'INV-TXN-002', 'INV-TXN-003', 'INV-TXN-004', 'INV-TXN-005', 'INV-TXN-006', 'INV-TXN-007']],
            'FIX-TXN-002' => ['domain' => 'transactions', 'scenario_id' => 'transaction.filter-summary-pagination', 'description' => 'Filter, search, summary, and pagination', 'source' => 'encrypted-domain fixture corpus', 'invariants' => ['INV-TXN-008', 'INV-TXN-009', 'INV-TXN-010']],
            'FIX-TXN-003' => ['domain' => 'transactions', 'scenario_id' => 'transaction.suggestion-ranking', 'description' => 'Suggestion ranking and tie behavior', 'source' => 'encrypted-domain fixture corpus', 'invariants' => ['INV-TXN-011']],
            'FIX-TXN-004' => ['domain' => 'transactions', 'scenario_id' => 'transaction.duplicate-import-linkage', 'description' => 'Duplicate fingerprint and import linkage', 'source' => 'encrypted-domain fixture corpus', 'invariants' => ['INV-TXN-012']],
            'FIX-TXN-005' => ['domain' => 'transactions', 'scenario_id' => 'transaction.fund-linked-mutation', 'description' => 'Fund-linked transaction mutation', 'source' => 'encrypted-domain fixture corpus', 'invariants' => ['INV-TXN-013']],
            'FIX-TAX-001' => ['domain' => 'taxonomy', 'scenario_id' => 'taxonomy.lifecycle-and-icons', 'description' => 'Uniqueness, reactivation, favorites, and icons', 'source' => 'encrypted-domain fixture corpus', 'invariants' => ['INV-TAX-001', 'INV-TAX-002', 'INV-TAX-003', 'INV-TAX-005'], 'scenario_variants' => [['scenario_id' => 'taxonomy.historical-relationships-after-delete', 'description' => 'Historical transaction relationships survive taxonomy deletion', 'invariants' => ['INV-TAX-007']]]],
            'FIX-BUD-001' => ['domain' => 'budgets', 'scenario_id' => 'budget.allocation-validation', 'description' => 'Percent and amount allocation validation', 'source' => 'encrypted-domain fixture corpus', 'invariants' => ['INV-BUDGET-001', 'INV-BUDGET-002']],
            'FIX-BUD-002' => ['domain' => 'budgets', 'scenario_id' => 'budget.income-conversion', 'description' => 'Income breakdown and hourly conversion', 'source' => 'encrypted-domain fixture corpus', 'invariants' => ['INV-BUDGET-003', 'INV-BUDGET-004']],
            'FIX-BUD-003' => ['domain' => 'budgets', 'scenario_id' => 'budget.effective-month-inheritance', 'description' => 'Effective-month inheritance and history', 'source' => 'encrypted-domain fixture corpus', 'invariants' => ['INV-BUDGET-005', 'INV-BUDGET-006', 'INV-BUDGET-008']],
            'FIX-REC-001' => ['domain' => 'recurring', 'scenario_id' => 'recurring.schedule-generation', 'description' => 'Schedule and date generation', 'source' => 'encrypted-domain fixture corpus', 'invariants' => ['INV-REC-002', 'INV-REC-003', 'INV-REC-004', 'INV-REC-006']],
            'FIX-REC-002' => ['domain' => 'recurring', 'scenario_id' => 'recurring.version-change-delete', 'description' => 'Version overlap, change, and delete', 'source' => 'encrypted-domain fixture corpus', 'invariants' => ['INV-REC-001', 'INV-REC-008', 'INV-REC-009']],
            'FIX-REC-003' => ['domain' => 'recurring', 'scenario_id' => 'recurring.idempotent-materialization', 'description' => 'Idempotent occurrence materialization', 'source' => 'encrypted-domain fixture corpus', 'invariants' => ['INV-TXN-014', 'INV-REC-005', 'INV-REC-010']],
            'FIX-OVR-001' => ['domain' => 'overview', 'scenario_id' => 'overview.month-summary', 'description' => 'Month overview totals and progress', 'source' => 'encrypted-domain fixture corpus', 'invariants' => ['INV-OVR-001', 'INV-OVR-002', 'INV-OVR-003']],
            'FIX-OVR-002' => ['domain' => 'insights', 'scenario_id' => 'insights.range-aggregates', 'description' => 'Insights ranges, rankings, and distributions', 'source' => 'encrypted-domain fixture corpus', 'invariants' => ['INV-OVR-004', 'INV-OVR-005']],
            'FIX-FUND-001' => ['domain' => 'funds', 'scenario_id' => 'fund.ledger-balance-goal', 'description' => 'Ledger balance and goal state', 'source' => 'encrypted-domain fixture corpus', 'invariants' => ['INV-FUND-001', 'INV-FUND-002', 'INV-FUND-003', 'INV-FUND-004', 'INV-FUND-005']],
            'FIX-FUND-002' => ['domain' => 'funds', 'scenario_id' => 'fund.archive-source-restrictions', 'description' => 'Archived and source restrictions', 'source' => 'encrypted-domain fixture corpus', 'invariants' => ['INV-FUND-006', 'INV-FUND-007', 'INV-FUND-008']],
            'FIX-FUND-003' => ['domain' => 'funds', 'scenario_id' => 'fund.transaction-closeout-linkage', 'description' => 'Transaction and closeout linkage', 'source' => 'encrypted-domain fixture corpus', 'invariants' => ['INV-FUND-009', 'INV-FUND-010', 'INV-FUND-011', 'INV-FUND-013']],
            'FIX-SAV-001' => ['domain' => 'savings-plan', 'scenario_id' => 'savings-plan.allocation-state', 'description' => 'Plan, no-plan, and over-allocation state', 'source' => 'encrypted-domain fixture corpus', 'invariants' => ['INV-SAVINGS-001', 'INV-SAVINGS-002', 'INV-SAVINGS-003', 'INV-SAVINGS-004']],
            'FIX-SAV-002' => ['domain' => 'savings-plan', 'scenario_id' => 'savings-plan.pacing-directed-activity', 'description' => 'Directed, unplanned, and pacing behavior', 'source' => 'encrypted-domain fixture corpus', 'invariants' => ['INV-SAVINGS-005', 'INV-SAVINGS-007', 'INV-SAVINGS-008']],
            'FIX-CLOSE-001' => ['domain' => 'closeouts', 'scenario_id' => 'closeout.eligibility-result-snapshot', 'description' => 'Eligibility, result, and snapshot', 'source' => 'encrypted-domain fixture corpus', 'invariants' => ['INV-CLOSEOUT-001', 'INV-CLOSEOUT-002', 'INV-CLOSEOUT-003', 'INV-CLOSEOUT-004', 'INV-CLOSEOUT-005', 'INV-CLOSEOUT-006']],
            'FIX-CLOSE-002' => ['domain' => 'closeouts', 'scenario_id' => 'closeout.allocation-reopen-stale', 'description' => 'Allocation, reopen, and stale behavior', 'source' => 'encrypted-domain fixture corpus', 'invariants' => ['INV-CLOSEOUT-007', 'INV-CLOSEOUT-008', 'INV-CLOSEOUT-009', 'INV-CLOSEOUT-010', 'INV-CLOSEOUT-011', 'INV-CLOSEOUT-012']],
            'FIX-CSV-001' => ['domain' => 'csv', 'scenario_id' => 'csv.preview-dry-run-mappings', 'description' => 'Preview, dry-run, and mappings', 'source' => 'encrypted-domain fixture corpus', 'invariants' => ['INV-CSV-001', 'INV-CSV-002', 'INV-CSV-003', 'INV-CSV-004', 'INV-CSV-005', 'INV-CSV-006', 'INV-CSV-007', 'INV-CSV-008', 'INV-CSV-009', 'INV-CSV-010']],
            'FIX-CSV-002' => ['domain' => 'csv', 'scenario_id' => 'csv.commit-rollback-export', 'description' => 'Commit, duplicates, rollback, and export', 'source' => 'encrypted-domain fixture corpus', 'invariants' => ['INV-CSV-011', 'INV-CSV-012']],
            'FIX-CROSS-001' => ['domain' => 'cross-domain', 'scenario_id' => 'cross.transaction-fund-closeout', 'description' => 'Transaction to Fund to closeout lifecycle', 'source' => 'encrypted-domain fixture corpus', 'invariants' => []],
        ];

        foreach ($groups as $id => &$group) {
            $group['group_id'] = $id;
            $group['future_consumer'] = 'future client domain parity runner';
            $group['clock'] = ['now' => '2026-01-15T12:00:00Z', 'timezone' => 'UTC', 'month' => '2026-01'];
            $group['input'] = [
                'before' => ['user' => ['id' => 'user_1', 'email' => 'fixture-user@example.test']],
                'steps' => [['action' => $group['scenario_id'], 'input' => ['case' => 'synthetic-boundary-case']]],
            ];
            if ($id === 'FIX-TXN-001') {
                $group['input']['steps'][0]['input'] = ['valid_create_update_delete' => true, 'invalid_money' => '0.00', 'invalid_category' => 'other', 'cross_user_tag' => true];
            } elseif ($id === 'FIX-TAX-001') {
                $group['input']['steps'][0]['input'] = ['duplicate_name_case_insensitive' => true, 'soft_delete_reactivate' => true, 'allowed_icon' => 'box', 'foreign_ownership' => true];
            } elseif ($id === 'FIX-BUD-001') {
                $group['input']['steps'][0]['input'] = ['valid_percent' => true, 'invalid_percent_total' => true, 'valid_amount' => true, 'invalid_amount_total' => true];
            } elseif ($id === 'FIX-TXN-002') {
                $group['input']['steps'][0]['input'] = ['search' => 'coffee', 'date_range' => ['from' => '2026-01-10', 'to' => '2026-01-12'], 'page_size' => 2, 'summary_over_full_filter' => true];
            } elseif ($id === 'FIX-TXN-003') {
                $group['input']['steps'][0]['input'] = ['queries' => ['target', 'target e', 'town'], 'ranking' => ['exact' => 0, 'prefix' => 1, 'contains' => 2, 'frequency' => true, 'recency' => true, 'tie_breaking' => true]];
            } elseif ($id === 'FIX-TXN-004') {
                $group['input']['steps'][0]['input'] = ['commit_same_csv_twice' => true, 'fingerprint' => true, 'import_run_linkage' => true];
            } elseif ($id === 'FIX-TXN-005') {
                $group['input']['steps'][0]['input'] = ['create_linked_transaction' => true, 'update_transaction' => true, 'delete_transaction' => true];
            } elseif ($id === 'FIX-TAX-001') {
                $group['input']['steps'][0]['input']['historical_relationships_after_delete'] = true;
            } elseif ($id === 'FIX-BUD-002') {
                $group['input']['steps'][0]['input'] = ['monthly_composition' => true, 'hourly_conversion' => true, 'rounding' => true];
            } elseif ($id === 'FIX-BUD-003') {
                $group['input']['steps'][0]['input'] = ['inheritance' => true, 'edit_inherited_month' => true, 'prior_version_preservation' => true, 'resolved_amounts' => true];
            } elseif ($id === 'FIX-REC-001') {
                $group['input']['steps'][0]['input'] = ['day_31' => true, 'short_months' => true, 'february' => true, 'leap_year' => true, 'last_day' => true, 'future_non_generation' => true];
            } elseif ($id === 'FIX-REC-002') {
                $group['input']['steps'][0]['input'] = ['overlap' => true, 'open_ended_versions' => true, 'future_change' => true, 'deletion' => true, 'prior_history' => true];
            } elseif ($id === 'FIX-REC-003') {
                $group['input']['steps'][0]['input'] = ['retry' => true, 'seed_transaction' => true, 'current_month' => true, 'future_non_generation' => true];
            } elseif ($id === 'FIX-OVR-001') {
                $group['input']['steps'][0]['input'] = ['selected_month_boundaries' => true, 'inherited_budget' => true, 'categories' => true, 'tags' => true, 'progress' => true, 'status_cards' => true, 'recurring' => true, 'recent_limit' => 5, 'empty_month' => true];
            } elseif ($id === 'FIX-OVR-002') {
                $group['input']['steps'][0]['input'] = ['date_range' => ['from' => '2026-01-01', 'to' => '2026-02-28'], 'totals' => true, 'weekday' => true, 'largest' => true, 'recurring_variable' => true, 'ties' => true, 'empty_zero_safe' => true];
            } elseif ($id === 'FIX-FUND-001') {
                $group['input']['steps'][0]['input'] = ['zero' => true, 'exact_goal' => true, 'over_goal' => true, 'multiple_funds' => true, 'directions' => true, 'sources' => true];
            } elseif ($id === 'FIX-FUND-002') {
                $group['input']['steps'][0]['input'] = ['archive' => true, 'restore' => true, 'archived_mutation_rejection' => true];
            } elseif ($id === 'FIX-FUND-003') {
                $group['input']['steps'][0]['input'] = ['closeout_source' => true, 'fund_balance' => true, 'summary' => true];
            } elseif ($id === 'FIX-SAV-001') {
                $group['input']['steps'][0]['input'] = ['goal_configuration' => true, 'monthly_allocations' => true, 'over_allocation' => true, 'multiple_funds' => true];
            } elseif ($id === 'FIX-SAV-002') {
                $group['input']['steps'][0]['input'] = ['pacing' => true, 'goal_completion' => true, 'contribution_source' => true, 'month_boundary' => true];
            } elseif ($id === 'FIX-CLOSE-001') {
                $group['input']['steps'][0]['input'] = ['eligibility' => true, 'snapshot' => true, 'multi_fund_allocations' => true, 'idempotency' => true];
            } elseif ($id === 'FIX-CLOSE-002') {
                $group['input']['steps'][0]['input'] = ['replacement' => true, 'reopen' => true, 'voided_entries' => true, 'source_relationships' => true, 'balances' => true];
            } elseif ($id === 'FIX-CSV-001') {
                $group['input']['steps'][0]['input'] = ['preview' => true, 'dry_run' => true, 'mapping' => true, 'yearless_dates' => true, 'category_strategy' => true, 'tag_strategy' => true, 'blank_amount_skip' => true, 'malformed_row' => true];
            } elseif ($id === 'FIX-CSV-002') {
                $group['input']['steps'][0]['input'] = ['commit' => true, 'duplicates' => true, 'rollback' => true, 'export_filters' => true, 'formula_injection' => true];
            } elseif ($id === 'FIX-CROSS-001') {
                $group['input']['steps'][0]['input'] = ['transaction_fund_linkage' => true, 'closeout_replacement' => true, 'reopen' => true, 'historical_source_linkage' => true];
            }
            $group['invariants'] = $group['invariants'] ?? [];
        }
        unset($group);
        return $groups;
    }

    /** @return array<string, string> */
    public static function groupForInvariant(string $id): array
    {
        $direct = [
            'INV-TXN-001' => 'FIX-TXN-001', 'INV-TXN-002' => 'FIX-TXN-001', 'INV-TXN-003' => 'FIX-TXN-001',
            'INV-TXN-004' => 'FIX-TXN-001', 'INV-TXN-005' => 'FIX-TXN-001', 'INV-TXN-006' => 'FIX-TXN-001',
            'INV-TXN-007' => 'FIX-TXN-001', 'INV-TXN-008' => 'FIX-TXN-002', 'INV-TXN-009' => 'FIX-TXN-002',
            'INV-TXN-010' => 'FIX-TXN-002', 'INV-TXN-011' => 'FIX-TXN-003', 'INV-TXN-012' => 'FIX-TXN-004',
            'INV-TXN-013' => 'FIX-TXN-005', 'INV-TXN-014' => 'FIX-REC-003',
            'INV-REC-001' => 'FIX-REC-002', 'INV-REC-002' => 'FIX-REC-001', 'INV-REC-003' => 'FIX-REC-001',
            'INV-REC-004' => 'FIX-REC-001', 'INV-REC-005' => 'FIX-REC-003', 'INV-REC-006' => 'FIX-REC-001',
            'INV-REC-008' => 'FIX-REC-002', 'INV-REC-009' => 'FIX-REC-002', 'INV-REC-010' => 'FIX-REC-003',
            'INV-OVR-001' => 'FIX-OVR-001', 'INV-OVR-002' => 'FIX-OVR-001', 'INV-OVR-003' => 'FIX-OVR-001',
            'INV-OVR-004' => 'FIX-OVR-002', 'INV-OVR-005' => 'FIX-OVR-002',
            'INV-FUND-001' => 'FIX-FUND-001', 'INV-FUND-002' => 'FIX-FUND-001', 'INV-FUND-003' => 'FIX-FUND-001',
            'INV-FUND-004' => 'FIX-FUND-001', 'INV-FUND-005' => 'FIX-FUND-001', 'INV-FUND-006' => 'FIX-FUND-002',
            'INV-FUND-007' => 'FIX-FUND-002', 'INV-FUND-008' => 'FIX-FUND-002', 'INV-FUND-009' => 'FIX-FUND-003',
            'INV-FUND-010' => 'FIX-FUND-003', 'INV-FUND-011' => 'FIX-FUND-003', 'INV-FUND-013' => 'FIX-FUND-003',
            'INV-SAVINGS-001' => 'FIX-SAV-001', 'INV-SAVINGS-002' => 'FIX-SAV-001', 'INV-SAVINGS-003' => 'FIX-SAV-001',
            'INV-SAVINGS-004' => 'FIX-SAV-001', 'INV-SAVINGS-005' => 'FIX-SAV-002', 'INV-SAVINGS-007' => 'FIX-SAV-002',
            'INV-SAVINGS-008' => 'FIX-SAV-002',
            'INV-CLOSEOUT-001' => 'FIX-CLOSE-001', 'INV-CLOSEOUT-002' => 'FIX-CLOSE-001', 'INV-CLOSEOUT-003' => 'FIX-CLOSE-001',
            'INV-CLOSEOUT-004' => 'FIX-CLOSE-001', 'INV-CLOSEOUT-005' => 'FIX-CLOSE-001', 'INV-CLOSEOUT-006' => 'FIX-CLOSE-001',
            'INV-CLOSEOUT-007' => 'FIX-CLOSE-002', 'INV-CLOSEOUT-008' => 'FIX-CLOSE-002', 'INV-CLOSEOUT-009' => 'FIX-CLOSE-002',
            'INV-CLOSEOUT-010' => 'FIX-CLOSE-002', 'INV-CLOSEOUT-011' => 'FIX-CLOSE-002', 'INV-CLOSEOUT-012' => 'FIX-CLOSE-002',
            'INV-TAX-001' => 'FIX-TAX-001', 'INV-TAX-002' => 'FIX-TAX-001', 'INV-TAX-003' => 'FIX-TAX-001',
            'INV-TAX-005' => 'FIX-TAX-001', 'INV-TAX-007' => 'FIX-TAX-001',
            'INV-BUDGET-001' => 'FIX-BUD-001', 'INV-BUDGET-002' => 'FIX-BUD-001',
            'INV-BUDGET-003' => 'FIX-BUD-002', 'INV-BUDGET-004' => 'FIX-BUD-002',
            'INV-BUDGET-005' => 'FIX-BUD-003', 'INV-BUDGET-006' => 'FIX-BUD-003',
            'INV-BUDGET-007' => 'FIX-BUD-003', 'INV-BUDGET-008' => 'FIX-BUD-003',
        ];
        if (isset($direct[$id])) return [$direct[$id]];
        $prefix = explode('-', $id)[1] ?? '';
        $map = [
            'REC' => 'FIX-REC-001', 'OVR' => 'FIX-OVR-001', 'FUND' => 'FIX-FUND-001',
            'SAVINGS' => 'FIX-SAV-001', 'CLOSEOUT' => 'FIX-CLOSE-001', 'CSV' => 'FIX-CSV-001',
        ];
        return isset($map[$prefix]) ? [$map[$prefix]] : [];
    }
}
