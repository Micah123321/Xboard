# CHANGELOG

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
