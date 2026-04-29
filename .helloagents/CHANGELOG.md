# CHANGELOG

## [0.6.23] - 2026-04-29

### 新增
- **[user-frontend-access]**: 新增用户前端访问开关；后台站点设置可切换 `frontend_enable`，关闭后 `/`、订阅入口和用户侧 API 返回空 404，不渲染站点标题或用户主题内容，同时保留节点 API 与管理后台原有访问边界 — by yinjianm
  - 方案: [202604291559_user-frontend-access-toggle](archive/2026-04/202604291559_user-frontend-access-toggle/)
  - 决策: user-frontend-access-toggle#D001(使用路由级中间件控制用户入口)

## [0.6.22] - 2026-04-29

### 修复
- **[node-traffic-limit]**: 修复父节点自动下线后子节点仍可能保持上线的问题；新增 `parent_auto_hidden` 标记和父节点显隐联动服务，自动上线离线、流量限额 suspended 会隐藏当时仍显示的直接子节点，自动恢复或限额重置后只恢复这批由联动逻辑隐藏的子节点，手动隐藏的子节点不被误上线 — by yinjianm
  - 方案: [202604290153_parent-node-auto-visibility](archive/2026-04/202604290153_parent-node-auto-visibility/)
  - 决策: parent-node-auto-visibility#D001(使用独立父级自动隐藏标记)

## [0.6.21] - 2026-04-29

### 修复
- **[node-traffic-limit]**: 修正节点管理月额度使用量口径；同 `machine_id` 或同 host 节点现在共享当前账期用量，`server/manage/getNodes` 返回 `traffic_limit_snapshot`，mi-node 下发的 `traffic_limit.current_used` 也改为共享账期统计，管理端优先显示快照并保留旧 metrics / `u+d` 回退 — by yinjianm
  - 方案: [202604290132_shared-node-traffic-limit](archive/2026-04/202604290132_shared-node-traffic-limit/)
  - 决策: shared-node-traffic-limit#D001(共享范围优先 machine_id，兜底 host)

## [0.6.20] - 2026-04-29

### 新增
- **[admin-frontend]**: 节点流量详情卡新增“昨日”统计；`server/manage/getNodes` 现在返回 `traffic_stats.today/yesterday/month/total`，今日、昨日和本月均使用半开时间窗口聚合，便于对比“今日下行多、本月上行多”的流量分布来源 — by yinjianm
  - 方案: [202604290123_node-traffic-yesterday-stats](archive/2026-04/202604290123_node-traffic-yesterday-stats/)
  - 决策: node-traffic-yesterday-stats#D001(保持 u/d 语义并新增后端 yesterday 字段)

## [0.6.19] - 2026-04-29

### 快速修改
- **[node-traffic-limit]**: 修复节点提高月流量额度后管理端仍显示“已限额”的问题；保存配置、缓存 metrics 回写和节点下发配置现在都会按当前已用流量与新额度重新计算 suspended 状态，旧额度产生的 stale metrics 不会再把节点重新标记为限额下线 — by yinjianm
  - 类型: 快速修改（无方案包）
  - 文件: app/Services/ServerTrafficLimitService.php:16-320, app/Services/ServerService.php:247-252, tests/Unit/ServerTrafficLimitServiceTest.php:60-148, E:/code/go/mi-node/internal/trafficlimit/manager_test.go:120-157

## [0.6.18] - 2026-04-28

### 新增
- **[node-traffic-limit]**: 新增节点月流量限额强制下线能力；Xboard 可为单节点配置月额度、重置日、重置时间和时区，下发 `traffic_limit` 给 mi-node，并在手动/定时重置和 metrics 回传时同步限额状态；管理端节点编辑与流量浮层同步展示限额配置、用量、状态和下次重置 — by yinjianm
  - 方案: [202604281921_node-traffic-limit-enforcement](archive/2026-04/202604281921_node-traffic-limit-enforcement/)
  - 决策: node-traffic-limit-enforcement#D001(由 mi-node 本地强制节点下线), node-traffic-limit-enforcement#D002(复用 `transfer_enable` 作为节点月额度)

## [0.6.17] - 2026-04-28

### 快速修改
- **[ci-workflows]**: 修复后端 Docker 构建在 `composer dump-autoload` 阶段因旧锁文件中的 `laravel/reverb` provider 自动发现而失败的问题；项目未启用 Reverb，Composer package discovery 现在会跳过 `laravel/reverb` — by yinjianm
  - 类型: 快速修改（无方案包）
  - 文件: composer.json:53-60

## [0.6.16] - 2026-04-28

### 快速修改
- **[ci-workflows]**: 优化后端 Docker 构建缓存命中；`composer.lock` 现在进入镜像构建上下文，Composer 依赖安装提前到源码复制前并使用 BuildKit 缓存挂载，构建期不再重复执行全量 `chown/chmod` — by yinjianm
  - 类型: 快速修改（无方案包）
  - 文件: Dockerfile:1-44, .dockerignore:18-22

## [0.6.15] - 2026-04-28

### 快速修改
- **[admin-frontend]**: 修正节点 hover 流量详情的累计统计口径，今日、本月、累计现在全部从 `v2_stat_server` 按节点聚合，避免节点当前累计字段被重置后出现“本月大于累计”的展示错误 — by yinjianm
  - 类型: 快速修改（无方案包）
  - 文件: app/Http/Controllers/V2/Admin/Server/ManageController.php:34-66, .helloagents/modules/admin-frontend.md:49

## [0.6.14] - 2026-04-28

