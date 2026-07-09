# 变更提案: invite-visibility

## 元信息
```yaml
类型: 新功能
方案类型: implementation
优先级: P1
状态: 执行中
创建: 2026-07-10
复杂度: moderate
workflow_mode: INTERACTIVE
```

---

## 1. 需求

### 背景
现有邀请能力分散且不完整：
- 用户端 `vue-xboard-theme-micah` 邀请页仅展示邀请码、佣金统计与佣金明细，**没有**被邀请用户邮箱列表。
- 管理端 `admin-frontend` 用户页「TA的邀请」仅跳转到用户列表并按 `invite_user_id` 过滤，只能看到被邀请用户，**不能**直接看该用户生成的邀请链接，也**不能**一键看该用户邀请产生的订单。
- 后端用户侧仅有 `invite/fetch|save|details`；管理侧有 `invite_user` 关系、订单 `invite_user_id` 过滤，但**没有**面向管理端的邀请码/链接聚合接口。

### 目标
1. 用户前端：邀请页可查看自己邀请到的用户邮箱（及注册时间）。
2. 管理端：管理员可查看指定用户生成的邀请链接，以及该用户邀请到的订单列表；被邀请用户邮箱可查。
3. 仅邀请人本人与管理员可见邮箱，避免越权。

### 约束条件
```yaml
时间约束: 无
性能约束: 概览接口默认最近 20 条，完整列表走分页筛选
兼容性约束: 不破坏现有 invite/fetch|save|details 与用户/订单筛选
业务约束: 不改佣金规则、邀请码生成上限与过期策略
```

### 验收标准
1. 用户登录后邀请页能看到自己邀请注册的用户邮箱与注册时间
2. 管理员在用户管理中可查看该用户生成的邀请链接并复制
3. 管理员可查看该用户邀请产生的订单列表（摘要 + 全部跳转）
4. 管理员可查看被邀请用户邮箱（摘要 + 全部跳转）
5. 非本人/非管理员无法获取他人邀请邮箱
6. 相关自动化测试通过

---

## 2. 方案

### 2.1 方案概述
在现有邀请模型上增量扩展 API 与 UI，不新建表、不新建独立邀请管理页。

### 2.2 方案取舍
**唯一方案理由**
- 被邀请用户：`User.invite_user_id`
- 邀请码：`InviteCode` + `User::codes()`
- 邀请订单：`Order.invite_user_id` + 管理订单 filter 已支持
- 管理端已有「TA的邀请」与订单跳转模式可复用

**放弃路径**
| 路径 | 原因 |
|------|------|
| 新建 Admin 邀请管理页 | 与用户/订单页重复，成本高 |
| 仅前端拼装 | 管理端无邀请码数据源，越权难控 |
| 用户列表行内嵌全部邀请数据 | 列表膨胀与隐私风险 |

### 2.3 技术方案

#### 用户 API
- 新增 `GET /api/v1/user/invite/users`
- `InviteController::users`：`User::where('invite_user_id', authId)`，返回 `email`、`created_at`（可选 plan/banned）
- 分页：`current` + `page_size`，默认 10，最大 100
- 仅当前登录用户自己的邀请关系

#### 管理 API
- 新增 `GET /api/v2/admin/user/inviteInfo?id={userId}`
- 返回：
  - `user`：id/email
  - `codes`：code/status/pv/created_at/`invite_url`
  - `invited_users_count` / `invited_orders_count`
  - `invited_users` 最近 20：id/email/created_at
  - `invited_orders` 最近 20：id/trade_no/email/total_amount/status/commission_status/created_at
- `invite_url` = `rtrim(app_url|host,'/') . '/?code=' . code`
- AdminRoute 注册

#### 用户前端（`E:/code/vue/vue-xboard-theme-micah`）
- API/类型：`getInvitedUsers` + `InvitedUser`
- `invite.vue` 增加「已邀请用户」表格（邮箱、注册时间、分页）

#### 管理前端（`admin-frontend`）
- API/类型：`fetchUserInviteInfo`
- 「TA的邀请」打开邀请信息抽屉：链接复制、被邀请邮箱摘要、邀请订单摘要、查看全部跳转
- `OrdersView` 支持 `invite_user_id`/`invite_user_email` query 作用域（对齐 `user_id`）

#### 测试
- 用户 invite/users 作用域
- 管理 inviteInfo 关键字段

### 2.4 影响范围
| 区域 | 文件（预计） |
|------|-------------|
| 用户 API | `InviteController.php`, `UserRoute.php` |
| 管理 API | `UserController.php`, `AdminRoute.php` |
| 用户前端 | `invite.ts`, `types.ts`, `invite.vue` |
| 管理前端 | `admin.ts`, `api.d.ts`, users/*, `OrdersView.vue` |
| 测试 | `tests/Unit/...` |

### 2.5 风险评估
| 风险 | 等级 | 处理 |
|------|------|------|
| 邮箱 PII 泄露 | 中 | 用户接口 auth scope；管理接口 admin 中间件 |
| 邀请链接域名错误 | 低 | 优先 app_url，回退 host |
| 订单筛选参数不一致 | 低 | 对齐 user_id scoped query |
| 大数据量一次返回 | 低 | 概览限 20 |

### 2.6 回滚边界
无 DB migration。回滚移除新路由/UI 即可，不影响注册与佣金结算。

### 2.7 验证策略
```yaml
verifyMode: test-first
reviewerFocus:
  - 越权：用户不能读他人邀请邮箱
  - 管理端链接生成与复制
  - 订单 invite_user_id 作用域筛选
testerFocus:
  - InviteController::users 分页与过滤
  - Admin UserController::inviteInfo 字段完整性
UI验证:
  - 用户邀请页出现已邀请用户表
  - 管理端邀请抽屉可见链接/用户/订单入口
风险边界:
  - 不改佣金计算
  - 不改邀请码生成上限逻辑
```

### 2.8 成果设计
沿用现有邀请页与管理端用户抽屉视觉体系：
- 用户端：同 `invite-section` 表格区块，标题「已邀请用户」
- 管理端：轻量 Drawer，字段标签：邀请链接 / 已邀请用户 / 邀请订单

---

## 3. 决策记录

### #D001 采用增量 API + 复用现有列表跳转
- 决策: 不新建独立邀请管理模块，扩展 invite/users 与 admin inviteInfo，并增强现有入口
- 原因: 模型与筛选能力已具备，交付最快且风险最低
- 影响: 前后端少量文件改动，无 schema 变更

---

## 4. 技术约束
- 输出语言/UI 文案：中文简洁标签
- 金额字段：后端仍以分为单位，前端展示时 /100
- 时间戳：沿用项目 Unix 秒时间戳

---

## 5. 开放问题
无（INTERACTIVE 模式下实现细节若与现有组件模式冲突，优先复用 TrafficLogDialog/Order 作用域模式）
