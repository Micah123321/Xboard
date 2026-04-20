# 任务清单: admin-frontend-apple-performance-refresh

> **@status:** completed | 2026-04-21 04:14

```yaml
@feature: admin-frontend-apple-performance-refresh
@created: 2026-04-21
@status: completed
@mode: R2
```

## 进度概览

| 完成 | 失败 | 跳过 | 总数 |
|------|------|------|------|
| 5 | 0 | 0 | 5 |

---

## 任务列表

### 1. 全局视觉与性能基线

- [√] 1.1 在 `admin-frontend/src/styles/index.scss` 中移除高成本装饰层并建立 Apple 风格全局变量 | depends_on: []

### 2. 登录与布局

- [√] 2.1 在 `admin-frontend/src/views/login/LoginView.vue` 中将登录页重构为 Apple 风格轻量布局 | depends_on: [1.1]
- [√] 2.2 在 `admin-frontend/src/layouts/AdminLayout.vue` 中重构主布局与头部导航，去除当前控制台式装饰 | depends_on: [1.1]

### 3. 仪表盘

- [√] 3.1 在 `admin-frontend/src/views/dashboard/DashboardView.vue` 中重构为 Apple 风格信息分区并保留现有统计功能 | depends_on: [1.1,2.2]
- [√] 3.2 完成 `admin-frontend` 构建验证并修正重构引入的问题 | depends_on: [2.1,2.2,3.1]

---

## 执行日志

| 时间 | 任务 | 状态 | 备注 |
|------|------|------|------|
| 2026-04-21 04:00 | 方案包初始化 | completed | 已锁定 Apple 风格 + 性能减法方向 |
| 2026-04-21 04:10 | 1.1 / 2.x | completed | 已移除远程字体与全局重装饰，完成登录页和主布局 Apple 化 |
| 2026-04-21 04:12 | 3.1 | completed | 已将仪表盘重构为 Apple 风格分区，同时保留现有数据接口 |
| 2026-04-21 04:12 | 3.2 | completed | `npm run build` 通过，运行态入口 `200` |

---

## 执行备注

> 记录执行过程中的重要说明、决策变更、风险提示等

- 本轮不回退上一轮的数据接入和登录回跳逻辑，仅重构视图皮层和高成本样式。
- 运行态验证已确认 `/assets/admin/` 与 `/#/login` 可访问；真实性能评估仍建议在真实后台数据环境里再观察一次滚动与切换体验。
