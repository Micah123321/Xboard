# CHANGELOG

## [0.5.9] - 2026-04-24

### 新增
- **[admin-frontend]**: 为节点管理工作台补齐本地分页、父/子节点筛选、单节点置顶与仅对已勾选节点生效的批量修改，支持统一更新 `host / group_ids / rate`，并接通真实 `server/manage/batchUpdate` 后端链路 — by yinjianm
  - 方案: [202604242245_admin-frontend-node-pagination-batch-edit](archive/2026-04/202604242245_admin-frontend-node-pagination-batch-edit/)
  - 决策: admin-frontend-node-pagination-batch-edit#D001(节点分页采用前端本地分页), admin-frontend-node-pagination-batch-edit#D002(批量修改范围固定为已勾选节点), admin-frontend-node-pagination-batch-edit#D003(置顶节点复用 server/manage/sort)

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
