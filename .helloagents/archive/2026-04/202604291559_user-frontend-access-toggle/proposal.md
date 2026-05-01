# 变更提案: user-frontend-access-toggle

## 元信息
```yaml
类型: 新功能
方案类型: implementation
优先级: P1
状态: 已规划
创建: 2026-04-29
```

---

## 1. 需求

### 背景
当前用户前端由 `routes/web.php` 的 `/` 入口渲染，访问公网 `IP:端口/` 会直接返回主题 HTML，其中包含站点标题、描述、Logo 与主题资源路径。节点通信接口位于 `/api/v1/server/*`，`mi-node` 需要保留这些 API；订阅入口和用户 API 也需要保持原有访问边界。用户希望关闭开关时只隐藏默认首页渲染出的前端网页，避免通过 `/` 直接暴露站点特征，并可在后台手动开启。

### 目标
- 新增后台可保存的 `frontend_enable` 开关，默认开启以保持升级兼容。
- 开关关闭时，仅用户前端首页 `/` 返回空 404，不输出站点标题、描述、Logo、主题脚本或 `window.settings`。
- 订阅入口、用户 API、客户端 API、Guest API 与节点 API 不受该开关影响，继续保留原有鉴权和响应边界。
- 管理后台路由与管理 API 不纳入本次变更范围。

### 约束条件
```yaml
兼容性约束: 默认值必须为开启，避免升级后自动关闭现有站点。
业务约束: 节点通信接口、订阅入口和用户 API 不可被误拦截；管理后台不纳入处理范围。
实现约束: 用户主题源码不在仓内，隐藏用户前端优先在 Laravel 路由和中间件层完成。
安全约束: 关闭时使用 404 隐藏响应，不输出开关状态或产品识别信息。
```

### 验收标准
- [ ] `frontend_enable` 默认开启时，用户前端、用户 API、订阅入口保持原有路由行为。
- [ ] `frontend_enable` 关闭时，只有 `/` 返回空 404，不渲染用户主题 HTML，不暴露 `<title>`、`window.settings` 或主题资源。
- [ ] `frontend_enable` 关闭时，`/{subscribe_path}/{token}`、用户登录注册、用户端 API、Guest API 与客户端 API 保持原有路由行为。
- [ ] `frontend_enable` 关闭时，`/api/v1/server/*` 与 `/api/v2/server/*` 节点 API 仍进入原有中间件和控制器链路。
- [ ] 后台系统配置页可以读取、切换并保存该开关。
- [ ] 自动化测试覆盖关闭/开启两种状态下的核心路由边界。

---

## 2. 方案

### 技术方案
新增 `EnsureUserFrontendEnabled` 路由中间件，读取 `admin_setting('frontend_enable', 1)`。当开关关闭时返回空 404；开启时放行。该中间件只挂到用户前端首页 `/`；订阅入口、用户侧 API、节点 API 和管理端路由不挂载。后台配置接口在 `site` 配置组返回并保存 `frontend_enable`，管理端系统配置页在站点设置中显示该开关。

### 影响范围
```yaml
涉及模块:
  - Laravel Web 路由: 控制用户主题首页隐藏行为。
  - Laravel API 路由: 保留原有边界，测试确保未挂载用户前端开关。
  - 管理端系统配置: 暴露可保存的用户前端开关。
  - 测试: 增加路由边界 feature 测试。
预计变更文件: 8-10
```

### 风险评估
| 风险 | 等级 | 应对 |
|------|------|------|
| 误拦截节点 API 导致 `mi-node` 无法同步 | 高 | 不在 `ServerRoute` 上挂载新中间件，并增加节点 API 不返回 404 的测试 |
| 关闭用户前端后现有订阅链接不可用 | 高 | 不在订阅入口上挂载新中间件，关闭开关只影响 `/` |
| 用户 API 被误关导致客户端或登录流程异常 | 高 | 不在 API 路由类上挂载新中间件，并用路由中间件断言覆盖关键入口 |
| 配置值布尔转换不一致 | 低 | 中间件用 `filter_var(..., FILTER_VALIDATE_BOOLEAN)` 统一识别 `0/1/true/false` |

### 方案取舍
```yaml
唯一方案理由: 路由中间件能在 Laravel 首页入口隐藏用户主题 HTML，改动范围清晰，不依赖用户主题源码，也不影响订阅、API、节点或后台路由。
放弃的替代路径:
  - Nginx 路径白名单: 部署层可行但不支持后台开关，且每台服务器配置成本高。
  - 修改用户主题前端: 仓库内只有用户主题编译产物，且请求 `/` 时仍会返回可识别 HTML 壳。
  - 全局 API 中间件路径判断: 容易误伤管理 API 和回调接口，边界不如路由级挂载明确。
回滚边界: 移除新增中间件、路由挂载、配置字段和测试即可恢复原行为；数据库中残留 `frontend_enable` 设置不会影响旧代码。
```

