# node-auto-online

## 职责

- 维护节点自动上线配置与自动显隐同步规则
- 根据节点在线状态、墙检测状态和重连冷却状态决定 `v2_server.show`
- 为父节点自动隐藏 / 恢复直接子节点提供统一入口
- 为管理端节点编辑表单和快捷更新接口提供字段契约

## 行为规范

- `v2_server.auto_online` 控制节点是否由后台托管前台显示状态；未开启时节点继续保持手动显隐控制。
- `ServerAutoOnlineService` 是自动上线唯一同步入口，只处理 `auto_online=true` 的节点；`sync:server-auto-online` 每 5 分钟兜底同步，管理端保存 / 快捷更新、REST 心跳和 WebSocket 状态上报会触发单节点即时同步。
- 自动上线正常情况下按 `available_status` 判断：在线或待同步时可显示，离线时隐藏。
- 墙检测状态参与显示否决：最新墙状态为 `blocked`，或节点仍处于 `gfw_auto_hidden=true` 且最新墙状态未恢复 `normal` 时，自动上线不得重新显示节点。
- `v2_server.auto_online_cooldown_enabled` 是自动上线的节点级重连冷却开关；只有同时开启 `auto_online` 和该开关时才生效。
- `ServerReconnectCooldownService` 使用 Cache/Redis 记录运行时状态：首次状态只记录不计数；后续在线 / 离线状态切换会写入 1 小时窗口内的切换时间戳。
- 同一节点 1 小时内在线 / 离线切换次数超过 10 次时，`ServerReconnectCooldownService` 写入 6 小时冷却键；冷却期间 `ServerAutoOnlineService` 强制 `show=false`，并继续复用父子联动隐藏逻辑。
- 关闭自动上线或关闭重连冷却时，管理端后端接口会把 `auto_online_cooldown_enabled` 归零并清理该节点已有冷却缓存，避免后续重新开启时被旧缓存影响。
- 重连冷却状态是短期保护策略，不做数据库审计；Redis 重启后冷却状态可能消失。

## 依赖关系

- 依赖 `app/Services/ServerAutoOnlineService.php` 统一执行自动上线同步
- 依赖 `app/Services/ServerReconnectCooldownService.php` 记录连断窗口和冷却状态
- 依赖 `app/Services/ServerParentVisibilityService.php` 在父节点隐藏 / 恢复时同步直接子节点显隐
- 依赖 `app/Services/ServerService.php` 在 REST / WebSocket 状态上报时触发单节点同步
- 依赖 `app/Console/Commands/SyncServerAutoOnline.php` 与 Laravel Scheduler 执行兜底同步
- 依赖 `app/Http/Controllers/V2/Admin/Server/ManageController.php` 和 `app/Http/Requests/Admin/ServerSave.php` 接收管理端配置
- 依赖管理端 `admin-frontend/src/utils/nodeEditor*` 与 `admin-frontend/src/views/nodes/NodeEditorDialog.vue` 提供节点级配置入口
