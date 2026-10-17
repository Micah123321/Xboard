<?php

namespace Tests\Unit;

use App\Http\Controllers\V2\Server\AccessTrafficController;
use App\Jobs\ChildNodeTrafficBillingJob;
use App\Jobs\TrafficBillingJob;
use App\Models\Server;
use App\Services\AccessTrafficService;
use App\Services\ChildNodeTrafficService;
use App\Services\Plugin\HookManager;
use App\Support\Setting;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Mockery;
use PHPUnit\Framework\TestCase;

class ChildNodeTrafficTest extends TestCase
{
    private Manager $db;
    private Container $app;
    private ChildNodeTrafficService $children;
    private AccessTrafficService $access;
    private array $jobs = [];
    private int $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = new Container();
        Container::setInstance($this->app);
        $this->app->instance('app', $this->app);
        $this->app->instance('config', new Repository([
            'app' => ['timezone' => 'Asia/Shanghai'],
            'access_traffic' => require __DIR__ . '/../../config/access_traffic.php',
        ]));
        $this->db = new Manager($this->app);
        $this->db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->db->setAsGlobal();
        $this->db->bootEloquent();
        $this->app->instance('db', $this->db->getDatabaseManager());
        $this->app->instance('db.schema', $this->db->schema());
        $this->db->getConnection()->setTransactionManager(new DatabaseTransactionsManager());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        Log::swap(new \Psr\Log\NullLogger());
        Redis::swap(Mockery::mock()->shouldReceive('sadd')->andReturn(1)->getMock());
        $this->app->instance('validator', new Factory(new Translator(new ArrayLoader(), 'en'), $this->app));
        $this->app->instance(Setting::class, Mockery::mock(Setting::class)->shouldReceive('get')->andReturn(null)->getMock());
        $this->captureJobs();
        $schema = $this->db->schema();
        foreach (['v2_user', 'v2_server'] as $name) {
            $schema->create($name, function (Blueprint $table) use ($name): void {
                $table->integer('id')->primary();
                $table->string('name')->nullable();
                $table->bigInteger('u')->default(0);
                $table->bigInteger('d')->default(0);
                $table->bigInteger('t')->nullable();
                $table->string('updated_at')->nullable();
                if ($name === 'v2_server') {
                    $table->integer('parent_id')->nullable();
                    $table->string('type')->default('vmess');
                    $table->double('rate')->default(1);
                    $table->boolean('child_rate_enabled')->default(false);
                    $table->boolean('rate_time_enable')->default(false);
                    $table->text('rate_time_ranges')->nullable();
                }
            });
        }
        $schema->create('v2_server_child_identity', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->integer('parent_id');
            $table->integer('child_id');
            $table->integer('user_id');
            $table->unique(['parent_id', 'child_id', 'user_id']);
        });
        (require __DIR__ . '/../../database/migrations/2026_08_28_000001_create_traffic_reports_table.php')->up();
        (require __DIR__ . '/../../database/migrations/2026_09_01_000001_create_access_traffic_tables.php')->up();
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
                } else $table->unique(['server_id', 'server_type', 'record_at']);
            });
        }
        DB::table('v2_user')->insert([['id' => 1], ['id' => 2]]);
        DB::table('v2_server')->insert([
            ['id' => 1, 'parent_id' => null, 'rate' => 4],
            ['id' => 2, 'parent_id' => 1, 'rate' => 3],
            ['id' => 3, 'parent_id' => 1, 'rate' => 2],
            ['id' => 4, 'parent_id' => null, 'rate' => 1],
        ]);
        DB::table('v2_server_child_identity')->insert([
            ['id' => 1, 'parent_id' => 1, 'child_id' => 2, 'user_id' => 1],
            ['id' => 2, 'parent_id' => 1, 'child_id' => 3, 'user_id' => 1],
            ['id' => 3, 'parent_id' => 4, 'child_id' => 2, 'user_id' => 2],
        ]);
        $this->children = new ChildNodeTrafficService();
        $this->access = new AccessTrafficService();
        $this->app->instance(AccessTrafficService::class, $this->access);
        $this->now = time();
    }

    private function captureJobs(): void
    {
        $bus = Mockery::mock(Dispatcher::class);
        $bus->shouldReceive('dispatch')->andReturnUsing(function ($job): void { $this->jobs[] = $job; });
        $this->app->instance(Dispatcher::class, $bus);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        HookManager::reset();
        Mockery::close();
        $this->db->getDatabaseManager()->disconnect();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    private function auth(int $id = 1): int
    {
        return ChildNodeTrafficService::AUTH_ID_OFFSET + $id;
    }

    private function job(array $traffic, string $id = 'batch', ?int $at = null): ChildNodeTrafficBillingJob
    {
        $this->children->processTraffic(Server::findOrFail(1), $traffic, $id, $at ?? $this->now);
        $job = end($this->jobs);
        self::assertInstanceOf(ChildNodeTrafficBillingJob::class, $job);
        return $job;
    }

    private function payload(array $totals, string $id = 'batch'): array
    {
        $entries = [];
        foreach ($totals as $uid => [$u, $d]) {
            $entries[] = ['user_id' => $uid, 'domain' => 'example.com', 'ip' => '', 'port' => 443,
                'network' => 'tcp', 'domain_source' => 'request', 'bucket_at' => intdiv($this->now, 300) * 300,
                'first_seen' => $this->now, 'last_seen' => $this->now, 'u' => $u, 'd' => $d, 'connections' => 1];
        }
        return ['version' => 1, 'report_id' => 'access-' . $id, 'billing_report_id' => $id,
            'kernel' => 'sing-box', 'observed_at' => $this->now, 'coverage' => 'complete',
            'dropped_entries' => 0, 'totals' => $totals, 'entries' => $entries];
    }

    public function test_parent_four_child_three_and_second_child_same_user_have_independent_stats(): void
    {
        $traffic = [1 => [10, 20], $this->auth() => [10, 20], $this->auth(2) => [5, 10]];
        $job = $this->job($traffic);
        self::assertSame('traffic_fetch', $job->queue);
        $job->handle();
        unserialize(serialize($job))->handle();
        self::assertSame(80, DB::table('v2_user')->where('id', 1)->value('u'));
        self::assertSame(160, DB::table('v2_user')->where('id', 1)->value('d'));
        self::assertSame([10, 10, 5], DB::table('v2_server')->whereIn('id', [1, 2, 3])->orderBy('id')->pluck('u')->all());
        self::assertSame([40, 30, 10], DB::table('v2_stat_user')->orderBy('server_id')->pluck('u')->all());
        self::assertSame(3, DB::table('v2_traffic_report')->count());
        self::assertSame(TrafficBillingJob::fingerprint($traffic), DB::table('v2_traffic_report')->where('node_id', 1)->value('payload_hash'));
    }

    public function test_root_duplicate_detects_added_child_and_changed_child_bytes(): void
    {
        $traffic = [$this->auth() => [3, 5]];
        $this->job($traffic)->handle();
        foreach ([$traffic + [$this->auth(2) => [9, 9]], [$this->auth() => [4, 5]]] as $conflict) {
            try { $this->job($conflict)->handle(); self::fail('Expected root conflict'); }
            catch (\InvalidArgumentException $e) { self::assertStringContainsString('different traffic', $e->getMessage()); }
        }
        self::assertSame(9, DB::table('v2_user')->where('id', 1)->value('u'));
        self::assertSame(2, DB::table('v2_traffic_report')->count());
        self::assertSame(0, DB::table('v2_server')->where('id', 1)->value('u'));
        self::assertSame(0, DB::table('v2_server')->where('id', 3)->value('u'));
    }

    public function test_second_child_database_failure_rolls_back_every_group_then_retries(): void
    {
        Redis::swap(Mockery::mock()->shouldNotReceive('sadd')->getMock());
        DB::unprepared("CREATE TRIGGER fail_child BEFORE UPDATE ON v2_server WHEN OLD.id = 3 BEGIN SELECT RAISE(ABORT, 'child failure'); END");
        $job = $this->job([1 => [1, 1], $this->auth() => [2, 2], $this->auth(2) => [3, 3]]);
        try { $job->handle(); self::fail('Expected database failure'); }
        catch (\Illuminate\Database\QueryException $e) { self::assertStringContainsString('child failure', $e->getMessage()); }
        foreach (['v2_traffic_report', 'v2_stat_user', 'v2_stat_server'] as $table) self::assertSame(0, DB::table($table)->count());
        self::assertSame(0, (int) DB::table('v2_user')->sum('u'));
        self::assertSame(0, (int) DB::table('v2_server')->sum('u'));
        DB::unprepared('DROP TRIGGER fail_child');
        Redis::swap(Mockery::mock()->shouldReceive('sadd')->times(3)->andReturn(1)->getMock());
        unserialize(serialize($job))->handle();
        self::assertSame(16, DB::table('v2_user')->where('id', 1)->value('u'));
    }

    public function test_forged_cross_parent_and_missing_child_are_all_or_nothing(): void
    {
        $calls = 0;
        HookManager::registerFilter('traffic.process.before', function ($payload) use (&$calls) { $calls++; return $payload; });
        foreach ([$this->auth(999), $this->auth(3), ChildNodeTrafficService::AUTH_ID_OFFSET, PHP_INT_MAX] as $uid) {
            try { $this->job([1 => [1, 1], $uid => [2, 2]]); self::fail('Expected invalid identity'); }
            catch (\InvalidArgumentException) { self::assertCount(0, $this->jobs); }
        }
        foreach ([['id' => 999]] as $change) {
            DB::table('v2_server')->where('id', 2)->update($change);
            try { $this->job([1 => [1, 1], $this->auth() => [2, 2]]); self::fail('Expected invalid child'); }
            catch (\InvalidArgumentException) { self::assertCount(0, $this->jobs); }
        }
        self::assertSame(0, $calls);
        self::assertSame(0, DB::table('v2_traffic_report')->count());
        self::assertSame(0, (int) DB::table('v2_user')->sum('u'));
    }

    public function test_historical_tail_after_protocol_change_still_bills_mixed_batch(): void
    {
        DB::table('v2_server')->where('id', 2)->update(['type' => 'trojan', 'child_rate_enabled' => false]);
        $this->job([1 => [10, 20], $this->auth() => [10, 20]], 'historical-protocol')->handle();
        self::assertSame(70, DB::table('v2_user')->where('id', 1)->value('u'));
        self::assertSame(140, DB::table('v2_user')->where('id', 1)->value('d'));
        self::assertSame(2, DB::table('v2_traffic_report')->count());
    }

    public function test_child_reports_require_nonempty_bounded_report_id(): void
    {
        foreach ([null, '', ' ', str_repeat('x', 129)] as $id) {
            try { $this->children->processTraffic(Server::findOrFail(1), [$this->auth() => [1, 1]], $id, $this->now); self::fail('Expected report id validation'); }
            catch (\InvalidArgumentException) { self::assertCount(0, $this->jobs); }
        }
    }

    public function test_historical_tail_and_receive_time_rate_hooks_are_frozen_for_queue_retry(): void
    {
        $at = Carbon::parse('2026-09-01 12:30', 'Asia/Shanghai')->timestamp;
        DB::table('v2_server')->where('id', 2)->update(['rate_time_enable' => true,
            'rate_time_ranges' => json_encode([['start' => '12:00', 'end' => '13:00', 'rate' => 0.5]])]);
        $calls = [];
        foreach (['traffic.process.before', 'traffic.before_process'] as $hook) {
            HookManager::registerFilter($hook, function ($payload) use (&$calls, $hook) {
                $calls[] = $hook;
                self::assertSame($hook === 'traffic.process.before' ? 2 : 777, $payload[0]['id']);
                if ($hook === 'traffic.process.before') { $payload[0]['id'] = 777; $payload[2][1][0] *= 2; }
                return $payload;
            });
        }
        $job = $this->job([$this->auth() => [3, 5]], 'tail', $at);
        DB::table('v2_server')->where('id', 2)->update(['rate' => 99, 'rate_time_ranges' => '[]']);
        Carbon::setTestNow(Carbon::parse('2026-09-02 23:00', 'Asia/Shanghai'));
        $job->handle();
        unserialize(serialize($job))->handle();
        self::assertSame(['traffic.process.before', 'traffic.before_process'], $calls);
        self::assertSame(3, DB::table('v2_user')->where('id', 1)->value('u'));
        $snapshot = json_decode(DB::table('v2_traffic_report')->where('node_id', 2)->value('billing_snapshot'), true);
        self::assertSame(0.5, $snapshot['rate']);
        self::assertSame(6, $snapshot['users'][1]['final_u']);
        self::assertSame(6, DB::table('v2_server')->where('id', 2)->value('u'));
        self::assertFalse((bool) DB::table('v2_server')->where('id', 1)->value('child_rate_enabled'));
    }

    public function test_notifications_wait_for_outer_commit_and_redis_failure_retries_without_charging(): void
    {
        $job = $this->job([1 => [1, 1], $this->auth() => [2, 2]]);
        $notifications = 0;
        $redis = Mockery::mock();
        $redis->shouldReceive('sadd')->andReturnUsing(function () use (&$notifications): int {
            self::assertSame(0, DB::connection()->transactionLevel());
            self::assertSame(2, DB::table('v2_traffic_report')->count());
            $notifications++;
            return 1;
        });
        Redis::swap($redis);
        DB::transaction(function () use ($job, &$notifications): void { $job->handle(); self::assertSame(0, $notifications); });
        self::assertSame(2, $notifications);
        Redis::swap(Mockery::mock()->shouldReceive('sadd')->once()->andThrow(new \RuntimeException('redis down'))->getMock());
        try { $job->handle(); self::fail('Expected redis failure'); }
        catch (\RuntimeException $e) { self::assertSame('redis down', $e->getMessage()); }
        Redis::swap($redis);
        unserialize(serialize($job))->handle();
        self::assertSame(10, DB::table('v2_user')->where('id', 1)->value('u'));
        self::assertSame(2, DB::table('v2_traffic_report')->count());
    }

    public function test_access_controller_partitions_and_matches_child_bills_in_both_arrival_orders(): void
    {
        $traffic = [1 => [2, 3], $this->auth() => [4, 5], $this->auth(2) => [6, 7]];
        foreach (['access-first', 'billing-first'] as $order) {
            $job = $this->job($traffic, $order);
            if ($order === 'billing-first') $job->handle();
            $request = Request::create('/api/v2/server/access/report', 'POST', $this->payload($traffic, $order) + ['node_id' => 4]);
            $request->attributes->set('node_info', Server::findOrFail(1));
            (new AccessTrafficController())->report($request, $this->access);
            if ($order === 'access-first') $job->handle();
            $reports = DB::table('v2_access_report')->orderBy('id')->get();
            foreach ($reports as $report) $this->access->process((int) $report->id);
        }
        self::assertSame(6, DB::table('v2_access_report')->whereNotNull('settled_at')->count());
        self::assertSame(0, DB::table('v2_access_report')->whereNotNull('settlement_error')->count());
        self::assertSame([16, 24, 24], DB::table('v2_access_traffic')->select('node_id')->selectRaw('SUM(billed_u) AS total')->groupBy('node_id')->orderBy('node_id')->pluck('total')->all());
        self::assertSame([1], DB::table('v2_access_traffic')->distinct()->pluck('user_id')->all());
        self::assertSame(64, DB::table('v2_user')->where('id', 1)->value('u'));
        $key = AccessTrafficService::reportKey(ChildNodeTrafficService::billingReportId(1, 'access-first', 2));
        self::assertSame($key, DB::table('v2_access_report')->where('node_id', 2)->orderBy('id')->value('billing_report_id'));
    }

    public function test_access_root_rejects_new_child_conflict_and_foreign_identity_without_receipts(): void
    {
        $source = Server::findOrFail(1);
        $traffic = [$this->auth() => [3, 5]];
        $ids = $this->access->receiveFromSource($source, $this->payload($traffic), $this->now);
        self::assertSame($ids, $this->access->receiveFromSource($source, $this->payload($traffic), $this->now));
        try { $this->access->receiveFromSource($source, $this->payload($traffic + [$this->auth(2) => [1, 1]]), $this->now); self::fail('Expected access conflict'); }
        catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException) { self::assertSame(2, DB::table('v2_access_report')->count()); }
        try { $this->access->receiveFromSource($source, $this->payload([$this->auth(3) => [1, 1]], 'forged'), $this->now); self::fail('Expected foreign identity'); }
        catch (\Illuminate\Validation\ValidationException) { self::assertSame(2, DB::table('v2_access_report')->count()); }
    }

    public function test_child_only_access_uses_root_wire_hash_and_billing_snapshot_after_hooks(): void
    {
        HookManager::registerFilter('traffic.process.before', function ($payload) {
            $payload[2][1][0] *= 2;
            return $payload;
        });
        $traffic = [$this->auth() => [3, 5]];
        $ids = $this->access->receiveFromSource(Server::findOrFail(1), $this->payload($traffic), $this->now);
        $this->job($traffic)->handle();
        foreach ($ids as $id) $this->access->process($id);
        self::assertSame(2, DB::table('v2_access_report')->whereNotNull('settled_at')->count());
        self::assertSame(0, DB::table('v2_access_report')->whereNotNull('settlement_error')->count());
        self::assertSame(0, DB::table('v2_server')->where('id', 1)->value('u'));
        self::assertSame(1, DB::table('v2_access_traffic')->count());
        $entry = DB::table('v2_access_traffic')->first();
        self::assertSame(2, $entry->node_id);
        self::assertSame(1, $entry->user_id);
        self::assertSame(3, $entry->raw_u);
        self::assertSame(18, $entry->billed_u);
        self::assertSame(18, DB::table('v2_user')->where('id', 1)->value('u'));
    }

    public function test_outer_rollback_discards_billing_notifications_and_access_dispatch(): void
    {
        $job = $this->job([1 => [1, 1], $this->auth() => [2, 2]]);
        $this->jobs = [];
        Redis::swap(Mockery::mock()->shouldNotReceive('sadd')->getMock());
        try {
            DB::transaction(function () use ($job): void {
                $job->handle();
                $this->access->receiveFromSource(Server::findOrFail(1), $this->payload([$this->auth() => [2, 2]]), $this->now);
                self::assertCount(0, $this->jobs);
                throw new \RuntimeException('outer rollback');
            });
        } catch (\RuntimeException $e) { self::assertSame('outer rollback', $e->getMessage()); }
        self::assertCount(0, $this->jobs);
        self::assertSame(0, DB::table('v2_access_report')->count());
        self::assertSame(0, DB::table('v2_traffic_report')->count());
        self::assertSame(0, (int) DB::table('v2_user')->sum('u'));
    }

    public function test_access_dispatch_waits_for_outer_commit_and_sees_all_receipts(): void
    {
        $this->jobs = [];
        $bus = Mockery::mock(Dispatcher::class);
        $bus->shouldReceive('dispatch')->times(3)->andReturnUsing(function ($job): void {
            self::assertSame(0, DB::connection()->transactionLevel());
            self::assertSame(3, DB::table('v2_access_report')->count());
            $this->jobs[] = $job;
        });
        $this->app->instance(Dispatcher::class, $bus);
        DB::transaction(function (): void {
            $this->access->receiveFromSource(Server::findOrFail(1), $this->payload([1 => [1, 1], $this->auth() => [2, 2], $this->auth(2) => [3, 3]]), $this->now);
            self::assertCount(0, $this->jobs);
        });
        self::assertCount(3, $this->jobs);
    }

    public function test_access_second_child_receive_failure_rolls_back_receipts_and_never_enqueues(): void
    {
        $this->jobs = [];
        DB::unprepared("CREATE TRIGGER fail_access BEFORE INSERT ON v2_access_report WHEN NEW.node_id = 3 BEGIN SELECT RAISE(ABORT, 'access failure'); END");
        try {
            $this->access->receiveFromSource(Server::findOrFail(1), $this->payload([1 => [1, 1], $this->auth() => [2, 2], $this->auth(2) => [3, 3]]), $this->now);
            self::fail('Expected receipt failure');
        } catch (\Illuminate\Database\QueryException $e) { self::assertStringContainsString('access failure', $e->getMessage()); }
        self::assertSame(0, DB::table('v2_access_report')->count());
        self::assertCount(0, $this->jobs);
    }
}
