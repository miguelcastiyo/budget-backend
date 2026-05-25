<?php

declare(strict_types=1);

namespace App\Security;

use App\Http\Request;
use App\Support\Str;
use PDO;

final class AuditLogger
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string,mixed> $metadata */
    public function record(
        Request $request,
        ?int $actorUserId,
        string $actorAuthType,
        string $action,
        string $targetType,
        ?string $targetId = null,
        array $metadata = []
    ): void {
        $metadataJson = json_encode($this->redactMetadata($metadata), JSON_UNESCAPED_SLASHES);
        if ($metadataJson === false) {
            $metadataJson = '{}';
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_logs (
                event_id,
                actor_user_id,
                actor_auth_type,
                action,
                target_type,
                target_id,
                ip_address,
                user_agent,
                metadata
            ) VALUES (
                :event_id,
                :actor_user_id,
                :actor_auth_type,
                :action,
                :target_type,
                :target_id,
                :ip_address,
                :user_agent,
                :metadata
            )'
        );

        $stmt->execute([
            ':event_id' => Str::randomId('aud'),
            ':actor_user_id' => $actorUserId,
            ':actor_auth_type' => $actorAuthType,
            ':action' => $action,
            ':target_type' => $targetType,
            ':target_id' => $targetId,
            ':ip_address' => $this->ipAddress(),
            ':user_agent' => substr((string) ($request->header('User-Agent') ?? ''), 0, 255),
            ':metadata' => $metadataJson,
        ]);
    }

    /** @param array<string,mixed> $metadata
     *  @return array<string,mixed>
     */
    private function redactMetadata(array $metadata): array
    {
        $redacted = [];
        foreach ($metadata as $key => $value) {
            $normalizedKey = strtolower((string) $key);
            if (
                str_contains($normalizedKey, 'token')
                || str_contains($normalizedKey, 'secret')
                || str_contains($normalizedKey, 'password')
                || str_contains($normalizedKey, 'hash')
                || str_contains($normalizedKey, 'code')
            ) {
                $redacted[$key] = '[redacted]';
                continue;
            }

            if (is_array($value)) {
                $redacted[$key] = $this->redactMetadata($value);
                continue;
            }

            if (is_string($value) && strlen($value) > 512) {
                $redacted[$key] = substr($value, 0, 512);
                continue;
            }

            $redacted[$key] = $value;
        }

        return $redacted;
    }

    private function ipAddress(): ?string
    {
        $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        return $ip === '' ? null : substr($ip, 0, 45);
    }
}
