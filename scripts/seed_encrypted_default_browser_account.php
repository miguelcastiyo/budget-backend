<?php

declare(strict_types=1);

if (getenv('PRIVACY_PARITY_TEST') !== '1') { fwrite(STDERR, "Refusing encrypted-by-default browser seed: set PRIVACY_PARITY_TEST=1\n"); exit(2); }
$dsn = (string) getenv('DB_DSN');
if (!preg_match('/dbname=[^;]*_privacy_parity_test(?:;|$)/', $dsn)) { fwrite(STDERR, "Refusing non-parity database\n"); exit(2); }
require __DIR__ . '/../src/bootstrap.php';
$pdo = new PDO($dsn, (string) getenv('DB_USER'), (string) getenv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$suffix = preg_replace('/[^a-z0-9]/i', '', (string) (getenv('ENCRYPTED_DEFAULT_BROWSER_SUFFIX') ?: bin2hex(random_bytes(5))));
$email = "encrypted-default-browser-{$suffix}@example.test";
$password = 'encrypted-default-browser-password';
$pdo->beginTransaction();
try {
    $old = $pdo->prepare('SELECT id FROM users WHERE email = :email'); $old->execute([':email' => $email]);
    foreach ($old->fetchAll(PDO::FETCH_COLUMN) as $oldUserId) {
        foreach (['encrypted_record_batches','encrypted_record_changes','encrypted_financial_records','encrypted_record_sync_state','user_financial_vaults','user_sessions','password_credentials','auth_identities'] as $table) $pdo->prepare("DELETE FROM {$table} WHERE user_id = :uid")->execute([':uid' => (int) $oldUserId]);
        $pdo->prepare('DELETE FROM users WHERE id = :uid')->execute([':uid' => (int) $oldUserId]);
    }
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (email, display_name, email_verified, role, is_active) VALUES (:email, 'Encrypted Default Browser', 1, 'member', 1)")->execute([':email' => $email]);
    $userId = (int) $pdo->lastInsertId(); $pdo->prepare('INSERT INTO password_credentials (user_id, password_hash) VALUES (:user_id, :password_hash)')->execute([':user_id' => $userId, ':password_hash' => $passwordHash]); $sessionId = "encrypted-default-seed_{$suffix}"; $sessionSecret = "encrypted-default-seed-secret-{$suffix}"; $csrfToken = "encrypted-default-csrf-{$suffix}";
    $pdo->prepare("INSERT INTO user_sessions (session_id, user_id, session_secret_hash, csrf_token_hash, client_type, created_at, expires_at) VALUES (:sid, :uid, :secret, :csrf, 'web', UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR))")->execute([':sid' => $sessionId, ':uid' => $userId, ':secret' => hash('sha256', $sessionSecret), ':csrf' => hash('sha256', $csrfToken)]);
    $pdo->commit(); echo json_encode(['email' => $email, 'password' => $password, 'user_id' => $userId, 'session_token' => $sessionId . '.' . $sessionSecret, 'csrf_token' => $csrfToken], JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
