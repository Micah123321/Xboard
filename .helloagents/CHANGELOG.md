# CHANGELOG

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
