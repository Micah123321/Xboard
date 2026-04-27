<?php

namespace App\Console\Commands;

use App\Services\ServerAutoOnlineService;
use Illuminate\Console\Command;

class SyncServerAutoOnline extends Command
{
    protected $signature = 'sync:server-auto-online';

    protected $description = 'Sync visible status for nodes with auto online enabled';

    public function handle(ServerAutoOnlineService $service): int
    {
        $result = $service->sync();

        $this->info(sprintf(
            'Server auto online synced: total=%d updated=%d shown=%d hidden=%d unchanged=%d',
            $result['total'],
            $result['updated'],
            $result['shown'],
            $result['hidden'],
            $result['unchanged']
        ));

        return self::SUCCESS;
    }
}
