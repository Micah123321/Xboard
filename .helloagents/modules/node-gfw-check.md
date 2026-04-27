# node-gfw-check

## 职责

- 在 Xboard 后端维护节点墙状态检测任务和检测结果
- 通过管理端接口触发父节点墙状态检测，并通过节点端接口提供 REST 兜底领取与结果上报
- 通过 `NodeSyncService` 发送 `gfw.check` WS 事件，让在线 mi-node 可即时执行检测
- 在节点列表返回 `gfw_check` 字段，并对子节点应用父节点检测结果继承规则

## 行为规范

- 检测任务只对父节点创建；带 `parent_id` 的子节点不单独下发任务
- 子节点列表展示继承父节点最新 `gfw_check`，并返回 `inherited=true` 与 `source_node_id`
- `server_gfw_checks.status` 使用 `pending / checking / normal / blocked / partial / failed / skipped`
- 管理端 `POST server/manage/checkGfw` 接收 `{ ids: number[] }`，响应中区分 `started` 与 `skipped`
- 后端定时命令 `sync:server-gfw-checks` 会自动为 `gfw_check_enabled=1` 的父节点创建检测任务；已有未超时的 `pending/checking` 任务时跳过，超过 5 分钟未领取或未上报的任务会自动标记为 `failed`
- 节点端 `GET server/gfw/task` 只向父节点返回待执行任务；节点端 `POST server/gfw/report` 必须校验 `check_id` 归属当前节点
- `v2_server.gfw_check_enabled` 控制节点是否参与自动墙检测与墙状态自动显隐；父节点开启时会自动创建检测任务，子节点不独立检测但可单独关闭随父节点自动隐藏 / 恢复
- `blocked` 结果会自动隐藏仍开启墙检测托管且当前显示中的父节点及其子节点，并设置 `gfw_auto_hidden=1`
- `normal` 结果只恢复 `gfw_auto_hidden=1` 的节点，避免误恢复管理员手动隐藏的节点；`partial/failed` 只记录状态，不触发自动上线或下线
- `sync:server-auto-online` 会把最新墙状态 `blocked` 和未恢复的 `gfw_auto_hidden` 作为显示否决条件，防止自动上线重新发布疑似被墙节点
- 当前检测方向只做节点服务器主动 ping 国内三网目标；后续墙内探测 IP 可在同一任务模型中扩展
- 参考脚本中的 Telegram 通知、chat_id、bot token 和自动安装依赖逻辑不得进入项目实现
- mi-node 使用 Go 原生 runner 调用系统 `ping`，按三网目标并发检测并结构化上报 `summary / operator_summary / raw_result`；ping 命令成功即视为目标可达，平均延迟解析失败不再把正常可达误判为超时
- Docker runtime 镜像需要提供 `ping`，当前通过 Alpine `iputils` 满足

## 依赖关系

- 依赖 `app/Services/ServerGfwCheckService.php` 统一处理任务创建、状态判定、结果装饰和子节点继承
- 依赖 `app/Models/ServerGfwCheck.php` 与 `server_gfw_checks` 表保存检测记录
- 依赖 `app/Http/Controllers/V2/Admin/Server/ManageController.php` 暴露管理端触发接口
- 依赖 `app/Http/Controllers/V2/Server/ServerController.php` 暴露节点端任务领取和上报接口
- 依赖 `app/Services/NodeSyncService.php` 与 Workerman WS 通道向在线节点推送 `gfw.check`
- 依赖 `app/Console/Commands/SyncServerGfwChecks.php` 与 Laravel Scheduler 自动创建检测任务
- 依赖 `app/Services/ServerAutoOnlineService.php` 在自动上线同步时尊重墙状态否决
- 依赖 `E:/code/go/mi-node/internal/gfwcheck` 执行 ping 检测和结果判定
- 依赖 `E:/code/go/mi-node/internal/panel`、`internal/controlplane` 与 `internal/service` 接收任务、轮询兜底并上报结果
