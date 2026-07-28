<?php

declare(strict_types=1);

namespace App\Database;

use App\Core\Config;
use PDO;

final class Connection
{
    public static function make(Config $config): PDO
    {
        $dsn = (string) $config->get('DB_DSN', '');
        $user = (string) $config->get('DB_USER', '');
        $pass = (string) $config->get('DB_PASS', '');
        $timeout = max(1, $config->getInt('DB_CONNECT_TIMEOUT_SECONDS', 5));

        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => $timeout,
        ]);

        // MySQL/MariaDB returns TIMESTAMP values in the connection timezone.
        // The application treats database timestamps as UTC, so make that
        // contract explicit instead of inheriting the host's local timezone.
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $pdo->exec("SET time_zone = '+00:00'");
        }

        return $pdo;
    }
}
