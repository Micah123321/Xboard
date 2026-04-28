# 方案归档索引

> 通过此文件快速查找历史方案
> 历史年份: [2024](_index-2024.md) | [2023](_index-2023.md) | ...

## 快速索引（当前年份）

| 时间戳 | 名称 | 类型 | 涉及模块 | 决策 | 结果 |
|--------|------|------|---------|------|------|
| 202604290153 | parent-node-auto-visibility | - | - | - | ✅完成 |
| 202604290132 | shared-node-traffic-limit | implementation | node-traffic-limit,admin-frontend | shared-node-traffic-limit#D001 | ✅完成 |
| 202604290123 | node-traffic-yesterday-stats | implementation | admin-frontend,backend-admin-api | node-traffic-yesterday-stats#D001 | ✅完成 |
| 202604281921 | node-traffic-limit-enforcement | implementation | node-traffic-limit,admin-frontend,mi-node | node-traffic-limit-enforcement#D001,#D002 | ✅完成 |
| 202604281625 | admin-frontend-node-traffic-hover | - | - | - | ✅完成 |
| 202604281632 | admin-frontend-node-auto-online-immediate-sync | - | - | - | ✅完成 |
| 202604281441 | fix-admin-node-gfw-null-enabled | implementation | node-gfw-check,admin-frontend | fix-admin-node-gfw-null-enabled#D001 | ✅完成 |
| 202604281432 | ci-ignore-helloagents-for-backend-docker | implementation | ci-workflows | ci-ignore-helloagents-for-backend-docker#D001 | ✅完成 |
| 202604281303 | xboard-reusable-server-deploy | implementation | deploy,node-gfw-check | xboard-reusable-server-deploy#D001,#D002 | ✅完成 |
| 202604281258 | fix-send-email-job-timeout | implementation | queue-mail | fix-send-email-job-timeout#D001 | ✅完成 |
| 202604280024 | node-gfw-auto-check-and-online | implementation | node-gfw-check,admin-frontend | node-gfw-auto-check-and-online#D001,#D002 | ✅完成 |
| 202604272338 | admin-frontend-node-auto-online | - | - | - | ✅完成 |
| 202604272325 | node-gfw-check | implementation | node-gfw-check,admin-frontend,mi-node | node-gfw-check#D001,#D002 | ✅完成 |
| 202604272310 | ticket-chat-image-dnd-paste-upload | implementation | admin-frontend | ticket-chat-image-dnd-paste-upload#D001 | ✅完成 |
| 202604250018 | admin-frontend-user-activity-status-filter | implementation | admin-frontend,backend | admin-frontend-user-activity-status-filter#D001,#D002,#D003 | ✅完成 |
| 202604250002 | order-payment-snapshot | implementation | admin-frontend,order-payment | order-payment-snapshot#D001,#D002 | ✅完成 |
| 202604242245 | admin-frontend-node-pagination-batch-edit | implementation | admin-frontend | admin-frontend-node-pagination-batch-edit#D001,#D002,#D003 | ✅完成 |
| 202604242217 | admin-frontend-orders-commission-confirmation | implementation | admin-frontend | admin-frontend-orders-commission-confirmation#D001,#D002 | ✅完成 |
| 202604241703 | admin-frontend-gift-card-management | implementation | admin-frontend | admin-frontend-gift-card-management#D001,#D002,#D003 | ✅完成 |
| 202604241659 | admin-frontend-node-group-management | implementation | admin-frontend | admin-frontend-node-group-management#D001,#D002,#D003 | ✅完成 |
| 202604241655 | admin-frontend-sidebar-height-overflow | - | - | - | ✅完成 |
| 202604241620 | admin-frontend-order-management | implementation | admin-frontend | admin-frontend-order-management#D001,#D002,#D003 | ✅完成 |
| 202604241558 | admin-frontend-payment-management | implementation | admin-frontend | admin-frontend-payment-management#D001,#D002,#D003 | ✅完成 |
| 202604241553 | admin-frontend-plugin-management | implementation | admin-frontend | admin-frontend-plugin-management#D001,#D002,#D003 | ✅完成 |
| 202604241607 | admin-frontend-theme-management | implementation | admin-frontend | admin-frontend-theme-management#D001,#D002 | ✅完成 |
| 202604241551 | admin-frontend-coupon-management | implementation | admin-frontend | admin-frontend-coupon-management#D001,#D002,#D003 | ✅完成 |
| 202604232345 | traffic-rank-limit-backend-adapt | implementation | admin-frontend,backend | traffic-rank-limit-backend-adapt#D001,#D002 | ✅完成 |
| 202604232329 | admin-frontend-system-management | implementation | admin-frontend | admin-frontend-system-management#D001,#D002,#D003 | ✅完成 |
| 202604232320 | admin-frontend-node-management | implementation | admin-frontend | admin-frontend-node-management#D001,#D002 | ✅完成 |
| 202604232318 | admin-frontend-rank-limit-scroll | implementation | admin-frontend | admin-frontend-rank-limit-scroll#D001,#D002 | ✅完成 |
| 202604232313 | admin-frontend-dashboard-trend-count-toggle | implementation | admin-frontend | admin-frontend-dashboard-trend-count-toggle#D001,#D002 | ✅完成 |
| 202604231515 | admin-frontend-dashboard-refresh-button | implementation | admin-frontend | admin-frontend-dashboard-refresh-button#D001,#D002 | ✅完成 |
| 202604210441 | admin-frontend-user-management | - | - | - | ✅完成 |
| 202604210400 | admin-frontend-apple-performance-refresh | implementation | admin-frontend | admin-frontend-apple-performance-refresh#D001,#D002 | ✅完成 |
| 202604210326 | admin-frontend-composio-dashboard | implementation | admin-frontend | admin-frontend-composio-dashboard#D001,#D002 | ✅完成 |
| 202604180040 | optimize-docker-publish-workflow | - | - | - | ✅完成 |
| 202604180029 | fix-clashmeta-flow-map-export | - | - | - | ✅完成 |
| 202604161703 | create-git-merge-preserve-local-skill | - | - | - | ✅完成 |
| 202604161655 | merge-upstream-preserve-local | - | - | - | ✅完成 |

