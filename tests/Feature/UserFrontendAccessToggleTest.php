<?php

namespace Tests\Feature;

use App\Http\Middleware\InitializePlugins;
use App\Support\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class UserFrontendAccessToggleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(InitializePlugins::class);
        config()->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
        $this->bindSettings();
    }

    public function test_user_frontend_defaults_to_enabled(): void
    {
        $response = $this->postJson('/api/v1/passport/auth/login', []);

        $this->assertNotSame(404, $response->getStatusCode());
        $response->assertStatus(422);
    }

    public function test_disabled_frontend_hides_web_entry_without_site_title(): void
    {
        $this->setFrontendEnabled(false);

        $response = $this->get('/');

        $response->assertStatus(404);
        $response->assertContent('');
    }

    public function test_disabled_frontend_does_not_hide_user_api_routes(): void
    {
        $this->setFrontendEnabled(false);

        $this->postJson('/api/v1/passport/auth/login', [])->assertStatus(422);
        $this->getJson('/api/v1/user/info')->assertStatus(403);

        $this->assertRouteDoesNotUseFrontendGate('GET', '/s/example-token');
        $this->assertRouteDoesNotUseFrontendGate('GET', '/api/v1/guest/plan/fetch');
        $this->assertRouteDoesNotUseFrontendGate('GET', '/api/v1/guest/comm/config');
        $this->assertRouteDoesNotUseFrontendGate('GET', '/api/v2/client/app/getVersion');
    }

    public function test_disabled_frontend_does_not_hide_node_api(): void
    {
        $this->setFrontendEnabled(false);

        $response = $this->getJson('/api/v1/server/UniProxy/config?token=wrong-token&node_id=1&node_type=vmess');

        $this->assertNotSame(404, $response->getStatusCode());
        $response->assertStatus(422);
    }

    private function setFrontendEnabled(bool $enabled): void
    {
        $this->bindSettings([
            'frontend_enable' => $enabled ? 1 : 0,
        ]);
    }

    private function assertRouteDoesNotUseFrontendGate(string $method, string $uri): void
    {
        $route = Route::getRoutes()->match(Request::create($uri, $method));

        $this->assertNotContains('user.frontend', $route->gatherMiddleware());
    }

    private function bindSettings(array $settings = []): void
    {
        $settings = array_change_key_case(array_merge([
            'server_token' => 'server-token',
            'subscribe_path' => 's',
        ], $settings), CASE_LOWER);

        $this->app->instance(Setting::class, new class($settings) extends Setting {
            public function __construct(private array $settings)
            {
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->settings[strtolower($key)] ?? $default;
            }

            public function save(array $settings): bool
            {
                $this->settings = array_change_key_case($settings, CASE_LOWER) + $this->settings;
                return true;
            }
        });
    }
}
