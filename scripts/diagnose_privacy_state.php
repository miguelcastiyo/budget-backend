<?php

declare(strict_types=1);

/*
 * Privacy-safe operational diagnostic.
 *
 * It emits aggregate counts only: no emails, user IDs, financial amounts,
 * ciphertext, filenames, request payloads, or row contents. It is read-only.
 * The explicit confirmation flag prevents accidental execution against a
 * database that has not been selected for evidence capture.
 */

if (getenv('PRIVACY_EVIDENCE_CONFIRM') !== '1') {
    fwrite(STDERR, "Refusing privacy evidence diagnostic: set PRIVACY_EVIDENCE_CONFIRM=1\n");
    exit(2);
}

require_once dirname(__DIR__) . '/src/bootstrap.php';

use App\Core\Config;
use App\Database\Connection;

$root = dirname(__DIR__);
$config = Config::load($root);
$pdo = Connection::make($config);
$tables = [
    'users',
    'user_sessions',
    'user_financial_vaults',
    'vault_quick_unlock_credentials',
    'webauthn_challenges',
    'encrypted_record_batches',
    'encrypted_record_sync_state',
    'encrypted_financial_records',
    'encrypted_record_changes',
    'audit_logs',
];

$present = [];
$tableRows = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tableRows as $table) {
    $present[(string) $table] = true;
}

$countTable = static function (PDO $pdo, string $table, array $present): int {
    if (!isset($present[$table])) {
        return 0;
    }
    return (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
};

$grouped = static function (PDO $pdo, string $table, string $column, array $present): array {
    if (!isset($present[$table])) {
        return [];
    }
    $stmt = $pdo->query('SELECT ' . $column . ' AS state, COUNT(*) AS total FROM ' . $table . ' GROUP BY ' . $column . ' ORDER BY ' . $column);
    $result = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $result[(string) ($row['state'] ?? 'null')] = (int) $row['total'];
    }
    return $result;
};

$rowCounts = [];
foreach ($tables as $table) {
    $rowCounts[$table] = $countTable($pdo, $table, $present);
}

$report = [
    'diagnostic_version' => 'phase4_encrypted_schema_v1',
    'generated_at_utc' => gmdate('c'),
    'environment_label' => getenv('PRIVACY_EVIDENCE_ENV') ?: 'unspecified',
    'privacy_state_counts' => $grouped($pdo, 'users', 'financial_privacy_state', $present),
    'row_counts' => $rowCounts,
    'schema_tables_present' => count($present),
    'schema_tables_expected_in_diagnostic' => count($tables),
    'privacy_safe_scope' => [
        'includes_user_ids' => false,
        'includes_emails' => false,
        'includes_financial_values' => false,
        'includes_ciphertext' => false,
        'read_only' => true,
    ],
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
