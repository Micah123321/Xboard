# 变更提案: admin-ticket-user-order-links

## 元信息
```yaml
类型: 新功能
方案类型: implementation
优先级: P1
状态: 已确认
创建: 2026-05-01
```

---

## 1. 需求

### 背景
管理端工单对话页已经能查看工单用户邮箱、流量日志和会话记录，但排查用户问题时还需要手动切到用户管理或订单管理，再按用户信息重新筛选。用户要求在工单管理的对话页面直接跳转到对应用户，以及该用户的订单列表。

### 目标
- 在工单对话页为当前工单用户增加“查看用户”和“用户订单”入口。
- “查看用户”跳转到用户管理页并精准定位当前用户。
- “用户订单”跳转到订单管理页，并复用订单页已有 `user_id/user_email` 作用域筛选。
- 不改后端接口，不引入新页面，不改变已有流量日志、回复、关闭工单行为。

### 约束条件
```yaml
时间约束: 无
性能约束: 仅增加路由跳转和本地 computed，不增加额外接口请求
兼容性约束: 复用 Vue Router hash 路由和 Element Plus 按钮风格
业务约束: 后端返回的 AdminTicketDetail.user 为跳转真相源；缺少 user.id 时不展示跳转入口
```

### 验收标准
- [ ] 工单对话页当前工单存在用户 ID 时显示“查看用户”和“用户订单”入口。
- [ ] 点击“查看用户”进入 `Users` 路由，用户列表按当前用户 ID 精准筛选，并显示清晰的已生效筛选摘要。
- [ ] 点击“用户订单”进入 `SubscriptionOrders` 路由，订单页按当前用户 ID 精准筛选，并保留用户邮箱提示。
- [ ] `admin-frontend` 构建或类型检查通过；若失败，必须确认失败是否由本次改动引入。

---

## 2. 方案

### 技术方案
在 `TicketWorkspaceDialog.vue` 内引入 `useRouter` 和合适的 Element Plus 图标，为当前 `detail.user` 构造两个路由跳转方法：
- `Users`：携带 `user_id` 和 `user_email`。
- `SubscriptionOrders`：携带 `user_id` 和 `user_email`。

在 `useUserScopedActions.ts` 中补齐用户管理页的本人作用域：
- 读取 `route.query.user_id/user_email`。
- 生成 `AdminUserFilter`：`{ id: 'id', value: 'eq:{user_id}' }`。
- 在筛选摘要中显示“用户：邮箱或 #ID”。
- `clearScopedUserQuery()` 清除本人作用域。

在 `useUsersManagement.ts` 中把本人作用域合并到 `appliedFilters` 与 `appliedFilterSummaries`，并让重置筛选、路由监听同时处理 `user_id/user_email` 与既有 `invite_user_id/invite_user_email`。

### 影响范围
```yaml
涉及模块:
  - admin-frontend 工单工作台: 新增跨页面跳转入口
  - admin-frontend 用户管理: 新增 user_id/user_email 本人作用域筛选
  - admin-frontend 订单管理: 复用已有 user_id/user_email 订单作用域筛选，无需改动
预计变更文件: 3
```

### 风险评估
| 风险 | 等级 | 应对 |
|------|------|------|
| 用户页原有邀请人作用域与新增本人作用域互相污染 | 中 | 分别维护 `scopedUser*` 和 `scopedInvite*`，清理函数显式删除各自 query |
| 工单用户字段缺失导致跳转参数为空 | 低 | 入口仅在 `detail.user.id` 存在时展示，邮箱作为可选提示 |
| 构建失败来自历史遗留问题 | 中 | 运行 `npm run build` 并记录具体失败归因 |

### 方案取舍
```yaml
唯一方案理由: 复用现有路由和列表筛选机制，改动最小，能保持用户/订单页各自的筛选真相源。
放弃的替代路径:
  - 在工单页内嵌用户/订单抽屉: 会复制用户和订单工作台逻辑，增加维护成本。
  - 新增后端接口返回用户订单摘要: 当前需求是跳转到订单管理，不需要扩展 API。
  - 仅按邮箱关键字跳用户页: 精准度低，邮箱变化或模糊匹配时容易误命中。
回滚边界: 回退 TicketWorkspaceDialog.vue、useUserScopedActions.ts、useUsersManagement.ts 的本次改动即可恢复原行为。
```

