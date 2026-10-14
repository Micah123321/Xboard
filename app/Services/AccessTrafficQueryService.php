<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AccessTrafficQueryService
{
    public function filters(array $input, ?int $now = null): array
    {
        $now ??= time();
        $data = Validator::make($input, [
            'user_id' => 'nullable|integer|min:1', 'node_id' => 'nullable|integer|min:1',
            'start_time' => 'nullable|integer|min:1|max:253402214400',
            'end_time' => 'nullable|integer|min:1|max:253402214400',
            'search' => 'nullable|string|max:253', 'category' => 'nullable|in:video,web,download,update,sync,cdn,unknown',
            'network' => 'nullable|in:tcp,udp,unknown', 'group_by' => 'nullable|in:domain,time',
            'page' => 'nullable|integer|min:1|max:10000000', 'pageSize' => 'nullable|integer|min:1|max:100',
            'format' => 'nullable|in:csv,html',
        ])->validate();
        $retention = (int) app(AccessTrafficService::class)->settings()['retention_days'] * 86400;
        $start = (int) ($data['start_time'] ?? $now - 86400);
        $end = (int) ($data['end_time'] ?? $now + 1);
        if ($end <= $start) throw ValidationException::withMessages(['end_time' => 'End time must follow start time.']);
        $end = min($end, $now + 1);
        $start = min($end, max($start, $now - $retention, $end - $retention));
        return array_replace($data, [
            'start_time' => $start, 'end_time' => $end, 'group_by' => $data['group_by'] ?? 'domain',
            'page' => (int) ($data['page'] ?? 1), 'pageSize' => (int) ($data['pageSize'] ?? 20),
        ]);
    }

    private function base(array $filters): Builder
    {
        $query = DB::table('v2_access_traffic as e')->join('v2_access_report as r', 'r.id', '=', 'e.report_id')
            ->where('e.last_seen', '>=', $filters['start_time'])->where('e.first_seen', '<', $filters['end_time']);
        foreach (['user_id', 'node_id', 'category', 'network'] as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '') $query->where('e.' . $key, $filters[$key]);
        }
        if (($filters['search'] ?? '') !== '') {
            // A literal contains search: SQL wildcards in the filter have no special meaning.
            $search = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['search']) . '%';
            $query->where(function ($query) use ($search): void {
                $query->whereRaw("e.domain LIKE ? ESCAPE '!'", [$search])
                    ->orWhereRaw("e.ip LIKE ? ESCAPE '!'", [$search])->orWhereRaw("r.node_name LIKE ? ESCAPE '!'", [$search]);
            });
        }
        return $query;
    }

    public function rows(array $filters): Builder
    {
        $groups = ['e.user_id', 'e.node_id', 'r.node_name', 'e.domain', 'e.ip', 'e.port', 'e.network',
            'e.domain_source', 'e.service', 'e.category', 'e.confidence', 'e.reason'];
        if ($filters['group_by'] === 'time') $groups[] = 'e.bucket_at';
        $query = $this->base($filters)->select($groups)->groupBy($groups)
            ->selectRaw('MIN(e.id) as id, MIN(e.first_seen) as first_seen, MAX(e.last_seen) as last_seen')
            ->selectRaw('SUM(e.raw_u) as raw_u, SUM(e.raw_d) as raw_d, SUM(e.raw_u + e.raw_d) as raw_total')
            ->selectRaw('CASE WHEN COUNT(e.billed_u) = COUNT(*) THEN SUM(e.billed_u) ELSE NULL END as billed_u')
            ->selectRaw('CASE WHEN COUNT(e.billed_d) = COUNT(*) THEN SUM(e.billed_d) ELSE NULL END as billed_d')
            ->selectRaw('CASE WHEN COUNT(e.billed_u) = COUNT(*) AND COUNT(e.billed_d) = COUNT(*) THEN SUM(e.billed_u + e.billed_d) ELSE NULL END as billed_total')
            ->selectRaw('CASE WHEN COUNT(e.rate) = COUNT(*) AND MIN(e.rate) = MAX(e.rate) THEN MIN(e.rate) ELSE NULL END as rate')
            ->selectRaw('SUM(e.connections) as connections')
            ->selectRaw("CASE WHEN SUM(CASE WHEN r.coverage = 'partial' OR r.dropped_entries > 0 THEN 1 ELSE 0 END) > 0 THEN 'partial' ELSE 'complete' END as coverage")
            ->orderByDesc('raw_total')->orderBy('id');
        return $query;
    }

    public function row(object $row, int $total): array
    {
        $result = (array) $row;
        $result['id'] = (string) $result['id'];
        $result['node_name'] = $result['node_name'] ?: ('Node #' . $result['node_id']);
        foreach (['user_id', 'node_id', 'port', 'bucket_at', 'first_seen', 'last_seen', 'raw_u', 'raw_d', 'raw_total', 'connections'] as $key) {
            if (array_key_exists($key, $result)) $result[$key] = (int) $result[$key];
        }
        foreach (['billed_u', 'billed_d', 'billed_total'] as $key) $result[$key] = $result[$key] === null ? null : (int) $result[$key];
        $result['rate'] = $result['rate'] === null ? null : (float) $result['rate'];
        $result['share'] = $total > 0 ? round($result['raw_total'] / $total * 100, 2) : 0;
        return $result;
    }

    public function context(array $filters): array
    {
        $base = $this->base($filters);
        $summary = (array) (clone $base)->selectRaw('COALESCE(SUM(e.raw_u), 0) as raw_u, COALESCE(SUM(e.raw_d), 0) as raw_d, COALESCE(SUM(e.raw_u + e.raw_d), 0) as raw_total')
            ->selectRaw('COALESCE(SUM(e.billed_u), 0) as billed_u, COALESCE(SUM(e.billed_d), 0) as billed_d, COALESCE(SUM(e.billed_u + e.billed_d), 0) as billed_total')
            ->selectRaw("COALESCE(SUM(CASE WHEN e.category <> 'unknown' THEN e.raw_u + e.raw_d ELSE 0 END), 0) as identified_bytes")
            ->selectRaw("COALESCE(SUM(CASE WHEN e.category = 'unknown' THEN e.raw_u + e.raw_d ELSE 0 END), 0) as unidentified_bytes")
            ->selectRaw("COALESCE(SUM(CASE WHEN e.domain = '' AND e.ip = '' THEN e.raw_u + e.raw_d ELSE 0 END), 0) as unattributed_bytes")
            ->selectRaw('COUNT(DISTINCT r.id) as report_count')
            ->selectRaw("COUNT(DISTINCT CASE WHEN r.coverage = 'partial' OR r.dropped_entries > 0 THEN r.id ELSE NULL END) as partial_report_count")
            ->selectRaw('COUNT(DISTINCT CASE WHEN r.settled_at IS NULL THEN r.id ELSE NULL END) as unsettled_report_count')->first();
        $summary = array_map('intval', $summary);
        $summary['identified_percent'] = $summary['raw_total'] ? round($summary['identified_bytes'] / $summary['raw_total'] * 100, 2) : 0;
        $analysis = (clone $base)->select('e.category')->groupBy('e.category')
            ->selectRaw('SUM(e.raw_u + e.raw_d) as raw_total')
            ->selectRaw("MIN(CASE e.confidence WHEN 'high' THEN 3 WHEN 'medium' THEN 2 WHEN 'low' THEN 1 ELSE 0 END) as confidence_rank")
            ->orderByDesc('raw_total')->get()->map(function ($row) use ($summary): array {
                return ['category' => $row->category, 'category_label' => AccessTrafficClassifier::LABELS[$row->category],
                    'raw_total' => (int) $row->raw_total, 'share' => $summary['raw_total'] ? round($row->raw_total / $summary['raw_total'] * 100, 2) : 0,
                    'confidence' => ['unknown', 'low', 'medium', 'high'][(int) $row->confidence_rank],
                    'reason' => $row->category === 'unknown' ? '目标证据或服务规则不足，具体用途未知。' : '依据目标域名规则推断服务类型；视频播放、下载、预加载等具体操作仍属推断。'];
            })->all();
        $collected = (clone $base)->selectRaw('MIN(r.observed_at) as first_at, MAX(r.observed_at) as last_at')->first();
        $billing = DB::table('v2_traffic_report as b')->where('b.received_at', '>=', $filters['start_time'])
            ->where('b.received_at', '<', $filters['end_time']);
        if (isset($filters['node_id'])) $billing->where('b.node_id', $filters['node_id']);
        $missing = $billing->whereNotExists(function ($query): void {
            $query->selectRaw('1')->from('v2_access_report as a')->whereColumn('a.node_id', 'b.node_id')
                ->whereColumn('a.billing_report_id', 'b.report_id')->whereNotNull('a.processed_at');
        })->count();
        $pending = DB::table('v2_access_report')->whereNull('processed_at')->where('observed_at', '>=', $filters['start_time'])
            ->where('observed_at', '<', $filters['end_time']);
        if (isset($filters['node_id'])) $pending->where('node_id', $filters['node_id']);
        $settings = app(AccessTrafficService::class)->settings();
        $meta = [
            'timezone' => config('app.timezone', 'UTC'), 'bucket_seconds' => (int) $settings['bucket_seconds'],
            'retention_days' => (int) $settings['retention_days'],
            'enabled' => isset($filters['node_id']) ? app(AccessTrafficService::class)->nodeConfig((int) $filters['node_id'])['enabled'] : (bool) $settings['enabled'],
            'first_collected_at' => $collected->first_at === null ? null : (int) $collected->first_at,
            'last_collected_at' => $collected->last_at === null ? null : (int) $collected->last_at,
            'coverage' => !$summary['report_count'] ? 'none' : ($summary['partial_report_count'] || $missing ? 'partial' : 'complete'),
            'reason' => '覆盖状态仅描述已收到的报告，未采集时段及节点不等于零流量。时间筛选统计与范围重叠的整个采集片段，片段内字节不作秒级拆分。计费量按实际扣费分摊，未结算部分暂不计入计费汇总；缺口对比使用节点级计费批次。',
            'collection_gaps' => [
                'node_billing_reports_without_details' => $missing, 'pending_detail_reports' => $pending->count(),
                'failed_settlement_reports' => (clone $base)->whereNotNull('r.settlement_error')->distinct()->count('r.id'),
            ],
            'filters' => $filters,
        ];
        return compact('summary', 'analysis', 'meta');
    }

    public function index(array $filters): array
    {
        $context = $this->context($filters);
        $query = $this->rows($filters);
        $total = $query->getCountForPagination();
        $rows = $query->forPage($filters['page'], $filters['pageSize'])->get()
            ->map(fn ($row) => $this->row($row, $context['summary']['raw_total']))->all();
        return ['data' => $rows, 'total' => $total] + $context;
    }

    public static function csvCell(mixed $value): string
    {
        $value = $value === null ? '' : (string) $value;
        if (preg_match('/^[\x00-\x20]*[=+@-]/', $value) || preg_match('/^[\t\r\n]/', $value)) $value = "'" . $value;
        return $value;
    }
}