### 修复
- **[admin-frontend]**: 修复复制节点后在自动上线开启状态下不会立即显示的问题；自动上线同步现在可针对单节点执行，管理端保存 / 开启自动上线、REST 心跳和 WebSocket 状态上报都会立即按在线与墙状态同步 `show`，`sync:server-auto-online` 继续作为定时兜底 — by yinjianm
  - 方案: [202604281632_admin-frontend-node-auto-online-immediate-sync](archive/2026-04/202604281632_admin-frontend-node-auto-online-immediate-sync/)

## [0.6.13] - 2026-04-28

### 新增
- **[admin-frontend]**: 为节点管理页补齐节点名称 hover 流量详情；`server/manage/getNodes` 现在返回节点级 `traffic_stats.today/month/total`，前端展示今日、本月、累计的上行、下行和合计流量 — by yinjianm
  - 方案: [202604281625_admin-frontend-node-traffic-hover](archive/2026-04/202604281625_admin-frontend-node-traffic-hover/)
  - 决策: admin-frontend-node-traffic-hover#D001(在 getNodes 聚合节点流量而不是 hover 拉取)

## [0.6.12] - 2026-04-28

### 快速修改
- **[admin-frontend]**: 为节点管理页搜索过滤增加显隐条件，可按全部、显示中、已隐藏筛选节点，并同步重置与分页刷新逻辑 — by yinjianm
  - 类型: 快速修改（无方案包）
  - 文件: admin-frontend/src/utils/nodes.ts, admin-frontend/src/views/nodes/NodesView.vue

## [0.6.11] - 2026-04-28

### 快速修改
- **[ci-workflows]**: 优化管理端前端 Docker 发布耗时，默认只构建 `linux/amd64`，移除 QEMU/ARM64 跨架构构建，并将 BuildKit GHA 缓存导出收敛为 `mode=min` — by yinjianm
  - 类型: 快速修改（无方案包）
  - 文件: .github/workflows/admin-frontend-docker-publish.yml:34-82, .helloagents/modules/ci-workflows.md:14-15

## [0.6.10] - 2026-04-28

### 修复
- **[node-gfw-check]**: 修复 `parent_id=0` 的父节点在管理端显示已开启墙检测托管、但不会被自动墙检任务入队而长期显示“未检测”的问题；自动墙检现在同时兼容 `parent_id IS NULL` 与 `parent_id=0`，并把未明确关闭的 `gfw_check_enabled` 视为开启，管理端自动墙检统计也改为只统计父节点 — by yinjianm
  - 方案: [202604281441_fix-admin-node-gfw-null-enabled](archive/2026-04/202604281441_fix-admin-node-gfw-null-enabled/)
  - 决策: fix-admin-node-gfw-null-enabled#D001(自动墙检查询对齐项目父节点与启用语义)

## [0.6.9] - 2026-04-28

### 修复
- **[ci-workflows]**: 修复仅修改 `admin-frontend/**` 且附带 `.helloagents/**` 知识库记录时仍误触发后端 Docker 发布的问题；后端 workflow 现在会忽略 `.helloagents/**`，但混有后端相关文件时仍会正常运行 — by yinjianm
  - 方案: [202604281432_ci-ignore-helloagents-for-backend-docker](archive/2026-04/202604281432_ci-ignore-helloagents-for-backend-docker/)
  - 决策: ci-ignore-helloagents-for-backend-docker#D001(后端 Docker workflow 忽略 HelloAGENTS 知识库路径)

## [0.6.8] - 2026-04-28

### 快速修改
- **[admin-frontend]**: 精简节点管理页批量操作与工作台说明文案，并在表格底部新增基于 `sync:server-gfw-checks` 30 分钟调度节奏估算的下次自动墙检倒计时提示 — by yinjianm
  - 类型: 快速修改（无方案包）
  - 文件: admin-frontend/src/views/nodes/NodesView.vue

## [0.6.7] - 2026-04-28

### 快速修改
- **[deploy]**: 调整 `deploy/xboard-server` 更新脚本，改为拉取镜像后通过一次性 `web` 容器执行 `php artisan xboard:update`，再重新 `up -d` 拉起服务；README 同步更新后续升级命令 — by yinjianm
  - 类型: 快速修改（无方案包）
  - 文件: deploy/xboard-server/scripts/update.sh, deploy/xboard-server/README.md

## [0.6.6] - 2026-04-28

### 新增
- **[deploy]**: 新增 `deploy/xboard-server` 可复用服务器部署模板，基于生产 compose 拓扑补齐 `scheduler` 服务，并提供 `.env.example`、初始化/部署/更新/状态检查脚本和部署说明 — by yinjianm
  - 方案: [202604281303_xboard-reusable-server-deploy](archive/2026-04/202604281303_xboard-reusable-server-deploy/)
  - 决策: xboard-reusable-server-deploy#D001(使用独立 scheduler 服务驱动 Laravel Scheduler), xboard-reusable-server-deploy#D002(默认不把 MySQL 纳入一键模板)

## [0.6.5] - 2026-04-28

### 修复
- **[queue-mail]**: 修复 `SendEmailJob` 10 秒超时导致 `send_email` 队列邮件作业批量失败的问题；邮件 job 现在使用 60 秒超时、明确 backoff、timeout 失败直接 fail，并把邮件发送错误交给队列异常机制处理。同时新增 `MAIL_TIMEOUT` / `QUEUE_RETRY_AFTER` 配置、刷新 Horizon 长驻 worker 的运行时 mailer 配置，并对 `MailLog.config` 中的敏感字段脱敏 — by yinjianm
  - 方案: [202604281258_fix-send-email-job-timeout](archive/2026-04/202604281258_fix-send-email-job-timeout/)
  - 决策: fix-send-email-job-timeout#D001(保留队列结构并修复 job 与 mail transport 超时)

