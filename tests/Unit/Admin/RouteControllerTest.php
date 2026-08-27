<?php

namespace Tests\Unit\Admin;

use App\Models\ServerRoute;
use App\Models\User;
use App\Support\Setting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use ReflectionProperty;
use Tests\TestCase;

class RouteControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $securePath;

    protected function setUp(): void
    {
        parent::setUp();

        Model::unguard();
        config(['cache.default' => 'array']);

        $setting = new Setting();
        $cacheProp = new ReflectionProperty(Setting::class, 'cache');
        $cacheProp->setValue($setting, Cache::store('array'));
        $this->app->instance(Setting::class, $setting);

        $this->securePath = hash('crc32b', config('app.key'));

        $admin = User::create([
            'email' => 'admin-route@test.local',
            'password' => bcrypt('secret'),
            'is_admin' => true,
            'uuid' => 'admin-route-test-uuid',
            'token' => 'admin-route-test-token',
        ]);

        $this->actingAs($admin, 'sanctum');
    }

    public function test_fetch_routes_uses_cache_after_first_request(): void
    {
        ServerRoute::create([
            'remarks' => 'Route A',
            'match' => ['domain:example.com'],
            'action' => 'proxy',
            'action_value' => null,
        ]);
        Cache::forget(ServerRoute::FETCH_CACHE_KEY);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $first = $this->getJson($this->url('/server/route/fetch'));
        $first->assertOk();
        $first->assertJsonPath('data.0.remarks', 'Route A');
        $first->assertJsonPath('data.0.match.0', 'domain:example.com');
        $this->assertGreaterThanOrEqual(1, $this->routeQueryCount());

        DB::flushQueryLog();

        $second = $this->getJson($this->url('/server/route/fetch'));
        $second->assertOk();
        $second->assertJsonPath('data.0.remarks', 'Route A');
        $this->assertSame(0, $this->routeQueryCount());
    }

    public function test_fetch_routes_cache_is_flushed_when_route_changes(): void
    {
        $route = ServerRoute::create([
            'remarks' => 'Route A',
            'match' => ['domain:example.com'],
            'action' => 'proxy',
            'action_value' => null,
        ]);
        Cache::forget(ServerRoute::FETCH_CACHE_KEY);

        $this->getJson($this->url('/server/route/fetch'))->assertOk();

        $route->update(['remarks' => 'Route B']);
        DB::enableQueryLog();
        DB::flushQueryLog();

        $response = $this->getJson($this->url('/server/route/fetch'));
        $response->assertOk();
        $response->assertJsonPath('data.0.remarks', 'Route B');
        $this->assertGreaterThanOrEqual(1, $this->routeQueryCount());
    }

    private function url(string $path): string
    {
        return '/api/v2/' . $this->securePath . $path;
    }

    private function routeQueryCount(): int
    {
        return collect(DB::getQueryLog())->filter(
            fn (array $log) => isset($log['query'])
                && str_contains((string) $log['query'], 'v2_server_route')
        )->count();
    }
}
