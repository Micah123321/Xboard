<?php

namespace Tests\Unit;

use App\Services\AccessTrafficActivityInference;
use PHPUnit\Framework\TestCase;

class AccessTrafficActivityInferenceTest extends TestCase
{
    public function test_directional_hints_are_low_confidence_and_do_not_guess_target_or_video(): void
    {
        $service = new AccessTrafficActivityInference();
        $row = ['raw_u' => 1024, 'raw_d' => 2 * 1024 * 1024, 'domain' => 'ojfn.hzrmzz.com', 'ip' => ''];
        $result = $service->infer($row);
        self::assertSame('疑似下载 / 内容接收', $result['activity_hint']);
        self::assertSame('low', $result['activity_confidence']);
        self::assertStringContainsString('网页资源与流媒体', $result['activity_reason']);
        self::assertArrayNotHasKey('category', $result);
        self::assertArrayNotHasKey('service', $result);
        self::assertSame(1024, $row['raw_u']);
        $upload = $service->infer(['raw_u' => 2 * 1024 * 1024, 'raw_d' => 1024, 'domain' => 'chatgpt.com', 'ip' => '']);
        self::assertSame('疑似上传 / 同步', $upload['activity_hint']);
        self::assertSame('low', $upload['activity_confidence']);
        self::assertStringContainsString('仅为流量形态线索', $upload['activity_reason']);
    }

    public function test_missing_target_does_not_suppress_bytes_based_hint_or_invent_a_service(): void
    {
        $service = new AccessTrafficActivityInference();
        $result = $service->infer(['raw_u' => 1, 'raw_d' => 1048576, 'domain' => '', 'ip' => '']);
        self::assertSame('疑似下载 / 内容接收', $result['activity_hint']);
        self::assertStringContainsString('真实目标未采集', $result['activity_reason']);
        self::assertArrayNotHasKey('category', $result);
        self::assertSame([], $service->infer(['raw_u' => 100, 'raw_d' => 100]));
        self::assertSame([], $service->infer(['raw_u' => 0, 'raw_d' => 0]));
    }

    public function test_balanced_and_large_traffic_preserves_uncertainty_without_integer_overflow(): void
    {
        $service = new AccessTrafficActivityInference();
        $result = $service->infer(['raw_u' => PHP_INT_MAX, 'raw_d' => PHP_INT_MAX]);
        self::assertSame('双向数据传输', $result['activity_hint']);
        self::assertSame('low', $result['activity_confidence']);
        self::assertStringContainsString('具体内容或用户操作', $result['activity_reason']);
        self::assertSame('疑似上传 / 同步', $service->infer(['raw_u' => 9 * 1048576, 'raw_d' => 1048576])['activity_hint']);
        self::assertSame('双向数据传输', $service->infer(['raw_u' => 8 * 1048576, 'raw_d' => 2 * 1048576])['activity_hint']);
    }
}