## [0.6.4] - 2026-04-28

### 修复
- **[node-gfw-check]**: 修复墙检测任务卡在 `pending/checking` 后会长期占用 active 状态的问题；超过 5 分钟未被节点端领取或未上报的任务会标记为检测失败，管理端区分展示“等待节点领取”和“检测中”，并在开启父节点墙检测托管时立即发起一次检测。同时补齐 Docker/supervisor 的 `schedule:work` 进程和 compose scheduler 样例，确保自动墙检测调度会持续运行；修正 mi-node 的 ping 成功判定，避免正常可达但平均延迟解析不到时被误判为超时 — by yinjianm
  - 类型: 快速修改（无方案包）
  - 文件: app/Services/ServerGfwCheckService.php, app/Console/Commands/SyncServerGfwChecks.php, admin-frontend/src/utils/nodes.ts, admin-frontend/src/views/nodes/NodesView.vue, .docker/supervisor/supervisord.conf, .docker/entrypoint.sh, Dockerfile, compose.sample.yaml, E:/code/go/mi-node/internal/gfwcheck/gfwcheck.go

## [0.6.3] - 2026-04-28

### 新增
- **[node-gfw-check]**: 为节点墙状态检测打通自动检测与自动显隐；`sync:server-gfw-checks` 会自动为开启托管的父节点创建检测任务，`blocked` 时自动隐藏节点并阻止自动上线重新发布，`normal` 时只恢复由墙检测自动隐藏的节点；管理端节点页新增刷新数据、墙检测托管开关和批量设置入口 — by yinjianm
  - 方案: [202604280024_node-gfw-auto-check-and-online](archive/2026-04/202604280024_node-gfw-auto-check-and-online/)
  - 决策: node-gfw-auto-check-and-online#D001(使用自动隐藏标记隔离管理员手动显隐), node-gfw-auto-check-and-online#D002(自动上线服务必须把 blocked 作为显示否决)

## [0.6.2] - 2026-04-27

### 新增
- **[admin-frontend]**: 为节点管理新增可控“自动上线”能力；节点可单独或批量开启后台托管，`sync:server-auto-online` 会按在线状态自动同步前台显示，在线 / 待同步时显示，离线时隐藏，未开启自动上线的节点继续保持手动显隐控制 — by yinjianm
  - 方案: [202604272338_admin-frontend-node-auto-online](archive/2026-04/202604272338_admin-frontend-node-auto-online/)
  - 决策: admin-frontend-node-auto-online#D001(自动上线使用独立字段与独立同步服务)

## [0.6.1] - 2026-04-27

### 快速修改
- **[admin-frontend]**: 修复独立 admin 前端容器内 `/upload/rest/upload` 返回 404 的问题；`Caddyfile` 现在会把 `/upload/*` 去掉 `/upload` 前缀后反向代理到 `XBOARD_UPLOAD_UPSTREAM`，默认对齐开发环境的图片上传服务 — by yinjianm
  - 类型: 快速修改（无方案包）
  - 文件: admin-frontend/Caddyfile:1-28

## [0.6.0] - 2026-04-27

### 新增
- **[node-gfw-check]**: 新增节点墙状态检测闭环，管理端可对父节点发起检测并在节点列表展示、搜索和筛选正常 / 疑似被墙 / 部分异常 / 检测失败 / 未检测状态；子节点不单独检测并继承父节点状态，mi-node 支持 `gfw.check` WS 触发、REST 兜底领取和三网 ping 结果上报 — by yinjianm
  - 方案: [202604272325_node-gfw-check](archive/2026-04/202604272325_node-gfw-check/)
  - 决策: node-gfw-check#D001(使用 WS 触发 + REST 兜底), node-gfw-check#D002(子节点继承父节点墙状态)

## [0.5.19] - 2026-04-27

### 新增
- **[admin-frontend]**: 为工单工作台回复区补齐图片拖拽上传与剪贴板粘贴上传，统一复用现有图片上传接口和 Markdown 图片插入逻辑，并将超大工单工作台组件拆分出上传 composable 与独立 SCSS 样式文件 — by yinjianm
  - 方案: [202604272310_ticket-chat-image-dnd-paste-upload](archive/2026-04/202604272310_ticket-chat-image-dnd-paste-upload/)
  - 决策: ticket-chat-image-dnd-paste-upload#D001(统一图片入口到现有 Markdown 上传链路)

## [0.5.18] - 2026-04-27

### 快速修改
- **[admin-frontend]**: 调整节点管理状态筛选口径，“在线节点”现在同时包含显式在线与待同步节点，顶部在线节点统计同步采用相同口径；“离线节点”仍只匹配显式离线节点 — by yinjianm
  - 类型: 快速修改（无方案包）
  - 文件: admin-frontend/src/utils/nodes.ts:12-176

## [0.5.17] - 2026-04-25

### 修复
- **[admin-frontend]**: 修复节点编辑 / 批量修改保存权限组后订阅侧无法命中节点的问题；前端提交 `group_ids / route_ids` 时统一序列化为字符串 ID，后端 `whereGroupId` 同时兼容历史字符串与数字 JSON 值，并补齐 TUIC V5/V4、ALPN 选项与 AnyTLS 完整默认 Padding Scheme — by yinjianm
  - 类型: 快速修改（无方案包）
  - 文件: admin-frontend/src/utils/nodeEditorMapper.ts, admin-frontend/src/utils/nodeEditorOptions.ts, admin-frontend/src/views/nodes/NodeEditorProtocolSection.vue, app/Models/Server.php

