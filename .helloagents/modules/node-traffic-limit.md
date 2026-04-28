# node-traffic-limit

## 职责

- 保存节点级月流量限额配置、重置规则和运行状态
- 将限额配置下发给 `mi-node`，由节点端执行真实内核停启
- 在手动重置、定时重置和节点 metrics 回传时同步面板侧状态

## 行为规范

- `v2_server.transfer_enable` 是节点月流量额度，单位为字节；新增字段只负责启用状态、重置日、重置时间、时区和运行状态
- `traffic_limit_enabled=false` 或 `transfer_enable<=0` 时不启用节点限额，`ServerTrafficLimitService::buildNodeConfig()` 仍会下发 disabled 配置，旧行为保持不变
- 重置日支持 `1-31`，短月按当月最后一天计算；重置时间使用 `HH:mm`，时区优先使用节点字段，空值或非法值回退 `config('app.timezone')`
- 管理端保存节点后调用 `ServerTrafficLimitService::refreshSchedule()` 计算 `traffic_limit_next_reset_at`，并通过 `NodeSyncService::notifyConfigUpdated()` 通知节点更新配置
- 手动重置和定时重置统一走 `ServerTrafficLimitService::resetServer()`：清空节点 `u/d`，恢复 `traffic_limit_status=normal`，记录 `traffic_limit_last_reset_at`，计算下一次重置时间，并触发 `notifyFullSync()`
- `sync:server-traffic-limits` 每分钟扫描到期且启用限额的节点，只处理 `traffic_limit_next_reset_at <= now()` 的记录，不影响未启用限额的节点
- `ServerService::updateMetrics()` 会缓存 `metrics.traffic_limit` 并把节点端 `suspended / last_reset_at / next_reset_at / suspended_at` 写回 `v2_server`
- 限额下线不修改 `show`、`auto_online` 或墙检测字段；真实下线由 `mi-node` 调用内核 `Stop()` 完成

## 依赖关系

- 依赖 `app/Services/ServerTrafficLimitService.php` 统一处理配置下发、时间计算、状态回写和重置
- 依赖 `app/Services/ServerService.php` 在节点配置中追加 `traffic_limit` 并消费节点 metrics
- 依赖 `app/Observers/ServerObserver.php` 在限额配置变化时推送 `sync.config`
- 依赖 `app/Console/Commands/SyncServerTrafficLimits.php` 与 Laravel Scheduler 执行到期重置
- 依赖管理端 `admin-frontend/src/utils/nodeEditor*`、`admin-frontend/src/utils/nodes.ts` 和 `admin-frontend/src/views/nodes/*` 提供配置与展示入口
- 依赖 `E:/code/go/mi-node/internal/trafficlimit` 和 `internal/service` 执行本地强制下线、重置恢复与状态持久化
