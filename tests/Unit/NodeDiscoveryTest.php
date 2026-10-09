<?php

namespace Tests\Unit;

use App\Http\Controllers\V2\Admin\NodeUpdateController;
use App\Http\Controllers\V2\Server\ServerController;
use App\Jobs\TrafficBillingJob;
use App\Models\Server;
use App\Services\NodeUpdate\NodeDiscoveryService;
use App\Services\NodeUpdate\NodeUpdateService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

class NodeDiscoveryTest extends TestCase
{
    private Manager $db;
    private Container $app;
    private NodeDiscoveryService $service;

    protected function setUp(): void
    {
        $this->app = new Container();
        Container::setInstance($this->app);
        $this->app->instance('app', $this->app);
        $this->app->instance('config', new Repository(['app' => ['timezone' => 'Asia/Shanghai']]));
        $this->db = new Manager($this->app);
        $this->db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->db->setAsGlobal();
        $this->db->bootEloquent();
        Model::clearBootedModels();
        $this->app->instance('db', $this->db->getDatabaseManager());
        $this->app->bind('db.schema', fn () => $this->db->schema());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        Carbon::setTestNow(Carbon::parse('2026-08-30T16:00:00+08:00'));
        $this->db->schema()->create('v2_server', function (Blueprint $t) {
            $t->increments('id');
            $t->string('name');
            $t->unsignedInteger('machine_id')->nullable();
        });
        (require __DIR__.'/../../database/migrations/2026_08_30_000001_create_node_update_discoveries_table.php')->up();
        $this->service = new NodeDiscoveryService();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        \Mockery::close();
        Request::flushMacros();
        $this->db->getDatabaseManager()->disconnect();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Model::clearBootedModels();
        Container::setInstance(null);
        parent::tearDown();
    }

    private function node(int $id = 1): Server
    {
        DB::table('v2_server')->insertOrIgnore(['id' => $id, 'name' => 'Node '.$id, 'machine_id' => 7]);
        $node = new Server();
        $node->setRawAttributes(['id' => $id, 'type' => 'vmess', 'rate' => 1, 'auto_online' => false]);
        $node->setAppends([]);
        return $node;
    }

    private function inventory(): array
    {
        return ['installation_id' => '67369e8e-99cd-42bb-92f0-b2157e4fdfda',
            'version' => str_repeat('a', 40), 'os' => 'linux', 'arch' => 'amd64'];
    }

    public function testIdentityWhitelistAndIdempotentReplacement(): void
    {
        $node = $this->node();
        $this->service->report($node, $this->inventory() + ['node_id' => 99, 'machine_id' => 999, 'token' => 'secret']);
        $row = (array) DB::table('v2_node_update_discoveries')->sole();
        self::assertSame(['node_id', 'installation_id', 'version', 'os', 'arch', 'last_seen_at'], array_keys($row));
        self::assertSame(1, $row['node_id']);
        self::assertSame('2026-08-30 08:00:00', $row['last_seen_at']);
        Carbon::setTestNow(Carbon::parse('2026-08-30T09:00:00Z'));
        $next = array_replace($this->inventory(), ['installation_id' => '77369e8e-99cd-42bb-92f0-b2157e4fdfda', 'version' => str_repeat('b', 128), 'arch' => 'arm64']);
        $this->service->report($node, $next);
        self::assertSame(1, DB::table('v2_node_update_discoveries')->count());
        $item = $this->service->listing([])['items'][0];
        self::assertSame($next['version'], $item['version']);
        self::assertSame($next['installation_id'], $item['installation_id']);
        self::assertSame('arm64', $item['arch']);
        self::assertSame('2026-08-30T09:00:00Z', $item['last_seen_at']);
        self::assertSame(7, $item['machine_id']);
    }