## [0.5.16] - 2026-04-25

### 新增
- **[admin-frontend]**: 为用户管理高级筛选新增“活跃状态”条件，支持按“活跃 / 非活跃”筛选；后端 `user/fetch` 现可识别 `activity_status` 复合规则，并按“任意订阅 + 流量未用完 + 最后在线时间在半年内”为活跃标准返回结果 — by yinjianm
  - 方案: [202604250018_admin-frontend-user-activity-status-filter](archive/2026-04/202604250018_admin-frontend-user-activity-status-filter/)
  - 决策: admin-frontend-user-activity-status-filter#D001(活跃判断入口固定在高级筛选弹窗), admin-frontend-user-activity-status-filter#D002(复合活跃规则统一由后端 activity_status 承接), admin-frontend-user-activity-status-filter#D003(全部状态继续由无条件表达)

## [0.5.15] - 2026-04-25

### 新增
- **[admin-frontend]**: 为节点管理工作台补齐“在线节点 / 离线节点”状态筛选，并新增针对已勾选节点的批量删除入口，接通真实 `server/manage/batchDelete` 后端链路；其中“离线节点”按本轮确认只筛显式离线状态，不包含待同步 / 已停用节点 — by yinjianm
  - 方案: [202604250015_admin-frontend-node-status-filter-batch-delete](plan/202604250015_admin-frontend-node-status-filter-batch-delete/)
  - 决策: admin-frontend-node-status-filter-batch-delete#D001(离线筛选仅匹配显式 offline 状态), admin-frontend-node-status-filter-batch-delete#D002(批量删除复用现有勾选工作流)

## [0.5.14] - 2026-04-25

### 修复
- **[order-payment]**: 补齐订单支付成功快照保存链路；现在会在支付成功后保存支付渠道、支付方法、实际支付金额与支付 IP，并在后台订单详情中集中展示平台订单号 / 商户订单号 / 支付快照信息 — by yinjianm
  - 方案: [202604250002_order-payment-snapshot](archive/2026-04/202604250002_order-payment-snapshot/)
  - 决策: order-payment-snapshot#D001(支付快照优先展示真实快照并回退当前支付配置), order-payment-snapshot#D002(实际支付金额统一按“分”存储)

## [0.5.13] - 2026-04-25

### 修复
- **[admin-frontend]**: 修复前后台已关闭工单无法再次回复的问题；现在用户与管理员再次回复 closed ticket 时都会自动重新开启工单，管理端工单工作台也补上“发送并重开”交互提示 — by yinjianm
  - 方案: [202604250006_ticket-closed-reply-reopen](plan/202604250006_ticket-closed-reply-reopen/)
  - 决策: ticket-closed-reply-reopen#D001(自动重开语义统一下沉到 TicketService::reply), ticket-closed-reply-reopen#D002(用户端优先通过后端语义修复打通), ticket-closed-reply-reopen#D003(管理端仅修复交互门禁)

## [0.5.12] - 2026-04-25

### 新增
- **[admin-frontend]**: 为仪表盘顶部指标卡补齐快捷入口增强；“待处理工单 / 待处理佣金 / 总用户”现在可直接进入对应工作台，其中工单页与订单页还会自动识别 dashboard 来源并落在目标视图 — by yinjianm
  - 方案: [202604250002_admin-frontend-dashboard-shortcuts](plan/202604250002_admin-frontend-dashboard-shortcuts/)
  - 决策: admin-frontend-dashboard-shortcuts#D001(仅开放已有明确承接页的指标卡快捷入口), admin-frontend-dashboard-shortcuts#D002(用路由查询同步 dashboard 入口上下文)

## [0.5.11] - 2026-04-24

### 快速修改
- **[admin-frontend]**: 修复节点管理页多选框点击后立即被程序化同步清空的问题；现在仅在分页切换时回填勾选状态，并在回填期间忽略内部 `selection-change` 事件，节点多选可正常选中与跨页恢复 — by yinjianm
  - 类型: 快速修改（无方案包）
  - 文件: admin-frontend/src/views/nodes/NodesView.vue:67,179-197,240-243,412-417

## [0.5.10] - 2026-04-24

### 快速修改
- **[ci-workflows]**: 将后端镜像发布工作流显式命名为 `Backend Docker Build and Publish`，并对 `admin-frontend/**` 及其独立 workflow 启用 `paths-ignore`；现在仅修改 `admin-frontend` 源码时只触发前端镜像发布，不再误触发后端镜像发布 — by yinjianm
  - 类型: 快速修改（无方案包）
  - 文件: .github/workflows/docker-publish.yml:1-76

## [0.5.9] - 2026-04-24

### 新增
- **[admin-frontend]**: 为节点管理工作台补齐本地分页、父/子节点筛选、单节点置顶与仅对已勾选节点生效的批量修改，支持统一更新 `host / group_ids / rate`，并接通真实 `server/manage/batchUpdate` 后端链路 — by yinjianm
  - 方案: [202604242245_admin-frontend-node-pagination-batch-edit](archive/2026-04/202604242245_admin-frontend-node-pagination-batch-edit/)
  - 决策: admin-frontend-node-pagination-batch-edit#D001(节点分页采用前端本地分页), admin-frontend-node-pagination-batch-edit#D002(批量修改范围固定为已勾选节点), admin-frontend-node-pagination-batch-edit#D003(置顶节点复用 server/manage/sort)

