<?php

namespace App\Console\Commands;

use App\Services\ServerGfwCheckService;
use Illuminate\Console\Command;

class SyncServerGfwChecks extends Command
{
    protected $signature = 'sync:server-gfw-checks {--limit= : Maximum number of nodes to enqueue}';

    protected $description = 'Create automated GFW check tasks for managed parent nodes';

    public function handle(ServerGfwCheckService $service): int
    {
        $limit = $this->option('limit');
        $result = $service->startAutomaticChecks(
            is_numeric($limit) ? (int) $limit : null
        );

        $this->info(sprintf(
            'Server GFW checks synced: total=%d started=%d skipped=%d active=%d',
            $result['total'],
            count($result['started']),
            count($result['skipped']),
            $result['active']
        ));

        return self::SUCCESS;
    }
}
