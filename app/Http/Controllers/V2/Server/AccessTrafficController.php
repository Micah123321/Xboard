<?php

namespace App\Http\Controllers\V2\Server;

use App\Http\Controllers\Controller;
use App\Services\AccessTrafficService;
use App\Services\ChildNodeTrafficService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccessTrafficController extends Controller
{
    public function report(Request $request, AccessTrafficService $service): JsonResponse
    {
        $node = $request->attributes->get('node_info');
        abort_unless($node && $node->id > 0, 401);
        $input = $request->all();
        $totals = $input['totals'] ?? [];
        $virtual = is_array($totals) && array_filter(array_keys($totals), fn ($uid) => is_numeric($uid) && $uid >= ChildNodeTrafficService::AUTH_ID_OFFSET);
        if ($virtual) {
            $service->receiveFromSource($node, $input);
        } else {
            $id = $service->receive((int) $node->id, $input);
            $service->enqueue($id);
        }
        return new JsonResponse(['data' => true]);
    }
}
