<?php

declare(strict_types=1);

use App\Auth\AuthIdentityRepository;
use App\Auth\AuthMethodService;
use App\Auth\PasswordCredentialRepository;
use App\Core\Config;
use App\Monitoring\StructuredLogger;

require __DIR__ . '/../src/bootstrap.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDOUT, "Skipping auth method domain test: PDO sqlite is unavailable\n");
    exit(0);
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE auth_identities (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, provider TEXT NOT NULL, provider_subject TEXT NOT NULL, provider_email TEXT NULL, provider_email_verified INTEGER NULL, last_used_at TEXT NULL, created_at TEXT NOT NULL)');
$pdo->exec('CREATE TABLE password_credentials (user_id INTEGER PRIMARY KEY, password_hash TEXT NOT NULL, last_used_at TEXT NULL, password_changed_at TEXT NULL, created_at TEXT NOT NULL)');
$pdo->exec("INSERT INTO auth_identities (user_id, provider, provider_subject, provider_email, provider_email_verified, created_at, last_used_at) VALUES (10, 'google', 'opaque-subject', 'google@example.test', 1, '2026-08-14 20:00:00', '2026-08-14 20:15:00')");
$pdo->exec("INSERT INTO password_credentials (user_id, password_hash, created_at) VALUES (20, 'test-hash', '2026-08-14 20:00:00')");

$service = new AuthMethodService(new AuthIdentityRepository($pdo), new PasswordCredentialRepository($pdo), new StructuredLogger(Config::load(dirname(__DIR__))));
$google = $service->listForUser(10);
if (count($google) !== 1 || $google[0]->type !== 'google' || $google[0]->providerEmail !== 'google@example.test') throw new RuntimeException('Google method inventory is incorrect');
$password = $service->listForUser(20);
if (count($password) !== 1 || $password[0]->type !== 'password' || $password[0]->providerEmail !== null) throw new RuntimeException('Password method inventory is incorrect');
$pdo->exec("INSERT INTO auth_identities (user_id, provider, provider_subject, provider_email, provider_email_verified, created_at) VALUES (20, 'google', 'second-subject', NULL, 1, '2026-08-14 20:00:00')");
if (count($service->listForUser(20)) !== 2) throw new RuntimeException('Unexpected multi-method state was hidden');
echo "Auth method domain tests passed\n";
