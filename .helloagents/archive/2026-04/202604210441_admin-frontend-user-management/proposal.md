# 变更提案: admin-frontend-user-management

## 元信息
```yaml
类型: 新功能
方案类型: implementation
优先级: P1
状态: 已完成
创建: 2026-04-21
```

---

## 1. 需求

### 背景
当前 `admin-frontend` 已完成登录、主布局与仪表盘，但业务路由仍只有 `/dashboard`。用户本轮明确要求继续沿 [.claude/plan/admin-frontend-login.md](/E:/code/php/Xboard-new/.claude/plan/admin-frontend-login.md) 推进，按照 [apple/DESIGN.md](/E:/code/php/Xboard-new/apple/DESIGN.md) 的 Apple 风格，为后台补齐“用户管理 / 工单管理”入口，且优先完整实现“用户管理”页面。参考图已经给出了目标交互形态，包括左侧菜单分组、用户列表、行内更多操作菜单，以及右侧抽屉式新增/编辑用户表单。

### 目标
- 在管理端新增“用户管理”业务页，完成菜单、路由、页面与真实接口接入。
- 让用户管理页具备可用的搜索、分页、状态展示、更多操作菜单，以及新增/编辑用户抽屉。
- 预留“工单管理”菜单与路由入口，使后台导航结构与参考图对齐，但本轮不展开工单业务实现。

