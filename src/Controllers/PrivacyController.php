<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthService;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Privacy\FinancialPrivacyState;
use App\Privacy\FinancialPrivacyStateService;
use App\Privacy\FinancialRevisionService;
use App\Privacy\MigrationSnapshotService;
use App\Privacy\MigrationStagingRepository;
use App\Privacy\PrivacyCleanupRepository;
use App\Privacy\PrivacyMigrationRepository;
use App\Privacy\PrivacyMigrationService;
use App\Privacy\PrivacyCutoverService;
use App\Privacy\RecentAuthGuard;
use App\Privacy\VaultRepository;

final class PrivacyController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly FinancialPrivacyStateService $states,
        private readonly FinancialRevisionService $revisions,
        private readonly PrivacyMigrationRepository $migrations,
        private readonly PrivacyCleanupRepository $cleanup,
        private readonly ?PrivacyMigrationService $migrationService = null,
        private readonly ?MigrationSnapshotService $snapshots = null,
        private readonly ?MigrationStagingRepository $staging = null,
        private readonly ?VaultRepository $vaults = null,
        private readonly ?RecentAuthGuard $recentAuth = null,
        private readonly ?PrivacyCutoverService $cutover = null
    ) {}

    public function status(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request);
        return $this->statusResponse($ctx->userId());
    }

    public function start(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, false, true);
        ($this->recentAuth ?? throw new HttpException(500, 'MIGRATION_CONFIGURATION_ERROR', 'Migration controls are unavailable'))->requireRecentInteractiveSession($ctx);
        if (($this->vaults ?? throw new HttpException(500, 'MIGRATION_CONFIGURATION_ERROR', 'Migration controls are unavailable'))->findByUser($ctx->userId()) === null) {
            throw new HttpException(409, 'MIGRATION_VAULT_REQUIRED', 'Initialize the financial Vault before migration');
        }
        $migration = ($this->migrationService ?? throw new HttpException(500, 'MIGRATION_CONFIGURATION_ERROR', 'Migration controls are unavailable'))->createInternal($ctx->userId());
        return Response::json(['migration' => $this->migrationOutput($migration, $ctx->userId())], 201);
    }

    public function migrationStatus(Request $request, array $params): Response
    {
        $ctx = $this->auth->requireAuth($request, false, true);
        $migration = $this->migrations->getByPublicId($ctx->userId(), (string) ($params['migration_id'] ?? ''));
        if ($migration === null) throw new HttpException(404, 'MIGRATION_NOT_FOUND', 'Migration run not found');
        return Response::json(['migration' => $this->migrationOutput($migration, $ctx->userId())]);
    }

    public function cutover(Request $request, array $params): Response
    {
        $ctx = $this->auth->requireAuth($request, false, true);
        ($this->recentAuth ?? throw new HttpException(500, 'MIGRATION_CONFIGURATION_ERROR', 'Migration controls are unavailable'))->requireRecentInteractiveSession($ctx);
        $result = ($this->cutover ?? throw new HttpException(500, 'MIGRATION_CONFIGURATION_ERROR', 'Migration cutover is unavailable'))->cutover($ctx->userId(), (string) ($params['migration_id'] ?? ''));
        return Response::json($result);
    }

    public function snapshot(Request $request, array $params): Response
    {
        $ctx = $this->auth->requireAuth($request, false, true);
        $this->ownedActive($ctx->userId(), (string) ($params['migration_id'] ?? ''));
        $response = Response::json(($this->snapshots ?? throw new HttpException(500, 'MIGRATION_CONFIGURATION_ERROR', 'Migration controls are unavailable'))->snapshot($ctx->userId(), (string) $params['migration_id']));
        return $response->withHeader('Cache-Control', 'no-store, private')->withHeader('Pragma', 'no-cache');
    }

    public function putManifest(Request $request, array $params): Response
    {
        $ctx = $this->auth->requireAuth($request, false, true);
        $migrationId = (string) ($params['migration_id'] ?? '');
        $migration = $this->ownedActive($ctx->userId(), $migrationId);
        $body = $request->json();
        $targets = $body['targets'] ?? null;
        if (!is_array($targets) || count($targets) > 100000) {
            throw new HttpException(422, 'MIGRATION_MANIFEST_INVALID', 'Target manifest is invalid');
        }
        $seen = [];
        foreach ($targets as $target) {
            if (!is_array($target) || !is_string($target['target_record_id'] ?? null) || !preg_match('/^[A-Za-z0-9._:-]{1,160}$/', $target['target_record_id']) || isset($seen[$target['target_record_id']])) {
                throw new HttpException(422, 'MIGRATION_MANIFEST_INVALID', 'Target manifest contains an invalid or duplicate target');
            }
            $seen[$target['target_record_id']] = true;
        }
        $body['manifest_version'] = (string) ($body['manifest_version'] ?? 'phase5_target_manifest_v1');
        $body['snapshot_schema_version'] = (string) ($body['snapshot_schema_version'] ?? MigrationSnapshotService::SNAPSHOT_SCHEMA_VERSION);
        $this->staging->putManifest($migrationId, $ctx->userId(), $body, (int) $migration['source_financial_revision']);
        return Response::json(['ok' => true, 'status' => $this->staging->counts($migrationId, $ctx->userId())]);
    }

    public function putRecord(Request $request, array $params): Response
    {
        $ctx = $this->auth->requireAuth($request, false, true);
        $migrationId = (string) ($params['migration_id'] ?? '');
        $this->ownedActive($ctx->userId(), $migrationId);
        $recordId = rawurldecode((string) ($params['record_id'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9._:-]{1,160}$/', $recordId)) {
            throw new HttpException(422, 'MIGRATION_RECORD_INVALID', 'Target record ID is invalid');
        }
        $body = $request->json();
        if ((string) ($body['target_record_id'] ?? $recordId) !== $recordId || (int) ($body['envelope_version'] ?? 0) !== 1) {
            throw new HttpException(422, 'MIGRATION_RECORD_INVALID', 'Encrypted staging record metadata is invalid');
        }
        $iv = $this->decode($body['iv'] ?? null);
        $ciphertext = $this->decode($body['ciphertext'] ?? null);
        if ($iv === null || strlen($iv) !== 12 || $ciphertext === null || strlen($ciphertext) < 16 || !is_string($body['record_family'] ?? null) || !is_string($body['record_schema_version'] ?? null)) {
            throw new HttpException(422, 'MIGRATION_RECORD_INVALID', 'Encrypted staging envelope is invalid');
        }
        $this->staging->upsertRecord($migrationId, $ctx->userId(), ['target_record_id'=>$recordId,'record_family'=>$body['record_family'],'record_schema_version'=>$body['record_schema_version'],'envelope_version'=>1,'iv'=>$iv,'ciphertext'=>$ciphertext]);
        return Response::json(['ok' => true, 'target_record_id' => $recordId]);
    }

    public function verify(Request $request, array $params): Response
    {
        $ctx = $this->auth->requireAuth($request, false, true);
        $migrationId = (string) ($params['migration_id'] ?? '');
        $migration = $this->ownedActive($ctx->userId(), $migrationId);
        if ($this->revisions->get($ctx->userId()) !== (int) $migration['source_financial_revision']) {
            $this->migrationService->failInternal($ctx->userId(), $migrationId, 'MIGRATION_SOURCE_REVISION_CHANGED');
            throw new HttpException(409, 'MIGRATION_SOURCE_REVISION_CHANGED', 'The migration source revision changed');
        }
        $status = $this->staging->counts($migrationId, $ctx->userId());
        if ($status['expected_count'] === null || $status['expected_count'] !== $status['staged_count']) {
            throw new HttpException(409, 'MIGRATION_MANIFEST_INCOMPLETE', 'Encrypted staging is incomplete');
        }
        $manifest = $this->staging->manifest($migrationId, $ctx->userId());
        $expected = [];
        foreach (($manifest['targets'] ?? []) as $target) {
            if (is_array($target) && isset($target['target_record_id'], $target['record_family'], $target['record_schema_version'])) {
                $expected[(string)$target['target_record_id']] = ['record_family'=>(string)$target['record_family'],'record_schema_version'=>(string)$target['record_schema_version']];
            }
        }
        if ($expected !== $this->staging->targetMetadata($migrationId, $ctx->userId())) {
            throw new HttpException(409, 'MIGRATION_MANIFEST_MISMATCH', 'Encrypted staging does not match the target manifest');
        }
        $this->staging->markVerified($migrationId, $ctx->userId());
        return Response::json(['status' => $this->staging->counts($migrationId, $ctx->userId()), 'financial_privacy_state' => $this->states->get($ctx->userId())->value]);
    }

    public function cancel(Request $request, array $params): Response
    {
        $ctx = $this->auth->requireAuth($request, false, true);
        $migrationId = (string) ($params['migration_id'] ?? '');
        $migration = $this->ownedActive($ctx->userId(), $migrationId);
        $this->staging->deleteForMigration($migrationId, $ctx->userId());
        $this->migrations->markCancelled($ctx->userId(), $migrationId);
        $this->states->transition($ctx->userId(), FinancialPrivacyState::LEGACY_PLAINTEXT);
        return Response::json(['financial_privacy_state' => FinancialPrivacyState::LEGACY_PLAINTEXT->value, 'migration_id' => $migration['migration_id']]);
    }

    /** @return array<string,mixed> */
    private function ownedActive(int $userId, string $migrationId): array
    {
        $migration = $this->migrations->getByPublicId($userId, $migrationId);
        if ($migration === null) throw new HttpException(404, 'MIGRATION_NOT_FOUND', 'Migration run not found');
        if ((string) $migration['status'] !== 'active') throw new HttpException(409, 'MIGRATION_STATE_INVALID', 'Migration is not active');
        return $migration;
    }

    /** @return array<string,mixed> */
    private function migrationOutput(array $migration, int $userId): array
    {
        $staging = $this->staging === null
            ? ['staged_count' => 0, 'expected_count' => null, 'verified' => false]
            : $this->staging->counts((string)$migration['migration_id'], $userId);
        return ['migration_id'=>$migration['migration_id'],'status'=>$migration['status'],'source_financial_revision'=>(int)$migration['source_financial_revision'],'started_at'=>$migration['started_at'],'staging'=>$staging];
    }

    private function decode(mixed $value): ?string
    {
        if (!is_string($value) || $value === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $value)) return null;
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        return $decoded === false ? null : $decoded;
    }

    private function statusResponse(int $userId): Response
    {
        $active = $this->migrations->getActive($userId);
        $latest = $active ?? $this->migrations->getLatest($userId);
        $cleanup = $latest === null ? null : $this->cleanup->getForMigration($userId, (string) $latest['migration_id']);
        return Response::json(['financial_privacy_state'=>$this->states->get($userId)->value,'financial_revision'=>$this->revisions->get($userId),'active_migration'=>$active===null?null:$this->migrationOutput($active,$userId),'latest_migration'=>$latest===null?null:$this->migrationOutput($latest,$userId),'cleanup_status'=>$cleanup===null?null:['cleanup_job_id'=>$cleanup['cleanup_job_id'],'status'=>$cleanup['status'],'attempt_count'=>(int)$cleanup['attempt_count'],'next_attempt_at'=>$cleanup['next_attempt_at']]]);
    }
}
