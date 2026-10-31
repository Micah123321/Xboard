<?php

namespace App\Services\AiTopup;

use App\Exceptions\ApiException;

class AiTopupPolicy
{
    public const PAYMENT_METHODS = ['EPay', 'BEpusdt', 'AlipayF2F', 'TokenPay', 'Coinbase', 'BTCPay', 'CoinPayments'];
    public const GIB = 1073741824;
    public const MAX_USD = 1000000;
    public const MIN_CNY_CENTS = 500;
    public const MAX_CNY_CENTS = 200000;

    public static function defaults(): array
    {
        $tiers = [];
        foreach ([0 => '0.150', 1000 => '0.145', 3000 => '0.135', 5000 => '0.130', 6000 => '0.127',
            8000 => '0.123', 9000 => '0.119', 10000 => '0.115', 15000 => '0.105'] as $min => $price) {
            $tiers[] = ['min_usd' => $min, 'unit_price_cny' => $price];
        }
        return ['enabled' => false, 'tiers' => $tiers, 'min_usd' => 1, 'max_usd' => self::MAX_USD,
            'bonus_rate_bps' => 150, 'traffic_gb_per_cny' => 10, 'order_ttl_minutes' => 30, 'version' => 1];
    }

    public static function add(int $a, int $b): int
    {
        if ($a < 0 || $b < 0 || $a > PHP_INT_MAX - $b) throw new ApiException('金额或流量超出可处理范围', 422);
        return $a + $b;
    }

    public static function multiply(int $a, int $b): int
    {
        if ($a < 0 || $b < 0 || ($b > 0 && $a > intdiv(PHP_INT_MAX, $b))) {
            throw new ApiException('金额或流量超出可处理范围', 422);
        }
        return $a * $b;
    }

    public static function divideProduct(int $a, int $b, int $divisor, bool $round = false): int
    {
        $whole = self::multiply(intdiv($a, $divisor), $b);
        $remainder = self::multiply($a % $divisor, $b);
        if ($round) $remainder = self::add($remainder, intdiv($divisor, 2));
        return self::add($whole, intdiv($remainder, $divisor));
    }

    public static function priceUnits(string $price): int
    {
        if (!preg_match('/\A(0|[1-9][0-9]{0,3})(?:\.([0-9]{1,3}))?\z/D', $price, $m)) {
            throw new ApiException('充值单价最多保留三位小数', 422);
        }
        $units = (int) $m[1] * 1000 + (int) str_pad($m[2] ?? '', 3, '0');
        if ($units < 1 || $units > 1000000) throw new ApiException('充值单价须在 0.001 至 1000 元之间', 422);
        return $units;
    }

    public static function price(int $units): string
    {
        return intdiv($units, 1000) . '.' . str_pad((string) ($units % 1000), 3, '0', STR_PAD_LEFT);
    }

    public static function giftBytes(int $cents, int $bps): int
    {
        return self::divideProduct(self::multiply($cents, $bps), self::GIB, 100000);
    }

    public static function quote(int $usd, array $settings, bool $enforceSaleLimits = true): array
    {
        if ($usd < $settings['min_usd'] || $usd > $settings['max_usd'] || $usd < 1 || $usd > self::MAX_USD) {
            throw new ApiException('充值面额超出当前可选范围', 422);
        }
        $tier = null;
        foreach ($settings['tiers'] as $candidate) {
            if ($candidate['min_usd'] <= $usd) $tier = $candidate;
        }
        if ($tier === null) throw new ApiException('当前面额没有对应单价', 422);
        $cents = intdiv(self::add(self::multiply($usd, self::priceUnits($tier['unit_price_cny'])), 5), 10);
        if ($enforceSaleLimits && $cents <= 0) throw new ApiException('订单本金至少为人民币 0.01 元', 422);
        if ($enforceSaleLimits && ($cents < self::MIN_CNY_CENTS || $cents > self::MAX_CNY_CENTS)) {
            throw new ApiException('充值本金须在人民币 5 至 2000 元之间，支付渠道手续费另计', 422);
        }
        return ['amount_usd' => (string) $usd, 'unit_price_cny' => $tier['unit_price_cny'],
            'total_amount' => $cents, 'gift_traffic_bytes' => self::giftBytes($cents, $settings['bonus_rate_bps']),
            'tier_min_usd' => $tier['min_usd'], 'bonus_rate_bps' => $settings['bonus_rate_bps'],
            'traffic_gb_per_cny' => 10, 'policy_version' => $settings['version']];
    }

