# node-traffic-limit

## 职责

- 保存节点级月流量限额配置、重置规则和运行状态
- 将限额配置下发给 `mi-node`，由节点端执行真实内核停启
- 在手动重置、定时重置和节点 metrics 回传时同步面板侧状态
- 为管理端和 mi-node 下发提供共享月额度快照，统一计算同机器 / 同 host 节点的当前账期已用流量

## 行为规范

- `v2_server.transfer_enable` 是节点月流量额度配置，单位为字节；新增字段只负责启用状态、重置日、重置时间、时区和运行状态
- `traffic_limit_enabled=false` 或 `transfer_enable<=0` 时不启用节点限额，`ServerTrafficLimitService::buildNodeConfig()` 仍会下发 disabled 配置，旧行为保持不变
- 重置日支持 `1-31`，短月按当月最后一天计算；重置时间使用 `HH:mm`，时区优先使用节点字段，空值或非法值回退 `config('app.timezone')`
- 月额度使用量按共享范围计算：优先按 `machine_id` 聚合，未绑定机器时按规范化后的 `host` 聚合，空 host 回退单节点范围；同一范围内的节点在管理端快照中返回相同 `used / scope_key / scope_node_ids`
- 共享月额度按当前账期统计：`ServerTrafficLimitService::calculateCurrentCycleStartAt()` 会取当前时间之前最近一次重置边界，`current_used` 优先聚合 `v2_stat_server.record_type='d'` 在 `[cycle_start_at, now]` 日粒度窗口内的 `u+d`
- 当前账期没有统计行时回退共享范围内 `v2_server.u + v2_server.d`；同范围任一节点有当前有效的 mi-node runtime metrics 时，快照会取 metrics `used` 的最大值并保留同限额下的 `suspended` 运行态
- `ServerTrafficLimitService::buildNodeConfig()` 下发给 mi-node 的 `traffic_limit.current_used` 使用共享账期口径，不再只使用当前单节点的 `u+d`
- `ServerTrafficLimitService::buildSnapshotsForServers()` 为管理端节点列表批量生成 `traffic_limit_snapshot`，避免前端自行按 IP 猜测共享规则
- 管理端保存节点后调用 `ServerTrafficLimitService::refreshSchedule()` 计算 `traffic_limit_next_reset_at`，并通过 `NodeSyncService::notifyConfigUpdated()` 通知节点更新配置
- 手动重置和定时重置统一走 `ServerTrafficLimitService::resetServer()`：清空当前节点 `u/d`，恢复 `traffic_limit_status=normal`，记录 `traffic_limit_last_reset_at`，计算下一次重置时间，并触发 `notifyFullSync()`；该接口不批量重置同共享范围的其他节点
- `sync:server-traffic-limits` 每分钟扫描到期且启用限额的节点，只处理 `traffic_limit_next_reset_at <= now()` 的记录，不影响未启用限额的节点
- `ServerService::updateMetrics()` 会缓存 `metrics.traffic_limit` 并把节点端 `suspended / last_reset_at / next_reset_at / suspended_at` 写回 `v2_server`
- 限额下线不修改父节点自身的 `show`、`auto_online` 或墙检测字段；真实下线由 `mi-node` 调用内核 `Stop()` 完成
- 父节点限额状态变为 `suspended` 时会通过 `ServerParentVisibilityService` 自动隐藏当时仍显示的直接子节点，并写入 `parent_auto_hidden=1`；限额重置或恢复 `normal` 后只恢复这些由父节点联动自动隐藏的子节点，原本手动隐藏的子节点保持隐藏

## 依赖关系

- 依赖 `app/Services/ServerTrafficLimitService.php` 统一处理配置下发、时间计算、状态回写和重置
- 依赖 `app/Services/ServerParentVisibilityService.php` 在父节点限额下线 / 恢复时同步直接子节点显隐
- 依赖 `app/Services/ServerService.php` 在节点配置中追加 `traffic_limit` 并消费节点 metrics
- 依赖 `app/Http/Controllers/V2/Admin/Server/ManageController.php` 在 `server/manage/getNodes` 响应中返回 `traffic_limit_snapshot`
- 依赖 `v2_stat_server` 的日统计记录作为当前账期共享已用流量的主要来源
- 依赖 `app/Observers/ServerObserver.php` 在限额配置变化时推送 `sync.config`
- 依赖 `app/Console/Commands/SyncServerTrafficLimits.php` 与 Laravel Scheduler 执行到期重置
- 依赖管理端 `admin-frontend/src/utils/nodeEditor*`、`admin-frontend/src/utils/nodes.ts` 和 `admin-frontend/src/views/nodes/*` 提供配置与展示入口
- 依赖 `E:/code/go/mi-node/internal/trafficlimit` 和 `internal/service` 执行本地强制下线、重置恢复与状态持久化