## 按月归档

### 2026-04
- [202604290132_shared-node-traffic-limit](./2026-04/202604290132_shared-node-traffic-limit/) - 修正节点管理月额度使用量口径，同 `machine_id` 或同 host 节点共享当前账期用量，并由后端快照统一服务管理端展示和 mi-node 下发
- [202604290123_node-traffic-yesterday-stats](./2026-04/202604290123_node-traffic-yesterday-stats/) - 节点流量详情卡新增“昨日”统计，并让今日、昨日和本月统计按半开窗口聚合，便于核对上行/下行流量分布
- [202604281921_node-traffic-limit-enforcement](./2026-04/202604281921_node-traffic-limit-enforcement/) - 新增节点月流量限额强制下线能力，Xboard 负责配置、重置调度和状态展示，mi-node 负责本地额度累计、内核停止与重置恢复
- [202604281441_fix-admin-node-gfw-null-enabled](./2026-04/202604281441_fix-admin-node-gfw-null-enabled/) - 修复 `parent_id=0` 父节点不会被自动墙检入队导致长期显示“未检测”的问题，并让自动墙检查询对齐项目父节点与启用语义
- [202604281303_xboard-reusable-server-deploy](./2026-04/202604281303_xboard-reusable-server-deploy/) - 新增可复制到服务器的 Xboard Compose 部署模板，补齐独立 `scheduler` 服务，并提供 `.env.example`、初始化/部署/更新/状态检查脚本和部署说明
- [202604281258_fix-send-email-job-timeout](./2026-04/202604281258_fix-send-email-job-timeout/) - 修复 `SendEmailJob` 10 秒超时导致 `send_email` 队列批量失败的问题，补齐邮件 job 超时/backoff、SMTP transport timeout、运行时 mailer 刷新和 MailLog 配置脱敏
- [202604280024_node-gfw-auto-check-and-online](./2026-04/202604280024_node-gfw-auto-check-and-online/) - 为节点墙状态检测打通自动检测与自动显隐，支持开启托管的父节点定时检测、疑似被墙自动隐藏、恢复正常自动显示，并让自动上线尊重 blocked 状态
- [202604272325_node-gfw-check](./2026-04/202604272325_node-gfw-check/) - 新增节点墙状态检测闭环，支持父节点检测、子节点继承、管理端展示筛选，以及 mi-node WS/REST 检测上报
- [202604272310_ticket-chat-image-dnd-paste-upload](./2026-04/202604272310_ticket-chat-image-dnd-paste-upload/) - 为工单工作台回复区补齐图片拖拽上传与剪贴板粘贴上传，并将上传逻辑与样式从超大 SFC 中拆分
- [202604250018_admin-frontend-user-activity-status-filter](./2026-04/202604250018_admin-frontend-user-activity-status-filter/) - 为用户管理高级筛选新增“活跃状态”条件，并在后端补齐 `activity_status` 复合过滤规则，支持按活跃 / 非活跃筛选用户
- [202604250002_order-payment-snapshot](./2026-04/202604250002_order-payment-snapshot/) - 补齐订单支付成功快照保存链路，并在后台订单详情中集中展示支付渠道、支付方法、平台订单号、商户订单号、实付金额与支付 IP
- [202604242245_admin-frontend-node-pagination-batch-edit](./2026-04/202604242245_admin-frontend-node-pagination-batch-edit/) - 为节点管理工作台补齐本地分页、父/子节点筛选、单节点置顶，以及仅对已勾选节点生效的批量修改
- [202604242217_admin-frontend-orders-commission-confirmation](./2026-04/202604242217_admin-frontend-orders-commission-confirmation/) - 修复订单页无佣金订单误显示为待确认的问题，并新增真实待确认佣金筛选与行级手动确认入口
- [202604241703_admin-frontend-gift-card-management](./2026-04/202604241703_admin-frontend-gift-card-management/) - 开放“礼品卡管理”入口，交付模板管理、兑换码管理、使用记录与统计数据四页签工作台，并接入真实 gift-card 接口
- [202604241659_admin-frontend-node-group-management](./2026-04/202604241659_admin-frontend-node-group-management/) - 将 `#/node-groups` 从占位页升级为真实权限组管理工作台，并补齐到 `#/nodes` 的权限组筛选联动
- [202604241620_admin-frontend-order-management](./2026-04/202604241620_admin-frontend-order-management/) - 开放“订单管理”入口，交付真实订单列表、筛选、分配订单、详情抽屉、手动支付与佣金状态维护
- [202604241558_admin-frontend-payment-management](./2026-04/202604241558_admin-frontend-payment-management/) - 将 `#/system/payments` 从占位页升级为真实支付配置工作台，接入支付方式列表、动态配置抽屉、启停、删除与排序
- [202604241553_admin-frontend-plugin-management](./2026-04/202604241553_admin-frontend-plugin-management/) - 将 `#/system/plugins` 从占位页升级为真实插件管理工作台，接入插件列表、筛选、上传、安装、启停、升级、卸载，以及 README / 动态配置抽屉
- [202604241607_admin-frontend-theme-management](./2026-04/202604241607_admin-frontend-theme-management/) - 将 `#/system/themes` 从占位页升级为真实主题管理页面，接入主题列表、当前主题切换、动态配置抽屉与 zip 主题上传
- [202604241551_admin-frontend-coupon-management](./2026-04/202604241551_admin-frontend-coupon-management/) - 开放“优惠券管理”入口，完整交付优惠券列表、类型筛选、启停、删除，以及接入真实 coupon 接口的新增/编辑弹窗
- [202604232345_traffic-rank-limit-backend-adapt](./2026-04/202604232345_traffic-rank-limit-backend-adapt/) - traffic rank 接口新增 limit=10|20 支持，并让 dashboard 的 10/20 切换真正驱动后端返回条数
- [202604232329_admin-frontend-system-management](./2026-04/202604232329_admin-frontend-system-management/) - 新增“系统管理”侧边栏分组，完整交付系统配置页，并接入插件/主题/公告/支付/知识库结构化占位页
- [202604232320_admin-frontend-node-management](./2026-04/202604232320_admin-frontend-node-management/) - 新增“节点管理”侧边栏分组、节点管理工作台，以及权限组/路由管理占位页
- [202604232318_admin-frontend-rank-limit-scroll](./2026-04/202604232318_admin-frontend-rank-limit-scroll/) - 仪表盘节点流量排行和用户流量排行新增 10/20 显示切换，并在面板内提供滚动查看
- [202604232313_admin-frontend-dashboard-trend-count-toggle](./2026-04/202604232313_admin-frontend-dashboard-trend-count-toggle/) - 仪表盘收入趋势新增“按金额 / 按数量”切换，并让摘要和最近记录同步切换口径
- [202604231515_admin-frontend-dashboard-refresh-button](./2026-04/202604231515_admin-frontend-dashboard-refresh-button/) - 为 Apple 风格仪表盘新增 Hero 区“刷新全部数据”按钮，并补齐加载反馈与最后刷新时间
- [202604210441_admin-frontend-user-management](./2026-04/202604210441_admin-frontend-user-management/) - 新增用户管理工作台、抽屉表单、用户操作菜单，以及“用户管理 / 工单管理”导航与路由骨架
- [202604210400_admin-frontend-apple-performance-refresh](./2026-04/202604210400_admin-frontend-apple-performance-refresh/) - Apple 风格重构登录页、主布局和仪表盘，并移除高成本装饰层以缓解卡顿
- [202604210326_admin-frontend-composio-dashboard](./2026-04/202604210326_admin-frontend-composio-dashboard/) - 深色 Composio 风格管理端仪表盘、登录回跳与真实统计数据接入

## 结果状态说明
- ✅ 完成
- ⚠️ 部分完成
- ❌ 失败/中止
- ⏸ 未执行
- 🔄 已回滚
- 📄 概述
