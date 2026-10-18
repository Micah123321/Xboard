<?php
namespace Tests\Unit;

use App\Services\AccessTrafficClassifier;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

class AccessTrafficReviewedEvidenceTest extends TestCase
{
    public function test_reviewed_samples_preserve_evidence_confidence_and_hostname_scope_without_network(): void
    {
        $app = new Container(); Container::setInstance($app);
        $geosite = require __DIR__ . '/../../config/access_traffic_geosite.php';
        $geosite['update_path'] = __DIR__ . '/review-evidence-no-runtime-override.json';
        $app->instance('config', new Repository([
            'access_traffic' => require __DIR__ . '/../../config/access_traffic.php',
            'access_traffic_geosite' => $geosite,
        ]));
        try {
            $ledger = json_decode(file_get_contents(__DIR__ . '/../../resources/data/access-traffic/reviewed-evidence.json'), true, 64, JSON_THROW_ON_ERROR);
            $classifier = new AccessTrafficClassifier();
            foreach ($ledger['targets'] as $sample) {
                $result = $classifier->classify($sample['domain'], 'request');
                self::assertSame($sample['category'], $result['category'], $sample['domain']);
                self::assertSame($sample['confidence'], $result['confidence'], $sample['domain']);
                self::assertStringContainsString($sample['service_contains'], $result['service']);
                self::assertLessThanOrEqual(255, mb_strlen($result['reason']));
            }
            foreach ($ledger['dedicated_ips'] as $sample) {
                $result = $classifier->classify('', 'ip', $sample['ip']);
                self::assertSame($sample['category'], $result['category']);
                self::assertSame($sample['confidence'], $result['confidence']);
                self::assertStringContainsString($sample['service_contains'], $result['service']);
                self::assertStringContainsString($sample['source'], $result['reason']);
            }
            foreach ($ledger['negative_domains'] as $domain) self::assertSame('unknown', $classifier->classify($domain, 'request')['category'], $domain);
            foreach ($ledger['negative_ips'] as $ip) self::assertSame('unknown', $classifier->classify('', 'ip', $ip)['category'], $ip);
            self::assertSame('unknown', $classifier->classify('unrecognized.example.test', 'request', '8.8.8.8')['category']);
            self::assertStringContainsString('完整域名', $classifier->classify('CODEX.CCBVV.COM.', 'request')['reason']);
            self::assertSame('unknown', $classifier->classify('codex.ccbvv.com', 'ip')['category']);
            $result = $classifier->classify('', 'ip', '::ffff:8.8.4.4');
            self::assertSame('Google Public DNS', $result['service']);
            self::assertSame('high', $result['confidence']);
        } finally {
            Container::setInstance(null);
        }
    }
}
