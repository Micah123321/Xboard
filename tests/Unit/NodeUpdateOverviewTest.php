<?php

namespace Tests\Unit;

use App\Exceptions\NodeUpdateException;
use App\Http\Controllers\V2\Admin\NodeUpdateController;
use App\Models\NodeUpdateRecord;
use App\Models\Server;
use App\Services\NodeUpdate\NodeDiscoveryService;
use App\Services\NodeUpdate\NodeUpdateOverviewService;
use App\Services\NodeUpdate\NodeUpdateService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

class NodeUpdateOverviewTest extends TestCase
{
    private Manager $db;
    private NodeUpdateService $managed;
    private NodeDiscoveryService $discoveries;
    private NodeUpdateOverviewService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $app = new Container();
        Container::setInstance($app);
        $app->instance('app', $app);
        $app->instance('config', new Repository(['app' => ['timezone' => 'Asia/Shanghai']]));
        $app->instance('events', new Dispatcher($app));
        $this->db = new Manager($app);
        $this->db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->db->setAsGlobal();
        $this->db->bootEloquent();
        Model::clearBootedModels();
        $app->instance('db', $this->db->getDatabaseManager());
        $app->bind('db.schema', fn () => $this->db->schema());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        Carbon::setTestNow(Carbon::parse('2026-10-31T12:00:00Z'));
        $this->db->schema()->create('v2_server', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->unsignedInteger('machine_id')->nullable();
        });
        foreach (['2026_08_29_000001_create_node_update_tables.php',
            '2026_08_30_000001_create_node_update_discoveries_table.php',
            '2026_10_12_000001_add_node_update_latest_policies.php',
            '2026_10_12_000002_add_node_auto_enrollment_capability.php'] as $migration) {
            (require __DIR__.'/../../database/migrations/'.$migration)->up();
        }
        Cache::swap(new CacheRepository(new ArrayStore()));
        $this->managed = new NodeUpdateService();
        $this->discoveries = new NodeDiscoveryService();
        $this->service = new NodeUpdateOverviewService($this->managed, $this->discoveries);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        \Mockery::close();
        $this->db->getDatabaseManager()->disconnect();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Model::clearBootedModels();
        Container::setInstance(null);
        parent::tearDown();
    }

    private function id(int $number): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $number);
    }

    private function node(int $id, ?int $machine = null): Server
    {
        DB::table('v2_server')->insert(['id' => $id, 'name' => 'Node '.$id, 'machine_id' => $machine]);
        $node = new Server();
        $node->setRawAttributes(['id' => $id]);
        return $node;
    }

    private function discover(Server $node, string $installation, array $extra = []): void
    {
        $this->discoveries->report($node, array_replace(['installation_id' => $installation,
            'version' => 'v1.2.3', 'os' => 'linux', 'arch' => 'amd64', 'auto_enroll_supported' => true], $extra));
    }

    private function installation(string $id, array $scope, array $extra = []): NodeUpdateRecord
    {
        return $this->managed->q('installations')->create(array_replace([
            'id' => $id, 'scope' => $scope, 'token_hash' => hash('sha256', 'token-'.$id),
            'credential_hash' => hash('sha256', 'credential-'.$id), 'policy_enabled' => false,
            'follow_latest' => false, 'policy_revision' => 1,
            'capability' => ['status' => 'supported', 'reason' => null],
            'runtime' => ['os' => 'linux', 'arch' => 'amd64', 'init' => 'systemd', 'container' => false],
            'versions' => ['mi_node' => 'v1.2.2', 'xbctl' => 'v1.2.2'],
            'installed_sha256' => ['mi_node' => null, 'xbctl' => null],
        ], $extra));
    }

    public function testAllDiscoveryNodesMergeWithFullScopeAndWhitelistedDto(): void
    {
        $first = $this->node(328, 7);
        $second = $this->node(376, 8);
        $this->node(500, 7);
        $installation = $this->installation($this->id(1), ['kind' => 'legacy', 'node_ids' => [328, 500]],
            ['last_seen_at' => '2026-10-30 12:00:00']);
        $status = ['state' => 'retrying', 'stage' => 'enroll', 'code' => 'scope_mismatch',
            'updated_at' => '2026-10-31T12:00:00Z'];
        $this->discover($first, $installation->id, ['enrollment_status' => $status, 'token' => 'fixture-secret']);
        $this->discover($second, $installation->id);
        $this->discover($this->node(398, 9), $this->id(2));
        $this->discover($this->node(433, 9), $this->id(2));
        $page = $this->service->listing([]);
        self::assertSame(2, $page['total']);
        self::assertCount(2, $page['items']);
        $row = $page['items'][0];
        self::assertSame(['installation_id', 'installation', 'discoveries', 'nodes',
            'scope_mismatch_node_ids', 'last_seen_at'], array_keys($row));
        self::assertSame($this->managed->dto('installations', $installation), $row['installation']);
        self::assertSame([328, 376], array_column($row['discoveries'], 'node_id'));
        self::assertSame($status, $row['discoveries'][0]['enrollment_status']);
        self::assertSame([328, 376, 500], array_column($row['nodes'], 'id'));
        self::assertSame([376], $row['scope_mismatch_node_ids']);
        self::assertSame('2026-10-31T12:00:00Z', $row['last_seen_at']);
        self::assertFalse($row['installation']['online']);
        self::assertArrayNotHasKey('online', $row);
        self::assertSame([328, 500], $installation->fresh()->scope['node_ids']);
        self::assertNull($page['items'][1]['installation']);
        self::assertSame([398, 433], array_column($page['items'][1]['nodes'], 'id'));
        self::assertSame([], $page['items'][1]['scope_mismatch_node_ids']);
        $json = json_encode($page, JSON_THROW_ON_ERROR);
        foreach (['token_hash', 'credential_hash', 'reported_active_attempt', 'fixture-secret',
            $installation->token_hash, $installation->credential_hash] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
    }

    public function testSingleSourceRevokedAndNodelessInstallationsRemainVisibleInStableOrder(): void
    {
        $this->node(20, 7);
        $managed = $this->installation($this->id(3), ['kind' => 'machine', 'machine_id' => 7],
            ['last_seen_at' => '2026-11-01 00:00:00']);
        $revoked = $this->installation($this->id(2), ['kind' => 'legacy', 'node_ids' => [20]],
            ['revoked_at' => '2026-10-30 12:00:00']);
        $this->discover($this->node(30), $revoked->id);
        $this->installation($this->id(5), ['kind' => 'machine', 'machine_id' => 99]);
        $this->installation($this->id(4), ['kind' => 'legacy', 'node_ids' => [9999]]);
        $this->discover($this->node(10), $this->id(1));
        $first = $this->service->listing(['page_size' => 100]);
        $second = $this->service->listing(['page_size' => 100]);
        self::assertSame($first, $second);
        self::assertSame(5, $first['total']);
        self::assertSame([$this->id(1), $this->id(2), $this->id(3), $this->id(4), $this->id(5)],
            array_column($first['items'], 'installation_id'));
        self::assertNotNull($first['items'][1]['installation']['revoked_at']);
        self::assertSame([30], $first['items'][1]['scope_mismatch_node_ids']);
        self::assertSame([], $first['items'][2]['discoveries']);
        self::assertSame('2026-11-01T00:00:00Z', $first['items'][2]['last_seen_at']);
        self::assertSame([], $first['items'][3]['nodes']);
        self::assertNull($first['items'][3]['last_seen_at']);
        self::assertSame([$this->id(5)], array_column($this->service->listing(['machine_id' => '99'])['items'], 'installation_id'));
        self::assertNull($managed->fresh()->revoked_at);
    }

    public function testFiltersMatchNodeUnionAndRetainCompleteBindingsAndDiscoveries(): void
    {
        $this->node(328, 7);
        $this->node(500, 7);
        $this->installation($this->id(1), ['kind' => 'legacy', 'node_ids' => [328, 500]]);
        $this->discover($this->node(376, 8), $this->id(1));
        $this->discover($this->node(398, 8), $this->id(2));
        $this->discover($this->node(433, 8), $this->id(2));
        $this->installation($this->id(3), ['kind' => 'legacy', 'node_ids' => [500]],
            ['capability' => ['status' => 'unsupported', 'reason' => 'fixture']]);
        foreach ([['node_id' => '376'], ['node_id' => 328], ['machine_id' => '8', 'node_id' => '500'],
            ['capability_status' => 'supported']] as $filter) {
            $page = $this->service->listing($filter);
            self::assertSame(1, $page['total']);
            self::assertSame($this->id(1), $page['items'][0]['installation_id']);
            self::assertSame([328, 376, 500], array_column($page['items'][0]['nodes'], 'id'));
            self::assertSame([376], array_column($page['items'][0]['discoveries'], 'node_id'));
            self::assertSame([328, 500], $page['items'][0]['installation']['scope']['node_ids']);
        }
        self::assertSame(2, $this->service->listing(['machine_id' => 8])['total']);
        self::assertSame(2, $this->service->listing(['node_id' => 500])['total']);
        $unsupported = $this->service->listing(['capability_status' => 'unsupported']);
        self::assertSame([$this->id(3)], array_column($unsupported['items'], 'installation_id'));
        self::assertSame(0, $this->service->listing(['capability_status' => 'unsupported', 'machine_id' => 8])['total']);
        self::assertSame(0, $this->service->listing(['node_id' => 999])['total']);
    }

    public function testGroupingPrecedesPaginationAcrossMoreThanTwentyDiscoveryPages(): void
    {
        $nodes = []; $discoveries = [];
        for ($nodeId = 1; $nodeId <= 2101; $nodeId++) {
            $nodes[] = ['id' => $nodeId, 'name' => 'Node '.$nodeId, 'machine_id' => null];
            $discoveries[] = ['node_id' => $nodeId, 'installation_id' => $this->id($nodeId === 2101 ? 1 : $nodeId),
                'version' => 'v1.2.3', 'os' => 'linux', 'arch' => 'amd64',
                'last_seen_at' => '2026-10-31 12:00:00', 'auto_enroll_supported' => false];
        }
        foreach (array_chunk($nodes, 100) as $chunk) DB::table('v2_server')->insert($chunk);
        foreach (array_chunk($discoveries, 100) as $chunk) DB::table('v2_node_update_discoveries')->insert($chunk);
        $this->installation($this->id(1), ['kind' => 'legacy', 'node_ids' => [1]]);
        $this->installation($this->id(21), ['kind' => 'legacy', 'node_ids' => [21]]);
        DB::enableQueryLog();
        $first = $this->service->listing([]);
        $discoveryQueries = array_filter(DB::getQueryLog(), fn ($query) => str_contains($query['query'], 'v2_node_update_discoveries'));
        DB::disableQueryLog();
        self::assertCount(1, $discoveryQueries);
        self::assertSame(2100, $first['total']);
        self::assertCount(20, $first['items']);
        self::assertSame([1, 2101], array_column($first['items'][0]['discoveries'], 'node_id'));
        self::assertNotNull($first['items'][0]['installation']);
        $second = $this->service->listing(['page' => '2']);
        self::assertSame(2100, $second['total']);
        self::assertSame($this->id(21), $second['items'][0]['installation_id']);
        self::assertNotNull($second['items'][0]['installation']);
        $twentyFirst = $this->service->listing(['page' => 21]);
        self::assertSame($this->id(401), $twentyFirst['items'][0]['installation_id']);
        self::assertSame(2100, $twentyFirst['total']);
        $empty = $this->service->listing(['page' => 106]);
        self::assertSame(2100, $empty['total']);
        self::assertSame([], $empty['items']);
        self::assertSame(1, $this->service->listing(['node_id' => 2101])['total']);
    }

    public function testOnlyCurrentPageUsesManagedDtosAndOverviewDoesNotWrite(): void
    {
        $managed = new class extends NodeUpdateService {
            public array $dtoIds = [];
            public function dto(string $type, NodeUpdateRecord $record): array
            {
                $this->dtoIds[] = $record->id;
                return parent::dto($type, $record);
            }
        };
        for ($id = 1; $id <= 25; $id++) {
            $this->node($id, 7);
            $this->installation($this->id($id), ['kind' => 'legacy', 'node_ids' => [$id]]);
        }
        $before = DB::table('v2_node_update_installations')->orderBy('id')->get()->toJson();
        DB::enableQueryLog();
        $page = (new NodeUpdateOverviewService($managed))->listing(['page' => 2]);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        self::assertSame(25, $page['total']);
        self::assertSame(array_map(fn ($id) => $this->id($id), range(21, 25)), $managed->dtoIds);
        self::assertSame($before, DB::table('v2_node_update_installations')->orderBy('id')->get()->toJson());
        foreach ($queries as $query) self::assertMatchesRegularExpression('/^select\b/i', $query['query']);
    }

    public function testStrictValidationRejectsUnknownFieldsAndMalformedIntegerFilters(): void
    {
        foreach ([['unknown' => 1], ['page' => '1.2'], ['page' => 0], ['page' => 1000001],
            ['page_size' => 101], ['page_size' => true], ['page_size' => '1e2'], ['node_id' => []],
            ['node_id' => null], ['node_id' => 1.0], ['machine_id' => '0'], ['machine_id' => '-1'],
            ['machine_id' => '9007199254740992'], ['capability_status' => 'online'],
            ['capability_status' => null], ['capability_status' => ['supported']]] as $input) {
            try {
                $this->service->listing($input);
                self::fail('Expected validation failure for '.json_encode($input));
            } catch (NodeUpdateException $error) {
                self::assertSame('validation_failed', $error->errorCode);
                self::assertSame(422, $error->status);
            }
        }
        $page = $this->service->listing(['page' => '1000000', 'page_size' => '100', 'node_id' => '9007199254740991']);
        self::assertSame(1000000, $page['page']);
        self::assertSame(100, $page['page_size']);
        self::assertSame(0, $page['total']);
    }

    public function testControllerOverviewUsesReadServiceAndKeepsAdminCheck(): void
    {
        $this->discover($this->node(328), $this->id(1));
        $admin = (object) ['id' => 1, 'is_admin' => true];
        Auth::swap(\Mockery::mock());
        Auth::shouldReceive('guard')->with('sanctum')->andReturnSelf();
        Auth::shouldReceive('user')->andReturnUsing(fn () => $admin);
        $managed = \Mockery::mock(NodeUpdateService::class);
        $managed->shouldNotReceive('admin');
        $managed->shouldReceive('q')->with('installations')->andReturnUsing(fn () => $this->managed->q('installations'));
        $controller = new NodeUpdateController($managed);
        $request = Request::create('http://localhost/api/v2/admin/server/update/overview', 'GET');
        $response = $controller->handle($request);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $response->getData(true)['data']['total']);
        $bad = Request::create('http://localhost/api/v2/admin/server/update/overview?unknown=1', 'GET');
        self::assertSame(422, $controller->handle($bad)->getStatusCode());
        $admin->is_admin = false;
        self::assertSame(403, $controller->handle($request)->getStatusCode());
    }
}
