<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthService;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Privacy\RecentAuthGuard;
use App\Devices\DeviceLifecycleService;
use PDO;

final class DeviceController
{
    public function __construct(private readonly PDO $pdo, private readonly AuthService $auth, private readonly RecentAuthGuard $recentAuth, private readonly DeviceLifecycleService $lifecycle)
    {
    }

    public function list(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: false, sessionOnly: true);
        $stmt = $this->pdo->prepare('SELECT device_id, session_id, client_type, user_agent, created_at, last_seen_at, expires_at, revoked_at FROM user_sessions WHERE user_id = :user_id ORDER BY COALESCE(last_seen_at, created_at) DESC, id DESC');
        $stmt->execute([':user_id' => $ctx->userId()]);
        $groups=[]; foreach($stmt->fetchAll() as $row){$id=(string)$row['device_id']; if(!isset($groups[$id])){$groups[$id]=$row;$groups[$id]['has_active']=false;} if($row['revoked_at']===null)$groups[$id]['has_active']=true; if(($row['last_seen_at']??$row['created_at'])>($groups[$id]['last_seen_at']??$groups[$id]['created_at'])){$active=$groups[$id]['has_active'];$groups[$id]=$row;$groups[$id]['has_active']=$active;}}
        $quick=$this->pdo->prepare('SELECT DISTINCT device_id FROM vault_quick_unlock_credentials WHERE user_id=:u AND status="active"');$quick->execute([':u'=>$ctx->userId()]);$quickIds=array_fill_keys(array_map('strval',$quick->fetchAll(PDO::FETCH_COLUMN)),true);$currentDevice=$ctx->deviceId ?: $ctx->sessionId;
        $items=array_map(static function(array $row)use($quickIds,$currentDevice):array{$active=(bool)$row['has_active'];return ['id'=>(string)$row['device_id'],'device_id'=>(string)$row['device_id'],'client_type'=>(string)$row['client_type'],'label'=>self::label((string)($row['user_agent']??''),(string)$row['client_type']),'created_at'=>(string)$row['created_at'],'last_seen_at'=>$row['last_seen_at']===null?null:(string)$row['last_seen_at'],'expires_at'=>(string)$row['expires_at'],'revoked_at'=>$active?null:((string)($row['revoked_at']??'')),'is_current'=>$currentDevice===(string)$row['device_id'],'status'=>$active?'active':'revoked','quick_unlock'=>['status'=>isset($quickIds[(string)$row['device_id']])?'enabled':'not_enabled']];},array_values($groups));
        return Response::json(['items' => $items]);
    }

    public function revoke(Request $request, array $params): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: false, sessionOnly: true);
        $this->recentAuth->requireRecentInteractiveSession($ctx);
        $id = (string) ($params['device_id'] ?? $params['session_id'] ?? '');
        if ($id === '') throw new HttpException(422, 'VALIDATION_ERROR', 'Session id is required');
        return Response::json($this->lifecycle->remove($ctx, $request, $id));
    }

    private static function label(string $userAgent, string $clientType): string
    {
        if ($clientType === 'native') return 'Native app';
        if (stripos($userAgent, 'Safari') !== false && stripos($userAgent, 'Chrome') === false) return stripos($userAgent, 'iPhone') !== false ? 'Safari on iPhone' : 'Safari';
        if (stripos($userAgent, 'Chrome') !== false) return 'Chrome on desktop';
        return 'Web browser';
    }
}
