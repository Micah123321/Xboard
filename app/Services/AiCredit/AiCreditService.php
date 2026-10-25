<?php

namespace App\Services\AiCredit;

use App\Exceptions\ApiException;
use App\Models\AiCreditGrant;
use App\Models\AiCreditSetting;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class AiCreditService
{
    public function __construct(private AiCreditClient $client) {}

    public static function defaults(): array
    {
        return [
            'enabled' => false, 'title' => 'AI 中转福利',
            'description' => '购买流量套餐，领取 AI 中转额度。公开号池不掺水，模型价格与缓存率公开可查。',
            'site_url' => 'https://codex.ccbvv.com/',
            'pricing_url' => 'https://codex-agent.ccbvv.com/pricing',
            'transparency_url' => 'https://codex-agent.ccbvv.com/transparency',
            'rate_usd_per_gb' => '0.05', 'min_paid_cents' => 1500,
            'include_balance' => false, 'legacy_enabled' => true, 'plan_ids' => [],
            'starts_at' => null, 'ends_at' => null, 'bridge_url' => '',
            'version' => 1, 'activated_at' => null, 'legacy_max_order_id' => null,
        ];
    }

    public function available(): bool
    {
        return DB::connection()->getSchemaBuilder()->hasTable('v2_ai_credit_settings')
            && DB::connection()->getSchemaBuilder()->hasTable('v2_ai_credit_grants');
    }

    private function model(): AiCreditSetting
    {
        return AiCreditSetting::firstOrCreate(['id' => 1], [
            'instance_id' => (string) Str::uuid(), 'settings' => self::defaults(),
        ]);
    }

    public function settings(bool $public = false): array
    {
        $model = $this->available() ? AiCreditSetting::find(1) : null;
        $settings = array_replace(self::defaults(), $model?->settings ?? []);
        $settings['orders_since_at'] = self::ordersSinceAt();
        $settings['bridge_token_set'] = (bool) $model?->bridge_token;
        $settings['provider_ready'] = $settings['bridge_token_set'] && $settings['bridge_url'] !== '';
        if ($public) {
            unset($settings['bridge_url'], $settings['bridge_token_set'], $settings['activated_at'], $settings['legacy_max_order_id']);
        }
        return $settings;
    }

    public function saveSettings(array $input): array
    {
        if (!$this->available()) throw new ApiException('请先执行 AI 福利数据库迁移', 503);
        $data = Validator::make($input, [
            'enabled' => 'sometimes|boolean', 'title' => 'sometimes|string|max:80',
            'description' => 'sometimes|string|max:2000',
            'site_url' => 'sometimes|url:http,https|max:2048',
            'pricing_url' => 'sometimes|url:http,https|max:2048',
            'transparency_url' => 'sometimes|url:http,https|max:2048',
            'rate_usd_per_gb' => ['sometimes', 'regex:/\A(?:0|[1-9][0-9]{0,3})(?:\.[0-9]{1,4})?\z/'],
            'min_paid_cents' => 'sometimes|integer|min:1500|max:100000000',
            'include_balance' => 'sometimes|boolean', 'legacy_enabled' => 'sometimes|boolean',
            'plan_ids' => 'sometimes|array|max:1000', 'plan_ids.*' => 'integer|distinct|exists:v2_plan,id',
            'starts_at' => 'nullable|integer|min:0', 'ends_at' => 'nullable|integer|min:0',
            'bridge_url' => 'sometimes|nullable|url:http,https|max:2048',
            'bridge_token' => ['sometimes', 'nullable', 'string', 'min:32', 'max:512', 'regex:/\A[^\r
]+\z/'],
        ])->validate();
        if (isset($data['rate_usd_per_gb'])) {
            try { AiCreditPolicy::rateUnits((string) $data['rate_usd_per_gb']); }
            catch (\InvalidArgumentException $e) { throw new ApiException($e->getMessage()); }
            $data['rate_usd_per_gb'] = (string) $data['rate_usd_per_gb'];
        }
        DB::transaction(function () use ($data) {
            $this->model();
            $model = AiCreditSetting::lockForUpdate()->findOrFail(1);
            $settings = array_replace(self::defaults(), $model->settings);
            $token = $data['bridge_token'] ?? null;
            $changes = $data;
            unset($changes['bridge_token']);
            $settings = array_replace($settings, $changes);
            $settings['bridge_url'] = rtrim((string) $settings['bridge_url'], '/');
            $settings['plan_ids'] = array_map('intval', $settings['plan_ids']);
            if ($settings['starts_at'] && $settings['ends_at'] && $settings['ends_at'] <= $settings['starts_at']) {
                throw new ApiException('结束时间须晚于开始时间');
            }
            if ($settings['bridge_url'] !== '') {
                $parts = parse_url($settings['bridge_url']);
                if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
                    throw new ApiException('发码服务地址须为不带账号、查询参数和片段的 HTTP 地址');
                }
            }
            if ($token !== null && $token !== '') $model->bridge_token = $token;
            if ($settings['enabled'] && !$settings['activated_at']) {
                $settings['activated_at'] = time();
                $settings['legacy_max_order_id'] = (int) Order::max('id');
            }
            $settings['version'] = (int) $settings['version'] + 1;
            $model->settings = $settings;
            $model->save();
        });
        return $this->settings();
    }

    private function active(array $settings, ?int $at = null): bool
    {
        $at ??= time();
        return $settings['enabled']
            && (!$settings['starts_at'] || $at >= $settings['starts_at'])
            && (!$settings['ends_at'] || $at <= $settings['ends_at']);
    }

    public function planBenefit(Plan|array $plan): array
    {
        $settings = $this->settings();
        return [
            'enabled' => $this->active($settings) && (!$settings['plan_ids'] || in_array((int) $plan['id'], $settings['plan_ids'], true)),
            'amount_usd' => AiCreditPolicy::money(AiCreditPolicy::amountCents((int) $plan['transfer_enable'], $settings['rate_usd_per_gb'])),
            'rate_usd_per_gb' => $settings['rate_usd_per_gb'], 'min_paid_cents' => $settings['min_paid_cents'],
        ];
    }

    public function onOrderCompleted(Order $order, Plan $plan, ?array $previousPlan = null): ?AiCreditGrant
    {
        if (!$this->available()) return null;
        $settings = $this->settings();
        if ($settings['activated_at']) {
            // Once evaluated after launch, an ineligible order must never become a legacy gift on a later refresh.
            DB::table('v2_order')->where('id', $order->id)->update(['ai_credit_evaluated_at' => time()]);
        }
        if (!$this->active($settings) || (int) $order->paid_at < self::ordersSinceAt() || AiCreditPolicy::orderReason($order, $settings)) return null;
        if ((int) $order->type === Order::TYPE_UPGRADE
            && (!$previousPlan || (int) $previousPlan['id'] === (int) $plan->id || (int) $previousPlan['traffic_gb'] >= (int) $plan->transfer_enable)) return null;
        return $this->createGrant($order, $plan, $settings, 'order');
    }

    private function createGrant(Order $order, Plan $plan, array $settings, string $source): ?AiCreditGrant
    {
        $cents = AiCreditPolicy::amountCents((int) $plan->transfer_enable, $settings['rate_usd_per_gb']);
        if ($cents <= 0) return null;
        $instance = $this->model()->instance_id;
        return AiCreditGrant::firstOrCreate(['order_id' => $order->id], [
            'user_id' => $order->user_id, 'legacy_user_id' => $source === 'legacy' ? $order->user_id : null,
            'external_ref' => $instance . ':order:' . $order->id, 'trade_no' => $order->trade_no,
            'plan_id' => $plan->id, 'plan_name' => $plan->name, 'traffic_gb' => (int) $plan->transfer_enable,
            'paid_amount_cents' => AiCreditPolicy::paidCents($order, (bool) $settings['include_balance']),
            'amount_usd_cents' => $cents,
            'policy_snapshot' => array_intersect_key($settings, array_flip(['version', 'rate_usd_per_gb', 'min_paid_cents', 'include_balance', 'orders_since_at'])),
            'source' => $source, 'status' => 'eligible',
        ]);
    }

    public static function ordersSinceAt(): int
    {
        return (new \DateTimeImmutable('2026-10-20T00:00:00+08:00'))->getTimestamp();
    }

    private function currentSubscriptionOrder(Builder $query): void
    {
        // Check the latest fulfilled subscription before testing its price; an older paid plan is not a substitute.
        $query->whereColumn('v2_user.plan_id', 'v2_order.plan_id')
            ->where(fn ($q) => $q->whereNull('v2_user.expired_at')->orWhere('v2_user.expired_at', '>', time()))
            ->where('v2_order.id', function ($q) {
                $q->select('recent.id')->from('v2_order as recent')->whereColumn('recent.user_id', 'v2_order.user_id')
                    ->whereIn('recent.status', [Order::STATUS_COMPLETED, Order::STATUS_DISCOUNTED])
                    ->where('recent.period', '!=', Plan::PERIOD_RESET_TRAFFIC)
                    ->orderByDesc('recent.paid_at')->orderByDesc('recent.id')->limit(1);
            })
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('v2_ai_credit_grants as l')->whereColumn('l.legacy_user_id', 'v2_order.user_id'));
    }

    private function legacyOrders(array $settings, bool $currentOnly = false): Builder
    {
        $since = self::ordersSinceAt();
        return Order::query()->select('v2_order.*')->with('plan')
            ->join('v2_user', 'v2_user.id', '=', 'v2_order.user_id')->where('v2_user.banned', 0)
            ->where(function (Builder $query) use ($currentOnly, $since) {
                if ($currentOnly) {
                    $this->currentSubscriptionOrder($query);
                } else {
                    $query->where('v2_order.paid_at', '>=', $since)
                        ->orWhere(function (Builder $current) use ($since) {
                            $current->where('v2_order.paid_at', '<', $since);
                            $this->currentSubscriptionOrder($current);
                        });
                }
            })
            ->where('v2_order.status', Order::STATUS_COMPLETED)->whereIn('v2_order.type', [1, 2, 3])
            ->where('v2_order.period', '!=', Plan::PERIOD_RESET_TRAFFIC)
            ->whereNull('v2_order.ai_credit_evaluated_at')
            ->where('v2_order.id', '<=', (int) $settings['legacy_max_order_id'])
            ->where('v2_order.paid_at', '>', 0)->where('v2_order.paid_at', '<=', (int) $settings['activated_at'])
            ->whereNotNull('v2_order.callback_no')->where('v2_order.callback_no', '!=', '')
            ->where('v2_order.callback_no', '!=', 'manual_operation')
            ->where(fn ($q) => $q->where('v2_order.total_amount', '<=', 0)->orWhere('v2_order.payment_id', '>', 0))
            ->whereRaw('COALESCE(v2_order.total_amount, 0)' . ($settings['include_balance'] ? ' + COALESCE(v2_order.balance_amount, 0)' : '') . ' > ?', [(int) $settings['min_paid_cents']])
            ->when($settings['plan_ids'], fn ($q) => $q->whereIn('v2_order.plan_id', $settings['plan_ids']))
            ->whereHas('plan', fn ($q) => $q->where('transfer_enable', '>', 0))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('v2_ai_credit_grants as g')->whereColumn('g.order_id', 'v2_order.id'))
            ->orderBy('v2_order.id');
    }

    private function backfillSource(Order $order): string
    {
        return (int) $order->paid_at >= self::ordersSinceAt() ? 'order' : 'legacy';
    }

    public function prepareLegacy(User $user, bool $preserveBeforePurchase = false): ?AiCreditGrant
    {
        if (!$this->available()) return null;
        $settings = $this->settings();
        if ((!$preserveBeforePurchase && !$this->active($settings)) || !$settings['legacy_enabled'] || !$settings['activated_at']) return null;
        return DB::transaction(function () use ($user, $settings) {
            $locked = User::lockForUpdate()->findOrFail($user->id);
            if (!$locked->isActive()) return null;
            $order = $this->legacyOrders($settings, true)->where('v2_order.user_id', $locked->id)->lockForUpdate()->first();
            if (!$order?->plan) return null;
            return $this->createGrant($order, $order->plan, $settings, $this->backfillSource($order));
        });
    }

    public function prepareHistorical(User $user, int $limit = 100): int
    {
        if (!$this->available()) return 0;
        $settings = $this->settings();
        if (!$this->active($settings) || !$settings['legacy_enabled'] || !$settings['activated_at']) return 0;
        return DB::transaction(function () use ($user, $settings, $limit) {
            $locked = User::lockForUpdate()->findOrFail($user->id);
            if ($locked->banned) return 0;
            $orders = $this->legacyOrders($settings)->where('v2_order.user_id', $locked->id)
                ->limit(max(1, min(100, $limit)))->lockForUpdate()->get();
            $created = 0;
            foreach ($orders as $order) {
                if ($order->plan && $this->createGrant($order, $order->plan, $settings, $this->backfillSource($order))?->wasRecentlyCreated) $created++;
            }
            return $created;
        });
    }

    public function legacyPreview(int $limit = 200): array
    {
        $settings = $this->settings();
        if (!$this->available() || !$this->active($settings) || !$settings['legacy_enabled'] || !$settings['activated_at']) {
            return ['eligible_count' => 0, 'total_amount_usd' => '0.00', 'records' => [], 'truncated' => false];
        }
        $orders = $this->legacyOrders($settings)->with('user')->limit($limit + 1)->get();
        $records = []; $sum = 0;
        foreach ($orders->take($limit) as $order) {
            if (!$order->plan) continue;
            $cents = AiCreditPolicy::amountCents((int) $order->plan->transfer_enable, $settings['rate_usd_per_gb']);
            if ($cents <= 0) continue;
            $sum += $cents;
            $records[] = [
                'user_id' => $order->user_id, 'email' => $order->user?->email, 'order_id' => $order->id,
                'trade_no' => $order->trade_no, 'plan_name' => $order->plan->name,
                'traffic_gb' => (int) $order->plan->transfer_enable,
                'paid_amount_cents' => AiCreditPolicy::paidCents($order, $settings['include_balance']),
                'amount_usd' => AiCreditPolicy::money($cents), 'source' => $this->backfillSource($order),
            ];
        }
        return ['eligible_count' => count($records), 'total_amount_usd' => AiCreditPolicy::money($sum), 'records' => $records, 'truncated' => $orders->count() > $limit];
    }

    public function backfill(int $limit): array
    {
        $preview = $this->legacyPreview($limit);
        $created = 0;
        foreach ($preview['records'] as $record) {
            $created += DB::transaction(function () use ($record) {
                $user = User::lockForUpdate()->find($record['user_id']);
                $settings = $this->settings();
                if (!$user || $user->banned || !$this->active($settings) || !$settings['legacy_enabled']) return 0;
                // Revalidate this exact previewed order; a per-user preparation could exceed the chosen batch size.
                $order = $this->legacyOrders($settings)->where('v2_order.user_id', $user->id)
                    ->where('v2_order.id', $record['order_id'])->lockForUpdate()->first();
                return $order?->plan && $this->createGrant($order, $order->plan, $settings, $this->backfillSource($order))?->wasRecentlyCreated ? 1 : 0;
            });
        }
        return ['created' => $created, 'skipped' => count($preview['records']) - $created, 'has_more' => $preview['truncated']];
    }

    public function serialize(AiCreditGrant $grant, bool $withCode = false, bool $admin = false): array
    {
        $result = $grant->only(['id', 'user_id', 'order_id', 'trade_no', 'plan_name', 'traffic_gb', 'paid_amount_cents', 'source', 'status', 'created_at', 'issued_at', 'redeemed_at', 'last_error']);
        $result['amount_usd'] = AiCreditPolicy::money($grant->amount_usd_cents);
        if ($admin) $result['email'] = $grant->relationLoaded('user') ? $grant->user?->email : null;
        if ($withCode && in_array($grant->status, ['issued', 'redeemed'], true)) $result['code'] = $grant->code;
        return $result;
    }

    public function overview(User $user): array
    {
        $settings = $this->settings(true);
        $settings['enabled'] = $this->active($settings);
        $grants = $this->available() ? AiCreditGrant::where('user_id', $user->id)->orderByRaw("CASE WHEN status IN ('eligible', 'failed', 'generating', 'reconciling') THEN 0 ELSE 1 END")->latest('id')->limit(100)->get() : collect();
        $settings['grants'] = $grants->map(fn ($g) => $this->serialize($g))->all();
        $settings['eligible_count'] = $this->available() ? AiCreditGrant::where('user_id', $user->id)->where('status', 'eligible')->count() : 0;
        $settings['history_count'] = $this->available() ? AiCreditGrant::where('user_id', $user->id)->where('status', '!=', 'eligible')->count() : 0;
        $settings['eligible_amount_usd'] = AiCreditPolicy::money($this->available() ? (int) AiCreditGrant::where('user_id', $user->id)->where('status', 'eligible')->sum('amount_usd_cents') : 0);
        $settings['issued_amount_usd'] = AiCreditPolicy::money($this->available() ? (int) AiCreditGrant::where('user_id', $user->id)->whereNotNull('issued_at')->sum('amount_usd_cents') : 0);
        $settings['ineligible_reason'] = !$this->available() ? '福利活动正在准备中'
            : (!$settings['enabled'] ? '活动暂未开放领取，已生成的兑换码可继续查看'
                : (!$user->isActive() && $grants->isEmpty() ? '订阅有效后可查看领取资格'
                    : ($grants->isEmpty() ? '暂无合格订单：订单实付须大于 ¥' . AiCreditPolicy::money($settings['min_paid_cents']) . '，历史订单缺少付款凭据时请联系客服' : null)));
        return $settings;
    }

    public function userRecords(User $user, int $page = 1, int $pageSize = 20, ?string $kind = null, ?string $status = null): array
    {
        if (!$this->available()) return ['records' => [], 'total' => 0, 'current' => $page, 'page_size' => $pageSize];
        $query = AiCreditGrant::where('user_id', $user->id);
        if ($kind === 'available') $query->where('status', 'eligible');
        if ($kind === 'history') $query->where('status', '!=', 'eligible');
        if ($status !== null) $query->where('status', $status);
        $records = $query->latest('id')->paginate($pageSize, ['*'], 'page', $page);
        return ['records' => $records->getCollection()->map(fn ($g) => $this->serialize($g))->all(),
            'total' => $records->total(), 'current' => $page, 'page_size' => $pageSize];
    }

    private function ownedGrant(int $id, int $userId): AiCreditGrant
    {
        $grant = AiCreditGrant::where('user_id', $userId)->find($id);
        if (!$grant) throw new ApiException('福利记录不存在', 404);
        return $grant;
    }

    public function claim(int $id, User $user): array
    {
        if (!$this->available()) throw new ApiException('福利活动正在准备中', 503);
        $grant = $this->ownedGrant($id, (int) $user->id);
        if (in_array($grant->status, ['issued', 'redeemed'], true)) return $this->serialize($grant, true);
        if ($user->banned) throw new ApiException('账户已停用', 403);
        $settings = $this->settings();
        if (!$this->active($settings)) throw new ApiException('活动暂未开放领取');
        if (!$settings['provider_ready']) throw new ApiException('发码服务正在准备中，请稍后再试', 503);
        return $this->serialize($this->process($id, true, (int) $user->id), true);
    }

    public function detail(int $id, User $user): array
    {
        $grant = $this->ownedGrant($id, (int) $user->id);
        if (in_array($grant->status, ['generating', 'reconciling', 'issued'], true)) {
            $grant = $this->refreshRemote($grant);
        }
        return $this->serialize($grant, true);
    }

    private function credentials(AiCreditGrant $grant): array
    {
        $settings = $this->settings();
        $model = AiCreditSetting::find(1);
        if (!$settings['provider_ready'] || !$model?->bridge_token) throw new ApiException('发码服务正在准备中', 503);
        if ($grant->bridge_url && $grant->bridge_url !== $settings['bridge_url']) throw new ApiException('发码服务地址已变更，请管理员核对原服务记录', 503);
        return [$grant->bridge_url ?: $settings['bridge_url'], $model->bridge_token];
    }

    public function process(int $id, bool $start = false, ?int $userId = null): AiCreditGrant
    {
        $lease = (string) Str::uuid();
        $grant = DB::transaction(function () use ($id, $start, $userId, $lease) {
            $initial = AiCreditGrant::findOrFail($id);
            // Match the fulfillment lock order so claiming and new purchases do not deadlock.
            $user = User::lockForUpdate()->findOrFail($initial->user_id);
            $order = Order::lockForUpdate()->find($initial->order_id);
            $grant = AiCreditGrant::lockForUpdate()->findOrFail($id);
            if ($userId !== null && (int) $grant->user_id !== $userId) throw new ApiException('福利记录不存在', 404);
            if ($user->banned) throw new ApiException('账户已停用', 403);
            if (!in_array($grant->status, ['eligible', 'failed', 'generating', 'reconciling'], true)) return $grant;
            if (!$order || !in_array((int) $order->status, [Order::STATUS_COMPLETED, Order::STATUS_DISCOUNTED], true)) {
                $grant->update(['status' => 'revoked', 'last_error' => '来源订单已失效']);
                return $grant;
            }
            if (!$start && in_array($grant->status, ['eligible', 'failed'], true)) return $grant;
            if ($grant->lease_until && $grant->lease_until > time()) return $grant;
            if (!$start && $grant->next_attempt_at && $grant->next_attempt_at > time()) return $grant;
            [$baseUrl] = $this->credentials($grant);
            $grant->fill(['status' => 'generating', 'bridge_url' => $baseUrl, 'lease_token' => $lease,
                'lease_until' => time() + 90, 'next_attempt_at' => time() + 90, 'attempts' => $grant->attempts + 1])->save();
            return $grant;
        });
        if ($grant->lease_token !== $lease) return $grant;
        try {
            [$url, $token] = $this->credentials($grant);
            $data = $grant->attempts > 1 ? $this->client->request('GET', $url, $token, $grant->external_ref) : null;
            if ($data === null) {
                if (!$this->active($this->settings())) throw new ApiException('活动已暂停，原请求保留并等待核对', 503);
                $data = $this->client->request('POST', $url, $token, $grant->external_ref, AiCreditPolicy::money($grant->amount_usd_cents));
            }
            $this->applyRemote($grant->id, $data, $lease);
        } catch (Throwable $e) {
            AiCreditGrant::whereKey($id)->where('lease_token', $lease)->update([
                'status' => 'reconciling', 'lease_token' => null, 'lease_until' => null,
                'next_attempt_at' => time() + min(3600, 30 * (2 ** min(6, $grant->attempts))),
                'last_error' => $e instanceof ApiException ? $e->getMessage() : '网络请求结果待核对，系统将继续处理原请求',
                'updated_at' => time(),
            ]);
        }
        return AiCreditGrant::findOrFail($id);
    }

    private function applyRemote(int $id, array $data, ?string $lease = null): void
    {
        DB::transaction(function () use ($id, $data, $lease) {
            $grant = AiCreditGrant::lockForUpdate()->findOrFail($id);
            if ($grant->status === 'revoked' || ($lease !== null && $grant->lease_token !== $lease)) return;
            if (!is_numeric($data['value'] ?? null) || abs((float) $data['value'] * 100 - $grant->amount_usd_cents) > 0.001) {
                throw new ApiException('兑换码金额与订单权益不一致，请管理员核对', 503);
            }
            $status = match ($data['status']) { 'used' => 'redeemed', 'expired' => 'revoked', default => 'issued' };
            $usedAt = $data['used_at'] ?? null;
            $redeemedAt = $usedAt ? (is_numeric($usedAt) ? (int) $usedAt : (strtotime($usedAt) ?: time())) : time();
            $grant->fill([
                'status' => $status, 'upstream_id' => (string) $data['id'], 'code' => $data['code'],
                'issued_at' => $grant->issued_at ?? time(), 'redeemed_at' => $status === 'redeemed' ? $redeemedAt : null,
                'lease_token' => null, 'lease_until' => null, 'next_attempt_at' => null, 'last_error' => null,
            ])->save();
        });
    }

    private function refreshRemote(AiCreditGrant $grant): AiCreditGrant
    {
        try {
            [$url, $token] = $this->credentials($grant);
            $data = $this->client->request('GET', $url, $token, $grant->external_ref);
            if ($data !== null) $this->applyRemote($grant->id, $data);
        } catch (Throwable $e) {
            // Keep the last confirmed code available during a transient status-query failure.
        }
        return $grant->fresh();
    }

    public function reconcile(int $id): array
    {
        $grant = AiCreditGrant::findOrFail($id);
        if (in_array($grant->status, ['issued', 'redeemed'], true)) return $this->serialize($this->refreshRemote($grant), false, true);
        return $this->serialize($this->process($id), false, true);
    }

    public function revoke(int $id, string $reason): array
    {
        $lease = (string) Str::uuid();
        $grant = DB::transaction(function () use ($id, $reason, $lease) {
            $grant = AiCreditGrant::lockForUpdate()->findOrFail($id);
            if (in_array($grant->status, ['eligible', 'failed', 'revoked'], true)) {
                $grant->update(['status' => 'revoked', 'last_error' => mb_substr($reason, 0, 255)]);
                return $grant;
            }
            if ($grant->status === 'redeemed') throw new ApiException('兑换码已经使用，已兑换余额需另行处理');
            if ($grant->status !== 'issued') throw new ApiException('发码结果仍在核对中，请先核对原请求');
            if ($grant->lease_until && $grant->lease_until > time()) throw new ApiException('该记录正在处理，请稍后再试');
            $grant->update(['lease_token' => $lease, 'lease_until' => time() + 90]);
            return $grant;
        });
        if ($grant->status === 'revoked') return $this->serialize($grant, false, true);
        try {
            [$url, $token] = $this->credentials($grant);
            $data = $this->client->request('EXPIRE', $url, $token, $grant->external_ref);
            if (($data['status'] ?? null) !== 'expired') throw new ApiException('上游尚未确认作废，请核对原请求', 503);
            $this->applyRemote($id, $data);
            AiCreditGrant::whereKey($id)->where('status', 'revoked')->update(['last_error' => mb_substr($reason, 0, 255)]);
        } catch (Throwable $e) {
            $this->refreshRemote($grant);
            $current = $grant->fresh();
            if ($current->status !== 'revoked') throw new ApiException($current->status === 'redeemed'
                ? '兑换码已经使用，已兑换余额需另行处理' : '作废结果待核对，请稍后核对原请求', 409);
        } finally {
            AiCreditGrant::whereKey($id)->where('lease_token', $lease)->update(['lease_token' => null, 'lease_until' => null]);
        }
        return $this->serialize($grant->fresh(), false, true);
    }

    public function records(array $filters): array
    {
        if (!$this->available()) return ['records' => [], 'total' => 0, 'summary' => $this->summary()];
        $query = AiCreditGrant::with('user');
        if (!empty($filters['status'])) $query->where('status', $filters['status']);
        if (!empty($filters['user_id'])) $query->where('user_id', (int) $filters['user_id']);
        $page = $query->latest('id')->paginate((int) ($filters['page_size'] ?? 20), ['*'], 'page', (int) ($filters['current'] ?? 1));
        return ['records' => $page->getCollection()->map(fn ($g) => $this->serialize($g, false, true))->all(), 'total' => $page->total(), 'summary' => $this->summary()];
    }

    private function summary(): array
    {
        $summary = ['eligible_count' => 0, 'issued_count' => 0, 'redeemed_count' => 0, 'reconciling_count' => 0, 'eligible_amount_usd' => '0.00', 'issued_amount_usd' => '0.00'];
        if (!$this->available()) return $summary;
        foreach (['eligible', 'issued', 'redeemed', 'reconciling'] as $status) $summary[$status . '_count'] = AiCreditGrant::where('status', $status)->count();
        $summary['issued_count'] = AiCreditGrant::whereNotNull('issued_at')->count();
        $summary['reconciling_count'] += AiCreditGrant::where('status', 'generating')->count();
        $summary['eligible_amount_usd'] = AiCreditPolicy::money((int) AiCreditGrant::where('status', 'eligible')->sum('amount_usd_cents'));
        $summary['issued_amount_usd'] = AiCreditPolicy::money((int) AiCreditGrant::whereNotNull('issued_at')->sum('amount_usd_cents'));
        return $summary;
    }
}
