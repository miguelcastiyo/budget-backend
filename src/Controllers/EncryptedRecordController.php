<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthService;
use App\Http\Request;
use App\Http\Response;
use App\Privacy\EncryptedRecordService;

final class EncryptedRecordController
{
    public function __construct(private readonly AuthService $auth, private readonly EncryptedRecordService $records)
    {
    }

    public function create(Request $request): Response { $ctx = $this->auth->requireAuth($request, allowApiKey: false); return Response::json($this->records->create($ctx, $request->json(), $request), 201); }
    public function get(Request $request, array $params): Response { $ctx = $this->auth->requireAuth($request, allowApiKey: false); return Response::json($this->records->get($ctx, (string) $params['record_id'])); }
    public function update(Request $request, array $params): Response { $ctx = $this->auth->requireAuth($request, allowApiKey: false); return Response::json($this->records->update($ctx, (string) $params['record_id'], $request->json(), $request)); }
    public function delete(Request $request, array $params): Response { $ctx = $this->auth->requireAuth($request, allowApiKey: false); return Response::json($this->records->delete($ctx, (string) $params['record_id'], $request->json(), $request)); }
    public function sync(Request $request): Response { $ctx = $this->auth->requireAuth($request, allowApiKey: false); return Response::json($this->records->sync($ctx, $request->query['after'] ?? null, $request->query['limit'] ?? null)); }
    public function batch(Request $request): Response { $ctx = $this->auth->requireAuth($request, allowApiKey: false); return Response::json($this->records->batch($ctx, $request->json(), $request)); }
}
