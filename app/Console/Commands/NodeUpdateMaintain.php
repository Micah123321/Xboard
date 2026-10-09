<?php

namespace App\Console\Commands;

use App\Services\NodeUpdate\NodeUpdateService;
use Illuminate\Console\Command;

class NodeUpdateMaintain extends Command
{
    protected $signature = 'node-update:maintain';
    protected $description = 'Expire node update leases without releasing uncertain capacity';

    public function handle(NodeUpdateService $service): int
    {
        $service->maintain();
        return self::SUCCESS;
    }
}
