<?php

namespace Tests\Unit\Admin;

use App\Http\Controllers\V2\Admin\UserController;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Query\Builder as QueryBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class UserControllerActivityStatusFilterTest extends TestCase
{
    private static ?Capsule $capsule = null;

    public function test_resolve_activity_status_value_supports_eq_payloads(): void
    {
        $controller = new UserController();
        $method = new ReflectionMethod(UserController::class, 'resolveActivityStatusValue');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($controller, 'eq:1'));
        $this->assertFalse($method->invoke($controller, 'eq:0'));
        $this->assertNull($method->invoke($controller, 'gte:1'));
    }

    public function test_active_activity_status_filter_requires_plan_remaining_traffic_and_recent_online(): void
    {
        $builder = $this->newQueryBuilder();

        $this->applyFilter($builder, 'activity_status', 'eq:1');

        $sql = $builder->toSql();

        $this->assertStringContainsString('"plan_id" is not null', $sql);
        $this->assertStringContainsString('COALESCE(transfer_enable, 0) > COALESCE(u, 0) + COALESCE(d, 0)', $sql);
        $this->assertStringContainsString('"last_online_at" is not null', $sql);
        $this->assertStringContainsString('"last_online_at" >= ?', $sql);
        $this->assertCount(1, $builder->getBindings());
    }

    public function test_inactive_activity_status_filter_uses_reverse_condition_set(): void
    {
        $builder = $this->newQueryBuilder();

        $this->applyFilter($builder, 'activity_status', 'eq:0');

        $sql = $builder->toSql();

        $this->assertStringContainsString('("plan_id" is null or COALESCE(transfer_enable, 0) <= COALESCE(u, 0) + COALESCE(d, 0) or "last_online_at" is null or "last_online_at" < ?)', $sql);
        $this->assertCount(1, $builder->getBindings());
    }

    private function applyFilter(QueryBuilder $builder, string $field, mixed $value): void
    {
        $controller = new UserController();
        $method = new ReflectionMethod(UserController::class, 'buildFilterQuery');
        $method->setAccessible(true);
        $method->invoke($controller, $builder, $field, $value);
    }

    private function newQueryBuilder(): QueryBuilder
    {
        if (!self::$capsule) {
            $capsule = new Capsule();
            $capsule->addConnection([
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ]);
            $capsule->setAsGlobal();
            $capsule->bootEloquent();
            self::$capsule = $capsule;
        }

        return self::$capsule->getConnection()->table('v2_user');
    }
}
