<?php

namespace Tests\Unit;

use App\Exceptions\ApiException;
use App\Models\GiftCardCode;
use App\Models\GiftCardTemplate;
use App\Models\User;
use App\Services\GiftCardService;
use PHPUnit\Framework\TestCase;

class GiftCardServiceRedeemAtomicityTest extends TestCase
{
    public function test_redeem_revalidates_the_locked_code_after_a_stale_precheck(): void
    {
        $template = $this->makeTemplate();
        $user = $this->makeUser();
        $staleCode = $this->makeCode($template, 0);
        $lockedCode = $this->makeCode($template, 1);

        $service = new class($staleCode, $template, $user, $lockedCode) extends GiftCardService {
            public bool $lockAttempted = false;
            public bool $rewardsGiven = false;

            public function __construct(
                GiftCardCode $staleCode,
                GiftCardTemplate $template,
                User $user,
                private readonly GiftCardCode $lockedCode
            ) {
                $this->code = $staleCode;
                $this->template = $template;
                $this->user = $user;
            }

            protected function runRedeemTransaction(callable $callback): mixed
            {
                return $callback();
            }

            protected function lockCodeForRedeem(): GiftCardCode
            {
                $this->lockAttempted = true;

                return $this->lockedCode;
            }

            protected function lockUserForRedeem(): User
            {
                return $this->user;
            }

            protected function giveRewards(array $rewards): void
            {
                $this->rewardsGiven = true;
            }
        };

        $service->validate();

        try {
            $service->redeem();
            self::fail('兑换码在锁定后已不可用时不应继续发放奖励。');
        } catch (ApiException) {
            self::assertTrue($service->lockAttempted);
            self::assertFalse($service->rewardsGiven);
        }
    }

    private function makeTemplate(): GiftCardTemplate
    {
        return new GiftCardTemplate([
            'name' => '测试礼品卡',
            'type' => GiftCardTemplate::TYPE_GENERAL,
            'status' => true,
            'conditions' => [],
            'rewards' => [],
            'limits' => [],
            'special_config' => [],
        ]);
    }

    private function makeUser(): User
    {
        $user = new User();
        $user->setRawAttributes([
            'id' => 1,
            'banned' => false,
            'plan_id' => null,
            'invite_user_id' => null,
        ], true);

        return $user;
    }

    private function makeCode(GiftCardTemplate $template, int $usageCount): GiftCardCode
    {
        $code = new GiftCardCode([
            'code' => 'ATOMIC2026',
            'status' => GiftCardCode::STATUS_UNUSED,
            'usage_count' => $usageCount,
            'max_usage' => 1,
        ]);
        $code->setRawAttributes([
            ...$code->getAttributes(),
            'id' => 1,
        ], true);
        $code->setRelation('template', $template);

        return $code;
    }
}
