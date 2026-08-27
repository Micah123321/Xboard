<?php

namespace Tests\Unit\Protocols;

use App\Protocols\General;
use App\Protocols\Shadowrocket;
use PHPUnit\Framework\TestCase;

class UpstreamProtocolFixesTest extends TestCase
{
    public function test_general_vless_fragment_uses_raw_url_encoding(): void
    {
        $uri = General::buildVless('00000000-0000-0000-0000-000000000001', [
            'name' => 'Hong Kong+Node',
            'host' => 'example.com',
            'port' => 443,
            'protocol_settings' => [
                'tls' => 0,
                'network' => 'tcp',
                'encryption' => ['enabled' => false],
            ],
        ]);

        $this->assertStringContainsString('#Hong%20Kong%2BNode', $uri);
        $this->assertStringNotContainsString('#Hong+Kong%2BNode', $uri);
    }

    public function test_shadowrocket_tuic_includes_congestion_control(): void
    {
        $uri = Shadowrocket::buildTuic('secret', [
            'name' => 'TUIC Node',
            'host' => 'tuic.example.com',
            'port' => 443,
            'protocol_settings' => [
                'alpn' => ['h3'],
                'tls' => [
                    'server_name' => 'tuic.example.com',
                    'allow_insecure' => false,
                ],
                'version' => 5,
                'congestion_control' => 'bbr',
            ],
        ]);

        $this->assertStringContainsString('congestion_control=bbr', $uri);
    }
}
