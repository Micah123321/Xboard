<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\GiftCardTemplate;
use App\Models\Plan;
use App\Models\User;

class GiftCardRedemptionService
{
    public const REDEMPTION_MODE_PLAN = 'plan';
    public const REDEMPTION_MODE_TRAFFIC = 'traffic';

    /**
     * 获取套餐礼品卡可用的兑换方式。
     */
    public function getOptions(GiftCardTemplate $template, ?User $user): array
    {
        if ($template->type !== GiftCardTemplate::TYPE_PLAN) {
            return [];
        }

        $options = [[
            'mode' => self::REDEMPTION_MODE_PLAN,
            'label' => '切换套餐',
            'description' => '切换至礼品卡对应套餐，当前套餐的流量、有效期和套餐设置将被替换。',
        ]];

        if (!$user || !$user->isActive()) {
            return $options;
        }

        $plan = Plan::find($template->rewards['plan_id'] ?? null);
        if (!$plan || $plan->transfer_enable <= 0) {
            return $options;
        }

        $remainingTraffic = $user->getRemainingTraffic();

        $options[] = [
            'mode' => self::REDEMPTION_MODE_TRAFFIC,
            'label' => '追加流量',
            'description' => '切换至礼品卡套餐，保留当前账户的剩余流量并追加到新套餐中。',
            'transfer_enable' => $remainingTraffic,
        ];

        return $options;
    }

    /**
     * 验证并归一化兑换方式。
     */
    public function resolveMode(?string $mode, GiftCardTemplate $template, User $user): string
    {
        $mode ??= self::REDEMPTION_MODE_PLAN;

        if (!in_array($mode, [self::REDEMPTION_MODE_PLAN, self::REDEMPTION_MODE_TRAFFIC], true)) {
            throw new ApiException('无效的礼品卡兑换方式');
        }

        if ($mode === self::REDEMPTION_MODE_TRAFFIC
            && ($template->type !== GiftCardTemplate::TYPE_PLAN || !$user->isActive())) {
            throw new ApiException('当前礼品卡不支持追加流量');
        }

        return $mode;
    }

    /**
     * 将实际奖励转换为兑换记录和接口响应中的奖励。
     */
    public function getRewardsGiven(array $rewards, string $mode, ?int $trafficBytes = null): array
    {
        if ($mode !== self::REDEMPTION_MODE_TRAFFIC || !isset($rewards['plan_id'])) {
            return $rewards;
        }

        // 追加流量模式：保留 plan_id 和 plan_validity_days，追加用户剩余流量
        $rewards['transfer_enable'] = ($rewards['transfer_enable'] ?? 0) + ($trafficBytes ?? 0);

        return $rewards;
    }

    /**
     * 获取套餐对应的字节数。
     */
    public function getTrafficBytes(int|string $planId): int
    {
        $plan = Plan::find($planId);
        if (!$plan || $plan->transfer_enable <= 0) {
            throw new ApiException('礼品卡套餐流量不可用');
        }

        return (int) ($plan->transfer_enable * 1073741824);
    }
}
