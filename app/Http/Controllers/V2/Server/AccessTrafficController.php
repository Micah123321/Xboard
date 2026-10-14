<?php

namespace App\Http\Controllers\V2\Server;

use App\Http\Controllers\Controller;
use App\Services\AccessTrafficService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccessTrafficController extends Controller
{
    public function report(Request $request, AccessTrafficService $service): JsonResponse
    {
        $node = $request->attributes->get('node_info');
        abort_unless($node && $node->id > 0, 401);
        $id = $service->receive((int) $node->id, $request->all());
        $service->enqueue($id);
        return new JsonResponse(['data' => true]);
    }
}
