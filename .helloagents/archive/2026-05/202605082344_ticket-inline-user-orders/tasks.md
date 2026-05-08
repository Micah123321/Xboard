# 任务清单: ticket-inline-user-orders

> **@status:** completed | 2026-05-08 23:59

```yaml
@feature: ticket-inline-user-orders
@created: 2026-05-08
@status: completed
@mode: R2
```

## LIVE_STATUS

```json
{"status":"completed","completed":4,"failed":0,"pending":0,"total":4,"percent":100,"current":"已归档到 archive/2026-05","updated_at":"2026-05-08 23:59:49","skipped":0,"uncertain":0,"done":4}
```

## 进度概览

| 完成 | 失败 | 跳过 | 总数 |
|------|------|------|------|
| 4 | 0 | 0 | 4 |

---

## 任务列表

### 1. 工单用户编辑入口

- [√] 1.1 修改 `admin-frontend/src/views/tickets/TicketWorkspaceDialog.vue`
  - 预期变更: 将“查看用户”路由跳转改为打开当前工单用户编辑抽屉；按需加载套餐列表；保存后刷新工单详情。
  - 完成标准: 点击工单头部“编辑用户”不改变路由，保存成功后仍停留在当前工单会话。
  - 验证方式: `npm run build`；人工核对按钮状态与保存回调。
  - depends_on: []

### 2. 工单订单列表弹窗

- [√] 2.1 新增 `admin-frontend/src/views/tickets/TicketOrdersDialog.vue`
  - 预期变更: 新增用户订单列表弹窗，按 `user_id` 加载订单，支持分页、刷新、打开详情。
  - 完成标准: 弹窗仅展示当前工单用户订单，列表空态、加载态、错误提示和分页可用。
  - 验证方式: `npm run build`；人工核对 `fetchOrders` 参数与列表交互。
  - depends_on: []

- [√] 2.2 在 `TicketOrdersDialog.vue` 复用 `OrderDetailDrawer.vue`
  - 预期变更: 订单详情内继续支持标记已支付、取消订单、佣金状态维护，并在操作完成后刷新列表和详情。
  - 完成标准: 详情动作调用现有订单 API，完成后列表数据同步刷新。
  - 验证方式: `npm run build`；代码审查动作回调和错误处理。
  - depends_on: [2.1]

### 3. 工单工作台整合

- [√] 3.1 修改 `admin-frontend/src/views/tickets/TicketWorkspaceDialog.vue`
  - 预期变更: 将“用户订单”路由跳转改为打开 `TicketOrdersDialog`，保留“流量日志”入口；移除不再使用的路由跳转依赖。
  - 完成标准: 工单头部“编辑用户 / 用户订单 / 流量日志”都在当前工作台覆盖层内打开。
  - 验证方式: `npm run build`；人工核对三个入口不互相影响。
  - depends_on: [1.1, 2.1, 2.2]

---

## 执行日志

| 时间 | 任务 | 状态 | 备注 |
|------|------|------|------|
| 2026-05-08 23:44 | 方案设计 | 完成 | 已确定唯一方案并创建任务清单 |
| 2026-05-08 23:56 | 1.1 | 完成 | 工单内接入用户编辑抽屉，保存后刷新工作台 |
| 2026-05-08 23:57 | 2.1 | 完成 | 新增工单用户订单弹窗、分页、刷新与空态 |
| 2026-05-08 23:58 | 2.2 | 完成 | 复用订单详情抽屉并同步刷新列表和详情 |
| 2026-05-08 23:59 | 3.1 | 完成 | 工单头部入口全部切为内联覆盖层，构建通过 |

---

## 执行备注

> 开发实施阶段不得新增后端接口；用户页和订单页原有工单返回 query 能力保留。