### 快速修改
- **[admin-frontend]**: 为独立 `xboard-admin-frontend` 容器补齐 `/api` 反向代理到后端 `web` 服务的链路，并在 compose 分支 `admin` 服务中显式声明 `XBOARD_BACKEND_UPSTREAM=http://web:7001`；同时把镜像名对齐到当前 fork `ghcr.io/micah123321/*` — by yinjianm
  - 类型: 快速修改（无方案包）
  - 文件: admin-frontend/Caddyfile:1-17, E:/code/php/Xboard-new-compose/compose.yaml:1-26

## [0.5.8] - 2026-04-24

### 新增
- **[admin-frontend]**: 为 `admin-frontend` 新增独立 Docker 镜像与 GHCR 自动发布链路，支持通过 `ADMIN_BUILD_OUT_DIR` 切换到容器专用 `dist` 输出，并在 `compose` 分支新增独立 `admin` 服务拉取 `ghcr.io/cedar2025/xboard-admin-frontend:new` 暴露运行 — by yinjianm
  - 方案: [202604242250_admin-frontend-ghcr-compose](plans/202604242250_admin-frontend-ghcr-compose/)
  - 决策: admin-frontend-ghcr-compose#D001(前端镜像发布链路独立于后端 docker-publish), admin-frontend-ghcr-compose#D002(容器内统一重定向到 /assets/admin/), admin-frontend-ghcr-compose#D003(compose 分支采用独立 admin 服务并暴露 7002)

## [0.5.7] - 2026-04-24

### 新增
- **[admin-frontend]**: 为用户管理“更多操作”菜单补齐旧后台常用行级动作，新增分配订单、查看 TA 的订单、查看 TA 的邀请、查看 TA 的流量记录与重置流量，并接通订单页按用户过滤与真实 `traffic-reset/reset-user` 后端链路 — by yinjianm
  - 方案: [202604242236_admin-frontend-user-more-actions](plans/202604242236_admin-frontend-user-more-actions/)
  - 决策: admin-frontend-user-more-actions#D001(订单分配抽屉预填邮箱), admin-frontend-user-more-actions#D002(用户订单采用跳页 + user_id 过滤), admin-frontend-user-more-actions#D003(邀请结果复用当前用户页筛选视图)

## [0.5.6] - 2026-04-24

### 修复
- **[admin-frontend]**: 修复订单管理页把无佣金订单误显示为“待确认”的问题；现在佣金状态会按真实佣金金额与发放链路判断，同时新增“确认佣金”菜单，可筛出真实待确认订单并在列表行级直接手动确认 — by yinjianm
  - 方案: [202604242217_admin-frontend-orders-commission-confirmation](archive/2026-04/202604242217_admin-frontend-orders-commission-confirmation/)
  - 决策: admin-frontend-orders-commission-confirmation#D001(真实佣金判定以金额和发放链路为准), admin-frontend-orders-commission-confirmation#D002(列表页新增确认佣金菜单与单行快捷确认)

## [0.5.5] - 2026-04-24

### 新增
- **[admin-frontend]**: 为用户管理工作台新增高级筛选与批量操作能力，支持多条件筛选、批量发送邮件、导出 CSV、批量封禁，以及对筛选结果执行“恢复正常” — by yinjianm
  - 方案: [202604242200_admin-frontend-user-advanced-filter-batch-ops](plans/202604242200_admin-frontend-user-advanced-filter-batch-ops/)
  - 决策: admin-frontend-user-advanced-filter-batch-ops#D001(高级筛选采用独立弹窗), admin-frontend-user-advanced-filter-batch-ops#D002(批量作用域按 selected > filtered > all 解析), admin-frontend-user-advanced-filter-batch-ops#D003(恢复正常沿用 user/ban 并扩展 banned=0|1)

## [0.5.4] - 2026-04-24

### 修复
- **[admin-frontend]**: 修复仪表盘“节点流量排行 / 用户流量排行”在 `24h` 视图下涨跌始终显示 `0%` 的问题；后端现在会把单日排行改为精确对比昨天整日统计，前端同步补上悬浮详情卡，并把当前流量值右移强化显示 — by yinjianm
  - 方案: [202604241925_admin-frontend-dashboard-rank-24h-compare](plan/202604241925_admin-frontend-dashboard-rank-24h-compare/)
  - 决策: admin-frontend-dashboard-rank-24h-compare#D001(仅修复 24h 与昨天对比逻辑，7天/30天 保持现状), admin-frontend-dashboard-rank-24h-compare#D002(排行项补充 hover 详情卡，并将当前流量右移显示)

## [0.5.3] - 2026-04-24

### 新增
- **[admin-frontend]**: 完成节点管理真实新增 / 编辑 / 排序工作台，补齐 11 种协议的动态配置弹窗、动态倍率规则编辑、路由 / 权限组联动与排序保存流程，并接入真实 `server/manage/save`、`server/manage/sort`、`server/route/fetch` 后台接口 — by yinjianm
  - 方案: [202604241718_admin-frontend-node-management](plans/202604241718_admin-frontend-node-management/)
  - 决策: admin-frontend-node-management#D001(新增与编辑共用中央大弹窗), admin-frontend-node-management#D002(排序沿用本地草稿 + 上移 / 下移), admin-frontend-node-management#D003(协议配置采用通用字段 + 动态协议块)

## [0.5.2] - 2026-04-24

