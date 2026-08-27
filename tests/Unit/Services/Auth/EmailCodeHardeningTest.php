<?php

namespace Tests\Unit\Services\Auth;

use App\Http\Requests\Passport\AuthForget;
use App\Services\Auth\LoginService;
use App\Services\Auth\RegisterService;
use App\Services\CaptchaService;
use App\Support\Setting;
use App\Utils\CacheKey;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;

class EmailCodeHardeningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container();
        $app->instance('config', new ConfigRepository(['v2board' => []]));
        $app->instance('cache', new CacheRepository(new ArrayStore()));
        $app->instance('translator', new Translator(new ArrayLoader(), 'en'));
        $app->instance(Setting::class, new class {
            public function get(string $key, mixed $default = null): mixed
            {
                return [
                    'email_verify' => 1,
                    'register_limit_by_ip_enable' => 0,
                    'email_whitelist_enable' => 0,
                    'email_gmail_limit_enable' => 0,
                    'stop_register' => 0,
                    'invite_force' => 0,
                ][$key] ?? $default;
            }
        });
        $app->instance(CaptchaService::class, new class extends CaptchaService {
            public function verify(Request $request): array
            {
                return [true, null];
            }
        });

        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        parent::tearDown();
    }

    public function test_password_reset_rejects_missing_cached_email_code(): void
    {
        [$success, $result] = (new LoginService())->resetPassword('victim@example.com', '', 'new-password');

        $this->assertFalse($success);
        $this->assertSame(400, $result[0]);
        $this->assertSame(1, Cache::get(CacheKey::get('FORGET_REQUEST_LIMIT', 'victim@example.com')));
    }

    public function test_password_reset_accepts_only_matching_cached_code(): void
    {
        Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', 'victim@example.com'), 123456, 300);

        [$success, $result] = (new LoginService())->resetPassword('victim@example.com', '000000', 'new-password');

        $this->assertFalse($success);
        $this->assertSame(400, $result[0]);
    }

    public function test_password_reset_rejects_falsy_cached_email_code(): void
    {
        Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', 'victim@example.com'), false, 300);

        [$success, $result] = (new LoginService())->resetPassword('victim@example.com', '', 'new-password');

        $this->assertFalse($success);
        $this->assertSame(400, $result[0]);
    }

    public function test_auth_forget_requires_six_digit_email_code(): void
    {
        $rules = (new AuthForget())->rules();

        $this->assertSame('required|digits:6', $rules['email_code']);
    }

    public function test_registration_rejects_invalid_or_missing_cached_email_code(): void
    {
        $service = new RegisterService();

        [$invalidSuccess, $invalidResult] = $service->validateRegister($this->registerRequest('user@example.com', 'abc123'));
        $this->assertFalse($invalidSuccess);
        $this->assertSame(422, $invalidResult[0]);

        [$missingSuccess, $missingResult] = $service->validateRegister($this->registerRequest('user@example.com', '123456'));
        $this->assertFalse($missingSuccess);
        $this->assertSame(400, $missingResult[0]);

        Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', 'user@example.com'), [], 300);
        [$badCacheSuccess, $badCacheResult] = $service->validateRegister($this->registerRequest('user@example.com', '123456'));
        $this->assertFalse($badCacheSuccess);
        $this->assertSame(400, $badCacheResult[0]);
    }

    private function registerRequest(string $email, string $emailCode): Request
    {
        return Request::create('/register', 'POST', [
            'email' => $email,
            'email_code' => $emailCode,
        ], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
    }
}
