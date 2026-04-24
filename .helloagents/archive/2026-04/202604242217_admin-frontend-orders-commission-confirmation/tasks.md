# 任务清单: admin-frontend-orders-commission-confirmation

> **@status:** completed | 2026-04-24 22:29

```yaml
@feature: admin-frontend-orders-commission-confirmation
@created: 2026-04-24
@status: completed
@mode: R2
```

## 进度概览

| 完成 | 失败 | 跳过 | 总数 |
|------|------|------|------|
| 5 | 0 | 0 | 5 |

---

## 任务列表

### 1. 佣金状态判定与页面交互

- [√] 1.1 在 `admin-frontend/src/utils/orders.ts` 中统一真实佣金判定与状态映射，修复无佣金订单误显示为“待确认” | depends_on: []
- [√] 1.2 在 `admin-frontend/src/views/subscriptions/OrdersView.vue` 中接入真实佣金筛选状态，新增“确认佣金”菜单并在列表请求中透传 `is_commission` | depends_on: [1.1]
- [√] 1.3 在 `admin-frontend/src/views/subscriptions/OrdersView.vue` 与 `OrdersView.scss` 中增加行级操作列，为真实待确认订单提供“确认佣金”快捷操作与状态提示 | depends_on: [1.2]

### 2. 一致性与验收

- [√] 2.1 在 `admin-frontend/src/views/subscriptions/OrderDetailDrawer.vue` 中同步佣金状态文案与可编辑条件，确保列表与详情一致 | depends_on: [1.1]
- [√] 2.2 执行 `admin-frontend` 构建验证，并同步 `.helloagents` 记录与变更说明 | depends_on: [1.3, 2.1]

---

## 执行日志

| 时间 | 任务 | 状态 | 备注 |
|------|------|------|------|
| 2026-04-24 22:17 | 方案设计 | completed | 已确认采用“列表页确认佣金菜单 + 单行快捷确认 + 保留详情抽屉”方案 |
| 2026-04-24 22:20 | 佣金判定修复 | completed | `orders.ts` 新增真实佣金判定，列表/详情统一改为无佣金不再显示待确认 |
| 2026-04-24 22:22 | 订单页交互增强 | completed | 订单工具栏新增“确认佣金”菜单，佣金状态筛选自动透传 `is_commission=true` |
| 2026-04-24 22:24 | 行级确认接入 | completed | 列表新增操作列，可对真实待确认订单直接确认为“发放中” |
| 2026-04-24 22:26 | 构建与文档同步 | completed | `npm run build` 通过，准备归档方案包并更新知识库记录 |

---

## 执行备注

- 当前后端已具备 `GET /order/fetch?is_commission=true` 与 `POST /order/update` 能力，本轮优先复用现有接口，不新增后端 API。
- 若本地构建外存在后端联调缺口，需以 `admin-frontend` 构建结果作为本轮最低验证证据，并在交付中明确说明。
