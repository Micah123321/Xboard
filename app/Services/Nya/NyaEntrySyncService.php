<?php

namespace App\Services\Nya;

use App\Models\Server;
use App\Services\ServerEntryPointService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class NyaEntrySyncService
{
    public function __construct(private NyaClient $client)
    {
    }

    public function sync(bool $dryRun = false, ?array $serverIds = null, ?array $ports = null, ?array $settings = null): array
    {
        $serverIds = $this->positiveIntegers($serverIds ?? ($settings['server_ids'] ?? config('nya_entry_sync.server_ids', [])), 'server IDs');
        $ports = $this->positiveIntegers($ports ?? ($settings['ports'] ?? config('nya_entry_sync.ports', [])), 'ports', 65535);
        if (!$serverIds) {
            throw new RuntimeException('Configure NYA_ENTRY_SYNC_SERVER_IDS or supply --server before syncing.');
        }

        $rulesByPort = [];
        $accounts = [];
        foreach ($this->client->fetchEntries($settings) as $rule) {
            $rulesByPort[$rule['port']][] = $rule;
            if (isset($rule['account_id'])) {
                $id = $rule['account_id'];
                $accounts[$id] ??= ['id' => $id, 'name' => $rule['account_name'], 'rules' => 0];
                $accounts[$id]['rules']++;
            }
        }

        return DB::transaction(function () use ($serverIds, $ports, $rulesByPort, $dryRun, $accounts): array {
            $servers = Server::whereIn('id', $serverIds)->orderBy('id')->lockForUpdate()->get();
            if ($servers->count() !== count($serverIds)) {
                throw new RuntimeException('One or more selected server IDs do not exist; no changes saved.');
            }
            $result = ['servers' => $servers->count(), 'updated' => 0, 'matched' => 0,
                'unchanged' => 0, 'skipped' => 0, 'changes' => [], 'warnings' => [],
                'accounts_checked' => count($accounts), 'account_summaries' => array_values($accounts)];
            $pending = [];
            foreach ($servers as $server) {
                $entries = $server->entry_points;
                if ($entries !== null && (!is_array($entries) || !array_is_list($entries)
                    || count($entries) > ServerEntryPointService::MAX_EXTRA_ENTRIES)) {
                    throw new RuntimeException("Server {$server->id} has an invalid extra entry list; no changes saved.");
                }
                foreach ($entries ?? [] as $index => $entry) {
                    if (!is_array($entry) || !is_string($entry['host'] ?? null)
                        || !ServerEntryPointService::validHost($entry['host'])
                        || !ServerEntryPointService::validPort($entry['port'] ?? null)) {
                        throw new RuntimeException("Server {$server->id} has an invalid extra entry; no changes saved.");
                    }
                }
                // Reserve current hosts before allocating replacements within each server and port.
                $used = [];
                foreach (array_merge([['host' => $server->host, 'port' => $server->port]], $entries ?? []) as $entry) {
                    if (ServerEntryPointService::validPort($entry['port'])) {
                        $used[(int) $entry['port']][ServerEntryPointService::normalizeHost($entry['host'])] = true;
                    }
                }
                $host = $this->replacement($server, 'host', $server->host, $server->port,
                    $rulesByPort, $ports, $used, $result);
                foreach ($entries ?? [] as $index => $entry) {
                    $entries[$index]['host'] = $this->replacement($server, "entry_points.{$index}.host",
                        $entry['host'], $entry['port'], $rulesByPort, $ports, $used, $result);
                }
                if ($host === $server->host && $entries === $server->entry_points) {
                    continue;
                }
                // Reject collapsed pools rather than silently changing their weighting or size.
                $seen = [];
                foreach (array_merge([['host' => $host, 'port' => $server->port]], $entries ?? []) as $entry) {
                    $port = ServerEntryPointService::validPort($entry['port']) ? (int) $entry['port'] : $entry['port'];
                    $key = json_encode([ServerEntryPointService::normalizeHost($entry['host']), $port]);
                    if (isset($seen[$key])) {
                        throw new RuntimeException("Server {$server->id} would have duplicate entries; no changes saved.");
                    }
                    $seen[$key] = true;
                }
                $server->host = $host;
                $server->entry_points = $entries;
                $pending[] = $server;
            }
            if ($result['matched'] === 0) {
                throw new RuntimeException('No selected connection ports match NYA rules; no changes saved.');
            }
            $result['updated'] = count($pending);
            if (!$dryRun) {
                foreach ($pending as $server) {
                    $server->save();
                }
            }
            return $result;
        });
    }

    private function replacement(Server $server, string $field, string $host, mixed $port,
        array $rulesByPort, array $ports, array &$used, array &$result): string
    {
        if (!ServerEntryPointService::validPort($port) || ($ports && !in_array((int) $port, $ports, true))) {
            $result['skipped']++;
            return $host;
        }
        $rules = $rulesByPort[(int) $port] ?? [];
        if (!$rules) {
            $result['skipped']++;
            $result['warnings'][] = "Server {$server->id} {$field}: no NYA rule for connection port {$port}; kept.";
            return $host;
        }
        if (count($rules) !== 1) {
            throw new RuntimeException("Multiple NYA rules use connection port {$port}; no changes saved.");
        }
        $rule = $rules[0];
        $result['matched']++;
        $normalized = ServerEntryPointService::normalizeHost($host);
        if (in_array($normalized, $rule['ips'], true)) {
            $result['unchanged']++;
            return $host;
        }
        $replacement = null;
        foreach ($rule['ips'] as $ip) {
            if (!isset($used[(int) $port][$ip])) {
                $replacement = $ip;
                $used[(int) $port][$ip] = true;
                break;
            }
        }
        if ($replacement === null) {
            throw new RuntimeException("Server {$server->id} would have duplicate entries; no changes saved.");
        }
        $change = ['server_id' => $server->id, 'field' => $field, 'port' => (int) $port,
            'rule_id' => $rule['rule_id'], 'from' => $host, 'to' => $replacement];
        if (isset($rule['account_name'])) {
            $change['account_name'] = $rule['account_name'];
        }
        $result['changes'][] = $change;
        return $replacement;
    }

    private function positiveIntegers(array $values, string $label, int $max = PHP_INT_MAX): array
    {
        $result = [];
        foreach ($values as $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            if (is_string($value)) {
                $value = trim($value);
            }
            if ((!is_int($value) && !is_string($value)) || !preg_match('/^[0-9]+$/D', (string) $value)
                || (float) $value < 1 || (float) $value > $max) {
                throw new RuntimeException("Invalid NYA sync {$label}; use positive integers.");
            }
            $result[] = (int) $value;
        }
        return array_values(array_unique($result));
    }
}
