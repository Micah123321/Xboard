<?php

namespace App\Console\Commands;

use App\Models\Plugin;
use App\Models\Server;
use App\Models\ServerRoute;
use App\Models\StatServer;
use App\Services\Plugin\PluginManager;
use App\Services\ServerGfwCheckService;
use App\Services\ServerTrafficLimitService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class DiagnoseAdminServerEndpoints extends Command
{
    protected $signature = 'diagnose:admin-server-endpoints {--current=3} {--page_size=20} {--explain : Print EXPLAIN for the node stat aggregate query}';

    protected $description = 'Profile admin server node and route endpoints without printing credentials';

    private array $queries = [];

    public function handle(): int
    {
        $current = max(1, (int) $this->option('current'));
        $pageSize = min(200, max(1, (int) $this->option('page_size')));
        $pageServers = collect();
        $pageServerIds = [];
        $statExplain = null;

        DB::listen(function ($query): void {
            $this->queries[] = [
                'sql' => (string) $query->sql,
                'time' => (float) $query->time,
            ];
        });

        $this->line('Profiling admin server endpoints');
        $this->line('DB driver: ' . DB::connection()->getDriverName() . ', database: ' . DB::connection()->getDatabaseName());
        $this->line("Node page: current={$current}, page_size={$pageSize}");
        $this->newLine();

        $rows = [];
        $rows[] = $this->measure('db: select 1', fn () => DB::select('select 1'));
        $rows[] = $this->measure('settings: admin_setting()', fn () => [
            'app_url' => is_string(admin_setting('app_url')) ? 'set' : 'empty',
            'force_https' => (bool) admin_setting('force_https', false),
        ]);
        $rows[] = $this->measure('plugins: initialize enabled', function () {
            app(PluginManager::class)->initializeEnabledPlugins();
            return ['enabled_plugins' => Plugin::where('is_enabled', true)->count()];
        });
        $rows[] = $this->measure('table counts', fn () => [
            'servers' => Server::count(),
            'routes' => ServerRoute::count(),
            'stat_server' => StatServer::count(),
            'enabled_plugins' => Plugin::where('is_enabled', true)->count(),
        ]);
        $rows[] = $this->measure('route/fetch: DB query + cast', fn () => $this->profileRouteFetch());
        $rows[] = $this->measure('route/fetch: cache key status', fn () => [
            'cache_key' => ServerRoute::FETCH_CACHE_KEY,
            'hit' => Cache::has(ServerRoute::FETCH_CACHE_KEY),
        ]);
        $rows[] = $this->measure('nodes: page query + append', function () use ($current, $pageSize, &$pageServers, &$pageServerIds) {
            $query = Server::query()->orderBy('sort', 'ASC');
            $total = (clone $query)->count();
            $pageServers = $query
                ->with('parent')
                ->skip(($current - 1) * $pageSize)
                ->take($pageSize)
                ->get();
            $pageServers->append([
                'last_check_at', 'last_push_at', 'online', 'is_online',
                'available_status', 'cache_key', 'load_status', 'metrics', 'online_conn',
            ]);
            $pageServerIds = $pageServers->pluck('id')->map(fn ($id) => (int) $id)->all();

            return [
                'total' => $total,
                'page_rows' => count($pageServerIds),
                'first_id' => $pageServerIds[0] ?? null,
                'last_id' => $pageServerIds ? $pageServerIds[count($pageServerIds) - 1] : null,
            ];
        });
        $rows[] = $this->measure('nodes: traffic stats aggregate', function () use (&$pageServerIds, &$statExplain) {
            [$result, $explain] = $this->profileNodeTrafficStats($pageServerIds, (bool) $this->option('explain'));
            $statExplain = $explain;
            return $result;
        });
        $rows[] = $this->measure('nodes: traffic limit snapshots', function () use (&$pageServers) {
            return ['snapshots' => count(app(ServerTrafficLimitService::class)->buildSnapshotsForServers($pageServers))];
        });
        $rows[] = $this->measure('nodes: gfw decorate', function () use (&$pageServers) {
            return ['decorated' => app(ServerGfwCheckService::class)->decorateServers($pageServers)->count()];
        });
        $rows[] = $this->measure('indexes: v2_stat_server', fn () => $this->profileStatServerIndexes());

        $this->table(['Step', 'Wall ms', 'SQL ms', 'Queries', 'Result'], $rows);

        $slowQueries = collect($this->queries)
            ->sortByDesc('time')
            ->take(10)
            ->values()
            ->map(fn (array $query) => [
                number_format($query['time'], 2),
                $this->shortenSql($query['sql']),
            ])
            ->all();

        if ($slowQueries) {
            $this->newLine();
            $this->table(['SQL ms', 'SQL'], $slowQueries);
        }

        if ($statExplain !== null) {
            $this->newLine();
            $this->line('EXPLAIN for nodes: traffic stats aggregate');
            foreach ($statExplain as $row) {
                $this->line(json_encode((array) $row, JSON_UNESCAPED_UNICODE));
            }
        }

        return self::SUCCESS;
    }

    private function measure(string $name, callable $callback): array
    {
        $queryStart = count($this->queries);
        $start = microtime(true);

        try {
            $result = $callback();
            $summary = $this->summarize($result);
        } catch (Throwable $e) {
            $summary = 'ERROR: ' . $e->getMessage();
        }

        $wallMs = (microtime(true) - $start) * 1000;
        $queries = array_slice($this->queries, $queryStart);
        $sqlMs = array_sum(array_column($queries, 'time'));

        return [
            $name,
            number_format($wallMs, 2),
            number_format($sqlMs, 2),
            count($queries),
            $summary,
        ];
    }

    private function profileRouteFetch(): array
    {
        $routes = ServerRoute::query()
            ->select(['id', 'remarks', 'match', 'action', 'action_value', 'created_at', 'updated_at'])
            ->orderBy('id')
            ->get();

        $payload = json_encode($routes->toArray(), JSON_UNESCAPED_UNICODE);

        return [
            'rows' => $routes->count(),
            'payload_bytes' => is_string($payload) ? strlen($payload) : 0,
        ];
    }

    private function profileNodeTrafficStats(array $serverIds, bool $withExplain): array
    {
        if (empty($serverIds)) {
            return [['servers' => 0, 'rows' => 0], null];
        }

        $windows = $this->resolveNodeTrafficWindows();
        $query = StatServer::query()
            ->select('server_id')
            ->selectRaw('COALESCE(SUM(u), 0) as total_upload')
            ->selectRaw('COALESCE(SUM(d), 0) as total_download')
            ->whereIn('server_id', $serverIds)
            ->where('record_type', 'd')
            ->groupBy('server_id');

        foreach ($windows as $key => $window) {
            $query
                ->selectRaw(
                    "COALESCE(SUM(CASE WHEN record_at >= ? AND record_at < ? THEN u ELSE 0 END), 0) as {$key}_upload",
                    [$window['start'], $window['end']]
                )
                ->selectRaw(
                    "COALESCE(SUM(CASE WHEN record_at >= ? AND record_at < ? THEN d ELSE 0 END), 0) as {$key}_download",
                    [$window['start'], $window['end']]
                );
        }

        $explain = $withExplain ? $this->explain($query->toSql(), $query->getBindings()) : null;
        $rows = $query->get();

        return [[
            'servers' => count($serverIds),
            'stat_rows' => StatServer::whereIn('server_id', $serverIds)->where('record_type', 'd')->count(),
            'aggregate_rows' => $rows->count(),
        ], $explain];
    }

    private function resolveNodeTrafficWindows(?int $referenceTimestamp = null): array
    {
        $now = Carbon::createFromTimestamp($referenceTimestamp ?? time(), config('app.timezone'));

        return [
            'today' => [
                'start' => $now->copy()->startOfDay()->timestamp,
                'end' => $now->copy()->addDay()->startOfDay()->timestamp,
            ],
            'yesterday' => [
                'start' => $now->copy()->subDay()->startOfDay()->timestamp,
                'end' => $now->copy()->startOfDay()->timestamp,
            ],
            'month' => [
                'start' => $now->copy()->startOfMonth()->timestamp,
                'end' => $now->copy()->addMonthNoOverflow()->startOfMonth()->timestamp,
            ],
        ];
    }

    private function profileStatServerIndexes(): array
    {
        $driver = DB::connection()->getDriverName();

        return match ($driver) {
            'mysql' => collect(DB::select('SHOW INDEX FROM v2_stat_server'))
                ->pluck('Column_name', 'Key_name')
                ->all(),
            'pgsql' => collect(DB::select("SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = current_schema() AND tablename = 'v2_stat_server'"))
                ->pluck('indexdef', 'indexname')
                ->all(),
            'sqlite' => collect(DB::select("PRAGMA index_list('v2_stat_server')"))
                ->pluck('name')
                ->all(),
            default => ['driver' => $driver],
        };
    }

    private function explain(string $sql, array $bindings): array
    {
        $driver = DB::connection()->getDriverName();
        if (!in_array($driver, ['mysql', 'pgsql', 'sqlite'], true)) {
            return [];
        }

        return DB::select('EXPLAIN ' . $sql, $bindings);
    }

    private function summarize(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            return get_debug_type($value);
        }

        return mb_strlen($encoded) > 180 ? mb_substr($encoded, 0, 177) . '...' : $encoded;
    }

    private function shortenSql(string $sql): string
    {
        $sql = preg_replace('/\s+/', ' ', $sql) ?? $sql;
        return mb_strlen($sql) > 180 ? mb_substr($sql, 0, 177) . '...' : $sql;
    }
}
