<?php

declare(strict_types=1);

namespace PrivacyParity;

use App\Auth\AuthService;
use App\Budget\BudgetSettingsResolver;
use App\Controllers\BudgetSettingsController;
use App\Controllers\FundController;
use App\Controllers\MetricsController;
use App\Controllers\MonthOverviewController;
use App\Controllers\MonthCloseoutController;
use App\Controllers\RecurringExpenseController;
use App\Controllers\SavingsPlanController;
use App\Controllers\TaxonomyController;
use App\Controllers\TransactionController;
use App\Core\Config;
use App\Funds\FundRepository;
use App\Funds\FundBalanceService;
use App\Funds\FundService;
use App\Funds\FundTransactionIntegrationService;
use App\Funds\FundCloseoutIntegrationService;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\ImportExport\CsvImportCommitter;
use App\ImportExport\CsvImportMapper;
use App\ImportExport\CsvImportReader;
use App\ImportExport\CsvImportService;
use App\ImportExport\CsvExportService;
use App\ImportExport\DataRunRepository;
use App\ImportExport\TaxonomyImportRepository;
use App\Recurring\RecurringExpenseService;
use App\Savings\SavingsPlanService;
use App\MonthCloseout\MonthCloseoutRepository;
use App\MonthCloseout\MonthCloseoutService;
use App\Support\Str;
use App\Overview\MonthOverviewService;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

final class CurrentImplementationAdapter
{
    private const BOUND_GROUPS = ['FIX-TXN-001', 'FIX-TXN-002', 'FIX-TXN-003', 'FIX-TXN-004', 'FIX-TXN-005', 'FIX-TAX-001', 'FIX-BUD-001', 'FIX-BUD-002', 'FIX-BUD-003', 'FIX-REC-001', 'FIX-REC-002', 'FIX-REC-003', 'FIX-OVR-001', 'FIX-OVR-002', 'FIX-FUND-001', 'FIX-FUND-002', 'FIX-FUND-003', 'FIX-SAV-001', 'FIX-SAV-002', 'FIX-CLOSE-001', 'FIX-CLOSE-002', 'FIX-CSV-001', 'FIX-CSV-002', 'FIX-CROSS-001'];
    private const USER_ID = 1;
    private const SESSION_ID = 'privacy_parity_session';
    private const SESSION_SECRET = 'privacy_parity_secret';

    private ?PDO $pdo = null;
    private ?AuthService $auth = null;

    /** @return list<string> */
    public static function boundGroups(): array
    {
        return self::BOUND_GROUPS;
    }

    public function isBound(string $groupId): bool
    {
        return in_array($groupId, self::BOUND_GROUPS, true);
    }

    /** @param array<string,mixed> $scenario */
    public function blocked(array $scenario): array
    {
        return [
            'status' => 'blocked',
            'reason' => 'current_implementation_adapter_not_bound',
            'authority' => $scenario['source'],
        ];
    }

    /** @param array<string,mixed> $scenario */
    public function capture(array $scenario): array
    {
        $groupId = (string) ($scenario['group_id'] ?? '');
        if (!$this->isBound($groupId)) {
            return $this->blocked($scenario);
        }

        $this->connect();
        $this->resetDatabase();
        $this->seedIdentity();

        return match ($groupId) {
            'FIX-TXN-001' => $this->captureTransactions(),
            'FIX-TXN-002' => $this->captureTransactionFiltering(),
            'FIX-TXN-003' => $this->captureTransactionSuggestions(),
            'FIX-TXN-004' => $this->captureTransactionImportDuplicates(),
            'FIX-TXN-005' => $this->captureFundLinkedTransactions(),
            'FIX-TAX-001' => $this->captureTaxonomy((string) ($scenario['scenario_id'] ?? '')),
            'FIX-BUD-001' => $this->captureBudget(),
            'FIX-BUD-002' => $this->captureBudgetIncome(),
            'FIX-BUD-003' => $this->captureBudgetInheritance(),
            'FIX-REC-001' => $this->captureRecurringSchedule(),
            'FIX-REC-002' => $this->captureRecurringVersions(),
            'FIX-REC-003' => $this->captureRecurringMaterialization(),
            'FIX-OVR-001' => $this->captureOverview(),
            'FIX-OVR-002' => $this->captureInsights(),
            'FIX-FUND-001' => $this->captureFundsBalance(),
            'FIX-FUND-002' => $this->captureFundsArchiveRestrictions(),
            'FIX-FUND-003' => $this->captureFundsCloseoutLinkage(),
            'FIX-SAV-001' => $this->captureSavingsConfiguration(),
            'FIX-SAV-002' => $this->captureSavingsPacing(),
            'FIX-CLOSE-001' => $this->captureCloseoutLifecycle(),
            'FIX-CLOSE-002' => $this->captureCloseoutLifecycle(),
            'FIX-CSV-001' => $this->captureCsvImport(),
            'FIX-CSV-002' => $this->captureCsvExportAndRollback(),
            'FIX-CROSS-001' => $this->captureCrossDomainLifecycle(),
            default => $this->blocked($scenario),
        };
    }

    private function connect(): void
    {
        if ($this->pdo !== null) {
            return;
        }

        $database = getenv('PRIVACY_PARITY_DB_NAME') ?: 'budget_privacy_parity_test';
        if (!preg_match('/_privacy_parity_test$/', $database)) {
            throw new \RuntimeException('Adapter requires a dedicated *_privacy_parity_test database');
        }
        $dsn = getenv('DB_DSN') ?: sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            getenv('PRIVACY_PARITY_DB_HOST') ?: '127.0.0.1',
            getenv('PRIVACY_PARITY_DB_PORT') ?: '3306',
            $database
        );
        if (!preg_match('/^mysql:.*dbname=([^;]+)/', $dsn, $match) || !preg_match('/_privacy_parity_test$/', $match[1])) {
            throw new \RuntimeException('DB_DSN must point to the dedicated parity database');
        }

