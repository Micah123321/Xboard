<?php

namespace Tests\Unit;

use App\Exceptions\ApiException;
use App\Models\GiftCardTemplate;
use App\Models\User;
use App\Services\GiftCardRedemptionService;
use PHPUnit\Framework\TestCase;

class GiftCardRedemptionServiceTest extends TestCase
{
    public function test_plan_mode_is_the_default_for_a_plan_card(): void
    {
        $service = new GiftCardRedemptionService();
        $template = $this->makeTemplate(GiftCardTemplate::TYPE_PLAN);
        $user = $this->makeUser(true);

        self::assertSame(
            GiftCardRedemptionService::REDEMPTION_MODE_PLAN,
            $service->resolveMode(null, $template, $user),
        );
    }

    public function test_traffic_mode_requires_an_active_plan_user(): void
    {
        $service = new GiftCardRedemptionService();
        $template = $this->makeTemplate(GiftCardTemplate::TYPE_PLAN);

        try {
            $service->resolveMode('traffic', $template, $this->makeUser(false));
            self::fail('非活跃用户不应选择追加流量模式。');
        } catch (ApiException $exception) {
            self::assertSame('当前礼品卡不支持追加流量', $exception->getMessage());
        }
    }

    private function makeTemplate(int $type): GiftCardTemplate
    {
        return new GiftCardTemplate([
            'type' => $type,
            'rewards' => ['plan_id' => 3],
        ]);
    }

    private function makeUser(bool $active): User
    {
        $user = new User();
        $user->setRawAttributes([
            'banned' => false,
            'plan_id' => 1,
            'expired_at' => $active ? time() + 86400 : time() - 1,
        ], true);

        return $user;
    }
}
