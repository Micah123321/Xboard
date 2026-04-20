# Xboard 管理端前端 - 基础登录功能实施计划

## 任务类型

- [x] 前端 (Vue3 + TypeScript + Vite + Element Plus)
- [ ] 后端 (无需改动，复用现有 API)
- [ ] 全栈

## 需求概述

为 Xboard 创建独立的管理端前端项目，技术栈：Vue3 + TypeScript + Vite + Element Plus。第一阶段实现基础登录功能，包括：

- 管理员登录页面（邮箱 + 密码）
- Token 认证与路由守卫
- 登录后 Dashboard 占位页
- 基础布局框架（侧边栏 + 顶栏）

## 技术方案

### 后端 API 契约（已存在，无需改动）

| 接口 | 方法 | URL | 说明 |
|------|------|-----|------|
| 登录 | POST | `/api/v2/passport/auth/login` | `{email, password}` → `{auth_data, is_admin, token}` |
| 管理接口前缀 | - | `/api/v2/{secure_path}/...` | secure_path 从后端 admin_setting 获取 |
| 系统状态探针 | GET | `/api/v2/{secure_path}/system/getSystemStatus` | 登录后验证 admin 权限 |

**关键发现：**
- 登录接口是通用用户接口，`is_admin` 在响应中标识管理员身份
- `auth_data`（格式 `Bearer xxx`）是鉴权凭证，`token` 是邀请码字段，不可用作认证
- Admin 中间件使用 Sanctum guard，通过 `Authorization` header 传递 Bearer token
- `secure_path` 是动态的，首期从 Laravel 的 `window.settings.secure_path` 获取

### 部署策略：独立源码 + Laravel 托管 dist

首期将 Vue3 项目构建产物放到 Laravel 的 `public/` 目录下，由 Laravel 的 `admin.blade.php` 通过 `window.settings` 注入运行时配置。这样 `secure_path` 自举最简单，无需跨域。

### 前端分析交叉验证（Codex 后端视角 + 前端 UI/UX 视角）

**一致观点（强信号）：**
- 方案 A（独立源码 + Laravel 托管 dist）是最务实的首期路线
- `secure_path` 从运行时配置获取，不在前端推导
- 登录后必须校验 `is_admin`

**前端分析补充建议（已纳入计划）：**
1. **工程化优化**：使用 `unplugin-auto-import` + `unplugin-vue-components` 实现 Element Plus 按需引入，优化包体积
2. **暗色/亮色主题切换**：Element Plus 原生支持 dark mode，首期预留切换能力
3. **前端登录频率限制提示**：在登录表单增加防抖，429 时显示倒计时提示
4. **Token 过期自动重定向**：Axios 拦截器中 401 响应自动清除 token 并跳转登录页
5. **动态路径策略**：`VITE_ADMIN_PATH` 环境变量作为 `secure_path` 的默认值，同时支持从 `window.settings.secure_path` 动态覆盖

### 项目结构

```
admin-frontend/                    # 独立前端项目根目录
├── index.html
├── package.json
├── tsconfig.json
├── tsconfig.node.json
├── vite.config.ts
├── env.d.ts
├── .env
├── .env.development
├── .env.production
├── public/
│   └── favicon.ico
└── src/
    ├── main.ts                    # 应用入口
    ├── App.vue                    # 根组件
    ├── api/
    │   ├── client.ts              # Axios 实例与拦截器
    │   ├── passport.ts            # 登录相关 API
    │   └── admin.ts               # 管理端 API（带 secure_path）
    ├── stores/
    │   ├── auth.ts                # 认证状态（Pinia）
    │   └── app.ts                 # 应用运行时配置
    ├── router/
    │   ├── index.ts               # 路由定义
    │   └── guards.ts              # 路由守卫
    ├── layouts/
    │   └── AdminLayout.vue        # 管理端主布局
    ├── views/
    │   ├── login/
    │   │   └── LoginView.vue      # 登录页
    │   └── dashboard/
    │       └── DashboardView.vue  # 仪表盘占位页
    ├── types/
    │   ├── api.d.ts               # API 响应类型
    │   └── env.d.ts               # 环境变量类型
    ├── utils/
    │   ├── token.ts               # Token 存储工具
    │   └── runtime.ts             # 运行时配置解析
    └── styles/
        └── index.scss             # 全局样式
```

## 实施步骤

### Step 1: 初始化项目骨架

