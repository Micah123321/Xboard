<?php

namespace Tests\Unit;

use App\Jobs\StatServerJob;
use App\Jobs\StatUserJob;
use App\Jobs\TrafficBillingJob;
use App\Http\Resources\TrafficLogResource;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Mockery;
use PHPUnit\Framework\TestCase;

class TrafficBillingJobTest extends TestCase
{
    private Manager $db;
    private array $server = ['id' => 1, 'type' => 'vmess', 'rate' => 1.5];
    private int $receivedAt;

    protected function setUp(): void
    {
        parent::setUp();
        $app = new Container();
        Container::setInstance($app);
        $app->instance('app', $app);
        $app->instance('config', new Repository(['app' => ['timezone' => 'Asia/Shanghai']]));
        $this->db = new Manager($app);
        $this->db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->db->setAsGlobal();
        $this->db->bootEloquent();
        $app->instance('db', $this->db->getDatabaseManager());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        Log::swap(new \Psr\Log\NullLogger());
        Redis::swap(Mockery::mock()->shouldReceive('sadd')->andReturn(1)->getMock());
        $schema = $this->db->schema();
        foreach (['v2_user', 'v2_server'] as $name) {
            $schema->create($name, function (Blueprint $table): void {
                $table->integer('id')->primary();
                $table->string('name')->nullable();
                $table->bigInteger('u')->default(0);
                $table->bigInteger('d')->default(0);
                $table->bigInteger('t')->nullable();
                $table->string('updated_at')->nullable();
            });
        }
        $schema->create('v2_traffic_report', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('node_id');
            $table->string('report_id', 66);
            $table->string('payload_hash', 64);
            $table->bigInteger('received_at');
            $table->unique(['node_id', 'report_id']);
        });
        foreach (['v2_stat_user', 'v2_stat_server'] as $name) {
            $schema->create($name, function (Blueprint $table) use ($name): void {
                $table->increments('id');
                $table->integer('server_id');
                $table->string('server_type');
                $table->bigInteger('record_at');
                $table->string('record_type');
                $table->bigInteger('u');
                $table->bigInteger('d');
                $table->bigInteger('created_at');
                $table->bigInteger('updated_at');
                if ($name === 'v2_stat_user') {
                    $table->integer('user_id');
                    $table->double('server_rate');
                    $table->unique(['user_id', 'server_rate', 'server_id', 'server_type', 'record_at', 'record_type'], 'user_stat_unique');
                } else {
                    $table->unique(['server_id', 'server_type', 'record_at']);
                }
            });
        }
        DB::table('v2_user')->insert([['id' => 1], ['id' => 2]]);
        DB::table('v2_server')->insert([['id' => 1], ['id' => 2]]);
        $this->receivedAt = Carbon::parse('2026-08-31 23:59:59', 'Asia/Shanghai')->timestamp;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        $this->db->getDatabaseManager()->disconnect();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    private function job(?string $id = 'batch-1', ?array $data = null): TrafficBillingJob
    {
        return new TrafficBillingJob($this->server, $data ?? [1 => [3, 5], 2 => [1, 1]], 'vmess', $this->receivedAt, $id);
    }

