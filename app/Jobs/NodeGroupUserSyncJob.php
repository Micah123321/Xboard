<?php

namespace App\Jobs;

use App\Services\NodeSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NodeGroupUserSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;
    public $timeout = 60;

    public function __construct(public readonly int $groupId)
    {
        $this->onQueue('node_sync');
        $this->afterCommit();
    }

    public function handle(): void
    {
        NodeSyncService::notifyUsersUpdatedByGroup($this->groupId);
    }
}
