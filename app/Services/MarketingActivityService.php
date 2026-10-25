<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\MarketingActivity;
use App\Models\User;
use App\Services\AiCredit\AiCreditClient;
use App\Services\AiCredit\AiCreditService;
use App\Services\AiTopup\AiTopupService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class MarketingActivityService
{
    private const FIELDS = ['code', 'source', 'title', 'badge', 'summary', 'benefits', 'image_url',
        'target_url', 'button_text', 'theme', 'sort', 'published', 'starts_at', 'ends_at'];
    private const TEXT_FIELDS = ['title', 'badge', 'summary', 'button_text'];
    private const DEFAULTS = ['code' => null, 'source' => 'custom', 'badge' => '', 'benefits' => [],
        'theme' => 'navy', 'sort' => 0, 'published' => false, 'starts_at' => null, 'ends_at' => null];

    private function available(): bool
    {
        return DB::connection()->getSchemaBuilder()->hasTable('v2_marketing_activities');
    }

    public function fetchForUser(User $user): array
    {
        if (!$this->available()) return [];
        $now = Carbon::now()->getTimestamp();
        $models = MarketingActivity::where('published', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now))
            ->orderBy('sort')->orderBy('id')->get();
        if ($models->isEmpty()) return [];
        $sources = $this->sources();
        $result = [];
        foreach ($models as $model) {
            if (!($sources[$model->source] ?? false)) continue;
            $record = $this->serialize($model, $sources, $now);
            $replacements = [];
            if ($model->source === 'invite') {
                $replacements = [
                    '{invite_commission_percent}' => $this->percent($user->commission_rate ?: admin_setting('invite_commission', 10)),
                    // OrderService awards one tenth of the first paid plan's traffic.
                    '{invite_traffic_percent}' => '10',
                ];
            } elseif ($model->source === 'ai_topup') {
                $replacements = ['{ai_topup_bonus_percent}' => $this->percent($sources['bonus_rate_bps'] / 100)];
            }
            foreach (self::TEXT_FIELDS as $field) $record[$field] = strtr($record[$field], $replacements);
            $record['benefits'] = array_map(fn ($text) => strtr($text, $replacements), $record['benefits']);
            $result[] = $record;
        }
        return $result;
    }

    public function records(array $input): array
    {
        $this->validate($input, ['current' => 'sometimes|integer|min:1|max:10000000',
            'page_size' => 'sometimes|integer|min:1|max:100'], ['current', 'page_size']);
        $current = (int) ($input['current'] ?? 1);
        $size = (int) ($input['page_size'] ?? 20);
        $response = ['records' => [], 'total' => 0, 'current' => $current, 'page_size' => $size];
        if (!$this->available()) return $response;
        $response['total'] = MarketingActivity::count();
        $sources = $this->sources();
        $now = Carbon::now()->getTimestamp();
        $response['records'] = MarketingActivity::orderBy('sort')->orderBy('id')
            ->offset(($current - 1) * $size)->limit($size)->get()
            ->map(fn ($model) => $this->serialize($model, $sources, $now))->all();
        return $response;
    }

    public function save(array $input): MarketingActivity
    {
        $this->requireAvailable();
        $this->validate($input, ['id' => 'sometimes|integer|min:1',
            'version' => 'sometimes|integer|min:1|max:4294967294'], array_merge(self::FIELDS, ['id', 'version']));
        $model = null;
        if (array_key_exists('id', $input)) {
            if (!array_key_exists('version', $input)) throw new ApiException('更新活动须提供版本号', 422);
            $model = MarketingActivity::find((int) $input['id']);
            $this->checkVersion($model, (int) $input['version']);
        } elseif (array_key_exists('version', $input) && (int) $input['version'] !== 1) {
            throw new ApiException('新活动版本须为 1', 422);
        }
        $data = array_replace(self::DEFAULTS, $model ? $model->only(self::FIELDS) : [],
            array_intersect_key($input, array_flip(self::FIELDS)));
        $data['badge'] ??= '';
        $this->validateActivity($data);
        if ($data['code'] !== null && MarketingActivity::where('code', $data['code'])
            ->when($model, fn ($q) => $q->where('id', '!=', $model->id))->exists()) {
            throw new ApiException('活动标识已存在', 422);
        }
        try {
            if (!$model) return MarketingActivity::create(array_merge($data, ['version' => 1]));
            $model->fill($data);
            return $this->updateVersion($model, (int) $input['version']);
        } catch (UniqueConstraintViolationException $e) {
            throw new ApiException('活动标识已存在', 422);
        }
    }

    public function publish(int $id, int $version, bool $published): MarketingActivity
    {
        $this->requireAvailable();
        $this->validate(['id' => $id, 'version' => $version], [
            'id' => 'required|integer|min:1', 'version' => 'required|integer|min:1|max:4294967294']);
        $model = MarketingActivity::find($id);
        $this->checkVersion($model, $version);
        $this->validateActivity($model->only(self::FIELDS));
        $model->published = $published;
        return $this->updateVersion($model, $version);
    }

    public function delete(int $id, int $version): void
    {
        $this->requireAvailable();
        $this->validate(['id' => $id, 'version' => $version], [
            'id' => 'required|integer|min:1', 'version' => 'required|integer|min:1|max:4294967294']);
        if (MarketingActivity::whereKey($id)->where('version', $version)->delete() !== 1) {
            throw new ApiException('活动已更新或删除，请刷新后重试', 409);
        }
    }

    public function serialize(MarketingActivity $model, ?array $sources = null, ?int $now = null): array
    {
        $record = $model->only(array_merge(['id'], self::FIELDS, ['version', 'created_at', 'updated_at']));
        $now ??= Carbon::now()->getTimestamp();
        $sources ??= $this->sources();
        $record['status'] = match (true) {
            !$model->published => 'draft',
            $model->ends_at !== null && $now >= $model->ends_at => 'ended',
            !($sources[$model->source] ?? false) => 'unavailable',
            $model->starts_at !== null && $now < $model->starts_at => 'scheduled',
            default => 'active',
        };
        return $record;
    }

    public function seedDefaults(): array
    {
        $this->requireAvailable();
        $defaults = [
            [
                'code' => 'invite-friends', 'source' => 'invite', 'title' => '邀请好友，双方都有奖励！',
                'badge' => '邀请奖励', 'summary' => '每邀请一位好友注册并首次购买套餐，好友可获得额外流量，您可获得返利。',
                'benefits' => ['好友首次购买可获得额外 {invite_traffic_percent}% 流量，系统自动发放，无需工单申请',
                    '您可获得好友首次购买金额的 {invite_commission_percent}% 返利', '邀请人数不限，奖励无上限'],
                'image_url' => '/activities/invite-rewards.webp', 'target_url' => '/dashboard/finance/invite',
                'button_text' => '立即邀请', 'theme' => 'navy', 'sort' => 10,
            ],
            [
                'code' => 'ai-credit-gift', 'source' => 'ai_credit', 'title' => 'AI 中转站额度免费送',
                'badge' => 'AI 中转福利', 'summary' => '购买符合条件的流量套餐，领取 AI 中转额度，具体可领权益以福利页面为准。',
                'benefits' => ['购买符合条件的套餐即可领取', '模型价格与缓存率公开可查', '前往福利页面查看并领取兑换码'],
                'image_url' => '/activities/ai-credit-gift.webp', 'target_url' => '/dashboard/finance/ai-credit',
                'button_text' => '查看 AI 福利', 'theme' => 'indigo', 'sort' => 20,
            ],
            [
                'code' => 'ai-topup-traffic', 'source' => 'ai_topup', 'title' => '充值 AI 额度，再享流量赠送',
                'badge' => '充值赠流量', 'summary' => '充值 AI 中转额度，可按实际充值本金的 {ai_topup_bonus_percent}% 获得流量赠送，具体以充值页面报价为准。',
                'benefits' => ['美元面额充值，人民币结算', '按实际充值本金的 {ai_topup_bonus_percent}% 赠送流量', '支付后查看兑换码与流量激活状态'],
                'image_url' => '/activities/ai-topup-traffic.webp', 'target_url' => '/dashboard/finance/ai-topup',
                'button_text' => '前往充值', 'theme' => 'teal', 'sort' => 30,
            ],
        ];
        $result = [];
        foreach ($defaults as $data) {
            $existing = MarketingActivity::where('code', $data['code'])->first();
            if ($existing) { $result[] = $existing; continue; }
            try {
                $result[] = $this->save(array_merge($data, ['published' => true]));
            } catch (ApiException $e) {
                // A simultaneous explicit seed may have inserted this unique code first.
                $existing = MarketingActivity::where('code', $data['code'])->first();
                if (!$existing) throw $e;
                $result[] = $existing;
            }
        }
        return $result;
    }

    private function sources(): array
    {
        $sources = ['custom' => true, 'invite' => true, 'ai_credit' => false, 'ai_topup' => false,
            'bonus_rate_bps' => 0];
        $client = new AiCreditClient();
        try {
            $settings = (new AiCreditService($client))->settings(true);
            $sources['ai_credit'] = (bool) ($settings['enabled'] && $settings['provider_ready']);
        } catch (Throwable $e) {
            // Optional source migrations or provider configuration can be absent during rollout.
        }
        try {
            $settings = (new AiTopupService($client))->settings();
            $sources['ai_topup'] = (bool) ($settings['enabled'] && $settings['provider_ready']);
            $sources['bonus_rate_bps'] = (int) $settings['bonus_rate_bps'];
        } catch (Throwable $e) {
        }
        return $sources;
    }

    private function percent(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
    }

    private function requireAvailable(): void
    {
        if (!$this->available()) throw new ApiException('请先执行活动数据库迁移', 422);
    }

    private function checkVersion(?MarketingActivity $model, int $version): void
    {
        if (!$model || $model->version !== $version) throw new ApiException('活动已更新或删除，请刷新后重试', 409);
    }

    private function updateVersion(MarketingActivity $model, int $version): MarketingActivity
    {
        $model->version = $version + 1;
        $model->updated_at = Carbon::now()->getTimestamp();
        if (MarketingActivity::whereKey($model->id)->where('version', $version)->update($model->getDirty()) !== 1) {
            throw new ApiException('活动已更新或删除，请刷新后重试', 409);
        }
        return $model->fresh();
    }

    private function validate(array $data, array $rules, ?array $allowed = null): void
    {
        if ($allowed !== null && array_diff(array_keys($data), $allowed)) throw new ApiException('活动参数包含未知字段', 422);
        $validator = Validator::make($data, $rules);
        if ($validator->fails()) throw new ApiException('活动参数有误', 422, $validator->errors()->toArray());
        foreach (['id', 'version', 'current', 'page_size', 'sort', 'starts_at', 'ends_at'] as $key) {
            if (!isset($data[$key])) continue;
            if (!is_int($data[$key]) && !(is_string($data[$key]) && preg_match('/\A(?:0|[1-9][0-9]*)\z/', $data[$key]))) {
                throw new ApiException('活动整数参数有误', 422);
            }
        }
    }

    private function validateActivity(array $data): void
    {
        if ($data['code'] !== null && (!is_string($data['code']) || !preg_match('/\A[a-z0-9][a-z0-9_-]*\z/', $data['code']))) {
            throw new ApiException('活动标识须为小写字母、数字、下划线或连字符', 422);
        }
        $this->validate($data, [
            'code' => ['nullable', 'string', 'max:64', 'regex:/\A[a-z0-9][a-z0-9_-]*\z/'],
            'source' => 'required|in:custom,invite,ai_credit,ai_topup',
            'title' => 'required|string|max:80', 'badge' => 'present|string|max:30',
            'summary' => 'required|string|max:240', 'benefits' => 'present|array|max:6',
            'benefits.*' => 'required|string|max:180', 'image_url' => 'required|string|max:2048',
            'target_url' => 'required|string|max:2048', 'button_text' => 'required|string|max:30',
            'theme' => 'required|in:navy,indigo,teal', 'sort' => 'required|integer|min:0|max:10000',
            'published' => 'required|boolean', 'starts_at' => 'nullable|integer|min:0', 'ends_at' => 'nullable|integer|min:0',
        ]);
        if (!array_is_list($data['benefits'])) throw new ApiException('活动权益须为列表', 422);
        if ($data['starts_at'] !== null && $data['ends_at'] !== null && $data['ends_at'] <= $data['starts_at']) {
            throw new ApiException('结束时间须晚于开始时间', 422);
        }
        $allowed = match ($data['source']) {
            'invite' => ['{invite_commission_percent}', '{invite_traffic_percent}'],
            'ai_topup' => ['{ai_topup_bonus_percent}'],
            default => [],
        };
        foreach (array_merge(array_intersect_key($data, array_flip(self::TEXT_FIELDS)), $data['benefits']) as $text) {
            $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!mb_check_encoding($text, 'UTF-8') || preg_match('/[<>\p{Cc}\p{Cf}]/u', $decoded)
                || preg_match('/[{}]/', str_replace($allowed, '', $text))) {
                throw new ApiException('活动文案须为纯文本且仅使用对应来源的已知占位符', 422);
            }
        }
        $this->validateUrl($data['image_url'], true);
        $this->validateUrl($data['target_url'], false);
    }

    private function validateUrl(string $url, bool $image): void
    {
        $decoded = rawurldecode($url);
        if (!mb_check_encoding($decoded, 'UTF-8') || preg_match('/[\s\p{Cc}\p{Cf}<>"\x27\x60{}\\\\]/u', $decoded)
            || preg_match('/%(?![a-f0-9]{2})/i', $url)
            || preg_match('/%(?:25|2e|2f|3a|3f|23|40|5c)/i', $url)) {
            throw new ApiException('活动链接格式有误', 422);
        }
        if (str_starts_with($url, '/')) {
            $pattern = $image
                ? '~\A/activities/(?:[a-z0-9_-]+/)*[a-z0-9_-]+(?:\.[a-z0-9_-]+)*\z~i'
                : '~\A/dashboard/(?:[a-z0-9_-]+(?:/[a-z0-9_-]+)*/?)?\z~i';
            if (preg_match($pattern, $url)) return;
        } else {
            $parts = parse_url($url);
            $path = $parts['path'] ?? '';
            if (filter_var($url, FILTER_VALIDATE_URL) && strtolower($parts['scheme'] ?? '') === 'https'
                && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass'])
                && !str_contains($path, '//') && !preg_match('~(?:\A|/)\.{1,2}(?:/|\z)~', $path)) return;
        }
        throw new ApiException('图片须为 HTTPS 或 /activities/ 路径，跳转须为 HTTPS 或 /dashboard/ 路径', 422);
    }
}
