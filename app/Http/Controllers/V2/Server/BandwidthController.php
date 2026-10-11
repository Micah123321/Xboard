<?php

namespace App\Http\Controllers\V2\Server;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\ServerBandwidthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BandwidthController extends Controller
{
    private function scope(Request $request, ServerBandwidthService $service): string
    {
        $machine = $request->attributes->get('machine_info');
        if ($machine instanceof ServerMachine) {
            return 'machine:' . $machine->id;
        }
        $node = $request->attributes->get('node_info');
        abort_unless($node instanceof Server, 403, 'Node required');
        return $service->scopeKey($node);
    }

    public function config(Request $request, ServerBandwidthService $service): JsonResponse
    {
        return response()->json(['data' => $service->configuration(
            $this->scope($request, $service), $request->attributes->get('node_info')
        )])->header('Cache-Control', 'no-store');
    }

    public function upload(Request $request, ServerBandwidthService $service): JsonResponse
    {
        $params = $request->validate([
            'probe_id' => 'required|uuid',
            'warmup' => 'sometimes|boolean',
        ]);
        abort_unless($request->getMimeType($request->header('Content-Type', '')) === 'bin'
            || str_starts_with($request->header('Content-Type', ''), 'application/octet-stream'), 415, 'Binary body required');
        abort_if((int) $request->header('Content-Length', 0) > ServerBandwidthService::CHUNK_BYTES, 413, 'Probe chunk too large');
        $stream = $request->getContent(true);
        $bytes = strlen(stream_get_contents($stream, ServerBandwidthService::CHUNK_BYTES + 1));
        $service->acknowledgeUpload($this->scope($request, $service), $params['probe_id'], $bytes, (bool) ($params['warmup'] ?? false));
        return response()->json(['data' => ['probe_id' => $params['probe_id'], 'bytes_received' => $bytes]])
            ->header('Cache-Control', 'no-store');
    }

    public function report(Request $request, ServerBandwidthService $service): JsonResponse
    {
        $params = $request->validate([
            'probe_id' => 'required|uuid',
            'policy_revision' => 'required|string|size:40',
            'probe_method' => 'sometimes|string|max:64',
            'bytes_received' => 'required|integer|min:0|max:' . ServerBandwidthService::MAX_BYTES,
            'duration_ms' => 'required|numeric|min:0|max:600000',
            'error' => 'nullable|string|max:512',
        ]);
        return response()->json(['data' => $service->report(
            $this->scope($request, $service), $params, $request->attributes->get('node_info')
        )])->header('Cache-Control', 'no-store');
    }
}
