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
        (require __DIR__.'/../../database/migrations/2026_10_12_000002_add_node_auto_enrollment_capability.php')->up();
        Cache::swap(new \Illuminate\Cache\Repository(new \Illuminate\Cache\ArrayStore()));
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
        self::assertSame(['node_id', 'installation_id', 'version', 'os', 'arch', 'last_seen_at', 'auto_enroll_supported'], array_keys($row));
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

    public function testAutomaticEnrollmentCapabilityIsTypedAndKeepsOldNodesVisible(): void
    {
        $node = $this->node();
        $this->service->report($node, $this->inventory());
        self::assertFalse($this->service->listing([])['items'][0]['auto_enroll_supported']);
        $this->service->report($node, $this->inventory() + ['auto_enroll_supported'=>true]);
        self::assertTrue($this->service->listing([])['items'][0]['auto_enroll_supported']);
        foreach (['true', 1, [], null] as $invalid) {
            $this->service->report($node, $this->inventory() + ['auto_enroll_supported'=>$invalid]);
            self::assertTrue($this->service->listing([])['items'][0]['auto_enroll_supported']);
        }
        $this->service->report($node, $this->inventory() + ['auto_enroll_supported'=>false]);
        self::assertFalse($this->service->listing([])['items'][0]['auto_enroll_supported']);
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

    public function testEnrollmentFailureDiagnosticsAreWhitelistedVersionScopedAndExpire(): void
    {
        $node = $this->node();
        $status = ['state'=>'retrying','stage'=>'enroll','code'=>'invalid_credentials','updated_at'=>'2026-08-30T08:00:00Z'];
        $inventory = $this->inventory() + ['auto_enroll_supported'=>true,'enrollment_status'=>$status];
        $this->service->report($node, $inventory);
        self::assertSame($status, $this->service->listing([])['items'][0]['enrollment_status']);
        foreach ([array_replace($status,['code'=>'token=private-value']), $status + ['token'=>'private-value'],
            array_replace($status,['state'=>'bad']), array_replace($status,['updated_at'=>'2026-02-30T08:00:00Z'])] as $invalid) {
            $this->service->report($node, array_replace($inventory,['enrollment_status'=>$invalid]));
            self::assertSame($status, $this->service->listing([])['items'][0]['enrollment_status']);
        }
        self::assertStringNotContainsString('private-value', json_encode($this->service->listing([])));
        $this->service->report($node, array_replace($inventory,['version'=>'v1.2.3','enrollment_status'=>null]));
        self::assertNull($this->service->listing([])['items'][0]['enrollment_status']);
        $this->service->report($node, $inventory);
        Carbon::setTestNow(Carbon::parse('2026-08-30T08:05:01Z'));
        self::assertNull($this->service->listing([])['items'][0]['enrollment_status']);
        self::assertSame($inventory['version'], $this->service->listing([])['items'][0]['version']);
        $completed=array_replace($status,['state'=>'registered','stage'=>'poll','code'=>'']);
        $this->service->report($node,array_replace($inventory,['enrollment_status'=>$completed]));
        self::assertSame(array_replace($completed,['code'=>null]),$this->service->listing([])['items'][0]['enrollment_status']);
    }

    public function testDelayedEnrollmentReportsCannotReplaceNewerTerminalResults(): void
    {
        foreach (['registered','disabled'] as $index => $state) {
            $node = $this->node($index + 1);
            $completed = ['state'=>$state,'stage'=>'enroll','code'=>null,'updated_at'=>'2026-08-30T08:00:12Z'];
            $inventory = $this->inventory() + ['enrollment_status'=>$completed];
            $this->service->report($node, $inventory);
            foreach (['starting','retrying'] as $delayed) {
                $old = ['state'=>$delayed,'stage'=>'bootstrap','code'=>'systemd_failed','updated_at'=>'2026-08-30T08:00:10Z'];
                $this->service->report($node, array_replace($inventory, ['enrollment_status'=>$old]));
                self::assertSame($completed, $this->service->listing(['node_id'=>$node->id])['items'][0]['enrollment_status']);
            }
        }
    }

    public function testSameSecondPreservesTerminalStateWhileLaterPollResultsCanAdvance(): void
    {
        $node = $this->node();
        $registered = ['state'=>'registered','stage'=>'poll','code'=>null,'updated_at'=>'2026-08-30T08:00:12Z'];
        $inventory = $this->inventory() + ['enrollment_status'=>$registered];
        $this->service->report($node, $inventory);
        $failure = array_replace($registered, ['state'=>'retrying','code'=>'api_unavailable']);
        $this->service->report($node, array_replace($inventory, ['enrollment_status'=>$failure]));
        self::assertSame($registered, $this->service->listing([])['items'][0]['enrollment_status']);
        $failure['updated_at'] = '2026-08-30T08:00:13Z';
        $this->service->report($node, array_replace($inventory, ['enrollment_status'=>$failure]));
        self::assertSame($failure, $this->service->listing([])['items'][0]['enrollment_status']);
        $recovered = array_replace($registered, ['updated_at'=>$failure['updated_at']]);
        $this->service->report($node, array_replace($inventory, ['enrollment_status'=>$recovered]));
        self::assertSame($recovered, $this->service->listing([])['items'][0]['enrollment_status']);
    }

    public function testDiagnosticsCacheFailurePreservesVersionReportAndListing(): void
    {
        Cache::swap(\Mockery::mock());
        Cache::shouldReceive('lock')->andThrow(new \RuntimeException('cache down'));
        Cache::shouldReceive('get')->andThrow(new \RuntimeException('cache down'));
        $inventory = $this->inventory() + ['enrollment_status'=>['state'=>'retrying','stage'=>'layout','code'=>'systemd_failed','updated_at'=>'2026-08-30T08:00:00Z']];
        $this->service->report($this->node(), $inventory);
        $item = $this->service->listing([])['items'][0];
        self::assertSame($inventory['version'], $item['version']);
        self::assertNull($item['enrollment_status']);
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
