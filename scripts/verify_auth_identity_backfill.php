<?php

declare(strict_types=1);

use App\Core\Config;
use App\Database\Connection;

require __DIR__ . '/../src/bootstrap.php';

$requestedUserId = null;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--user-id=([1-9][0-9]*)$/', $argument, $match)) {
        $requestedUserId = (int) $match[1];
        continue;
    }
    fwrite(STDERR, "Usage: php scripts/verify_auth_identity_backfill.php [--user-id=ID]\n");
    exit(2);
}

$pdo = Connection::make(Config::load(dirname(__DIR__)));

/** @param array<string, mixed> $parameters */
$count = static function (string $sql, array $parameters = []) use ($pdo): int {
    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);
    return (int) $statement->fetchColumn();
};

$requiredTables = ['users', 'user_sessions', 'auth_identities', 'password_credentials'];
$missingTables = [];
foreach ($requiredTables as $table) {
    if ($count('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table', [':table' => $table]) === 0) {
        $missingTables[] = $table;
    }
}
$sessionColumnExists = $count("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_sessions' AND COLUMN_NAME = 'last_authenticated_at'") === 1;

$ownershipTables = $pdo->query(
    "SELECT DISTINCT TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'users' AND COLUMN_NAME = 'user_id' ORDER BY TABLE_NAME"
)->fetchAll(PDO::FETCH_COLUMN);

$report = [
    'ok' => false,
    'missing_tables' => $missingTables,
    'session_last_authenticated_at_present' => $sessionColumnExists,
    'counts' => [
        'legacy_google_users' => $count("SELECT COUNT(*) FROM users WHERE auth_provider = 'google'"),
        'google_auth_identities' => $count("SELECT COUNT(*) FROM auth_identities WHERE provider = 'google'"),
        'legacy_password_users' => $count("SELECT COUNT(*) FROM users WHERE auth_provider = 'password'"),
        'password_credentials' => $count('SELECT COUNT(*) FROM password_credentials'),
    ],
    'failures' => [
        'invalid_legacy_auth_state' => $count("SELECT COUNT(*) FROM users WHERE auth_provider NOT IN ('google', 'password') OR (auth_provider = 'google' AND (google_sub IS NULL OR password_hash IS NOT NULL)) OR (auth_provider = 'password' AND (password_hash IS NULL OR google_sub IS NOT NULL))"),
        'google_mapping_mismatches' => $count("SELECT COUNT(*) FROM users u LEFT JOIN auth_identities ai ON ai.user_id = u.id AND ai.provider = 'google' AND BINARY ai.provider_subject = BINARY u.google_sub WHERE u.auth_provider = 'google' AND ai.id IS NULL"),
        'password_mapping_mismatches' => $count("SELECT COUNT(*) FROM users u LEFT JOIN password_credentials pc ON pc.user_id = u.id AND BINARY pc.password_hash = BINARY u.password_hash WHERE u.auth_provider = 'password' AND pc.user_id IS NULL"),
        'unexpected_identity_rows' => $count("SELECT COUNT(*) FROM auth_identities ai WHERE ai.provider <> 'google' OR NOT EXISTS (SELECT 1 FROM users u WHERE u.id = ai.user_id AND u.auth_provider = 'google' AND BINARY u.google_sub = BINARY ai.provider_subject)"),
        'unexpected_password_credential_rows' => $count("SELECT COUNT(*) FROM password_credentials pc WHERE NOT EXISTS (SELECT 1 FROM users u WHERE u.id = pc.user_id AND u.auth_provider = 'password' AND BINARY u.password_hash = BINARY pc.password_hash)"),
        'orphan_auth_identities' => $count('SELECT COUNT(*) FROM auth_identities ai LEFT JOIN users u ON u.id = ai.user_id WHERE u.id IS NULL'),
        'orphan_password_credentials' => $count('SELECT COUNT(*) FROM password_credentials pc LEFT JOIN users u ON u.id = pc.user_id WHERE u.id IS NULL'),
        'duplicate_google_provider_subjects' => $count("SELECT COUNT(*) FROM (SELECT provider_subject FROM auth_identities WHERE provider = 'google' GROUP BY provider_subject HAVING COUNT(*) > 1) duplicate_subjects"),
        'duplicate_user_provider_mappings' => $count('SELECT COUNT(*) FROM (SELECT user_id, provider FROM auth_identities GROUP BY user_id, provider HAVING COUNT(*) > 1) duplicate_mappings'),
    ],
    'ownership_foreign_key_tables' => array_values(array_filter($ownershipTables, static fn(mixed $table): bool => is_string($table))),
];

if ($requestedUserId !== null) {
    $user = $pdo->prepare("SELECT id, auth_provider, google_sub FROM users WHERE id = :user_id AND auth_provider = 'google' LIMIT 1");
    $user->execute([':user_id' => $requestedUserId]);
    $legacyGoogleUser = $user->fetch(PDO::FETCH_ASSOC);
    $identityCount = $legacyGoogleUser === false ? 0 : $count("SELECT COUNT(*) FROM auth_identities WHERE user_id = :user_id AND provider = 'google' AND BINARY provider_subject = BINARY :provider_subject", [':user_id' => $requestedUserId, ':provider_subject' => $legacyGoogleUser['google_sub']]);
    $ownershipCounts = [];
    foreach ($report['ownership_foreign_key_tables'] as $table) {
        // Information-schema table names are DB-derived, not caller input.
        $ownershipCounts[$table] = $count("SELECT COUNT(*) FROM `{$table}` WHERE user_id = :user_id", [':user_id' => $requestedUserId]);
    }
    $report['requested_google_user'] = [
        'found_as_legacy_google_user' => $legacyGoogleUser !== false,
        'exact_google_identity_count' => $identityCount,
        'ownership_row_counts' => $ownershipCounts,
    ];
}

$countParity = $report['counts']['legacy_google_users'] === $report['counts']['google_auth_identities']
    && $report['counts']['legacy_password_users'] === $report['counts']['password_credentials'];
$noFailures = array_sum($report['failures']) === 0;
$requestedUserPasses = !isset($report['requested_google_user'])
    || ($report['requested_google_user']['found_as_legacy_google_user'] && $report['requested_google_user']['exact_google_identity_count'] === 1);
$report['ok'] = $missingTables === [] && $sessionColumnExists && $countParity && $noFailures && $requestedUserPasses;

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($report['ok'] ? 0 : 1);
