<?php

namespace App\Console\Commands;

use App\Services\ServerTcpCheckService;
use Illuminate\Console\Command;

class SyncServerTcpChecks extends Command
{
    protected $signature = 'sync:server-tcp-checks {--limit= : Maximum number of child nodes to check}';

    protected $description = 'Check TCP reachability for child nodes with TCP check enabled';

    public function handle(ServerTcpCheckService $service): int
    {
        $limit = $this->option('limit');
        $result = $service->sync(is_numeric($limit) ? (int) $limit : null);

        $this->info(sprintf(
            'Server TCP checks synced: total=%d checked=%d online=%d offline=%d skipped=%d',
            $result['total'],
            $result['checked'],
            $result['online'],
            $result['offline'],
            $result['skipped']
        ));

        return self::SUCCESS;
    }
}
