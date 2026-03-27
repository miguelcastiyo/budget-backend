<?php

declare(strict_types=1);

use App\Core\Config;
use App\Database\Connection;

require __DIR__ . '/../src/bootstrap.php';

$config = Config::load(dirname(__DIR__));
$pdo = Connection::make($config);

$sql = file_get_contents(__DIR__ . '/../schema.sql');
if ($sql === false) {
    fwrite(STDERR, "Failed to read schema.sql\n");
    exit(1);
}

$pdo->exec($sql);
echo "Schema applied successfully.\n";
