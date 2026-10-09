<?php

namespace App\Jobs;

use App\Models\TrafficReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;

class TrafficBillingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;
    public $timeout = 60;
    protected string $reportKey;
    protected string $payloadHash;

    public function __construct(
        protected array $server,
        protected array $data,
        protected string $protocol,
        protected int $receivedAt,
        ?string $reportId = null,
        ?string $payloadHash = null,
    ) {
        self::recordAt($receivedAt);
        $this->data = self::normalize($data);
        foreach ($this->data as $traffic) {
            self::bill($traffic[0], $server['rate']);
            self::bill($traffic[1], $server['rate']);
        }
        if ($reportId !== null && (trim($reportId) === '' || strlen($reportId) > 128)) {
            throw new InvalidArgumentException('Invalid report_id.');
        }
        $this->reportKey = $reportId === null ? 'q:' . bin2hex(random_bytes(32)) : 'h:' . hash('sha256', $reportId);
        $this->payloadHash = $payloadHash ?? self::fingerprint($this->data);
        $this->onQueue('traffic_fetch');
    }

    public static function normalize(array $data): array
    {
        $result = [];
        $total = 0;
        foreach ($data as $uid => $traffic) {
            if (filter_var($uid, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
                || !is_array($traffic) || array_keys($traffic) !== [0, 1]) {
                throw new InvalidArgumentException('Invalid traffic user or byte pair.');
            }
            foreach ($traffic as $bytes) {
                if ((!is_int($bytes) && !is_string($bytes))
                    || filter_var($bytes, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false
                    || (int) $bytes > PHP_INT_MAX - $total) {
                    throw new InvalidArgumentException('Traffic bytes must be non-negative integers within range.');
                }
                $total += (int) $bytes;
            }
            $result[(int) $uid] = [(int) $traffic[0], (int) $traffic[1]];
        }
        ksort($result, SORT_NUMERIC);
        return $result;
    }

    public static function fingerprint(array $data): string
    {
        return hash('sha256', json_encode(self::normalize($data), JSON_THROW_ON_ERROR));
    }

    public static function bill(int $bytes, $rate): int
    {
        $value = $bytes * (float) $rate;
        if ($bytes < 0 || !is_numeric($rate) || !is_finite((float) $rate) || (float) $rate < 0
            || !is_finite($value) || $value >= PHP_INT_MAX) {
            throw new InvalidArgumentException('Invalid traffic rate or billed byte overflow.');
        }
        return (int) $value;
    }

    public static function recordAt(int $timestamp, string $type = 'd'): int
    {
        if ($timestamp <= 0 || $timestamp > 253402214400 || !in_array($type, ['d', 'm'], true)) {
            throw new InvalidArgumentException('Invalid traffic date or record type.');
        }
        $date = Carbon::createFromTimestamp($timestamp, config('app.timezone', 'UTC'));
        return ($type === 'm' ? $date->startOfMonth() : $date->startOfDay())->timestamp;
    }

    public function backoff(): array
    {
        return [1, 5, 10, 30];
    }

    public function handle(): void
    {
        DB::transaction(function (): void {
            // The node lock serializes reports and protects all node-scoped aggregates.
            if (!DB::table('v2_server')->where('id', $this->server['id'])->lockForUpdate()->first()) {
                throw new InvalidArgumentException('Traffic node no longer exists.');
            }
            $key = ['node_id' => $this->server['id'], 'report_id' => $this->reportKey];
            $existing = TrafficReport::where($key)->first();
            if ($existing) {
                if (!hash_equals($existing->payload_hash, $this->payloadHash)) {
                    throw new InvalidArgumentException('report_id was reused with different traffic.');
                }
                return;
            }
            TrafficReport::create($key + ['payload_hash' => $this->payloadHash, 'received_at' => $this->receivedAt]);
            foreach ($this->data as $uid => $traffic) {
                DB::table('v2_user')->where('id', $uid)->incrementEach([
                    'u' => self::bill($traffic[0], $this->server['rate']),
                    'd' => self::bill($traffic[1], $this->server['rate']),
                ], ['t' => $this->receivedAt]);
            }
            (new StatUserJob($this->server, $this->data, $this->protocol, 'd', $this->receivedAt))->handle();
            (new StatServerJob($this->server, $this->data, $this->protocol, 'd', $this->receivedAt))->handle();
        }, 3);

        // A failed notification retries the same ledger key without charging again.
        if ($this->data) {
            Redis::sadd('traffic:pending_check', ...array_keys($this->data));
        }
    }
}
