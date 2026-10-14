<?php

namespace App\Console\Commands;

use App\Services\AccessTrafficService;
use Illuminate\Console\Command;

class MaintainAccessTraffic extends Command
{
    protected $signature = 'access-traffic:maintain {--prune : Delete access records past retention}';
    protected $description = 'Repair durable access receipts and optionally prune expired details';

    public function handle(AccessTrafficService $service): int
    {
        $repaired = $service->repair();
        $deleted = $this->option('prune') ? $service->prune() : 0;
        $this->info("Access reports processed: {$repaired}; expired reports removed: {$deleted}");
        return self::SUCCESS;
    }
}
