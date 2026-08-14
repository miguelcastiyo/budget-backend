<?php

declare(strict_types=1);

use App\Core\Config;
use App\Database\Connection;

require __DIR__ . '/../src/bootstrap.php';

if ($argc < 4) {
    fwrite(STDERR, "Usage: php scripts/seed_owner.php <email> <display_name> <password>\n");
    exit(1);
}

[$script, $email, $displayName, $password] = $argv;

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Invalid email\n");
    exit(1);
}

$config = Config::load(dirname(__DIR__));
$pdo = Connection::make($config);

$stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
$stmt->execute([':email' => strtolower($email)]);
if ($stmt->fetch()) {
    fwrite(STDERR, "User already exists for email: {$email}\n");
    exit(1);
}

$insert = $pdo->prepare(
    'INSERT INTO users (email, display_name, email_verified, role, financial_privacy_state) VALUES (:email, :display_name, 1, :role, \'vault_setup_required\')'
);
$passwordHash = password_hash($password, PASSWORD_DEFAULT);
$insert->execute([
    ':email' => strtolower($email),
    ':display_name' => $displayName,
    ':role' => 'owner',
]);

$userId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO password_credentials (user_id, password_hash) VALUES (:user_id, :password_hash)')->execute([':user_id' => $userId, ':password_hash' => $passwordHash]);
echo "Owner created with id {$userId}\n";
