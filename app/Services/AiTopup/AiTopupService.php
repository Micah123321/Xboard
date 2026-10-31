<?php

namespace App\Services\AiTopup;

use App\Exceptions\ApiException;
use App\Jobs\AiTopupFulfillJob;
use App\Models\AiCreditSetting;
use App\Models\AiTopupOrder;
use App\Models\AiTopupSetting;
use App\Models\Payment;
use App\Models\User;
use App\Services\AiCredit\AiCreditClient;
use App\Services\AiCredit\AiCreditService;
use App\Services\UserService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class AiTopupService
{
    private const LEASE_SECONDS = 90;
    private const RETRY_ERROR = '发码结果待核对，系统将继续处理原订单，请稍后刷新';

    public function __construct(private AiCreditClient $client) {}

    public function available(): bool
    {
        $schema = DB::connection()->getSchemaBuilder();
        return $schema->hasTable('v2_ai_topup_settings') && $schema->hasTable('v2_ai_topup_orders');
    }

    public static function defaults(): array
    {
        return AiTopupPolicy::defaults();
    }

    private function provider(): ?array
    {
        if (!DB::connection()->getSchemaBuilder()->hasTable('v2_ai_credit_settings')) return null;
        try {
            $provider = AiCreditSetting::find(1);
            $url = rtrim((string) ($provider?->settings['bridge_url'] ?? ''), '/');
            $token = $provider?->bridge_token;
            $parts = parse_url($url);
            if (!$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])
                || isset($parts['user']) || isset($parts['pass'])
                || isset($parts['query']) || isset($parts['fragment']) || !$token) return null;
            return [$url, $token];
        } catch (Throwable $e) {
            return null;
        }
    }

    public function settings(): array
    {
        $available = $this->available();
        $model = $available ? AiTopupSetting::find(1) : null;
        $settings = array_replace(self::defaults(), $model?->settings ?? []);
        $provider = DB::connection()->getSchemaBuilder()->hasTable('v2_ai_credit_settings') ? AiCreditSetting::find(1) : null;
        $links = array_replace(AiCreditService::defaults(), $provider?->settings ?? []);
        foreach (['site_url', 'pricing_url', 'transparency_url'] as $key) $settings[$key] = $links[$key];
        $settings['provider_ready'] = $this->provider() !== null;
        $settings['enabled'] = $available && (bool) $settings['enabled'];
        $settings = array_replace($settings, AiTopupPolicy::saleLimits($settings));
        return array_intersect_key($settings, array_flip(['min_cny_cents', 'max_cny_cents', 'effective_min_usd', 'effective_max_usd', 'enabled', 'provider_ready', 'tiers', 'min_usd', 'max_usd',
            'bonus_rate_bps', 'traffic_gb_per_cny', 'order_ttl_minutes', 'site_url', 'pricing_url', 'transparency_url', 'version']));
    }

    public function saveSettings(array $input): array
    {
        $this->requireAvailable();
        $data = Validator::make($input, [
            'enabled' => 'sometimes|boolean', 'min_usd' => 'sometimes|integer|min:1|max:1000000',
            'max_usd' => 'sometimes|integer|min:1|max:1000000', 'bonus_rate_bps' => 'sometimes|integer|min:0|max:10000',
            'order_ttl_minutes' => 'sometimes|integer|min:1|max:1440', 'tiers' => 'sometimes|array|min:1|max:100',
            'tiers.*.min_usd' => 'required_with:tiers|integer|min:0|max:1000000',
            'tiers.*.unit_price_cny' => ['required_with:tiers', 'regex:/\A(?:0|[1-9][0-9]{0,3})(?:\.[0-9]{1,3})?\z/D'],
        ])->validate();
        DB::transaction(function () use ($data) {
            $this->settingModel();
            $model = AiTopupSetting::lockForUpdate()->findOrFail(1);
            $settings = AiTopupPolicy::normalize(array_replace(self::defaults(), $model->settings, $data));
            $settings['version'] = AiTopupPolicy::add((int) ($model->settings['version'] ?? 1), 1);
            $model->update(['settings' => $settings]);
        });
        return $this->settings();
    }

    private function settingModel(): AiTopupSetting
    {
        return AiTopupSetting::firstOrCreate(['id' => 1], ['instance_id' => (string) Str::uuid(), 'settings' => self::defaults()]);
    }

    private function requireAvailable(): void
    {
        if (!$this->available()) throw new ApiException('AI 充值正在准备中，请先完成数据库迁移', 503);
    }

    private function requireCheckout(): void
    {
        $settings = $this->settings();
        if (!$settings['enabled']) throw new ApiException('AI 充值暂未开放，已支付订单将继续发放', 409);
        if (!$settings['provider_ready']) throw new ApiException('发码服务正在准备中，请稍后充值', 503);
    }

    public function quote(int $amountUsd): array
    {
        return AiTopupPolicy::quote($amountUsd, $this->settings());
    }

    public function create(User $user, int $amountUsd, string $key): AiTopupOrder
    {
        $this->requireAvailable();
        if (!Str::isUuid($key)) throw new ApiException('充值请求标识无效，请刷新后重试', 422);
        $key = strtolower($key);
        return DB::transaction(function () use ($user, $amountUsd, $key) {
            $locked = User::lockForUpdate()->findOrFail($user->id);
            $existing = AiTopupOrder::where('user_id', $locked->id)->where('client_request_id', $key)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->amount_usd !== $amountUsd) throw new ApiException('该充值请求已用于其他面额，请重新创建订单', 409);
                return $existing;
            }
            if ($locked->banned) throw new ApiException('账户已停用', 403);
            $this->requireCheckout();
            $settings = $this->settings();
            $quote = AiTopupPolicy::quote($amountUsd, $settings);
            $instance = $this->settingModel()->instance_id;
            $order = AiTopupOrder::create([
                'user_id' => $locked->id, 'client_request_id' => $key,
                'trade_no' => 'AIT' . gmdate('YmdHis') . Str::upper(Str::random(20)),
                'status' => 'pending', 'issue_status' => 'pending', 'amount_usd' => $amountUsd,
                'unit_price_cny' => $quote['unit_price_cny'], 'total_amount' => $quote['total_amount'],
                'handling_amount' => 0, 'gift_traffic_bytes' => $quote['gift_traffic_bytes'], 'policy_snapshot' => $quote,
                'expires_at' => time() + $settings['order_ttl_minutes'] * 60,
            ]);
            $order->update(['external_ref' => $instance . ':topup:' . $order->id]);
            return $order;
        }, 3);
    }

    public function ownedOrder(User $user, string $tradeNo): AiTopupOrder
    {
        $this->requireAvailable();
        $order = AiTopupOrder::where('user_id', $user->id)->where('trade_no', $tradeNo)->first();
        if (!$order) throw new ApiException('充值订单不存在', 404);
        return $order;
    }

    private function lockedOwned(User $user, string $tradeNo): array
    {
        $locked = User::lockForUpdate()->findOrFail($user->id);
        $order = AiTopupOrder::where('user_id', $locked->id)->where('trade_no', $tradeNo)->lockForUpdate()->first();
        if (!$order) throw new ApiException('充值订单不存在', 404);
        return [$locked, $order];
    }

    public function prepareCheckout(User $user, string $tradeNo, Payment $payment): AiTopupOrder
    {
        $this->requireAvailable();
        // Commit expiry evidence before returning a checkout error.
        $order = DB::transaction(function () use ($user, $tradeNo, $payment) {
            [$locked, $order] = $this->lockedOwned($user, $tradeNo);
            $this->expire($order);
            if ($order->status !== 'pending') return $order;
            if ($locked->banned) throw new ApiException('账户已停用', 403);
            $this->requireCheckout();
            $channel = Payment::find($payment->id);
            if (!$channel?->enable || !in_array($channel->payment, AiTopupPolicy::PAYMENT_METHODS, true)
                || ($channel->payment === 'BEpusdt' && ($channel->config['fiat'] ?? 'CNY') !== 'CNY')
                || ($channel->payment === 'CoinPayments' && ($channel->config['coinpayments_currency'] ?? '') !== 'CNY')) {
                throw new ApiException('支付方式暂不可用', 422);
            }
            if ($order->payment_id !== null) {
                if ($order->payment_id !== (int) $channel->id || $order->payment_method !== $channel->payment) {
                    throw new ApiException('订单已选择其他支付方式，请取消后重新下单', 409);
                }
                return $order;
            }
            $fee = AiTopupPolicy::handlingFee($order->total_amount, $channel->handling_fee_fixed, $channel->handling_fee_percent);
            AiTopupPolicy::add($order->total_amount, $fee);
            $order->fill(['payment_id' => $channel->id, 'payment_method' => $channel->payment, 'handling_amount' => $fee])->save();
            return $order;
        }, 3);
        if ($order->status !== 'pending') throw new ApiException('订单已支付、取消或超时，请查看最新状态', 409);
        return $order;
    }

    public function checkoutPayment(User $user, string $tradeNo, Payment $payment, callable $gateway): array
    {
        $this->prepareCheckout($user, $tradeNo, $payment);
        $token = (string) Str::uuid();
        $order = DB::transaction(function () use ($user, $tradeNo, $token) {
            [, $order] = $this->lockedOwned($user, $tradeNo);
            $this->expire($order);
            if ($order->status !== 'pending') throw new ApiException('订单状态已变化，请查询最新结果', 409);
            if ($order->checkout_payload !== null) return $order;
            if ($order->checkout_token !== null) throw new ApiException('收银台请求结果待确认，请查询订单；未付款可取消后重新下单', 409);
            $order->update(['checkout_token' => $token, 'checkout_started_at' => time()]);
            return $order;
        }, 3);
        if ($order->checkout_payload !== null) return $order->checkout_payload;
        // A timeout can follow invoice creation. Keep one gateway request per order.
        try {
            $result = $gateway($order);
            if (!is_array($result) || !in_array($result['type'] ?? null, [0, 1, 2], true)
                || !is_string($result['data'] ?? null) || $result['data'] === '' || strlen($result['data']) > 1048576) {
                throw new \RuntimeException('Invalid checkout response');
            }
            DB::transaction(function () use ($user, $tradeNo, $token, $result) {
                [, $locked] = $this->lockedOwned($user, $tradeNo);
                if ($locked->checkout_token === $token) $locked->update(['checkout_payload' => $result]);
            }, 3);
            return $result;
        } catch (Throwable $e) {
            throw new ApiException('收银台请求结果待确认，请查询订单；未付款可取消后重新下单', 503);
        }
    }

    private function expire(AiTopupOrder $order): void
    {
        if ($order->status === 'pending' && $order->expires_at <= time()) {
            $order->update(['status' => 'cancelled', 'cancelled_at' => time()]);
        }
    }

    public function cancel(User $user, string $tradeNo): array
    {
        $this->requireAvailable();
        $order = DB::transaction(function () use ($user, $tradeNo) {
            [, $order] = $this->lockedOwned($user, $tradeNo);
            if ($order->status === 'paid') throw new ApiException('已支付订单正在发放，请查看订单详情', 409);
            if ($order->status === 'pending') $order->update(['status' => 'cancelled', 'cancelled_at' => time()]);
            return $order;
        }, 3);
        return $this->serialize($order);
    }

    public function paid(array $verify, string $method): bool
    {
        if (!$this->available() || !in_array($method, AiTopupPolicy::PAYMENT_METHODS, true) || !is_string($verify['trade_no'] ?? null) || !is_string($verify['callback_no'] ?? null)
            || trim($verify['callback_no']) === '' || strlen($verify['callback_no']) > 255 || ($verify['paid'] ?? null) === false) return false;
        $initial = AiTopupOrder::where('trade_no', $verify['trade_no'])->first();
        if (!$initial) return false;
        return DB::transaction(function () use ($initial, $verify, $method) {
            $user = User::lockForUpdate()->find($initial->user_id);
            $order = AiTopupOrder::lockForUpdate()->find($initial->id);
            if (!$user || !$order || (int) $order->user_id !== (int) $user->id || !$order->payment_id
                || (string) ($verify['payment_id'] ?? '') !== (string) $order->payment_id || $method !== $order->payment_method) return false;
            $expected = AiTopupPolicy::add($order->total_amount, $order->handling_amount);
            $hasExactEvidence = false;
            if (array_key_exists('expected_amount', $verify)) {
                if (!is_int($verify['expected_amount']) || $verify['expected_amount'] !== $expected) return false;
                $hasExactEvidence = true;
            }
            if (array_key_exists('payment_amount', $verify)) {
                if (AiTopupPolicy::callbackCents($verify['payment_amount']) !== $expected) return false;
                $hasExactEvidence = true;
            }
            if (!$hasExactEvidence) return false;
            $receipts = $order->payment_receipts ?? [];
            $receipt = (string) $verify['callback_no'];
            if (!in_array($receipt, $receipts, true) && count($receipts) < 20) {
                $receipts[] = $receipt;
                $order->payment_receipts = $receipts;
            }
            if ($order->status === 'paid') { $order->save(); return true; }
            if (!in_array($order->status, ['pending', 'cancelled'], true)) return false;
            $provider = $this->provider();
            $order->fill(['status' => 'paid', 'paid_at' => time(), 'callback_no' => $verify['callback_no'],
                'issue_status' => 'pending', 'next_attempt_at' => time()]);
            if ($provider) [$order->bridge_url, $order->bridge_token] = $provider;
            $order->save();
            // Queue failure must never roll back a verified payment; the scheduled reconciler recovers it.
            DB::afterCommit(function () use ($order) {
                try { AiTopupFulfillJob::dispatch((int) $order->id); }
                catch (Throwable $e) { /* The durable paid row is the recovery queue. */ }
            });
            return true;
        }, 3);
    }

    public function serialize(AiTopupOrder $order, bool $withCode = false, bool $admin = false): array
    {
        $result = $order->only(['id', 'user_id', 'trade_no', 'status', 'issue_status', 'unit_price_cny', 'total_amount',
            'handling_amount', 'payment_id', 'created_at', 'expires_at', 'paid_at', 'issued_at', 'redeemed_at',
            'traffic_activated_at', 'gift_traffic_bytes', 'last_error']);
        $result['amount_usd'] = (string) $order->amount_usd;
        if ($admin) $result['email'] = $order->user?->email;
        if (count($order->payment_receipts ?? []) > 1) $result['last_error'] = '收到不同付款凭据，请联系客服核对；请勿再次支付本单';
        if ($withCode && !$admin && in_array($order->issue_status, ['issued', 'redeemed'], true)) $result['code'] = $order->code;
        return $result;
    }

    public function records(?User $user, array $filters): array
    {
        $page = max(1, min(1000000, (int) ($filters['current'] ?? 1)));
        $pageSize = max(1, min(100, (int) ($filters['page_size'] ?? 20)));
        $result = ['records' => [], 'total' => 0, 'current' => $page, 'page_size' => $pageSize];
        if (!$this->available()) return $result;
        $query = AiTopupOrder::query();
        if ($user !== null) $query->where('user_id', $user->id);
        else {
            $query->with('user');
            if (!empty($filters['user_id'])) $query->where('user_id', (int) $filters['user_id']);
            if (!empty($filters['trade_no'])) $query->where('trade_no', $filters['trade_no']);
        }
        foreach (['status', 'issue_status'] as $key) if (!empty($filters[$key])) $query->where($key, $filters[$key]);
        $result['total'] = $query->count();
        $result['records'] = $query->latest('id')->offset(($page - 1) * $pageSize)->limit($pageSize)->get()
            ->map(fn ($order) => $this->serialize($order, false, $user === null))->all();
        return $result;
    }

    public function overview(User $user): array
    {
        $settings = $this->settings();
        $freshUser = User::findOrFail($user->id);
        $settings += ['pending_traffic_bytes' => 0, 'active_temporary_traffic_bytes' => max(0, (int) $freshUser->temporary_transfer_enable),
            'can_activate_traffic' => $freshUser->isActive(), 'paid_amount_cents' => 0, 'issued_amount_usd' => '0'];
        $issued = 0;
        if ($this->available()) {
            foreach (AiTopupOrder::where('user_id', $user->id)->where('status', 'paid')->cursor() as $order) {
                $settings['paid_amount_cents'] = AiTopupPolicy::add($settings['paid_amount_cents'], $order->total_amount);
                if (!$order->traffic_activated_at) $settings['pending_traffic_bytes'] = AiTopupPolicy::add($settings['pending_traffic_bytes'], $order->gift_traffic_bytes);
                if ($order->issued_at) $issued = AiTopupPolicy::add($issued, $order->amount_usd);
            }
        }
        $settings['issued_amount_usd'] = (string) $issued;
        return $settings;
    }

    public function activateTraffic(User $user, string $tradeNo): array
    {
        $this->requireAvailable();
        $order = DB::transaction(function () use ($user, $tradeNo) {
            [$locked, $order] = $this->lockedOwned($user, $tradeNo);
            if ($order->status !== 'paid') throw new ApiException('支付成功后即可启用赠送流量', 409);
            if ($order->traffic_activated_at) return $order;
            if (!$locked->isActive()) throw new ApiException('启用赠送流量需要有效订阅', 409);
            if ($order->gift_traffic_bytes <= 0) throw new ApiException('该订单没有待启用赠送流量', 409);
            AiTopupPolicy::add(max(0, (int) $locked->transfer_enable), $order->gift_traffic_bytes);
            AiTopupPolicy::add(max(0, (int) $locked->temporary_transfer_enable), $order->gift_traffic_bytes);
            app(UserService::class)->addTemporaryTraffic($locked, $order->gift_traffic_bytes)->save();
            $order->update(['traffic_activated_at' => time()]);
            return $order;
        }, 3);
        return $this->serialize($order);
    }

    public function detail(User $user, string $tradeNo): array
    {
        $order = $this->ownedOrder($user, $tradeNo);
        if ($order->status === 'pending' && $order->expires_at <= time()) {
            $order = DB::transaction(function () use ($user, $tradeNo) {
                [, $order] = $this->lockedOwned($user, $tradeNo);
                $this->expire($order);
                return $order;
            }, 3);
        }
        if ($order->status === 'paid') $order = $this->fulfill((int) $order->id);
        return $this->serialize($order, true);
    }

    public function fulfill(int $id, bool $force = false): AiTopupOrder
    {
        $this->requireAvailable();
        // Guard callers too: an enclosing transaction would retain locks during HTTP requests.
        if (DB::transactionLevel() > 0) throw new ApiException('发放任务须在订单入账后执行', 409);
        $lease = (string) Str::uuid();
        $order = DB::transaction(function () use ($id, $force, $lease) {
            $initial = AiTopupOrder::findOrFail($id);
            User::lockForUpdate()->findOrFail($initial->user_id);
            $order = AiTopupOrder::lockForUpdate()->findOrFail($id);
            if ($order->status !== 'paid' || in_array($order->issue_status, ['redeemed', 'revoked'], true)) return $order;
            if ($order->lease_until && $order->lease_until > time()) return $order;
            if (!$force && $order->next_attempt_at && $order->next_attempt_at > time()) return $order;
            $order->fill(['lease_token' => $lease, 'lease_until' => time() + self::LEASE_SECONDS,
                'next_attempt_at' => time() + self::LEASE_SECONDS, 'attempts' => min(1000000, $order->attempts + 1)]);
            if ($order->issue_status !== 'issued') $order->issue_status = 'generating';
            // Only unsent paid rows may attach newly restored credentials. Existing snapshots are immutable.
            if (!$order->bridge_url && !$order->bridge_token) {
                $provider = $this->provider();
                if ($provider) [$order->bridge_url, $order->bridge_token] = $provider;
            }
            $order->save();
            return $order;
        }, 3);
        if ($order->lease_token !== $lease) return $order;
        try {
            if (!$order->bridge_url || !$order->bridge_token) throw new ApiException('发码配置待恢复', 503);
            $data = $this->client->request('GET', $order->bridge_url, $order->bridge_token, $order->external_ref);
            if ($data === null) {
                if ($order->issued_at) throw new ApiException('原兑换码记录待提供方核对', 503);
                $data = $this->client->request('POST', $order->bridge_url, $order->bridge_token, $order->external_ref, (string) $order->amount_usd);
            }
            $this->applyRemote($order, $data, $lease);
        } catch (Throwable $e) {
            // Error text is deliberately fixed: provider exceptions may include codes, tokens or request headers.
            AiTopupOrder::whereKey($id)->where('lease_token', $lease)->update([
                'issue_status' => $order->issued_at ? 'issued' : 'reconciling', 'lease_token' => null, 'lease_until' => null,
                'next_attempt_at' => time() + min(3600, 30 * (2 ** min(7, $order->attempts))),
                'last_error' => self::RETRY_ERROR, 'updated_at' => time(),
            ]);
        }
        return AiTopupOrder::findOrFail($id);
    }

    private function applyRemote(AiTopupOrder $original, ?array $data, string $lease): void
    {
        if (!$data || ($data['external_ref'] ?? null) !== $original->external_ref || ($data['type'] ?? null) !== 'balance'
            || !AiTopupPolicy::remoteAmountMatches($data['value'] ?? null, $original->amount_usd)
            || !is_scalar($data['id'] ?? null) || strlen((string) $data['id']) > 64
            || !is_string($data['code'] ?? null) || $data['code'] === '' || strlen($data['code']) > 256
            || !in_array($data['status'] ?? null, ['unused', 'used', 'expired'], true)) {
            throw new ApiException('提供方结果待核对', 503);
        }
        DB::transaction(function () use ($original, $data, $lease) {
            User::lockForUpdate()->findOrFail($original->user_id);
            $order = AiTopupOrder::lockForUpdate()->findOrFail($original->id);
            if ($order->lease_token !== $lease || $order->status !== 'paid' || in_array($order->issue_status, ['redeemed', 'revoked'], true)) return;
            if (($order->upstream_id && $order->upstream_id !== (string) $data['id']) || ($order->code && $order->code !== $data['code'])) {
                throw new ApiException('原兑换码绑定信息发生变化，待核对', 503);
            }
            $status = match ($data['status']) { 'used' => 'redeemed', 'expired' => 'revoked', default => 'issued' };
            $order->fill(['issue_status' => $status, 'upstream_id' => (string) $data['id'], 'code' => $data['code'],
                'issued_at' => $order->issued_at ?? time(), 'redeemed_at' => $status === 'redeemed' ? ($order->redeemed_at ?? time()) : null,
                'lease_token' => null, 'lease_until' => null, 'next_attempt_at' => $status === 'issued' ? time() + 3600 : null, 'last_error' => null])->save();
        }, 3);
    }

    public function reconcile(int $limit = 20, bool $dryRun = false): array
    {
        $result = ['pending' => 0, 'processed' => 0, 'issued' => 0, 'reconciling' => 0];
        if (!$this->available()) return $result;
        $query = AiTopupOrder::where('status', 'paid')->whereIn('issue_status', ['pending', 'generating', 'reconciling', 'issued'])
            ->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<=', time()))
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', time()));
        $result['pending'] = (clone $query)->where('issue_status', '!=', 'issued')->count();
        if ($dryRun) return $result;
        $ids = $query->orderBy('next_attempt_at')->orderBy('id')->limit(max(1, min(100, $limit)))->pluck('id');
        foreach ($ids as $id) {
            try {
                $order = $this->fulfill((int) $id);
                $result['processed']++;
                if (in_array($order->issue_status, ['issued', 'redeemed'], true)) $result['issued']++;
                if ($order->issue_status === 'reconciling') $result['reconciling']++;
            } catch (Throwable $e) {
                AiTopupOrder::whereKey($id)->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<=', time()))
                    ->update(['next_attempt_at' => time() + 300, 'last_error' => self::RETRY_ERROR, 'updated_at' => time()]);
                $result['reconciling']++;
            }
        }
        return $result;
    }
}
