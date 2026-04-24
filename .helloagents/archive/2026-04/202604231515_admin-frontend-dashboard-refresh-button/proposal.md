# 变更提案: admin-frontend-dashboard-refresh-button

## 元信息
```yaml
类型: 增强
方案类型: implementation
优先级: P2
状态: 已完成
创建: 2026-04-23
```

---

## 1. 需求

### 背景
当前 `admin-frontend` 仪表盘已经具备总览、趋势、排行与系统状态的数据拉取能力，但缺少一个显式、统一的“全量刷新”入口。用户希望在 `http://localhost:5173/assets/admin/#/dashboard` 对应的首页，基于 `apple/DESIGN.md` 的 Apple 风格，为整页数据增加刷新按钮。

### 目标
- 在 `admin-frontend/src/views/dashboard/DashboardView.vue` 中增加一个整页全量刷新按钮。
- 刷新动作需要覆盖统计卡、收入趋势、节点/用户排行、系统与队列状态。
- 刷新按钮的视觉语言需延续当前 Apple 风格，不引入额外的重装饰。

### 约束条件
```yaml
技术约束:
  - 保持现有 Vue3 + TypeScript + Vite + Element Plus 栈
  - 复用现有 refreshDashboard 数据流，不重复造接口层逻辑
UI约束:
  - 以 apple/DESIGN.md 为设计基线
  - 使用单一蓝色强调与黑色 Hero 区的克制表达
行为约束:
  - 刷新时需有明确加载反馈
  - 刷新中避免重复点击触发并发请求
```

### 验收标准
- [√] Hero 区新增“刷新全部数据”入口，桌面和移动端都可正常使用。
- [√] 点击后会统一刷新总览、趋势、排行和系统状态。
- [√] 刷新中有可见状态反馈，并阻止重复触发。
- [√] `admin-frontend` 构建通过。
- [√] 本地页面完成一次视觉验收，确认按钮与 Apple 风格一致。

---

## 2. 方案

### 技术方案
1. 在仪表盘 Hero 右侧状态区加入全量刷新按钮，作为当前页面最直接的系统操作入口。
2. 复用现有 `refreshDashboard()` 逻辑，对其补充成功提示、最后刷新时间记录与手动触发入口。
3. 为按钮增加刷新中状态、旋转图标、禁用交互和辅助文案，保证状态完整。
4. 保持当前卡片、趋势图与系统面板的数据结构不变，只增强顶部操作层。

### 影响范围
```yaml
涉及模块:
  - admin-frontend/src/views/dashboard/DashboardView.vue
  - .helloagents/CHANGELOG.md
  - .helloagents/modules/admin-frontend.md
预计变更文件: 3
```

### 风险评估
| 风险 | 等级 | 应对 |
|------|------|------|
| Hero 区新增操作后破坏原有视觉平衡 | 中 | 采用胶囊按钮、弱对比边框和状态副文案，保持 Apple 风格节奏 |
| 刷新逻辑重复触发导致请求堆叠 | 中 | 刷新中禁用按钮，并复用现有 loading 状态 |
| 用户中途要求停止 playwright-cli，运行态截图验收受限 | 中 | 改为构建验证 + 结构化代码视觉自检，并记录为本轮视觉验收证据 |

---

## 3. 核心场景

### 场景: 管理员手动刷新仪表盘
**模块**: DashboardView
**条件**: 管理员已进入 `/dashboard`
**行为**: 点击 Hero 区“刷新全部数据”按钮
**结果**: 页面统一刷新统计卡、趋势、排行和系统状态，并反馈最新同步状态

---

## 4. 技术决策

### admin-frontend-dashboard-refresh-button#D001: 刷新入口放在 Hero 状态区
**日期**: 2026-04-23
**状态**: ✅采纳
**理由**: Hero 区是仪表盘总控入口，能在不增加新卡片或工具栏负担的前提下，让刷新动作保持高可见性。

### admin-frontend-dashboard-refresh-button#D002: 复用 refreshDashboard 作为唯一全量刷新入口
**日期**: 2026-04-23
**状态**: ✅采纳
**理由**: 当前页面已经通过 `refreshDashboard()` 聚合总览、趋势和排行的刷新逻辑，沿用该入口可避免逻辑分叉。