### 新增
- **[admin-frontend]**: 开放“礼品卡管理”入口，完整交付模板管理、兑换码管理、使用记录与统计数据四页签工作台，并接入真实 `gift-card/*` 后台接口 — by yinjianm
  - 方案: [202604241703_admin-frontend-gift-card-management](archive/2026-04/202604241703_admin-frontend-gift-card-management/)
  - 决策: admin-frontend-gift-card-management#D001(礼品卡管理采用单页四页签导航), admin-frontend-gift-card-management#D002(模板编辑使用分组式大抽屉), admin-frontend-gift-card-management#D003(兑换码导出按当前批次显式执行)

## [0.5.1] - 2026-04-24

### 新增
- **[admin-frontend]**: 完成“路由管理”真实工作台，支持路由列表、关键词搜索、新增/编辑弹窗、删除，以及基于节点 `route_ids` 推导的节点引用摘要，并接入真实 `server/route/*` 后台接口 — by yinjianm
  - 方案: [202604241701_admin-frontend-node-route-management](plan/202604241701_admin-frontend-node-route-management/)
  - 决策: admin-frontend-node-route-management#D001(列表页贴近用户截图并保留 Apple 化后台节奏), admin-frontend-node-route-management#D002(节点引用摘要先收敛为列表只读信息而不扩展拓扑视图), admin-frontend-node-route-management#D003(动作值仅在 dns/proxy 时显示独立输入)

## [0.5.0] - 2026-04-24

### 新增
- **[admin-frontend]**: 将 `#/node-groups` 从占位页升级为真实权限组管理工作台，支持搜索、新增/编辑中央弹窗、删除确认，并补齐到 `#/nodes` 的权限组筛选联动入口 — by yinjianm
  - 方案: [202604241659_admin-frontend-node-group-management](archive/2026-04/202604241659_admin-frontend-node-group-management/)
  - 决策: admin-frontend-node-group-management#D001(权限组页采用截图导向的轻量工作台), admin-frontend-node-group-management#D002(新增与编辑复用同一中央弹窗), admin-frontend-node-group-management#D003(节点数量列承担跳转到节点筛选的联动入口)

## [0.4.8] - 2026-04-24

### 修复
- **[admin-frontend]**: 修复 `#/system/knowledge` 仍回退到结构化占位页的问题，补齐真实知识库管理列表挂载、详情编辑加载与最新构建产物刷新 — by yinjianm
  - 方案: [202604241610_admin-frontend-knowledge-management](plan/202604241610_admin-frontend-knowledge-management/)
  - 决策: admin-frontend-knowledge-management#D001(编辑器采用轻量 Markdown 方案), admin-frontend-knowledge-management#D002(列表页采用真实表格与中央对话框), admin-frontend-knowledge-management#D003(排序采用本地草稿编辑后统一提交)

## [0.4.7] - 2026-04-24

### 修复
- **[admin-frontend]**: 修复管理端侧边栏在低窗口高度下会裁切底部菜单的问题；现在顶部品牌区保持固定，菜单区可独立滚动访问“礼品卡管理”“系统管理”“支付配置”“知识库管理”等底部入口 — by yinjianm
  - 方案: [202604241655_admin-frontend-sidebar-height-overflow](archive/2026-04/202604241655_admin-frontend-sidebar-height-overflow/)
  - 决策: admin-frontend-sidebar-height-overflow#D001(固定品牌区 + 独立滚动菜单区)

## [0.4.6] - 2026-04-24

### 修复
- **[subscription-protocols]**: 修复 `flag=stash` 订阅在未知版本或低版本客户端下仍导出 `AnyTLS` 节点的问题；现在只有 Stash `>= 3.3.0` 才会保留 `AnyTLS`，避免导入时报“**不支持 anytls 协议**” — by yinjianm
  - 方案: [202604241619_fix-stash-anytls-compat-filter](plan/202604241619_fix-stash-anytls-compat-filter/)
  - 决策: fix-stash-anytls-compat-filter#D001(未知版本按不支持 AnyTLS 处理), fix-stash-anytls-compat-filter#D002(仅在 Stash 导出器中做定点修复)

## [0.4.5] - 2026-04-24

### 新增
- **[admin-frontend]**: 开放“订单管理”入口，完整交付真实订单列表、类型/周期/状态筛选、分配订单抽屉、详情抽屉、手动标记已支付与佣金状态维护，并接入 `order/*` 后台接口 — by yinjianm
  - 方案: [202604241620_admin-frontend-order-management](archive/2026-04/202604241620_admin-frontend-order-management/)
  - 决策: admin-frontend-order-management#D001(列表页贴近用户截图并保留 Apple 化后台节奏), admin-frontend-order-management#D002(详情操作收口到订单详情抽屉而不是额外操作列), admin-frontend-order-management#D003(订单金额统一按“分→元”格式化展示)

## [0.4.4] - 2026-04-24

### 新增
- **[admin-frontend]**: 完成“支付配置”真实工作台，支持支付方式列表、关键词搜索、启停、删除、动态配置抽屉与排序模式，并接入真实 `payment/*` 后台接口 — by yinjianm
  - 方案: [202604241558_admin-frontend-payment-management](archive/2026-04/202604241558_admin-frontend-payment-management/)
  - 决策: admin-frontend-payment-management#D001(真实列表页+动态配置抽屉+排序对话框), admin-frontend-payment-management#D002(支付配置字段完全以后端动态表单为真相源), admin-frontend-payment-management#D003(启停继续沿用切换型接口并做同值短路保护)

