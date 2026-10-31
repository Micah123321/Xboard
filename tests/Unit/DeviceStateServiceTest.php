<?php

namespace Tests\Unit;

use App\Services\DeviceStateService;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Redis;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DeviceStateServiceTest extends TestCase
{
    private Container $previousContainer;
    private mixed $previousFacadeApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $container = new Container();
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        Redis::swap(Mockery::mock());
    }

    protected function tearDown(): void
    {
        try {
            Mockery::close();
        } finally {
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($this->previousFacadeApplication);
            Container::setInstance($this->previousContainer);
            parent::tearDown();
        }
    }

    #[DataProvider('crossNodeDuplicates')]
    public function test_cross_node_duplicates_remain_json_lists(array $fields, array $expectedIps): void
    {
        Redis::shouldReceive('hgetall')->once()->with('user_devices:3401')
            ->andReturn(array_fill_keys($fields, (string) time()));

        $devices = (new DeviceStateService())->getUsersDevices([3401]);
        $payload = json_decode(json_encode(['users' => $devices], JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($payload->users->{'3401'});
        self::assertSame($expectedIps, $payload->users->{'3401'});
    }

    public static function crossNodeDuplicates(): array
    {
        return [
            'duplicates near the start' => [
                ['1:198.51.100.10', '2:198.51.100.10', '1:2001:db8::1', '2:2001:db8::1', '3:203.0.113.20'],
                ['198.51.100.10', '2001:db8::1', '203.0.113.20'],
            ],
            'duplicates in the middle' => [
                ['1:198.51.100.10', '1:2001:db8::1', '2:2001:db8::1', '2:198.51.100.10', '3:203.0.113.20'],
                ['198.51.100.10', '2001:db8::1', '203.0.113.20'],
            ],
        ];
    }

    public function test_expired_records_and_inactive_users_are_omitted(): void
    {
        $now = time();
        Redis::shouldReceive('hgetall')->once()->with('user_devices:3401')->andReturn([
            '1:192.0.2.99' => (string) ($now - 3600),
            '2:198.51.100.10' => (string) ($now - 60),
            '3:2001:db8::1' => (string) ($now - 60),
        ]);
        Redis::shouldReceive('hgetall')->once()->with('user_devices:3402')
            ->andReturn(['1:192.0.2.2' => (string) ($now - 3600)]);
        Redis::shouldReceive('hgetall')->once()->with('user_devices:3403')->andReturn([]);
        Redis::shouldReceive('hgetall')->once()->with('user_devices:3404')
            ->andReturn(['4:2001:db8::4' => (string) $now]);

        $expected = [3401 => ['198.51.100.10', '2001:db8::1'], 3404 => ['2001:db8::4']];
        $devices = (new DeviceStateService())->getUsersDevices([3401, 3402, 3403, 3404]);
        self::assertSame($expected, $devices);

        $payload = json_decode(json_encode(['users' => $devices], JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        foreach ($expected as $userId => $ips) {
            self::assertIsArray($payload->users->{(string) $userId});
            self::assertSame($ips, $payload->users->{(string) $userId});
        }
    }
}
