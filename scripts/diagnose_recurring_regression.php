<?php

declare(strict_types=1);

use App\Core\Config;
use App\Database\Connection;

/**
 * Read-only recurrence regression diagnostic.
 *
 * Usage:
 *   php scripts/diagnose_recurring_regression.php --since=2026-07-30T16:24:00Z
 *   php scripts/diagnose_recurring_regression.php --since=... --user-id=123
 *
 * This script never writes, repairs, or locks application records. The
 * --since boundary is required so a production run is an explicit review
 * request rather than an implicit scan/repair operation.
 */
require __DIR__ . '/../src/bootstrap.php';

$args = [];
foreach (array_slice($argv, 1) as $argument) {
    if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
        fwrite(STDERR, "Usage: php scripts/diagnose_recurring_regression.php --since=ISO-8601 [--user-id=ID]\n");
        exit(2);
    }
    [$key, $value] = explode('=', substr($argument, 2), 2);
    $args[$key] = $value;
}

$since = $args['since'] ?? '';
if ($since === '' || strtotime($since) === false) {
    fwrite(STDERR, "--since=ISO-8601 is required. No database query was run.\n");
    exit(2);
}

$config = Config::load(dirname(__DIR__));
$pdo = Connection::make($config);
$pdo->exec('SET SESSION TRANSACTION READ ONLY');
$pdo->beginTransaction();

$ruleWhere = '';
$occurrenceWhere = '';
$transactionWhere = '';
$sinceValue = date('Y-m-d H:i:s', strtotime($since));
$params = [':since_created' => $sinceValue, ':since_updated' => $sinceValue];
if (isset($args['user-id']) && ctype_digit($args['user-id'])) {
    $ruleWhere = ' AND re.user_id = :user_id';
    $occurrenceWhere = ' AND reo.user_id = :user_id';
    $transactionWhere = ' AND t.user_id = :user_id';
    $params[':user_id'] = (int) $args['user-id'];
}

/** @return list<array<string,mixed>> */
function rows(PDO $pdo, string $sql, array $params): array
{
    preg_match_all('/:([a-z_][a-z0-9_]*)/i', $sql, $matches);
    $used = array_fill_keys(array_map(static fn (string $name): string => ':' . $name, array_unique($matches[1])), true);
    $params = array_intersect_key($params, $used);
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll();
}

$report = [
    'read_only' => true,
    'since' => $sinceValue,
    'user_id' => $params[':user_id'] ?? null,
    'notes' => [
        'billing_day_matches_transaction_day' => 'Candidate only; matching the seed date can be valid and requires review.',
        'transaction_linkage_changes' => 'Without an audit/history table this identifies review candidates, not proven changes.',
        'no_repairs_run' => true,
    ],
    'rules_created_or_updated_after_cutoff' => rows($pdo, "
        SELECT id, user_id, series_id, expense, amount, billing_type, billing_day,
               starts_month, ends_month, is_active, created_at, updated_at
        FROM recurring_expenses re
        WHERE (re.created_at >= :since_created OR re.updated_at >= :since_updated) {$ruleWhere}
        ORDER BY user_id, updated_at, id
    ", $params),
    'billing_day_matches_linked_transaction_day' => rows($pdo, "
        SELECT re.id AS recurring_expense_id, re.user_id, re.expense,
               re.billing_type, re.billing_day, reo.occurrence_month,
               reo.due_date, t.id AS transaction_id, t.transaction_date
        FROM recurring_expenses re
        JOIN recurring_expense_occurrences reo
          ON reo.user_id = re.user_id AND reo.recurring_expense_id = re.id
        JOIN transactions t
          ON t.user_id = reo.user_id AND t.id = reo.transaction_id
        WHERE (re.created_at >= :since_created OR re.updated_at >= :since_updated)
          AND re.billing_type = 'day_of_month'
          AND re.billing_day = DAYOFMONTH(t.transaction_date)
          {$ruleWhere}
        ORDER BY re.user_id, re.id, reo.occurrence_month
    ", $params),
    'invalid_billing_rules' => rows($pdo, "
        SELECT id, user_id, series_id, expense, billing_type, billing_day,
               starts_month, ends_month, created_at, updated_at
        FROM recurring_expenses re
        WHERE ((billing_type = 'day_of_month' AND (billing_day IS NULL OR billing_day < 1 OR billing_day > 31))
            OR (billing_type = 'last_day' AND billing_day IS NOT NULL))
          {$ruleWhere}
        ORDER BY user_id, id
    ", $params),
    'duplicate_recurring_rules' => rows($pdo, "
        SELECT user_id, series_id, starts_month, COUNT(*) AS rule_count,
               GROUP_CONCAT(id ORDER BY id) AS rule_ids
        FROM recurring_expenses re
        WHERE 1 = 1 {$ruleWhere}
        GROUP BY user_id, series_id, starts_month
        HAVING COUNT(*) > 1
        ORDER BY user_id, series_id, starts_month
    ", $params),
    'duplicate_occurrences' => rows($pdo, "
        SELECT user_id, recurring_expense_id, occurrence_month, COUNT(*) AS occurrence_count,
               GROUP_CONCAT(id ORDER BY id) AS occurrence_ids
        FROM recurring_expense_occurrences reo
        WHERE 1 = 1 {$occurrenceWhere}
        GROUP BY user_id, recurring_expense_id, occurrence_month
        HAVING COUNT(*) > 1
        ORDER BY user_id, recurring_expense_id, occurrence_month
    ", $params),
    'same_transaction_linked_to_multiple_occurrences' => rows($pdo, "
        SELECT user_id, transaction_id, COUNT(*) AS occurrence_count,
               GROUP_CONCAT(id ORDER BY id) AS occurrence_ids
        FROM recurring_expense_occurrences reo
        WHERE transaction_id IS NOT NULL {$occurrenceWhere}
        GROUP BY user_id, transaction_id
        HAVING COUNT(*) > 1
        ORDER BY user_id, transaction_id
    ", $params),
    'duplicate_generated_transaction_candidates' => rows($pdo, "
        SELECT t.user_id, t.transaction_date, t.expense, t.amount, t.source,
               COUNT(*) AS transaction_count, GROUP_CONCAT(t.id ORDER BY t.id) AS transaction_ids
        FROM transactions t
        WHERE t.source = 'recurring' {$transactionWhere}
        GROUP BY t.user_id, t.transaction_date, t.expense, t.amount, t.source
        HAVING COUNT(*) > 1
        ORDER BY t.user_id, t.transaction_date, t.expense
    ", $params),
    'orphan_occurrences' => rows($pdo, "
        SELECT reo.id, reo.user_id, reo.recurring_expense_id, reo.occurrence_month,
               reo.due_date, reo.transaction_id
        FROM recurring_expense_occurrences reo
        WHERE reo.transaction_id IS NULL {$occurrenceWhere}
        ORDER BY reo.user_id, reo.occurrence_month, reo.id
    ", $params),
    'recurrence_linkage_review_candidates' => rows($pdo, "
        SELECT reo.id AS occurrence_id, reo.user_id, reo.recurring_expense_id,
               reo.transaction_id, reo.occurrence_month, reo.due_date,
               t.source AS transaction_source, t.transaction_date
        FROM recurring_expense_occurrences reo
        LEFT JOIN transactions t ON t.user_id = reo.user_id AND t.id = reo.transaction_id
        WHERE (reo.transaction_id IS NULL OR t.id IS NULL OR t.source <> 'recurring')
          {$occurrenceWhere}
        ORDER BY reo.user_id, reo.occurrence_month, reo.id
    ", $params),
];

$pdo->rollBack();
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
