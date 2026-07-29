<?php

declare(strict_types=1);

$composerAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($composerAutoload)) {
    throw new RuntimeException('Composer dependencies are missing; run composer install');
}
require_once $composerAutoload;

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

    if (file_exists($path)) {
        require $path;
    }
});
