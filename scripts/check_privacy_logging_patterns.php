<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$findings = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $path = $file->getPathname();
    if (str_contains($path, DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR)) continue;
    $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
    foreach ($lines as $number => $line) {
        if (preg_match('/error_log\s*\([^;]*(?:\$request|php:\/\/input)/i', $line) === 1
            || preg_match('/json_encode\s*\(\s*\$_(?:POST|REQUEST)/i', $line) === 1
            || preg_match('/(?:logger|audit)->(?:error|warning|log|record)\s*\([^;]*\$(?:request|payload|body)/i', $line) === 1
            || preg_match('/(?:var_dump|print_r)\s*\(/i', $line) === 1) {
            $findings[] = $path . ':' . ($number + 1);
        }
    }
}
if ($findings !== []) {
    fwrite(STDERR, "Unsafe operational logging patterns found:\n" . implode("\n", $findings) . "\n");
    exit(1);
}
echo "Privacy logging pattern scan passed\n";