    public static function saleLimits(array $settings): array
    {
        $min = null; $max = null;
        foreach ($settings['tiers'] as $i => $tier) {
            $units = self::priceUnits($tier['unit_price_cny']);
            $lower = max(1, $settings['min_usd'], $tier['min_usd'], intdiv(self::MIN_CNY_CENTS * 10 - 5 + $units - 1, $units));
            $upper = min(self::MAX_USD, $settings['max_usd'], ($settings['tiers'][$i + 1]['min_usd'] ?? (self::MAX_USD + 1)) - 1,
                intdiv((self::MAX_CNY_CENTS + 1) * 10 - 6, $units));
            if ($lower > $upper) continue;
            $min = $min === null ? $lower : min($min, $lower);
            $max = $max === null ? $upper : max($max, $upper);
        }
        return ['min_cny_cents' => self::MIN_CNY_CENTS, 'max_cny_cents' => self::MAX_CNY_CENTS,
            'effective_min_usd' => $min, 'effective_max_usd' => $max];
    }

    public static function normalize(array $settings): array
    {
        $settings['enabled'] = (bool) $settings['enabled'];
        foreach (['min_usd', 'max_usd', 'bonus_rate_bps', 'order_ttl_minutes'] as $key) $settings[$key] = (int) $settings[$key];
        if ($settings['min_usd'] < 1 || $settings['max_usd'] > self::MAX_USD || $settings['min_usd'] > $settings['max_usd']
            || $settings['bonus_rate_bps'] < 0 || $settings['bonus_rate_bps'] > 10000
            || $settings['order_ttl_minutes'] < 1 || $settings['order_ttl_minutes'] > 1440) {
            throw new ApiException('充值金额范围、赠送比例或支付期限配置无效', 422);
        }
        $tiers = $settings['tiers'];
        if (!$tiers || count($tiers) > 100) throw new ApiException('价格阶梯须设置 1 至 100 档', 422);
        $seen = [];
        foreach ($tiers as &$tier) {
            $min = (int) $tier['min_usd'];
            if ($min < 0 || $min > self::MAX_USD || isset($seen[$min])) throw new ApiException('价格阶梯门槛无效或重复', 422);
            $seen[$min] = true;
            $tier = ['min_usd' => $min, 'unit_price_cny' => self::price(self::priceUnits((string) $tier['unit_price_cny']))];
        }
        unset($tier);
        usort($tiers, fn ($a, $b) => $a['min_usd'] <=> $b['min_usd']);
        if ($tiers[0]['min_usd'] !== 0) throw new ApiException('须保留从 0 开始的基础单价档位', 422);
        for ($i = 1; $i < count($tiers); $i++) {
            if (self::priceUnits($tiers[$i]['unit_price_cny']) > self::priceUnits($tiers[$i - 1]['unit_price_cny'])) {
                throw new ApiException('充值面额越高，阶梯单价应保持或降低', 422);
            }
        }
        $settings['tiers'] = $tiers;
        $settings['traffic_gb_per_cny'] = 10;
        self::quote($settings['min_usd'], $settings, false);
        foreach ($tiers as $index => $tier) {
            $upper = min($settings['max_usd'], ($tiers[$index + 1]['min_usd'] ?? (self::MAX_USD + 1)) - 1);
            if ($upper >= max($settings['min_usd'], $tier['min_usd'])) self::quote($upper, $settings, false);
        }
        if ($settings['enabled'] && self::saleLimits($settings)['effective_min_usd'] === null) throw new ApiException('当前单价与面额范围内没有人民币 5 至 2000 元的可售面额', 422);
        return $settings;
    }

    public static function handlingFee(int $principal, mixed $fixed, mixed $percent): int
    {
        $fixed = $fixed ?? 0;
        $percent = $percent ?? '0';
        if (!preg_match('/\A[0-9]{1,10}\z/D', (string) $fixed)
            || !preg_match('/\A([0-9]{1,3})(?:\.([0-9]{1,4}))?\z/D', (string) $percent, $m)) {
            throw new ApiException('支付渠道手续费配置无效', 422);
        }
        $rate = (int) $m[1] * 10000 + (int) str_pad($m[2] ?? '', 4, '0');
        return self::add((int) $fixed, self::divideProduct($principal, $rate, 1000000, true));
    }

    public static function callbackCents(mixed $amount): ?int
    {
        if ((!is_int($amount) && !is_string($amount) && !is_float($amount))
            || !preg_match('/\A([0-9]{1,12})(?:\.([0-9]{1,2})0*)?\z/D', (string) $amount, $m)) return null;
        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }

    public static function remoteAmountMatches(mixed $value, int $usd): bool
    {
        if (!is_int($value) && !is_string($value) && !is_float($value)) return false;
        return preg_match('/\A([0-9]{1,7})(?:\.0+)?\z/D', (string) $value, $m) === 1 && (int) $m[1] === $usd;
    }
}
