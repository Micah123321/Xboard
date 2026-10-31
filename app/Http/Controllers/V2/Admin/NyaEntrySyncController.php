<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\Nya\NyaEntrySyncRunner;
use App\Services\Nya\NyaEntrySyncSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class NyaEntrySyncController extends Controller
{
    public function settings(NyaEntrySyncSettings $settings, NyaEntrySyncRunner $runner): JsonResponse
    {
        try {
            return $this->success($this->payload($settings, $runner));
        } catch (Throwable) {
            return $this->fail([503001, '读取同步设置失败，请检查数据库和缓存服务。']);
        }
    }

    public function save(Request $request, NyaEntrySyncSettings $settings, NyaEntrySyncRunner $runner): JsonResponse
    {
        try {
            $settings->save($request->all());
            return $this->success($this->payload($settings, $runner));
        } catch (ValidationException $exception) {
            // Invalid URL userinfo must not reach the request audit log either.
            foreach (['base_url', 'proxy'] as $field) {
                if (!NyaEntrySyncSettings::validUrl($request->input($field, ''))) {
                    $request->merge([$field => '']);
                }
            }
            throw $exception;
        } catch (Throwable) {
            return $this->fail([503001, '保存同步设置失败，请检查加密配置和数据库服务。']);
        }
    }

    public function run(Request $request, NyaEntrySyncRunner $runner): JsonResponse
    {
        $values = $request->validate(['dry_run' => ['required', 'boolean']]);
        try {
            return $this->success($runner->run((bool) $values['dry_run'], 'manual'));
        } catch (Throwable) {
            return $this->fail([503001, '同步任务状态服务异常，请检查缓存服务。']);
        }
    }

    public function status(NyaEntrySyncRunner $runner): JsonResponse
    {
        try {
            return $this->success($runner->status());
        } catch (Throwable) {
            return $this->fail([503001, '读取同步状态失败，请检查缓存服务。']);
        }
    }

    private function payload(NyaEntrySyncSettings $settings, NyaEntrySyncRunner $runner): array
    {
        $nodes = Server::query()->select(['id', 'name', 'parent_id', 'host', 'port', 'type', 'entry_points'])
            ->orderBy('id')->get()->map(static fn (Server $server): array => [
                'id' => $server->id, 'name' => $server->name, 'parent_id' => $server->parent_id,
                'host' => $server->host, 'port' => $server->port, 'type' => $server->type,
                'entry_points_count' => is_array($server->entry_points) ? count($server->entry_points) : 0,
            ])->all();
        return ['settings' => $settings->publicSettings(), 'nodes' => $nodes] + $runner->status();
    }
}
