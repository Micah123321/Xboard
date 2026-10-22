<?php

namespace Tests\Unit;

use App\Http\Controllers\V2\Admin\AccessTrafficController;
use App\Jobs\TrafficBillingJob;
use App\Services\AccessTrafficClassifier;
use App\Services\AccessTrafficQueryService;
use App\Services\AccessTrafficService;
use App\Services\Plugin\HookManager;
use App\Support\Setting;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\DatabasePresenceVerifier;
use Illuminate\Validation\Factory as ValidationFactory;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory as ViewFactory;
use Illuminate\View\FileViewFinder;
use Mockery;
use PHPUnit\Framework\TestCase;

class AccessTrafficTest extends TestCase
{
    private Manager $db;
    private Container $app;
    private AccessTrafficService $service;
    private int $now;
    private array $settings = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = new Container();
        Container::setInstance($this->app);
        $this->app->instance('app', $this->app);
        $this->app->instance('config', new Repository([
            'app' => ['timezone' => 'Asia/Shanghai', 'key' => 'access-test-key'],
            'access_traffic' => require __DIR__ . '/../../config/access_traffic.php',
        ]));
        $this->db = new Manager($this->app);
        $this->db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]);
        $this->db->setAsGlobal();
        $this->db->bootEloquent();
        $this->app->instance('db', $this->db->getDatabaseManager());
        $this->app->instance('db.schema', $this->db->schema());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        Log::swap(new \Psr\Log\NullLogger());
        Redis::swap(Mockery::mock()->shouldReceive('sadd')->andReturn(1)->getMock());
        $validator = new ValidationFactory(new Translator(new ArrayLoader(), 'en'), $this->app);
        $validator->setPresenceVerifier(new DatabasePresenceVerifier($this->db->getDatabaseManager()));
        $this->app->instance('validator', $validator);
        $setting = Mockery::mock(Setting::class);
        $setting->shouldReceive('get')->andReturnUsing(fn ($key) => $this->settings[$key] ?? null);
        $setting->shouldReceive('save')->andReturnUsing(function ($values): bool {
            $this->settings = array_replace($this->settings, $values);
            return true;
        });
        $this->app->instance(Setting::class, $setting);
        $bus = Mockery::mock(\Illuminate\Contracts\Bus\Dispatcher::class);
        $bus->shouldReceive('dispatch')->andReturn(null);
        $this->app->instance(\Illuminate\Contracts\Bus\Dispatcher::class, $bus);
        foreach (['v2_user', 'v2_server'] as $name) {
            $this->db->schema()->create($name, function (Blueprint $table): void {
                $table->integer('id')->primary();
                $table->string('name')->nullable();
                $table->string('email')->nullable();
                $table->bigInteger('u')->default(0);
                $table->bigInteger('d')->default(0);
                $table->bigInteger('t')->nullable();
                $table->string('updated_at')->nullable();
            });
        }
        (require __DIR__ . '/../../database/migrations/2026_08_28_000001_create_traffic_reports_table.php')->up();
        (require __DIR__ . '/../../database/migrations/2026_09_01_000001_create_access_traffic_tables.php')->up();
        foreach (['v2_stat_user', 'v2_stat_server'] as $name) {
            $this->db->schema()->create($name, function (Blueprint $table) use ($name): void {
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
        DB::table('v2_server')->insert([['id' => 1, 'name' => 'Node one'], ['id' => 2, 'name' => 'Node two']]);
        $this->service = new AccessTrafficService();
        $this->app->instance(AccessTrafficService::class, $this->service);
        $this->now = time();
    }

    protected function tearDown(): void
    {
        HookManager::reset();
        Mockery::close();
        $this->db->getDatabaseManager()->disconnect();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    private function entry(string $domain = 'r1.googlevideo.com', int $u = 1, int $d = 2, int $uid = 1): array
    {
        return ['user_id' => $uid, 'domain' => $domain, 'ip' => '', 'port' => 443, 'network' => 'tcp',
            'domain_source' => 'request', 'bucket_at' => intdiv($this->now, 300) * 300,
            'first_seen' => $this->now, 'last_seen' => $this->now, 'u' => $u, 'd' => $d, 'connections' => 1];
    }

    private function payload(string $id = 'batch', array $entries = [], ?array $totals = null): array
    {
        return ['version' => 1, 'report_id' => 'access-' . $id, 'billing_report_id' => $id,
            'kernel' => 'sing-box', 'observed_at' => $this->now, 'coverage' => 'complete', 'dropped_entries' => 0,
            'totals' => $totals ?? [1 => [3, 5]], 'entries' => $entries ?: [$this->entry()]];
    }

    private function bill(string $id = 'batch', array $data = [1 => [3, 5]], float $rate = 1.5, int $node = 1, ?string $rawHash = null): void
    {
        (new TrafficBillingJob(['id' => $node, 'type' => 'vmess', 'rate' => $rate], $data, 'vmess', $this->now, $id, $rawHash))->handle();
    }

    private function queryResult(array $filters = []): array
    {
        $query = new AccessTrafficQueryService();
        return $query->index($query->filters(['start_time' => $this->now - 1000, 'end_time' => $this->now + 1] + $filters));
    }

    public function test_durable_receipt_both_arrival_orders_and_duplicate_do_not_charge(): void
    {
        $id = $this->service->receive(1, $this->payload(), $this->now);
        self::assertSame(0, (int) DB::table('v2_user')->sum('u'));
        self::assertSame(0, DB::table('v2_access_traffic')->count());
        $this->service->process($id);
        self::assertNull(DB::table('v2_access_traffic')->value('billed_u'));
        $this->bill();
        $this->service->repair();
        self::assertSame(4, (int) DB::table('v2_access_traffic')->sum('billed_u'));
        self::assertSame(7, (int) DB::table('v2_access_traffic')->sum('billed_d'));
        self::assertSame($id, $this->service->receive(1, $this->payload(), $this->now));
        $reordered = array_reverse($this->payload(), true);
        $reordered['entries'] = [array_reverse($reordered['entries'][0], true)];
        $reordered['token'] = 'synthetic-auth-field';
        self::assertSame($id, $this->service->receive(1, $reordered, $this->now));
        $this->service->process($id);
        $this->bill();
        self::assertSame(4, DB::table('v2_user')->where('id', 1)->value('u'));
        self::assertSame(2, DB::table('v2_access_traffic')->count());
        $this->bill('second', [1 => [3, 5]], 0.5);
        $id = $this->service->receive(1, $this->payload('second'), $this->now);
        $this->service->process($id);
        self::assertSame(1, (int) DB::table('v2_access_traffic')->where('report_id', $id)->sum('billed_u'));
        self::assertSame(2, (int) DB::table('v2_access_traffic')->where('report_id', $id)->sum('billed_d'));
    }

    public function test_payload_conflicts_and_second_detail_for_billing_report_are_errors(): void
    {
        $this->service->receive(1, $this->payload(), $this->now);
        foreach ([array_replace($this->payload(), ['kernel' => 'xray']), array_replace($this->payload(), ['report_id' => 'other'])] as $payload) {
            try {
                $this->service->receive(1, $payload, $this->now);
                self::fail('Expected receipt conflict');
            } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException) {
                self::assertSame(1, DB::table('v2_access_report')->count());
            }
        }
        $this->service->receive(2, $this->payload(), $this->now);
        self::assertSame(2, DB::table('v2_access_report')->count());
    }

    public function test_exact_largest_remainder_per_direction_including_large_integers(): void
    {
        self::assertSame([2, 1, 1], AccessTrafficService::allocate([1, 1, 1], 4));
        self::assertSame([0, 0], AccessTrafficService::allocate([1, 1], 0));
        self::assertSame([7, 0], AccessTrafficService::allocate([0, 0], 7));
        $large = AccessTrafficService::allocate([PHP_INT_MAX - 2, 1, 1], PHP_INT_MAX - 1);
        self::assertSame(PHP_INT_MAX - 1, array_sum($large));
        self::assertSame([PHP_INT_MAX - 3, 1, 1], $large);
        $entries = [$this->entry('a.googlevideo.com', 1, 0), $this->entry('b.googlevideo.com', 1, 1), $this->entry('c.googlevideo.com', 1, 4)];
        $id = $this->service->receive(1, $this->payload('round', $entries), $this->now);
        $this->bill('round');
        $this->service->process($id);
        self::assertSame([2, 1, 1], DB::table('v2_access_traffic')->orderBy('id')->pluck('billed_u')->all());
        self::assertSame([0, 1, 6], DB::table('v2_access_traffic')->orderBy('id')->pluck('billed_d')->all());
    }

    public function test_final_hooks_change_bytes_and_rate_but_original_hash_still_links(): void
    {
        $node = new \App\Models\Server();
        $node->setRawAttributes(['id' => 1, 'type' => 'vmess', 'rate' => 9, 'rate_time_enable' => true,
            'rate_time_ranges' => json_encode([['start' => '00:00', 'end' => '23:59', 'rate' => 0.5]])]);
        HookManager::registerFilter('traffic.process.before', function ($payload) {
            $payload[2] = [1 => [7, 9], 2 => [2, 4]];
            return $payload;
        });
        HookManager::registerFilter('traffic.before_process', function ($payload) {
            $payload[0]['rate'] = 1.25;
            $payload[0]['id'] = 99;
            return $payload;
        });
        $bus = Mockery::mock(\Illuminate\Contracts\Bus\Dispatcher::class);
        $bus->shouldReceive('dispatch')->once()->andReturnUsing(function ($job): void { $job->handle(); });
        $this->app->instance(\Illuminate\Contracts\Bus\Dispatcher::class, $bus);
        (new \App\Services\UserService())->trafficFetch($node, 'vmess', [1 => [3, 5]], 'hooks', $this->now);
        $id = $this->service->receive(1, $this->payload('hooks'), $this->now);
        $this->service->process($id);
        $snapshot = json_decode(DB::table('v2_traffic_report')->value('billing_snapshot'), true);
        self::assertSame(7, $snapshot['users'][1]['final_u']);
        self::assertSame(8, (int) DB::table('v2_access_traffic')->where('user_id', 1)->sum('billed_u'));
        self::assertSame(11, (int) DB::table('v2_access_traffic')->where('user_id', 1)->sum('billed_d'));
        self::assertSame(2, (int) DB::table('v2_access_traffic')->where('user_id', 2)->sum('billed_u'));
        self::assertSame(5, (int) DB::table('v2_access_traffic')->where('user_id', 2)->sum('billed_d'));
        self::assertSame(0, (int) DB::table('v2_access_traffic')->where('user_id', 2)->sum('raw_u'));
        self::assertSame(1.25, DB::table('v2_access_traffic')->value('rate'));
    }

    public function test_hook_created_direction_is_unattributed_and_removed_or_deleted_users_bill_zero(): void
    {
        $payload = $this->payload('direction', [$this->entry('r.googlevideo.com', 0, 5)], [1 => [0, 5]]);
        $id = $this->service->receive(1, $payload, $this->now);
        $this->bill('direction', [1 => [4, 5]], 1, 1, TrafficBillingJob::fingerprint($payload['totals']));
        $this->service->process($id);
        self::assertSame(0, DB::table('v2_access_traffic')->where('domain', 'r.googlevideo.com')->value('billed_u'));
        self::assertSame(4, DB::table('v2_access_traffic')->where('domain_source', 'unknown')->value('billed_u'));
        $payload = $this->payload('removed', [$this->entry('r.googlevideo.com', 3, 5)], [1 => [3, 5]]);
        $id = $this->service->receive(1, $payload, $this->now);
        $this->bill('removed', [], 2, 1, TrafficBillingJob::fingerprint($payload['totals']));
        $this->service->process($id);
        self::assertSame(0, DB::table('v2_access_traffic')->where('report_id', $id)->value('billed_u'));
        $payload = $this->payload('deleted', [$this->entry('r.googlevideo.com', 3, 5, 2)], [2 => [3, 5]]);
        $id = $this->service->receive(1, $payload, $this->now);
        DB::table('v2_user')->where('id', 2)->delete();
        $this->bill('deleted', [2 => [3, 5]]);
        $this->service->process($id);
        self::assertSame(0, DB::table('v2_access_traffic')->where('report_id', $id)->value('billed_d'));
    }

    public function test_detail_processing_failure_rolls_back_then_retries_cleanly(): void
    {
        $id = $this->service->receive(1, $this->payload('retry'), $this->now);
        $this->bill('retry');
        DB::unprepared("CREATE TRIGGER fail_allocation BEFORE UPDATE ON v2_access_traffic BEGIN SELECT RAISE(ABORT, 'allocation failure'); END");
        try { $this->service->process($id); self::fail('Expected detail failure'); }
        catch (\Illuminate\Database\QueryException) {
            self::assertSame(0, DB::table('v2_access_traffic')->count());
            self::assertNotNull(DB::table('v2_access_report')->value('payload'));
            self::assertSame(4, DB::table('v2_user')->where('id', 1)->value('u'));
        }
        DB::unprepared('DROP TRIGGER fail_allocation');
        $this->service->repair();
        self::assertSame(2, DB::table('v2_access_traffic')->count());
        self::assertSame(4, (int) DB::table('v2_access_traffic')->sum('billed_u'));
        self::assertNull(DB::table('v2_access_report')->value('payload'));
    }

    public function test_timed_rate_is_retained_when_current_settings_change(): void
    {
        $node = new \App\Models\Server();
        $node->setRawAttributes(['id' => 1, 'type' => 'vmess', 'rate' => 9, 'rate_time_enable' => true,
            'rate_time_ranges' => json_encode([['start' => '00:00', 'end' => '23:59', 'rate' => 0.5]])]);
        $bus = Mockery::mock(\Illuminate\Contracts\Bus\Dispatcher::class);
        $bus->shouldReceive('dispatch')->once()->andReturnUsing(fn ($job) => $job->handle());
        $this->app->instance(\Illuminate\Contracts\Bus\Dispatcher::class, $bus);
        (new \App\Services\UserService())->trafficFetch($node, 'vmess', [1 => [3, 5]], 'timed', $this->now);
        $node->rate = 99;
        $node->rate_time_ranges = [];
        $id = $this->service->receive(1, $this->payload('timed'), $this->now);
        $this->service->process($id);
        self::assertSame(0.5, DB::table('v2_access_traffic')->value('rate'));
        self::assertSame(1, (int) DB::table('v2_access_traffic')->sum('billed_u'));
        self::assertSame(2, (int) DB::table('v2_access_traffic')->sum('billed_d'));
    }

    public function test_totals_mismatch_never_allocates_or_changes_billing(): void
    {
        $this->bill('bad', [1 => [30, 50]]);
        $id = $this->service->receive(1, $this->payload('bad'), $this->now);
        $this->service->process($id);
        self::assertSame('totals_mismatch', DB::table('v2_access_report')->value('settlement_error'));
        self::assertNull(DB::table('v2_access_traffic')->value('billed_u'));
        self::assertSame(45, DB::table('v2_user')->where('id', 1)->value('u'));
        self::assertSame(1, $this->queryResult()['meta']['collection_gaps']['failed_settlement_reports']);
        $early = $this->service->receive(1, $this->payload('bad-early'), $this->now);
        $this->service->process($early);
        $this->bill('bad-early', [1 => [30, 50]]);
        $this->service->repair();
        self::assertSame('totals_mismatch', DB::table('v2_access_report')->where('id', $early)->value('settlement_error'));
    }

    public function test_direction_budgets_validation_and_explicit_unknown_bytes(): void
    {
        foreach ([$this->payload('over', [$this->entry('a.example', 4, 0)]), $this->payload('user', [$this->entry('a.example', 1, 1, 2)]),
            $this->payload('source', [array_replace($this->entry(), ['domain_source' => 'unknown'])])] as $payload) {
            try { $this->service->receive(1, $payload, $this->now); self::fail('Expected invalid report'); }
            catch (\Illuminate\Validation\ValidationException) { self::assertSame(0, DB::table('v2_access_report')->count()); }
        }
        $id = $this->service->receive(1, $this->payload(), $this->now);
        $this->service->process($id);
        $unknown = DB::table('v2_access_traffic')->where('domain_source', 'unknown')->first();
        self::assertSame(2, $unknown->raw_u);
        self::assertSame(3, $unknown->raw_d);
        self::assertSame('', $unknown->domain);
        self::assertSame('', $unknown->ip);
        self::assertSame(0, $unknown->port);
        $summary = $this->queryResult()['summary'];
        self::assertSame(8, $summary['raw_total']);
        self::assertSame(3, $summary['identified_bytes']);
        self::assertSame(5, $summary['unattributed_bytes']);
        self::assertSame(1, $summary['unsettled_report_count']);
        self::assertSame(1, $summary['partial_report_count']);
    }

    public function test_suffix_boundary_and_generic_cdn_do_not_invent_https_contents(): void
    {
        $classifier = new AccessTrafficClassifier();
        foreach (['googlevideo.com', 'R1.GoogleVideo.Com.'] as $domain) self::assertSame('video', $classifier->classify($domain, 'sniff')['category']);
        foreach (['evilgooglevideo.com', 'googlevideo.com.evil.example'] as $domain) self::assertSame('unknown', $classifier->classify($domain, 'request')['category']);
        self::assertSame('cdn', $classifier->classify('movie.cloudfront.net', 'request')['category']);
        self::assertSame('unknown', $classifier->classify('', 'ip')['confidence']);
        self::assertStringContainsString('具体 HTTPS 内容及用户意图未知', $classifier->classify('googlevideo.com', 'request')['reason']);
    }

    public function test_full_filter_summary_time_grouping_half_open_window_and_mixed_rates(): void
    {
        foreach ([['a', 1, 1, 1.5], ['b', 1, 1, 0.5], ['c', 2, 1, 1.5], ['d', 1, 2, 1.5]] as [$name, $uid, $node, $rate]) {
            $payload = $this->payload($name, [$this->entry('same.googlevideo.com', 3, 5, $uid)], [$uid => [3, 5]]);
            $id = $this->service->receive($node, $payload, $this->now);
            $this->bill($name, [$uid => [3, 5]], $rate, $node);
            $this->service->process($id);
        }
        $all = $this->queryResult(['pageSize' => 1]);
        self::assertCount(1, $all['data']);
        self::assertSame(3, $all['total']);
        self::assertSame(32, $all['summary']['raw_total']);
        $filtered = $this->queryResult(['user_id' => 1, 'node_id' => 1, 'category' => 'video', 'network' => 'tcp', 'search' => 'same.']);
        self::assertSame(1, $filtered['total']);
        self::assertSame(16, $filtered['summary']['raw_total']);
        self::assertNull($filtered['data'][0]['rate']);
        self::assertSame(14, $filtered['data'][0]['billed_total']);
        $firstBucket = intdiv($this->now, 300) * 300;
        DB::table('v2_access_traffic')->where('report_id', 2)->update([
            'bucket_at' => $firstBucket - 300, 'first_seen' => $firstBucket - 300, 'last_seen' => $firstBucket - 1,
        ]);
        self::assertSame(4, $this->queryResult(['group_by' => 'time'])['total']);
        $query = new AccessTrafficQueryService();
        $result = $query->index($query->filters(['start_time' => $firstBucket - 300, 'end_time' => $firstBucket], $this->now));
        self::assertSame(8, $result['summary']['raw_total']);
        self::assertSame(0, $this->queryResult(['search' => '%'])['total']);
        $filters = $query->filters(['start_time' => $this->now - 99999999], $this->now);
        self::assertSame($this->now + 1 - 30 * 86400, $filters['start_time']);
    }

    public function test_paginated_window_and_legacy_queries_keep_exact_stats_and_email(): void
    {
        DB::table('v2_user')->where('id', 1)->update(['email' => 'customer@example.test']);
        foreach (['one', 'two'] as $name) {
            $entries = [$this->entry('same.googlevideo.com', 1, 2), $this->entry('example.test', 2, 3)];
            $id = $this->service->receive(1, $this->payload($name, $entries), $this->now);
            $this->service->process($id);
        }
        $query = new AccessTrafficQueryService();
        $filters = $query->filters(['start_time' => $this->now - 3600, 'end_time' => $this->now + 1, 'pageSize' => 1], $this->now);
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        $result = $query->index($filters);
        self::assertCount(4, DB::connection()->getQueryLog());
        DB::connection()->disableQueryLog();
        self::assertSame('customer@example.test', $result['data'][0]['user_email']);
        self::assertSame(2, $result['total']);
        self::assertSame(2, $result['summary']['report_count']);
        self::assertSame(16, $result['summary']['raw_total']);
        self::assertCount(2, $result['analysis']);
        self::assertSame([10, 6], array_column($result['analysis'], 'raw_total'));
        $legacy = new class extends AccessTrafficQueryService {
            protected function supportsWindowCount(): bool { return false; }
        };
        self::assertSame($result, $legacy->index($filters));
        $filters['page'] = 2;
        self::assertSame($query->index($filters), $legacy->index($filters));
        $filters['page'] = 999;
        $empty = $query->index($filters);
        self::assertSame(2, $empty['total']);
        self::assertSame([], $empty['data']);
        self::assertSame($empty, $legacy->index($filters));
        self::assertSame(1, $query->countGroups($filters, 1));
    }

    public function test_category_analysis_respects_case_insensitive_database_comparisons(): void
    {
        // Exercise historical values under a CI collation without requiring a MySQL server.
        $schema = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'v2_access_traffic'")->sql;
        $schema = preg_replace('/("category"\s+varchar(?:\(\d+\))?)/i', '$1 COLLATE NOCASE', $schema, 1, $changed);
        self::assertSame(1, $changed);
        DB::statement('DROP TABLE v2_access_traffic');
        DB::statement($schema);
        $categories = ['VIDEO', 'video', 'Unknown', 'unknown', 'future-category'];
        $entries = array_map(fn ($i) => $this->entry('case-' . $i . '.test', 1, 2), array_keys($categories));
        $id = $this->service->receive(1, $this->payload('collation', $entries, [1 => [5, 10]]), $this->now);
        $this->service->process($id);
        foreach ($categories as $i => $category) {
            DB::table('v2_access_traffic')->where('report_id', $id)->where('domain', 'case-' . $i . '.test')->update(['category' => $category]);
        }
        $result = $this->queryResult();
        self::assertSame(15, $result['summary']['raw_total']);
        self::assertSame(9, $result['summary']['identified_bytes']);
        self::assertSame(6, $result['summary']['unidentified_bytes']);
        self::assertSame(1, $result['summary']['report_count']);
        self::assertSame(['unknown', 'video'], array_column($result['analysis'], 'category'));
        self::assertSame([6, 6], array_column($result['analysis'], 'raw_total'));
    }

    public function test_category_summary_counts_each_report_once_including_zero_byte_categories(): void
    {
        $entries = [$this->entry('video.googlevideo.com', 1, 2), $this->entry('example.test', 2, 3),
            $this->entry('chatgpt.com', 0, 0)];
        foreach (['settled', 'pending', 'failed'] as $state) {
            $payload = $this->payload($state, $entries);
            if ($state === 'pending') $payload['dropped_entries'] = 2;
            if ($state === 'failed') $payload['coverage'] = 'partial';
            $id = $this->service->receive(1, $payload, $this->now);
            if ($state === 'settled') $this->bill($state, [1 => [3, 5]], 2);
            if ($state === 'failed') $this->bill($state, [1 => [30, 50]], 2);
            $this->service->process($id);
        }
        $result = $this->queryResult(['pageSize' => 1]);
        self::assertSame(3, $result['summary']['report_count']);
        self::assertSame(2, $result['summary']['partial_report_count']);
        self::assertSame(2, $result['summary']['unsettled_report_count']);
        self::assertSame(1, $result['meta']['collection_gaps']['failed_settlement_reports']);
        self::assertSame('partial', $result['meta']['coverage']);
        self::assertSame([9, 15, 24, 6, 10, 16], array_map(fn ($key) => $result['summary'][$key],
            ['raw_u', 'raw_d', 'raw_total', 'billed_u', 'billed_d', 'billed_total']));
        self::assertSame(9, $result['summary']['identified_bytes']);
        self::assertSame(15, $result['summary']['unidentified_bytes']);
        self::assertSame(['unknown', 'video', 'ai'], array_column($result['analysis'], 'category'));
        self::assertSame([15, 9, 0], array_column($result['analysis'], 'raw_total'));
        $zero = $this->queryResult(['category' => 'ai']);
        self::assertSame(1, $zero['total']);
        self::assertSame(0, $zero['summary']['raw_total']);
        self::assertSame(0, $zero['summary']['billed_total']);
        self::assertSame(3, $zero['summary']['report_count']);
        self::assertSame(2, $zero['summary']['partial_report_count']);
        self::assertSame(2, $zero['summary']['unsettled_report_count']);
        self::assertSame(1, $zero['meta']['collection_gaps']['failed_settlement_reports']);
        self::assertSame(['ai'], array_column($zero['analysis'], 'category'));
        self::assertEquals(0, $zero['analysis'][0]['share']);
    }

    public function test_tied_pages_preserve_historical_group_keys_and_partial_billing(): void
    {
        $expected = [];
        foreach (['first', 'mixed-rate', 'partial-billing', 'renamed', 'reason', 'confidence'] as $index => $name) {
            DB::table('v2_server')->where('id', 1)->update(['name' => $name === 'renamed' ? 'Renamed node' : 'Node one']);
            $bytes = $index < 3 ? 1 : 3;
            $id = $this->service->receive(1, $this->payload($name,
                [$this->entry('same.googlevideo.com', $bytes, $bytes)], [1 => [$bytes, $bytes]]), $this->now);
            $this->bill($name, [1 => [$bytes, $bytes]], $name === 'mixed-rate' ? 2 : 1);
            $this->service->process($id);
            if ($name === 'partial-billing') {
                DB::table('v2_access_traffic')->where('report_id', $id)->update(['billed_u' => null]);
            }
            if ($name === 'reason') {
                DB::table('v2_access_traffic')->where('report_id', $id)->update(['reason' => 'Historical rule variant']);
            }
            if ($name === 'confidence') {
                DB::table('v2_access_traffic')->where('report_id', $id)->update(['confidence' => 'low']);
            }
            if ($index === 0 || $index >= 3) {
                $stored = DB::table('v2_access_traffic')->where('report_id', $id)->first();
                $expected[] = ['id' => (string) $stored->id, 'node_name' => $name === 'renamed' ? 'Renamed node' : 'Node one',
                    'domain' => 'same.googlevideo.com', 'reason' => $stored->reason, 'confidence' => $stored->confidence,
                    'raw_total' => 6, 'connections' => $index === 0 ? 3 : 1];
            }
        }
        foreach ($expected as $index => $details) {
            $result = $this->queryResult(['pageSize' => 1, 'page' => $index + 1]);
            self::assertSame(4, $result['total']);
            self::assertCount(1, $result['data']);
            foreach ($details as $key => $value) self::assertSame($value, $result['data'][0][$key], $key);
            if ($index === 0) {
                self::assertNull($result['data'][0]['billed_u']);
                self::assertSame(4, $result['data'][0]['billed_d']);
                self::assertNull($result['data'][0]['billed_total']);
                self::assertNull($result['data'][0]['rate']);
                self::assertSame(24, $result['summary']['raw_total']);
                self::assertSame([12, 13, 24], array_map(fn ($key) => $result['summary'][$key], ['billed_u', 'billed_d', 'billed_total']));
            }
        }
        $beyond = $this->queryResult(['pageSize' => 1, 'page' => 5]);
        self::assertSame(4, $beyond['total']);
        self::assertSame([], $beyond['data']);
        self::assertSame(6, $beyond['summary']['report_count']);
        self::assertSame(24, $beyond['summary']['raw_total']);
    }

    public function test_literal_historical_name_search_keeps_whole_overlapping_fragments(): void
    {
        $this->now = intdiv(time(), 300) * 300 - 450;
        $entries = [
            array_replace($this->entry('same.googlevideo.com', 1, 2), ['first_seen' => $this->now - 10, 'last_seen' => $this->now + 1]),
            array_replace($this->entry('same.googlevideo.com', 2, 3), ['first_seen' => $this->now + 4, 'last_seen' => $this->now + 10]),
            array_replace($this->entry('same.googlevideo.com', 4, 5), ['first_seen' => $this->now - 10, 'last_seen' => $this->now]),
            array_replace($this->entry('same.googlevideo.com', 6, 7), ['first_seen' => $this->now + 5, 'last_seen' => $this->now + 10]),
        ];
        foreach (['Archive !_% node', 'Archive ab node', 'Current node'] as $index => $name) {
            DB::table('v2_server')->where('id', 1)->update(['name' => $name]);
            $id = $this->service->receive(1, $this->payload('search-' . $index, $entries, [1 => [13, 17]]), $this->now + 10);
            $this->service->process($id);
        }
        $query = new AccessTrafficQueryService();
        $filters = $query->filters(['start_time' => $this->now + 1, 'end_time' => $this->now + 5], $this->now + 20);
        foreach (['domain', 'time'] as $groupBy) {
            foreach (['Archive !_% node', '!_%', '!', '_', '%'] as $search) {
                $result = $query->index(array_replace($filters, ['group_by' => $groupBy, 'search' => $search]));
                self::assertSame(1, $result['total'], $search);
                self::assertSame(1, $result['summary']['report_count']);
                self::assertSame(8, $result['summary']['raw_total']);
                self::assertSame('Archive !_% node', $result['data'][0]['node_name']);
                self::assertSame(8, $result['data'][0]['raw_total']);
                self::assertSame(2, $result['data'][0]['connections']);
                self::assertSame($this->now - 10, $result['data'][0]['first_seen']);
                self::assertSame($this->now + 10, $result['data'][0]['last_seen']);
            }
        }
        $empty = $query->index($filters + ['search' => 'Absent node']);
        self::assertSame(0, $empty['total']);
        self::assertSame(0, $empty['summary']['report_count']);
        self::assertSame([], $empty['analysis']);
        self::assertSame('none', $empty['meta']['coverage']);
    }

    public function test_placeholder_addresses_keep_wire_idempotency_but_not_fake_destinations(): void
    {
        foreach (['::', '0.0.0.0', '::ffff:0.0.0.0'] as $index => $ip) {
            $entry = array_replace($this->entry('', 3, 5), ['ip' => $ip, 'domain_source' => 'ip', 'port' => 30525]);
            $payload = $this->payload('placeholder-' . $index, [$entry]);
            $normalized = $this->service->normalize($payload);
            self::assertSame($ip, $normalized['entries'][0]['ip']);
            $id = $this->service->receive(1, $payload, $this->now);
            $this->service->process($id);
            self::assertSame($id, $this->service->receive(1, $payload, $this->now));
        }
        $result = $this->queryResult();
        self::assertSame(24, $result['summary']['raw_total']);
        self::assertSame(24, $result['summary']['unattributed_bytes']);
        self::assertSame('', $result['data'][0]['ip']);
        self::assertSame(0, $result['data'][0]['port']);
        self::assertSame('unknown', $result['data'][0]['domain_source']);
        self::assertSame('partial', $result['meta']['coverage']);
        DB::table('v2_access_traffic')->update(['ip' => '::', 'port' => 30525, 'domain_source' => 'ip']);
        $historic = $this->queryResult();
        self::assertSame('', $historic['data'][0]['ip']);
        self::assertStringContainsString('未指定地址', $historic['data'][0]['ip_unavailable_reason']);
        self::assertSame(24, $historic['summary']['unattributed_bytes']);
    }

    public function test_query_index_migration_is_reversible_on_existing_tables(): void
    {
        $migration = require __DIR__ . '/../../database/migrations/2026_10_18_000001_add_access_traffic_query_indexes.php';
        $this->db->schema()->table('v2_access_report', function (Blueprint $table): void {
            $table->index(['processed_at', 'observed_at', 'node_id'], 'access_pending_capture_node');
        });
        $migration->up();
        $migration->up();
        self::assertTrue($this->db->schema()->hasIndex('v2_access_report', 'access_pending_capture_node'));
        self::assertTrue($this->db->schema()->hasIndex('v2_traffic_report', 'traffic_received_node_report'));
        $migration->down();
        $migration->down();
        self::assertFalse($this->db->schema()->hasIndex('v2_access_report', 'access_pending_capture_node'));
        self::assertFalse($this->db->schema()->hasIndex('v2_traffic_report', 'traffic_received_node_report'));
    }

    public function test_historic_reclassification_updates_filters_without_touching_billing(): void
    {
        $entry = $this->entry('ykj-eos-dg5-01.eos-dongguan-6.cmecloud.cn', 3, 5);
        $id = $this->service->receive(1, $this->payload('historic-cloud', [$entry]), $this->now);
        $this->bill('historic-cloud'); $this->service->process($id);
        DB::table('v2_access_traffic')->update(['service' => '', 'category' => 'unknown', 'confidence' => 'unknown', 'reason' => 'old rules']);
        $before = (array) DB::table('v2_access_traffic')->first();
        $userBefore = (array) DB::table('v2_user')->where('id', 1)->first();
        $service = new \App\Services\AccessTrafficReclassifier();
        $result = $service->chunk(0, $before['id'], 1);
        self::assertSame(1, $result['updated']);
        $after = (array) DB::table('v2_access_traffic')->first();
        self::assertSame('storage', $after['category']);
        self::assertStringContainsString('移动云', $after['service']);
        foreach (['raw_u', 'raw_d', 'billed_u', 'billed_d', 'rate', 'report_id', 'user_id'] as $key) self::assertSame($before[$key], $after[$key]);
        self::assertSame($userBefore, (array) DB::table('v2_user')->where('id', 1)->first());
        self::assertSame(1, $this->queryResult(['category' => 'storage'])['total']);
        self::assertSame(0, $service->chunk(0, $before['id'], 1)['updated']);
    }

    public function test_ai_and_ip_services_refresh_historic_filters_without_changing_billing(): void
    {
        $cases = [
            ['ai-upload', 'chatgpt.com', '', 'request', 6 * 1048576, 500000, 'ai'],
            ['quark-download', 'dl-c-zb-u.drive.quark.cn', '', 'request', 30000, 3 * 1048576, 'storage'],
            ['telegram', '', '91.108.56.137', 'ip', 30000, 3 * 1048576, 'messaging'],
            ['unproven', 'ojfn.hzrmzz.com', '', 'request', 30000, 3 * 1048576, 'unknown'],
            ['missing-target', '', '', 'unknown', 30000, 3 * 1048576, 'unknown'],
        ];
        foreach ($cases as [$name, $domain, $ip, $source, $u, $d, $category]) {
            $entry = array_replace($this->entry($domain, $u, $d), ['ip' => $ip, 'domain_source' => $source, 'port' => $source === 'unknown' ? 0 : 443]);
            $id = $this->service->receive(1, $this->payload($name, [$entry], [1 => [$u, $d]]), $this->now);
            $this->bill($name, [1 => [$u, $d]], 2); $this->service->process($id);
        }
        $result = $this->queryResult();
        self::assertSame(5, $result['total']);
        self::assertSame(4, count($result['analysis']));
        $byDomain = collect($result['data'])->keyBy(fn ($row) => $row['domain'] ?: $row['ip']);
        self::assertSame('ai', $byDomain['chatgpt.com']['category']);
        self::assertSame('疑似上传 / 同步', $byDomain['chatgpt.com']['activity_hint']);
        self::assertSame('storage', $byDomain['dl-c-zb-u.drive.quark.cn']['category']);
        self::assertSame('messaging', $byDomain['91.108.56.137']['category']);
        self::assertSame('unknown', $byDomain['ojfn.hzrmzz.com']['category']);
        self::assertSame('疑似下载 / 内容接收', $byDomain['ojfn.hzrmzz.com']['activity_hint']);
        self::assertSame('low', $byDomain['ojfn.hzrmzz.com']['activity_confidence']);
        self::assertSame('unknown', $byDomain['']['category']);
        self::assertStringContainsString('真实目标未采集', $byDomain['']['activity_reason']);
        $before = DB::table('v2_access_traffic')->orderBy('id')->get(['raw_u', 'raw_d', 'billed_u', 'billed_d', 'rate'])->toArray();
        $accountBefore = (array) DB::table('v2_user')->where('id', 1)->first();
        DB::table('v2_access_traffic')->update(['service' => '', 'category' => 'unknown', 'confidence' => 'unknown', 'reason' => 'old rules']);
        $reclassifier = new \App\Services\AccessTrafficReclassifier();
        $last = (int) DB::table('v2_access_traffic')->max('id');
        $reclassifier->chunk(0, $last);
        self::assertSame(1, $this->queryResult(['category' => 'ai'])['total']);
        self::assertSame(1, $this->queryResult(['category' => 'messaging'])['total']);
        self::assertEquals($before, DB::table('v2_access_traffic')->orderBy('id')->get(['raw_u', 'raw_d', 'billed_u', 'billed_d', 'rate'])->toArray());
        self::assertSame($accountBefore, (array) DB::table('v2_user')->where('id', 1)->first());
    }

    public function test_crypto_and_other_service_categories_refresh_historic_filters_without_extra_queries_or_charging(): void
    {
        $samples = [
            ['static.okx.com', 'crypto'], ['static.oklink.com', 'crypto'],
            ['data.onekey.so', 'crypto'], ['utility.onekeycn.com', 'crypto'], ['uni.onekey-asset.com', 'crypto'],
            ['www.itdog.cn', 'tools'], ['api.onesignal.com', 'push'], ['toblog.tobsnssdk.com', 'telemetry'],
        ];
        foreach ($samples as $index => [$domain, $category]) {
            $name = 'service-' . $index;
            $id = $this->service->receive(1, $this->payload($name, [$this->entry($domain, 3, 5)]), $this->now);
            $this->bill($name); $this->service->process($id);
        }
        $query = new AccessTrafficQueryService();
        $filters = $query->filters(['start_time' => $this->now - 1000, 'end_time' => $this->now + 1, 'pageSize' => 1]);
        DB::connection()->enableQueryLog(); DB::connection()->flushQueryLog();
        $all = $query->index($filters);
        self::assertCount(4, DB::connection()->getQueryLog());
        DB::connection()->disableQueryLog();
        self::assertSame(8, $all['total']);
        self::assertSame(64, $all['summary']['raw_total']);
        self::assertSame(64, $all['summary']['identified_bytes']);
        self::assertSame(88, $all['summary']['billed_total']);
        self::assertSame(40, collect($all['analysis'])->firstWhere('category', 'crypto')['raw_total']);
        foreach (array_keys(AccessTrafficClassifier::LABELS) as $category) {
            self::assertSame($category, $query->filters(['category' => $category])['category']);
        }
        $before = DB::table('v2_access_traffic')->orderBy('id')->get(['raw_u', 'raw_d', 'billed_u', 'billed_d', 'rate'])->toArray();
        $account = (array) DB::table('v2_user')->where('id', 1)->first();
        DB::table('v2_access_traffic')->update(['service' => '', 'category' => 'unknown', 'confidence' => 'unknown', 'reason' => 'previous subset']);
        $reclassifier = new \App\Services\AccessTrafficReclassifier();
        $maximum = (int) DB::table('v2_access_traffic')->max('id');
        $last = 0; $updated = 0;
        while ($last < $maximum) {
            $chunk = $reclassifier->chunk($last, $maximum, 2);
            $last = $chunk['last_id']; $updated += $chunk['updated'];
        }
        self::assertSame(8, $updated);
        self::assertSame(5, $this->queryResult(['category' => 'crypto'])['total']);
        self::assertSame(1, $this->queryResult(['category' => 'tools'])['total']);
        self::assertSame(1, $this->queryResult(['category' => 'push'])['total']);
        self::assertSame(1, $this->queryResult(['category' => 'telemetry'])['total']);
        self::assertEquals($before, DB::table('v2_access_traffic')->orderBy('id')->get(['raw_u', 'raw_d', 'billed_u', 'billed_d', 'rate'])->toArray());
        self::assertSame($account, (array) DB::table('v2_user')->where('id', 1)->first());
        self::assertSame(0, $reclassifier->chunk(0, $maximum)['updated']);
    }

    public function test_reviewed_game_gateway_cdn_and_dns_rules_reclassify_without_changing_traffic_or_accounts(): void
    {
        $examples = ['cdn-huoshan-hw-mc.aki-game.net', 'codex.ccbvv.com', 'cdn.jsdelivr.net', 'other.ccbvv.com', ''];
        foreach ($examples as $index => $domain) {
            $entry = $this->entry($domain, 3, 5);
            if ($domain === '') $entry = array_replace($entry, ['ip' => '8.8.4.4', 'domain_source' => 'ip']);
            $name = 'reviewed-' . $index;
            $id = $this->service->receive(1, $this->payload($name, [$entry]), $this->now);
            $this->bill($name); $this->service->process($id);
        }
        $trafficBefore = DB::table('v2_access_traffic')->orderBy('id')->get(['raw_u', 'raw_d', 'billed_u', 'billed_d', 'rate'])->toArray();
        $accountBefore = (array) DB::table('v2_user')->where('id', 1)->first();
        DB::table('v2_access_traffic')->update(['service' => '', 'category' => 'unknown', 'confidence' => 'unknown', 'reason' => 'previous rules']);
        $reclassifier = new \App\Services\AccessTrafficReclassifier();
        $through = (int) DB::table('v2_access_traffic')->max('id');
        $last = 0;
        while ($last < $through) $last = $reclassifier->chunk($last, $through, 2)['last_id'];
        $result = $this->queryResult();
        self::assertSame(5, $result['total']);
        self::assertSame(40, $result['summary']['raw_total']);
        self::assertSame(32, $result['summary']['identified_bytes']);
        self::assertSame(55, $result['summary']['billed_total']);
        foreach (['games', 'ai', 'cdn', 'tools', 'unknown'] as $category) self::assertSame(1, $this->queryResult(['category' => $category])['total']);
        $rows = collect($result['data'])->keyBy('domain');
        self::assertSame('medium', $rows['codex.ccbvv.com']['confidence']);
        self::assertStringContainsString('完整域名', $rows['codex.ccbvv.com']['reason']);
        self::assertSame('medium', $rows['cdn-huoshan-hw-mc.aki-game.net']['confidence']);
        self::assertSame('high', $rows['cdn.jsdelivr.net']['confidence']);
        self::assertSame('unknown', $rows['other.ccbvv.com']['category']);
        self::assertEquals($trafficBefore, DB::table('v2_access_traffic')->orderBy('id')->get(['raw_u', 'raw_d', 'billed_u', 'billed_d', 'rate'])->toArray());
        self::assertSame($accountBefore, (array) DB::table('v2_user')->where('id', 1)->first());
        self::assertSame(0, $reclassifier->chunk(0, $through)['updated']);
    }

    public function test_coverage_exposes_uncollected_reports_and_partial_batches(): void
    {
        $this->bill('uncaptured');
        $payload = array_replace($this->payload('partial'), ['coverage' => 'partial', 'dropped_entries' => 2]);
        $id = $this->service->receive(1, $payload, $this->now);
        self::assertSame(1, $this->queryResult()['meta']['collection_gaps']['pending_detail_reports']);
        $this->service->process($id);
        $result = $this->queryResult();
        self::assertSame('partial', $result['meta']['coverage']);
        self::assertSame(1, $result['summary']['partial_report_count']);
        self::assertSame(1, $result['meta']['collection_gaps']['node_billing_reports_without_details']);
        self::assertStringContainsString('覆盖状态仅描述已收到的报告', $result['meta']['reason']);
    }

    public function test_config_defaults_allowlist_validation_and_authenticated_node_identity(): void
    {
        self::assertFalse($this->service->nodeConfig(1)['enabled']);
        $config = ['enabled' => true, 'bucket_seconds' => 60, 'retention_days' => 7, 'max_entries' => 100, 'node_ids' => [2]];
        self::assertSame($config, $this->service->saveSettings($config));
        self::assertFalse($this->service->nodeConfig(1)['enabled']);
        self::assertTrue($this->service->nodeConfig(2)['enabled']);
        $config['node_ids'] = [];
        $this->service->saveSettings($config);
        self::assertTrue($this->service->nodeConfig(1)['enabled']);
        try { $this->service->saveSettings(array_replace($config, ['bucket_seconds' => 1])); self::fail('Expected invalid config'); }
        catch (\Illuminate\Validation\ValidationException) { self::assertSame(60, $this->service->settings()['bucket_seconds']); }
        $request = Request::create('/api/v2/server/access/report', 'POST', $this->payload() + ['node_id' => 2]);
        $request->attributes->set('node_info', (object) ['id' => 1]);
        (new \App\Http\Controllers\V2\Server\AccessTrafficController())->report($request, $this->service);
        self::assertSame(1, DB::table('v2_access_report')->value('node_id'));
    }

    public function test_queue_failure_does_not_lose_acknowledged_receipt(): void
    {
        $bus = Mockery::mock(\Illuminate\Contracts\Bus\Dispatcher::class);
        $bus->shouldReceive('dispatch')->andThrow(new \RuntimeException('queue down'));
        $this->app->instance(\Illuminate\Contracts\Bus\Dispatcher::class, $bus);
        $id = $this->service->receive(1, $this->payload(), $this->now);
        $this->service->enqueue($id);
        self::assertSame(1, DB::table('v2_access_report')->count());
        $this->service->repair();
        self::assertSame(2, DB::table('v2_access_traffic')->count());
    }

    public function test_retention_cleanup_preserves_billing_idempotency_and_rejects_old_replay(): void
    {
        $old = $this->now - 31 * 86400;
        $payload = $this->payload('old');
        $payload['observed_at'] = $old;
        $payload['entries'] = [array_replace($this->entry(), ['bucket_at' => intdiv($old, 300) * 300, 'first_seen' => $old, 'last_seen' => $old])];
        $id = $this->service->receive(1, $payload, $old);
        $this->now = $old;
        $this->bill('old');
        $this->service->process($id);
        $this->now = time();
        self::assertSame(1, $this->service->prune($this->now));
        self::assertSame(0, DB::table('v2_access_traffic')->count());
        self::assertSame(1, DB::table('v2_traffic_report')->count());
        self::assertNull(DB::table('v2_traffic_report')->value('billing_snapshot'));
        $this->bill('old');
        self::assertSame(4, DB::table('v2_user')->where('id', 1)->value('u'));
        $this->expectException(\Symfony\Component\HttpKernel\Exception\GoneHttpException::class);
        $this->service->receive(1, $payload, $this->now);
    }

    public function test_retention_keeps_valid_activity_in_a_bucket_starting_before_cutoff(): void
    {
        $cutoff = intdiv(time(), 300) * 300 - 150;
        $this->now = $cutoff + 20;
        $entry = $this->entry('valid.googlevideo.com', 3, 5);
        $entry['first_seen'] = $cutoff + 10;
        $entry['last_seen'] = $cutoff + 20;
        $id = $this->service->receive(1, $this->payload('near-cutoff', [$entry]), $this->now);
        $this->service->process($id);
        $this->service->prune($cutoff + 30 * 86400);
        self::assertSame(1, DB::table('v2_access_traffic')->count());
        self::assertSame(8, (int) DB::table('v2_access_traffic')->selectRaw('SUM(raw_u + raw_d) AS total')->value('total'));
        self::assertNull(DB::table('v2_access_report')->value('settlement_error'));
    }

    public function test_retention_truncated_unsettled_report_is_not_overallocated(): void
    {
        $id = $this->service->receive(1, $this->payload(), $this->now);
        $this->service->process($id);
        DB::table('v2_access_traffic')->where('domain_source', 'request')->update(['bucket_at' => $this->now - 31 * 86400, 'first_seen' => $this->now - 31 * 86400, 'last_seen' => $this->now - 31 * 86400]);
        $this->service->prune($this->now);
        self::assertSame('retention_expired', DB::table('v2_access_report')->value('settlement_error'));
        $this->bill();
        $this->service->process($id);
        self::assertNull(DB::table('v2_access_traffic')->value('billed_u'));
        self::assertSame(4, DB::table('v2_user')->where('id', 1)->value('u'));
    }

    public function test_shared_node_config_includes_access_log_without_reading_credentials(): void
    {
        $this->settings['access_traffic_config'] = ['enabled' => true];
        $node = new \App\Models\Server();
        $node->setRawAttributes(['id' => 1, 'type' => 'vmess', 'server_port' => 443, 'host' => 'node.example',
            'protocol_settings' => json_encode(['tls' => 0, 'tls_settings' => []])]);
        $limit = Mockery::mock(\App\Services\ServerTrafficLimitService::class);
        $limit->shouldReceive('buildNodeConfig')->andReturn(['enabled' => false]);
        $this->app->instance(\App\Services\ServerTrafficLimitService::class, $limit);
        $config = \App\Services\ServerService::buildNodeConfig($node);
        self::assertSame(['enabled' => true, 'bucket_seconds' => 300, 'max_entries' => 2000], $config['access_log']);
    }

    private function registerViews(): void
    {
        $files = new Filesystem();
        $events = new Dispatcher($this->app);
        $compiler = new BladeCompiler($files, sys_get_temp_dir() . '/xboard-access-blade');
        $files->ensureDirectoryExists(sys_get_temp_dir() . '/xboard-access-blade');
        $engines = new EngineResolver();
        $engines->register('blade', fn () => new CompilerEngine($compiler, $files));
        $views = new ViewFactory($engines, new FileViewFinder($files, [__DIR__ . '/../../resources/views']), $events);
        $views->setContainer($this->app);
        $views->share('__env', $views);
        $this->app->instance('view', $views);
        $this->app->instance(\Illuminate\Contracts\View\Factory::class, $views);
    }

    public function test_stream_exports_all_rows_with_csv_and_html_escaping_and_limits(): void
    {
        $this->registerViews();
        DB::table('v2_server')->where('id', 1)->update(['name' => '=SUM(1,2)<script>boom</script>']);
        $id = $this->service->receive(1, $this->payload(), $this->now);
        $this->service->process($id);
        $controller = new AccessTrafficController();
        $query = new AccessTrafficQueryService();
        foreach (['csv', 'html'] as $format) {
            $request = Request::create('/export', 'GET', ['format' => $format, 'pageSize' => 1]);
            $response = $controller->export($request, $query);
            ob_start();
            $response->sendContent();
            $text = ob_get_clean();
            if ($format === 'csv') {
                self::assertStringContainsString('raw_total', $text);
                self::assertStringContainsString('billed_total', $text);
                self::assertStringStartsWith("\xEF\xBB\xBF", $text);
            } else {
                self::assertStringContainsString('原始合计', $text);
                self::assertStringContainsString('计费分摊', $text);
            }
            self::assertStringContainsString('Asia/Shanghai', $text);
            if ($format === 'csv') {
                self::assertStringContainsString("'=SUM", $text);
                self::assertStringContainsString('googlevideo.com', $text);
            } else {
                self::assertStringNotContainsString('<script>boom</script>', $text);
                self::assertStringContainsString('&lt;script&gt;boom&lt;/script&gt;', $text);
                self::assertStringContainsString('window.print()', $text);
                self::assertSame(2, substr_count($text, '<tr>') - 1);
            }
        }
        foreach (['=SUM(A1)', ' +cmd', "\t@cmd", '-formula'] as $cell) self::assertStringStartsWith("'", AccessTrafficQueryService::csvCell($cell));
        $this->app['config']->set('access_traffic.export_max_rows', 1);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $controller->export(Request::create('/export'), $query);
    }

    public function test_streamed_page_keeps_snapshot_when_another_connection_prunes_details(): void
    {
        $id = $this->service->receive(1, $this->payload('snapshot', [$this->entry('snapshot.googlevideo.com', 3, 5)]), $this->now);
        $this->service->process($id);
        $path = tempnam(sys_get_temp_dir(), 'access-snapshot-');
        $previous = DB::getDefaultConnection();
        $deleted = false;
        try {
            foreach (['snapshot_reader', 'snapshot_writer'] as $name) {
                $this->db->addConnection(['driver' => 'sqlite', 'database' => $path, 'foreign_key_constraints' => true], $name);
            }
            $reader = DB::connection('snapshot_reader');
            $writer = DB::connection('snapshot_writer');
            $reader->statement('PRAGMA journal_mode = WAL');
            foreach (['v2_user', 'v2_access_report', 'v2_access_traffic', 'v2_traffic_report'] as $table) {
                $schema = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]);
                $reader->statement($schema->sql);
                $rows = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
                if ($rows) $reader->table($table)->insert($rows);
            }
            $reader->setEventDispatcher(new Dispatcher($this->app));
            $reader->listen(function ($query) use ($writer, &$deleted): void {
                if (!$deleted && str_contains($query->sql, 'as "g"')) {
                    $deleted = true;
                    $writer->table('v2_access_traffic')->delete();
                    $writer->table('v2_access_report')->delete();
                }
            });
            DB::setDefaultConnection('snapshot_reader');
            $query = new AccessTrafficQueryService();
            $result = $query->index($query->filters(['start_time' => $this->now - 1000, 'end_time' => $this->now + 1]));
            self::assertTrue($deleted);
            self::assertSame(0, $writer->table('v2_access_traffic')->count());
            self::assertSame(1, $result['total']);
            self::assertSame(1, $result['summary']['report_count']);
            self::assertSame(8, $result['summary']['raw_total']);
            self::assertSame('snapshot.googlevideo.com', $result['data'][0]['domain']);
            self::assertSame(0, $reader->transactionLevel());
        } finally {
            DB::setDefaultConnection($previous);
            foreach (['snapshot_reader', 'snapshot_writer'] as $name) DB::purge($name);
            foreach ([$path, $path . '-wal', $path . '-shm'] as $file) if (is_file($file)) unlink($file);
        }
    }

    public function test_report_time_bound_keeps_clock_tolerance_and_delayed_long_fragments(): void
    {
        $start = $this->now - 500;
        foreach ([['boundary', $start - 300, $start - 1, $start],
            ['delayed', $this->now, $start - 86400, $start + 5],
            ['before', $start - 301, $start - 10, $start - 1]] as [$name, $observed, $first, $last]) {
            $entry = array_replace($this->entry($name . '.googlevideo.com', 3, 5),
                ['bucket_at' => $first, 'first_seen' => $first, 'last_seen' => $last]);
            $payload = array_replace($this->payload($name, [$entry]), ['observed_at' => $observed]);
            $id = $this->service->receive(1, $payload, $this->now);
            $this->service->process($id);
        }
        $query = new AccessTrafficQueryService();
        $filters = $query->filters(['start_time' => $start, 'end_time' => $start + 10], $this->now);
        $result = $query->index($filters);
        self::assertSame(2, $result['total']);
        self::assertSame(16, $result['summary']['raw_total']);
        self::assertSame(2, $result['summary']['report_count']);
        self::assertSame($start - 300, $result['meta']['first_collected_at']);
        self::assertSame($this->now, $result['meta']['last_collected_at']);
        self::assertSame(['boundary.googlevideo.com', 'delayed.googlevideo.com'], array_column($result['data'], 'domain'));
        $deep = $query->index(array_replace($filters, ['page' => 10001, 'pageSize' => 1]));
        self::assertSame(2, $deep['total']);
        self::assertSame([], $deep['data']);
        self::assertSame($result['summary'], $deep['summary']);
    }

    public function test_activity_inside_bucket_is_found_and_historical_node_name_survives(): void
    {
        $this->now = intdiv(time(), 300) * 300 - 150;
        $entry = $this->entry('video.googlevideo.com', 3, 5);
        $entry['last_seen'] = $this->now + 10;
        $payload = $this->payload('interval', [$entry]);
        $payload['observed_at'] = $this->now + 10;
        $id = $this->service->receive(1, $payload, $this->now + 10);
        DB::table('v2_server')->where('id', 1)->update(['name' => 'Renamed node']);
        $this->service->process($id);
        DB::table('v2_server')->where('id', 1)->delete();
        $query = new AccessTrafficQueryService();
        $filters = $query->filters(['start_time' => $this->now + 1, 'end_time' => $this->now + 5], $this->now + 20);
        $result = $query->index($filters);
        self::assertSame(8, $result['summary']['raw_total']);
        self::assertSame('Node one', $result['data'][0]['node_name']);
        $before = $query->filters(['start_time' => $this->now - 10, 'end_time' => $this->now], $this->now + 20);
        self::assertSame(0, $query->index($before)['summary']['raw_total']);
    }

    public function test_detail_jobs_use_a_queue_separate_from_charging(): void
    {
        self::assertSame('access_traffic', (new \App\Jobs\AccessTrafficJob(1))->queue);
        $horizon = require __DIR__ . '/../../config/horizon.php';
        self::assertSame(['access_traffic'], $horizon['environments']['production']['access-traffic']['queue']);
        self::assertNotContains('access_traffic', $horizon['environments']['production']['data-pipeline']['queue']);
        self::assertSame(['access_traffic'], $horizon['environments']['local']['access-traffic']['queue']);
        self::assertNotContains('access_traffic', $horizon['environments']['local']['Xboard']['queue']);
    }

    public function test_node_scoped_capture_setting_respects_allowlist(): void
    {
        $this->service->saveSettings(['enabled' => true, 'bucket_seconds' => 300, 'retention_days' => 30,
            'max_entries' => 2000, 'node_ids' => [2]]);
        self::assertFalse($this->queryResult(['node_id' => 1])['meta']['enabled']);
        self::assertTrue($this->queryResult(['node_id' => 2])['meta']['enabled']);
    }

    public function test_v2_routes_keep_server_auth_and_admin_log_middleware(): void
    {
        $this->settings['secure_path'] = 'test-admin';
        $router = new Router(new Dispatcher($this->app), $this->app);
        (new \App\Http\Routes\V2\ServerRoute())->map($router);
        (new \App\Http\Routes\V2\AdminRoute())->map($router);
        $routes = [];
        foreach ($router->getRoutes() as $route) $routes[$route->uri()] = $route;
        self::assertContains('server.v2', $routes['server/access/report']->middleware());
        foreach (['getAccessTraffic', 'exportAccessTraffic', 'accessTrafficConfig'] as $action) {
            self::assertContains('admin', $routes['test-admin/stat/' . $action]->middleware());
            self::assertContains('log', $routes['test-admin/stat/' . $action]->middleware());
        }
    }
}
