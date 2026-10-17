<?php

namespace Tests\Unit;

use App\Services\AccessTrafficClassifier;
use App\Services\AccessTrafficDomainDatabase;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\TestCase;

class AccessTrafficDomainDatabaseTest extends TestCase
{
    private string $directory;
    private Container $app;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/access-geosite-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
        $this->app = new Container(); Container::setInstance($this->app);
        $this->app->instance('config', new Repository([
            'access_traffic' => require __DIR__ . '/../../config/access_traffic.php',
            'access_traffic_geosite' => ['snapshot_path' => $this->directory . '/snapshot.json', 'update_path' => $this->directory . '/updated.json',
                'groups' => ['demo' => ['Demo Platform', 'web'], 'cloud' => ['Cloud Platform', 'cdn']]],
        ]));
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($this->app);
        Http::swap(new Factory());
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') as $file) unlink($file);
        rmdir($this->directory);
        Facade::clearResolvedInstances(); Container::setInstance(null);
        parent::tearDown();
    }

    public function test_bundled_upstream_snapshot_and_cloud_sample_are_ready_without_network(): void
    {
        $this->app['config']->set('access_traffic_geosite.snapshot_path', __DIR__ . '/../../resources/data/access-traffic/geosite.json');
        $db = new AccessTrafficDomainDatabase();
        self::assertGreaterThan(5000, $db->status()['rule_count']);
        self::assertSame(24, $db->status()['group_count']);
        self::assertSame('Netflix', $db->lookup('api.netflix.com')['service']);
        self::assertSame('哔哩哔哩', $db->lookup('r.acgvideo.com')['service']);
        $classified = (new AccessTrafficClassifier())->classify('ykj-eos-dg5-01.eos-dongguan-6.cmecloud.cn', 'request');
        self::assertSame('storage', $classified['category']);
        self::assertStringContainsString('移动云 EOS', $classified['service']);
        self::assertStringContainsString('用途未知', $classified['reason']);
        Http::assertNothingSent();
    }

    public function test_include_full_suffix_and_specific_service_precedence(): void
    {
        Http::fake([
            '*/data/demo' => Http::response("include:shared\nexample.test\nfull:exact.test\nregexp:.*video.*\ninclude:ads @ads\n"),
            '*/data/shared' => Http::response("domain:shared.test @cn\n"),
            '*/data/cloud' => Http::response("cloud.test\nexample.test\n"),
        ]);
        $db = new AccessTrafficDomainDatabase();
        $result = $db->update();
        self::assertSame(4, $result['rule_count']);
        self::assertSame(2, $result['skipped_rules']);
        self::assertSame('Demo Platform', $db->lookup('sub.example.test')['service']);
        self::assertSame('Demo Platform', $db->lookup('shared.test')['service']);
        self::assertSame('Demo Platform', $db->lookup('exact.test')['service']);
        self::assertNull($db->lookup('sub.exact.test'));
        self::assertNull($db->lookup('evil-example.test'));
        self::assertSame('cdn', $db->lookup('sub.cloud.test')['category']);
        self::assertStringContainsString('具体页面、播放、下载', $db->lookup('example.test')['reason']);
        $classifier = new AccessTrafficClassifier();
        self::assertSame('web', $classifier->classify('example.test', 'request')['category']);
        self::assertSame('unknown', $classifier->classify('example.test', 'ip')['category']);
        self::assertSame('video', $classifier->classify('r.googlevideo.com', 'request')['category']);
        self::assertTrue($db->status()['available']);
    }

    public function test_failed_update_preserves_active_database_and_snapshot_fallback(): void
    {
        Http::fake(['*/data/demo' => Http::response('example.test'), '*/data/cloud' => Http::response('cloud.test')]);
        $db = new AccessTrafficDomainDatabase(); $db->update($this->directory . '/snapshot.json');
        self::assertSame('Demo Platform', $db->lookup('example.test')['service']);
        $before = file_get_contents($this->directory . '/snapshot.json');
        Http::swap(new Factory());
        Http::fake(['*' => Http::response('upstream unavailable', 503)]);
        try { $db->update($this->directory . '/snapshot.json'); self::fail('Expected download failure'); }
        catch (\RuntimeException $error) { self::assertStringContainsString('download failed', $error->getMessage()); }
        self::assertSame($before, file_get_contents($this->directory . '/snapshot.json'));
        file_put_contents($this->directory . '/updated.json', '<html>corrupt</html>');
        self::assertSame('Demo Platform', $db->lookup('example.test')['service']);
    }

    public function test_invalid_recursive_include_does_not_publish_partial_data(): void
    {
        Http::fake(['*/data/demo' => Http::response('include:demo'), '*/data/cloud' => Http::response('cloud.test')]);
        $db = new AccessTrafficDomainDatabase();
        try { $db->update(); self::fail('Expected include cycle'); }
        catch (\RuntimeException $error) { self::assertStringContainsString('Cyclic', $error->getMessage()); }
        self::assertFileDoesNotExist($this->directory . '/updated.json');
    }

    public function test_atomic_updates_refresh_previously_loaded_index(): void
    {
        Http::fake(['*/data/demo' => Http::response('old.example'), '*/data/cloud' => Http::response('cloud.test')]);
        $db = new AccessTrafficDomainDatabase(); $db->update();
        self::assertNotNull($db->lookup('old.example'));
        Http::swap(new Factory());
        Http::fake(['*/data/demo' => Http::response('new.example'), '*/data/cloud' => Http::response('cloud.test')]);
        $db->update();
        self::assertNull($db->lookup('old.example')); self::assertNotNull($db->lookup('new.example'));
    }
}
