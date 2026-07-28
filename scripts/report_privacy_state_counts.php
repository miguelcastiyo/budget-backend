<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$config = \App\Core\Config::load(dirname(__DIR__));
$pdo = \App\Database\Connection::make($config);
$rows = $pdo->query("SELECT financial_privacy_state AS state, COUNT(*) AS account_count FROM users GROUP BY financial_privacy_state ORDER BY FIELD(financial_privacy_state, 'vault_setup_required', 'legacy_plaintext', 'migration_in_progress', 'migration_failed', 'encrypted')")->fetchAll(PDO::FETCH_ASSOC);
$counts = array_fill_keys(['vault_setup_required', 'legacy_plaintext', 'migration_in_progress', 'migration_failed', 'encrypted'], 0);
foreach ($rows as $row) $counts[(string) $row['state']] = (int) $row['account_count'];
echo json_encode(['counts' => $counts], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
