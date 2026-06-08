<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthService;
use App\Http\Request;
use App\Http\Response;
use App\ImportExport\CsvExportService;
use App\ImportExport\CsvImportMapper;
use App\ImportExport\CsvImportService;
use App\ImportExport\DataRunRepository;
use App\Security\AuditLogger;

final class ImportExportController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly AuditLogger $audit,
        private readonly CsvImportService $imports,
        private readonly CsvExportService $exports,
        private readonly DataRunRepository $dataRuns,
        private readonly CsvImportMapper $mapper
    ) {
    }

    public function exportCsv(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);

        return $this->exports->exportCsv($ctx->userId(), $request->query);
    }

    public function importCsv(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        $file = $request->files['file'] ?? null;
        if (!is_array($file)) {
            $file = [];
        }

        return Response::json($this->imports->importCsv($ctx->userId(), $file, [
            'mode' => $request->input('mode'),
            'mapping' => $request->input('mapping'),
            'category_strategy' => $request->input('category_strategy'),
            'amount_strategy' => $request->input('amount_strategy'),
            'date_strategy' => $request->input('date_strategy'),
            'tag_strategy' => $request->input('tag_strategy'),
        ]));
    }

    public function listDataRuns(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);

        return Response::json([
            'items' => $this->dataRuns->listDataRuns($ctx->userId(), $request->query['limit'] ?? null),
        ]);
    }

    /** @param array{import_run_id:string} $params */
    public function rollbackImport(Request $request, array $params): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        $importRunId = $this->mapper->parseEntityId((string) ($params['import_run_id'] ?? ''), 'import_run_id');
        $result = $this->dataRuns->rollbackImport($ctx->userId(), $importRunId);

        $this->audit->record(
            $request,
            $ctx->userId(),
            $ctx->authType,
            'csv_import.rollback',
            'csv_import_run',
            (string) $importRunId,
            ['deleted_rows' => (int) $result['deleted_rows']]
        );

        return Response::json($result);
    }
}
