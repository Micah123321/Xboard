<?php

namespace App\Console\Commands;

use App\Services\ServerTrafficLimitService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncServerTrafficLimits extends Command
{
    protected $signature = 'sync:server-traffic-limits';

    protected $description = '重置到期的节点流量限额状态';

    public function handle(ServerTrafficLimitService $service): int
    {
        try {
            $result = $service->resetDueServers();
            $this->info("处理 {$result['processed']} 个节点，重置 {$result['reset']} 个节点");

            if (!empty($result['errors'])) {
                $this->warn('部分节点重置失败，详情请查看日志');
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('节点流量限额同步失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->error("节点流量限额同步失败: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
