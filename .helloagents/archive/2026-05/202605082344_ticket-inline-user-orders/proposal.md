# 变更提案: ticket-inline-user-orders

## 元信息
```yaml
类型: 新功能
方案类型: implementation
优先级: P1
状态: 已规划
创建: 2026-05-08
```

---

## 1. 需求

### 背景
管理端工单工作台已有“流量日志”弹窗，管理员可以在不离开工单会话的情况下查看排查信息。但“查看用户”和“用户订单”当前会跳转到独立用户页 / 订单页，处理工单时上下文会被打断。

### 目标
- 在工单工作台内直接打开当前工单用户的编辑入口。
- 在工单工作台内直接打开当前工单用户的订单列表，并支持查看订单详情与现有订单动作。
- 保留现有用户页、订单页和工单返回 query 机制，不做路由级重构。

### 约束条件
```yaml
时间约束: 本轮完成前端交互闭环
性能约束: 弹窗仅在打开时请求用户订单和套餐元数据
兼容性约束: 继续复用现有 Vue3 / TypeScript / Element Plus 栈
业务约束: 不新增后端接口，订单与用户字段以现有 API 类型为准
```

### 验收标准
- [ ] 工单弹窗内点击“编辑用户”直接打开用户编辑面板，不跳转路由。
- [ ] 保存用户资料后刷新当前工单详情，工单头部用户信息保持最新。
- [ ] 工单弹窗内点击“用户订单”直接打开该用户订单列表，不跳转路由。
- [ ] 订单列表支持分页、刷新、打开订单详情，并复用标记已支付 / 取消订单 / 佣金状态维护逻辑。
- [ ] `npm run build` 通过。

---

## 2. 方案

### 技术方案
- 在 `TicketWorkspaceDialog.vue` 中将原路由跳转按钮改为内联弹窗开关。
- 复用 `UserFormDrawer.vue` 作为当前工单用户编辑入口，打开前按需加载套餐列表，保存成功后刷新工单详情。
- 新增 `TicketOrdersDialog.vue`，按当前工单用户 ID 调用 `fetchOrders`，在弹窗内渲染用户订单列表，并复用 `OrderDetailDrawer.vue` 处理详情和订单动作。
- 保持 `TrafficLogDialog.vue` 的弹窗模式不变，让三个排查入口在同一工作台内形成一致入口。

### 影响范围
```yaml
涉及模块:
  - admin-frontend/tickets: 工单工作台入口与新增用户订单弹窗
  - admin-frontend/users: 复用现有用户编辑抽屉
  - admin-frontend/subscriptions: 复用订单详情抽屉与订单工具函数
预计变更文件: 4-5
```

### 风险评估
| 风险 | 等级 | 应对 |
|------|------|------|
| 工单弹窗内再打开抽屉 / 弹窗导致层级混乱 | 中 | 保持订单列表使用 `ElDialog append-to-body`，用户编辑复用现有 `ElDrawer`，避免嵌套在工单 DOM 内 |
| 用户保存后工单详情缓存不刷新 | 中 | 用户保存成功后调用 `refreshWorkspace()` 并通知父页面刷新 |
| 订单详情动作与订单列表状态不同步 | 中 | 详情动作完成后同时刷新订单列表与当前详情 |
| 后端列表字段不足 | 低 | 列表只展示 `AdminOrderListItem` 已定义字段，详情继续通过 `getOrderDetail` 补齐 |

### 方案取舍
```yaml
唯一方案理由: 当前需求是工单内联操作，复用现有用户编辑抽屉和订单详情抽屉能保持业务逻辑一致，同时减少后端接口与路由改动。
放弃的替代路径:
  - 继续跳转用户页 / 订单页: 无法满足“不离开工单”的核心目标。
  - 把完整用户管理页和订单管理页嵌入工单弹窗: 交互过重，且会复制筛选、批量操作和路由状态。
  - 新增专用后端聚合接口: 当前 API 已满足列表与详情需求，新增接口会扩大后端影响范围。
回滚边界: 可独立回退新增 TicketOrdersDialog 与 TicketWorkspaceDialog 按钮改动；不影响用户页、订单页和后端接口。
```

---

## 3. 技术设计

