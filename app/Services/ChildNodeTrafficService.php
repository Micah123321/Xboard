<?php

namespace App\Services;

use App\Jobs\ChildNodeTrafficBillingJob;
use App\Jobs\TrafficBillingJob;
use App\Models\Server;
use App\Services\Plugin\HookManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ChildNodeTrafficService
{
    public const AUTH_ID_OFFSET = 1099511627776;
    public const MAX_AUTH_ID = 9007199254740991;

    public static function billingReportId(int $sourceId, string $reportId, int $targetId): string
    {
        if (trim($reportId) === '' || strlen($reportId) > 128) {
            throw new InvalidArgumentException('Invalid report_id.');
        }
        return $sourceId === $targetId ? $reportId
            : 'child:' . hash('sha256', json_encode([$sourceId, $reportId, $targetId], JSON_THROW_ON_ERROR));
    }

    /** Resolve the entire wire batch before hooks, writes or dispatch. Historical identities remain valid. */
    public function splitTraffic(Server $source, array $traffic): array
    {
        $traffic = TrafficBillingJob::normalize($traffic);
        $sourceId = (int) $source->id;
        if (PHP_INT_SIZE < 8 || $sourceId < 1) throw new InvalidArgumentException('Invalid traffic source or integer size.');
        $ids = [];
        foreach ($traffic as $authId => $_) {
            if ($authId < self::AUTH_ID_OFFSET) continue;
            if ($authId <= self::AUTH_ID_OFFSET || $authId > self::MAX_AUTH_ID) {
                throw new InvalidArgumentException('Invalid child authentication ID.');
            }
            $ids[] = $authId - self::AUTH_ID_OFFSET;
        }
        $identities = collect();
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach (DB::table('v2_server_child_identity')->whereIn('id', $chunk)->get() as $identity) {
                $identities->put((int) $identity->id, $identity);
            }
        }
        $children = collect();
        foreach (array_chunk($identities->pluck('child_id')->unique()->all(), 1000) as $chunk) {
            foreach (Server::whereIn('id', $chunk)->get() as $child) $children->put((int) $child->id, $child);
        }
        $groups = [$sourceId => []];
        $nodes = [$sourceId => $source];
        $mapping = [];
        foreach ($traffic as $authId => $pair) {
            $targetId = $sourceId;
            $userId = $authId;
            if ($authId >= self::AUTH_ID_OFFSET) {
                $identity = $identities->get($authId - self::AUTH_ID_OFFSET);
                $child = $identity ? $children->get((int) $identity->child_id) : null;
                if (!$identity || (int) $identity->parent_id !== $sourceId || !$child
                    || (int) $child->id === $sourceId
                    || (int) $identity->user_id < 1 || (int) $identity->user_id >= self::AUTH_ID_OFFSET) {
                    throw new InvalidArgumentException('Unknown or foreign child traffic identity.');
                }
                $targetId = (int) $child->id;
                $userId = (int) $identity->user_id;
                $nodes[$targetId] = $child;
            }
            $mapping[$authId] = ['node_id' => $targetId, 'user_id' => $userId];
            $groups[$targetId][$userId] ??= [0, 0];
            $groups[$targetId][$userId][0] += $pair[0];
            $groups[$targetId][$userId][1] += $pair[1];
        }
        // Stable lock order after the root prevents sibling batches from reversing child locks.
        $own = $groups[$sourceId];
        unset($groups[$sourceId]);
        ksort($groups, SORT_NUMERIC);
        $groups = [$sourceId => $own] + $groups;
        foreach ($groups as &$group) $group = TrafficBillingJob::normalize($group);
        unset($group);
        return ['traffic' => $groups, 'nodes' => $nodes, 'mapping' => $mapping];
    }

    public function processTraffic(Server $source, array $traffic, ?string $reportId, ?int $receivedAt): array
    {
        if ($reportId === null) throw new InvalidArgumentException('Child traffic requires report_id.');
        self::billingReportId((int) $source->id, $reportId, (int) $source->id);
        $receivedAt ??= time();
        TrafficBillingJob::recordAt($receivedAt);
        $traffic = TrafficBillingJob::normalize($traffic);
        $split = $this->splitTraffic($source, $traffic);
        $clock = Carbon::createFromTimestamp($receivedAt, config('app.timezone', 'UTC'))->format('H:i');
        $jobs = [];
        foreach ($split['traffic'] as $targetId => $data) {
            $node = $split['nodes'][$targetId];
            $rate = $node->rate;
            if ($node->rate_time_enable) {
                foreach ($node->rate_time_ranges ?? [] as $range) {
                    if ($clock >= $range['start'] && $clock <= $range['end']) {
                        $rate = $range['rate'];
                        break;
                    }
                }
            }
            $server = $node->toArray();
            $server['rate'] = $rate;
            $protocol = $node->type;
            $hash = TrafficBillingJob::fingerprint($targetId === (int) $source->id ? $traffic : $data);
            if ($data) {
                [$server, $protocol, $data] = HookManager::filter('traffic.process.before', [$server, $protocol, $data]);
                [$server, $protocol, $data] = HookManager::filter('traffic.before_process', [$server, $protocol, $data]);
            }
            $server['id'] = $targetId;
            $jobs[] = new TrafficBillingJob($server, $data, $protocol, $receivedAt,
                self::billingReportId((int) $source->id, $reportId, $targetId), $hash);
        }
        ChildNodeTrafficBillingJob::dispatch($jobs)->afterCommit();
        return $split;
    }
}
