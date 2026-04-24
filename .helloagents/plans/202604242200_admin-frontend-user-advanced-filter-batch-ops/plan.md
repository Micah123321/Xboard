# admin-frontend 用户管理高级筛选与批量操作 — 实施规划

## 目标与范围
- 将现有用户管理页从“基础搜索 + 两个快捷筛选”升级为“基础筛选 + 高级筛选 + 批量操作”的真实运营工作台。
- 保持当前 Apple 化后台节奏，不引入厚重的筛选面板或新的视觉体系。

## 架构与实现策略
- 前端拆分为三层：
  - `UsersView.vue`：只负责页面装配、表格渲染与弹层挂载
  - `useUsersManagement.ts`：承接用户页状态、筛选、批量操作与列表刷新逻辑
  - `UserAdvancedFilterDialog.vue` / `UserBatchMailDialog.vue`：分别承接高级筛选与批量邮件输入
- 工具层统一收敛到 `src/utils/users.ts`：
  - 高级筛选字段定义
  - 字段 → 后端过滤条件映射
  - 流量/时间/状态等值转换
  - 已生效筛选摘要生成
- API 层补齐用户批量操作封装：
  - `exportUsersCsv`
  - `sendUsersMail`
  - `batchUpdateUserBan`
- 后端最小改动放在 `App\Http\Controllers\V2\Admin\UserController::ban`：
  - 现有 `/user/ban` 默认仍保持“批量封禁”
  - 新增兼容 `banned=0|1`，以支持“批量恢复正常”

## 完成定义
- 用户页工具条新增“高级筛选”入口，并可查看已生效筛选摘要。
- 高级筛选至少支持以下字段：
  - 邮箱 / 用户 ID / 订阅 / 流量 / 已用流量 / 在线设备 / 到期时间 / UUID / Token / 账号状态 / 备注
- 用户表格支持勾选用户，并能从批量操作菜单触发“发送邮件 / 导出 CSV / 批量封禁 / 恢复正常”。
- 未勾选用户时，批量操作自动作用于当前筛选结果；无筛选条件时明确提示会作用于全部用户。
- 后端 `user/ban` 支持 `banned=0`，前端“恢复正常”可真实落库。

## 文件结构
- `.helloagents/plans/202604242200_admin-frontend-user-advanced-filter-batch-ops/*`
- `admin-frontend/src/api/admin.ts`
- `admin-frontend/src/types/api.d.ts`
- `admin-frontend/src/utils/users.ts`
- `admin-frontend/src/views/users/UsersView.vue`
- `admin-frontend/src/views/users/UsersView.scss`
- `admin-frontend/src/views/users/useUsersManagement.ts`
- `admin-frontend/src/views/users/UserAdvancedFilterDialog.vue`
- `admin-frontend/src/views/users/UserBatchMailDialog.vue`
- `app/Http/Controllers/V2/Admin/UserController.php`

## UI / 设计约束
- 首屏继续保持黑底标题区，不额外加营销式视觉层。
- 高级筛选弹窗采用白色内容面、清晰的条件分组和轻量边框，靠结构区分复杂度，而不是靠重阴影或渐变。
- 批量操作入口采用克制型下拉菜单，贴近用户截图中的纯文本操作列表。
- 已生效筛选摘要使用轻量胶囊标签呈现，帮助用户快速理解当前列表上下文。

## 风险与验证
- 风险 1：高级筛选字段多、值类型混合，若直接散落在视图中容易出现映射漂移，因此统一收口到 `src/utils/users.ts`。
- 风险 2：批量操作的“作用范围”容易误伤全部用户，因此前端必须在执行前展示目标范围。
- 风险 3：批量恢复正常依赖后端接口补齐 `banned=0` 语义，若不改后端只能伪实现，因此必须做最小后端兼容。
- 验证方式：
  - `npm run build`
  - 代码级视觉自检：高级筛选弹窗、批量邮件弹窗、用户列表批量操作入口
  - 批量恢复正常逻辑代码自检：前后端 payload 一致

## 决策记录
- [2026-04-24] 高级筛选采用独立弹窗而不是在工具条横向堆叠字段，以匹配用户截图并控制后台密度。
- [2026-04-24] 批量操作默认优先使用“已勾选用户 > 当前筛选结果 > 全部用户”的三段式作用域规则。
- [2026-04-24] 批量恢复正常沿用 `/user/ban` 现有路由，通过 `banned=0|1` 扩展语义，避免新开重复接口。
