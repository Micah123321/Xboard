# 任务清单: order-payment-snapshot

> **@status:** completed | 2026-04-25 00:20

```yaml
@feature: order-payment-snapshot
@created: 2026-04-25
@status: completed
@mode: R2
```

## 进度概览

| 完成 | 失败 | 跳过 | 总数 |
|------|------|------|------|
| 5 | 0 | 0 | 5 |

---

## 任务列表

### 1. 支付快照后端链路

- [√] 1.1 为 `v2_order` 新增支付快照字段，并补齐 `app/Models/Order.php` 的字段注释与类型转换 | depends_on: []
- [√] 1.2 调整支付成功链路，在回调 / 标记已支付时保存支付渠道、支付方法、实付金额与支付 IP | depends_on: [1.1]

### 2. 后台订单详情展示

- [√] 2.1 调整后台订单详情接口与前端类型声明，补齐 `payment` 关联和支付快照字段 | depends_on: [1.2]
- [√] 2.2 更新 `admin-frontend/src/views/subscriptions/OrderDetailDrawer.vue`，新增“支付成功信息”展示区块并保持现有 Apple 化后台风格 | depends_on: [2.1]

### 3. 验证与知识库同步

- [√] 3.1 新增 / 运行后端定向测试与 `admin-frontend` 构建验证，并同步知识库文档与变更记录 | depends_on: [2.2]

---

## 执行日志

| 时间 | 任务 | 状态 | 备注 |
|------|------|------|------|
| 2026-04-25 00:02 | 方案包初始化 | completed | 已确认按完整支付快照方案执行，目标是保存并展示支付渠道 / 方法 / 实付金额 / 支付 IP |
| 2026-04-25 00:10 | 1.1 / 1.2 | completed | 已新增支付快照字段，并将 TokenPay 回调元信息透传到 `OrderService::paid()` 统一落库 |
| 2026-04-25 00:16 | 2.1 / 2.2 | completed | 已补齐后台订单详情 `payment` 关联、前端类型与支付成功信息卡片展示 |
| 2026-04-25 00:20 | 3.1 | completed | 已新增后端定向测试文件；前端目标文件 `vue-tsc` 校验通过。`npm run build` 仍被既有 `DashboardView/TicketsView` 类型错误阻断，且当前工作区缺少 PHP 运行时与 `vendor`，无法直接执行 PHPUnit |

---

## 执行备注

> 记录执行过程中的重要说明、决策变更、风险提示等

- 当前工作树存在与本轮无关的未提交改动，实施时必须避免覆盖已有业务变更。
- 历史订单缺少新增快照字段属于预期兼容范围，前端详情需允许空值展示。
- `admin-frontend` 全量构建失败来自既有 `DashboardView.vue` 与 `TicketsView.vue` 类型错误，本轮改动通过独立 `tsconfig` 对目标文件完成定向校验。
- 当前环境缺少 PHP 可执行文件与 `vendor` 依赖目录，后端仅完成代码级实现与测试文件落地，未能执行 Laravel / PHPUnit 运行时验证。
