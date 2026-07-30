<?php

namespace Tests\Unit;

use App\Models\GiftCardTemplate;
use App\Models\User;
use PHPUnit\Framework\TestCase;

class GiftCardTemplateEligibilityTest extends TestCase
{
    public function test_active_user_can_choose_a_redemption_mode_for_a_plan_card(): void
    {
        $template = new GiftCardTemplate([
            'type' => GiftCardTemplate::TYPE_PLAN,
            'conditions' => [],
            'rewards' => ['plan_id' => 3],
        ]);
        $user = new User();
        $user->setRawAttributes([
            'banned' => false,
            'plan_id' => 1,
            'expired_at' => time() + 86400,
        ], true);

        self::assertTrue($template->checkUserConditions($user));
    }

    public function test_expired_user_still_passes_without_extra_template_conditions(): void
    {
        $template = new GiftCardTemplate([
            'type' => GiftCardTemplate::TYPE_PLAN,
            'conditions' => [],
            'rewards' => ['plan_id' => 3],
        ]);
        $user = new User();
        $user->setRawAttributes([
            'banned' => false,
            'plan_id' => 1,
            'expired_at' => time() - 1,
        ], true);

        self::assertTrue($template->checkUserConditions($user));
    }
}
