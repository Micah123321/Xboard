<?php

namespace App\Jobs;

use App\Services\NodeSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SubscriptionLabelSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;
    public $timeout = 120;

    public function __construct(public readonly int $labelId)
    {
        $this->onQueue('node_sync');
        $this->afterCommit();
    }

    public function handle(): void
    {
        NodeSyncService::notifyUsersUpdatedByLabel($this->labelId);
    }
}