---

## 3. 技术设计

### 路由参数
```yaml
Users:
  name: Users
  query:
    user_id: 当前工单 user.id
    user_email: 当前工单 user.email
SubscriptionOrders:
  name: SubscriptionOrders
  query:
    user_id: 当前工单 user.id
    user_email: 当前工单 user.email
```

### 用户页筛选映射
| query | AdminUserFilter | 摘要 |
|------|------------------|------|
| `user_id=123&user_email=a@b.com` | `{ id: 'id', value: 'eq:123' }` | `用户：a@b.com` |
| `invite_user_id=123&invite_user_email=a@b.com` | `{ id: 'invite_user_id', value: 'eq:123' }` | `邀请人：a@b.com` |

---

## 4. 核心场景

### 场景: 工单对话跳转到用户与订单
**模块**: admin-frontend
**条件**: 管理员打开工单管理对话页，当前工单详情包含 `user.id`。
**行为**: 管理员点击“查看用户”或“用户订单”。
**结果**: 前者进入用户管理并精准筛选该用户，后者进入订单管理并展示该用户订单。

---

## 5. 技术决策

### admin-ticket-user-order-links#D001: 使用路由 query 承载跨页面用户作用域
**日期**: 2026-05-01
**状态**: 采纳
**背景**: 工单、用户、订单三处均为管理端前端页面，订单页已经支持 `user_id/user_email` query 过滤，用户页只缺本人作用域。
**选项分析**:
| 选项 | 优点 | 缺点 |
|------|------|------|
| A: 复用路由 query | 与现有订单页一致，可刷新/分享，改动小 | 需要在用户页补齐 query 监听和清理 |
| B: 全局 store 临时传参 | 不污染 URL | 刷新丢失状态，不利于定位 |
| C: 工单页内嵌详情 | 一页完成更多操作 | 重复用户/订单页面逻辑，范围扩大 |
**决策**: 选择方案 A。
**理由**: 当前项目已有相同模式，URL 可见、可恢复，且不需要新增后端或全局状态。
**影响**: 用户管理页新增 `user_id/user_email` 作用域，订单页沿用既有行为。

---

## 6. 验证策略

```yaml
verifyMode: review-first
reviewerFocus:
  - admin-frontend/src/views/tickets/TicketWorkspaceDialog.vue
  - admin-frontend/src/views/users/useUserScopedActions.ts
  - admin-frontend/src/views/users/useUsersManagement.ts
testerFocus:
  - npm run build
  - 静态检查工单跳转目标 name 与 router/index.ts 一致
  - 静态检查用户页 user_id 筛选与订单页 user_id 筛选参数一致
uiValidation: optional
riskBoundary:
  - 不改 Laravel 后端接口
  - 不改订单页筛选契约
  - 不执行生产部署或推送
```

---

## 7. 成果设计

### 设计方向
- **美学基调**: 延续当前 Apple-style 管理后台的克制工具栏风格，新增入口以轻量文字按钮出现在对话页头部操作区。
- **记忆点**: 工单头部形成“用户排查三联入口”：查看用户、用户订单、流量日志。
- **参考**: 现有 `ghost-action` 按钮和 Element Plus 图标。

### 视觉要素
- **配色**: 沿用 `var(--xboard-link)` 与现有危险操作红色，不增加新色。
- **字体**: 沿用项目系统字体栈，保持管理端一致性。
- **布局**: 头部右侧按钮横向排列，移动端沿现有 header 响应式折行。
- **动效**: 复用现有按钮 hover/focus 反馈，不新增装饰性动效。
- **氛围**: 不新增卡片或背景装饰，保持工单工作台信息密度。

### 技术约束
- **可访问性**: 使用真实 `ElButton`，按钮文本明确。
- **响应式**: 依赖现有 `.workspace-header__actions` flex wrap 行为，必要时补充 wrap。
