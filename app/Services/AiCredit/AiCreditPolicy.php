<?php

namespace App\Services\AiCredit;

use App\Models\Order;
use App\Models\Plan;
use InvalidArgumentException;

class AiCreditPolicy
{
    public static function rateUnits(string $rate): int
    {
        if (!preg_match('/\A(0|[1-9][0-9]{0,3})(?:\.([0-9]{1,4}))?\z/D', $rate, $match)) {
            throw new InvalidArgumentException('赠送比例须为最多四位小数的正数');
        }
        $units = (int) $match[1] * 10000 + (int) str_pad($match[2] ?? '', 4, '0');
        if ($units <= 0 || $units > 10000000) {
            throw new InvalidArgumentException('赠送比例须大于 0 且不超过 1000');
        }
        return $units;
    }

    public static function amountCents(int $gb, string $rate): int
    {
        if ($gb <= 0 || $gb > 100000000) return 0;
        return intdiv($gb * self::rateUnits($rate) + 50, 100);
    }

    public static function money(int $cents): string
    {
        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function paidCents(Order $order, bool $includeBalance): int
    {
        return max(0, (int) $order->total_amount) + ($includeBalance ? max(0, (int) $order->balance_amount) : 0);
    }

    public static function orderReason(Order $order, array $settings): ?string
    {
        if ((int) $order->status !== Order::STATUS_COMPLETED) return '订单尚未完成开通';
        if ($order->period === Plan::PERIOD_RESET_TRAFFIC || !in_array((int) $order->type, [1, 2, 3], true)) return '流量重置包不参加活动';
        if (!$order->paid_at || trim((string) $order->callback_no) === '' || $order->callback_no === 'manual_operation') return '订单缺少在线付款凭据，请联系客服核实';
        if ((int) $order->total_amount > 0 && !(int) $order->payment_id) return '订单缺少支付渠道记录，请联系客服核实';
        if (self::paidCents($order, (bool) $settings['include_balance']) <= (int) $settings['min_paid_cents']) return '订单实付须大于 ¥' . self::money((int) $settings['min_paid_cents']);
        if ($settings['plan_ids'] && !in_array((int) $order->plan_id, $settings['plan_ids'], true)) return '该套餐未参加活动';
        return null;
    }
}