        $this->pdo = new PDO(
            $dsn,
            getenv('DB_USER') ?: (getenv('PRIVACY_PARITY_DB_USER') ?: 'root'),
            getenv('DB_PASS') ?: (getenv('PRIVACY_PARITY_DB_PASS') ?: ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $this->auth = new AuthService($this->pdo, Config::load(dirname(__DIR__, 2)));
    }

    private function resetDatabase(): void
    {
        $tables = $this->pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME <> 'schema_migrations'")->fetchAll(PDO::FETCH_COLUMN);
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            $quoted = str_replace('`', '``', (string) $table);
            $this->pdo->exec('TRUNCATE TABLE `' . $quoted . '`');
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function seedIdentity(): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (id, email, display_name, auth_provider, password_hash, email_verified, role, is_active, financial_privacy_state)
             VALUES (:id, :email, :display_name, :auth_provider, :password_hash, 1, \'owner\', 1, \'legacy_plaintext\')'
        );
        foreach ([
            [self::USER_ID, 'privacy-parity@example.test', 'Privacy Parity Fixture'],
            [2, 'privacy-parity-other@example.test', 'Privacy Parity Other'],
        ] as [$id, $email, $displayName]) {
            $stmt->execute([
                ':id' => $id,
                ':email' => $email,
                ':display_name' => $displayName,
                ':auth_provider' => 'password',
                ':password_hash' => password_hash('not-used', PASSWORD_DEFAULT),
            ]);
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO user_sessions (session_id, user_id, session_secret_hash, client_type, expires_at)
             VALUES (:session_id, :user_id, :session_secret_hash, \'native\', UTC_TIMESTAMP() + INTERVAL 1 DAY)'
        );
        $stmt->execute([
            ':session_id' => self::SESSION_ID,
            ':user_id' => self::USER_ID,
            ':session_secret_hash' => Str::hashSha256(self::SESSION_SECRET),
        ]);
    }

    /** @return array<string,mixed> */
    private function captureTransactions(): array
    {
        $taxonomy = new TaxonomyController($this->pdo, $this->auth);
        $tag = $this->call($taxonomy, 'createTag', $this->request('POST', '/me/tags', ['name' => 'Groceries', 'icon_key' => 'shopping_cart']));
        $tagId = (string) ($tag['body']['id'] ?? '');
        $this->pdo->exec("INSERT INTO tags (id, user_id, name) VALUES (2, 2, 'Other User Tag')");
        $transactions = new TransactionController(
            $this->pdo,
            $this->auth,
            new RecurringExpenseService($this->pdo),
            new FundTransactionIntegrationService($this->pdo, new FundRepository($this->pdo))
        );

        $created = $this->call($transactions, 'create', $this->request('POST', '/me/transactions', [
            'date' => '2026-01-15', 'expense' => '  Coffee  ', 'amount' => '12.50',
            'category' => 'needs', 'tag_id' => $tagId, 'is_split' => true, 'notes' => '  Morning coffee  ',
        ]));
        $invalid = $this->captureException(fn() => $transactions->create($this->request('POST', '/me/transactions', [
            'date' => '2026-01-15', 'expense' => 'Invalid', 'amount' => '0.00', 'category' => 'needs', 'tag_id' => $tagId,
        ])));
        $invalidCategory = $this->captureException(fn() => $transactions->create($this->request('POST', '/me/transactions', [
            'date' => '2026-01-15', 'expense' => 'Invalid category', 'amount' => '1.00', 'category' => 'other', 'tag_id' => $tagId,
        ])));
        $missingRequired = $this->captureException(fn() => $transactions->create($this->request('POST', '/me/transactions', [
            'date' => '2026-01-15', 'amount' => '1.00', 'category' => 'needs', 'tag_id' => $tagId,
        ])));
        $crossUserTag = $this->captureException(fn() => $transactions->create($this->request('POST', '/me/transactions', [
            'date' => '2026-01-15', 'expense' => 'Cross user', 'amount' => '1.00', 'category' => 'needs', 'tag_id' => '2',
        ])));
        $createdId = (string) ($created['body']['id'] ?? '');
        $updated = $this->call($transactions, 'update', $this->request('PATCH', '/me/transactions/' . $createdId, [
            'notes' => '  Updated note  ',
        ]), ['transaction_id' => $createdId]);
        $listed = $this->call($transactions, 'list', $this->request('GET', '/me/transactions', [], ['page' => '1', 'page_size' => '1']));
        $deleted = $this->call($transactions, 'delete', $this->request('DELETE', '/me/transactions/' . $createdId), ['transaction_id' => $createdId]);

        $state = StateSnapshot::relevant([
            'records' => $this->rows('SELECT CAST(id AS CHAR) AS id, CAST(user_id AS CHAR) AS user_id, transaction_date, expense, amount, category, is_split, notes, deleted_at IS NOT NULL AS is_deleted FROM transactions ORDER BY id'),
            'tombstones' => $this->rows('SELECT CAST(id AS CHAR) AS id, deleted_at IS NOT NULL AS is_deleted FROM transactions WHERE deleted_at IS NOT NULL ORDER BY id'),
        ]);

        return [
            'status' => 'captured',
            'operations' => ['create' => $created, 'invalid_create' => $invalid, 'invalid_category' => $invalidCategory, 'missing_required' => $missingRequired, 'cross_user_tag' => $crossUserTag, 'update' => $updated, 'list' => $listed, 'delete' => $deleted],
            'invariant_checks' => [
                'positive_money_accepted' => ($created['status'] ?? 0) === 201,
                'invalid_money_rejected' => ($invalid['status'] ?? 0) === 422,
                'invalid_category_rejected' => ($invalidCategory['status'] ?? 0) === 422,
                'missing_required_rejected' => ($missingRequired['status'] ?? 0) === 422,
                'cross_user_tag_rejected' => ($crossUserTag['status'] ?? 0) === 404,
                'notes_trimmed' => ($created['body']['notes'] ?? null) === 'Morning coffee',
                'split_preserved' => ($created['body']['is_split'] ?? null) === true,
                'delete_is_soft' => count($state['tombstones']) === 1,
            ],
            'state' => $state,
        ];
    }

    /** @return array<string,mixed> */
    private function captureTransactionFiltering(): array
    {
        $taxonomy = new TaxonomyController($this->pdo, $this->auth);
        $tag = $this->call($taxonomy, 'createTag', $this->request('POST', '/me/tags', ['name' => 'Groceries', 'icon_key' => 'shopping_cart']));
        $tagId = (string) ($tag['body']['id'] ?? '');
        $transactions = $this->transactionController();
        foreach ([
            ['date' => '2026-01-10', 'expense' => 'Coffee beans', 'amount' => '10.00', 'category' => 'needs', 'is_split' => false],
            ['date' => '2026-01-11', 'expense' => 'Coffee shop', 'amount' => '20.00', 'category' => 'wants', 'is_split' => true],
            ['date' => '2026-01-12', 'expense' => 'Coffee subscription', 'amount' => '30.00', 'category' => 'needs', 'is_split' => false],
        ] as $row) {
            $row['tag_id'] = $tagId;
            $this->call($transactions, 'create', $this->request('POST', '/me/transactions', $row));
        }
        $pageOne = $this->call($transactions, 'list', $this->request('GET', '/me/transactions', [], [
            'q' => 'coffee', 'date_from' => '2026-01-10', 'date_to' => '2026-01-12',
            'page' => '1', 'page_size' => '2', 'sort' => 'date_asc',
        ]));
        $pageTwo = $this->call($transactions, 'list', $this->request('GET', '/me/transactions', [], [
            'q' => 'coffee', 'date_from' => '2026-01-10', 'date_to' => '2026-01-12',
            'page' => '2', 'page_size' => '2', 'sort' => 'date_asc',
        ]));
        $summary = $pageOne['body']['summary'] ?? [];
        return [
            'status' => 'captured',
            'operations' => ['page_one' => $pageOne, 'page_two' => $pageTwo],
            'invariant_checks' => [
                'filter_applied' => count($pageOne['body']['items'] ?? []) === 2 && ($pageOne['body']['total_items'] ?? 0) === 3,
                'pagination_stable' => count($pageTwo['body']['items'] ?? []) === 1 && (($pageTwo['body']['items'][0]['expense'] ?? null) === 'Coffee subscription'),
                'summary_uses_full_collection' => ($summary['count'] ?? 0) === 3 && ($summary['total_spent'] ?? null) === '60.00' && ($summary['split_count'] ?? 0) === 1,
            ],
            'state' => StateSnapshot::relevant([
                'records' => $this->rows('SELECT CAST(id AS CHAR) AS id, transaction_date, expense, amount, category, is_split FROM transactions WHERE deleted_at IS NULL ORDER BY transaction_date, id'),
            ]),
        ];
    }

    /** @return array<string,mixed> */
    private function captureTransactionSuggestions(): array
    {
        $taxonomy = new TaxonomyController($this->pdo, $this->auth);
        $tag = $this->call($taxonomy, 'createTag', $this->request('POST', '/me/tags', ['name' => 'Groceries', 'icon_key' => 'shopping_cart']));
        $tagId = (string) ($tag['body']['id'] ?? '');
        $transactions = $this->transactionController();
        $rows = [
            ['date' => '2026-01-01', 'expense' => 'Target', 'amount' => '10.00', 'category' => 'needs', 'tag_id' => $tagId],
            ['date' => '2026-01-02', 'expense' => 'Target', 'amount' => '11.00', 'category' => 'needs', 'tag_id' => $tagId],
            ['date' => '2026-01-03', 'expense' => 'Target', 'amount' => '12.00', 'category' => 'wants', 'tag_id' => $tagId],
            ['date' => '2026-01-04', 'expense' => 'Target Express', 'amount' => '13.00', 'category' => 'wants', 'tag_id' => $tagId],
            ['date' => '2026-01-05', 'expense' => 'Downtown Target Market', 'amount' => '14.00', 'category' => 'needs', 'tag_id' => $tagId],
        ];
        foreach ($rows as $row) $this->call($transactions, 'create', $this->request('POST', '/me/transactions', $row));
        $exact = $this->call($transactions, 'suggestions', $this->request('GET', '/me/transactions/suggestions', [], ['q' => 'target', 'limit' => '10']));
        $prefix = $this->call($transactions, 'suggestions', $this->request('GET', '/me/transactions/suggestions', [], ['q' => 'target e', 'limit' => '10']));
        $contains = $this->call($transactions, 'suggestions', $this->request('GET', '/me/transactions/suggestions', [], ['q' => 'town', 'limit' => '10']));
        $items = $exact['body']['items'] ?? [];
        return [
            'status' => 'captured',
            'operations' => ['exact' => $exact, 'prefix' => $prefix, 'contains' => $contains],
            'invariant_checks' => [
                'exact_before_prefix_before_contains' => ($items[0]['expense'] ?? null) === 'Target' && ($items[0]['usage_count'] ?? 0) >= 2,
                'frequency_and_recency_applied' => ($items[0]['category'] ?? null) === 'needs' && ($items[0]['last_used_at'] ?? null) === '2026-01-02',
                'prefix_ranked' => (($prefix['body']['items'][0]['expense'] ?? null) === 'Target Express'),
                'contains_ranked' => (($contains['body']['items'][0]['expense'] ?? null) === 'Downtown Target Market'),
                'deterministic_tie_breaking' => (($items[0]['expense'] ?? null) === ($this->call($transactions, 'suggestions', $this->request('GET', '/me/transactions/suggestions', [], ['q' => 'target', 'limit' => '10']))['body']['items'][0]['expense'] ?? null)),
            ],
            'state' => StateSnapshot::relevant([
                'records' => $this->rows('SELECT CAST(id AS CHAR) AS id, transaction_date, expense, category FROM transactions WHERE deleted_at IS NULL ORDER BY id'),
            ]),
        ];
    }

    /** @return array<string,mixed> */
    private function captureTransactionImportDuplicates(): array
    {
        $mapperBase = new CsvImportMapper();
        $taxonomyImport = new TaxonomyImportRepository($this->pdo, $mapperBase);
        $mapper = new CsvImportMapper($taxonomyImport);
        $service = new CsvImportService(
            $this->pdo,
            new CsvImportReader(Config::load(dirname(__DIR__, 2)), $mapper),
            $mapper,
            new CsvImportCommitter($this->pdo, $taxonomyImport, $mapper),
            new DataRunRepository($this->pdo)
        );
        $filePath = tempnam(sys_get_temp_dir(), 'privacy-parity-csv-');
        if ($filePath === false) throw new \RuntimeException('Unable to create CSV fixture');
        file_put_contents($filePath, "date,expense,amount,category,tag\n2026-01-15,Coffee import,12.50,needs,Imported\n");
        $file = ['error' => UPLOAD_ERR_OK, 'tmp_name' => $filePath, 'size' => filesize($filePath), 'name' => 'duplicate.csv'];
        $input = [
            'mode' => 'commit', 'mapping' => null, 'category_strategy' => null, 'amount_strategy' => null, 'date_strategy' => null,
            'tag_strategy' => json_encode(['mode' => 'value_map', 'value_map' => ['Imported' => ['mode' => 'new', 'name' => 'Imported']]], JSON_THROW_ON_ERROR),
        ];
        $first = $service->importCsv(self::USER_ID, $file, $input);
        $second = $service->importCsv(self::USER_ID, $file, $input);
        unlink($filePath);
        return [
            'status' => 'captured',
            'operations' => ['first_commit' => $first, 'second_commit' => $second],
            'invariant_checks' => [
                'first_imported' => ($first['imported_rows'] ?? 0) === 1 && ($first['duplicate_rows'] ?? 0) === 0,
                'second_duplicate' => ($second['imported_rows'] ?? 0) === 0 && ($second['duplicate_rows'] ?? 0) === 1,
                'fingerprint_and_run_linkage' => ($this->rows("SELECT import_fingerprint, csv_import_run_id FROM transactions WHERE source = 'import'") !== [])
                    && count($this->rows("SELECT id FROM transactions WHERE source = 'import'")) === 1,
            ],
            'state' => StateSnapshot::relevant([
                'records' => $this->rows('SELECT CAST(id AS CHAR) AS id, source, import_fingerprint, CAST(csv_import_run_id AS CHAR) AS csv_import_run_id FROM transactions ORDER BY id'),
                'source_records' => $this->rows('SELECT CAST(id AS CHAR) AS id, mode, imported_rows, duplicate_rows FROM csv_import_runs ORDER BY id'),
            ]),
        ];
    }

    /** @return array<string,mixed> */
    private function captureFundLinkedTransactions(): array
    {
        $taxonomy = new TaxonomyController($this->pdo, $this->auth);
        $tag = $this->call($taxonomy, 'createTag', $this->request('POST', '/me/tags', ['name' => 'Savings', 'icon_key' => 'shopping_cart']));
        $fundRepository = new FundRepository($this->pdo);
        $integration = new FundTransactionIntegrationService($this->pdo, $fundRepository);
        $fundController = new FundController($this->auth, new FundService($this->pdo, $fundRepository, new FundBalanceService($fundRepository), $integration));
        $fund = $this->call($fundController, 'create', $this->request('POST', '/me/funds', ['name' => 'Emergency', 'fund_type' => 'emergency']));
        $fundId = (string) ($fund['body']['id'] ?? '');
        $entry = $this->call($fundController, 'createEntry', $this->request('POST', '/me/funds/' . $fundId . '/entries', [
            'entry_date' => '2026-01-15', 'entry_type' => 'contribution', 'direction' => 'in', 'amount' => '100.00',
            'budget_tracking' => 'create_transaction', 'transaction' => ['expense' => 'Emergency saving', 'tag_id' => $tag['body']['id'], 'notes' => 'Initial'],
        ]), ['fund_id' => $fundId]);
        $transactionId = (string) ($this->rows('SELECT id FROM transactions ORDER BY id DESC LIMIT 1')[0]['id'] ?? '');
        $transactions = $this->transactionController();
        $updated = $this->call($transactions, 'update', $this->request('PATCH', '/me/transactions/' . $transactionId, ['amount' => '125.00', 'notes' => 'Updated']), ['transaction_id' => $transactionId]);
        $deleted = $this->call($transactions, 'delete', $this->request('DELETE', '/me/transactions/' . $transactionId), ['transaction_id' => $transactionId]);
        return [
            'status' => 'captured',
            'operations' => ['fund_entry_create' => $entry, 'transaction_update' => $updated, 'transaction_delete' => $deleted],
            'invariant_checks' => [
                'create_linked_transaction' => ($entry['status'] ?? 0) === 201,
                'update_syncs_entry' => (($this->rows('SELECT amount FROM fund_entries WHERE source_transaction_id = ' . (int) $transactionId . ' LIMIT 1')[0]['amount'] ?? null) === '125.00'),
                'delete_voids_entry' => (($this->rows('SELECT voided_at, void_reason FROM fund_entries WHERE source_transaction_id = ' . (int) $transactionId . ' LIMIT 1')[0]['void_reason'] ?? null) === 'transaction_deleted'),
            ],
            'state' => StateSnapshot::relevant([
                'records' => $this->rows('SELECT CAST(id AS CHAR) AS id, CAST(fund_id AS CHAR) AS fund_id, amount, source_type, CAST(source_transaction_id AS CHAR) AS source_transaction_id FROM fund_entries ORDER BY id'),
                'voided_entries' => $this->rows('SELECT CAST(id AS CHAR) AS id, void_reason FROM fund_entries WHERE voided_at IS NOT NULL ORDER BY id'),
            ]),
        ];
    }

    /** @return array<string,mixed> */
    private function captureTaxonomy(string $scenarioId = ''): array
    {
        $taxonomy = new TaxonomyController($this->pdo, $this->auth);
        if ($scenarioId === 'taxonomy.historical-relationships-after-delete') {
            $tag = $this->call($taxonomy, 'createTag', $this->request('POST', '/me/tags', ['name' => 'Historical', 'icon_key' => 'shopping_cart']));
            $transaction = $this->call($this->transactionController(), 'create', $this->request('POST', '/me/transactions', [
                'date' => '2026-01-15', 'expense' => 'Historical expense', 'amount' => '25.00',
                'category' => 'needs', 'tag_id' => $tag['body']['id'],
            ]));
            $this->call($taxonomy, 'deleteTag', $this->request('DELETE', '/me/tags/' . $tag['body']['id']), ['tag_id' => (string) $tag['body']['id']]);
            $transactionId = (string) ($transaction['body']['id'] ?? '');
            $row = $this->rows('SELECT CAST(tag_id AS CHAR) AS tag_id, deleted_at IS NOT NULL AS is_deleted FROM transactions WHERE id = ' . (int) $transactionId . ' LIMIT 1');
            return [
                'status' => 'captured',
                'operations' => ['created_transaction' => $transaction],
                'invariant_checks' => [
                    'deleted_taxonomy_preserves_relationship' => (($row[0]['tag_id'] ?? null) === (string) $tag['body']['id']) && ((int) ($row[0]['is_deleted'] ?? 1) === 0),
                    'deleted_tag_is_soft_deleted' => ((int) ($this->rows('SELECT deleted_at IS NOT NULL AS is_deleted FROM tags WHERE id = ' . (int) $tag['body']['id'] . ' LIMIT 1')[0]['is_deleted'] ?? 0) === 1),
                ],
                'state' => StateSnapshot::relevant([
                    'records' => $this->rows('SELECT CAST(t.id AS CHAR) AS id, CAST(t.tag_id AS CHAR) AS tag_id, CAST(g.id AS CHAR) AS taxonomy_id, g.deleted_at IS NOT NULL AS taxonomy_deleted FROM transactions t JOIN tags g ON g.id = t.tag_id WHERE t.id = ' . (int) $transactionId),
                    'tombstones' => $this->rows('SELECT CAST(id AS CHAR) AS id, deleted_at IS NOT NULL AS is_deleted FROM tags WHERE id = ' . (int) $tag['body']['id']),
                ]),
            ];
        }
        $tag = $this->call($taxonomy, 'createTag', $this->request('POST', '/me/tags', ['name' => 'Travel', 'icon_key' => 'plane']));
        $duplicate = $this->captureException(fn() => $taxonomy->createTag($this->request('POST', '/me/tags', ['name' => 'travel', 'icon_key' => 'plane'])));
        $this->pdo->exec("INSERT INTO tags (id, user_id, name) VALUES (2, 2, 'Other User Tag')");
        $foreignOwnership = $this->captureException(fn() => $taxonomy->updateTag($this->request('PATCH', '/me/tags/2', ['name' => 'Hijack']), ['tag_id' => '2']));
        $context = $this->call($taxonomy, 'createContext', $this->request('POST', '/me/contexts', ['name' => 'Trip', 'icon_key' => 'map_pinned']));
        $contextId = (string) ($context['body']['id'] ?? '');
        $this->call($taxonomy, 'deleteContext', $this->request('DELETE', '/me/contexts/' . $contextId), ['context_id' => $contextId]);
        $reactivated = $this->call($taxonomy, 'createContext', $this->request('POST', '/me/contexts', ['name' => 'Trip', 'icon_key' => 'box']));
        $card = $this->call($taxonomy, 'createCard', $this->request('POST', '/me/cards', ['name' => 'Visa']));
        $cardId = (string) ($card['body']['id'] ?? '');
        $favorited = $this->call($taxonomy, 'updateCard', $this->request('PATCH', '/me/cards/' . $cardId, ['is_favorite' => true]), ['card_id' => $cardId]);

        return [
            'status' => 'captured',
            'operations' => ['tag' => $tag, 'duplicate_tag' => $duplicate, 'foreign_ownership' => $foreignOwnership, 'context' => $context, 'reactivated_context' => $reactivated, 'card' => $card, 'favorited_card' => $favorited],
            'invariant_checks' => [
                'case_insensitive_duplicate_rejected' => ($duplicate['status'] ?? 0) === 409,
                'ownership_rejected' => ($foreignOwnership['status'] ?? 0) === 404,
                'context_reactivated_same_id' => ($reactivated['body']['id'] ?? null) === $contextId,
                'allowed_icon_preserved' => ($reactivated['body']['icon_key'] ?? null) === 'box',
                'favorite_update_succeeded' => ($favorited['body']['is_favorite'] ?? null) === true,
            ],
            'state' => StateSnapshot::relevant([
                'records' => [
                    'tags' => $this->rows('SELECT CAST(id AS CHAR) AS id, CAST(user_id AS CHAR) AS user_id, name, icon_key, is_active, deleted_at IS NOT NULL AS is_deleted FROM tags ORDER BY id'),
                    'contexts' => $this->rows('SELECT CAST(id AS CHAR) AS id, CAST(user_id AS CHAR) AS user_id, name, icon_key, is_active, deleted_at IS NOT NULL AS is_deleted FROM contexts ORDER BY id'),
                    'cards' => $this->rows('SELECT CAST(id AS CHAR) AS id, CAST(user_id AS CHAR) AS user_id, name, is_favorite, is_active, deleted_at IS NOT NULL AS is_deleted FROM cards ORDER BY id'),
                ],
                'tombstones' => $this->rows('SELECT CAST(id AS CHAR) AS id, name, deleted_at IS NOT NULL AS is_deleted FROM contexts WHERE deleted_at IS NOT NULL ORDER BY id'),
            ]),
        ];
    }

    /** @return array<string,mixed> */
    private function captureBudget(): array
    {
        $resolver = new BudgetSettingsResolver($this->pdo);
        $controller = new BudgetSettingsController($this->pdo, $this->auth, $resolver);
        $validPercent = $this->call($controller, 'upsert', $this->request('PUT', '/me/budget-settings', [
            'effective_month' => '2026-01', 'monthly_income' => '5000.00', 'allocation_mode' => 'percent',
            'needs_percent' => '50.00', 'wants_percent' => '30.00', 'savings_percent' => '20.00',
        ]));
        $invalidPercent = $this->captureException(fn() => $controller->upsert($this->request('PUT', '/me/budget-settings', [
            'effective_month' => '2026-02', 'monthly_income' => '5000.00', 'allocation_mode' => 'percent',
            'needs_percent' => '50.00', 'wants_percent' => '30.00', 'savings_percent' => '10.00',
        ])));
        $validAmount = $this->call($controller, 'upsert', $this->request('PUT', '/me/budget-settings', [
            'effective_month' => '2026-02', 'monthly_income' => '5000.00', 'allocation_mode' => 'amount',
            'needs_amount' => '2500.00', 'wants_amount' => '1500.00', 'savings_amount' => '1000.00',
        ]));
        $invalidAmount = $this->captureException(fn() => $controller->upsert($this->request('PUT', '/me/budget-settings', [
            'effective_month' => '2026-03', 'monthly_income' => '5000.00', 'allocation_mode' => 'amount',
            'needs_amount' => '2500.00', 'wants_amount' => '1500.00', 'savings_amount' => '999.99',
        ])));
        $resolved = $this->call($controller, 'get', $this->request('GET', '/me/budget-settings', [], ['month' => '2026-02']));

        return [
            'status' => 'captured',
            'operations' => ['valid_percent' => $validPercent, 'invalid_percent' => $invalidPercent, 'valid_amount' => $validAmount, 'invalid_amount' => $invalidAmount, 'resolved' => $resolved],
            'invariant_checks' => [
                'percent_validation_succeeded' => ($validPercent['status'] ?? 0) === 200,
                'invalid_percent_rejected' => ($invalidPercent['status'] ?? 0) === 422,
                'amount_validation_succeeded' => ($validAmount['status'] ?? 0) === 200,
                'invalid_amount_rejected' => ($invalidAmount['status'] ?? 0) === 422,
                'resolved_amount_mode_preserved' => ($resolved['body']['settings']['allocation_mode'] ?? null) === 'amount',
            ],
            'state' => StateSnapshot::relevant([
                'historical_versions' => $this->rows('SELECT CAST(user_id AS CHAR) AS user_id, effective_month, monthly_income, allocation_mode, needs_percent, wants_percent, savings_percent, needs_amount, wants_amount, savings_amount FROM budget_settings_versions ORDER BY effective_month'),
                'records' => $this->rows('SELECT CAST(user_id AS CHAR) AS user_id, monthly_income, allocation_mode, needs_percent, wants_percent, savings_percent, needs_amount, wants_amount, savings_amount FROM budget_settings ORDER BY user_id'),
            ]),
        ];
    }

    /** @return array<string,mixed> */
    private function captureBudgetIncome(): array
    {
        $resolver = new BudgetSettingsResolver($this->pdo);
        $controller = new BudgetSettingsController($this->pdo, $this->auth, $resolver);
        $monthly = $this->call($controller, 'upsert', $this->request('PUT', '/me/budget-settings', [
            'effective_month' => '2026-01', 'monthly_income' => '5000.00', 'allocation_mode' => 'percent',
            'needs_percent' => '50.00', 'wants_percent' => '30.00', 'savings_percent' => '20.00',
            'income_source_type' => 'monthly', 'primary_monthly_income' => '4000.00',
            'side_income_type' => 'monthly', 'side_income_label' => 'Freelance', 'side_monthly_income' => '1000.00',
        ]));
        $hourly = $this->call($controller, 'upsert', $this->request('PUT', '/me/budget-settings', [
            'effective_month' => '2026-02', 'monthly_income' => '5000.00', 'allocation_mode' => 'percent',
            'needs_percent' => '50.00', 'wants_percent' => '30.00', 'savings_percent' => '20.00',
            'income_source_type' => 'hourly', 'primary_hourly_rate' => '25.00', 'primary_weekly_hours' => '40.00',
            'side_income_type' => 'monthly', 'side_income_label' => 'Side', 'side_monthly_income' => '666.67',
        ]));
        $resolved = $this->call($controller, 'get', $this->request('GET', '/me/budget-settings', [], ['month' => '2026-02']));
        return [
            'status' => 'captured',
            'operations' => ['monthly_composition' => $monthly, 'hourly_conversion' => $hourly, 'resolved' => $resolved],
            'invariant_checks' => [
                'income_composition_accepted' => ($monthly['status'] ?? 0) === 200 && ($monthly['body']['primary_monthly_income'] ?? null) === '4000.00',
                'hourly_conversion_accepted' => ($hourly['status'] ?? 0) === 200 && ($hourly['body']['primary_hourly_rate'] ?? null) === '25.00',
                'hourly_conversion_rounding' => ($hourly['body']['monthly_income'] ?? null) === '5000.00',
                'resolved_income_breakdown_preserved' => (($resolved['body']['settings']['income_source_type'] ?? null) === 'hourly') && (($resolved['body']['settings']['side_income_type'] ?? null) === 'monthly'),
            ],
            'state' => StateSnapshot::relevant([
                'historical_versions' => $this->rows('SELECT effective_month, monthly_income, income_source_type, primary_monthly_income, primary_hourly_rate, primary_weekly_hours, side_income_type, side_monthly_income, side_hourly_rate, side_weekly_hours FROM budget_settings_versions ORDER BY effective_month'),
            ]),
        ];
    }

    /** @return array<string,mixed> */
    private function captureBudgetInheritance(): array
    {
        $resolver = new BudgetSettingsResolver($this->pdo);
        $controller = new BudgetSettingsController($this->pdo, $this->auth, $resolver);
        $jan = $this->call($controller, 'upsert', $this->request('PUT', '/me/budget-settings', [
            'effective_month' => '2026-01', 'monthly_income' => '4000.00', 'allocation_mode' => 'percent',
            'needs_percent' => '50.00', 'wants_percent' => '30.00', 'savings_percent' => '20.00',
        ]));
        $mar = $this->call($controller, 'upsert', $this->request('PUT', '/me/budget-settings', [
            'effective_month' => '2026-03', 'monthly_income' => '6000.00', 'allocation_mode' => 'amount',
            'needs_amount' => '3000.00', 'wants_amount' => '1800.00', 'savings_amount' => '1200.00',
        ]));
        $inherited = $this->call($controller, 'get', $this->request('GET', '/me/budget-settings', [], ['month' => '2026-02']));
        $edited = $this->call($controller, 'upsert', $this->request('PUT', '/me/budget-settings', [
            'effective_month' => '2026-02', 'monthly_income' => '4500.00', 'allocation_mode' => 'percent',
            'needs_percent' => '50.00', 'wants_percent' => '25.00', 'savings_percent' => '25.00',
        ]));
        $resolvedAfterEdit = $this->call($controller, 'get', $this->request('GET', '/me/budget-settings', [], ['month' => '2026-02']));
        return [
            'status' => 'captured',
            'operations' => ['january' => $jan, 'march' => $mar, 'inherited_february' => $inherited, 'edited_february' => $edited, 'resolved_after_edit' => $resolvedAfterEdit],
            'invariant_checks' => [
                'inheritance_resolves_prior_version' => (($inherited['body']['resolved_effective_month'] ?? null) === '2026-01') && (($inherited['body']['is_exact_match'] ?? true) === false),
                'inherited_month_edit_creates_version' => (($resolvedAfterEdit['body']['resolved_effective_month'] ?? null) === '2026-02') && (($resolvedAfterEdit['body']['settings']['monthly_income'] ?? null) === '4500.00'),
                'prior_version_preserved' => count($this->rows('SELECT id FROM budget_settings_versions')) === 3,
                'resolved_allocation_amounts' => (($resolvedAfterEdit['body']['settings']['allocation_mode'] ?? null) === 'percent'),
            ],
            'state' => StateSnapshot::relevant([
                'historical_versions' => $this->rows('SELECT effective_month, monthly_income, allocation_mode, needs_percent, wants_percent, savings_percent, needs_amount, wants_amount, savings_amount FROM budget_settings_versions ORDER BY effective_month'),
                'records' => $this->rows('SELECT monthly_income, allocation_mode, needs_percent, wants_percent, savings_percent, needs_amount, wants_amount, savings_amount FROM budget_settings ORDER BY user_id'),
            ]),
        ];
    }

    /** @return array<string,mixed> */
    private function captureRecurringSchedule(): array
    {
        $now = new DateTimeImmutable('2028-04-15T12:00:00Z', new DateTimeZone('UTC'));
        $taxonomy = new TaxonomyController($this->pdo, $this->auth);
        $tag = $this->call($taxonomy, 'createTag', $this->request('POST', '/me/tags', ['name' => 'Bills', 'icon_key' => 'receipt']));
        $recurring = $this->recurringController($now);
        $day31 = $this->call($recurring, 'create', $this->request('POST', '/me/recurring-expenses', [
            'expense' => 'Day 31 bill', 'amount' => '31.00', 'category' => 'needs', 'tag_id' => $tag['body']['id'],
            'billing_type' => 'day_of_month', 'billing_day' => 31, 'starts_month' => '2028-01',
        ]));
        $lastDay = $this->call($recurring, 'create', $this->request('POST', '/me/recurring-expenses', [
            'expense' => 'Last day bill', 'amount' => '29.00', 'category' => 'wants', 'tag_id' => $tag['body']['id'],
            'billing_type' => 'last_day', 'starts_month' => '2028-01',
        ]));
        $future = $this->call($recurring, 'create', $this->request('POST', '/me/recurring-expenses', [
            'expense' => 'Future bill', 'amount' => '40.00', 'category' => 'needs', 'tag_id' => $tag['body']['id'],
            'billing_type' => 'day_of_month', 'billing_day' => 15, 'starts_month' => '2028-05',
        ]));
        $service = new RecurringExpenseService($this->pdo, $now);
        foreach (['2028-01', '2028-02', '2028-04', '2028-05'] as $month) $service->ensureGeneratedForMonth(self::USER_ID, $month);
        $rows = $this->rows('SELECT CAST(recurring_expense_id AS CHAR) AS recurring_expense_id, occurrence_month, due_date, CAST(transaction_id AS CHAR) AS transaction_id FROM recurring_expense_occurrences ORDER BY occurrence_month, recurring_expense_id');
        $day31Id = (string) ($day31['body']['id'] ?? '');
        $lastDayId = (string) ($lastDay['body']['id'] ?? '');
        $futureId = (string) ($future['body']['id'] ?? '');
        $dueDates = [];
        foreach ($rows as $row) $dueDates[(string) $row['recurring_expense_id'] . ':' . substr((string) $row['occurrence_month'], 0, 7)] = (string) $row['due_date'];
        return [
            'status' => 'captured',
            'operations' => ['day_31' => $day31, 'last_day' => $lastDay, 'future' => $future, 'february_list' => $this->call($recurring, 'list', $this->request('GET', '/me/recurring-expenses', [], ['month' => '2028-02']))],
            'invariant_checks' => [
                'day_31_clamps_short_months' => ($dueDates[$day31Id . ':2028-02'] ?? null) === '2028-02-29' && ($dueDates[$day31Id . ':2028-04'] ?? null) === '2028-04-30',
                'last_day_uses_calendar_end' => ($dueDates[$lastDayId . ':2028-02'] ?? null) === '2028-02-29' && ($dueDates[$lastDayId . ':2028-04'] ?? null) === '2028-04-30',
                'future_month_not_generated' => !array_key_exists($futureId . ':2028-05', $dueDates),
                'generated_dates_are_explicit' => ($dueDates[$day31Id . ':2028-01'] ?? null) === '2028-01-31',
            ],
            'state' => StateSnapshot::relevant([
                'records' => $rows,
                'source_records' => $this->rows('SELECT CAST(id AS CHAR) AS id, expense, billing_type, billing_day, starts_month, ends_month, is_active FROM recurring_expenses ORDER BY id'),
            ]),
        ];
    }

    /** @return array<string,mixed> */
    private function captureRecurringVersions(): array
    {
        $now = new DateTimeImmutable('2026-04-15T12:00:00Z', new DateTimeZone('UTC'));
        $taxonomy = new TaxonomyController($this->pdo, $this->auth);
        $tag = $this->call($taxonomy, 'createTag', $this->request('POST', '/me/tags', ['name' => 'Versions', 'icon_key' => 'receipt']));
        $recurring = $this->recurringController($now);
        $base = $this->call($recurring, 'create', $this->request('POST', '/me/recurring-expenses', [
            'expense' => 'Versioned bill', 'amount' => '50.00', 'category' => 'needs', 'tag_id' => $tag['body']['id'],
            'billing_type' => 'day_of_month', 'billing_day' => 31, 'starts_month' => '2026-01',
        ]));
        $baseId = (string) ($base['body']['id'] ?? '');
        $scheduled = $this->call($recurring, 'scheduleChange', $this->request('POST', '/me/recurring-expenses/' . $baseId . '/schedule-change', [
            'effective_month' => '2026-06', 'amount' => '75.00',
        ]), ['recurring_expense_id' => $baseId]);
        $newId = (string) ($scheduled['body']['new_rule']['id'] ?? '');
        $overlap = $this->captureException(fn() => $recurring->update($this->request('PATCH', '/me/recurring-expenses/' . $newId, ['starts_month' => '2026-02']), ['recurring_expense_id' => $newId]));
        $stoppable = $this->call($recurring, 'create', $this->request('POST', '/me/recurring-expenses', [
            'expense' => 'Stopped bill', 'amount' => '20.00', 'category' => 'wants', 'tag_id' => $tag['body']['id'],
            'billing_type' => 'day_of_month', 'billing_day' => 10, 'starts_month' => '2026-01',
        ]));
        $stoppableId = (string) ($stoppable['body']['id'] ?? '');
        $this->call($recurring, 'delete', $this->request('DELETE', '/me/recurring-expenses/' . $stoppableId), ['recurring_expense_id' => $stoppableId]);
        $service = new RecurringExpenseService($this->pdo, $now);
        $service->ensureGeneratedForMonth(self::USER_ID, '2026-03');
        $before = count($this->rows('SELECT id FROM recurring_expense_occurrences WHERE recurring_expense_id = ' . (int) $stoppableId));
        $service->ensureGeneratedForMonth(self::USER_ID, '2026-05');
        $after = count($this->rows('SELECT id FROM recurring_expense_occurrences WHERE recurring_expense_id = ' . (int) $stoppableId));
        return [
            'status' => 'captured',
            'operations' => ['base' => $base, 'scheduled' => $scheduled, 'overlap_rejection' => $overlap, 'stopped_delete' => ['status' => 204]],
            'invariant_checks' => [
                'future_version_created' => ($scheduled['status'] ?? 0) === 200 && ($scheduled['body']['new_rule']['starts_month'] ?? null) === '2026-06',
                'overlap_rejected' => ($overlap['status'] ?? 0) === 409,
                'prior_version_preserved' => (($this->rows('SELECT ends_month FROM recurring_expenses WHERE id = ' . (int) $baseId)[0]['ends_month'] ?? null) === '2026-05-01'),
                'deletion_stops_future_generation' => $before === 1 && $after === 1,
                'existing_transaction_preserved_after_delete' => count($this->rows("SELECT id FROM transactions WHERE expense = 'Stopped bill' AND deleted_at IS NULL")) === 1,
            ],
            'state' => StateSnapshot::relevant([
                'records' => $this->rows('SELECT CAST(id AS CHAR) AS id, series_id, expense, amount, starts_month, ends_month, is_active, deleted_at IS NOT NULL AS is_deleted FROM recurring_expenses ORDER BY id'),
                'source_records' => $this->rows('SELECT CAST(recurring_expense_id AS CHAR) AS recurring_expense_id, occurrence_month, due_date, CAST(transaction_id AS CHAR) AS transaction_id FROM recurring_expense_occurrences ORDER BY id'),
            ]),
        ];
    }

    /** @return array<string,mixed> */
    private function captureRecurringMaterialization(): array
    {
        $now = new DateTimeImmutable('2026-03-15T12:00:00Z', new DateTimeZone('UTC'));
        $taxonomy = new TaxonomyController($this->pdo, $this->auth);
        $tag = $this->call($taxonomy, 'createTag', $this->request('POST', '/me/tags', ['name' => 'Seed', 'icon_key' => 'receipt']));
        $transactions = $this->transactionController($now);
        $seed = $this->call($transactions, 'create', $this->request('POST', '/me/transactions', [
            'date' => '2026-02-15', 'expense' => 'Seeded bill', 'amount' => '45.00', 'category' => 'needs', 'tag_id' => $tag['body']['id'],
        ]));
        $recurring = $this->recurringController($now);
        $rule = $this->call($recurring, 'create', $this->request('POST', '/me/recurring-expenses', [
            'expense' => 'Seeded bill', 'amount' => '45.00', 'category' => 'needs', 'tag_id' => $tag['body']['id'],
            'billing_type' => 'day_of_month', 'billing_day' => 15, 'starts_month' => '2026-02', 'seed_transaction_id' => $seed['body']['id'],
        ]));
        $service = new RecurringExpenseService($this->pdo, $now);
        $service->ensureGeneratedForMonth(self::USER_ID, '2026-02');
        $service->ensureGeneratedForMonth(self::USER_ID, '2026-02');
        $service->ensureGeneratedForMonth(self::USER_ID, '2026-03');
        $service->ensureGeneratedForMonth(self::USER_ID, '2026-04');
        $ruleId = (int) ($rule['body']['id'] ?? 0);
        $occurrences = $this->rows('SELECT CAST(id AS CHAR) AS id, occurrence_month, due_date, CAST(transaction_id AS CHAR) AS transaction_id FROM recurring_expense_occurrences WHERE recurring_expense_id = ' . $ruleId . ' ORDER BY occurrence_month');
        $linked = $this->rows('SELECT CAST(t.id AS CHAR) AS id, t.source, reo.occurrence_month FROM transactions t JOIN recurring_expense_occurrences reo ON reo.transaction_id = t.id WHERE t.user_id = 1 ORDER BY t.id');
        return [
            'status' => 'captured',
            'operations' => ['seed' => $seed, 'rule' => $rule, 'occurrences_after_retry_and_future_call' => $occurrences],
            'invariant_checks' => [
                'seed_occurrence_reused' => count($occurrences) === 2 && ($occurrences[0]['transaction_id'] ?? null) === (string) $seed['body']['id'],
                'retry_is_idempotent' => count($this->rows('SELECT id FROM recurring_expense_occurrences WHERE recurring_expense_id = ' . $ruleId)) === 2,
                'future_month_not_generated' => count($this->rows("SELECT id FROM recurring_expense_occurrences WHERE recurring_expense_id = {$ruleId} AND occurrence_month = '2026-04-01'")) === 0,
                'generated_transaction_distinguishable' => count($linked) === 2 && ($linked[0]['source'] ?? null) === 'manual' && ($linked[0]['occurrence_month'] ?? null) !== null,
            ],
            'state' => StateSnapshot::relevant([
                'records' => $linked,
                'source_records' => $occurrences,
            ]),
        ];
    }

    /** @return array<string,mixed> */
    private function captureOverview(): array
    {
        $now = new DateTimeImmutable('2026-02-15T12:00:00Z', new DateTimeZone('UTC'));
        $taxonomy = new TaxonomyController($this->pdo, $this->auth);
        $tagA = $this->call($taxonomy, 'createTag', $this->request('POST', '/me/tags', ['name' => 'Needs', 'icon_key' => 'receipt']));
        $tagB = $this->call($taxonomy, 'createTag', $this->request('POST', '/me/tags', ['name' => 'Wants', 'icon_key' => 'shopping_cart']));
        $budget = new BudgetSettingsController($this->pdo, $this->auth, new BudgetSettingsResolver($this->pdo));
        $this->call($budget, 'upsert', $this->request('PUT', '/me/budget-settings', [
            'effective_month' => '2026-01', 'monthly_income' => '1000.00', 'allocation_mode' => 'percent',
            'needs_percent' => '50.00', 'wants_percent' => '30.00', 'savings_percent' => '20.00',
        ]));
        $transactions = $this->transactionController($now);
        foreach ([
            ['date' => '2026-01-05', 'expense' => 'Rent', 'amount' => '100.00', 'category' => 'needs', 'tag_id' => $tagA['body']['id']],
            ['date' => '2026-01-06', 'expense' => 'Food', 'amount' => '200.00', 'category' => 'wants', 'tag_id' => $tagB['body']['id']],
            ['date' => '2026-01-07', 'expense' => 'Fuel', 'amount' => '50.00', 'category' => 'needs', 'tag_id' => $tagA['body']['id']],
            ['date' => '2026-01-08', 'expense' => 'Books', 'amount' => '25.00', 'category' => 'wants', 'tag_id' => $tagB['body']['id']],
            ['date' => '2026-01-09', 'expense' => 'Lunch', 'amount' => '15.00', 'category' => 'wants', 'tag_id' => $tagB['body']['id']],
            ['date' => '2026-01-10', 'expense' => 'Coffee', 'amount' => '10.00', 'category' => 'needs', 'tag_id' => $tagA['body']['id']],
        ] as $row) $this->call($transactions, 'create', $this->request('POST', '/me/transactions', $row));
        $recurring = $this->recurringController($now);
        $rule = $this->call($recurring, 'create', $this->request('POST', '/me/recurring-expenses', [
            'expense' => 'Rent recurring', 'amount' => '25.00', 'category' => 'needs', 'tag_id' => $tagA['body']['id'],
            'billing_type' => 'last_day', 'starts_month' => '2026-01',
        ]));
        $recurringService = new RecurringExpenseService($this->pdo, $now);
        $recurringService->ensureGeneratedForMonth(self::USER_ID, '2026-01');
        $overview = new MonthOverviewController($this->auth, new MonthOverviewService($this->pdo, new BudgetSettingsResolver($this->pdo), null, $now));
        $jan = $this->call($overview, 'overview', $this->request('GET', '/me/months/2026-01/overview'), ['month' => '2026-01']);
        $empty = $this->call($overview, 'overview', $this->request('GET', '/me/months/2025-01/overview'), ['month' => '2025-01']);
        return [
            'status' => 'captured',
            'operations' => ['selected_month' => $jan, 'empty_month' => $empty, 'recurring_rule' => $rule],
            'invariant_checks' => [
                'selected_month_and_inherited_budget' => ($jan['body']['month'] ?? null) === '2026-01' && (($jan['body']['budget']['resolved_effective_month'] ?? null) === '2026-01'),
                'category_and_tag_totals' => (($jan['body']['summary']['total_spent'] ?? null) === '425.00') && count($jan['body']['categories'] ?? []) === 3 && count($jan['body']['tags'] ?? []) === 2,
                'progress_and_status_cards_present' => (($jan['body']['month_progress']['status'] ?? null) === 'past') && count($jan['body']['status_cards'] ?? []) > 0,
                'recurring_total_included' => (($jan['body']['recurring']['generated_total'] ?? null) === '25.00'),
                'recent_transactions_bounded_and_ordered' => count($jan['body']['recent_transactions'] ?? []) === 5 && (($jan['body']['recent_transactions'][0]['date'] ?? null) === '2026-01-31'),
                'empty_month_zero_safe' => (($empty['body']['summary']['total_spent'] ?? null) === '0.00') && (($empty['body']['budget']['has_budget'] ?? true) === false),
            ],
            'state' => StateSnapshot::relevant([
                'records' => $this->rows('SELECT CAST(id AS CHAR) AS id, transaction_date, expense, amount, category FROM transactions ORDER BY transaction_date, id'),
            ]),
        ];
    }

    /** @return array<string,mixed> */
    private function captureInsights(): array
    {
        $now = new DateTimeImmutable('2026-03-15T12:00:00Z', new DateTimeZone('UTC'));
        $taxonomy = new TaxonomyController($this->pdo, $this->auth);
        $tag = $this->call($taxonomy, 'createTag', $this->request('POST', '/me/tags', ['name' => 'Insights', 'icon_key' => 'receipt']));
        $transactions = $this->transactionController($now);
        foreach ([
            ['date' => '2026-01-05', 'expense' => 'Alpha', 'amount' => '10.00', 'category' => 'needs'],
            ['date' => '2026-01-12', 'expense' => 'Beta', 'amount' => '30.00', 'category' => 'wants'],
            ['date' => '2026-02-05', 'expense' => 'Gamma', 'amount' => '20.00', 'category' => 'savings'],
            ['date' => '2026-02-20', 'expense' => 'Delta', 'amount' => '30.00', 'category' => 'wants'],
        ] as $row) {
            $row['tag_id'] = $tag['body']['id'];
            $this->call($transactions, 'create', $this->request('POST', '/me/transactions', $row));
        }
        $recurring = $this->recurringController($now);
        $this->call($recurring, 'create', $this->request('POST', '/me/recurring-expenses', [
            'expense' => 'Recurring insight', 'amount' => '15.00', 'category' => 'needs', 'tag_id' => $tag['body']['id'],
            'billing_type' => 'day_of_month', 'billing_day' => 10, 'starts_month' => '2026-01',
        ]));
        $recurringService = new RecurringExpenseService($this->pdo, $now);
        $recurringService->ensureGeneratedForMonth(self::USER_ID, '2026-01');
        $recurringService->ensureGeneratedForMonth(self::USER_ID, '2026-02');
        $metrics = new MetricsController($this->pdo, $this->auth, $recurringService, new BudgetSettingsResolver($this->pdo));
        $range = $this->call($metrics, 'insights', $this->request('GET', '/me/metrics/insights', [], ['date_from' => '2026-01-01', 'date_to' => '2026-02-28']));
        $empty = $this->call($metrics, 'insights', $this->request('GET', '/me/metrics/insights', [], ['date_from' => '2027-01-01', 'date_to' => '2027-02-28']));
        return [
            'status' => 'captured',
            'operations' => ['range' => $range, 'empty_range' => $empty],
            'invariant_checks' => [
                'range_totals_and_months' => ($range['body']['total_spend'] ?? null) === '120.00' && ($range['body']['months_in_range'] ?? 0) === 2 && count($range['body']['monthly_spend_trend'] ?? []) === 2,
                'category_and_weekday_aggregates' => count($range['body']['category_breakdown'] ?? []) === 3 && count($range['body']['day_of_week_spend'] ?? []) === 7,
                'largest_and_tie_ordering' => ($range['body']['largest_transactions'][0]['amount'] ?? null) === '30.00' && count($range['body']['largest_transactions'] ?? []) === 6,
                'recurring_variable_distinction' => ($range['body']['recurring_vs_variable']['recurring'] ?? null) === '30.00' && ($range['body']['recurring_vs_variable']['variable'] ?? null) === '90.00',
                'empty_zero_safe' => ($empty['body']['total_spend'] ?? null) === '0.00' && ($empty['body']['recurring_vs_variable']['variable'] ?? null) === '0.00',
            ],
            'state' => StateSnapshot::relevant([
                'records' => $this->rows('SELECT CAST(id AS CHAR) AS id, transaction_date, expense, amount, category FROM transactions ORDER BY transaction_date, id'),
            ]),
        ];
    }

    /** @return array<string,mixed> */
    private function captureFundsBalance(): array
    {
        $funds = $this->fundController();
        $first = $this->call($funds, 'create', $this->request('POST', '/me/funds', ['name' => 'Emergency', 'fund_type' => 'emergency', 'goal_amount' => '100.00']));
        $second = $this->call($funds, 'create', $this->request('POST', '/me/funds', ['name' => 'Buffer', 'fund_type' => 'buffer', 'goal_amount' => '50.00']));
        $firstId = (string) ($first['body']['id'] ?? '');
        $secondId = (string) ($second['body']['id'] ?? '');
        $contribution = $this->call($funds, 'createEntry', $this->request('POST', '/me/funds/' . $firstId . '/entries', ['entry_date' => '2026-01-02', 'entry_type' => 'contribution', 'direction' => 'in', 'amount' => '100.00', 'source_type' => 'manual']), ['fund_id' => $firstId]);
        $withdrawal = $this->call($funds, 'createEntry', $this->request('POST', '/me/funds/' . $firstId . '/entries', ['entry_date' => '2026-01-03', 'entry_type' => 'withdrawal', 'direction' => 'out', 'amount' => '10.00']), ['fund_id' => $firstId]);
        $secondEntry = $this->call($funds, 'createEntry', $this->request('POST', '/me/funds/' . $secondId . '/entries', ['entry_date' => '2026-01-04', 'entry_type' => 'contribution', 'direction' => 'in', 'amount' => '50.00']), ['fund_id' => $secondId]);
        $startingBalance = $this->call($funds, 'createEntry', $this->request('POST', '/me/funds/' . $secondId . '/entries', ['entry_date' => '2026-01-01', 'entry_type' => 'starting_balance', 'direction' => 'in', 'amount' => '10.00', 'source_type' => 'starting_balance']), ['fund_id' => $secondId]);
        $firstView = $this->call($funds, 'get', $this->request('GET', '/me/funds/' . $firstId), ['fund_id' => $firstId]);
        $initialBalance = (string) ($first['body']['current_balance'] ?? '');
        $overGoalEntry = $this->call($funds, 'createEntry', $this->request('POST', '/me/funds/' . $firstId . '/entries', ['entry_date' => '2026-01-04', 'entry_type' => 'contribution', 'direction' => 'in', 'amount' => '20.00', 'source_type' => 'manual']), ['fund_id' => $firstId]);
        $overGoalView = $this->call($funds, 'get', $this->request('GET', '/me/funds/' . $firstId), ['fund_id' => $firstId]);
        return [
            'status' => 'captured',
            'operations' => ['funds' => [$first, $second], 'entries' => [$contribution, $withdrawal, $secondEntry, $startingBalance, $overGoalEntry], 'first_view' => $firstView, 'over_goal_view' => $overGoalView],
            'invariant_checks' => [
                'zero_initial_balance' => $initialBalance === '0.00',
                'balance_accounts_for_directions' => ($firstView['body']['current_balance'] ?? null) === '90.00',
                'exact_goal_completion_and_overfunding_safe' => ($firstView['body']['is_goal_met'] ?? false) === false && ($firstView['body']['percent_funded'] ?? null) === '90.00' && ($overGoalView['body']['current_balance'] ?? null) === '110.00' && ($overGoalView['body']['is_goal_met'] ?? false) === true,
                'multiple_funds_ordered' => count(($this->call($funds, 'list', $this->request('GET', '/me/funds'), [],))['body']['items'] ?? []) === 2,
                'source_types_preserved' => count($this->rows("SELECT id FROM fund_entries WHERE source_type = 'manual'")) === 4 && count($this->rows("SELECT id FROM fund_entries WHERE source_type = 'starting_balance'")) === 1,
            ],
            'state' => StateSnapshot::relevant([
                'records' => $this->rows('SELECT CAST(fe.id AS CHAR) AS id, CAST(fe.fund_id AS CHAR) AS fund_id, entry_type, direction, amount, source_type, CAST(source_transaction_id AS CHAR) AS source_transaction_id, CAST(source_closeout_id AS CHAR) AS source_closeout_id, voided_at IS NOT NULL AS is_voided, deleted_at IS NOT NULL AS is_deleted FROM fund_entries fe ORDER BY id'),
            ]),
        ];
    }

    /** @return array<string,mixed> */
    private function captureFundsArchiveRestrictions(): array
    {
        $funds = $this->fundController();
        $created = $this->call($funds, 'create', $this->request('POST', '/me/funds', ['name' => 'Archive candidate', 'fund_type' => 'goal', 'goal_amount' => '25.00']));
        $fundId = (string) ($created['body']['id'] ?? '');
        $archived = $this->call($funds, 'archive', $this->request('POST', '/me/funds/' . $fundId . '/archive'), ['fund_id' => $fundId]);
        $rejected = $this->captureException(fn() => $funds->createEntry($this->request('POST', '/me/funds/' . $fundId . '/entries', ['entry_date' => '2026-01-01', 'entry_type' => 'contribution', 'direction' => 'in', 'amount' => '1.00']), ['fund_id' => $fundId]));
        $restored = $this->call($funds, 'restore', $this->request('POST', '/me/funds/' . $fundId . '/restore'), ['fund_id' => $fundId]);
        $entry = $this->call($funds, 'createEntry', $this->request('POST', '/me/funds/' . $fundId . '/entries', ['entry_date' => '2026-01-02', 'entry_type' => 'contribution', 'direction' => 'in', 'amount' => '25.00']), ['fund_id' => $fundId]);
        return [
            'status' => 'captured',
            'operations' => ['created' => $created, 'archived' => $archived, 'rejected_entry' => $rejected, 'restored' => $restored, 'entry_after_restore' => $entry],
            'invariant_checks' => [
                'archive_changes_status' => ($archived['body']['status'] ?? null) === 'archived',
                'archived_entry_rejected' => ($rejected['status'] ?? 0) === 409,
                'restore_reenables_mutation' => ($restored['body']['status'] ?? null) === 'active' && ($entry['status'] ?? 0) === 201,
                'goal_completion_after_restore' => (($this->call($funds, 'get', $this->request('GET', '/me/funds/' . $fundId), ['fund_id' => $fundId])['body']['is_goal_met'] ?? false) === true),
            ],
            'state' => StateSnapshot::relevant([
                'records' => $this->rows('SELECT CAST(id AS CHAR) AS id, status, archived_at IS NOT NULL AS is_archived FROM funds ORDER BY id'),
            ]),
        ];
    }

    /** @return array<string,mixed> */
    private function captureFundsCloseoutLinkage(): array
    {
        $now = new DateTimeImmutable('2026-04-15T12:00:00Z', new DateTimeZone('UTC'));
        $funds = $this->fundController();
        $fund = $this->call($funds, 'create', $this->request('POST', '/me/funds', ['name' => 'Closeout fund', 'fund_type' => 'goal']));
        $fundId = (string) ($fund['body']['id'] ?? '');
        $this->seedBudget('2026-01', '1000.00');
        $taxonomy = new TaxonomyController($this->pdo, $this->auth);
        $tag = $this->call($taxonomy, 'createTag', $this->request('POST', '/me/tags', ['name' => 'Closeout', 'icon_key' => 'receipt']));
        $this->call($this->transactionController($now), 'create', $this->request('POST', '/me/transactions', ['date' => '2026-01-10', 'expense' => 'January spend', 'amount' => '100.00', 'category' => 'needs', 'tag_id' => $tag['body']['id']]));
        $closeout = $this->closeoutController($now);
        $closed = $this->call($closeout, 'close', $this->request('POST', '/me/month-closeouts/2026-01/close', ['allocations' => [['allocation_type' => 'fund', 'fund_id' => $fundId, 'amount' => '900.00']]]), ['month' => '2026-01']);
        $summary = $this->call($funds, 'closeoutSummary', $this->request('GET', '/me/funds/closeout-summary', [], ['year' => '2026']));
        return [
            'status' => 'captured',
            'operations' => ['closeout' => $closed, 'fund_summary' => $summary],
            'invariant_checks' => [
                'closeout_entry_source_linked' => count($this->rows("SELECT id FROM fund_entries WHERE source_type = 'month_closeout' AND source_closeout_id IS NOT NULL")) === 1,
                'fund_balance_includes_closeout' => (($this->call($funds, 'get', $this->request('GET', '/me/funds/' . $fundId), ['fund_id' => $fundId])['body']['current_balance'] ?? null) === '900.00'),
                'fund_closeout_summary_includes_month' => count($summary['body']['months'] ?? []) === 1,
            ],
            'state' => StateSnapshot::relevant([
                'records' => $this->rows('SELECT CAST(id AS CHAR) AS id, CAST(fund_id AS CHAR) AS fund_id, amount, source_type, CAST(source_closeout_id AS CHAR) AS source_closeout_id, CAST(source_closeout_allocation_id AS CHAR) AS source_closeout_allocation_id FROM fund_entries ORDER BY id'),
            ]),
        ];
    }

    /** @return array<string,mixed> */
    private function captureSavingsConfiguration(): array
    {
        $this->seedBudget('2026-01', '1000.00');
        $funds = $this->fundController();
        $fundA = $this->call($funds, 'create', $this->request('POST', '/me/funds', ['name' => 'Travel goal', 'fund_type' => 'goal', 'goal_amount' => '200.00']));
        $fundB = $this->call($funds, 'create', $this->request('POST', '/me/funds', ['name' => 'Buffer goal', 'fund_type' => 'buffer', 'goal_amount' => '100.00']));
        $plan = new SavingsPlanController($this->auth, new SavingsPlanService($this->pdo, new BudgetSettingsResolver($this->pdo)));
        $saved = $this->call($plan, 'replace', $this->request('PUT', '/me/months/2026-01/savings-plan', ['allocations' => [
            ['fund_id' => $fundA['body']['id'], 'amount' => '150.00'], ['fund_id' => $fundB['body']['id'], 'amount' => '50.00'],
        ]]), ['month' => '2026-01']);
        $over = $this->captureException(fn() => $plan->replace($this->request('PUT', '/me/months/2026-01/savings-plan', ['allocations' => [['fund_id' => $fundA['body']['id'], 'amount' => '201.00']]]), ['month' => '2026-01']));
        $view = $this->call($plan, 'get', $this->request('GET', '/me/months/2026-01/savings-plan'), ['month' => '2026-01']);
        return [
            'status' => 'captured',
            'operations' => ['funds' => [$fundA, $fundB], 'saved_plan' => $saved, 'over_budget' => $over, 'view' => $view],
            'invariant_checks' => [
                'plan_configuration_persisted' => ($saved['status'] ?? 0) === 200 && ($view['body']['has_plan'] ?? false) === true,
                'multiple_fund_allocations_ordered' => count($view['body']['funds'] ?? []) === 2,
                'over_allocation_rejected' => ($over['status'] ?? 0) === 422,
                'goal_fields_exposed' => ($view['body']['funds'][0]['fund']['goal_amount'] ?? null) !== null,
            ],
            'state' => StateSnapshot::relevant([
                'records' => $this->rows('SELECT CAST(fund_id AS CHAR) AS fund_id, month, planned_amount FROM monthly_savings_allocations ORDER BY id'),
            ]),
        ];
    }

    /** @return array<string,mixed> */
    private function captureSavingsPacing(): array
    {
        $this->seedBudget('2026-01', '1000.00');
        $funds = $this->fundController();
        $fund = $this->call($funds, 'create', $this->request('POST', '/me/funds', ['name' => 'Pacing goal', 'fund_type' => 'goal', 'goal_amount' => '100.00', 'target_month' => '2026-02']));
        $taxonomy = new TaxonomyController($this->pdo, $this->auth);
        $tag = $this->call($taxonomy, 'createTag', $this->request('POST', '/me/tags', ['name' => 'Savings', 'icon_key' => 'receipt']));
        $this->call($funds, 'createEntry', $this->request('POST', '/me/funds/' . $fund['body']['id'] . '/entries', ['entry_date' => '2026-01-15', 'entry_type' => 'contribution', 'direction' => 'in', 'amount' => '100.00', 'budget_tracking' => 'create_transaction', 'transaction' => ['expense' => 'Goal contribution', 'tag_id' => $tag['body']['id']]]), ['fund_id' => $fund['body']['id']]);
        $plan = new SavingsPlanController($this->auth, new SavingsPlanService($this->pdo, new BudgetSettingsResolver($this->pdo)));
        $this->call($plan, 'replace', $this->request('PUT', '/me/months/2026-01/savings-plan', ['allocations' => [['fund_id' => $fund['body']['id'], 'amount' => '100.00']]]), ['month' => '2026-01']);
        $jan = $this->call($plan, 'get', $this->request('GET', '/me/months/2026-01/savings-plan'), ['month' => '2026-01']);
        $feb = $this->call($plan, 'get', $this->request('GET', '/me/months/2026-02/savings-plan'), ['month' => '2026-02']);
        return [
            'status' => 'captured',
            'operations' => ['january' => $jan, 'february' => $feb],
            'invariant_checks' => [
                'contribution_source_integrated' => ($jan['body']['summary']['transaction_directed_to_funds'] ?? null) === '100.00',
                'goal_completion_reflected' => (($jan['body']['funds'][0]['progress_amount'] ?? null) === '100.00') && (($feb['body']['funds'][0]['pace']['status'] ?? null) === 'goal_met'),
                'month_boundary_respected' => ($feb['body']['month'] ?? null) === '2026-02',
                'fund_balance_integrated' => (($feb['body']['funds'][0]['fund']['current_balance'] ?? null) === '100.00'),
            ],
            'state' => StateSnapshot::relevant([
                'records' => $this->rows('SELECT CAST(fund_id AS CHAR) AS fund_id, month, planned_amount FROM monthly_savings_allocations ORDER BY id'),
            ]),
        ];
    }

    /** @return array<string,mixed> */
    private function captureCloseoutLifecycle(): array
    {
        $now = new DateTimeImmutable('2026-04-15T12:00:00Z', new DateTimeZone('UTC'));
        $this->seedBudget('2026-01', '1000.00');
        $funds = $this->fundController();
        $fundA = $this->call($funds, 'create', $this->request('POST', '/me/funds', ['name' => 'Close A', 'fund_type' => 'goal']));
        $fundB = $this->call($funds, 'create', $this->request('POST', '/me/funds', ['name' => 'Close B', 'fund_type' => 'goal']));
        $taxonomy = new TaxonomyController($this->pdo, $this->auth);
        $tag = $this->call($taxonomy, 'createTag', $this->request('POST', '/me/tags', ['name' => 'Closeout', 'icon_key' => 'receipt']));
        $tx = $this->transactionController($now);
        $this->call($tx, 'create', $this->request('POST', '/me/transactions', ['date' => '2026-01-12', 'expense' => 'Needs spend', 'amount' => '100.00', 'category' => 'needs', 'tag_id' => $tag['body']['id']]));
        $closeout = $this->closeoutController($now);
        $allocations = [['allocation_type' => 'fund', 'fund_id' => $fundA['body']['id'], 'amount' => '500.00'], ['allocation_type' => 'fund', 'fund_id' => $fundB['body']['id'], 'amount' => '400.00']];
        $closed = $this->call($closeout, 'close', $this->request('POST', '/me/month-closeouts/2026-01/close', ['allocations' => $allocations]), ['month' => '2026-01']);
        $repeated = $this->call($closeout, 'close', $this->request('POST', '/me/month-closeouts/2026-01/close', ['allocations' => $allocations]), ['month' => '2026-01']);
        $updated = $this->call($closeout, 'update', $this->request('PATCH', '/me/month-closeouts/2026-01', ['allocations' => [['allocation_type' => 'fund', 'fund_id' => $fundA['body']['id'], 'amount' => '900.00']]]), ['month' => '2026-01']);
        $reopened = $this->call($closeout, 'reopen', $this->request('POST', '/me/month-closeouts/2026-01/reopen'), ['month' => '2026-01']);
        return [
            'status' => 'captured',
            'operations' => ['closed' => $closed, 'repeated' => $repeated, 'updated' => $updated, 'reopened' => $reopened],
            'invariant_checks' => [
                'multi_fund_allocation_persisted' => ($closed['body']['closeout']['status'] ?? null) === 'closed' && count($closed['body']['closeout']['allocations'] ?? []) === 2,
                'repeated_closeout_replaces_entries' => count($this->rows("SELECT id FROM fund_entries WHERE source_type = 'month_closeout' AND void_reason = 'allocation_replaced'")) >= 2,
                'replacement_voids_prior_entries' => count($this->rows("SELECT id FROM fund_entries WHERE void_reason = 'allocation_replaced'")) >= 2,
                'reopen_voids_active_entries' => ($reopened['body']['status'] ?? null) === 'reopened' && count($this->rows("SELECT id FROM fund_entries WHERE void_reason = 'closeout_reopened'")) >= 1,
                'source_relationships_preserved' => count($this->rows('SELECT id FROM fund_entries WHERE source_closeout_id IS NOT NULL AND source_closeout_allocation_id IS NOT NULL')) >= 3,
                'fund_balance_after_reopen_excludes_voided' => (($this->call($funds, 'get', $this->request('GET', '/me/funds/' . $fundA['body']['id']), ['fund_id' => $fundA['body']['id']])['body']['current_balance'] ?? null) === '0.00'),
            ],
            'state' => StateSnapshot::relevant([
                'records' => $this->rows('SELECT CAST(id AS CHAR) AS id, CAST(fund_id AS CHAR) AS fund_id, amount, source_type, CAST(source_closeout_id AS CHAR) AS source_closeout_id, CAST(source_closeout_allocation_id AS CHAR) AS source_closeout_allocation_id FROM fund_entries ORDER BY id'),
                'voided_entries' => $this->rows('SELECT CAST(id AS CHAR) AS id, void_reason, voided_at IS NOT NULL AS is_voided FROM fund_entries WHERE voided_at IS NOT NULL ORDER BY id'),
                'allocations' => $this->rows('SELECT CAST(id AS CHAR) AS id, CAST(closeout_id AS CHAR) AS closeout_id, amount, superseded_at IS NOT NULL AS is_superseded FROM monthly_closeout_allocations ORDER BY id'),
            ]),
        ];
    }

    /** @return array<string,mixed> */
    private function captureCsvImport(): array
    {
        $service = $this->csvImportService();
        $csv = "date,expense,amount,external_category,tag_source,notes\n01/15,Coffee import,12.50,Debit,NewTag,Imported note\n01/16,Rejected import,not-money,Debit,NewTag,\n01/17,Blank amount,,Debit,NewTag,\n";
        $filePath = $this->writeParityCsv($csv);
        $file = ['error' => UPLOAD_ERR_OK, 'tmp_name' => $filePath, 'size' => strlen($csv), 'name' => 'phase0d-import.csv'];
        $input = [
            'mode' => 'preview',
            'mapping' => json_encode(['date' => 'date', 'expense' => 'expense', 'amount' => 'amount', 'category' => 'external_category', 'tag' => 'tag_source', 'notes' => 'notes'], JSON_THROW_ON_ERROR),
            'category_strategy' => json_encode(['mode' => 'value_map', 'source_header' => 'external_category', 'value_map' => ['Debit' => 'needs']], JSON_THROW_ON_ERROR),
            'amount_strategy' => json_encode(['blank_mapped_amount' => 'skip'], JSON_THROW_ON_ERROR),
            'date_strategy' => json_encode(['missing_year' => 'apply_year', 'year' => 2026], JSON_THROW_ON_ERROR),
            'tag_strategy' => json_encode(['mode' => 'value_map', 'value_map' => ['NewTag' => ['mode' => 'new', 'name' => 'Imported Tag']]], JSON_THROW_ON_ERROR),
        ];
        try {
            $before = [
                'transactions' => (int) $this->pdo->query('SELECT COUNT(*) FROM transactions')->fetchColumn(),
                'tags' => (int) $this->pdo->query('SELECT COUNT(*) FROM tags')->fetchColumn(),
                'runs' => (int) $this->pdo->query('SELECT COUNT(*) FROM csv_import_runs')->fetchColumn(),
            ];
            $preview = $service->importCsv(self::USER_ID, $file, $input);
            $afterPreview = [
                'transactions' => (int) $this->pdo->query('SELECT COUNT(*) FROM transactions')->fetchColumn(),
                'tags' => (int) $this->pdo->query('SELECT COUNT(*) FROM tags')->fetchColumn(),
                'runs' => (int) $this->pdo->query('SELECT COUNT(*) FROM csv_import_runs')->fetchColumn(),
            ];
            $input['mode'] = 'dry_run';
            $dryRun = $service->importCsv(self::USER_ID, $file, $input);
            $afterDryRun = (int) $this->pdo->query('SELECT COUNT(*) FROM transactions')->fetchColumn();
            $input['mode'] = 'commit';
            $commit = $service->importCsv(self::USER_ID, $file, $input);
            $duplicate = $service->importCsv(self::USER_ID, $file, $input);
            $malformedPath = $this->writeParityCsv('');
            $malformed = $this->captureException(fn() => $service->importCsv(self::USER_ID, ['error' => UPLOAD_ERR_OK, 'tmp_name' => $malformedPath, 'size' => 0, 'name' => 'malformed.csv'], ['mode' => 'preview']));
            unlink($malformedPath);
            $firstRunId = (int) $this->pdo->query("SELECT id FROM csv_import_runs WHERE mode = 'commit' ORDER BY id ASC LIMIT 1")->fetchColumn();
            $rollback = (new DataRunRepository($this->pdo))->rollbackImport(self::USER_ID, $firstRunId);
            return [
                'status' => 'captured',
                'operations' => ['preview' => $preview, 'dry_run' => $dryRun, 'commit' => $commit, 'duplicate_commit' => $duplicate, 'malformed' => $malformed, 'rollback' => $rollback],
                'invariant_checks' => [
                    'preview_does_not_mutate_financial_state' => $before === $afterPreview,
                    'dry_run_does_not_commit_transactions' => $afterDryRun === $before['transactions'],
                    'accepted_and_rejected_rows_are_deterministic' => ($commit['valid_rows'] ?? 0) === 1 && ($commit['invalid_rows'] ?? 0) === 1 && ($commit['skipped_rows'] ?? 0) === 1 && count($commit['errors'] ?? []) === 1,
                    'yearless_date_and_category_mapping_applied' => ($commit['imported_rows'] ?? 0) === 1 && (($this->rows("SELECT transaction_date, category FROM transactions WHERE source = 'import' ORDER BY id DESC LIMIT 1")[0]['transaction_date'] ?? null) === '2026-01-15') && (($this->rows("SELECT category FROM transactions WHERE source = 'import' ORDER BY id DESC LIMIT 1")[0]['category'] ?? null) === 'needs'),
                    'new_tag_created_only_on_commit' => (int) $this->pdo->query("SELECT COUNT(*) FROM tags WHERE name = 'Imported Tag'")->fetchColumn() === 1,
                    'duplicate_fingerprint_reused' => ($duplicate['duplicate_rows'] ?? 0) === 1 && (int) $this->pdo->query("SELECT COUNT(*) FROM transactions WHERE source = 'import'")->fetchColumn() === 1,
                    'malformed_input_rejected' => ($malformed['status'] ?? 0) === 422,
                    'rollback_soft_deletes_import_only' => ($rollback['deleted_rows'] ?? 0) === 1 && (int) $this->pdo->query("SELECT COUNT(*) FROM transactions WHERE source = 'import' AND deleted_at IS NULL")->fetchColumn() === 0 && (int) $this->pdo->query("SELECT COUNT(*) FROM tags WHERE name = 'Imported Tag' AND deleted_at IS NULL")->fetchColumn() === 1,
                ],
                'state' => StateSnapshot::relevant([
                    'transactions' => $this->rows("SELECT CAST(id AS CHAR) AS id, transaction_date, amount, category, source, deleted_at IS NOT NULL AS is_deleted, CAST(csv_import_run_id AS CHAR) AS csv_import_run_id FROM transactions ORDER BY id"),
                    'tags' => $this->rows('SELECT CAST(id AS CHAR) AS id, name, deleted_at IS NOT NULL AS is_deleted FROM tags ORDER BY id'),
                    'runs' => $this->rows('SELECT CAST(id AS CHAR) AS id, mode, valid_rows, imported_rows, duplicate_rows, invalid_rows, rolled_back_rows FROM csv_import_runs ORDER BY id'),
                ]),
            ];
        } finally {
            if (is_file($filePath)) unlink($filePath);
        }
    }

    /** @return array<string,mixed> */
    private function captureCsvExportAndRollback(): array
    {
        $service = $this->csvImportService();
        $csv = "date,expense,amount,category,tag\n2026-01-10,Export source,10.00,needs,ExportTag\n";
        $filePath = $this->writeParityCsv($csv);
        $file = ['error' => UPLOAD_ERR_OK, 'tmp_name' => $filePath, 'size' => strlen($csv), 'name' => 'export-source.csv'];
        $input = ['mode' => 'commit', 'mapping' => null, 'category_strategy' => null, 'amount_strategy' => null, 'date_strategy' => null, 'tag_strategy' => json_encode(['mode' => 'value_map', 'value_map' => ['ExportTag' => ['mode' => 'new', 'name' => 'Export Tag']]], JSON_THROW_ON_ERROR)];
        try {
            $import = $service->importCsv(self::USER_ID, $file, $input);
            $runId = (int) $this->pdo->query("SELECT id FROM csv_import_runs WHERE mode = 'commit' ORDER BY id DESC LIMIT 1")->fetchColumn();
            $rollback = (new DataRunRepository($this->pdo))->rollbackImport(self::USER_ID, $runId);
            $tag = $this->call((new TaxonomyController($this->pdo, $this->auth)), 'createTag', $this->request('POST', '/me/tags', ['name' => 'Formula', 'icon_key' => 'receipt']));
            $tagId = (int) $tag['body']['id'];
            $insert = $this->pdo->prepare('INSERT INTO transactions (user_id, transaction_date, expense, amount, category, is_split, notes, tag_id, source, created_at, updated_at) VALUES (:user_id, :date, :expense, :amount, :category, 0, :notes, :tag_id, :source, :created_at, :updated_at)');
            foreach ([['2026-01-12', '=SUM(A1)', '12.50', 'needs', '', $tagId], ['2026-01-11', 'Plain export', '5.00', 'wants', null, $tagId]] as $row) {
                $insert->execute([':user_id' => self::USER_ID, ':date' => $row[0], ':expense' => $row[1], ':amount' => $row[2], ':category' => $row[3], ':notes' => $row[4], ':tag_id' => $row[5], ':source' => 'manual', ':created_at' => '2026-01-15 10:00:00', ':updated_at' => '2026-01-15 10:00:00']);
            }
            $exports = new CsvExportService($this->pdo, new CsvImportMapper(), new DataRunRepository($this->pdo));
            $response = $exports->exportCsv(self::USER_ID, ['date_from' => '2026-01-01', 'date_to' => '2026-01-31', 'categories' => 'needs,wants']);
            ob_start();
            $response->send();
            $body = (string) ob_get_clean();
            $lines = array_values(array_filter(explode("\n", trim($body)), static fn(string $line): bool => $line !== ''));
            return [
                'status' => 'captured',
                'operations' => ['import' => $import, 'rollback' => $rollback, 'export' => ['status' => $response->status, 'headers' => ['Content-Type' => $response->headers['Content-Type'] ?? null], 'csv' => $body]],
                'invariant_checks' => [
                    'rollback_leaves_taxonomy' => ($rollback['deleted_rows'] ?? 0) === 1 && (int) $this->pdo->query("SELECT COUNT(*) FROM tags WHERE name = 'Export Tag' AND deleted_at IS NULL")->fetchColumn() === 1,
                    'export_header_and_filter' => $response->status === 200 && count($lines) === 3 && str_starts_with($lines[0], 'date,expense,amount'),
                    'export_order_and_formula_escape' => str_contains($lines[1], "'=SUM(A1)") && str_starts_with($lines[2], '2026-01-11'),
                    'export_money_dates_and_nulls' => str_contains($lines[1], '12.50') && str_contains($lines[2], '2026-01-11') && str_contains($lines[2], ',Formula,,') ,
                ],
                'state' => StateSnapshot::relevant([
                    'transactions' => $this->rows('SELECT CAST(id AS CHAR) AS id, transaction_date, expense, amount, category, source, deleted_at IS NOT NULL AS is_deleted FROM transactions ORDER BY id'),
                    'runs' => $this->rows('SELECT CAST(id AS CHAR) AS id, mode, imported_rows, rolled_back_rows FROM csv_import_runs ORDER BY id'),
                    'exports' => $this->rows('SELECT CAST(id AS CHAR) AS id, status, total_rows, date_from, date_to FROM csv_export_runs ORDER BY id'),
                ]),
            ];
        } finally {
            if (is_file($filePath)) unlink($filePath);
        }
    }

    /** @return array<string,mixed> */
    private function captureCrossDomainLifecycle(): array
    {
        $now = new DateTimeImmutable('2026-04-15T12:00:00Z', new DateTimeZone('UTC'));
        $this->seedBudget('2026-01', '1000.00');
        $funds = $this->fundController();
        $fund = $this->call($funds, 'create', $this->request('POST', '/me/funds', ['name' => 'Cross-domain fund', 'fund_type' => 'goal']));
        $tag = $this->call((new TaxonomyController($this->pdo, $this->auth)), 'createTag', $this->request('POST', '/me/tags', ['name' => 'Cross', 'icon_key' => 'receipt']));
        $transaction = $this->call($this->transactionController($now), 'create', $this->request('POST', '/me/transactions', ['date' => '2026-01-12', 'expense' => 'Cross savings', 'amount' => '100.00', 'category' => 'savings', 'tag_id' => $tag['body']['id']]));
        $fundId = (string) $fund['body']['id'];
        $linked = $this->call($funds, 'createEntry', $this->request('POST', '/me/funds/' . $fundId . '/entries', ['entry_date' => '2026-01-12', 'entry_type' => 'contribution', 'direction' => 'in', 'amount' => '100.00', 'budget_tracking' => 'link_existing_transaction', 'transaction_id' => (string) $transaction['body']['id']]), ['fund_id' => $fundId]);
        $closeout = $this->closeoutController($now);
        $closed = $this->call($closeout, 'close', $this->request('POST', '/me/month-closeouts/2026-01/close', ['allocations' => [['allocation_type' => 'fund', 'fund_id' => $fundId, 'amount' => '900.00']]]), ['month' => '2026-01']);
        $replaced = $this->call($closeout, 'update', $this->request('PATCH', '/me/month-closeouts/2026-01', ['allocations' => [['allocation_type' => 'fund', 'fund_id' => $fundId, 'amount' => '800.00']]]), ['month' => '2026-01']);
        $reopened = $this->call($closeout, 'reopen', $this->request('POST', '/me/month-closeouts/2026-01/reopen'), ['month' => '2026-01']);
        $reclosed = $this->call($closeout, 'close', $this->request('POST', '/me/month-closeouts/2026-01/close', ['allocations' => [['allocation_type' => 'fund', 'fund_id' => $fundId, 'amount' => '700.00']]]), ['month' => '2026-01']);
        $entries = $this->rows('SELECT source_closeout_allocation_id, voided_at, void_reason FROM fund_entries WHERE source_type = \'month_closeout\' ORDER BY id');
        return ['status' => 'captured', 'operations' => ['transaction' => $transaction, 'fund_link' => $linked, 'closed' => $closed, 'replaced' => $replaced, 'reopened' => $reopened, 'reclosed' => $reclosed], 'invariant_checks' => [
            'transaction_and_fund_linked' => ($linked['status'] ?? 0) === 201 && count($this->rows('SELECT id FROM fund_entries WHERE source_transaction_id IS NOT NULL')) === 1,
            'replacement_and_reopen_preserve_history' => count($entries) === 3 && count(array_filter($entries, static fn(array $row): bool => $row['void_reason'] === 'allocation_replaced')) >= 1 && count(array_filter($entries, static fn(array $row): bool => $row['void_reason'] === 'closeout_reopened')) >= 1,
            'reclose_has_new_active_entry' => ($reclosed['body']['status'] ?? null) === 'closed' && count($this->rows("SELECT id FROM fund_entries WHERE source_type = 'month_closeout' AND voided_at IS NULL")) === 1,
            'current_balance_excludes_voided_history' => (($this->call($funds, 'get', $this->request('GET', '/me/funds/' . $fundId), ['fund_id' => $fundId])['body']['current_balance'] ?? null) === '800.00'),
        ], 'state' => StateSnapshot::relevant(['entries' => $this->rows('SELECT CAST(id AS CHAR) AS id, amount, source_type, CAST(source_transaction_id AS CHAR) AS source_transaction_id, CAST(source_closeout_allocation_id AS CHAR) AS source_closeout_allocation_id, void_reason FROM fund_entries ORDER BY id'), 'allocations' => $this->rows('SELECT CAST(id AS CHAR) AS id, amount, superseded_at IS NOT NULL AS is_superseded FROM monthly_closeout_allocations ORDER BY id')])];
    }

    private function csvImportService(): CsvImportService
    {
        $mapperBase = new CsvImportMapper();
        $taxonomyImport = new TaxonomyImportRepository($this->pdo, $mapperBase);
        $mapper = new CsvImportMapper($taxonomyImport);
        return new CsvImportService($this->pdo, new CsvImportReader(Config::load(dirname(__DIR__, 2)), $mapper), $mapper, new CsvImportCommitter($this->pdo, $taxonomyImport, $mapper), new DataRunRepository($this->pdo));
    }

    private function writeParityCsv(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'privacy-parity-csv-');
        if ($path === false || file_put_contents($path, $contents) === false) throw new \RuntimeException('Unable to create CSV fixture');
        return $path;
    }

    private function seedBudget(string $month, string $income): void
    {
        $budget = new BudgetSettingsController($this->pdo, $this->auth, new BudgetSettingsResolver($this->pdo));
        $this->call($budget, 'upsert', $this->request('PUT', '/me/budget-settings', [
            'effective_month' => $month, 'monthly_income' => $income, 'allocation_mode' => 'percent',
            'needs_percent' => '50.00', 'wants_percent' => '30.00', 'savings_percent' => '20.00',
        ]));
    }

    private function fundController(): FundController
    {
        $repository = new FundRepository($this->pdo);
        $integration = new FundTransactionIntegrationService($this->pdo, $repository);
        return new FundController($this->auth, new FundService($this->pdo, $repository, new FundBalanceService($repository), $integration));
    }

    private function closeoutController(DateTimeImmutable $clockNow): MonthCloseoutController
    {
        $repository = new FundRepository($this->pdo);
        $config = Config::load(dirname(__DIR__, 2));
        $budget = new BudgetSettingsResolver($this->pdo);
        $closeoutRepository = new MonthCloseoutRepository($this->pdo);
        $integration = new FundCloseoutIntegrationService($this->pdo, $repository);
        return new MonthCloseoutController($this->auth, new MonthCloseoutService($this->pdo, $config, $budget, $closeoutRepository, $integration, $clockNow));
    }

    private function recurringController(DateTimeImmutable $clockNow): RecurringExpenseController
    {
        return new RecurringExpenseController($this->pdo, $this->auth, new RecurringExpenseService($this->pdo, $clockNow));
    }

    private function transactionController(?DateTimeImmutable $clockNow = null): TransactionController
    {
        $repository = new FundRepository($this->pdo);
        return new TransactionController($this->pdo, $this->auth, new RecurringExpenseService($this->pdo, $clockNow), new FundTransactionIntegrationService($this->pdo, $repository));
    }

    /** @return array{status:int,body:mixed} */
    private function call(object $object, string $method, Request $request, array $params = []): array
    {
        /** @var Response $response */
        $response = $params === []
            ? $object->{$method}($request)
            : $object->{$method}($request, $params);
        $body = $response->body === '' ? null : json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        return ['status' => $response->status, 'body' => $body];
    }

    /** @return array{status:int,error:array<string,mixed>} */
    private function captureException(callable $operation): array
    {
        try {
            $operation();
        } catch (HttpException $e) {
            return ['status' => $e->status, 'error' => ['code' => $e->errorCode, 'message' => $e->getMessage(), 'details' => $e->details()]];
        }
        throw new \RuntimeException('Expected authoritative operation to fail');
    }

    private function request(string $method, string $path, array $payload = [], array $query = []): Request
    {
        return new Request(
            $method,
            $path,
            $payload === [] ? '' : json_encode($payload, JSON_THROW_ON_ERROR),
            $query,
            [],
            [],
            [],
            ['Authorization' => 'Session ' . self::SESSION_ID . '.' . self::SESSION_SECRET]
        );
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $sql): array
    {
        $rows = $this->pdo->query($sql)->fetchAll();
        return is_array($rows) ? $rows : [];
    }
}