    public function testMalformedInventoryDoesNotOverwriteLastValidRecord(): void
    {
        $node = $this->node();
        $this->service->report($node, $this->inventory());
        $before = (array) DB::table('v2_node_update_discoveries')->sole();
        $bad = [null, false, 12, 'bad', [], ['installation_id' => []]];
        foreach (['installation_id' => 'bad', 'version' => str_repeat('v', 129), 'os' => str_repeat('o', 33), 'arch' => str_repeat('a', 33)] as $key => $value) {
            $bad[] = array_replace($this->inventory(), [$key => $value]);
        }
        foreach (['version', 'os', 'arch'] as $key) {
            foreach ([null, [], 42, '', '  ', "bad\nvalue", "\xff"] as $value) {
                $bad[] = array_replace($this->inventory(), [$key => $value]);
            }
        }
        foreach ($bad as $inventory) {
            $this->service->report($node, $inventory);
            self::assertSame($before, (array) DB::table('v2_node_update_discoveries')->sole());
        }
    }

    public function testPaginationFiltersAndLiveServerAssociation(): void
    {
        for ($id = 1; $id <= 25; $id++) $this->service->report($this->node($id), $this->inventory());
        DB::table('v2_server')->where('id', 25)->delete();
        DB::table('v2_server')->where('id', 1)->update(['machine_id' => 8, 'name' => 'Renamed']);
        $first = $this->service->listing([]);
        self::assertSame(24, $first['total']);
        self::assertSame(20, $first['page_size']);
        self::assertCount(20, $first['items']);
        $second = $this->service->listing(['page' => '2']);
        self::assertCount(4, $second['items']);
        self::assertSame(21, $second['items'][0]['node_id']);
        self::assertSame(100, $this->service->listing(['page_size' => '999'])['page_size']);
        $filtered = $this->service->listing(['machine_id' => '8', 'node_id' => '1']);
        self::assertSame(1, $filtered['total']);
        self::assertSame('Renamed', $filtered['items'][0]['node_name']);
        self::assertSame(0, $this->service->listing(['node_id' => 25])['total']);
    }

    public function testAdminReadBypassesManagedUpdateTablesAndKeepsAdminCheck(): void
    {
        $this->service->report($this->node(), $this->inventory());
        $admin = (object) ['id' => 1, 'is_admin' => true];
        Auth::swap(\Mockery::mock());
        Auth::shouldReceive('guard')->with('sanctum')->andReturnSelf();
        Auth::shouldReceive('user')->andReturnUsing(fn () => $admin);
        $controller = new NodeUpdateController(new NodeUpdateService());
        $request = Request::create('http://localhost/api/v2/admin/server/update/discoveries', 'GET');
        $response = $controller->handle($request);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $response->getData(true)['data']['total']);
        $admin->is_admin = false;
        self::assertSame(403, $controller->handle($request)->getStatusCode());
    }

    public function testMissingTableAndBadMetadataPreserveTrafficDispatchAndAck(): void
    {
        $node = $this->node();
        $factory = new \Illuminate\Validation\Factory(new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader(), 'en'), $this->app);
        Request::macro('validate', function ($rules) use ($factory) { return $factory->make($this->all(), $rules)->validate(); });
        Cache::swap(\Mockery::mock());
        Cache::shouldReceive('put')->andReturn(true);
        $responseFactory = \Mockery::mock(\Illuminate\Contracts\Routing\ResponseFactory::class);
        $responseFactory->shouldReceive('json')->andReturnUsing(fn ($data) => new JsonResponse($data));
        $this->app->instance(\Illuminate\Contracts\Routing\ResponseFactory::class, $responseFactory);
        $dispatcher = \Mockery::mock(\Illuminate\Contracts\Bus\Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->times(4)->with(\Mockery::type(TrafficBillingJob::class));
        $this->app->instance(\Illuminate\Contracts\Bus\Dispatcher::class, $dispatcher);
        foreach ([$this->inventory() + ['node_id' => 999, 'machine_id' => 99], ['version' => []], $this->inventory(), null] as $index => $inventory) {
            if ($index === 2) $this->db->schema()->drop('v2_node_update_discoveries');
            $request = Request::create('http://localhost/api/v2/server/report', 'POST', [
                'node_id' => 999, 'traffic' => [1 => [10, 20]], 'report_id' => 'report-'.$index,
                'update_inventory' => $inventory,
            ]);
            $request->attributes->set('node_info', $node);
            $response = (new ServerController())->report($request);
            self::assertSame(200, $response->getStatusCode());
            self::assertSame(['data' => true], $response->getData(true));
            if ($index === 0) self::assertSame(1, DB::table('v2_node_update_discoveries')->sole()->node_id);
        }
    }
}
