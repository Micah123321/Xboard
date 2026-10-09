<?php

namespace App\Http\Controllers\V2\Admin;

use App\Exceptions\NodeUpdateException;
use App\Services\NodeUpdate\NodeUpdateService;
use App\Services\NodeUpdate\Protocol;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NodeUpdateController
{
    public function __construct(private NodeUpdateService $service) {}

    public function handle(Request $r): JsonResponse
    {
        try {
            $admin=Auth::guard('sanctum')->user();
            if (!$admin || !$admin->is_admin) Protocol::fail('invalid_credentials',403);
            if ($r->method()!=='GET') {
                if (!$r->isJson() || $r->query->count() || strlen($r->getContent())>131072 || !is_object(json_decode($r->getContent()))) Protocol::fail();
            }
            $path=substr($r->path(),strpos($r->path(),'/server/update/')+15);
            $data=$r->method()==='GET' ? $r->query->all() : json_decode($r->getContent(), true, 512, JSON_THROW_ON_ERROR);
            return new JsonResponse(['data'=>$this->service->admin((int)$admin->id,$r->method(),$path,$data,$r->header('Idempotency-Key'))]);
        } catch (NodeUpdateException $e) { return $e->render($r); }
    }
}
