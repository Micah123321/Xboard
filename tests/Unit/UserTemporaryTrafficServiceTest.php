<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\UserService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class UserTemporaryTrafficServiceTest extends TestCase
{
    public function test_add_temporary_traffic_increases_total_and_temporary_transfer(): void
    {
        $user = $this->makeUser([
            'transfer_enable' => 500 * 1024 ** 3,
            'temporary_transfer_enable' => 0,
        ]);

        (new UserService())->addTemporaryTraffic($user, 50 * 1024 ** 3);

        $this->assertSame(550 * 1024 ** 3, $user->transfer_enable);
        $this->assertSame(50 * 1024 ** 3, $user->temporary_transfer_enable);
    }

    public function test_add_temporary_traffic_accumulates_existing_temporary_transfer(): void
    {
        $user = $this->makeUser([
            'transfer_enable' => 550 * 1024 ** 3,
            'temporary_transfer_enable' => 50 * 1024 ** 3,
        ]);

        (new UserService())->addTemporaryTraffic($user, 25 * 1024 ** 3);

        $this->assertSame(575 * 1024 ** 3, $user->transfer_enable);
        $this->assertSame(75 * 1024 ** 3, $user->temporary_transfer_enable);
    }

    public function test_add_temporary_traffic_rejects_non_positive_bytes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new UserService())->addTemporaryTraffic($this->makeUser(), 0);
    }

    private function makeUser(array $attributes = []): User
    {
        $user = new User();
        $user->setRawAttributes($attributes, true);

        return $user;
    }
}
