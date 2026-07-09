# 任务清单: invite-visibility

```yaml
@feature: invite-visibility
@created: 2026-07-10
@status: completed
@mode: R2
```

## 进度概览

| 完成 | 失败 | 跳过 | 总数 |
|------|------|------|------|
| 7 | 0 | 0 | 7 |

---

## 任务列表

### 1. 后端 API

- [√] 1.1 在 `app/Http/Controllers/V1/User/InviteController.php` 与 `app/Http/Routes/V1/UserRoute.php` 中实现已邀请用户列表
  - 预期变更: 新增 `users()` 分页接口，仅返回当前用户邀请的用户 email/created_at；注册路由 `GET /user/invite/users`
  - 完成标准: 登录用户可分页获取自己邀请的用户；不能越权看到他人邀请
  - 验证方式: 单元测试 + 路由存在性检查
  - depends_on: []

- [√] 1.2 在 `app/Http/Controllers/V2/Admin/UserController.php` 与 `app/Http/Routes/V2/AdminRoute.php` 中实现用户邀请概览
  - 预期变更: 新增 `inviteInfo(id)`，返回 codes(+invite_url)、invited_users 摘要、invited_orders 摘要与计数
  - 完成标准: 管理员按用户 ID 可取到邀请链接与邀请关系摘要
  - 验证方式: 单元测试
  - depends_on: []

- [√] 1.3 在 `tests/Unit/` 中补充后端单元测试
  - 预期变更: 覆盖用户 invite/users 作用域与管理 inviteInfo 关键字段
  - 完成标准: 相关测试通过
  - 验证方式: phpunit 指定文件
  - depends_on: [1.1, 1.2]

### 2. 用户前端

- [√] 2.1 在 `E:/code/vue/vue-xboard-theme-micah/src/api/modules/invite.ts` 与 `src/api/types.ts` 中增加 API/类型
  - 预期变更: 增加 `getInvitedUsers` 与 `InvitedUser` 类型
  - 完成标准: 类型完整、请求参数对齐后端
  - 验证方式: 静态检查
  - depends_on: [1.1]

- [√] 2.2 在 `E:/code/vue/vue-xboard-theme-micah/src/views/finance/invite.vue` 中展示已邀请用户
  - 预期变更: 新增「已邀请用户」表格（邮箱、注册时间、分页）
  - 完成标准: 页面可加载并展示邀请用户邮箱
  - 验证方式: 代码审查 + 类型检查
  - depends_on: [2.1]

### 3. 管理前端

- [√] 3.1 在 `admin-frontend/src/api/admin.ts`、`admin-frontend/src/types/api.d.ts`、`OrdersView.vue` 中增加 API 与订单作用域
  - 预期变更: `fetchUserInviteInfo`；订单页支持 `invite_user_id`/`invite_user_email` query 作用域
  - 完成标准: 可请求邀请概览；订单页可按邀请人筛选
  - 验证方式: 类型检查
  - depends_on: [1.2]

- [√] 3.2 在 `admin-frontend/src/views/users/*` 中实现邀请抽屉与入口
  - 预期变更: 「TA的邀请」打开邀请信息抽屉，展示链接复制、被邀请邮箱摘要、邀请订单摘要及查看全部跳转
  - 完成标准: 管理员可在用户页完成三类查看动作
  - 验证方式: 类型检查 + 交互代码审查
  - depends_on: [3.1]

---

## 执行日志

| 时间 | 事件 | 详情 |
|------|------|------|
| 2026-07-10 01:12:36 | 进度快照(自动) | 完成:0 失败:0 跳过:0 待做:7 (0%) |
| 2026-07-10 01:13:30 | 进度快照(自动) | 完成:0 失败:0 跳过:0 待做:7 (0%) |
| 2026-07-10 01:14:37 | 进度快照(自动) | 完成:0 失败:0 跳过:0 待做:7 (0%) |
| 2026-07-10 01:14:51 | 进度快照(自动) | 完成:0 失败:0 跳过:0 待做:7 (0%) |
| 2026-07-10 01:15:05 | 进度快照(自动) | 完成:0 失败:0 跳过:0 待做:7 (0%) |
| 2026-07-10 01:25:00 | 开发完成 | 7/7 任务完成；phpunit 7 tests OK；admin-frontend vue-tsc 通过 |
