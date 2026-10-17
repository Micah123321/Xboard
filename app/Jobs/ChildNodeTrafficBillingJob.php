<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ChildNodeTrafficBillingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;
    public $timeout = 60;

    /** @param TrafficBillingJob[] $groups Root receipt is first, even with no own traffic. */
    public function __construct(protected array $groups)
    {
        if (!$groups) throw new InvalidArgumentException('Missing root traffic receipt.');
        $this->onQueue('traffic_fetch');
    }

    public function backoff(): array
    {
        return [1, 5, 10, 30];
    }

    public function handle(): void
    {
        DB::transaction(function (): void {
            // Root receipt guards the complete wire payload, including newly introduced children.
            if (!$this->groups[0]->settle()) return;
            foreach (array_slice($this->groups, 1) as $group) $group->settle();
        }, 3);

        $notify = function (): void {
            foreach ($this->groups as $group) $group->notify();
        };
        if (DB::connection()->transactionLevel() > 0) {
            DB::afterCommit($notify);
        } else {
            $notify();
        }
    }
}
