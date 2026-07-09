# node-auto-online

## 职责

- 维护节点自动上线配置与自动显隐同步规则
- 根据节点在线状态、墙检测状态和重连冷却状态决定 `v2_server.show`
- 保持父节点与子节点的自动显隐相互独立，避免转发入口子节点受父节点直连状态影响
- 为管理端节点编辑表单和快捷更新接口提供字段契约

## 行为规范

- `v2_server.auto_online` 控制节点是否由后台托管前台显示状态；未开启时节点继续保持手动显隐控制。
- `ServerAutoOnlineService` 是自动上线唯一同步入口，只处理 `auto_online=true` 的节点；`sync:server-auto-online` 每 5 分钟兜底同步，管理端保存 / 快捷更新、REST 心跳和 WebSocket 状态上报会触发单节点即时同步。
- 自动上线写入 `show` 时使用当前节点的 `available_status` 判断有效运行状态；普通节点只读取自身运行缓存，转发子节点在自身缓存缺失或过期时可回退父节点运行缓存，避免只由父入口上报的转发子节点被定时同步误隐藏。
- `Server` 模型的 `last_check_at`、`last_push_at`、在线用户、metrics 和负载状态访问器优先按当前节点 ID 读取缓存；子节点自身运行缓存缺失或过期时回退读取父节点运行缓存，父子协议类型不一致时优先使用父节点真实 type，再兼容旧的子节点 type + parent_id 缓存键。
- 墙检测状态参与当前节点的显示否决：最新墙状态为 `blocked`，或节点仍处于 `gfw_auto_hidden=true` 且最新墙状态未恢复 `normal` 时，自动上线不得重新显示当前节点。
- 墙检测上报 `normal` 时只解除 `gfw_auto_hidden`；若节点开启了 `auto_online`，最终 `show` 必须再走 `ServerAutoOnlineService` 按运行状态重算，禁止因墙状态恢复直接强制显示离线节点。
- `v2_server.auto_online_cooldown_enabled` 是自动上线的节点级重连冷却开关；只有同时开启 `auto_online` 和该开关时才生效。
- `ServerReconnectCooldownService` 使用 Cache/Redis 记录运行时状态：首次状态只记录不计数；后续在线 / 离线状态切换会写入 1 小时窗口内的切换时间戳。
- 同一节点 1 小时内在线 / 离线切换次数超过 10 次时，`ServerReconnectCooldownService` 写入 6 小时冷却键；冷却期间 `ServerAutoOnlineService` 仅强制当前节点 `show=false`，不改写其子节点。
- 子节点 `available_status`、在线用户、metrics 和负载状态可使用父入口运行缓存兜底，避免管理端展示和自动上线把仅由父入口上报的转发入口判定为离线；墙检测否决、重连冷却和 `show` 写入仍只作用于当前子节点，不恢复父节点对子节点的批量显隐联动。
- 关闭自动上线或关闭重连冷却时，管理端后端接口会把 `auto_online_cooldown_enabled` 归零并清理该节点已有冷却缓存，避免后续重新开启时被旧缓存影响。
- 重连冷却状态是短期保护策略，不做数据库审计；Redis 重启后冷却状态可能消失。

## 依赖关系

- 依赖 `app/Services/ServerAutoOnlineService.php` 统一执行自动上线同步
- 依赖 `app/Models/Server.php` 提供当前节点优先、父节点兜底的运行状态访问器
- 依赖 `app/Services/ServerReconnectCooldownService.php` 记录连断窗口和冷却状态
- 依赖 `app/Services/ServerParentVisibilityService.php` 清理历史 `parent_auto_hidden` 标记，避免手动显隐后仍保留旧联动状态
- 依赖 `app/Services/ServerService.php` 在 REST / WebSocket 状态上报时触发单节点同步
- 依赖 `app/Console/Commands/SyncServerAutoOnline.php` 与 Laravel Scheduler 执行兜底同步
- 依赖 `app/Http/Controllers/V2/Admin/Server/ManageController.php` 和 `app/Http/Requests/Admin/ServerSave.php` 接收管理端配置
- 依赖管理端 `admin-frontend/src/utils/nodeEditor*` 与 `admin-frontend/src/views/nodes/NodeEditorDialog.vue` 提供节点级配置入口
