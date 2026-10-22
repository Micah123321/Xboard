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
            'search' => 'nullable|string|max:253', 'category' => ['nullable', \Illuminate\Validation\Rule::in(array_keys(AccessTrafficClassifier::LABELS))],
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
        $query = DB::table('v2_access_traffic as e')->join('v2_access_report as r', 'r.id', '=', 'e.report_id');
        if (DB::connection()->getDriverName() === 'mysql' && empty($filters['user_id']) && empty($filters['node_id']) && empty($filters['category'])) {
            // MySQL 8 can prefer scanning all historical details. Drive the existing report
            // time index first, then use the detail foreign-key index for each report.
            $query = DB::query()->fromRaw('v2_access_report as r FORCE INDEX (v2_access_report_observed_at_index) STRAIGHT_JOIN v2_access_traffic as e FORCE INDEX (v2_access_traffic_report_id_foreign) ON e.report_id = r.id');
        }
        // normalize() has enforced last_seen <= observed_at + 300 since the first writer.
        // This lower bound is safe for long/delayed fragments; an upper bound would not be.
        $query->where('r.observed_at', '>=', max(0, $filters['start_time'] - 300));
        return $this->applyFilters($query, $filters);
    }

    private function applyFilters(Builder $query, array $filters): Builder
    {
        $query->where('e.last_seen', '>=', $filters['start_time'])->where('e.first_seen', '<', $filters['end_time']);
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

    private function reportStatistics(array $filters): Builder
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            // MySQL creates the foreign-key index, so each report stops at its first match.
            $matches = $this->applyFilters(DB::table('v2_access_traffic as e')->whereColumn('e.report_id', 'r.id'), $filters)
                ->forceIndex('v2_access_traffic_report_id_foreign')->selectRaw('1')->limit(1);
            $query = DB::table('v2_access_report as r')->where('r.observed_at', '>=', max(0, $filters['start_time'] - 300))->whereExists($matches);
        } else {
            // SQLite does not automatically index foreign keys; avoid a correlated full scan.
            $reportIds = $this->base($filters)->select('e.report_id')->distinct();
            $query = DB::query()->fromSub($reportIds, 'matched')->join('v2_access_report as r', 'r.id', '=', 'matched.report_id');
        }
        return $query
            ->selectRaw('COUNT(*) as report_count')
            ->selectRaw("COALESCE(SUM(CASE WHEN r.coverage = 'partial' OR r.dropped_entries > 0 THEN 1 ELSE 0 END), 0) as partial_report_count")
            ->selectRaw('COALESCE(SUM(CASE WHEN r.settled_at IS NULL THEN 1 ELSE 0 END), 0) as unsettled_report_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN r.settlement_error IS NOT NULL THEN 1 ELSE 0 END), 0) as failed_report_count')
            ->selectRaw('MIN(r.observed_at) as first_at, MAX(r.observed_at) as last_at');
    }

    private function groupColumns(array $filters): array
    {
        $groups = ['e.user_id', 'e.node_id', 'r.node_name', 'e.domain', 'e.ip', 'e.port', 'e.network',
            'e.domain_source', 'e.service', 'e.category', 'e.confidence', 'e.reason'];
        if ($filters['group_by'] === 'time') $groups[] = 'e.bucket_at';
        return $groups;
    }

    public function countGroups(array $filters, ?int $limit = null): int
    {
        // Counting groups needs neither their byte aggregates nor their ranking sort.
        $groups = $this->base($filters)->selectRaw('1 AS present')->groupBy($this->groupColumns($filters));
        if ($limit !== null) $groups->limit($limit);
        return (int) DB::query()->fromSub($groups, 'access_groups')->count();
    }

    private function aggregates(array $filters, bool $withSummary = false): Builder
    {
        // Keep the complete grouping key, but carry only aggregates into ranking/window tables.
        $query = $this->base($filters)->groupBy($this->groupColumns($filters))
            ->selectRaw('MIN(e.id) as id, MIN(e.first_seen) as first_seen, MAX(e.last_seen) as last_seen')
            ->selectRaw('SUM(e.raw_u) as raw_u, SUM(e.raw_d) as raw_d, SUM(e.raw_u + e.raw_d) as raw_total')
            ->selectRaw('CASE WHEN COUNT(e.billed_u) = COUNT(*) THEN SUM(e.billed_u) ELSE NULL END as billed_u')
            ->selectRaw('CASE WHEN COUNT(e.billed_d) = COUNT(*) THEN SUM(e.billed_d) ELSE NULL END as billed_d')
            ->selectRaw('CASE WHEN COUNT(e.billed_u) = COUNT(*) AND COUNT(e.billed_d) = COUNT(*) THEN SUM(e.billed_u + e.billed_d) ELSE NULL END as billed_total')
            ->selectRaw('CASE WHEN COUNT(e.rate) = COUNT(*) AND MIN(e.rate) = MAX(e.rate) THEN MIN(e.rate) ELSE NULL END as rate')
            ->selectRaw('SUM(e.connections) as connections')
            ->selectRaw("CASE WHEN SUM(CASE WHEN r.coverage = 'partial' OR r.dropped_entries > 0 THEN 1 ELSE 0 END) > 0 THEN 'partial' ELSE 'complete' END as coverage");
        if ($withSummary) {
            // Partial settlements contribute known bytes to totals even when a group is NULL.
            $query->addSelect(['e.category', 'e.confidence', 'e.domain', 'e.ip'])
                ->selectRaw('SUM(e.billed_u) as known_billed_u, SUM(e.billed_d) as known_billed_d, SUM(e.billed_u + e.billed_d) as known_billed_total');
        }
        return $query;
    }

    private function withDetails(Builder $aggregates, array $filters): Builder
    {
        // MIN(id) is an actual member of each group. Fetch its descriptive fields after LIMIT,
        // including the historical report name; no hash/collation approximation of the key.
        $columns = array_map(fn ($column) => str_replace(['e.', 'r.'], ['detail.', 'report.'], $column), $this->groupColumns($filters));
        return DB::query()->fromSub($aggregates, 'g')
            ->join('v2_access_traffic as detail', 'detail.id', '=', 'g.id')
            ->join('v2_access_report as report', 'report.id', '=', 'detail.report_id')
            ->leftJoin('v2_user as u', 'u.id', '=', 'detail.user_id')
            ->select($columns)->addSelect('g.*', 'u.email as user_email')
            ->orderByDesc('g.raw_total')->orderBy('g.id');
    }

    public function rows(array $filters): Builder
    {
        return $this->withDetails($this->aggregates($filters), $filters);
    }

    public function row(object $row, int $total): array
    {
        $result = (array) $row;
        $result['id'] = (string) $result['id'];
        if (AccessTrafficService::isUnspecifiedIP($result['ip'])) {
            $result['ip'] = '';
            $result['ip_unavailable_reason'] = '节点上报了未指定地址，未采集到真实目标 IP';
            if ($result['domain'] === '') {
                $result['port'] = 0;
                $result['domain_source'] = 'unknown';
                $result['reason'] = '节点上报的是未指定地址（:: / 0.0.0.0），真实访问目标未采集。';
            }
        }
        $result['node_name'] = $result['node_name'] ?: ('Node #' . $result['node_id']);
        foreach (['user_id', 'node_id', 'port', 'bucket_at', 'first_seen', 'last_seen', 'raw_u', 'raw_d', 'raw_total', 'connections'] as $key) {
            if (array_key_exists($key, $result)) $result[$key] = (int) $result[$key];
        }
        foreach (['billed_u', 'billed_d', 'billed_total'] as $key) $result[$key] = $result[$key] === null ? null : (int) $result[$key];
        $result['rate'] = $result['rate'] === null ? null : (float) $result['rate'];
        $result['share'] = $total > 0 ? round($result['raw_total'] / $total * 100, 2) : 0;
        return $result + (new AccessTrafficActivityInference())->infer($result);
    }

    public function context(array $filters): array
    {
        $base = $this->base($filters);
        // Group once by category instead of evaluating two CASEs for every configured label.
        $categories = (clone $base)->select('e.category')->groupBy('e.category')
            ->selectRaw('SUM(e.raw_u) as raw_u, SUM(e.raw_d) as raw_d, SUM(e.raw_u + e.raw_d) as raw_total')
            ->selectRaw('COALESCE(SUM(e.billed_u), 0) as billed_u, COALESCE(SUM(e.billed_d), 0) as billed_d, COALESCE(SUM(e.billed_u + e.billed_d), 0) as billed_total')
            ->selectRaw("SUM(CASE WHEN e.category <> 'unknown' THEN e.raw_u + e.raw_d ELSE 0 END) as identified_bytes")
            ->selectRaw("SUM(CASE WHEN e.category = 'unknown' THEN e.raw_u + e.raw_d ELSE 0 END) as unidentified_bytes")
            ->selectRaw("SUM(CASE WHEN e.domain = '' AND e.ip IN ('', '::', '0.0.0.0', '::ffff:0.0.0.0') THEN e.raw_u + e.raw_d ELSE 0 END) as unattributed_bytes")
            ->selectRaw("MIN(CASE e.confidence WHEN 'high' THEN 3 WHEN 'medium' THEN 2 WHEN 'low' THEN 1 ELSE 0 END) as category_confidence");
        // Reports can contain several categories. Deduplicate their ids once, independently
        // of category groups, so coverage/settlement counts never count one report twice.
        $reports = $this->reportStatistics($filters);
        // Resolve labels using the database's comparison/collation rules, on the small
        // category result only (including case-insensitive historical values on MySQL).
        $labelCase = 'CASE c.category';
        $labelBindings = [];
        foreach (array_keys(AccessTrafficClassifier::LABELS) as $category) {
            $labelCase .= ' WHEN ? THEN ?';
            array_push($labelBindings, $category, $category);
        }
        // Both scans share a statement snapshot, including during concurrent settlement.
        $groups = DB::query()->fromSub($categories, 'c')->crossJoinSub($reports, 's')->select('c.*', 's.*')
            ->selectRaw($labelCase . ' ELSE NULL END as analysis_category', $labelBindings)->get();
        $stats = (array) ($groups->first() ?? (object) ['first_at' => null, 'last_at' => null, 'failed_report_count' => 0]);
        $byteKeys = ['raw_u', 'raw_d', 'raw_total', 'billed_u', 'billed_d', 'billed_total',
            'identified_bytes', 'unidentified_bytes', 'unattributed_bytes'];
        $summary = array_fill_keys($byteKeys, 0);
        foreach ($groups as $group) {
            foreach ($byteKeys as $key) $summary[$key] += (int) $group->$key;
        }
        foreach (['report_count', 'partial_report_count', 'unsettled_report_count'] as $key) $summary[$key] = (int) ($stats[$key] ?? 0);
        $summary['identified_percent'] = $summary['raw_total'] ? round($summary['identified_bytes'] / $summary['raw_total'] * 100, 2) : 0;
        $analysis = [];
        foreach ($groups as $group) {
            $category = $group->analysis_category;
            if (!isset(AccessTrafficClassifier::LABELS[$category])) continue;
            $bytes = (int) $group->raw_total;
            $analysis[] = ['category' => $category, 'category_label' => AccessTrafficClassifier::LABELS[$category], 'raw_total' => $bytes,
                'share' => $summary['raw_total'] ? round($bytes / $summary['raw_total'] * 100, 2) : 0,
                'confidence' => ['unknown', 'low', 'medium', 'high'][(int) $group->category_confidence],
                'reason' => $category === 'unknown' ? '目标证据或服务规则不足，具体用途未知。' : '依据目标域名规则或服务专用 IP 网段推断服务归属；具体聊天、视频、下载等操作仍属推断。'];
        }
        usort($analysis, fn ($a, $b) => $b['raw_total'] <=> $a['raw_total'] ?: strcmp($a['category'], $b['category']));
        return $this->finishContext($filters, $summary, $analysis, $stats);
    }

    private function finishContext(array $filters, array $summary, array $analysis, array $stats): array
    {
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
            'first_collected_at' => $stats['first_at'] === null ? null : (int) $stats['first_at'],
            'last_collected_at' => $stats['last_at'] === null ? null : (int) $stats['last_at'],
            'coverage' => !$summary['report_count'] ? 'none' : ($summary['partial_report_count'] || $missing ? 'partial' : 'complete'),
            'reason' => '覆盖状态仅描述已收到的报告，未采集时段及节点不等于零流量。时间筛选统计与范围重叠的整个采集片段，片段内字节不作秒级拆分。计费量按实际扣费分摊，未结算部分暂不计入计费汇总；缺口对比使用节点级计费批次。',
            'collection_gaps' => [
                'node_billing_reports_without_details' => $missing, 'pending_detail_reports' => $pending->count(),
                'failed_settlement_reports' => (int) $stats['failed_report_count'],
            ],
            'classification_database' => (new AccessTrafficDomainDatabase())->status(),
            'filters' => $filters,
        ];
        return compact('summary', 'analysis', 'meta');
    }

    private function streamedIndex(array $filters): array
    {
        $query = DB::query()->fromSub($this->aggregates($filters, true), 'g')
            ->select(['g.id', 'g.first_seen', 'g.last_seen', 'g.raw_u', 'g.raw_d', 'g.raw_total',
                'g.billed_u', 'g.billed_d', 'g.billed_total', 'g.rate', 'g.connections', 'g.coverage',
                'g.known_billed_u', 'g.known_billed_d', 'g.known_billed_total']);
        $labelCase = 'CASE g.category';
        $labelBindings = [];
        foreach (array_keys(AccessTrafficClassifier::LABELS) as $category) {
            $labelCase .= ' WHEN ? THEN ?';
            array_push($labelBindings, $category, $category);
        }
        // SQL comparisons retain the database collation; these expressions run per group.
        $query->selectRaw($labelCase . ' ELSE NULL END as analysis_category', $labelBindings)
            ->selectRaw("CASE g.confidence WHEN 'high' THEN 3 WHEN 'medium' THEN 2 WHEN 'low' THEN 1 ELSE 0 END as category_confidence")
            ->selectRaw("CASE WHEN g.category <> 'unknown' THEN g.raw_total ELSE 0 END as identified_bytes")
            ->selectRaw("CASE WHEN g.category = 'unknown' THEN g.raw_total ELSE 0 END as unidentified_bytes")
            ->selectRaw("CASE WHEN g.domain = '' AND g.ip IN ('', '::', '0.0.0.0', '::ffff:0.0.0.0') THEN g.raw_total ELSE 0 END as unattributed_bytes");
        $summary = array_fill_keys(['raw_u', 'raw_d', 'raw_total', 'billed_u', 'billed_d', 'billed_total',
            'identified_bytes', 'unidentified_bytes', 'unattributed_bytes'], 0);
        $categories = [];
        $total = 0;
        $keep = $filters['page'] * $filters['pageSize'];
        $heap = new \SplPriorityQueue();
        $heap->setExtractFlags(\SplPriorityQueue::EXTR_DATA);
        foreach ($query->cursor() as $record) {
            $total++;
            foreach ($summary as $key => $value) {
                $column = str_starts_with($key, 'billed_') ? 'known_' . $key : $key;
                $summary[$key] += (int) $record->$column;
            }
            $category = $record->analysis_category;
            if ($category !== null) {
                $categories[$category] ??= ['bytes' => 0, 'confidence' => 3];
                $categories[$category]['bytes'] += (int) $record->raw_total;
                $categories[$category]['confidence'] = min($categories[$category]['confidence'], (int) $record->category_confidence);
            }
            // The largest priority is the worst retained rank: lower bytes, then higher id.
            $heap->insert($record, [-(int) $record->raw_total, (int) $record->id]);
            if ($heap->count() > $keep) $heap->extract();
        }
        $ranked = iterator_to_array($heap, false);
        usort($ranked, fn ($a, $b) => (int) $b->raw_total <=> (int) $a->raw_total ?: (int) $a->id <=> (int) $b->id);
        $ranked = array_slice($ranked, ($filters['page'] - 1) * $filters['pageSize'], $filters['pageSize']);
        $columns = array_map(fn ($column) => str_replace(['e.', 'r.'], ['detail.', 'report.'], $column), $this->groupColumns($filters));
        $details = DB::table('v2_access_traffic as detail')->join('v2_access_report as report', 'report.id', '=', 'detail.report_id')
            ->leftJoin('v2_user as u', 'u.id', '=', 'detail.user_id')->select($columns)
            ->addSelect('detail.id', 'u.email as user_email')->whereIn('detail.id', array_map(fn ($r) => $r->id, $ranked));
        $records = DB::query()->fromSub($this->reportStatistics($filters), 'stats')
            ->leftJoinSub($details, 'detail_page', fn ($join) => $join->whereRaw('1 = 1'))->select('stats.*', 'detail_page.*')->get();
        $stats = (array) $records->first();
        foreach (['report_count', 'partial_report_count', 'unsettled_report_count'] as $key) $summary[$key] = (int) $stats[$key];
        $summary['identified_percent'] = $summary['raw_total'] ? round($summary['identified_bytes'] / $summary['raw_total'] * 100, 2) : 0;
        $analysis = [];
        foreach ($categories as $category => $values) {
            $analysis[] = ['category' => $category, 'category_label' => AccessTrafficClassifier::LABELS[$category], 'raw_total' => $values['bytes'],
                'share' => $summary['raw_total'] ? round($values['bytes'] / $summary['raw_total'] * 100, 2) : 0,
                'confidence' => ['unknown', 'low', 'medium', 'high'][$values['confidence']],
                'reason' => $category === 'unknown' ? '目标证据或服务规则不足，具体用途未知。' : '依据目标域名规则或服务专用 IP 网段推断服务归属；具体聊天、视频、下载等操作仍属推断。'];
        }
        usort($analysis, fn ($a, $b) => $b['raw_total'] <=> $a['raw_total'] ?: strcmp($a['category'], $b['category']));
        $context = $this->finishContext($filters, $summary, $analysis, $stats);
        $byId = $records->keyBy('id');
        $rows = [];
        foreach ($ranked as $record) {
            $detail = (array) $byId->get($record->id);
            foreach (['known_billed_u', 'known_billed_d', 'known_billed_total', 'analysis_category', 'category_confidence',
                'identified_bytes', 'unidentified_bytes', 'unattributed_bytes'] as $key) unset($record->$key);
            $detail = array_intersect_key($detail, array_flip(array_merge(array_map(fn ($c) => substr($c, strrpos($c, '.') + 1), $columns), ['user_email'])));
            $email = $detail['user_email'];
            unset($detail['user_email']);
            $row = array_merge($detail, (array) $record);
            $row['user_email'] = $email;
            $rows[] = $this->row((object) $row, $summary['raw_total']);
        }
        return ['data' => $rows, 'total' => $total] + $context;
    }

    public function index(array $filters): array
    {
        $connection = DB::connection();
        if ($filters['page'] * $filters['pageSize'] <= 10000 && $this->supportsWindowCount()
            && ($connection->getDriverName() === 'sqlite' || ($connection->getDriverName() === 'mysql' && !$connection->isMaria()))) {
            // Keep ranking, details and settlement metadata on one snapshot during pruning.
            // Diagnostic callers may already have started a raw READ ONLY transaction.
            if ($connection->transactionLevel() > 0 || $connection->getPdo()->inTransaction()) {
                return $this->streamedIndex($filters);
            }
            if ($connection->getDriverName() === 'mysql') {
                $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }
            return $connection->transaction(fn () => $this->streamedIndex($filters));
        }
        $context = $this->context($filters);
        $query = $this->aggregates($filters);
        $windowCount = $this->supportsWindowCount();
        if ($windowCount) $query->selectRaw('COUNT(*) OVER () AS total_groups');
        $page = $query->orderByDesc('raw_total')->orderBy('id')->forPage($filters['page'], $filters['pageSize']);
        $records = $this->withDetails($page, $filters)->get();
        $total = $windowCount && $records->isNotEmpty() ? (int) $records->first()->total_groups
            : ($windowCount && $filters['page'] === 1 ? 0 : $this->countGroups($filters));
        $rows = $records->map(function ($record) use ($context): array {
            unset($record->total_groups);
            return $this->row($record, $context['summary']['raw_total']);
        })->all();
        return ['data' => $rows, 'total' => $total] + $context;
    }

    protected function supportsWindowCount(): bool
    {
        $connection = DB::connection();
        return match ($connection->getDriverName()) {
            'pgsql', 'sqlsrv' => true,
            'sqlite' => version_compare($connection->getServerVersion(), '3.25.0', '>='),
            'mysql', 'mariadb' => version_compare($connection->getServerVersion(), $connection->isMaria() ? '10.2.0' : '8.0.0', '>='),
            default => false,
        };
    }

    public static function csvCell(mixed $value): string
    {
        $value = $value === null ? '' : (string) $value;
        if (preg_match('/^[\x00-\x20]*[=+@-]/', $value) || preg_match('/^[\t\r\n]/', $value)) $value = "'" . $value;
        return $value;
    }
}
