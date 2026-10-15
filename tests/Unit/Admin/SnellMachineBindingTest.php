<?php

namespace Tests\Unit\Admin;

use App\Http\Controllers\V2\Admin\Server\ManageController;
use App\Models\Server;
use App\Services\ServerReconnectCooldownService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory as ResponseFactoryContract;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\TestCase;

class SnellMachineBindingTest extends TestCase
{
    private Container $app;
    private Manager $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = new Container();
        Container::setInstance($this->app);
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        \Illuminate\Support\Facades\Facade::setFacadeApplication($this->app);
        $this->app->instance('config', new Repository(['app' => ['timezone' => 'UTC']]));
        $this->app->instance('validator', new Factory(new Translator(new ArrayLoader(), 'en'), $this->app));
        $this->app->instance(ResponseFactoryContract::class, new ResponseFactory(
            Mockery::mock(ViewFactory::class), Mockery::mock(Redirector::class)
        ));
        $this->db = new Manager($this->app);
        $this->db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->db->setAsGlobal();
        $this->db->bootEloquent();
        Model::clearBootedModels();
        $this->db->schema()->create('v2_server', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('type');
            $table->integer('machine_id')->nullable();
            $table->boolean('auto_online')->default(false);
            $table->boolean('auto_online_cooldown_enabled')->default(false);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->db->getDatabaseManager()->disconnect();
        Model::clearBootedModels();
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        \Illuminate\Support\Facades\Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    public function test_single_update_keeps_snell_out_of_existing_machine_agents(): void
    {
        $node = Server::create(['type' => 'snell']);
        try {
            (new ManageController())->update($this->request(['id' => $node->id, 'machine_id' => 7]));
            self::fail('Expected a machine binding validation error');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('machine_id', $e->errors());
        }
        self::assertNull($node->fresh()->machine_id);
    }

    public function test_batch_update_rejects_mixed_selection_before_any_assignment(): void
    {
        $snell = Server::create(['type' => 'snell']);
        $vless = Server::create(['type' => 'vless']);
        try {
            (new ManageController())->batchUpdate($this->request([
                'ids' => [$snell->id, $vless->id], 'machine_id' => 7,
            ]));
            self::fail('Expected a machine binding validation error');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('machine_id', $e->errors());
        }
        self::assertNull($snell->fresh()->machine_id);
        self::assertNull($vless->fresh()->machine_id);
    }

    public function test_other_protocol_machine_assignment_still_works(): void
    {
        $node = Server::create(['type' => 'vless']);
        $cooldown = Mockery::mock(ServerReconnectCooldownService::class);
        $cooldown->shouldReceive('reset')->once();
        $this->app->instance(ServerReconnectCooldownService::class, $cooldown);
        $response = (new ManageController())->update($this->request(['id' => $node->id, 'machine_id' => 7]));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(7, $node->fresh()->machine_id);
    }

    private function request(array $payload): Request
    {
        return new class([], $payload, [], [], [], ['REQUEST_METHOD' => 'POST']) extends Request {
            public function validate(array $rules): array
            {
                return app('validator')->make($this->all(), $rules)->validate();
            }
        };
    }
}
