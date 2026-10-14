<?php

namespace App\Jobs;

use App\Services\AccessTrafficService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AccessTrafficJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;
    public $timeout = 60;

    public function __construct(public int $reportId)
    {
        $this->onQueue('access_traffic');
    }

    public function backoff(): array
    {
        return [1, 5, 10, 30];
    }

    public function handle(AccessTrafficService $service): void
    {
        $service->process($this->reportId);
    }
}
