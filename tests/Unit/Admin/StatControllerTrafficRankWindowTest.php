<?php

namespace Tests\Unit\Admin;

use App\Http\Controllers\V2\Admin\StatController;
use Carbon\CarbonImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** No application bootstrap, environment files, persistent DB or migrations. */
class StatControllerTrafficRankWindowTest extends TestCase
{
    private Container $container;
    private Manager $db;
    private mixed $previousContainer;
    private mixed $previousFacadeApplication;
    private array $previousRequestMacros;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $this->container = new Container();
        Container::setInstance($this->container);
        $this->container->instance('config', new Repository(['app' => ['timezone' => 'Asia/Shanghai']]));
        $this->container->instance('cache', new CacheRepository(new ArrayStore()));
        $this->container->instance('validator', new Factory(new Translator(new ArrayLoader(), 'en'), $this->container));
        $this->db = new Manager($this->container);
        $this->db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->container->instance('db', $this->db->getDatabaseManager());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->container);
        $this->previousRequestMacros = (new \ReflectionProperty(Request::class, 'macros'))->getValue();
        Request::macro('validate', function (array $rules) {
            return app('validator')->make($this->all(), $rules)->validate();
        });
        $this->db->getConnection()->getSchemaBuilder()->create('v2_traffic_report', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('received_at');
            $table->text('billing_snapshot')->nullable();
        });
        foreach (['server', 'user'] as $type) {
            $this->db->getConnection()->getSchemaBuilder()->create('v2_stat_' . $type, function (Blueprint $table) use ($type): void {
                $table->integer($type . '_id');
                $table->string('record_type');
                $table->integer('record_at');
                $table->bigInteger('u');
                $table->bigInteger('d');
            });
            $this->db->getConnection()->getSchemaBuilder()->create('v2_' . $type, function (Blueprint $table) use ($type): void {
                $table->integer('id');
                $table->string($type === 'server' ? 'name' : 'email');
            });
            $this->db->getConnection()->table('v2_' . $type)->insert(['id' => 1, $type === 'server' ? 'name' : 'email' => 'test']);
        }
    }

    protected function tearDown(): void
    {
        $this->db->getConnection()->disconnect();
        CarbonImmutable::setTestNow();
        (new \ReflectionProperty(Request::class, 'macros'))->setValue(null, $this->previousRequestMacros);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public static function windows(): array
    {
        return [
            'today' => [1, '2026-05-01', 'Asia/Shanghai'],
            '7 days across month' => [7, '2026-04-28', 'Asia/Shanghai'],
            '30 days' => [30, '2026-03-20', 'Asia/Shanghai'],
            '90 days across year' => [90, '2025-12-01', 'Asia/Shanghai'],
            'spring DST' => [7, '2026-03-06', 'America/New_York'],
            'fall DST' => [7, '2026-10-30', 'America/New_York'],
            'DST day' => [1, '2026-03-08', 'America/New_York'],
        ];
    }

    #[DataProvider('windows')]
    public function test_current_and_previous_windows_include_every_daily_bucket(int $days, string $date, string $timezone): void
    {
        config(['app.timezone' => $timezone]);
        $start = CarbonImmutable::parse($date, $timezone);
        $end = $start->addDays($days);
        foreach (['server' => 1, 'user' => 3] as $table => $rate) {
            for ($day = -$days - 1; $day <= $days; $day++) {
                $this->insertBucket($table, $start->addDays($day)->timestamp, 10 * $rate, 20 * $rate);
            }
            // Monthly rollups must never be double-counted.
            $this->insertBucket($table, $start->timestamp, 99999, 99999, 'm');
            $response = (new StatController())->getTrafficRank(Request::create('/', 'GET', [
                'type' => $table === 'server' ? 'node' : 'user',
                'start_date' => $date,
                'end_date' => $end->subDay()->toDateString(),
            ]));
            $this->assertSame($days * 30 * $rate, $response['data'][0]['value']);
            $this->assertSame($days * 30 * $rate, $response['data'][0]['previousValue']);
            $this->assertEquals(0, $response['data'][0]['change']);
        }
        $previous = $this->invoke('resolveTrafficRankComparisonWindow', $start->timestamp, $end->timestamp);
        $this->assertSame($start->subDays($days)->timestamp, $previous['start']);
        $this->assertSame($start->timestamp, $previous['end']);
    }

    public function test_date_strings_ignore_process_timezone_and_take_precedence_over_legacy_timestamps(): void
    {
        $oldTimezone = date_default_timezone_get();
        date_default_timezone_set('America/Los_Angeles');
        try {
            $window = $this->invoke('resolveTrafficRankWindow', Request::create('/', 'GET', [
                'start_date' => '2026-05-01', 'end_date' => '2026-05-01',
                'start_time' => 1700000000, 'end_time' => 1700000000,
            ]));
            $this->assertSame(CarbonImmutable::parse('2026-05-01', 'Asia/Shanghai')->timestamp, $window['start']);
            $this->assertSame(CarbonImmutable::parse('2026-05-02', 'Asia/Shanghai')->timestamp, $window['end']);
        } finally {
            date_default_timezone_set($oldTimezone);
        }
    }

    public function test_legacy_timestamps_are_normalized_to_containing_days(): void
    {
        $start = CarbonImmutable::parse('2026-04-28', 'Asia/Shanghai');
        $end = $start->addDays(7);
        $window = $this->invoke('resolveTrafficRankWindow', Request::create('/', 'GET', [
            'start_time' => $start->addHours(12)->timestamp, 'end_time' => $end->subSecond()->timestamp,
        ]));
        $this->assertSame(['start' => $start->timestamp, 'end' => $end->timestamp], $window);
        $this->insertBucket('server', $start->timestamp, 1, 2);
        $response = (new StatController())->getTrafficRank(Request::create('/', 'GET', [
            'type' => 'node', 'start_time' => $start->addHours(12)->timestamp, 'end_time' => $end->subSecond()->timestamp,
        ]));
        $this->assertSame(3, $response['data'][0]['value']);
    }

    public function test_default_window_is_seven_calendar_days(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-01 12:00', 'Asia/Shanghai'));
        $window = $this->invoke('resolveTrafficRankWindow', Request::create('/'));
        $this->assertSame(CarbonImmutable::parse('2026-04-25', 'Asia/Shanghai')->timestamp, $window['start']);
        $this->assertSame(CarbonImmutable::parse('2026-05-02', 'Asia/Shanghai')->timestamp, $window['end']);
    }

    public function test_reversed_window_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        (new StatController())->getTrafficRank(Request::create('/', 'GET', [
            'type' => 'node', 'start_date' => '2026-05-02', 'end_date' => '2026-05-01',
        ]));
    }

    public function test_invalid_date_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        (new StatController())->getTrafficRank(Request::create('/', 'GET', [
            'type' => 'node', 'start_date' => '2026-02-30', 'end_date' => '2026-03-01',
        ]));
    }

    public function test_cumulative_upload_and_download_include_historical_months_and_cache_history(): void
    {
        $today = CarbonImmutable::parse('2026-05-03', 'Asia/Shanghai');
        $this->insertBucket('server', $today->subMonths(2)->timestamp, 100, 1000);
        $this->insertBucket('server', $today->subMonth()->timestamp, 200, 2000);
        $this->insertBucket('server', $today->subDay()->timestamp, 30, 300);
        $this->insertBucket('server', $today->timestamp, 4, 40);
        $this->insertBucket('server', $today->timestamp, 99999, 99999, 'm');
        $this->insertBucket('server', $today->addDay()->timestamp, 99999, 99999);
        $this->insertBucket('user', $today->timestamp, 88888, 88888);
        $this->db->getConnection()->enableQueryLog();
        $stats = $this->invoke('queryDashboardTrafficStats', $today->addHours(12)->timestamp, $today->timestamp, $today->startOfMonth()->timestamp);
        $this->assertSame(['upload' => 334, 'download' => 3340, 'total' => 3674], $stats['totalTraffic']);
        $this->assertSame(['upload' => 34, 'download' => 340, 'total' => 374], $stats['monthTraffic']);
        $this->assertSame(['upload' => 4, 'download' => 40, 'total' => 44], $stats['todayTraffic']);
        $this->assertCount(2, $this->db->getConnection()->getQueryLog());
        $this->db->getConnection()->table('v2_stat_server')->where('record_type', 'd')->where('record_at', $today->timestamp)->update(['d' => 50]);
        $this->db->getConnection()->flushQueryLog();
        $fresh = $this->invoke('queryDashboardTrafficStats', $today->addHours(12)->timestamp, $today->timestamp, $today->startOfMonth()->timestamp);
        $this->assertSame(3350, $fresh['totalTraffic']['download']);
        $this->assertCount(1, $this->db->getConnection()->getQueryLog());
        // A new date uses a new history key, including yesterday exactly once.
        $next = $today->addDay();
        $this->db->getConnection()->table('v2_stat_server')->where('record_at', $next->timestamp)->delete();
        $rollover = $this->invoke('queryDashboardTrafficStats', $next->addHours(12)->timestamp, $next->timestamp, $next->startOfMonth()->timestamp);
        $this->assertSame($fresh['totalTraffic'], $rollover['totalTraffic']);
    }

    public function test_empty_traffic_returns_zero_totals(): void
    {
        $today = CarbonImmutable::parse('2026-05-01', 'Asia/Shanghai');
        $stats = $this->invoke('queryDashboardTrafficStats', $today->addHour()->timestamp, $today->timestamp, $today->timestamp);
        foreach ($stats as $traffic) {
            $this->assertSame(['upload' => 0, 'download' => 0, 'total' => 0], $traffic);
        }
    }

    public function test_node_keyword_filters_before_limit_and_summary_covers_all_matching_nodes(): void
    {
        $today = CarbonImmutable::parse('2026-05-01', 'Asia/Shanghai');
        for ($id = 1; $id <= 22; $id++) {
            $name = $id <= 21 ? ($id === 21 ? '12.5X Node' : '12.5x Node') : 'Unrelated Node';
            if ($id === 1) {
                $this->db->getConnection()->table('v2_server')->where('id', 1)->update(['name' => $name]);
            } else {
                $this->db->getConnection()->table('v2_server')->insert(['id' => $id, 'name' => $name]);
            }
            $value = $id <= 21 ? $id : 10000;
            $this->db->getConnection()->table('v2_stat_server')->insert([
                'server_id' => $id, 'record_type' => 'd', 'record_at' => $today->timestamp, 'u' => $value, 'd' => $value * 2,
            ]);
        }
        $this->insertBucket('server', $today->timestamp, 99999, 99999, 'm');
        $this->insertBucket('server', $today->subDay()->timestamp, 3, 6);
        $this->insertBucket('user', $today->timestamp, 99999, 99999);
        $controller = new StatController();
        $params = ['type' => 'node', 'start_date' => '2026-05-01', 'end_date' => '2026-05-01'];
        foreach ([10, 20] as $limit) {
            $response = $controller->getTrafficRank(Request::create('/', 'GET', $params + ['keyword' => ' 12.5x ', 'limit' => $limit]));
            $this->assertCount($limit, $response['data']);
            $this->assertSame('21', $response['data'][0]['id']);
            $this->assertSame(['upload' => 231, 'download' => 462, 'total' => 693, 'matchedCount' => 21], $response['summary']);
        }
        $unfiltered = $controller->getTrafficRank(Request::create('/', 'GET', $params));
        $this->assertSame('22', $unfiltered['data'][0]['id']);
        $this->assertSame(30693, $unfiltered['summary']['total']);
        $empty = $controller->getTrafficRank(Request::create('/', 'GET', $params + ['keyword' => 'absent']));
        $this->assertSame([], $empty['data']);
        $this->assertSame(['upload' => 0, 'download' => 0, 'total' => 0, 'matchedCount' => 0], $empty['summary']);
    }

    public static function literalKeywords(): array
    {
        return ['percent' => ['12.5%'], 'underscore' => ['node_'], 'escape' => ['node!']];
    }

    #[DataProvider('literalKeywords')]
    public function test_keyword_metacharacters_are_matched_literally(string $keyword): void
    {
        $today = CarbonImmutable::parse('2026-05-01', 'Asia/Shanghai');
        $this->db->getConnection()->table('v2_server')->where('id', 1)->update(['name' => $keyword . ' literal']);
        $this->db->getConnection()->table('v2_server')->insert(['id' => 2, 'name' => '12.5x nodeX']);
        foreach ([1, 2] as $id) {
            $this->db->getConnection()->table('v2_stat_server')->insert([
                'server_id' => $id, 'record_type' => 'd', 'record_at' => $today->timestamp, 'u' => 10, 'd' => 20,
            ]);
        }
        $response = (new StatController())->getTrafficRank(Request::create('/', 'GET', [
            'type' => 'node', 'start_date' => '2026-05-01', 'end_date' => '2026-05-01', 'keyword' => $keyword,
        ]));
        $this->assertSame(['1'], array_column($response['data'], 'id'));
        $this->assertSame(30, $response['summary']['total']);
    }

    public function test_rolling_24_hours_use_raw_receipts_and_exact_time_boundaries(): void
    {
        $now = CarbonImmutable::parse('2026-05-01 12:34:56', 'Asia/Shanghai')->timestamp;
        $oldSnapshot = ['rate' => 12.5, 'users' => [
            1 => ['final_u' => 10, 'final_d' => 20, 'billed_u' => 125, 'billed_d' => 250],
            999 => ['final_u' => 7, 'final_d' => 9, 'billed_u' => 0, 'billed_d' => 0],
        ]];
        $newSnapshot = ['rate' => 12.5, 'raw_upload' => 3, 'raw_download' => 5, 'users' => [
            1 => ['final_u' => 3, 'final_d' => 5, 'billed_u' => 37, 'billed_d' => 62],
        ]];
        foreach ([[$now - 86400 - 1, null], [$now - 86400, $oldSnapshot], [$now - 1, $newSnapshot], [$now, null]] as [$receivedAt, $snapshot]) {
            $this->db->getConnection()->table('v2_traffic_report')->insert([
                'received_at' => $receivedAt, 'billing_snapshot' => $snapshot === null ? null : json_encode($snapshot),
            ]);
        }
        $this->insertBucket('server', $now - 100, 99999, 99999);
        $this->assertSame(['upload' => 20, 'download' => 34, 'total' => 54], $this->invoke('queryLast24HoursTraffic', $now));
    }

    public function test_rolling_24_hours_read_all_legacy_snapshot_batches_and_empty_window(): void
    {
        $now = CarbonImmutable::parse('2026-05-01 12:00', 'Asia/Shanghai')->timestamp;
        $this->assertSame(['upload' => 0, 'download' => 0, 'total' => 0], $this->invoke('queryLast24HoursTraffic', $now));
        for ($id = 0; $id < 301; $id++) {
            $this->db->getConnection()->table('v2_traffic_report')->insert([
                'received_at' => $now - 60,
                'billing_snapshot' => json_encode(['rate' => 12.5, 'users' => [1 => ['final_u' => 1, 'final_d' => 2]]]),
            ]);
        }
        $this->assertSame(['upload' => 301, 'download' => 602, 'total' => 903], $this->invoke('queryLast24HoursTraffic', $now));
    }

    public static function incompleteSnapshots(): array
    {
        return [
            'missing' => [null], 'invalid JSON' => ['{'], 'missing users' => ['{}'],
            'missing direction' => [json_encode(['users' => [1 => ['final_u' => 1]]])],
            'negative bytes' => [json_encode(['users' => [1 => ['final_u' => -1, 'final_d' => 2]]])],
        ];
    }

    #[DataProvider('incompleteSnapshots')]
    public function test_rolling_24_hours_do_not_present_partial_history_as_a_complete_total(?string $snapshot): void
    {
        $now = CarbonImmutable::parse('2026-05-01 12:00', 'Asia/Shanghai')->timestamp;
        $this->db->getConnection()->table('v2_traffic_report')->insert([
            'received_at' => $now - 60, 'billing_snapshot' => $snapshot,
        ]);
        $this->assertNull($this->invoke('queryLast24HoursTraffic', $now));
    }

    private function insertBucket(string $table, int $timestamp, int $upload, int $download, string $type = 'd'): void
    {
        $this->db->getConnection()->table('v2_stat_' . $table)->insert([
            $table . '_id' => 1, 'record_type' => $type, 'record_at' => $timestamp, 'u' => $upload, 'd' => $download,
        ]);
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        return (new ReflectionMethod(StatController::class, $method))->invoke(new StatController(), ...$arguments);
    }
}
