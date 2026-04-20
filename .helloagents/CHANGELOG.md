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
