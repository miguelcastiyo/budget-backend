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

        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