### 组件结构
```text
TicketWorkspaceDialog.vue
  ├─ UserFormDrawer.vue (编辑当前工单用户)
  ├─ TicketOrdersDialog.vue (当前工单用户订单列表)
  │   └─ OrderDetailDrawer.vue
  └─ TrafficLogDialog.vue
```

### 数据流
- 用户编辑: `detail.user` -> `UserFormDrawer` -> `updateUser` -> `refreshWorkspace()`
- 订单列表: `detail.user.id` -> `fetchOrders(filter user_id)` -> `TicketOrdersDialog`
- 订单详情动作: `getOrderDetail` / `markOrderPaid` / `cancelOrder` / `updateOrderCommissionStatus` -> 刷新列表与详情

---

## 4. 核心场景

### 场景: 工单内编辑用户资料
**模块**: admin-frontend/tickets  
**条件**: 工单详情包含有效 `detail.user.id`  
**行为**: 管理员点击“编辑用户”并保存表单  
**结果**: 用户资料更新成功，工单详情重新加载，管理员仍停留在原工单会话。

### 场景: 工单内查看用户订单
**模块**: admin-frontend/tickets  
**条件**: 工单详情包含有效 `detail.user.id`  
**行为**: 管理员点击“用户订单”  
**结果**: 弹窗展示该用户订单列表，可分页、刷新、查看详情并执行订单详情动作。

---

## 5. 技术决策

### ticket-inline-user-orders#D001: 工单工作台采用内联弹窗复用现有业务组件
**日期**: 2026-05-08  
**状态**: ✅采纳  
**背景**: 工单处理需要同时查看会话、用户资料、订单与流量日志，路由跳转会打断上下文。  
**选项分析**:
| 选项 | 优点 | 缺点 |
|------|------|------|
| A: 复用现有抽屉 / 详情组件并新增订单列表弹窗 | 业务逻辑一致，改动集中，风险低 | 工单弹窗内会出现多层覆盖组件 |
| B: 嵌入完整用户页 / 订单页 | 功能最完整 | 交互过重，路由与批量状态复杂 |
| C: 仅保留跳转并优化返回 | 改动小 | 不满足当前需求 |
**决策**: 选择方案 A  
**理由**: 最小化后端影响，同时满足工单内完成用户编辑与订单排查的核心目标。  
**影响**: 工单工作台新增两个内联操作入口，用户页与订单页继续保留原有能力。

---

## 6. 验证策略

```yaml
verifyMode: review-first
reviewerFocus:
  - admin-frontend/src/views/tickets/TicketWorkspaceDialog.vue
  - admin-frontend/src/views/tickets/TicketOrdersDialog.vue
testerFocus:
  - npm run build
  - 工单详情中编辑用户后刷新当前工单
  - 工单详情中打开用户订单并查看订单详情
uiValidation: optional
riskBoundary:
  - 不新增或修改后端接口
  - 不删除现有用户页 / 订单页的工单返回 query 能力
  - 不引入新的前端依赖
```

---

## 7. 成果设计

### 设计方向
- **美学基调**: Apple 化运营弹窗。沿用黑白主场、系统字体、蓝色交互和轻量表格，保持工单工作台的安静密度。
- **记忆点**: 工单顶部三个排查入口都以同一工作台覆盖层展开，管理员不离开会话即可处理用户与订单。
- **参考**: 现有 `TrafficLogDialog.vue`、`OrderDetailDrawer.vue` 与 `.helloagents/DESIGN.md`。

### 视觉要素
- **配色**: `#ffffff` / `#fbfbfd` 表面，`#0071e3` 作为唯一强调色，语义色仅用于订单状态。
- **字体**: 继续使用项目全局 `--xboard-font-sans`，与管理端 Apple 风格一致。
- **布局**: 订单列表弹窗采用头部身份信息、紧凑工具条、表格、分页四段式结构；用户编辑直接复用现有抽屉表单。
- **动效**: 使用 Element Plus 默认弹窗 / 抽屉过渡，保留按钮 hover 和 loading 状态。
- **氛围**: 低阴影、轻边界和浅灰表头，避免额外装饰层。

### 技术约束
- **可访问性**: 操作按钮保留文本标签与键盘焦点；危险动作继续走确认框。
- **响应式**: 订单弹窗宽度使用 `min(960px, 94vw)`，窄屏下工具条和分页纵向排列。
