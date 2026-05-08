# 任务清单: batch-approve-invite-commission

> **@status:** completed | 2026-05-09 00:00

```yaml
@feature: batch-approve-invite-commission
@created: 2026-05-08
@status: completed
@mode: R2
```

## LIVE_STATUS

```json
{"status":"completed","completed":5,"failed":0,"pending":0,"total":5,"percent":100,"current":"已归档到 archive/2026-05","updated_at":"2026-05-09 00:00:32","skipped":0,"uncertain":0,"done":5}
```

## 进度概览

| 完成 | 失败 | 跳过 | 总数 |
|------|------|------|------|
| 5 | 0 | 0 | 5 |

---

## 任务列表

### 1. 后端接口

- [√] 1.1 在 `app/Http/Controllers/V2/Admin/OrderController.php` 实现批量确认佣金方法
  - 预期变更: 新增 `batchConfirmCommission()`，校验 `ids`，只更新符合待确认佣金条件的订单，返回确认/跳过统计。
  - 完成标准: 接口不会更新已发放、无效、无佣金、未完成或无邀请人的订单。
  - 验证方式: `php -l app/Http/Controllers/V2/Admin/OrderController.php`，代码审查筛选条件。
  - depends_on: []

- [√] 1.2 在 `app/Http/Routes/V2/AdminRoute.php` 注册批量确认佣金路由
  - 预期变更: `order` 路由组新增 `POST /batchConfirmCommission`。
  - 完成标准: 前端可通过管理端 API 前缀访问该接口。
  - 验证方式: `php -l app/Http/Routes/V2/AdminRoute.php`，代码审查路由位置。
  - depends_on: [1.1]

### 2. 管理端接入

- [√] 2.1 在 `admin-frontend/src/types/api.d.ts` 和 `admin-frontend/src/api/admin.ts` 增加批量接口类型与封装
  - 预期变更: 新增批量确认响应类型和 `batchConfirmOrderCommissions(ids)` API。
  - 完成标准: TypeScript 可正确推断响应字段，调用路径与后端路由一致。
  - 验证方式: `npm run build`。
  - depends_on: [1.2]

- [√] 2.2 在 `admin-frontend/src/views/subscriptions/OrdersView.vue` 接入多选与批量确认按钮
  - 预期变更: 表格启用 selection 列，维护已选订单，工具条显示批量确认按钮，提交后刷新列表并提示跳过数量。
  - 完成标准: 未选择时按钮禁用；已选但不符合条件的订单交由后端跳过统计；确认后清空选择；单条确认仍可用。
  - 验证方式: `npm run build`，人工检查交互逻辑。
  - depends_on: [2.1]

### 3. 同步与验证

- [√] 3.1 同步知识库并执行验证
  - 预期变更: 更新订单管理相关知识库内容、CHANGELOG 和方案任务状态；运行 PHP 语法检查与前端构建。
  - 完成标准: 关键验证通过或明确记录环境阻塞；方案包任务状态反映真实执行结果。
  - 验证方式: `php -l ...`、`cd admin-frontend && npm run build`、检查 `.helloagents/CHANGELOG.md`。
  - depends_on: [1.1, 1.2, 2.1, 2.2]

---

## 执行日志

| 时间 | 任务 | 状态 | 备注 |
|------|------|------|------|
| 2026-05-08 23:47 | DESIGN | in_progress | 已完成上下文收集与方案包创建 |
| 2026-05-08 23:52 | 1.1 | completed | 后端批量确认方法已实现，事务内锁定订单并返回确认/跳过统计 |
| 2026-05-08 23:52 | 1.2 | completed | 已注册 `order/batchConfirmCommission` 管理端路由 |
| 2026-05-08 23:53 | 2.1-2.2 | completed | 管理端订单页已接入批量选择、批量确认按钮和 API 类型 |
| 2026-05-08 23:55 | 验证 | completed | `php -l` 和 `npm run build` 均通过 |
| 2026-05-08 23:58 | 知识库同步 | completed | 已更新模块文档与 CHANGELOG |

---

## 执行备注

- 用户确认范围: 只批量同意“待审核”的邀请佣金，已同意/已拒绝/异常记录自动跳过，并在前端提示跳过数量。
- 执行模式: INTERACTIVE。
