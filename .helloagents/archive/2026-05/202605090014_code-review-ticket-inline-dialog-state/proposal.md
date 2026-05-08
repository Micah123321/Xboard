# 变更提案: code-review-ticket-inline-dialog-state

## 元信息
```yaml
类型: 修复
方案类型: implementation
优先级: P1
状态: 已完成
创建: 2026-05-09
selected_plan: conservative
```

---

## 1. 审查范围

审查对象为本轮工单页内联能力相关改动：

- `admin-frontend/src/views/tickets/TicketWorkspaceDialog.vue`
- `admin-frontend/src/views/tickets/TicketOrdersDialog.vue`
- `admin-frontend/src/views/tickets/useTicketOrdersDialog.ts`
- `admin-frontend/src/views/tickets/TicketOrdersDialog.scss`
- 被本轮新增链路复用的抽屉组件：`UserFormDrawer.vue`、`OrderDetailDrawer.vue`

---

## 2. 审查结果摘要

### medium

- `TicketWorkspaceDialog.vue:427` 在工单详情弹窗内挂载 `UserFormDrawer`，但 `UserFormDrawer.vue:129` 的 `ElDrawer` 未设置 `append-to-body`。抽屉进入嵌套弹层后可能受到父弹窗层级、遮罩和滚动容器影响，表现为遮罩层级异常或内容被父弹窗裁剪。
- `TicketOrdersDialog.vue:208` 在订单列表弹窗内挂载 `OrderDetailDrawer`，但 `OrderDetailDrawer.vue:129` 的 `ElDrawer` 未设置 `append-to-body`。这会产生同类嵌套弹层层级风险。
- `TicketOrdersDialog.vue:51` 关闭订单弹窗时只向父组件同步 `visible=false`，未重置 `useTicketOrdersDialog.ts` 中的 `detailVisible/detailOrder`。如果用户先打开订单详情再关闭订单列表，重新打开订单列表时可能恢复旧详情抽屉状态。

### low

- `TicketOrdersDialog.vue` 的打开监听和分页监听在从非第一页重新打开时可能触发两次列表请求。该模式与现有流量日志弹窗类似，暂不纳入默认修复，避免扩大改动面。

---

## 3. 保守方案 conservative_plan

- 给 `UserFormDrawer` 和 `OrderDetailDrawer` 的 `ElDrawer` 增加 `append-to-body`，让共享抽屉在原页面和工单嵌套弹窗场景下都挂载到 body 层。
- 在订单弹窗关闭或 `visible` 变为 false 时重置订单详情状态，避免重开后出现旧详情抽屉。
- 保持接口、列表筛选、订单操作和样式结构不变。

## 4. 激进方案 aggressive_plan

- 抽象统一的弹层状态管理 composable，集中处理所有 `ElDialog`/`ElDrawer` 的挂载层级、关闭清理和跨弹层状态。
- 合并订单页与工单订单弹窗的详情操作逻辑，减少重复实现。
- 该方案改动范围更大，涉及共享订单管理链路；本次 review 默认不执行。

## 5. 方案取舍

默认选择 `conservative`。本次问题集中在新嵌套场景的弹层层级和状态清理，保守方案可以直接修复用户可见风险，且不会改动订单接口、用户编辑提交逻辑或现有页面信息架构。激进方案仅作为后续重构备选。

---

## 6. 影响范围

```yaml
涉及模块:
  - admin-frontend/tickets: 工单详情内联用户编辑和订单列表
  - admin-frontend/users: 用户表单抽屉挂载层级
  - admin-frontend/subscriptions: 订单详情抽屉挂载层级
预计变更文件: 4
```

## 7. 风险评估

| 风险 | 等级 | 应对 |
|------|------|------|
| 共享抽屉增加 `append-to-body` 后影响原独立页面层级 | 低 | Element Plus 弹层推荐 body 挂载用于嵌套场景，原页面行为保持同一 `v-model` 控制 |
| 关闭订单弹窗时重置详情状态影响未完成操作 | 低 | 仅关闭详情展示状态，不修改订单操作 API 与提交逻辑 |

---

## 8. 验证策略

```yaml
verifyMode: review-first
reviewerFocus:
  - 嵌套弹层挂载层级
  - 订单详情状态生命周期
testerFocus:
  - npm run build
  - 代码级复核关闭订单弹窗后的详情状态重置路径
uiValidation: code-review
riskBoundary:
  - 不改动后端接口
  - 不改动订单筛选字段与操作 API
  - 不清理无关工作区改动
```
