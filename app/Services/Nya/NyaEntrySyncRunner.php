<?php

namespace App\Services\Nya;

use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class NyaEntrySyncRunner
{
    public const LOCK = 'nya-entry-ip-sync';
    public const LAST_RUN = 'nya-entry-ip-sync:last-run';
    private const TTL = 604800;
    private const LOCK_SECONDS = 600;

    public function __construct(private NyaEntrySyncSettings $settings, private NyaEntrySyncService $service)
    {
    }

    public function status(): array
    {
        $run = Cache::get(self::LAST_RUN);
        if (is_array($run) && ($run['status'] ?? null) === 'running'
            && isset($run['started_at']) && $run['started_at'] <= time() - self::LOCK_SECONDS) {
            // A terminated PHP process cannot finalize its status; recover only after its lease is free.
            $lock = Cache::lock(self::LOCK, self::LOCK_SECONDS);
            if ($lock->get()) {
                try {
                    $current = Cache::get(self::LAST_RUN);
                    if (is_array($current) && ($current['id'] ?? null) === ($run['id'] ?? null)
                        && ($current['status'] ?? null) === 'running') {
                        $current['status'] = 'failed';
                        $current['finished_at'] = time();
                        $current['error'] = '上次运行已中断或超时，请核对节点状态后重试（NYA_RUN_EXPIRED）。';
                        Cache::put(self::LAST_RUN, $current, self::TTL);
                    }
                    $run = $current;
                } finally {
                    $lock->release();
                }
            }
        }
        return [
            'last_run' => $run,
            'scheduler' => ['last_checked_at' => Cache::get(CacheKey::get('SCHEDULE_LAST_CHECK_AT', null))],
        ];
    }

    public function run(bool $dryRun = false, string $source = 'manual', ?array $serverIds = null, ?array $ports = null): ?array
    {
        $run = [
            'id' => (string) Str::uuid(), 'status' => 'running', 'dry_run' => $dryRun,
            'source' => in_array($source, ['manual', 'schedule', 'cli'], true) ? $source : 'manual',
            'started_at' => time(), 'finished_at' => null, 'result' => null, 'error' => null,
        ];
        $lock = Cache::lock(self::LOCK, self::LOCK_SECONDS);
        if (!$lock->get()) {
            // An overlapping request must not replace the active run's state.
            return array_replace($run, ['status' => 'failed', 'finished_at' => time(),
                'error' => '已有同步任务正在运行（NYA_BUSY）。']);
        }
        try {
            $settings = $this->settings->runtime();
            if ($source === 'schedule' && !$settings['enabled']) {
                return null;
            }
            Cache::put(self::LAST_RUN, $run, self::TTL);
            if ($serverIds !== null) {
                $settings['server_ids'] = $serverIds;
            }
            if ($ports !== null) {
                $settings['ports'] = $ports;
            }
            $this->settings->assertReady($settings);
            if (function_exists('set_time_limit')) {
                @set_time_limit(210);
            }
            $run['result'] = $this->service->sync($dryRun, $serverIds, $ports, $settings);
            $run['status'] = 'succeeded';
        } catch (Throwable $exception) {
            $run['status'] = 'failed';
            $run['error'] = $this->publicError($exception);
        } finally {
            // Disabled schedules do not create or overwrite a run.
            if ($run['status'] !== 'running') {
                $run['finished_at'] = time();
                try {
                    Cache::put(self::LAST_RUN, $run, self::TTL);
                } finally {
                    $lock->release();
                }
            } else {
                $lock->release();
            }
        }
        return $run;
    }

    private function publicError(Throwable $exception): string
    {
        $messages = [
            'NYA_CONFIG_ERROR' => '同步配置不完整或节点已失效',
            'NYA_SETTINGS_ERROR' => '同步设置解密失败，请重新保存配置',
            'NYA_TRANSPORT_ERROR' => '连接 NYA 服务失败或请求超时',
            'NYA_HTTP_ERROR' => 'NYA 服务返回异常状态',
            'NYA_AUTH_ERROR' => 'NYA 登录认证失败',
            'NYA_API_ERROR' => 'NYA 接口操作失败，请检查账号和权限',
            'NYA_RESPONSE_ERROR' => 'NYA 响应格式异常',
            'NYA_RULE_ERROR' => 'NYA 转发规则无效或为空',
            'NYA_GROUP_ERROR' => 'NYA 入口设备组配置异常',
            'NYA_ADDRESS_ERROR' => 'NYA 入口地址配置异常',
            'NYA_DNS_ERROR' => 'NYA 入口域名解析失败',
            'NYA_TIMEOUT_ERROR' => '读取多账号规则超过总时限，请减少账号数量或稍后重试',
        ];
        $account = $exception instanceof NyaAccountSyncException ? '账号「' . $exception->accountName . '」：' : '';
        $code = $exception instanceof NyaAccountSyncException ? $exception->reasonCode
            : (get_class($exception) === RuntimeException::class ? $exception->getMessage() : '');
        if (isset($messages[$code])) {
            return $account . $messages[$code] . '（' . $code . '）。';
        }
        if (preg_match('/^Multiple NYA rules use connection port ([0-9]+); no changes saved\.$/D', $code, $port)) {
            return '连接端口 ' . $port[1] . ' 在多个账号或规则中重复，请核对后重试（NYA_PORT_CONFLICT）。';
        }
        return '节点同步失败，请检查节点入口、转发规则及数据库和缓存服务（NYA_SYNC_ERROR）。';
    }
}
