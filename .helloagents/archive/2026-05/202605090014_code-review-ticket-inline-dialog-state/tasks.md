# 任务清单: code-review-ticket-inline-dialog-state

> **@status:** completed | 2026-05-09 00:18

```yaml
@feature: code-review-ticket-inline-dialog-state
@created: 2026-05-09
@status: completed
@mode: DIRECT
```

## LIVE_STATUS

```json
{"status":"completed","completed":3,"failed":0,"pending":0,"total":3,"percent":100,"current":"已归档到 archive/2026-05","updated_at":"2026-05-09 00:18:24","skipped":0,"uncertain":0,"done":3}
```

## 进度概览

| 完成 | 失败 | 跳过 | 总数 |
|------|------|------|------|
| 3 | 0 | 0 | 3 |

---

## 任务列表

### 1. 弹层状态修复

- [√] 1.1 修复共享抽屉 body 挂载层级
  - 文件路径: `admin-frontend/src/views/users/UserFormDrawer.vue`, `admin-frontend/src/views/subscriptions/OrderDetailDrawer.vue`
  - 预期变更: 为两个共享 `ElDrawer` 增加 `append-to-body`，支持在工单弹窗内安全打开。
  - 完成标准: 用户编辑抽屉和订单详情抽屉不再依赖父弹窗 DOM 层级。
  - 验证方式: 代码复核 + `npm run build`
  - depends_on: []

- [√] 1.2 重置工单订单详情弹层状态
  - 文件路径: `admin-frontend/src/views/tickets/TicketOrdersDialog.vue`, `admin-frontend/src/views/tickets/useTicketOrdersDialog.ts`
  - 预期变更: 订单弹窗关闭或隐藏时清理详情抽屉可见状态和当前详情数据。
  - 完成标准: 关闭订单列表后重新打开，不会恢复上一次订单详情抽屉。
  - 验证方式: 代码复核 + `npm run build`
  - depends_on: [1.1]

### 2. 验证与收尾

- [√] 2.1 执行构建验证并归档 review 方案包
  - 文件路径: `admin-frontend`, `.helloagents/plan/202605090014_code-review-ticket-inline-dialog-state`
  - 预期变更: 构建通过，任务状态和归档索引同步。
  - 完成标准: `npm run build` 退出码为 0，方案包迁移至 `archive/2026-05/`。
  - 验证方式: 命令输出 + 方案包路径检查
  - depends_on: [1.2]

---

## 执行日志

| 时间 | 任务 | 状态 | 备注 |
|------|------|------|------|
| 2026-05-09 00:20:00 | 1.1 | completed | `UserFormDrawer` 与 `OrderDetailDrawer` 已增加 `append-to-body` |
| 2026-05-09 00:20:00 | 1.2 | completed | 订单弹窗关闭和隐藏路径会重置详情抽屉状态 |
| 2026-05-09 00:20:00 | 2.1 | completed | `npm run build` 通过 |

---

## 执行备注

- 激进重构方案仅记录在 `proposal.md`，本次默认不执行。