**预期产物：** 可运行的空 Vue3 + TS 项目

- 使用 `npm create vite@latest admin-frontend -- --template vue-ts` 创建项目
- 安装依赖：`element-plus`, `pinia`, `vue-router`, `axios`, `sass`, `@element-plus/icons-vue`
- 安装开发依赖：`unplugin-auto-import`, `unplugin-vue-components`（Element Plus 按需引入）
- 配置 `vite.config.ts`（代理、别名、构建输出路径、AutoImport 插件）
- 配置 `tsconfig.json` 路径别名
- 创建 `.env.development` 和 `.env.production`

**Vite 配置要点：**
```typescript
// vite.config.ts
export default defineConfig({
  base: '/assets/admin/',
  resolve: { alias: { '@': '/src' } },
  plugins: [
    vue(),
    AutoImport({ resolvers: [ElementPlusResolver()] }),
    Components({ resolvers: [ElementPlusResolver()] }),
  ],
  server: {
    port: 5173,
    proxy: {
      '/api': {
        target: 'http://localhost:8000',
        changeOrigin: true,
      }
    }
  },
  build: {
    outDir: '../public/assets/admin',
    emptyOutDir: true,
  }
})
```

**环境变量：**
```
# .env.development
VITE_API_BASE_URL=/api/v2
VITE_SECURE_PATH=           # 开发时留空，由 proxy 转发

# .env.production
VITE_API_BASE_URL=/api/v2
# production 下 secure_path 从 window.settings 获取
```

### Step 2: 基础设施层

**预期产物：** API client、Token 工具、运行时配置、类型定义

- **`src/types/api.d.ts`** - API 响应泛型、登录接口类型
- **`src/utils/token.ts`** - Token 存取（sessionStorage 为默认，可选 localStorage 记住登录）
- **`src/utils/runtime.ts`** - 解析 `window.settings` 和环境变量，提供 `getSecurePath()`、`getApiBaseUrl()`
  - `secure_path` 优先级：`window.settings.secure_path` > `VITE_ADMIN_PATH` 环境变量 > 启动报错
- **`src/api/client.ts`** - 两个 Axios 实例：
  - `passportClient`：固定前缀 `/api/v2/passport`
  - `adminClient`：运行时拼接 `/api/v2/{secure_path}`
  - 统一响应拦截：错误归一化、401/403 自动清除 token 跳登录
- **`src/api/passport.ts`** - `login(email, password)` 函数
- **`src/api/admin.ts`** - `getSystemStatus()` 探针函数

### Step 3: 状态管理

**预期产物：** Pinia stores

- **`src/stores/auth.ts`**：
  - state: `authHeader`, `isAdmin`, `isLoading`
  - actions: `login()`, `logout()`, `validateAdmin()`, `initFromStorage()`
  - 登录流程：调用 API → 校验 `is_admin` → 保存 `authHeader` → admin 探针验证
  - 非 admin 用户登录后直接拒绝并提示"无管理员权限"
- **`src/stores/app.ts`**：
  - state: `securePath`, `apiBaseUrl`, `sidebarCollapsed`
  - actions: `initConfig()` 从 runtime 解析配置

### Step 4: 路由与守卫

**预期产物：** 路由配置 + 权限守卫

- **`src/router/index.ts`**：
  - 使用 `createWebHashHistory()`（因为 Laravel 无 catch-all 路由，hash 模式避免刷新 404）
  - 路由表：
    ```
    /login      → LoginView      (公开)
    /           → AdminLayout     (需认证)
    /dashboard  → DashboardView   (需认证)
    ```
- **`src/router/guards.ts`**：
  - `beforeEach`：无 token → `/login`；有 token 但未验证 → 执行 admin 探针 → 失败清 token 回 `/login`
  - 已登录用户访问 `/login` → 重定向 `/dashboard`

### Step 5: 登录页面

**预期产物：** 功能完整的登录页

- **`src/views/login/LoginView.vue`**：
  - 简约卡片布局，深色渐变背景，居中展示
  - Element Plus 组件：`ElForm`, `ElFormItem`, `ElInput`, `ElButton`, `ElMessage`
  - 表单字段：
    - 邮箱（email 类型，必填，格式校验）
    - 密码（password 类型，必填，最少 8 位）
    - 记住登录（可选 checkbox，控制 token 存 localStorage vs sessionStorage）
  - 提交时 loading 状态，按钮禁用 + 防抖（防止重复提交）
  - 错误处理：
    - 400：邮箱或密码错误 → 表单级错误提示
    - 429：密码错误次数过多 → 显示倒计时提示（从响应 message 解析剩余分钟数）
    - 403/无权限：`is_admin=false` → 提示"该账号无管理员权限"
    - 网络错误：通用错误提示
  - 登录成功后跳转 `/dashboard`
  - 支持暗色/亮色主题切换（Element Plus 原生 dark mode）