---

## 3. 技术设计

### 架构设计
```mermaid
flowchart TD
    A[后台系统配置] --> B[frontend_enable 设置]
    B --> C[EnsureUserFrontendEnabled]
    C -->|开启| D[用户首页原流程]
    C -->|关闭| E[404]
    J[订阅/API] --> K[原有路由边界]
    F[/api/v1/server/*] --> G[server 中间件]
    H[管理后台/API] --> I[原有后台保护]
```

### API 设计
#### GET /api/v2/{secure_path}/config/fetch
- **响应**: `site.frontend_enable: boolean`

#### POST /api/v2/{secure_path}/config/save
- **请求**: `{ "frontend_enable": true|false }`
- **响应**: 沿用现有 `success(true)`

### 数据模型
| 字段 | 类型 | 说明 |
|------|------|------|
| `v2_settings.frontend_enable` | string/bool | 用户前端首页开关，默认 `1` |

---

## 4. 核心场景

### 场景: 用户入口隐藏
**模块**: Laravel Web/API 路由  
**条件**: `frontend_enable=false`  
**行为**: 访问 `/`
**结果**: 返回空 404，不渲染用户主题，不输出 `<title>`、`window.settings`、站点描述、Logo 或主题脚本。

### 场景: 订阅和用户 API 保留
**模块**: Laravel Web/API 路由
**条件**: `frontend_enable=false`
**行为**: 访问订阅入口、用户登录注册、用户端 API、Guest API 与客户端 API
**结果**: 按原有路由、中间件和鉴权逻辑响应，不被用户前端开关改写成 404。

### 场景: 节点接口保留
**模块**: 节点 API  
**条件**: `frontend_enable=false`  
**行为**: `mi-node` 访问 `/api/v1/server/*`  
**结果**: 路由继续进入原有节点中间件和控制器，不被用户前端开关拦截。

### 场景: 后台手动开启
**模块**: 管理端系统配置  
**条件**: 管理员进入系统配置站点设置  
**行为**: 切换“开放用户前端”开关并保存  
**结果**: 配置写入 `v2_settings`，下次请求按新开关执行。

---

## 5. 技术决策

### user-frontend-access-toggle#D001: 使用路由级中间件控制用户入口
**日期**: 2026-04-29  
**状态**: ✅采纳  
**背景**: 需要在应用代码内提供后台可控的首页隐藏能力，同时避免影响订阅、用户 API、节点 API 和后台 API。
**选项分析**:
| 选项 | 优点 | 缺点 |
|------|------|------|
| A: 路由级中间件 | 边界清晰、可测试、默认兼容、能避开订阅、API、节点和后台路由 | 只能隐藏被挂载的入口 |
| B: Nginx 白名单 | 部署快、应用代码少 | 无后台开关，部署环境差异大 |
| C: 全局 API 中间件 | 集中处理 | 动态后台路径、回调接口和节点路径容易误判 |
**决策**: 选择方案 A  
**理由**: 当前需求重点是“后台手动开启”和“只隐藏首页 HTML”，路由级中间件能精确表达边界，并能用 feature test 验证。
**影响**: `routes/web.php`、中间件注册、后台配置映射和管理端系统配置表单。

---

## 6. 验证策略

```yaml
verifyMode: test-first
reviewerFocus:
  - app/Http/Middleware/EnsureUserFrontendEnabled.php
  - app/Http/Routes/V1/*.php 与 app/Http/Routes/V2/ClientRoute.php 不挂载 `user.frontend` 的边界
  - routes/web.php 中用户入口与管理入口隔离
testerFocus:
  - vendor/bin/phpunit tests/Feature/UserFrontendAccessToggleTest.php
  - php artisan route:list --path=api/v1/server
  - admin-frontend npm build
uiValidation: optional
riskBoundary:
  - 不修改管理后台安全路径逻辑
  - 不修改节点 API Token 或节点认证逻辑
  - 不执行数据库迁移或生产数据写入
```

---

## 7. 成果设计

N/A。本次只在既有系统配置表单中加入一个安全开关，复用现有 Apple 风格后台布局、Element Plus `ElSwitch` 和当前表单密度，不新增页面视觉方向。
