<?php

namespace Tests\Unit\Admin;

use App\Http\Controllers\V2\Admin\PlanController;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

class PlanControllerTest extends TestCase
{
    private static ?Capsule $capsule = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDatabase();
        $schema = self::$capsule->schema();
        $schema->dropIfExists('v2_order');
        $schema->dropIfExists('v2_user');
        $schema->dropIfExists('v2_plan');

        $schema->create('v2_plan', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->integer('group_id')->nullable();
            $table->integer('transfer_enable')->default(0);
            $table->integer('speed_limit')->nullable();
            $table->boolean('show')->default(false);
            $table->integer('sort')->nullable();
            $table->boolean('renew')->default(true);
            $table->boolean('sell')->default(true);
            $table->text('content')->nullable();
            $table->text('prices')->nullable();
            $table->text('tags')->nullable();
            $table->integer('reset_traffic_method')->nullable();
            $table->integer('capacity_limit')->nullable();
            $table->integer('device_limit')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
        $schema->create('v2_user', function ($table) {
            $table->increments('id');
            $table->integer('plan_id')->nullable();
            $table->index('plan_id');
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
        $schema->create('v2_order', function ($table) {
            $table->increments('id');
            $table->integer('plan_id');
            $table->index('plan_id');
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }

    public function test_drop_moves_related_records_to_the_selected_replacement_plan(): void
    {
        $source = $this->createPlan('旧套餐', 1);
        $replacement = $this->createPlan('新套餐', 2);
        User::query()->insert(['plan_id' => $source->id]);
        Order::query()->insert(['plan_id' => $source->id]);

        $response = $this->controller()->drop(new TestPlanRequest([
            'id' => $source->id,
            'replacement_plan_id' => $replacement->id,
        ]));

        $this->assertSame('success', $response->getData(true)['status']);
        $this->assertNull(Plan::find($source->id));
        $this->assertSame($replacement->id, User::query()->value('plan_id'));
        $this->assertSame($replacement->id, Order::query()->value('plan_id'));
    }

    public function test_drop_rejects_the_source_plan_as_its_own_replacement_without_changes(): void
    {
        $source = $this->createPlan('旧套餐', 1);
        User::query()->insert(['plan_id' => $source->id]);
        Order::query()->insert(['plan_id' => $source->id]);

        $response = $this->controller()->drop(new TestPlanRequest([
            'id' => $source->id,
            'replacement_plan_id' => (string) $source->id,
        ]));

        $this->assertSame('fail', $response->getData(true)['status']);
        $this->assertNotNull(Plan::find($source->id));
        $this->assertSame($source->id, User::query()->value('plan_id'));
        $this->assertSame($source->id, Order::query()->value('plan_id'));
    }

    public function test_drop_requires_an_existing_replacement_when_related_records_exist(): void
    {
        $source = $this->createPlan('旧套餐', 1);
        User::query()->insert(['plan_id' => $source->id]);

        $missingReplacement = $this->controller()->drop(new TestPlanRequest([
            'id' => $source->id,
        ]));
        $this->assertSame('fail', $missingReplacement->getData(true)['status']);
        $this->assertNotNull(Plan::find($source->id));

        $unknownReplacement = $this->controller()->drop(new TestPlanRequest([
            'id' => $source->id,
            'replacement_plan_id' => 999,
        ]));
        $this->assertSame('fail', $unknownReplacement->getData(true)['status']);
        $this->assertNotNull(Plan::find($source->id));
    }

    public function test_drop_can_delete_a_plan_without_related_records_without_replacement(): void
    {
        $source = $this->createPlan('空套餐', 1);

        $response = $this->controller()->drop(new TestPlanRequest(['id' => $source->id]));

        $this->assertSame('success', $response->getData(true)['status']);
        $this->assertNull(Plan::find($source->id));
    }

    public function test_copy_creates_an_independent_plan_with_all_configuration_fields(): void
    {
        $source = Plan::query()->create([
            'name' => '专业版',
            'sort' => 3,
            'group_id' => 7,
            'transfer_enable' => 1024,
            'speed_limit' => 100,
            'show' => true,
            'renew' => false,
            'sell' => true,
            'content' => '套餐说明',
            'prices' => ['monthly' => 20],
            'tags' => ['热门'],
            'reset_traffic_method' => 1,
            'capacity_limit' => 20,
            'device_limit' => 3,
        ]);

        $response = $this->controller()->copy(new TestPlanRequest(['id' => $source->id]));
        $this->assertSame('success', $response->getData(true)['status']);
        $copy = Plan::query()->whereKeyNot($source->id)->firstOrFail();

        $this->assertSame('专业版--复制', $copy->name);
        $this->assertSame(4, $copy->sort);
        $this->assertSame($source->fresh()->only([
            'group_id', 'transfer_enable', 'speed_limit', 'show', 'renew', 'sell', 'content',
            'prices', 'tags', 'reset_traffic_method', 'capacity_limit', 'device_limit',
        ]), $copy->only([
            'group_id', 'transfer_enable', 'speed_limit', 'show', 'renew', 'sell', 'content',
            'prices', 'tags', 'reset_traffic_method', 'capacity_limit', 'device_limit',
        ]));
    }

    public function test_copy_appends_each_new_copy_after_the_current_last_sort_value(): void
    {
        $source = $this->createPlan('基础版', 4);

        $this->controller()->copy(new TestPlanRequest(['id' => $source->id]));
        $this->controller()->copy(new TestPlanRequest(['id' => $source->id]));

        $sorts = Plan::query()->orderBy('sort')->pluck('sort')->all();
        $this->assertSame([4, 5, 6], $sorts);
    }

    private function bootDatabase(): void
    {
        if (self::$capsule) {
            return;
        }

        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $container = new Container();
        $container->instance('db', $capsule->getDatabaseManager());
        Facade::setFacadeApplication($container);
        self::$capsule = $capsule;
    }

    private function createPlan(string $name, int $sort): Plan
    {
        return Plan::query()->create([
            'name' => $name,
            'sort' => $sort,
            'group_id' => 1,
            'transfer_enable' => 1024,
        ]);
    }

    private function controller(): TestPlanController
    {
        return new TestPlanController();
    }
}

class TestPlanController extends PlanController
{
    public function success($data = null, $codeResponse = null): JsonResponse
    {
        return new JsonResponse(['status' => 'success', 'data' => $data]);
    }

    public function fail($codeResponse = null, $data = null, $error = null): JsonResponse
    {
        return new JsonResponse(['status' => 'fail', 'code' => $codeResponse, 'data' => $data]);
    }
}

class TestPlanRequest extends Request
{
    public function __construct(array $payload)
    {
        parent::__construct([], $payload, [], [], [], ['REQUEST_METHOD' => 'POST']);
    }

    public function validate(array $rules, ...$parameters): array
    {
        return $this->all();
    }
}
