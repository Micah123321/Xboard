<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\TrafficResetService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class TrafficResetTemporaryTrafficTest extends TestCase
{
    public function test_perform_reset_uses_locked_user_state_when_passed_model_is_stale(): void
    {
        $staleUser = $this->makeUser([
            'id' => 1,
            'transfer_enable' => 500 * 1024 ** 3,
            'temporary_transfer_enable' => 0,
            'u' => 0,
            'd' => 0,
            'reset_count' => 1,
        ]);

        $lockedUser = $this->makeResettableUser([
            'id' => 1,
            'transfer_enable' => 550 * 1024 ** 3,
            'temporary_transfer_enable' => 50 * 1024 ** 3,
            'u' => 8,
            'd' => 12,
            'reset_count' => 9,
        ]);

        $service = new class($lockedUser) extends TrafficResetService {
            public array $logData = [];
            public User $hookUser;

            public function __construct(private readonly User $lockedUser)
            {
            }

            protected function runResetTransaction(callable $callback): mixed
            {
                return $callback();
            }

            protected function lockUserForReset(User $user): User
            {
                return $this->lockedUser;
            }

            protected function recordResetLog(User $user, array $data): void
            {
                $this->logData = $data;
            }

            protected function clearUserCache(User $user): void
            {
            }

            protected function dispatchAfterResetHook(User $user): void
            {
                $this->hookUser = $user;
            }
        };

        $this->assertTrue($service->performReset($staleUser));
        $this->assertSame(500 * 1024 ** 3, $lockedUser->transfer_enable);
        $this->assertSame(0, $lockedUser->temporary_transfer_enable);
        $this->assertSame(0, $lockedUser->u);
        $this->assertSame(0, $lockedUser->d);
        $this->assertSame(10, $lockedUser->reset_count);
        $this->assertSame(20, $service->logData['old_total']);
        $this->assertSame($lockedUser, $service->hookUser);
    }

    public function test_reset_transfer_enable_removes_temporary_transfer(): void
    {
        $user = $this->makeUser([
            'transfer_enable' => 550 * 1024 ** 3,
            'temporary_transfer_enable' => 50 * 1024 ** 3,
        ]);

        $this->assertSame(500 * 1024 ** 3, $this->resolveTransferEnableAfterReset($user));
    }

    public function test_reset_transfer_enable_never_returns_negative_total(): void
    {
        $user = $this->makeUser([
            'transfer_enable' => 20 * 1024 ** 3,
            'temporary_transfer_enable' => 50 * 1024 ** 3,
        ]);

        $this->assertSame(0, $this->resolveTransferEnableAfterReset($user));
    }

    private function resolveTransferEnableAfterReset(User $user): int
    {
        $method = new ReflectionMethod(TrafficResetService::class, 'resolveTransferEnableAfterReset');
        $method->setAccessible(true);

        return $method->invoke(new TrafficResetService(), $user);
    }

    private function makeUser(array $attributes = []): User
    {
        $user = new User();
        $user->setRawAttributes($attributes, true);

        return $user;
    }

    private function makeResettableUser(array $attributes = []): User
    {
        return new class($attributes) extends User {
            public function __construct(array $attributes = [])
            {
                parent::__construct();
                $this->setRawAttributes($attributes, true);
                $this->setRelation('plan', null);
            }

            public function update(array $attributes = [], array $options = []): bool
            {
                $this->forceFill($attributes);

                return true;
            }
        };
    }
}
