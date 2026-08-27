<?php

namespace Tests\Unit\Protocols;

use App\Models\Server;
use App\Protocols\SingBox;
use App\Support\AbstractProtocol;
use Illuminate\Support\Facades\Log;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class SingBoxOutboundTagFilterTest extends TestCase
{
    public function test_selector_include_exclude_and_fallback_are_applied_to_generated_tags(): void
    {
        Log::spy();

        $outbounds = $this->buildOutbounds([
            ['type' => 'selector', 'tag' => 'Auto', 'outbounds' => [], 'include' => 'HK|SG', 'exclude' => 'SG'],
            ['type' => 'urltest', 'tag' => 'FallbackDirect', 'outbounds' => [], 'include' => 'NoMatch', 'fallback' => 'direct'],
            ['type' => 'selector', 'tag' => 'InvalidPattern', 'outbounds' => [], 'include' => '/[/', 'fallback' => 'direct'],
            ['type' => 'direct', 'tag' => 'direct'],
        ]);

        $this->assertSame(['HK 01'], $outbounds[0]['outbounds']);
        $this->assertArrayNotHasKey('include', $outbounds[0]);
        $this->assertArrayNotHasKey('exclude', $outbounds[0]);

        $this->assertSame(['direct'], $outbounds[1]['outbounds']);
        $this->assertSame(['direct'], $outbounds[2]['outbounds']);
        $this->assertSame('HK 01', $outbounds[4]['tag']);
        $this->assertSame('SG 02', $outbounds[5]['tag']);
    }

    private function buildOutbounds(array $templateOutbounds): array
    {
        $class = new ReflectionClass(SingBox::class);
        /** @var SingBox $protocol */
        $protocol = $class->newInstanceWithoutConstructor();

        $this->setProperty(AbstractProtocol::class, $protocol, 'user', ['uuid' => '00000000-0000-0000-0000-000000000001']);
        $this->setProperty(AbstractProtocol::class, $protocol, 'servers', [
            $this->server('HK 01', 'hk.example.com'),
            $this->server('SG 02', 'sg.example.com'),
        ]);
        $this->setProperty(SingBox::class, $protocol, 'config', [
            'outbounds' => $templateOutbounds,
            'route' => ['rules' => []],
        ]);

        $method = new ReflectionMethod(SingBox::class, 'buildOutbounds');
        $method->setAccessible(true);

        return $method->invoke($protocol);
    }

    private function setProperty(string $className, object $object, string $propertyName, mixed $value): void
    {
        $property = new ReflectionProperty($className, $propertyName);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }

    private function server(string $name, string $host): array
    {
        return [
            'type' => Server::TYPE_SHADOWSOCKS,
            'name' => $name,
            'host' => $host,
            'port' => 8388,
            'password' => 'secret',
            'protocol_settings' => [
                'cipher' => 'aes-128-gcm',
            ],
        ];
    }
}
