# 任务清单: admin-frontend-ticket-return-manual-subscribe-copy

> **@status:** completed | 2026-05-07 01:52

```yaml
@feature: admin-frontend-ticket-return-manual-subscribe-copy
@created: 2026-05-07
@status: completed
@mode: R2
```

## LIVE_STATUS

```json
{"status":"completed","completed":5,"failed":0,"pending":0,"total":5,"percent":100,"current":"已归档到 archive/2026-05","updated_at":"2026-05-07 01:52:52","skipped":0,"uncertain":0,"done":5}
```

## 进度概览

| 完成 | 失败 | 跳过 | 总数 |
|------|------|------|------|
| 5 | 0 | 0 | 5 |

---

## 任务列表

### 1. 订阅地址手动复制

- [√] 1.1 修改 `admin-frontend/src/views/users/useUsersManagement.ts`
  - 预期变更: 为复制订阅地址增加手动复制弹窗；Clipboard API 不可用或写入失败时展示 readonly textarea。
  - 完成标准: `copySubscribeUrl()` 成功复制仍显示成功提示，失败路径弹窗展示当前用户 `subscribe_url`。
  - 验证方式: 代码审查 + `npm run build`。
  - depends_on: []

### 2. 工单返回上下文

- [√] 2.1 新增 `admin-frontend/src/views/tickets/useTicketReturnLink.ts`
  - 预期变更: 封装 `ticket_return_id/ticket_return_subject` query 解析、返回按钮文案、`returnToTicket()` 导航方法。
  - 完成标准: 用户页和订单页可复用同一逻辑，返回时跳转 `Tickets` 并携带 `ticket_id`。
  - 验证方式: 代码审查 + TypeScript 构建。
  - depends_on: []

- [√] 2.2 修改 `admin-frontend/src/views/tickets/TicketWorkspaceDialog.vue`
  - 预期变更: “查看用户”和“用户订单”跳转 query 中追加原工单 ID 与标题。
  - 完成标准: 目标页能通过 query 识别来自工单会话。
  - 验证方式: 代码审查 + TypeScript 构建。
  - depends_on: [2.1]

- [√] 2.3 修改 `admin-frontend/src/views/tickets/TicketsView.vue`
  - 预期变更: 支持读取 `ticket_id` query 并自动打开对应工单会话；query 变化时同步弹窗。
  - 完成标准: `Tickets?ticket_id={id}` 进入页面后 `TicketWorkspaceDialog` 自动打开并加载该 ID。
  - 验证方式: 代码审查 + TypeScript 构建。
  - depends_on: [2.1]

### 3. 目标页返回按钮

- [√] 3.1 修改 `admin-frontend/src/views/users/UsersView.vue`、`UsersView.scss`、`admin-frontend/src/views/subscriptions/OrdersView.vue`、`OrdersView.scss`
  - 预期变更: 用户页与订单页在存在工单返回上下文时展示“返回工单聊天”按钮，点击后回到原工单。
  - 完成标准: 按钮不影响现有筛选/提示/分页布局，移动端可正常堆叠。
  - 验证方式: 代码审查 + `npm run build`。
  - depends_on: [2.1, 2.2, 2.3]

---

## 执行日志

| 时间 | 任务 | 状态 | 备注 |
|------|------|------|------|
| 2026-05-07 01:40 | DESIGN | completed | 方案包已创建并填充 |
| 2026-05-07 01:47 | 1.1 | completed | 复制订阅地址失败路径改为手动复制弹窗 |
| 2026-05-07 01:50 | 2.1-2.3 | completed | 工单返回 query 与自动打开工单会话已接入 |
| 2026-05-07 01:52 | 3.1 | completed | 用户页与订单页已展示返回工单聊天按钮 |
| 2026-05-07 01:54 | 验证 | completed | `ADMIN_BUILD_OUT_DIR=dist-verify npm run build` 通过，临时目录已清理 |

---

## 执行备注

- 任务复杂度: moderate。涉及多个前端文件，但不需要多方案对比或后端改动。
- UI 方案沿用项目现有 Apple 风格和 Element Plus 控件，不引入新依赖。
