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
    'INSERT INTO users (email, display_name, auth_provider, password_hash, email_verified, role) VALUES (:email, :display_name, :auth_provider, :password_hash, 1, :role)'
);
$insert->execute([
    ':email' => strtolower($email),
    ':display_name' => $displayName,
    ':auth_provider' => 'password',
    ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ':role' => 'owner',
]);

echo "Owner created with id " . $pdo->lastInsertId() . "\n";
