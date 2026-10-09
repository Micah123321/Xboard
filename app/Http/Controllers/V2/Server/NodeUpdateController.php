<?php

namespace App\Http\Controllers\V2\Server;

use App\Services\NodeUpdate\NodeUpdateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NodeUpdateController
{
    public function __construct(private NodeUpdateService $service) {}
    public function enroll(Request $r): JsonResponse { return new JsonResponse(['data'=>$this->service->enroll(json_decode($r->getContent(), true, 512, JSON_THROW_ON_ERROR))]); }
    public function poll(Request $r): JsonResponse { return new JsonResponse(['data'=>$this->service->poll((string)$r->bearerToken(),json_decode($r->getContent(), true, 512, JSON_THROW_ON_ERROR))]); }
    public function claim(Request $r, string $task_id): JsonResponse { return new JsonResponse(['data'=>$this->service->claim((string)$r->bearerToken(),$task_id,json_decode($r->getContent(), true, 512, JSON_THROW_ON_ERROR))]); }
    public function heartbeat(Request $r, string $task_id): JsonResponse { return new JsonResponse(['data'=>$this->service->heartbeat((string)$r->bearerToken(),$task_id,json_decode($r->getContent(), true, 512, JSON_THROW_ON_ERROR))]); }
    public function events(Request $r, string $task_id): JsonResponse { return new JsonResponse(['data'=>$this->service->event((string)$r->bearerToken(),$task_id,json_decode($r->getContent(), true, 512, JSON_THROW_ON_ERROR))]); }
}
