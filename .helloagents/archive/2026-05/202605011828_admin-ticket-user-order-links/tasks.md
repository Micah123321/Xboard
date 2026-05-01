# 任务清单: admin-ticket-user-order-links

> **@status:** completed | 2026-05-01 18:33

```yaml
@feature: admin-ticket-user-order-links
@created: 2026-05-01
@status: completed
@mode: R2
```

## LIVE_STATUS

```json
{"status":"completed","completed":4,"failed":0,"pending":0,"total":4,"percent":100,"current":"代码实现、构建验证和知识库同步已完成","updated_at":"2026-05-01 18:40:00"}
```

## 进度概览

| 完成 | 失败 | 跳过 | 总数 |
|------|------|------|------|
| 4 | 0 | 0 | 4 |

---

## 任务列表

### 1. 工单对话页跳转入口

- [√] 1.1 修改 `admin-frontend/src/views/tickets/TicketWorkspaceDialog.vue`
  - 预期变更: 引入路由能力和图标，在对话页头部为当前工单用户增加“查看用户”和“用户订单”按钮。
  - 完成标准: 当前 `detail.user.id` 存在时两个按钮可见，点击后分别跳转到 `Users` 与 `SubscriptionOrders`，并携带 `user_id/user_email`。
  - 验证方式: 静态检查 route name 与 `router/index.ts` 一致；构建验证。
  - depends_on: []

### 2. 用户管理本人作用域

- [√] 2.1 修改 `admin-frontend/src/views/users/useUserScopedActions.ts`
  - 预期变更: 新增 `user_id/user_email` query 读取、本人精准筛选、摘要和清理函数，保留现有邀请人作用域。
  - 完成标准: `user_id` 会转换为 `{ id: 'id', value: 'eq:{id}' }`，`user_email` 仅用于显示摘要。
  - 验证方式: 静态检查 computed 输出和清理函数；构建验证。
  - depends_on: [1.1]

- [√] 2.2 修改 `admin-frontend/src/views/users/useUsersManagement.ts`
  - 预期变更: 将本人作用域合并进 `appliedFilters/appliedFilterSummaries`，重置筛选和路由监听覆盖 `user_id/user_email`。
  - 完成标准: 从工单跳转到用户页后会按用户 ID 精准筛选，重置筛选会清除本人和邀请人作用域。
  - 验证方式: 静态检查 fetchUsers 参数来源；构建验证。
  - depends_on: [2.1]

### 3. 验证与知识库同步

- [√] 3.1 执行前端验证并同步知识库
  - 预期变更: 运行 `npm run build`，根据结果更新 `.helloagents/modules/admin-frontend.md` 和 `CHANGELOG.md`。
  - 完成标准: 构建通过，或明确记录阻断原因及是否与本次改动相关；知识库反映新增工单跳转行为。
  - 验证方式: 命令输出、知识库 diff、自检本次修改文件。
  - depends_on: [1.1, 2.1, 2.2]

---

## 执行日志

| 时间 | 任务 | 状态 | 备注 |
|------|------|------|------|
| 2026-05-01 18:40 | DEVELOP | completed | npm run build 通过，知识库已同步 |
| 2026-05-01 18:34 | DEVELOP | completed | 已完成 1.1、2.1、2.2 前端改动 |
| 2026-05-01 18:28 | DESIGN | completed | 已创建方案包并填充规划 |

---

## 执行备注

- 订单页已有 `user_id/user_email` 作用域筛选，本次不修改订单页。
- 本次不涉及后端接口和生产部署。
