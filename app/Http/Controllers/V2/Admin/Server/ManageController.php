<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServerSave;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\StatServer;
use App\Services\ServerAutoOnlineService;
use App\Services\ServerGfwCheckService;
use App\Services\ServerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ManageController extends Controller
{
    public function getNodes(Request $request)
    {
        $servers = ServerService::getAllServers();
        $trafficStats = $this->buildNodeTrafficStats($servers);

        $servers = app(ServerGfwCheckService::class)->decorateServers($servers)->map(function ($item) use ($trafficStats) {
            $item['groups'] = ServerGroup::whereIn('id', $item['group_ids'] ?? [])->get(['name', 'id']);
            $item['parent'] = $item->parent;
            $item['traffic_stats'] = $trafficStats[(int) $item['id']] ?? $this->emptyNodeTrafficStats();
            return $item;
        });
        return $this->success($servers);
    }

    private function buildNodeTrafficStats($servers): array
    {
        $stats = [];
        foreach ($servers as $server) {
            $serverId = (int) $server->id;
            $stats[$serverId] = $this->emptyNodeTrafficStats();
            $stats[$serverId]['total'] = $this->buildTrafficAmount($server->u ?? 0, $server->d ?? 0);
        }

        if (empty($stats)) {
            return [];
        }

        $this->fillTrafficWindow($stats, 'today', strtotime('today'));
        $this->fillTrafficWindow($stats, 'month', strtotime(date('Y-m-01')));

        return $stats;
    }

    private function fillTrafficWindow(array &$stats, string $key, int $startAt): void
    {
        $rows = StatServer::query()
            ->selectRaw('server_id, COALESCE(SUM(u), 0) as upload, COALESCE(SUM(d), 0) as download')
            ->whereIn('server_id', array_keys($stats))
            ->where('record_type', 'd')
            ->where('record_at', '>=', $startAt)
            ->groupBy('server_id')
            ->get();

        foreach ($rows as $row) {
            $stats[(int) $row->server_id][$key] = $this->buildTrafficAmount($row->upload, $row->download);
        }
    }

    private function emptyNodeTrafficStats(): array
    {
        return [
            'today' => $this->buildTrafficAmount(0, 0),
            'month' => $this->buildTrafficAmount(0, 0),
            'total' => $this->buildTrafficAmount(0, 0),
        ];
    }

    private function buildTrafficAmount($upload, $download): array
    {
        $upload = max(0, (int) $upload);
        $download = max(0, (int) $download);

        return [
            'upload' => $upload,
            'download' => $download,
            'total' => $upload + $download,
        ];
    }

    private function syncAutoOnlineIfEnabled(Server $server): void
    {
        if ((bool) $server->auto_online) {
            app(ServerAutoOnlineService::class)->syncServer($server);
        }
    }

    public function sort(Request $request)
    {
        ini_set('post_max_size', '1m');
        $params = $request->validate([
            '*.id' => 'numeric',
            '*.order' => 'numeric'
        ]);

        try {
            DB::beginTransaction();
            collect($params)->each(function ($item) {
                if (isset($item['id']) && isset($item['order'])) {
                    Server::where('id', $item['id'])->update(['sort' => $item['order']]);
                }
            });
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e);
            return $this->fail([500, '保存失败']);

        }
        return $this->success(true);
    }

    public function save(ServerSave $request)
    {
        $params = $request->validated();
        if ($request->input('id')) {
            $server = Server::find($request->input('id'));
            if (!$server) {
                return $this->fail([400202, '服务器不存在']);
            }
            try {
                if (array_key_exists('show', $params)) {
                    $params['gfw_auto_hidden'] = false;
                    $params['gfw_auto_action_at'] = null;
                }
                $server->update($params);
                $this->syncAutoOnlineIfEnabled($server);
                return $this->success(true);
            } catch (\Exception $e) {
                Log::error($e);
                return $this->fail([500, '保存失败']);
            }
        }

        try {
            $server = Server::create($params);
            $this->syncAutoOnlineIfEnabled($server);
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '创建失败']);
        }
    }

    public function update(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer',
            'show' => 'nullable|integer',
            'auto_online' => 'nullable|boolean',
            'gfw_check_enabled' => 'nullable|boolean',
            'machine_id' => 'nullable|integer',
            'enabled' => 'nullable|boolean',
        ]);

        $server = Server::find($request->id);
        if (!$server) {
            return $this->fail([400202, '服务器不存在']);
        }

        if (array_key_exists('show', $params)) {
            $server->show = (int) $params['show'];
            $server->gfw_auto_hidden = false;
            $server->gfw_auto_action_at = null;
        }
        if (array_key_exists('auto_online', $params)) {
            $server->auto_online = (bool) $params['auto_online'];
        }
        if (array_key_exists('gfw_check_enabled', $params)) {
            $server->gfw_check_enabled = (bool) $params['gfw_check_enabled'];
        }
        if (array_key_exists('machine_id', $params)) {
            $server->machine_id = $params['machine_id'] ?: null;
        }
        if (array_key_exists('enabled', $params)) {
            $server->enabled = (bool) $params['enabled'];
        }

        if (!$server->save()) {
            return $this->fail([500, '保存失败']);
        }

        $this->syncAutoOnlineIfEnabled($server);

        return $this->success(true);
    }

    /**
     * 删除
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function drop(Request $request)
    {
        $request->validate([
            'id' => 'required|integer',
        ]);
        $server = Server::find($request->id);
        if (!$server) {
            return $this->fail([400202, '服务器不存在']);
        }
        if ($server->delete() === false) {
            return $this->fail([500, '删除失败']);
        }

        return $this->success(true);
    }

    /**
     * 批量删除节点
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function batchDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        $ids = $request->input('ids');
        if (empty($ids)) {
            return $this->fail([400, '请选择要删除的节点']);
        }

        try {
            $deleted = Server::whereIn('id', $ids)->delete();
            if ($deleted === false) {
                return $this->fail([500, '批量删除失败']);
            }
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '批量删除失败']);
        }
    }

    /**
     * 重置节点流量
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function resetTraffic(Request $request)
    {
        $request->validate([
            'id' => 'required|integer',
        ]);

        $server = Server::find($request->id);
        if (!$server) {
            return $this->fail([400202, '服务器不存在']);
        }

        try {
            $server->u = 0;
            $server->d = 0;
            $server->save();
            
            Log::info("Server {$server->id} ({$server->name}) traffic reset by admin");
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '重置失败']);
        }
    }

    /**
     * 批量重置节点流量
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function batchResetTraffic(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        $ids = $request->input('ids');
        if (empty($ids)) {
            return $this->fail([400, '请选择要重置的节点']);
        }

        try {
            Server::whereIn('id', $ids)->update([
                'u' => 0,
                'd' => 0,
            ]);
            
            Log::info("Servers " . implode(',', $ids) . " traffic reset by admin");
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '批量重置失败']);
        }
    }

    /**
     * 批量更新节点属性（show等）
     */
    public function batchUpdate(Request $request)
    {
        $params = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
            'show' => 'nullable|integer|in:0,1',
            'auto_online' => 'nullable|boolean',
            'gfw_check_enabled' => 'nullable|boolean',
            'enabled' => 'nullable|boolean',
            'machine_id' => 'nullable|integer',
            'host' => 'sometimes|required|string',
            'rate' => 'sometimes|required|numeric|min:0.01',
            'group_ids' => 'sometimes|array',
            'group_ids.*' => 'integer',
        ]);

        $ids = $params['ids'];
        if (empty($ids)) {
            return $this->fail([400, '请选择要更新的节点']);
        }

        $update = [];
        if (array_key_exists('show', $params) && $params['show'] !== null) {
            $update['show'] = (int) $params['show'];
            $update['gfw_auto_hidden'] = false;
            $update['gfw_auto_action_at'] = null;
        }
        if (array_key_exists('auto_online', $params) && $params['auto_online'] !== null) {
            $update['auto_online'] = (bool) $params['auto_online'];
        }
        if (array_key_exists('gfw_check_enabled', $params) && $params['gfw_check_enabled'] !== null) {
            $update['gfw_check_enabled'] = (bool) $params['gfw_check_enabled'];
        }
        if (array_key_exists('enabled', $params) && $params['enabled'] !== null) {
            $update['enabled'] = (bool) $params['enabled'];
        }
        if (array_key_exists('machine_id', $params)) {
            $update['machine_id'] = $params['machine_id'] ?: null;
        }
        if (array_key_exists('host', $params)) {
            $update['host'] = trim((string) $params['host']);
        }
        if (array_key_exists('rate', $params)) {
            $update['rate'] = (float) $params['rate'];
        }
        if (array_key_exists('group_ids', $params)) {
            $update['group_ids'] = $params['group_ids'];
        }

        if (empty($update)) {
            return $this->fail([400, '没有可更新的字段']);
        }

        try {
            $servers = Server::whereIn('id', $ids)->get();
            DB::transaction(function () use ($servers, $update) {
                /** @var Server $server */
                foreach ($servers as $server) {
                    $server->update($update);
                }
            });
            $servers->each(function (Server $server) {
                $freshServer = $server->fresh();
                if ($freshServer) {
                    $this->syncAutoOnlineIfEnabled($freshServer);
                }
            });
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '批量更新失败']);
        }
    }

    public function checkGfw(Request $request)
    {
        $params = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        if (empty($params['ids'])) {
            return $this->fail([400, '请选择需要检测的节点']);
        }

        $result = app(ServerGfwCheckService::class)->startChecks(
            $params['ids'],
            $request->user()?->id
        );

        return $this->success($result);
    }

    /**
     * 复制节点
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function copy(Request $request)
    {
        $server = Server::find($request->input('id'));
        if (!$server) {
            return $this->fail([400202, '服务器不存在']);
        }

        $copiedServer = $server->replicate();
        $copiedServer->show = 0;
        $copiedServer->code = null;
        $copiedServer->u = 0;
        $copiedServer->d = 0;
        $copiedServer->save();

        return $this->success(true);
    }

    /**
     * Generate ECH (Encrypted Client Hello) key pair.
     * Returns PEM-encoded ECH key (server-side) and ECH config (client-side).
     */
    public function generateEchKey(Request $request)
    {
        $publicName = $request->input('public_name', 'ech.example.com');
        if (strlen($publicName) < 1 || strlen($publicName) > 253) {
            throw new ApiException('public_name must be a valid domain (1-253 bytes)');
        }

        // Generate X25519 key pair
        $privateKey = random_bytes(32);
        $publicKey = sodium_crypto_scalarmult_base($privateKey);

        $configId = random_int(0, 255);

        // Build ECHConfigContents (draft-ietf-tls-esni-18)
        $contents = '';
        $contents .= pack('C', $configId);                // config_id
        $contents .= pack('n', 0x0020);                   // kem_id: DHKEM(X25519)
        $contents .= pack('n', 32) . $publicKey;          // public_key (length-prefixed)
        // cipher_suites: 2 suites × 4 bytes = 8 bytes
        $contents .= pack('n', 8);                        // cipher_suites byte length
        $contents .= pack('nn', 0x0001, 0x0001);          // HKDF-SHA256 + AES-128-GCM
        $contents .= pack('nn', 0x0001, 0x0003);          // HKDF-SHA256 + ChaCha20Poly1305
        $contents .= pack('C', 0);                        // max_name_length
        $contents .= pack('C', strlen($publicName)) . $publicName;
        $contents .= pack('n', 0);                        // extensions: empty

        // ECHConfig = version(2) + length(2) + contents
        $echConfig = pack('n', 0xfe0d) . pack('n', strlen($contents)) . $contents;

        // ECHConfigList = total_length(2) + configs
        $echConfigList = pack('n', strlen($echConfig)) . $echConfig;

        // ECH Keys = private_key_len(2) + key(32) + config_len(2) + config
        $echKeysPayload = pack('n', 32) . $privateKey . pack('n', strlen($echConfig)) . $echConfig;

        $keyPem = "-----BEGIN ECH KEYS-----\n"
            . chunk_split(base64_encode($echKeysPayload), 64, "\n")
            . "-----END ECH KEYS-----";

        $configPem = "-----BEGIN ECH CONFIGS-----\n"
            . chunk_split(base64_encode($echConfigList), 64, "\n")
            . "-----END ECH CONFIGS-----";

        return $this->success([
            'key' => $keyPem,
            'config' => $configPem,
        ]);
    }
}
