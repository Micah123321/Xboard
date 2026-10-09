<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;

class TrafficFetchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $data;
    protected $server;
    protected $protocol;
    protected $timestamp;
    protected ?string $billingKey = null;
    public $tries = 3;
    public $timeout = 20;

    public function __construct(array $server, array $data, $protocol, int $timestamp)
    {
        $this->onQueue('traffic_fetch');
        $this->server = $server;
        $this->data = $data;
        $this->protocol = $protocol;
        $this->timestamp = $timestamp;
        $this->billingKey = 'l:' . bin2hex(random_bytes(32));
    }

    public function handle(): void
    {
        $userIds = array_keys($this->data);

        $this->data = TrafficBillingJob::normalize($this->data);
        // Old serialized jobs have no key; the queue payload UUID remains stable on retry.
        $this->billingKey ??= 'l:' . hash('sha256', $this->job?->uuid() ?? $this->job?->getJobId() ?? bin2hex(random_bytes(32)));
        \Illuminate\Support\Facades\DB::transaction(function (): void {
            \Illuminate\Support\Facades\DB::table('v2_server')->where('id', $this->server['id'])->lockForUpdate()->first();
            $key = ['node_id' => $this->server['id'], 'report_id' => $this->billingKey];
            if (\App\Models\TrafficReport::where($key)->exists()) {
                return;
            }
            \App\Models\TrafficReport::create($key + [
                'payload_hash' => TrafficBillingJob::fingerprint($this->data),
                'received_at' => $this->timestamp ?? time(),
            ]);
            foreach ($this->data as $uid => $v) {
                User::where('id', $uid)
                    ->incrementEach(
                        [
                            'u' => TrafficBillingJob::bill($v[0], $this->server['rate']),
                            'd' => TrafficBillingJob::bill($v[1], $this->server['rate']),
                        ],
                        ['t' => $this->timestamp ?? time()]
                    );
            }
        }, 3);

        if (!empty($userIds)) {
            Redis::sadd('traffic:pending_check', ...$userIds);
        }
    }
}
