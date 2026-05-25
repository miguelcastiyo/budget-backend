<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthService;
use App\Http\Request;
use App\Http\Response;
use PDO;

final class AuditLogController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthService $auth
    ) {
    }

    public function list(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: false, sessionOnly: true);
        $this->auth->requireRole($ctx, ['owner', 'admin']);

        $limit = min(100, max(1, (int) ($request->query['limit'] ?? 50)));
        $stmt = $this->pdo->prepare(
            'SELECT
                al.event_id,
                al.actor_user_id,
                u.email AS actor_email,
                al.actor_auth_type,
                al.action,
                al.target_type,
                al.target_id,
                al.ip_address,
                al.user_agent,
                al.metadata,
                al.created_at
             FROM audit_logs al
             LEFT JOIN users u ON u.id = al.actor_user_id
             ORDER BY al.created_at DESC, al.id DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $metadata = [];
            $rawMetadata = $row['metadata'] ?? null;
            if (is_string($rawMetadata) && trim($rawMetadata) !== '') {
                $decoded = json_decode($rawMetadata, true);
                if (is_array($decoded)) {
                    $metadata = $decoded;
                }
            }

            $items[] = [
                'event_id' => (string) $row['event_id'],
                'actor_user_id' => $row['actor_user_id'] !== null ? (string) $row['actor_user_id'] : null,
                'actor_email' => $row['actor_email'] !== null ? (string) $row['actor_email'] : null,
                'actor_auth_type' => (string) $row['actor_auth_type'],
                'action' => (string) $row['action'],
                'target_type' => (string) $row['target_type'],
                'target_id' => $row['target_id'] !== null ? (string) $row['target_id'] : null,
                'ip_address' => $row['ip_address'] !== null ? (string) $row['ip_address'] : null,
                'user_agent' => $row['user_agent'] !== null ? (string) $row['user_agent'] : null,
                'metadata' => $metadata,
                'created_at' => (string) $row['created_at'],
            ];
        }

        return Response::json(['items' => $items]);
    }
}
