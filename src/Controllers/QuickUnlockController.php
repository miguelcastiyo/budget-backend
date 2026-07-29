<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthService;
use App\Http\Request;
use App\Http\Response;
use App\Privacy\QuickUnlockService;

final class QuickUnlockController
{
    public function __construct(private readonly AuthService $auth, private readonly QuickUnlockService $service) {}
    public function registrationOptions(Request $r):Response{return Response::json($this->service->registrationOptions($this->auth->requireAuth($r,allowApiKey:false,sessionOnly:true),$r));}
    public function registrationComplete(Request $r):Response{return Response::json($this->service->registrationComplete($this->auth->requireAuth($r,allowApiKey:false,sessionOnly:true),$r,$r->json()),201);}
    public function assertionOptions(Request $r):Response{return Response::json($this->service->assertionOptions($this->auth->requireAuth($r,allowApiKey:false,sessionOnly:true),$r));}
    public function status(Request $r):Response{return Response::json($this->service->status($this->auth->requireAuth($r,allowApiKey:false,sessionOnly:true)));}
    public function assertionComplete(Request $r):Response{return Response::json($this->service->assertionComplete($this->auth->requireAuth($r,allowApiKey:false,sessionOnly:true),$r,$r->json()));}
    public function revoke(Request $r,array $p):Response{$this->service->revoke($this->auth->requireAuth($r,allowApiKey:false,sessionOnly:true),$r,(string)$p['quick_unlock_id']);return Response::json(['ok'=>true]);}
}