## [0.4.3] - 2026-04-24

### 新增
- **[admin-frontend]**: 将 `#/system/themes` 从结构化占位页升级为真实主题管理页面，接入主题列表、当前主题切换、动态主题配置抽屉与 zip 主题上传入口 — by yinjianm
  - 方案: [202604241607_admin-frontend-theme-management](archive/2026-04/202604241607_admin-frontend-theme-management/)
  - 决策: admin-frontend-theme-management#D001(主题切换复用 config/save(frontend_theme)), admin-frontend-theme-management#D002(主题配置统一放入抽屉)

## [0.4.3] - 2026-04-24

### 新增
- **[admin-frontend]**: 将 `#/system/plugins` 从占位页升级为真实插件管理工作台，接入插件列表、类型 / 状态筛选、上传、安装、启停、升级、卸载，以及 README / 动态配置抽屉；同时补齐缺失的订单管理与知识库管理路由壳层以恢复 `npm run build` 通过 — by yinjianm
  - 方案: [202604241553_admin-frontend-plugin-management](archive/2026-04/202604241553_admin-frontend-plugin-management/)
  - 决策: admin-frontend-plugin-management#D001(插件管理采用卡片列表 + 详情抽屉), admin-frontend-plugin-management#D002(配置编辑使用动态 schema 渲染), admin-frontend-plugin-management#D003(README 与配置合并进同一个详情工作台)

## [0.4.2] - 2026-04-24

### 新增
- **[admin-frontend]**: 完成“知识库管理”真实工作台，支持知识列表、标题搜索、分类筛选、显隐切换、新增/编辑弹窗、删除与排序模式，并接入真实 `knowledge/*` 后台接口 — by yinjianm
  - 方案: [202604241610_admin-frontend-knowledge-management](plan/202604241610_admin-frontend-knowledge-management/)
  - 决策: admin-frontend-knowledge-management#D001(编辑器采用轻量 Markdown 方案), admin-frontend-knowledge-management#D002(列表页采用真实表格与中央对话框), admin-frontend-knowledge-management#D003(排序采用本地草稿编辑后统一提交)

## [0.4.1] - 2026-04-24

### 新增
- **[admin-frontend]**: 完成“公告管理”真实工作台，支持公告列表、标题搜索、显隐切换、新增/编辑弹窗、删除与排序模式，并接入真实 `notice/*` 后台接口 — by yinjianm
  - 方案: [202604241609_admin-frontend-notice-management](plan/202604241609_admin-frontend-notice-management/)
  - 决策: admin-frontend-notice-management#D001(真实列表页+编辑弹窗+排序对话框), admin-frontend-notice-management#D002(公告内容编辑继续使用轻量 Markdown 方案), admin-frontend-notice-management#D003(公告开关与标签统一归一化)

## [0.4.0] - 2026-04-24

### 新增
- **[admin-frontend]**: 开放“优惠券管理”入口，完整交付优惠券列表、关键字搜索、类型筛选、启停、删除，以及接入真实 `coupon/*` 接口的新增/编辑弹窗 — by yinjianm
  - 方案: [202604241551_admin-frontend-coupon-management](archive/2026-04/202604241551_admin-frontend-coupon-management/)
  - 决策: admin-frontend-coupon-management#D001(优惠券列表采用真实接口+本地搜索筛选分页), admin-frontend-coupon-management#D002(新增与编辑共用同一弹窗并统一序列化), admin-frontend-coupon-management#D003(优惠券编辑采用居中弹窗而非抽屉)

## [0.3.2] - 2026-04-24

### 修复
- **[admin-frontend]**: 修复 `#/subscriptions/plans` 页面首次加载时会误把套餐“新购”状态批量关闭的问题；现在会先归一化 `show / sell / renew` 开关值，并在同值事件下短路，避免浏览页面即触发真实写操作 — by yinjianm
  - 方案: [202604241542_admin-frontend-plan-toggle-regression](plan/202604241542_admin-frontend-plan-toggle-regression/)
  - 决策: admin-frontend-plan-toggle-regression#D001(前端入口先归一化套餐开关值), admin-frontend-plan-toggle-regression#D002(开关提交增加同值短路护栏)

## [0.3.0] - 2026-04-23

### 新增
- **[admin-frontend]**: 新增“节点管理”侧边栏分组、节点管理工作台，以及权限组/路由管理占位页；同时补齐缺失的 `PlansView` 占位组件以恢复 `npm run build` 构建通过 — by yinjianm
  - 方案: [202604232320_admin-frontend-node-management](archive/2026-04/202604232320_admin-frontend-node-management/)
  - 决策: admin-frontend-node-management#D001(首批聚焦节点列表运营链路), admin-frontend-node-management#D002(权限组与路由管理先交付结构化占位页)

## [0.3.1] - 2026-04-23

### 新增
- **[admin-frontend]**: 新增“系统管理”侧边栏分组，完整交付系统配置页，并接入插件/主题/公告/支付/知识库 5 个结构化占位页 — by yinjianm
  - 方案: [202604232329_admin-frontend-system-management](archive/2026-04/202604232329_admin-frontend-system-management/)
  - 决策: admin-frontend-system-management#D001(首批聚焦系统配置真实页), admin-frontend-system-management#D002(其余系统管理入口先交付结构化占位页), admin-frontend-system-management#D003(系统配置采用左侧分组导航与右侧连续 section)

## [0.1.0] - 2026-04-21

