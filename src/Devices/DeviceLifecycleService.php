<?php

declare(strict_types=1);

namespace App\Devices;

use App\Auth\AuthContext;
use App\Http\HttpException;
use App\Privacy\QuickUnlockRepository;
use App\Security\AuditLogger;
use App\Http\Request;
use PDO;

final class DeviceLifecycleService
{
    public function __construct(private readonly PDO $pdo, private readonly QuickUnlockRepository $quickUnlock, private readonly AuditLogger $audit) {}

    public function remove(AuthContext $auth, Request $httpRequest, string $deviceId): array
    {
        if ($auth->authType !== 'session' || $auth->sessionId === null) throw new HttpException(403, 'SESSION_REQUIRED', 'Interactive session required');
        $this->pdo->beginTransaction();
        try {
            $select = $this->pdo->prepare('SELECT device_id, SUM(revoked_at IS NULL) AS active_sessions FROM user_sessions WHERE user_id=:u AND device_id=:d GROUP BY device_id FOR UPDATE');
            $select->execute([':u'=>$auth->userId(), ':d'=>$deviceId]); $row=$select->fetch();
            if (!is_array($row)) { $this->pdo->rollBack(); throw new HttpException(404,'DEVICE_NOT_FOUND','Device was not found'); }
            $this->quickUnlock->revokeByDevice($auth->userId(),$deviceId);
            $sessions=$this->pdo->prepare('UPDATE user_sessions SET revoked_at=COALESCE(revoked_at,UTC_TIMESTAMP()) WHERE user_id=:u AND device_id=:d AND revoked_at IS NULL');$sessions->execute([':u'=>$auth->userId(),':d'=>$deviceId]);
            $this->pdo->commit();
            $current=$auth->deviceId!==null && hash_equals((string)$auth->deviceId,$deviceId);
            try { $this->audit->record($httpRequest,$auth->userId(),$auth->authType,'device.removed','device',$deviceId,['current_device'=>$current]); } catch (\Throwable) { /* audit failure must not undo committed authorization revocation */ }
            return ['status'=>'removed','device_id'=>$deviceId,'current_device'=>$current];
        } catch (\Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }
}