### 约束条件
```yaml
时间约束: 本轮只完整实现“用户管理”，工单管理仅补路由入口和占位页
性能约束: 保持当前轻量 Apple 风格，不新增重型表格或状态管理依赖
兼容性约束: 保持现有 Vue3 + TypeScript + Vite + Element Plus 栈与 hash 路由模式
业务约束: 后端接口沿用现有 `/api/v2/{secure_path}/user/*`、`/plan/fetch`，不改 Laravel API
```

### 验收标准
- [ ] 管理端左侧导航新增“用户管理”分组，包含“用户管理”和“工单管理”两个入口。
- [ ] 用户管理页可通过真实接口完成列表读取、分页、基础筛选、状态/套餐/流量展示。
- [ ] 用户管理页支持新增用户、编辑用户、复制订阅地址、重置密钥、封禁和删除等操作入口，并带明确确认反馈。
- [ ] `admin-frontend` 构建通过，新增页面在桌面和移动端都能正常访问。

---

## 2. 方案

### 技术方案
本轮采用“扩展现有管理壳层 + 新增用户管理业务模块”的方案：

1. 扩展管理端数据层  
   在 `src/types/api.d.ts` 中补充用户、套餐、分页与表单类型；在 `src/api/admin.ts` 中新增用户列表、用户详情、套餐列表、用户创建/更新/重置密钥/封禁/删除等请求封装。

2. 新增用户管理视图  
   在 `src/views/users/` 下拆分页面与抽屉组件。列表页负责搜索、表格、分页、更多操作菜单；抽屉组件负责新增/编辑表单。视觉上延续 Apple 风格的浅灰画布、白色内容区与单一蓝色交互重点。

3. 对齐后端真实创建能力  
   后端 `user/generate` 只能直接创建基础字段（邮箱、密码、套餐、到期时间），无法一次性写入完整表单字段。因此新增用户时采用“两段式”流程：
   - 先调用 `user/generate` 创建基础账号
   - 再按邮箱回查用户 ID，并调用 `user/update` 补齐流量、余额、佣金、权限、限速、设备数、备注等扩展字段

4. 补齐导航与路由  
   将当前仅有仪表盘的侧边栏调整为分组导航，新增 `/users` 路由和 `/tickets` 占位路由；本轮仅实现 `UsersView` 的完整业务功能。

### 影响范围
```yaml
涉及模块:
  - admin-frontend/src/router: 新增用户管理与工单管理路由
  - admin-frontend/src/layouts: 调整侧边栏菜单结构与导航文案
  - admin-frontend/src/api: 扩展用户与套餐相关请求
  - admin-frontend/src/types: 新增用户管理数据类型
  - admin-frontend/src/views/users: 新增用户列表页与表单抽屉
  - admin-frontend/src/views/tickets: 新增工单管理占位页
预计变更文件: 7-9
```

### 风险评估
| 风险 | 等级 | 应对 |
|------|------|------|
| 后端新增用户接口字段不足，无法一次提交完整表单 | 中 | 前端采用 `generate + fetch + update` 两段式创建流程 |
| 参考图中的“在线设备”字段后端列表接口未直接返回 | 中 | 本轮优先展示可用业务字段，设备相关展示使用已有限制字段和可退化文案 |
| 用户删除属于破坏性操作 | 中 | 在前端增加显式确认和操作完成提示，避免误删 |

---

## 3. 技术设计（可选）

> 涉及路由扩展、API映射与表单编排，需填写。

### 架构设计
```mermaid
flowchart TD
    A[AdminLayout 侧边栏菜单] --> B[UsersView 用户管理页]
    A --> C[TicketsPlaceholderView 工单占位页]
    B --> D[UserToolbar 搜索与操作]
    B --> E[UserTable 列表与更多操作]
    B --> F[UserFormDrawer 新增/编辑抽屉]
    B --> G[admin.ts 用户管理接口]
    F --> G
    G --> H[/user/fetch]
    G --> I[/user/generate]
    G --> J[/user/update]
    G --> K[/user/resetSecret]
    G --> L[/user/ban]
    G --> M[/user/destroy]
    G --> N[/plan/fetch]
```

### API设计
#### ANY /api/v2/{secure_path}/user/fetch
- **请求**: `current`, `pageSize`, `filter[]`, `sort[]`
- **响应**: `{ data: UserListItem[], total: number }`

#### GET /api/v2/{secure_path}/user/getUserInfoById
- **请求**: `id`
- **响应**: 单个用户详情，含邀请人信息

#### POST /api/v2/{secure_path}/user/generate
- **请求**: `email_prefix`, `email_suffix`, `password`, `plan_id`, `expired_at`
- **响应**: `success(true)` 或批量结果

#### POST /api/v2/{secure_path}/user/update
- **请求**: `id` + 用户扩展字段（余额、佣金、流量、权限、限速、设备数、备注等）
- **响应**: `success(true)`

#### GET /api/v2/{secure_path}/plan/fetch
- **请求**: 无
- **响应**: 套餐列表，用于表单选择和表格展示

### 数据模型
| 字段 | 类型 | 说明 |
|------|------|------|
| AdminUserListItem | object | 用户列表行数据，包含套餐、邀请人、权限组、流量、状态等 |
| AdminUserFormModel | object | 抽屉表单模型，覆盖新增/编辑时的基础与扩展字段 |
| AdminPlanOption | object | 订阅计划选项，用于表单下拉与列表展示 |
| AdminPaginationResult<T> | object | 用户列表分页结果 |

---

## 4. 核心场景

> 执行完成后同步到对应模块文档

### 场景: 浏览用户列表
**模块**: UsersView
**条件**: 管理员已登录并进入 `/users`
**行为**: 页面读取用户列表、套餐信息并渲染搜索栏、表格、分页
**结果**: 管理员可快速查看用户状态、流量、到期时间与套餐

### 场景: 新增用户
**模块**: UserFormDrawer / admin.ts
**条件**: 管理员在用户管理页点击“创建用户”
**行为**: 管理员填写抽屉表单，前端先生成基础账号，再补写扩展字段
**结果**: 新用户创建成功，列表自动刷新并提示结果

### 场景: 编辑或执行行内操作
**模块**: UsersView / UserFormDrawer
**条件**: 列表中存在目标用户
**行为**: 管理员打开更多菜单执行编辑、复制订阅地址、重置密钥、封禁或删除
**结果**: 对应操作完成并反馈到列表状态

---

## 5. 技术决策

> 本方案涉及的技术决策，归档后成为决策的唯一完整记录

### admin-frontend-user-management#D001: 新增用户采用“两段式创建”以兼容现有后端接口
**日期**: 2026-04-21
**状态**: ✅采纳
**背景**: 参考表单包含余额、佣金、流量、角色、限速、设备数、备注等字段，但后端 `user/generate` 仅支持基础创建字段。
**选项分析**:
| 选项 | 优点 | 缺点 |
|------|------|------|
| A: 仅用 `user/generate` 并缩减表单字段 | 实现简单 | 无法对齐参考页字段深度 |
| B: 先 `generate`，再按邮箱回查并 `update` | 能保留完整表单能力 | 前端流程更复杂 |
**决策**: 选择方案 B
**理由**: 既不改后端，也能最大化还原参考抽屉中的管理能力。
**影响**: `admin.ts`、`UsersView`、`UserFormDrawer`

### admin-frontend-user-management#D002: 先补齐用户/工单路由结构，但本轮仅交付完整用户页
**日期**: 2026-04-21
**状态**: ✅采纳
**背景**: 用户要求“添加用户管理、工单管理路由，先完成用户管理”，既要有完整导航结构，又要控制当前实现范围。
**选项分析**:
| 选项 | 优点 | 缺点 |
|------|------|------|
| A: 只加用户管理，不处理工单入口 | 实现最小 | 与用户点名的导航结构不一致 |
| B: 同时补齐用户/工单入口，工单先占位 | 与目标结构一致，后续扩展顺滑 | 需要新增一个占位页面 |
**决策**: 选择方案 B
**理由**: 先把信息架构铺平，后续实现工单页时不必再改侧边栏和路由骨架。
**影响**: `router/index.ts`、`layouts/AdminLayout.vue`

---

## 6. 成果设计

> 含视觉产出的任务由 DESIGN Phase2 填充。非视觉任务整节标注"N/A"。

### 设计方向
- **美学基调**: Apple Admin Ledger。像 Apple 系统设置和内部运营面板的结合体，用极少的颜色和干净层级承载高密度数据。
- **记忆点**: 浅灰页面基底上嵌入一块大尺寸白色数据工作台，右侧抽屉像系统级面板一样滑入。
- **参考**: 用户提供的用户列表、操作菜单、抽屉表单和侧边栏截图 + [apple/DESIGN.md](/E:/code/php/Xboard-new/apple/DESIGN.md)

### 视觉要素
- **配色**: 背景 `#f5f5f7`、表格/抽屉 `#ffffff`、标题 `#1d1d1f`、强调蓝 `#0071e3`、危险态 `#c93428`
- **字体**: 延续当前系统字体栈 `-apple-system`, `BlinkMacSystemFont`, `SF Pro Display`, `SF Pro Text`, `Helvetica Neue`, Arial, sans-serif`
- **布局**: 页面顶部为标题与操作条，中部为单块白色表格工作区；抽屉从右侧进入，表单按字段组自然分段
- **动效**: 仅保留表格 hover、高亮状态和抽屉进出动画，避免复杂动效
- **氛围**: 轻边框、软阴影、玻璃顶栏，避免深色重装饰和泛滥卡片分割

### 技术约束
- **可访问性**: 所有状态色均保留文字标签；删除、封禁等危险操作必须有二次确认
- **响应式**: 窄屏下工具栏折行、表格横向滚动、抽屉宽度自适应到视口
