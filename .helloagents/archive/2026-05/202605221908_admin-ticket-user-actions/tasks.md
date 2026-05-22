# 任务清单: admin-ticket-user-actions

> **@status:** completed | 2026-05-22 19:22

```yaml
@feature: admin-ticket-user-actions
@created: 2026-05-22
@status: completed
@mode: R2
```

## LIVE_STATUS

```json
{"status":"completed","completed":5,"failed":0,"pending":0,"total":5,"percent":100,"current":"已归档到 archive/2026-05","updated_at":"2026-05-22 19:22:00","skipped":0,"uncertain":0,"done":5}
```

## 进度概览

| 完成 | 失败 | 跳过 | 总数 |
|------|------|------|------|
| 5 | 0 | 0 | 5 |

---

## 任务列表

### 1. 共享用户动作

- [√] 1.1 新增 `admin-frontend/src/views/users/useUserRowActions.ts`
  - 预期变更: 抽出复制订阅 URL、重置 UUID 及订阅 URL、封禁/恢复用户、重置流量、查看订单、查看邀请、打开流量日志、打开分配订单等用户行级动作。
  - 完成标准: 用户管理页和工单页都能导入同一个 composable；敏感动作保留确认弹窗；动作成功后支持回调刷新调用方数据。
  - 验证方式: `npm run build`
  - depends_on: []

### 2. 用户管理页复用

- [√] 2.1 调整 `admin-frontend/src/views/users/useUsersManagement.ts`
  - 预期变更: 移除重复的用户行级动作实现，改为调用 `useUserRowActions.ts`，保留列表筛选、分页、批量操作和抽屉状态。
  - 完成标准: `UsersView.vue` 的更多操作菜单命令保持不变，原有弹窗和刷新行为不退化。
  - 验证方式: `npm run build`
  - depends_on: [1.1]

### 3. 工单工作台增强

- [√] 3.1 调整 `admin-frontend/src/views/tickets/TicketWorkspaceDialog.vue`
  - 预期变更: 增加“用户操作”下拉菜单，补齐截图中的常用动作，并新增进入用户管理的返回链路；必要时挂载分配订单抽屉。
  - 完成标准: 工单详情有用户时可执行编辑、分配订单、分配流量、复制订阅 URL、重置 UUID 及订阅 URL、TA 的订单、TA 的邀请、TA 的流量记录、重置流量、封禁/恢复、进入用户管理。
  - 验证方式: `npm run build`；人工检查模板中菜单项和命令映射。
  - depends_on: [1.1]

- [√] 3.2 调整 `admin-frontend/src/views/tickets/TicketWorkspaceDialog.scss`
  - 预期变更: 优化工单工作台头部操作区换行、下拉触发按钮和危险动作样式。
  - 完成标准: 新增入口在桌面和窄屏下不挤压标题或会话区域。
  - 验证方式: `npm run build`
  - depends_on: [3.1]

### 4. 验证与知识库

- [√] 4.1 验证构建并同步知识库
  - 预期变更: 运行 admin-frontend 构建验证；更新 `.helloagents/modules/admin-frontend.md` 与 `CHANGELOG.md`，记录工单用户操作增强。
  - 完成标准: 构建通过；知识库描述与代码事实一致；方案任务状态更新。
  - 验证方式: `npm run build`；检查方案包任务状态和知识库条目。
  - depends_on: [2.1, 3.1, 3.2]

---

## 执行日志

| 时间 | 任务 | 状态 | 备注 |
|------|------|------|------|
| 2026-05-22 19:08 | DESIGN | completed | 已完成上下文收集与方案规划 |
| 2026-05-22 19:17 | 1.1 | completed | 新增共享用户行级动作 composable |
| 2026-05-22 19:18 | 2.1 | completed | 用户管理页更多操作改为复用共享动作 |
| 2026-05-22 19:19 | 3.1-3.2 | completed | 工单工作台补齐用户操作菜单、用户管理跳转和分配订单抽屉 |
| 2026-05-22 19:20 | 验证 | completed | `npm run build` 通过；Vite 路由加载和登录重定向通过 Playwright 快照验证 |

---

## 执行备注

- 本方案不修改后端接口，所有用户操作复用现有管理端 API。
- `public/assets/admin` 当前已有未提交变更，本轮不主动回退或覆盖非必要构建产物。
- 浏览器验证在未登录状态下确认 `#/tickets?ticket_id=1` 会重定向到登录页并保留返回参数；真实工单弹窗需带后台登录态和后端 API 联调。
