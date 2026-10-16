<?php

namespace Tests\Unit;

use App\Models\Server;
use App\Services\AccessTrafficService;
use App\Services\ServerService;
use App\Services\ServerTrafficLimitService;
use Illuminate\Container\Container;
use Mockery;
use PHPUnit\Framework\TestCase;

class ServerProtocolDefaultsTest extends TestCase
{
    private Container $previousContainer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $app = new Container();
        Container::setInstance($app);
        $traffic = Mockery::mock(ServerTrafficLimitService::class);
        $traffic->shouldReceive('buildNodeConfig')->andReturn(['enabled' => false]);
        $access = Mockery::mock(AccessTrafficService::class);
        $access->shouldReceive('nodeConfig')->andReturn(['enabled' => false]);
        $app->instance(ServerTrafficLimitService::class, $traffic);
        $app->instance(AccessTrafficService::class, $access);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function test_tuic_server_alpn_matches_the_client_template(): void
    {
        foreach ([['h3'], ['custom-quic'], ['custom-quic', 'h3']] as $alpn) {
            $config = ServerService::buildNodeConfig($this->node($alpn));
            $this->assertSame($alpn, $config['tls_settings']['alpn']);
            $this->assertSame('node.test', $config['tls_settings']['server_name']);
            $this->assertFalse($config['tls_settings']['allow_insecure']);
        }
    }

    public function test_tuic_missing_or_empty_alpn_defaults_to_h3(): void
    {
        foreach ([null, []] as $alpn) {
            $config = ServerService::buildNodeConfig($this->node($alpn));
            $this->assertSame(['h3'], $config['tls_settings']['alpn']);
        }
    }

    private function node(?array $alpn): Server
    {
        $settings = [
            'version' => 5,
            'congestion_control' => 'cubic',
            'tls' => ['server_name' => 'node.test', 'allow_insecure' => false],
        ];
        if ($alpn !== null) {
            $settings['alpn'] = $alpn;
        }
        $node = new Server();
        $node->setRawAttributes([
            'id' => 7,
            'type' => 'tuic',
            'host' => 'node.test',
            'server_port' => 443,
            'protocol_settings' => json_encode($settings),
        ]);
        return $node;
    }
}
