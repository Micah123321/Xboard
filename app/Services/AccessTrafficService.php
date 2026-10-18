<?php

namespace App\Services;

use App\Jobs\AccessTrafficJob;
use App\Jobs\TrafficBillingJob;
use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class AccessTrafficService
{
    public function settings(): array
    {
        $defaults = array_intersect_key(config('access_traffic', []), array_flip([
            'enabled', 'bucket_seconds', 'retention_days', 'max_entries', 'node_ids',
        ])) + ['enabled' => false, 'bucket_seconds' => 300, 'retention_days' => 30, 'max_entries' => 2000, 'node_ids' => []];
        return array_replace($defaults, (array) admin_setting('access_traffic_config', []));
    }

    public function saveSettings(array $data): array
    {
        $data = Validator::make($data, [
            'enabled' => 'required|boolean',
            'bucket_seconds' => 'required|integer|min:60|max:3600',
            'retention_days' => 'required|integer|min:1|max:365',
            'max_entries' => 'required|integer|min:1|max:10000',
            'node_ids' => 'present|array|max:10000',
            'node_ids.*' => 'required|integer|min:1|distinct|exists:v2_server,id',
        ])->validate();
        $data['enabled'] = (bool) $data['enabled'];
        foreach (['bucket_seconds', 'retention_days', 'max_entries'] as $key) $data[$key] = (int) $data[$key];
        $data['node_ids'] = array_map('intval', $data['node_ids']);
        admin_setting(['access_traffic_config' => $data]);
        return $data;
    }

    public function nodeConfig(int $nodeId): array
    {
        $config = $this->settings();
        return [
            'enabled' => (bool) $config['enabled'] && (!$config['node_ids'] || in_array($nodeId, $config['node_ids'], true)),
            'bucket_seconds' => (int) $config['bucket_seconds'],
            'max_entries' => (int) $config['max_entries'],
        ];
    }

    public static function reportKey(string $id): string
    {
        return 'h:' . hash('sha256', $id);
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['access_report' => $message]);
    }

    public static function isUnspecifiedIP(string $ip): bool
    {
        if ($ip === '') return false;
        $packed = @inet_pton($ip);
        return $packed !== false && ($packed === str_repeat("\0", 4) || $packed === str_repeat("\0", 16)
            || $packed === str_repeat("\0", 10) . "\xff\xff" . str_repeat("\0", 4));
    }

    public function normalize(array $data): array
    {
        $data = Validator::make($data, [
            'version' => 'required|integer|in:1',
            'report_id' => 'required|string|max:128',
            'billing_report_id' => 'required|string|max:128',
            'kernel' => 'required|string|in:sing-box,xray',
            'observed_at' => 'required|integer|min:1|max:253402214400',
            'coverage' => 'required|in:complete,partial',
            'dropped_entries' => 'required|integer|min:0',
            'totals' => 'present|array|max:100000',
            'entries' => 'present|array|max:10000',
            'entries.*.user_id' => 'required|integer|min:1',
            'entries.*.domain' => 'present|nullable|string|max:253',
            'entries.*.ip' => 'present|nullable|string|max:45',
            'entries.*.port' => 'required|integer|min:0|max:65535',
            'entries.*.network' => 'required|in:tcp,udp,unknown',
            'entries.*.domain_source' => 'required|in:request,sniff,ip,unknown',
            'entries.*.bucket_at' => 'required|integer|min:1|max:253402214400',
            'entries.*.first_seen' => 'required|integer|min:1|max:253402214400',
            'entries.*.last_seen' => 'required|integer|min:1|max:253402214400',
            'entries.*.u' => 'required|integer|min:0',
            'entries.*.d' => 'required|integer|min:0',
            'entries.*.connections' => 'required|integer|min:0',
        ])->validate();
        try {
            $data['totals'] = TrafficBillingJob::normalize($data['totals']);
        } catch (\InvalidArgumentException $e) {
            $this->invalid($e->getMessage());
        }
        $budgets = $data['totals'];
        $fields = ['user_id', 'domain', 'ip', 'port', 'network', 'domain_source', 'bucket_at', 'first_seen', 'last_seen', 'u', 'd', 'connections'];
        foreach ($data['entries'] as &$entry) {
            $entry = array_replace(array_fill_keys($fields, null), array_intersect_key($entry, array_flip($fields)));
            foreach (['user_id', 'port', 'bucket_at', 'first_seen', 'last_seen', 'u', 'd', 'connections'] as $key) $entry[$key] = (int) $entry[$key];
            $entry['domain'] = strtolower(rtrim(trim($entry['domain'] ?? ''), '.'));
            $entry['ip'] = trim($entry['ip'] ?? '');
            if ($entry['domain'] !== '' && (filter_var($entry['domain'], FILTER_VALIDATE_IP)
                || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/D', $entry['domain']))) {
                $this->invalid('Invalid destination domain.');
            }
            if ($entry['ip'] !== '' && !filter_var($entry['ip'], FILTER_VALIDATE_IP)) $this->invalid('Invalid destination IP.');
            if (($entry['domain'] !== '' && !in_array($entry['domain_source'], ['request', 'sniff'], true))
                || ($entry['domain_source'] === 'ip' && ($entry['ip'] === '' || $entry['domain'] !== ''))
                || ($entry['domain_source'] === 'unknown' && ($entry['domain'] !== '' || $entry['ip'] !== '' || $entry['port'] !== 0))
                || (in_array($entry['domain_source'], ['request', 'sniff'], true) && $entry['domain'] === '')) {
                $this->invalid('Destination does not match its evidence source.');
            }
            if ($entry['first_seen'] > $entry['last_seen'] || $entry['bucket_at'] > $entry['last_seen']
                || $entry['last_seen'] > (int) $data['observed_at'] + 300) $this->invalid('Invalid capture interval.');
            $uid = $entry['user_id'];
            if (!isset($budgets[$uid]) || $entry['u'] > $budgets[$uid][0] || $entry['d'] > $budgets[$uid][1]) {
                $this->invalid('Entries exceed the original billing direction budgets.');
            }
            $budgets[$uid][0] -= $entry['u'];
            $budgets[$uid][1] -= $entry['d'];
        }
        unset($entry);
        $unattributed = collect($data['entries'])->contains(fn ($item) => $item['domain'] === '' && $item['ip'] === '' && ($item['u'] > 0 || $item['d'] > 0));
        if ($data['dropped_entries'] > 0 || $unattributed || collect($budgets)->contains(fn ($pair) => $pair[0] > 0 || $pair[1] > 0)) {
            $data['coverage'] = 'partial';
        }
        $data['version'] = 1;
        $data['observed_at'] = (int) $data['observed_at'];
        $data['dropped_entries'] = (int) $data['dropped_entries'];
        // Canonical wire fingerprint excludes authentication and unknown body fields.
        return array_replace(array_fill_keys(['version', 'report_id', 'billing_report_id', 'kernel', 'observed_at', 'coverage', 'dropped_entries', 'totals', 'entries'], null), $data);
    }

    public function receiveFromSource(Server $source, array $input, ?int $receivedAt = null): array
    {
        $receivedAt ??= time();
        $data = $this->normalize($input);
        $virtual = array_filter(array_keys($data['totals']), fn ($uid) => $uid >= ChildNodeTrafficService::AUTH_ID_OFFSET);
        if (!$virtual) {
            $ids = [$this->receive((int) $source->id, $input, $receivedAt)];
        } else {
            try {
                $split = (new ChildNodeTrafficService())->splitTraffic($source, $data['totals']);
            } catch (\InvalidArgumentException $e) {
                $this->invalid($e->getMessage());
            }
            $reports = [];
            foreach ($split['traffic'] as $nodeId => $totals) {
                $report = $data;
                $report['report_id'] = ChildNodeTrafficService::billingReportId((int) $source->id, $data['report_id'], $nodeId);
                $report['billing_report_id'] = ChildNodeTrafficService::billingReportId((int) $source->id, $data['billing_report_id'], $nodeId);
                $report['totals'] = $totals;
                $report['entries'] = [];
                $reports[$nodeId] = $report;
            }
            foreach ($data['entries'] as $entry) {
                $identity = $split['mapping'][$entry['user_id']];
                $entry['user_id'] = $identity['user_id'];
                $reports[$identity['node_id']]['entries'][] = $entry;
            }
            // Validate every partition before receiving any of them.
            foreach ($reports as &$report) $report = $this->normalize($report);
            unset($report);
            $wireHash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $totalsHash = TrafficBillingJob::fingerprint($data['totals']);
            $ids = DB::transaction(function () use ($source, $reports, $receivedAt, $wireHash, $totalsHash): array {
                $ids = [];
                foreach ($reports as $nodeId => $report) {
                    $root = $nodeId === (int) $source->id;
                    $ids[] = $this->receive($nodeId, $report, $receivedAt, $root ? $wireHash : null, $root ? $totalsHash : null);
                }
                return $ids;
            }, 3);
        }
        foreach ($ids as $id) $this->enqueue($id);
        return $ids;
    }

    public function receive(int $nodeId, array $input, ?int $receivedAt = null, ?string $payloadHash = null, ?string $totalsHash = null): int
    {
        $receivedAt ??= time();
        $data = $this->normalize($input);
        $settings = $this->settings();
        $hash = $payloadHash ?? hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        $totalsHash ??= TrafficBillingJob::fingerprint($data['totals']);
        return DB::transaction(function () use ($nodeId, $data, $settings, $hash, $totalsHash, $receivedAt): int {
            $node = DB::table('v2_server')->where('id', $nodeId)->lockForUpdate()->first();
            if (!$node) $this->invalid('Node does not exist.');
            $key = self::reportKey($data['report_id']);
            $existing = DB::table('v2_access_report')->where('node_id', $nodeId)->where('report_id', $key)->first();
            if ($existing) {
                if (!hash_equals($existing->payload_hash, $hash)) throw new ConflictHttpException('Access report_id has different payload.');
                return (int) $existing->id;
            }
            if ($data['observed_at'] < $receivedAt - (int) $settings['retention_days'] * 86400) {
                throw new \Symfony\Component\HttpKernel\Exception\GoneHttpException('Access report retention expired.');
            }
            if ($data['observed_at'] > $receivedAt + 300) $this->invalid('Report capture time is outside clock tolerance.');
            $billingKey = self::reportKey($data['billing_report_id']);
            if (DB::table('v2_access_report')->where('node_id', $nodeId)->where('billing_report_id', $billingKey)->exists()) {
                throw new ConflictHttpException('Billing report already has an access report.');
            }
            return (int) DB::table('v2_access_report')->insertGetId([
                'node_id' => $nodeId, 'report_id' => $key, 'billing_report_id' => $billingKey,
                'node_name' => (string) ($node->name ?? ('Node #' . $nodeId)),
                'payload_hash' => $hash, 'totals_hash' => $totalsHash,
                'payload' => json_encode($data, JSON_THROW_ON_ERROR), 'kernel' => $data['kernel'],
                'coverage' => $data['coverage'], 'dropped_entries' => $data['dropped_entries'],
                'bucket_seconds' => (int) $settings['bucket_seconds'],
                'observed_at' => $data['observed_at'], 'received_at' => $receivedAt,
            ]);
        }, 3);
    }

    public function enqueue(int $reportId): void
    {
        if (DB::connection()->transactionLevel() > 0) {
            DB::afterCommit(fn () => $this->enqueue($reportId));
            return;
        }
        try {
            AccessTrafficJob::dispatch($reportId);
        } catch (\Throwable $e) {
            // Receipt is durable; scheduled repair also handles a lost queue notification.
            Log::warning('Access traffic dispatch deferred', ['report_id' => $reportId, 'error_class' => $e::class]);
        }
    }

    private function unknownRow(object $report, int $uid, int $u, int $d): array
    {
        return ['report_id' => $report->id, 'user_id' => $uid, 'node_id' => $report->node_id,
            'domain' => '', 'ip' => '', 'port' => 0, 'network' => 'unknown', 'domain_source' => 'unknown',
            'bucket_at' => intdiv($report->observed_at, $report->bucket_seconds) * $report->bucket_seconds,
            'first_seen' => $report->observed_at, 'last_seen' => $report->observed_at,
            'raw_u' => $u, 'raw_d' => $d, 'connections' => 0,
        ] + (new AccessTrafficClassifier())->classify('', 'unknown');
    }

    public function process(int $reportId): void
    {
        $nodeId = DB::table('v2_access_report')->where('id', $reportId)->value('node_id');
        if (!$nodeId) return;
        DB::transaction(function () use ($reportId, $nodeId): void {
            DB::table('v2_server')->where('id', $nodeId)->lockForUpdate()->first();
            $report = DB::table('v2_access_report')->where('id', $reportId)->lockForUpdate()->first();
            if (!$report || $report->settled_at || $report->settlement_error) return;
            if (!$report->processed_at) {
                $payload = json_decode($report->payload, true, 512, JSON_THROW_ON_ERROR);
                $remaining = $payload['totals'];
                $rows = [];
                $classifier = new AccessTrafficClassifier();
                foreach ($payload['entries'] as $entry) {
                    // Keep the durable wire fingerprint stable across collector upgrades.
                    if (self::isUnspecifiedIP($entry['ip'])) {
                        $entry['ip'] = '';
                        if ($entry['domain'] === '') {
                            $entry['port'] = 0;
                            $entry['domain_source'] = 'unknown';
                            if ($entry['u'] || $entry['d']) $report->coverage = 'partial';
                        }
                    }
                    $uid = $entry['user_id'];
                    $remaining[$uid][0] -= $entry['u'];
                    $remaining[$uid][1] -= $entry['d'];
                    $rows[] = ['report_id' => $reportId, 'node_id' => $nodeId, 'raw_u' => $entry['u'], 'raw_d' => $entry['d']]
                        + array_diff_key($entry, array_flip(['u', 'd'])) + $classifier->classify($entry['domain'], $entry['domain_source'], $entry['ip']);
                }
                foreach ($remaining as $uid => [$u, $d]) {
                    if ($u || $d) $rows[] = $this->unknownRow($report, (int) $uid, $u, $d);
                }
                foreach (array_chunk($rows, 200) as $chunk) DB::table('v2_access_traffic')->insert($chunk);
                DB::table('v2_access_report')->where('id', $reportId)->update(['processed_at' => time(), 'payload' => null, 'coverage' => $report->coverage]);
            }
            $billing = DB::table('v2_traffic_report')->where('node_id', $nodeId)->where('report_id', $report->billing_report_id)->first();
            if (!$billing || !$billing->billing_snapshot) return;
            if (!hash_equals($billing->payload_hash, $report->totals_hash)) {
                DB::table('v2_access_report')->where('id', $reportId)->update(['settlement_error' => 'totals_mismatch']);
                return;
            }
            $snapshot = json_decode($billing->billing_snapshot, true, 512, JSON_THROW_ON_ERROR);
            $groups = DB::table('v2_access_traffic')->where('report_id', $reportId)->orderBy('id')->get()->groupBy('user_id');
            foreach ($snapshot['users'] as $uid => $actual) {
                $entries = $groups->get($uid, collect());
                $needsUnknown = ($actual['billed_u'] > 0 && $entries->sum('raw_u') === 0)
                    || ($actual['billed_d'] > 0 && $entries->sum('raw_d') === 0);
                if ($needsUnknown && !$entries->contains(fn ($entry) => $entry->domain_source === 'unknown')) {
                    DB::table('v2_access_traffic')->insert($this->unknownRow($report, (int) $uid, 0, 0));
                }
            }
            $groups = DB::table('v2_access_traffic')->where('report_id', $reportId)->orderBy('id')->get()->groupBy('user_id');
            foreach ($groups as $uid => $entries) {
                $actual = $snapshot['users'][$uid] ?? ['billed_u' => 0, 'billed_d' => 0];
                $rows = $entries->all();
                if (($actual['billed_u'] > 0 && $entries->sum('raw_u') === 0)
                    || ($actual['billed_d'] > 0 && $entries->sum('raw_d') === 0)) {
                    // A hook-created direction has no destination evidence in the original counters.
                    usort($rows, fn ($a, $b) => (($a->domain_source !== 'unknown') <=> ($b->domain_source !== 'unknown')) ?: ($a->id <=> $b->id));
                }
                $uploads = self::allocate(array_map(fn ($row) => (int) $row->raw_u, $rows), (int) $actual['billed_u']);
                $downloads = self::allocate(array_map(fn ($row) => (int) $row->raw_d, $rows), (int) $actual['billed_d']);
                $updates = [];
                foreach ($rows as $index => $row) {
                    $updates[] = array_replace((array) $row, [
                        'billed_u' => $uploads[$index], 'billed_d' => $downloads[$index], 'rate' => $snapshot['rate'],
                    ]);
                }
                foreach (array_chunk($updates, 25) as $chunk) {
                    DB::table('v2_access_traffic')->upsert($chunk, ['id'], ['billed_u', 'billed_d', 'rate']);
                }
            }
            DB::table('v2_access_report')->where('id', $reportId)->update(['settled_at' => $billing->billed_at]);
        }, 3);
    }

    /** Exact quotient/remainder without overflowing the integer product. */
    private static function mulDiv(int $weight, int $target, int $total): array
    {
        if ($weight === $total) return [$target, 0];
        $quotient = $remainder = 0;
        foreach (str_split(decbin($target)) as $bit) {
            $quotient *= 2;
            if ($remainder >= $total - $remainder) {
                $remainder -= $total - $remainder;
                $quotient++;
            } else $remainder += $remainder;
            if ($bit === '1') {
                if ($remainder >= $total - $weight) {
                    $remainder -= $total - $weight;
                    $quotient++;
                } else $remainder += $weight;
            }
        }
        return [$quotient, $remainder];
    }

    public static function allocate(array $weights, int $target): array
    {
        $total = array_sum($weights);
        $result = array_fill(0, count($weights), 0);
        if (!$weights || !$target) return $result;
        if (!$total) { $result[0] = $target; return $result; }
        $remainders = [];
        $remaining = $target;
        foreach ($weights as $index => $weight) {
            [$result[$index], $remainders[$index]] = self::mulDiv($weight, $target, $total);
            $remaining -= $result[$index];
        }
        $indices = array_keys($weights);
        usort($indices, fn ($a, $b) => ($remainders[$b] <=> $remainders[$a]) ?: ($a <=> $b));
        foreach (array_slice($indices, 0, $remaining) as $index) $result[$index]++;
        return $result;
    }

    public function repair(int $limit = 100): int
    {
        $ids = DB::table('v2_access_report')->whereNull('settled_at')->whereNull('settlement_error')
            ->where(function ($query): void {
                $query->whereNull('processed_at')->orWhereExists(function ($billing): void {
                    $billing->selectRaw('1')->from('v2_traffic_report as b')
                        ->whereColumn('b.node_id', 'v2_access_report.node_id')
                        ->whereColumn('b.report_id', 'v2_access_report.billing_report_id')->whereNotNull('b.billing_snapshot');
                });
            })->orderBy('id')->limit($limit)->pluck('id');
        foreach ($ids as $id) $this->process((int) $id);
        return $ids->count();
    }

    public function prune(?int $now = null): int
    {
        $cutoff = ($now ?? time()) - (int) $this->settings()['retention_days'] * 86400;
        $deleted = 0;
        do {
            $ids = DB::table('v2_access_report')->where('observed_at', '<', $cutoff - 300)
                ->whereNotExists(function ($query) use ($cutoff): void {
                    $query->selectRaw('1')->from('v2_access_traffic as e')
                        ->whereColumn('e.report_id', 'v2_access_report.id')->where('e.last_seen', '>=', $cutoff);
                })->orderBy('id')->limit(500)->pluck('id');
            if ($ids->isEmpty()) break;
            $deleted += DB::transaction(function () use ($ids): int {
                DB::table('v2_access_report')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
                DB::table('v2_access_traffic')->whereIn('report_id', $ids)->delete();
                return DB::table('v2_access_report')->whereIn('id', $ids)->delete();
            });
        } while ($ids->count() === 500);
        while (true) {
            $ids = DB::table('v2_access_traffic')->where('last_seen', '<', $cutoff)->distinct()->limit(500)->pluck('report_id');
            if ($ids->isEmpty()) break;
            DB::transaction(function () use ($ids, $cutoff): void {
                DB::table('v2_access_report')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
                // Never allocate a full billing target over a detail set truncated by retention.
                DB::table('v2_access_report')->whereIn('id', $ids)->whereNull('settled_at')
                    ->update(['settlement_error' => 'retention_expired']);
                DB::table('v2_access_report')->whereIn('id', $ids)->update(['coverage' => 'partial']);
                DB::table('v2_access_traffic')->whereIn('report_id', $ids)->where('last_seen', '<', $cutoff)->delete();
            }, 3);
        }
        // Keep billing idempotency keys; only the destination-association snapshot expires.
        DB::table('v2_traffic_report')->where('received_at', '<', $cutoff)->whereNotNull('billing_snapshot')
            ->update(['billing_snapshot' => null]);
        return $deleted;
    }
}