### 新增
- **[admin-frontend]**: 完成深色 Composio 风格管理端仪表盘、登录回跳和真实统计面板接入 — by yinjianm
  - 方案: [202604210326_admin-frontend-composio-dashboard](archive/2026-04/202604210326_admin-frontend-composio-dashboard/)
  - 决策: admin-frontend-composio-dashboard#D001(采用深色 Composio 风格), admin-frontend-composio-dashboard#D002(趋势图使用自绘 SVG)

## [0.1.1] - 2026-04-21

### 修复
- **[admin-frontend]**: 将登录页、主布局和仪表盘重构为 Apple 风格，并移除高成本视觉装饰以缓解页面卡顿 — by yinjianm
  - 方案: [202604210400_admin-frontend-apple-performance-refresh](archive/2026-04/202604210400_admin-frontend-apple-performance-refresh/)
  - 决策: admin-frontend-apple-performance-refresh#D001(采用 Apple 风格并优先性能减法), admin-frontend-apple-performance-refresh#D002(保留逻辑层只替换视图皮层)

## [0.1.2] - 2026-04-21

### 快速修改
- **[admin-frontend]**: 修正仪表盘金额显示单位，将后端返回的“分”统一转换为“元”后再格式化 — by yinjianm
  - 类型: 快速修改（无方案包）
  - 文件: admin-frontend/src/utils/dashboard.ts:75

## [0.2.0] - 2026-04-21

### 新增
- **[admin-frontend]**: 新增用户管理工作台、抽屉表单、用户操作菜单，以及“用户管理 / 工单管理”导航与路由骨架 — by yinjianm
  - 方案: [202604210441_admin-frontend-user-management](archive/2026-04/202604210441_admin-frontend-user-management/)
  - 决策: admin-frontend-user-management#D001(新增用户采用两段式创建), admin-frontend-user-management#D002(先补齐用户与工单入口结构)

## [0.2.1] - 2026-04-23

### 新增
- **[admin-frontend]**: 为仪表盘收入趋势面板新增“按金额 / 按数量”切换，并让图表摘要、Y 轴标签与最近记录跟随口径同步 — by yinjianm
  - 方案: [202604232313_admin-frontend-dashboard-trend-count-toggle](archive/2026-04/202604232313_admin-frontend-dashboard-trend-count-toggle/)
  - 决策: admin-frontend-dashboard-trend-count-toggle#D001(采用单图切换而不是双图/双线), admin-frontend-dashboard-trend-count-toggle#D002(数量模式复用现有接口字段)

## [0.2.2] - 2026-04-23

### 新增
- **[admin-frontend]**: 为仪表盘节点/用户流量排行新增 `10个 / 20个` 显示切换，并将长列表收进面板内滚动区域 — by yinjianm
  - 方案: [202604232318_admin-frontend-rank-limit-scroll](archive/2026-04/202604232318_admin-frontend-rank-limit-scroll/)
  - 决策: admin-frontend-rank-limit-scroll#D001(两个排行面板独立控制显示数量), admin-frontend-rank-limit-scroll#D002(使用固定高度滚动容器而不是整页自然拉伸)

## [0.2.3] - 2026-04-23

### 新增
- **[admin-frontend]**: 为仪表盘“作业详情”面板新增“查看报错详情”入口，并通过弹窗展示失败作业的报错摘要、失败时间与队列信息 — by yinjianm
  - 方案: [202604232330_admin-frontend-queue-error-details](plan/202604232330_admin-frontend-queue-error-details/)
  - 决策: admin-frontend-queue-error-details#D001(使用弹窗承载失败作业详情), admin-frontend-queue-error-details#D002(前端兼容式解析 Horizon 失败作业字段)

## [0.2.4] - 2026-04-23

### 新增
- **[admin-frontend]**: 为 traffic rank 前后端联动补齐 `limit=10|20` 支持，让排行数量切换真正驱动后端返回条数，并保留 24h 口径的涨跌展示 — by yinjianm
  - 方案: [202604232345_traffic-rank-limit-backend-adapt](archive/2026-04/202604232345_traffic-rank-limit-backend-adapt/)
  - 决策: traffic-rank-limit-backend-adapt#D001(在现有 getTrafficRank 接口上新增 limit 参数), traffic-rank-limit-backend-adapt#D002(24h 口径继续显示 change)

## [0.2.5] - 2026-04-23

### 新增
- **[admin-frontend]**: 为 Apple 风格仪表盘新增 Hero 区“刷新全部数据”按钮，并补齐最后刷新时间、加载反馈与防重复触发状态 — by yinjianm
  - 方案: [202604231515_admin-frontend-dashboard-refresh-button](archive/2026-04/202604231515_admin-frontend-dashboard-refresh-button/)
  - 决策: admin-frontend-dashboard-refresh-button#D001(刷新入口放在 Hero 状态区), admin-frontend-dashboard-refresh-button#D002(复用 refreshDashboard 作为唯一全量刷新入口)

## [0.2.6] - 2026-04-23

### 新增
- **[admin-frontend]**: 新增“订阅管理”侧边栏分组与套餐管理页面，支持真实套餐 CRUD、排序、价格矩阵与说明预览 — by yinjianm
  - 方案: [202604232325_admin-frontend-subscription-plan-management](plan/202604232325_admin-frontend-subscription-plan-management/)
  - 决策: admin-frontend-subscription-plan-management#D001(其余订阅菜单先保留禁用入口), admin-frontend-subscription-plan-management#D002(说明编辑采用轻量 Markdown 方案), admin-frontend-subscription-plan-management#D003(排序采用本地编辑对话框)