    public function test_duplicate_http_and_serialized_queue_retry_charge_once_on_received_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02 12:00:00', 'Asia/Shanghai'));
        $job = $this->job();
        $job->handle();
        unserialize(serialize($job))->handle();
        $this->job()->handle();
        self::assertSame(1, DB::table('v2_traffic_report')->count());
        self::assertSame(4, DB::table('v2_user')->where('id', 1)->value('u'));
        self::assertSame(7, DB::table('v2_user')->where('id', 1)->value('d'));
        self::assertSame(5, (int) DB::table('v2_stat_user')->sum('u'));
        self::assertSame(4, DB::table('v2_stat_server')->value('u'));
        self::assertSame(6, DB::table('v2_server')->where('id', 1)->value('d'));
        self::assertSame($this->receivedAt, DB::table('v2_user')->where('id', 1)->value('t'));
        self::assertSame(Carbon::parse('2026-08-31', 'Asia/Shanghai')->timestamp, DB::table('v2_stat_user')->value('record_at'));
    }

    public function test_same_id_with_different_payload_fails_without_writes(): void
    {
        $this->job()->handle();
        try {
            $this->job('batch-1', [1 => [99, 5]])->handle();
            self::fail('Expected payload conflict');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('different traffic', $e->getMessage());
        }
        self::assertSame(4, DB::table('v2_user')->where('id', 1)->value('u'));
        self::assertSame(1, DB::table('v2_traffic_report')->count());
    }

    public function test_report_key_is_node_scoped_and_case_sensitive(): void
    {
        $this->job('ID')->handle();
        $this->job('id')->handle();
        $this->server['id'] = 2;
        $this->job('ID')->handle();
        self::assertSame(3, DB::table('v2_traffic_report')->count());
        self::assertSame(12, DB::table('v2_user')->where('id', 1)->value('u'));
    }

    public function test_legacy_http_has_queue_idempotency_only(): void
    {
        $job = $this->job(null);
        $job->handle();
        unserialize(serialize($job))->handle();
        self::assertSame(4, DB::table('v2_user')->where('id', 1)->value('u'));
        $this->job(null)->handle();
        self::assertSame(8, DB::table('v2_user')->where('id', 1)->value('u'));
    }

    public function test_last_write_failure_rolls_back_ledger_and_all_aggregates_then_retries(): void
    {
        Redis::swap(Mockery::mock()->shouldNotReceive('sadd')->getMock());
        DB::unprepared("CREATE TRIGGER fail_total BEFORE UPDATE ON v2_server BEGIN SELECT RAISE(ABORT, 'injected failure'); END");
        $job = $this->job();
        try {
            $job->handle();
            self::fail('Expected database failure');
        } catch (\Illuminate\Database\QueryException $e) {
            self::assertStringContainsString('injected failure', $e->getMessage());
        }
        self::assertSame(0, DB::table('v2_traffic_report')->count());
        self::assertSame(0, DB::table('v2_stat_user')->count());
        self::assertSame(0, DB::table('v2_stat_server')->count());
        self::assertSame(0, (int) DB::table('v2_user')->sum('u'));
        DB::unprepared('DROP TRIGGER fail_total');
        Redis::swap(Mockery::mock()->shouldReceive('sadd')->once()->andReturn(1)->getMock());
        unserialize(serialize($job))->handle();
        self::assertSame(1, DB::table('v2_traffic_report')->count());
        self::assertSame(4, DB::table('v2_server')->where('id', 1)->value('u'));
    }

    public function test_redis_failure_retries_notification_after_commit_without_double_charge(): void
    {
        $redis = Mockery::mock();
        $redis->shouldReceive('sadd')->once()->andReturnUsing(function (): void {
            self::assertSame(0, DB::connection()->transactionLevel());
            self::assertSame(1, DB::table('v2_traffic_report')->count());
            throw new \RuntimeException('Redis offline');
        });
        Redis::swap($redis);
        $job = $this->job();
        try {
            $job->handle();
            self::fail('Expected Redis failure');
        } catch (\RuntimeException $e) {
            self::assertSame('Redis offline', $e->getMessage());
        }
        Redis::swap(Mockery::mock()->shouldReceive('sadd')->once()->with('traffic:pending_check', 1, 2)->andReturn(2)->getMock());
        unserialize(serialize($job))->handle();
        self::assertSame(4, DB::table('v2_user')->where('id', 1)->value('u'));
    }

    public function test_old_user_stat_batch_rolls_back_partial_success(): void
    {
        DB::unprepared("CREATE TRIGGER fail_second BEFORE INSERT ON v2_stat_user WHEN NEW.user_id = 2 BEGIN SELECT RAISE(ABORT, 'second user failure'); END");
        $job = new StatUserJob($this->server, [1 => [3, 5], 2 => [1, 1]], 'vmess', 'd', $this->receivedAt);
        try {
            $job->handle();
            self::fail('Expected second user failure');
        } catch (\Illuminate\Database\QueryException $e) {
            self::assertStringContainsString('second user failure', $e->getMessage());
        }
        self::assertSame(0, DB::table('v2_stat_user')->count());
        DB::unprepared('DROP TRIGGER fail_second');
        $job->handle();
        self::assertSame(5, (int) DB::table('v2_stat_user')->sum('u'));
    }

    public function test_old_server_stat_rolls_back_daily_when_total_fails(): void
    {
        DB::unprepared("CREATE TRIGGER fail_total BEFORE UPDATE ON v2_server BEGIN SELECT RAISE(ABORT, 'total failure'); END");
        $job = new StatServerJob($this->server, [1 => [3, 5]], 'vmess', 'd', $this->receivedAt);
        try {
            $job->handle();
            self::fail('Expected total failure');
        } catch (\Illuminate\Database\QueryException $e) {
            self::assertStringContainsString('total failure', $e->getMessage());
        }
        self::assertSame(0, DB::table('v2_stat_server')->count());
        DB::unprepared('DROP TRIGGER fail_total');
        $job->handle();
        self::assertSame(3, DB::table('v2_stat_server')->value('u'));
        self::assertSame(3, DB::table('v2_server')->where('id', 1)->value('u'));
    }

    public function test_invalid_bytes_dates_and_rates_are_rejected_before_writes(): void
    {
        foreach ([[1 => [-1, 0]], [1 => [1.5, 0]], [0 => [1, 0]], [1 => ['1e3', 0]], [1 => [PHP_INT_MAX, 1]]] as $data) {
            try {
                $this->job('invalid', $data);
                self::fail('Expected invalid bytes');
            } catch (\InvalidArgumentException) {
                self::assertSame(0, DB::table('v2_traffic_report')->count());
            }
        }
        foreach ([0, -1, PHP_INT_MAX] as $date) {
            try {
                TrafficBillingJob::recordAt($date);
                self::fail('Expected invalid date');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        foreach ([-1, INF, NAN, 'bad'] as $rate) {
            try {
                TrafficBillingJob::bill(1, $rate);
                self::fail('Expected invalid rate');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        self::assertSame(0, TrafficBillingJob::bill(1, 0.5));
        self::assertSame(4, TrafficBillingJob::bill(3, 1.5));
        self::assertSame(0, TrafficBillingJob::bill(100, 0));
    }

    public function test_dispatches_one_full_batch_with_received_time_rate_and_both_hooks(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 12:00:00', 'Asia/Shanghai'));
        $node = new \App\Models\Server();
        $node->setRawAttributes([
            'id' => 1, 'type' => 'vmess', 'rate' => 9, 'rate_time_enable' => true,
            'rate_time_ranges' => json_encode([['start' => '23:00', 'end' => '23:59', 'rate' => 0.5]]),
        ]);
        $calls = [];
        foreach (['traffic.process.before', 'traffic.before_process'] as $hook) {
            \App\Services\Plugin\HookManager::registerFilter($hook, function ($payload) use (&$calls, $hook) {
                $calls[] = $hook;
                return $payload;
            });
        }
        $data = array_fill(1, 1001, [3, 5]);
        $bus = Mockery::mock(\Illuminate\Contracts\Bus\Dispatcher::class);
        $bus->shouldReceive('dispatch')->once()->with(Mockery::on(function ($job) use ($data): bool {
            self::assertInstanceOf(TrafficBillingJob::class, $job);
            self::assertSame('traffic_fetch', $job->queue);
            $reflection = new \ReflectionObject($job);
            self::assertSame(0.5, $reflection->getProperty('server')->getValue($job)['rate']);
            self::assertSame($this->receivedAt, $reflection->getProperty('receivedAt')->getValue($job));
            self::assertSame($data, $reflection->getProperty('data')->getValue($job));
            return true;
        }));
        Container::getInstance()->instance(\Illuminate\Contracts\Bus\Dispatcher::class, $bus);
        (new \App\Services\UserService())->trafficFetch($node, 'vmess', $data, 'full-batch', $this->receivedAt);
        self::assertSame(['traffic.process.before', 'traffic.before_process'], $calls);
    }

    public function test_old_serialized_stat_tasks_without_received_at_still_execute(): void
    {
        foreach ([StatUserJob::class, StatServerJob::class] as $class) {
            $job = new $class($this->server, [1 => [3, 5]], 'vmess');
            $payload = $job->__serialize();
            unset($payload["\0*\0receivedAt"]);
            $restored = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
            $restored->__unserialize($payload);
            $restored->handle();
        }
        self::assertSame(4, DB::table('v2_stat_user')->value('u'));
        self::assertSame(3, DB::table('v2_stat_server')->value('u'));
    }

    public function test_old_serialized_traffic_task_uses_stable_queue_uuid_for_notification_retry(): void
    {
        $old = new \App\Jobs\TrafficFetchJob($this->server, [1 => [3, 5]], 'vmess', $this->receivedAt);
        $payload = $old->__serialize();
        unset($payload["\0*\0billingKey"]);
        $queueJob = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $queueJob->shouldReceive('uuid')->twice()->andReturn('old-queue-uuid');
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $job = (new \ReflectionClass($old))->newInstanceWithoutConstructor();
            $job->__unserialize($payload);
            $job->setJob($queueJob);
            $job->handle();
        }
        self::assertSame(4, DB::table('v2_user')->where('id', 1)->value('u'));
        self::assertSame(1, DB::table('v2_traffic_report')->count());
        self::assertSame(0, DB::table('v2_stat_user')->count());
    }

    public function test_log_window_includes_yesterday_on_month_start_and_whole_current_month(): void
    {
        $app = Container::getInstance();
        $app->instance('cache', Mockery::mock()->shouldReceive('get')->andReturn([])->getMock());
        $request = new Request();
        $request->setUserResolver(fn() => (object) ['id' => 1]);
        $app->instance('request', $request);
        $controller = new class extends \App\Http\Controllers\V1\User\StatController {
            public function success($data = null, $codeResponse = \App\Helpers\ResponseEnum::HTTP_OK): \Illuminate\Http\JsonResponse
            {
                return new \Illuminate\Http\JsonResponse($data->resolve(request()));
            }
        };
        foreach (['2026-08-30', '2026-08-31', '2026-09-01', '2026-09-02'] as $day) {
            (new StatUserJob($this->server, [1 => [3, 5]], 'vmess', 'd', Carbon::parse($day, 'Asia/Shanghai')->timestamp))->handle();
        }
        (new StatUserJob($this->server, [1 => [30, 50]], 'vmess', 'm', Carbon::parse('2026-09-01', 'Asia/Shanghai')->timestamp))->handle();
        Carbon::setTestNow(Carbon::parse('2026-09-01 00:05', 'Asia/Shanghai'));
        $rows = $controller->getTrafficLog($request)->getData(true);
        self::assertCount(2, $rows);
        self::assertContains(Carbon::parse('2026-08-31', 'Asia/Shanghai')->timestamp, array_column($rows, 'record_at'));
        Carbon::setTestNow(Carbon::parse('2026-09-20', 'Asia/Shanghai'));
        self::assertCount(2, $controller->getTrafficLog($request)->getData(true));
    }

    public function test_resource_exposes_timezone_and_billed_bytes_without_live_device_attribution(): void
    {
        $data = (new TrafficLogResource([
            'u' => 4, 'd' => 7, 'server_rate' => 1.5, 'record_at' => $this->receivedAt,
            'device_ips' => ['192.0.2.1'], 'device_count' => 1, 'device_name' => 'live device',
        ]))->toArray(new Request());
        self::assertSame('Asia/Shanghai', $data['stat_timezone']);
        self::assertSame(4, $data['u']);
        self::assertSame(7, $data['d']);
        self::assertSame([], $data['device_ips']);
        self::assertSame(0, $data['device_count']);
        self::assertSame('unavailable', $data['device_attribution']);
    }
}
