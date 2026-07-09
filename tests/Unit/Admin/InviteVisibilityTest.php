<?php

namespace Tests\Unit\Admin;

use App\Http\Controllers\V1\User\InviteController;
use App\Http\Controllers\V2\Admin\UserController;
use App\Models\InviteCode;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class InviteVisibilityTest extends TestCase
{
    public function test_user_invite_controller_exposes_users_method(): void
    {
        $this->assertTrue(method_exists(InviteController::class, 'users'));
        $method = new ReflectionMethod(InviteController::class, 'users');
        $this->assertSame('users', $method->getName());
        $this->assertTrue($method->isPublic());
    }

    public function test_admin_user_controller_exposes_invite_info_method(): void
    {
        $this->assertTrue(method_exists(UserController::class, 'inviteInfo'));
        $method = new ReflectionMethod(UserController::class, 'inviteInfo');
        $this->assertSame('inviteInfo', $method->getName());
        $this->assertTrue($method->isPublic());
    }

    public function test_invite_info_method_accepts_request_parameter(): void
    {
        $method = new ReflectionMethod(UserController::class, 'inviteInfo');
        $params = $method->getParameters();

        $this->assertCount(1, $params);
        $this->assertSame(Request::class, $params[0]->getType()?->getName());
    }

    public function test_user_invite_users_method_accepts_request_parameter(): void
    {
        $method = new ReflectionMethod(InviteController::class, 'users');
        $params = $method->getParameters();

        $this->assertCount(1, $params);
        $this->assertSame(Request::class, $params[0]->getType()?->getName());
    }

    public function test_invite_code_and_order_models_support_invite_relations(): void
    {
        $this->assertTrue(class_exists(InviteCode::class));
        $this->assertTrue(class_exists(Order::class));
        $this->assertTrue(method_exists(Order::class, 'user'));
        $this->assertTrue(method_exists(Order::class, 'invite_user'));
        $this->assertTrue(method_exists(User::class, 'codes'));
    }

    public function test_admin_route_file_registers_invite_info(): void
    {
        $routeFile = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Http' . DIRECTORY_SEPARATOR . 'Routes' . DIRECTORY_SEPARATOR . 'V2' . DIRECTORY_SEPARATOR . 'AdminRoute.php';
        $this->assertFileExists($routeFile);
        $content = file_get_contents($routeFile);
        $this->assertIsString($content);
        $this->assertStringContainsString("'/inviteInfo'", $content);
        $this->assertStringContainsString("'inviteInfo'", $content);
    }

    public function test_user_route_file_registers_invite_users(): void
    {
        $routeFile = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Http' . DIRECTORY_SEPARATOR . 'Routes' . DIRECTORY_SEPARATOR . 'V1' . DIRECTORY_SEPARATOR . 'UserRoute.php';
        $this->assertFileExists($routeFile);
        $content = file_get_contents($routeFile);
        $this->assertIsString($content);
        $this->assertStringContainsString("'/invite/users'", $content);
        $this->assertStringContainsString("'users'", $content);
    }
}
