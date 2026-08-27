<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\TrafficLogResource;
use App\Models\CommissionLog;
use App\Models\Order;
use App\Models\Server;
use App\Models\ServerGfwCheck;
use App\Models\Stat;
use App\Models\StatServer;
use App\Models\StatUser;
use App\Models\Ticket;
use App\Models\User;
use App\Services\StatisticalService;
use App\Services\UserOnlineService;
use App\Utils\CacheKey;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class StatController extends Controller
{
    private const DASHBOARD_STATS_CACHE_TTL_SECONDS = 30;
    private const TRAFFIC_RANK_CACHE_TTL_SECONDS = 60;
    private const GFW_RECENT_RECOVERY_WINDOW_SECONDS = 86400;

    private ?StatisticalService $service = null;

    private function statisticalService(): StatisticalService
    {
        return $this->service ??= app(StatisticalService::class);
    }
    public function getOverride(Request $request)
    {
        // 获取在线节点数
        $onlineNodes = Server::all()->filter(function ($server) {
            return !!$server->is_online;
        })->count();
        // 获取在线设备数和在线用户数
        $onlineDevices = User::where('t', '>=', time() - 600)
            ->sum('online_count');
        $onlineUsers = User::where('t', '>=', time() - 600)
            ->count();

        // 获取今日流量统计
        $todayStart = strtotime('today');
        $todayTraffic = StatServer::where('record_at', '>=', $todayStart)
            ->where('record_at', '<', time())
            ->selectRaw('SUM(u) as upload, SUM(d) as download, SUM(u + d) as total')
            ->first();

        // 获取本月流量统计
        $monthStart = strtotime(date('Y-m-1'));
        $monthTraffic = StatServer::where('record_at', '>=', $monthStart)
            ->where('record_at', '<', time())
            ->selectRaw('SUM(u) as upload, SUM(d) as download, SUM(u + d) as total')
            ->first();

        // 获取总流量统计
        $totalTraffic = StatServer::selectRaw('SUM(u) as upload, SUM(d) as download, SUM(u + d) as total')
            ->first();

        return [
            'data' => [
                'month_income' => Order::where('created_at', '>=', strtotime(date('Y-m-1')))
                    ->where('created_at', '<', time())
                    ->whereNotIn('status', [0, 2])
                    ->sum('total_amount'),
                'month_register_total' => User::where('created_at', '>=', strtotime(date('Y-m-1')))
                    ->where('created_at', '<', time())
                    ->count(),
                'ticket_pending_total' => Ticket::where('status', 0)
                    ->count(),
                'commission_pending_total' => Order::where('commission_status', 0)
                    ->where('invite_user_id', '!=', NULL)
                    ->whereNotIn('status', [0, 2])
                    ->where('commission_balance', '>', 0)
                    ->count(),
                'day_income' => Order::where('created_at', '>=', strtotime(date('Y-m-d')))
                    ->where('created_at', '<', time())
                    ->whereNotIn('status', [0, 2])
                    ->sum('total_amount'),
                'last_month_income' => Order::where('created_at', '>=', strtotime('-1 month', strtotime(date('Y-m-1'))))
                    ->where('created_at', '<', strtotime(date('Y-m-1')))
                    ->whereNotIn('status', [0, 2])
                    ->sum('total_amount'),
                'commission_month_payout' => CommissionLog::where('created_at', '>=', strtotime(date('Y-m-1')))
                    ->where('created_at', '<', time())
                    ->sum('get_amount'),
                'commission_last_month_payout' => CommissionLog::where('created_at', '>=', strtotime('-1 month', strtotime(date('Y-m-1'))))
                    ->where('created_at', '<', strtotime(date('Y-m-1')))
                    ->sum('get_amount'),
                // 新增统计数据
                'online_nodes' => $onlineNodes,
                'online_devices' => $onlineDevices,
                'online_users' => $onlineUsers,
                'today_traffic' => [
                    'upload' => $todayTraffic->upload ?? 0,
                    'download' => $todayTraffic->download ?? 0,
                    'total' => $todayTraffic->total ?? 0
                ],
                'month_traffic' => [
                    'upload' => $monthTraffic->upload ?? 0,
                    'download' => $monthTraffic->download ?? 0,
                    'total' => $monthTraffic->total ?? 0
                ],
                'total_traffic' => [
                    'upload' => $totalTraffic->upload ?? 0,
                    'download' => $totalTraffic->download ?? 0,
                    'total' => $totalTraffic->total ?? 0
                ]
            ]
        ];
    }

    /**
     * Get order statistics with filtering and pagination
     *
     * @param Request $request
     * @return array
     */
    public function getOrder(Request $request)
    {
        $request->validate([
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d',
            'type' => 'nullable|in:paid_total,paid_count,commission_total,commission_count',
        ]);

        $query = Stat::where('record_type', 'd');

        // Apply date filters
        if ($request->input('start_date')) {
            $query->where('record_at', '>=', strtotime($request->input('start_date')));
        }
        if ($request->input('end_date')) {
            $query->where('record_at', '<=', strtotime($request->input('end_date') . ' 23:59:59'));
        }

        $statistics = $query->orderBy('record_at', 'DESC')
            ->get();

        $summary = [
            'paid_total' => 0,
            'paid_count' => 0,
            'commission_total' => 0,
            'commission_count' => 0,
            'start_date' => $request->input('start_date', date('Y-m-d', $statistics->last()?->record_at)),
            'end_date' => $request->input('end_date', date('Y-m-d', $statistics->first()?->record_at)),
            'avg_paid_amount' => 0,
            'avg_commission_amount' => 0
        ];

        $dailyStats = [];
        foreach ($statistics as $statistic) {
            $date = date('Y-m-d', $statistic['record_at']);

            // Update summary
            $summary['paid_total'] += $statistic['paid_total'];
            $summary['paid_count'] += $statistic['paid_count'];
            $summary['commission_total'] += $statistic['commission_total'];
            $summary['commission_count'] += $statistic['commission_count'];

            // Calculate daily stats
            $dailyData = [
                'date' => $date,
                'paid_total' => $statistic['paid_total'],
                'paid_count' => $statistic['paid_count'],
                'commission_total' => $statistic['commission_total'],
                'commission_count' => $statistic['commission_count'],
                'avg_order_amount' => $statistic['paid_count'] > 0 ? round($statistic['paid_total'] / $statistic['paid_count'], 2) : 0,
                'avg_commission_amount' => $statistic['commission_count'] > 0 ? round($statistic['commission_total'] / $statistic['commission_count'], 2) : 0
            ];

            if ($request->input('type')) {
                $dailyStats[] = [
                    'date' => $date,
                    'value' => $statistic[$request->input('type')],
                    'type' => $this->getTypeLabel($request->input('type'))
                ];
            } else {
                $dailyStats[] = $dailyData;
            }
        }

        // Calculate averages for summary
        if ($summary['paid_count'] > 0) {
            $summary['avg_paid_amount'] = round($summary['paid_total'] / $summary['paid_count'], 2);
        }
        if ($summary['commission_count'] > 0) {
            $summary['avg_commission_amount'] = round($summary['commission_total'] / $summary['commission_count'], 2);
        }

        // Add percentage calculations to summary
        $summary['commission_rate'] = $summary['paid_total'] > 0
            ? round(($summary['commission_total'] / $summary['paid_total']) * 100, 2)
            : 0;

        return [
            'code' => 0,
            'message' => 'success',
            'data' => [
                'list' => array_reverse($dailyStats),
                'summary' => $summary,
            ]
        ];
    }

    /**
     * Get human readable label for statistic type
     *
     * @param string $type
     * @return string
     */
    private function getTypeLabel(string $type): string
    {
        return match ($type) {
            'paid_total' => '收款金额',
            'paid_count' => '收款笔数',
            'commission_total' => '佣金金额(已发放)',
            'commission_count' => '佣金笔数(已发放)',
            default => $type
        };
    }

    // 获取当日实时流量排行
    public function getServerLastRank()
    {
        $data = $this->statisticalService()->getServerRank();
        return $this->success(data: $data);
    }
    // 获取昨日节点流量排行
    public function getServerYesterdayRank()
    {
        $data = $this->statisticalService()->getServerRank('yesterday');
        return $this->success($data);
    }

    public function getStatUser(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer',
            'min_total' => 'nullable|integer|min:0',
            'start_time' => 'nullable|integer|min:0',
            'end_time' => 'nullable|integer|min:0',
        ]);

        $pageSize = $request->input('pageSize', 10);
        $userId = (int) $request->input('user_id');
        $minTotal = max(0, (int) $request->input('min_total', 0));
        $startTime = $request->filled('start_time') ? (int) $request->input('start_time') : null;
        $endTime = $request->filled('end_time') ? (int) $request->input('end_time') : null;

        $baseQuery = StatUser::query()
            ->with(['server:id,name'])
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->orderByDesc('record_at')
            ->where('user_id', $userId)
            ->when($startTime !== null, function ($query) use ($startTime) {
                $query->where('updated_at', '>=', $startTime);
            })
            ->when($endTime !== null, function ($query) use ($endTime) {
                $query->where('updated_at', '<=', $endTime);
            })
            ->when($minTotal > 0, function ($query) use ($minTotal) {
                $query->whereRaw('(u + d) >= ?', [$minTotal]);
            });

        $summary = (clone $baseQuery)
            ->selectRaw('COALESCE(SUM(u), 0) as upload, COALESCE(SUM(d), 0) as download, COALESCE(SUM(u + d), 0) as total')
            ->first();

        $records = $baseQuery->paginate($pageSize);

        $deviceMap = $this->buildNodeDeviceMap($userId);
        $data = collect($records->items())
            ->map(function (StatUser $record) use ($deviceMap, $request): array {
                $serverType = strtolower((string) $record->server_type);
                $serverId = (int) $record->server_id;
                $nodeKey = $serverType !== '' && $serverId > 0 ? "{$serverType}{$serverId}" : null;
                $deviceIps = $nodeKey ? ($deviceMap[$nodeKey] ?? []) : [];

                $record->setAttribute('server_name', $record->server?->name);
                $record->setAttribute('node_name', $record->server?->name);
                $record->setAttribute('device_ips', $deviceIps);
                $record->setAttribute('device_count', count($deviceIps));
                $record->setAttribute('device_name', $deviceIps[0] ?? 'Unknown');

                return (new TrafficLogResource($record))->toArray($request);
            })
            ->all();

        return [
            'data' => $data,
            'total' => $records->total(),
            'summary' => [
                'upload' => (int) ($summary->upload ?? 0),
                'download' => (int) ($summary->download ?? 0),
                'total' => (int) ($summary->total ?? 0),
            ],
        ];
    }

    private function buildNodeDeviceMap(int $userId): array
    {
        $devices = UserOnlineService::getUserDevices($userId);
        $deviceList = data_get($devices, 'devices', []);

        return collect($deviceList)
            ->filter(fn($item): bool => is_array($item) && !empty($item['ip']))
            ->map(function (array $item): array {
                $nodeKey = strtolower((string) ($item['node_key'] ?? ''));
                if ($nodeKey === '') {
                    $nodeType = strtolower((string) ($item['node_type'] ?? ''));
                    $nodeId = (int) ($item['node_id'] ?? 0);
                    $nodeKey = ($nodeType !== '' && $nodeId > 0) ? "{$nodeType}{$nodeId}" : $nodeType;
                }

                return [
                    'node_key' => $nodeKey,
                    'ip' => (string) ($item['ip'] ?? ''),
                ];
            })
            ->filter(fn(array $item): bool => $item['node_key'] !== '' && $item['ip'] !== '')
            ->groupBy(fn(array $item): string => $item['node_key'])
            ->map(fn($items): array => collect($items)
                ->pluck('ip')
                ->filter()
                ->unique()
                ->values()
                ->all())
            ->all();
    }

    public function getStatRecord(Request $request)
    {
        return [
            'data' => $this->statisticalService()->getStatRecord($request->input('type'))
        ];
    }

    /**
     * Get comprehensive statistics data including income, users, and growth rates
     */
    public function getStats()
    {
        return Cache::remember(CacheKey::get('ADMIN_DASHBOARD_STATS'), self::DASHBOARD_STATS_CACHE_TTL_SECONDS, function (): array {
            return $this->buildStats();
        });
    }
    private function buildStats(): array
    {
        $now = time();
        $todayStart = strtotime('today');
        $yesterdayStart = strtotime('-1 day', $todayStart);
        $currentMonthStart = strtotime(date('Y-m-01'));
        $lastMonthStart = strtotime('-1 month', $currentMonthStart);
        $twoMonthsAgoStart = strtotime('-2 month', $currentMonthStart);

        $onlineNodes = $this->countOnlineServers();
        $nodeGfwStats = $this->buildNodeGfwStats();
        $userStats = $this->queryDashboardUserStats($now, $currentMonthStart, $lastMonthStart);
        $orderStats = $this->queryDashboardOrderStats($now, $todayStart, $yesterdayStart, $currentMonthStart, $lastMonthStart, $twoMonthsAgoStart);
        $commissionStats = $this->queryDashboardCommissionStats($now, $currentMonthStart, $lastMonthStart, $twoMonthsAgoStart);
        $trafficStats = $this->queryDashboardTrafficStats($now, $todayStart, $currentMonthStart);
        $ticketPendingTotal = (int) DB::table('v2_ticket')
            ->where('status', Ticket::STATUS_OPENING)
            ->count();

        $currentMonthIncome = $orderStats['currentMonthIncome'];
        $lastMonthIncome = $orderStats['lastMonthIncome'];
        $twoMonthsAgoIncome = $orderStats['twoMonthsAgoIncome'];
        $todayIncome = $orderStats['todayIncome'];
        $yesterdayIncome = $orderStats['yesterdayIncome'];
        $currentMonthCommissionPayout = $commissionStats['currentMonthCommissionPayout'];
        $lastMonthCommissionPayout = $commissionStats['lastMonthCommissionPayout'];
        $twoMonthsAgoCommission = $commissionStats['twoMonthsAgoCommission'];
        $currentMonthNewUsers = $userStats['currentMonthNewUsers'];
        $lastMonthNewUsers = $userStats['lastMonthNewUsers'];

        $monthIncomeGrowth = $lastMonthIncome > 0 ? round(($currentMonthIncome - $lastMonthIncome) / $lastMonthIncome * 100, 1) : 0;
        $lastMonthIncomeGrowth = $twoMonthsAgoIncome > 0 ? round(($lastMonthIncome - $twoMonthsAgoIncome) / $twoMonthsAgoIncome * 100, 1) : 0;
        $commissionGrowth = $twoMonthsAgoCommission > 0 ? round(($lastMonthCommissionPayout - $twoMonthsAgoCommission) / $twoMonthsAgoCommission * 100, 1) : 0;
        $userGrowth = $lastMonthNewUsers > 0 ? round(($currentMonthNewUsers - $lastMonthNewUsers) / $lastMonthNewUsers * 100, 1) : 0;
        $dayIncomeGrowth = $yesterdayIncome > 0 ? round(($todayIncome - $yesterdayIncome) / $yesterdayIncome * 100, 1) : 0;

        return [
            'data' => [
                // 收入相关
                'todayIncome' => $todayIncome,
                'dayIncomeGrowth' => $dayIncomeGrowth,
                'currentMonthIncome' => $currentMonthIncome,
                'lastMonthIncome' => $lastMonthIncome,
                'monthIncomeGrowth' => $monthIncomeGrowth,
                'lastMonthIncomeGrowth' => $lastMonthIncomeGrowth,

                // 佣金相关
                'currentMonthCommissionPayout' => $currentMonthCommissionPayout,
                'lastMonthCommissionPayout' => $lastMonthCommissionPayout,
                'commissionGrowth' => $commissionGrowth,
                'commissionPendingTotal' => $orderStats['commissionPendingTotal'],

                // 用户相关
                'currentMonthNewUsers' => $currentMonthNewUsers,
                'totalUsers' => $userStats['totalUsers'],
                'activeUsers' => $userStats['activeUsers'],
                'userGrowth' => $userGrowth,
                'onlineUsers' => $userStats['onlineUsers'],
                'onlineDevices' => $userStats['onlineDevices'],

                // 工单相关
                'ticketPendingTotal' => $ticketPendingTotal,

                // 节点相关
                'onlineNodes' => $onlineNodes,
                'nodeGfwStats' => $nodeGfwStats,

                // 流量统计
                'todayTraffic' => $trafficStats['todayTraffic'],
                'monthTraffic' => $trafficStats['monthTraffic'],
                'totalTraffic' => $trafficStats['totalTraffic'],
            ]
        ];
    }

    /**
     * Dashboard cold requests should be bounded SQL scans, not many ORM round trips.
     * This keeps the user table to one pass for all counters displayed by the dashboard.
     */
    private function queryDashboardUserStats(int $now, int $currentMonthStart, int $lastMonthStart): array
    {
        $onlineSince = $now - 600;
        $row = DB::table('v2_user')
            ->selectRaw('COUNT(*) as total_users')
            ->selectRaw('COALESCE(SUM(CASE WHEN expired_at >= ? OR expired_at IS NULL THEN 1 ELSE 0 END), 0) as active_users', [$now])
            ->selectRaw('COALESCE(SUM(CASE WHEN t >= ? THEN 1 ELSE 0 END), 0) as online_users', [$onlineSince])
            ->selectRaw('COALESCE(SUM(CASE WHEN t >= ? THEN COALESCE(online_count, 0) ELSE 0 END), 0) as online_devices', [$onlineSince])
            ->selectRaw('COALESCE(SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END), 0) as current_month_new_users', [$currentMonthStart, $now])
            ->selectRaw('COALESCE(SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END), 0) as last_month_new_users', [$lastMonthStart, $currentMonthStart])
            ->first();

        return [
            'totalUsers' => (int) ($row->total_users ?? 0),
            'activeUsers' => (int) ($row->active_users ?? 0),
            'onlineUsers' => (int) ($row->online_users ?? 0),
            'onlineDevices' => (int) ($row->online_devices ?? 0),
            'currentMonthNewUsers' => (int) ($row->current_month_new_users ?? 0),
            'lastMonthNewUsers' => (int) ($row->last_month_new_users ?? 0),
        ];
    }

    private function queryDashboardOrderStats(int $now, int $todayStart, int $yesterdayStart, int $currentMonthStart, int $lastMonthStart, int $twoMonthsAgoStart): array
    {
        $paidStatusSql = 'status NOT IN (?, ?)';
        $row = DB::table('v2_order')
            ->where('created_at', '>=', $twoMonthsAgoStart)
            ->selectRaw("COALESCE(SUM(CASE WHEN created_at >= ? AND created_at < ? AND {$paidStatusSql} THEN total_amount ELSE 0 END), 0) as today_income", [
                $todayStart,
                $now,
                Order::STATUS_PENDING,
                Order::STATUS_CANCELLED,
            ])
            ->selectRaw("COALESCE(SUM(CASE WHEN created_at >= ? AND created_at < ? AND {$paidStatusSql} THEN total_amount ELSE 0 END), 0) as yesterday_income", [
                $yesterdayStart,
                $todayStart,
                Order::STATUS_PENDING,
                Order::STATUS_CANCELLED,
            ])
            ->selectRaw("COALESCE(SUM(CASE WHEN created_at >= ? AND created_at < ? AND {$paidStatusSql} THEN total_amount ELSE 0 END), 0) as current_month_income", [
                $currentMonthStart,
                $now,
                Order::STATUS_PENDING,
                Order::STATUS_CANCELLED,
            ])
            ->selectRaw("COALESCE(SUM(CASE WHEN created_at >= ? AND created_at < ? AND {$paidStatusSql} THEN total_amount ELSE 0 END), 0) as last_month_income", [
                $lastMonthStart,
                $currentMonthStart,
                Order::STATUS_PENDING,
                Order::STATUS_CANCELLED,
            ])
            ->selectRaw("COALESCE(SUM(CASE WHEN created_at >= ? AND created_at < ? AND {$paidStatusSql} THEN total_amount ELSE 0 END), 0) as two_months_ago_income", [
                $twoMonthsAgoStart,
                $lastMonthStart,
                Order::STATUS_PENDING,
                Order::STATUS_CANCELLED,
            ])
            ->first();

        return [
            'todayIncome' => (int) ($row->today_income ?? 0),
            'yesterdayIncome' => (int) ($row->yesterday_income ?? 0),
            'currentMonthIncome' => (int) ($row->current_month_income ?? 0),
            'lastMonthIncome' => (int) ($row->last_month_income ?? 0),
            'twoMonthsAgoIncome' => (int) ($row->two_months_ago_income ?? 0),
            'commissionPendingTotal' => $this->queryCommissionPendingOrderCount(),
        ];
    }

    private function queryCommissionPendingOrderCount(): int
    {
        return (int) DB::table('v2_order')
            ->where('commission_status', 0)
            ->whereNotNull('invite_user_id')
            ->where('status', Order::STATUS_COMPLETED)
            ->where('commission_balance', '>', 0)
            ->count();
    }

    private function queryDashboardCommissionStats(int $now, int $currentMonthStart, int $lastMonthStart, int $twoMonthsAgoStart): array
    {
        $row = DB::table('v2_commission_log')
            ->where('created_at', '>=', $twoMonthsAgoStart)
            ->selectRaw('COALESCE(SUM(CASE WHEN created_at >= ? AND created_at < ? THEN get_amount ELSE 0 END), 0) as current_month_commission', [$currentMonthStart, $now])
            ->selectRaw('COALESCE(SUM(CASE WHEN created_at >= ? AND created_at < ? THEN get_amount ELSE 0 END), 0) as last_month_commission', [$lastMonthStart, $currentMonthStart])
            ->selectRaw('COALESCE(SUM(CASE WHEN created_at >= ? AND created_at < ? THEN get_amount ELSE 0 END), 0) as two_months_ago_commission', [$twoMonthsAgoStart, $lastMonthStart])
            ->first();

        return [
            'currentMonthCommissionPayout' => (int) ($row->current_month_commission ?? 0),
            'lastMonthCommissionPayout' => (int) ($row->last_month_commission ?? 0),
            'twoMonthsAgoCommission' => (int) ($row->two_months_ago_commission ?? 0),
        ];
    }

    private function queryDashboardTrafficStats(int $now, int $todayStart, int $currentMonthStart): array
    {
        $retentionStart = strtotime('-2 month', $now);
        $row = DB::table('v2_stat_server')
            ->where('record_type', 'd')
            ->where('record_at', '>=', $retentionStart)
            ->where('record_at', '<', $now)
            ->selectRaw('COALESCE(SUM(CASE WHEN record_at >= ? THEN u ELSE 0 END), 0) as today_upload', [$todayStart])
            ->selectRaw('COALESCE(SUM(CASE WHEN record_at >= ? THEN d ELSE 0 END), 0) as today_download', [$todayStart])
            ->selectRaw('COALESCE(SUM(CASE WHEN record_at >= ? THEN u + d ELSE 0 END), 0) as today_total', [$todayStart])
            ->selectRaw('COALESCE(SUM(CASE WHEN record_at >= ? THEN u ELSE 0 END), 0) as month_upload', [$currentMonthStart])
            ->selectRaw('COALESCE(SUM(CASE WHEN record_at >= ? THEN d ELSE 0 END), 0) as month_download', [$currentMonthStart])
            ->selectRaw('COALESCE(SUM(CASE WHEN record_at >= ? THEN u + d ELSE 0 END), 0) as month_total', [$currentMonthStart])
            ->selectRaw('COALESCE(SUM(u), 0) as total_upload')
            ->selectRaw('COALESCE(SUM(d), 0) as total_download')
            ->selectRaw('COALESCE(SUM(u + d), 0) as total')
            ->first();

        return [
            'todayTraffic' => [
                'upload' => (int) ($row->today_upload ?? 0),
                'download' => (int) ($row->today_download ?? 0),
                'total' => (int) ($row->today_total ?? 0),
            ],
            'monthTraffic' => [
                'upload' => (int) ($row->month_upload ?? 0),
                'download' => (int) ($row->month_download ?? 0),
                'total' => (int) ($row->month_total ?? 0),
            ],
            'totalTraffic' => [
                'upload' => (int) ($row->total_upload ?? 0),
                'download' => (int) ($row->total_download ?? 0),
                'total' => (int) ($row->total ?? 0),
            ],
        ];
    }

    private function countOnlineServers(): int
    {
        $servers = Server::query()->get(['id', 'type', 'parent_id']);
        if ($servers->isEmpty()) {
            return 0;
        }

        $serversById = $servers->keyBy('id');
        $keys = [];
        $ownKeysById = [];
        $parentKeysById = [];

        foreach ($servers as $server) {
            $ownKey = $this->serverRuntimeCacheKey((string) $server->type, 'LAST_CHECK_AT', (int) $server->id);
            $ownKeysById[(int) $server->id] = $ownKey;
            $keys[] = $ownKey;

            $parentId = (int) ($server->parent_id ?? 0);
            if ($parentId <= 0) {
                continue;
            }

            $parentKeys = [];
            $parent = $serversById->get($parentId);
            if ($parent) {
                $parentKeys[] = $this->serverRuntimeCacheKey((string) $parent->type, 'LAST_CHECK_AT', $parentId);
            }
            $parentKeys[] = $this->serverRuntimeCacheKey((string) $server->type, 'LAST_CHECK_AT', $parentId);

            $parentKeysById[(int) $server->id] = array_values(array_unique($parentKeys));
            array_push($keys, ...$parentKeysById[(int) $server->id]);
        }

        $cacheValues = Cache::many(array_values(array_unique($keys)));
        $now = time();
        $online = 0;

        foreach ($servers as $server) {
            $serverId = (int) $server->id;
            if ($this->isOnlineLastCheckAt($cacheValues[$ownKeysById[$serverId]] ?? null, $now)) {
                $online++;
                continue;
            }

            foreach ($parentKeysById[$serverId] ?? [] as $parentKey) {
                if ($this->isOnlineLastCheckAt($cacheValues[$parentKey] ?? null, $now)) {
                    $online++;
                    break;
                }
            }
        }

        return $online;
    }

    private function serverRuntimeCacheKey(string $type, string $name, int $serverId): string
    {
        return CacheKey::get(sprintf('SERVER_%s_%s', strtoupper($type), $name), $serverId);
    }

    private function isOnlineLastCheckAt(mixed $lastCheckAt, int $now): bool
    {
        return $lastCheckAt !== null
            && $lastCheckAt !== false
            && $lastCheckAt !== ''
            && ($now - Server::CHECK_INTERVAL) <= (int) $lastCheckAt;
    }

    private function buildNodeGfwStats(): array
    {
        $latestCheckColumn = function (string $column): callable {
            return function ($query) use ($column): void {
                $query->from('server_gfw_checks as c')
                    ->select("c.{$column}")
                    ->whereColumn('c.server_id', 'v2_server.id')
                    ->whereIn('c.status', ServerGfwCheck::FINAL_STATUSES)
                    ->orderByDesc('c.id')
                    ->limit(1);
            };
        };

        $latestRows = DB::table('v2_server')
            ->where(function ($query): void {
                $query->where('v2_server.gfw_check_enabled', true)
                    ->orWhereNull('v2_server.gfw_check_enabled');
            })
            ->selectRaw('v2_server.id as server_id')
            ->selectRaw('v2_server.type')
            ->selectSub($latestCheckColumn('id'), 'id')
            ->selectSub($latestCheckColumn('status'), 'status')
            ->selectSub($latestCheckColumn('checked_at'), 'checked_at')
            ->selectSub($latestCheckColumn('updated_at'), 'updated_at')
            ->get()
            ->filter(fn ($row): bool => in_array($row->status, ServerGfwCheck::FINAL_STATUSES, true))
            ->values();

        $blockedRows = $latestRows
            ->filter(fn ($row): bool => $row->status === ServerGfwCheck::STATUS_BLOCKED)
            ->values();

        return [
            'blockedNodes' => $blockedRows->count(),
            'recentRecoveredNodes' => $this->countRecentRecoveredGfwNodes($latestRows),
            'recoveryWindowSeconds' => self::GFW_RECENT_RECOVERY_WINDOW_SECONDS,
            'blockedProtocolDistribution' => $this->formatBlockedProtocolDistribution($blockedRows),
        ];
    }

    private function countRecentRecoveredGfwNodes(Collection $latestRows): int
    {
        $since = time() - self::GFW_RECENT_RECOVERY_WINDOW_SECONDS;
        $candidates = $latestRows
            ->filter(function ($row) use ($since): bool {
                return $row->status === ServerGfwCheck::STATUS_NORMAL
                    && $this->resolveGfwCheckTimestamp($row) >= $since;
            })
            ->values();

        if ($candidates->isEmpty()) {
            return 0;
        }

        $latestIds = $candidates
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $serverIds = $candidates
            ->pluck('server_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $previousChecks = ServerGfwCheck::whereIn('server_id', $serverIds)
            ->whereIn('status', ServerGfwCheck::FINAL_STATUSES)
            ->whereNotIn('id', $latestIds)
            ->orderByDesc('id')
            ->get(['id', 'server_id', 'status'])
            ->groupBy('server_id')
            ->map(fn (Collection $checks) => $checks->first());

        return $candidates
            ->filter(function ($row) use ($previousChecks): bool {
                $previous = $previousChecks->get((int) $row->server_id);
                return $previous && $previous->status === ServerGfwCheck::STATUS_BLOCKED;
            })
            ->count();
    }

    private function formatBlockedProtocolDistribution(Collection $blockedRows): array
    {
        $total = $blockedRows->count();
        if ($total <= 0) {
            return [];
        }

        return $blockedRows
            ->groupBy(function ($row): string {
                return Server::normalizeType((string) ($row->type ?? '')) ?: 'unknown';
            })
            ->map(function (Collection $items, string $type) use ($total): array {
                $count = $items->count();
                return [
                    'type' => $type,
                    'count' => $count,
                    'percentage' => round($count / $total * 100, 1),
                ];
            })
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    private function resolveGfwCheckTimestamp($check): int
    {
        $checkedAt = (int) ($check->checked_at ?: 0);
        if ($checkedAt > 0) {
            return $checkedAt;
        }

        $updatedAt = $check->updated_at ?? null;
        if ($updatedAt instanceof \DateTimeInterface) {
            return $updatedAt->getTimestamp();
        }

        return (int) ($updatedAt ?: 0);
    }

    /**
     * Resolve the comparison window used by traffic rank change calculation.
     *
     * Daily traffic statistics are stored with `record_at` pinned to the start
     * of the day. For the dashboard `24h` preset, comparing by "same span in
     * seconds" would shift the previous window to `00:00:01`, which skips the
     * whole yesterday row and makes change percentages fall back to `0`.
     *
     * To keep the requested minimal scope, only the single-day preset is
     * aligned to the exact previous calendar day; longer ranges keep the
     * existing equal-span comparison behavior.
     *
     * @param int $startDate
     * @param int $endDate
     * @return array{start: int, end: int}
     */
    protected function resolveTrafficRankComparisonWindow(int $startDate, int $endDate): array
    {
        $currentWindowDays = (int) floor(max(0, $endDate - $startDate) / 86400) + 1;

        if ($currentWindowDays === 1) {
            return [
                'start' => $startDate - 86400,
                'end' => $startDate,
            ];
        }

        return [
            'start' => $startDate - ($endDate - $startDate),
            'end' => $startDate,
        ];
    }

    /**
     * Get traffic ranking data for nodes or users
     * 
     * @param Request $request
     * @return array
     */
    public function getTrafficRank(Request $request)
    {
        $request->validate([
            'type' => 'required|in:node,user',
            'start_time' => 'nullable|integer|min:1000000000|max:9999999999',
            'end_time' => 'nullable|integer|min:1000000000|max:9999999999',
            'limit' => 'nullable|integer|in:10,20'
        ]);

        $type = (string) $request->input('type');
        $startDate = (int) $request->input('start_time', strtotime('-7 days'));
        $endDate = (int) $request->input('end_time', time());
        $limit = (int) $request->input('limit', 10);
        $comparisonWindow = $this->resolveTrafficRankComparisonWindow($startDate, $endDate);
        $previousStartDate = $comparisonWindow['start'];
        $previousEndDate = $comparisonWindow['end'];
        $cacheKey = CacheKey::get('ADMIN_DASHBOARD_TRAFFIC_RANK', sha1(implode(':', [
            $type,
            $startDate,
            $endDate,
            $previousStartDate,
            $previousEndDate,
            $limit,
        ])));

        return Cache::remember($cacheKey, self::TRAFFIC_RANK_CACHE_TTL_SECONDS, function () use (
            $type,
            $startDate,
            $endDate,
            $previousStartDate,
            $previousEndDate,
            $limit
        ): array {
            return $this->buildTrafficRank($type, $startDate, $endDate, $previousStartDate, $previousEndDate, $limit);
        });
    }

    private function buildTrafficRank(string $type, int $startDate, int $endDate, int $previousStartDate, int $previousEndDate, int $limit): array
    {
        $isNodeRank = $type === 'node';
        $table = $isNodeRank ? 'v2_stat_server' : 'v2_stat_user';
        $nameTable = $isNodeRank ? 'v2_server' : 'v2_user';
        $idColumn = $isNodeRank ? 's.server_id' : 's.user_id';
        $nameColumn = $isNodeRank ? 'm.name' : 'm.email';
        $fallbackName = $isNodeRank ? 'Node' : 'User';
        $currentValueSql = 'COALESCE(SUM(CASE WHEN s.record_at >= ? AND s.record_at <= ? THEN s.u + s.d ELSE 0 END), 0)';
        $previousValueSql = 'COALESCE(SUM(CASE WHEN s.record_at >= ? AND s.record_at < ? THEN s.u + s.d ELSE 0 END), 0)';

        $rows = DB::table("{$table} as s")
            ->leftJoin("{$nameTable} as m", 'm.id', '=', $idColumn)
            ->where('s.record_type', 'd')
            ->where('s.record_at', '>=', min($startDate, $previousStartDate))
            ->where('s.record_at', '<=', max($endDate, $previousEndDate - 1))
            ->selectRaw("{$idColumn} as id")
            ->selectRaw("{$nameColumn} as name")
            ->selectRaw("{$currentValueSql} as value", [$startDate, $endDate])
            ->selectRaw("{$previousValueSql} as previous_value", [$previousStartDate, $previousEndDate])
            ->groupBy($idColumn, $nameColumn)
            ->havingRaw("{$currentValueSql} > 0", [$startDate, $endDate])
            ->orderByDesc('value')
            ->limit($limit)
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $value = (int) ($row->value ?? 0);
            $previousValue = (int) ($row->previous_value ?? 0);
            $change = $previousValue > 0 ? round(($value - $previousValue) / $previousValue * 100, 1) : 0;

            $result[] = [
                'id' => (string) $row->id,
                'name' => $row->name ?: "{$fallbackName} {$row->id}",
                'value' => $value,
                'previousValue' => $previousValue,
                'change' => $change,
                'timestamp' => date('c', $endDate)
            ];
        }

        return [
            'timestamp' => date('c'),
            'data' => $result
        ];
    }
}
