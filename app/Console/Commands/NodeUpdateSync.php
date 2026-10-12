<?php

namespace App\Console\Commands;

use App\Exceptions\NodeUpdateException;
use App\Services\NodeUpdate\ReleaseSourceService;
use Illuminate\Console\Command;

class NodeUpdateSync extends Command
{
    protected $signature = 'node-update:sync';
    protected $description = 'Discover and import the latest mi-node build manifest';

    public function handle(ReleaseSourceService $source): int
    {
        try {
            $result = $source->sync();
            $this->info('Latest mi-node: '.($result['latest']['version'] ?? 'none'));
            return self::SUCCESS;
        } catch (NodeUpdateException $exception) {
            $this->error($exception->errorCode);
            return self::FAILURE;
        }
    }
}
