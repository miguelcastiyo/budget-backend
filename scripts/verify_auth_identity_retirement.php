<?php

declare(strict_types=1);

use App\Core\Config;
use App\Database\Connection;

require __DIR__ . '/../src/bootstrap.php';

$requestedUserId = null;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--user-id=([1-9][0-9]*)$/', $argument, $match)) { $requestedUserId = (int) $match[1]; continue; }
    fwrite(STDERR, "Usage: php scripts/verify_auth_identity_retirement.php [--user-id=ID]\n");
    exit(2);
}
$pdo = Connection::make(Config::load(dirname(__DIR__)));
/** @param array<string,mixed> $parameters */
$count = static function (string $sql, array $parameters = []) use ($pdo): int { $stmt = $pdo->prepare($sql); $stmt->execute($parameters); return (int) $stmt->fetchColumn(); };

$report = [
    'ok' => false,
    'counts' => [
        'active_users' => $count('SELECT COUNT(*) FROM users WHERE is_active = 1'),
        'users_with_zero_methods' => $count("SELECT COUNT(*) FROM users u WHERE u.is_active = 1 AND (SELECT COUNT(*) FROM auth_identities ai WHERE ai.user_id = u.id) + (SELECT COUNT(*) FROM password_credentials pc WHERE pc.user_id = u.id) = 0"),
        'users_with_one_method' => $count("SELECT COUNT(*) FROM users u WHERE u.is_active = 1 AND (SELECT COUNT(*) FROM auth_identities ai WHERE ai.user_id = u.id) + (SELECT COUNT(*) FROM password_credentials pc WHERE pc.user_id = u.id) = 1"),
        'users_with_two_methods' => $count("SELECT COUNT(*) FROM users u WHERE u.is_active = 1 AND (SELECT COUNT(*) FROM auth_identities ai WHERE ai.user_id = u.id) + (SELECT COUNT(*) FROM password_credentials pc WHERE pc.user_id = u.id) = 2"),
    ],
    'failures' => [
        'auth_identity_orphans' => $count('SELECT COUNT(*) FROM auth_identities ai LEFT JOIN users u ON u.id = ai.user_id WHERE u.id IS NULL'),
        'password_credential_orphans' => $count('SELECT COUNT(*) FROM password_credentials pc LEFT JOIN users u ON u.id = pc.user_id WHERE u.id IS NULL'),
        'session_user_orphans' => $count('SELECT COUNT(*) FROM user_sessions s LEFT JOIN users u ON u.id = s.user_id WHERE u.id IS NULL'),
        'blank_password_hashes' => $count("SELECT COUNT(*) FROM password_credentials WHERE TRIM(password_hash) = ''"),
        'duplicate_provider_subjects' => $count('SELECT COUNT(*) FROM (SELECT provider, provider_subject FROM auth_identities GROUP BY provider, provider_subject HAVING COUNT(*) > 1) duplicate_identities'),
        'duplicate_user_provider_mappings' => $count('SELECT COUNT(*) FROM (SELECT user_id, provider FROM auth_identities GROUP BY user_id, provider HAVING COUNT(*) > 1) duplicate_mappings'),
    ],
];
if ($requestedUserId !== null) {
    $user = $pdo->prepare('SELECT id, is_active FROM users WHERE id = :user_id LIMIT 1');
    $user->execute([':user_id' => $requestedUserId]);
    $row = $user->fetch(PDO::FETCH_ASSOC);
    $report['requested_google_user'] = [
        'found_and_active' => is_array($row) && (int) $row['is_active'] === 1,
        'google_identity_count' => $count("SELECT COUNT(*) FROM auth_identities WHERE user_id = :user_id AND provider = 'google' AND TRIM(provider_subject) <> ''", [':user_id' => $requestedUserId]),
        'password_credential_count' => $count('SELECT COUNT(*) FROM password_credentials WHERE user_id = :user_id', [':user_id' => $requestedUserId]),
        'session_row_count' => $count('SELECT COUNT(*) FROM user_sessions WHERE user_id = :user_id', [':user_id' => $requestedUserId]),
    ];
}
$requestedPasses = !isset($report['requested_google_user']) || ($report['requested_google_user']['found_and_active'] && ($report['requested_google_user']['google_identity_count'] + $report['requested_google_user']['password_credential_count']) >= 1);
$report['ok'] = $report['counts']['users_with_zero_methods'] === 0 && array_sum($report['failures']) === 0 && $requestedPasses;
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($report['ok'] ? 0 : 1);