### Step 6: 管理端布局与 Dashboard

**预期产物：** 基础布局框架 + Dashboard 占位页

- **`src/layouts/AdminLayout.vue`**：
  - Element Plus `ElContainer` + `ElAside` + `ElHeader` + `ElMain`
  - 左侧边栏（可折叠）：Logo + 菜单项（首期仅 Dashboard）
  - 顶栏：面包屑 + 右侧用户操作区（登出按钮）
  - 响应式：小屏幕自动折叠侧边栏
- **`src/views/dashboard/DashboardView.vue`**：
  - 占位页面，显示欢迎信息和系统基本信息
  - 后续迭代补充统计数据

### Step 7: 样式与全局配置

**预期产物：** 统一视觉风格

- **`src/styles/index.scss`**：
  - CSS 变量定义主题色
  - Element Plus 主题覆盖（主色调、边框、背景）
  - 登录页专用样式
  - 全局样式重置

### Step 8: 入口文件整合

**预期产物：** 完整可运行的应用

- **`src/main.ts`**：
  - 注册 Element Plus（通过 AutoImport 按需引入）
  - 注册 Pinia
  - 注册 Router
  - 初始化运行时配置（`appStore.initConfig()`）
  - 初始化认证状态（`authStore.initFromStorage()`）
  - 引入 Element Plus 暗色主题 CSS（`element-plus/theme-chalk/dark/css-vars.css`）
- **`src/App.vue`**：`<RouterView />`

## 关键文件清单

| 文件 | 操作 | 说明 |
|------|------|------|
| `admin-frontend/package.json` | 新建 | 项目依赖与脚本 |
| `admin-frontend/vite.config.ts` | 新建 | Vite 构建 + 代理配置 |
| `admin-frontend/src/main.ts` | 新建 | 应用入口 |
| `admin-frontend/src/api/client.ts` | 新建 | Axios 实例 + 拦截器 |
| `admin-frontend/src/api/passport.ts` | 新建 | 登录 API |
| `admin-frontend/src/api/admin.ts` | 新建 | Admin API 封装 |
| `admin-frontend/src/utils/token.ts` | 新建 | Token 存储工具 |
| `admin-frontend/src/utils/runtime.ts` | 新建 | 运行时配置解析 |
| `admin-frontend/src/stores/auth.ts` | 新建 | 认证状态管理 |
| `admin-frontend/src/stores/app.ts` | 新建 | 应用配置管理 |
| `admin-frontend/src/router/index.ts` | 新建 | 路由定义 |
| `admin-frontend/src/router/guards.ts` | 新建 | 路由守卫 |
| `admin-frontend/src/views/login/LoginView.vue` | 新建 | 登录页 |
| `admin-frontend/src/layouts/AdminLayout.vue` | 新建 | 管理端布局 |
| `admin-frontend/src/views/dashboard/DashboardView.vue` | 新建 | 仪表盘占位 |

## 风险与缓解

| 风险 | 缓解措施 |
|------|----------|
| `secure_path` 在独立部署时无法从前端推导 | 首期用 Laravel 托管 dist，从 `window.settings` 获取；中长期可加 bootstrap API |
| 登录接口是用户通用接口，非管理员也能登录 | 登录后立即校验 `is_admin`，非 admin 拒绝进入管理端 |
| `token` 字段含义混淆（实际是邀请码） | 封装层仅暴露 `auth_data` 作为认证凭证，`token` 字段忽略 |
| CORS `supports_credentials=false` | 前端走 Bearer token 无状态认证，不依赖 cookie |
| Hash 路由不够优雅 | 首期务实选择，后续独立域名部署时可切 history 模式 |
| 无专用 admin logout API | 前端清除本地 token 即可（Sanctum token 有 1 年有效期，不影响安全性） |

## SESSION_ID

- CODEX_SESSION: 019dac16-b724-73a2-a3ff-f6b2ac49e2bf
- GEMINI_SESSION: (不可用，调用失败)
