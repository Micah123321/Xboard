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
        self::assertSame(72, $db->status()['group_count']);
        self::assertSame('Netflix', $db->lookup('api.netflix.com')['service']);
        self::assertSame('哔哩哔哩', $db->lookup('r.acgvideo.com')['service']);
        $classified = (new AccessTrafficClassifier())->classify('ykj-eos-dg5-01.eos-dongguan-6.cmecloud.cn', 'request');
        self::assertSame('storage', $classified['category']);
        self::assertStringContainsString('移动云 EOS', $classified['service']);
        self::assertStringContainsString('用途未知', $classified['reason']);
        Http::assertNothingSent();
    }

    public function test_ai_quark_and_dedicated_ip_evidence_classifies_screenshot_samples(): void
    {
        $this->app['config']->set('access_traffic_geosite.snapshot_path', __DIR__ . '/../../resources/data/access-traffic/geosite.json');
        $classifier = new AccessTrafficClassifier();
        foreach (['chatgpt.com', 'CHATGPT.COM.', 'chat.openai.com', 'claude.ai', 'gemini.google.com', 'aistudio.google.com', 'api.deepseek.com'] as $domain) {
            $result = $classifier->classify($domain, 'request');
            self::assertSame('ai', $result['category'], $domain);
            self::assertSame('high', $result['confidence'], $domain);
        }
        foreach (['askdiandian.com', 'api.askdiandian.com', 'diandianlife.top'] as $domain) {
            self::assertSame('ai', $classifier->classify($domain, 'request')['category'], $domain);
        }
        self::assertSame('social', $classifier->classify('xiaohongshu.com', 'request')['category']);
        self::assertSame('unknown', $classifier->classify('askdiandian.com.example.test', 'request')['category']);
        self::assertSame('ai', $classifier->classify('openrouter.ai', 'request')['category']);
        self::assertSame('ai', $classifier->classify('api.perplexity.ai', 'request')['category']);
        self::assertSame('storage', $classifier->classify('dl-c-zb-u.drive.quark.cn', 'request')['category']);
        self::assertSame('storage', $classifier->classify('pan.quark.cn', 'request')['category']);
        foreach (['91.108.56.137', '91.108.56.0', '91.108.59.255', '::ffff:91.108.56.137', '2001:b28:f23d::123'] as $ip) {
            $result = $classifier->classify('', 'ip', $ip);
            self::assertSame('Telegram', $result['service']);
            self::assertSame('messaging', $result['category']);
            self::assertSame('medium', $result['confidence']);
            self::assertStringContainsString('https://core.telegram.org/resources/cidr.txt', $result['reason']);
        }
        foreach (['91.108.55.255', '91.108.60.0', '203.0.113.7', '::', '::ffff:0.0.0.0', '2001:b28:f240::1'] as $ip) {
            self::assertSame('unknown', $classifier->classify('', 'ip', $ip)['category']);
        }
        foreach (['evilchatgpt.com', 'chatgpt.com.example.test', 'some-ai.example.test', 'ojfn.hzrmzz.com'] as $domain) {
            self::assertSame('unknown', $classifier->classify($domain, 'request')['category']);
        }
        self::assertSame('unknown', $classifier->classify('unknown.example', 'request', '91.108.56.137')['category']);
        self::assertSame('unknown', $classifier->classify('', 'unknown', '91.108.56.137')['category']);
        self::assertSame('unknown', $classifier->classify('chatgpt.com', 'ip')['category']);
        Http::assertNothingSent();
    }

    public function test_exchange_wallet_tools_push_and_telemetry_match_verified_screenshot_samples(): void
    {
        $this->app['config']->set('access_traffic_geosite.snapshot_path', __DIR__ . '/../../resources/data/access-traffic/geosite.json');
        $classifier = new AccessTrafficClassifier();
        $examples = [
            ['static.okx.com', 'crypto', 'OKX 交易所静态资源'],
            ['www.okx.com', 'crypto', 'OKX 加密货币交易所'],
            ['static.oklink.com', 'crypto', 'OKLink 区块链浏览器静态资源'],
            ['www.oklink.com', 'crypto', 'OKLink 区块链浏览器'],
            ['data.onekey.so', 'crypto', 'OneKey 加密货币钱包'],
            ['utility.onekeycn.com', 'crypto', 'OneKey 钱包 API'],
            ['uni.onekey-asset.com', 'crypto', 'OneKey 钱包资源'],
            ['www.itdog.cn', 'tools', 'ITDOG 网络测速与诊断工具'],
            ['api.onesignal.com', 'push', 'OneSignal 消息推送'],
            ['toblog.tobsnssdk.com', 'telemetry', 'BytePlus SDK 日志与统计上报'],
        ];
        foreach ($examples as [$domain, $category, $service]) {
            $result = $classifier->classify($domain, 'request');
            self::assertSame($category, $result['category'], $domain);
            self::assertSame($service, $result['service'], $domain);
            self::assertSame('high', $result['confidence'], $domain);
            self::assertStringContainsString('来源：https://', $result['reason']);
            self::assertLessThanOrEqual(255, mb_strlen($result['reason']));
        }
        foreach (['evilokx.com', 'oklink.com.attacker.test', 'onekeycn.com.evil.example', 'fakeonesignal.com'] as $domain) {
            self::assertSame('unknown', $classifier->classify($domain, 'request')['category'], $domain);
        }
        foreach (['hiadwwa.003939.xyz', 'unknown.example'] as $domain) self::assertSame('unknown', $classifier->classify($domain, 'request')['category']);
        foreach (['17.253.84.125', '194.221.250.50'] as $ip) self::assertSame('unknown', $classifier->classify('', 'ip', $ip)['category']);
        self::assertSame('unknown', $classifier->classify('www.okx.com', 'ip')['category']);
        Http::assertNothingSent();
    }

    public function test_whole_service_categories_expand_beyond_individual_screenshot_domains(): void
    {
        $this->app['config']->set('access_traffic_geosite.snapshot_path', __DIR__ . '/../../resources/data/access-traffic/geosite.json');
        $db = new AccessTrafficDomainDatabase();
        $examples = [
            'api.binance.com' => 'crypto', 'www.coinbase.com' => 'crypto', 'www.bybit.com' => 'crypto',
            'app.trustwallet.com' => 'crypto', 'walletconnect.com' => 'crypto',
            'www.paypal.com' => 'finance', 'tradingview.com' => 'finance',
            'www.speedtest.net' => 'tools', 'pypi.org' => 'tools',
            'discord.com' => 'messaging', 'www.instagram.com' => 'social',
            'store.epicgames.com' => 'games', 'www.ebay.com' => 'shopping',
            'www.notion.so' => 'productivity', 'www.shanbay.com' => 'education',
        ];
        foreach ($examples as $domain => $category) {
            $match = $db->lookup($domain);
            self::assertNotNull($match, $domain);
            self::assertSame($category, $match['category'], $domain);
            self::assertSame('medium', $match['confidence']);
        }
        Http::assertNothingSent();
    }

    public function test_shared_tracking_hosts_are_not_evidence_for_ai_service(): void
    {
        $this->app['config']->set('access_traffic_geosite.groups', ['openai' => ['OpenAI', 'ai']]);
        $this->app['config']->set('access_traffic_geosite.shared_domains', ['host.livekit.cloud', 'turn.livekit.cloud']);
        Http::fake(['*/data/openai' => Http::response("chatgpt.com\nfull:shared-tracker.example @ads\nshared-telemetry.example @telemetry\nhost.livekit.cloud\nturn.livekit.cloud\nchatgpt.livekit.cloud\n")]);
        $db = new AccessTrafficDomainDatabase();
        $result = $db->update();
        self::assertSame(2, $result['rule_count']);
        self::assertSame(4, $result['skipped_rules']);
        self::assertSame('ai', $db->lookup('chatgpt.com')['category']);
        self::assertSame('ai', $db->lookup('chatgpt.livekit.cloud')['category']);
        foreach (['shared-tracker.example', 'shared-telemetry.example', 'host.livekit.cloud', 'turn.livekit.cloud'] as $domain) self::assertNull($db->lookup($domain));
    }

    public function test_valid_bang_group_names_are_supported_without_path_traversal(): void
    {
        $this->app['config']->set('access_traffic_geosite.groups', ['category-ai-!cn' => ['AI', 'ai']]);
        Http::fake(['*/data/category-ai-!cn' => Http::response('model.example')]);
        $db = new AccessTrafficDomainDatabase(); $db->update();
        self::assertSame('ai', $db->lookup('api.model.example')['category']);
        Http::swap(new Factory()); Http::fake(['*' => Http::response('unexpected')]);
        $this->app['config']->set('access_traffic_geosite.groups', ['../outside' => ['Invalid', 'ai']]);
        try { $db->update(); self::fail('Expected invalid group path'); }
        catch (\RuntimeException $error) { self::assertStringContainsString('Invalid GeoSite group', $error->getMessage()); }
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

    public function test_new_bundled_rules_are_not_hidden_by_an_old_runtime_download(): void
    {
        Http::fake(['*/data/demo' => Http::response('old.example'), '*/data/cloud' => Http::response('cloud.test')]);
        $db = new AccessTrafficDomainDatabase(); $db->update();
        $runtime = json_decode(file_get_contents($this->directory . '/updated.json'), true);
        $runtime['updated_at'] = time() - 3600;
        file_put_contents($this->directory . '/updated.json', json_encode($runtime));
        Http::swap(new Factory());
        Http::fake(['*/data/demo' => Http::response('new.example'), '*/data/cloud' => Http::response('cloud.test')]);
        $db->update($this->directory . '/snapshot.json');
        self::assertNotNull($db->lookup('new.example')); self::assertNull($db->lookup('old.example'));
        $runtime['suffix'] = ['later.example' => 'demo'];
        $runtime['updated_at'] = time() + 1;
        file_put_contents($this->directory . '/updated.json', json_encode($runtime));
        self::assertNotNull($db->lookup('later.example')); self::assertNull($db->lookup('new.example'));
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
