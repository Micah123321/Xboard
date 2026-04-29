# 任务清单: user-frontend-access-toggle

> **@status:** completed | 2026-04-29 16:20

```yaml
@feature: user-frontend-access-toggle
@created: 2026-04-29
@status: completed
@mode: R2
```

## LIVE_STATUS

```json
{"status":"completed","completed":6,"failed":0,"pending":0,"total":6,"percent":100,"current":"用户前端访问开关、路由拦截、后台配置与验证已完成","updated_at":"2026-04-29 16:16:00"}
```

## 进度概览

| 完成 | 失败 | 跳过 | 总数 |
|------|------|------|------|
| 6 | 0 | 0 | 6 |

---

## 任务列表

### 1. 后端访问控制

- [√] 1.1 新增 `app/Http/Middleware/EnsureUserFrontendEnabled.php`
  - 预期变更: 读取 `frontend_enable` 设置，关闭时对用户入口返回 404，开启时放行。
  - 完成标准: 中间件能正确识别 `true/false/1/0` 等设置值。
  - 验证方式: `vendor/bin/phpunit tests/Feature/UserFrontendAccessToggleTest.php`
  - depends_on: []

- [√] 1.2 修改 `app/Http/Kernel.php`
  - 预期变更: 注册 `user.frontend` 路由中间件别名。
  - 完成标准: 路由类可通过别名挂载新增中间件。
  - 验证方式: `php artisan route:list` 不报中间件解析错误。
  - depends_on: [1.1]

- [√] 1.3 修改 `routes/web.php` 与用户侧 API 路由类
  - 预期变更: 用户首页、订阅入口、V1 Passport/User/Client、V2 Client、V1 Guest 的公开展示接口挂载 `user.frontend`；节点 API 与后台 API 不挂载。
  - 完成标准: `frontend_enable=false` 时用户侧入口返回 404，`/api/v1/server/*` 不被该中间件拦截。
  - 验证方式: `vendor/bin/phpunit tests/Feature/UserFrontendAccessToggleTest.php`
  - depends_on: [1.2]

### 2. 后台配置接入

- [√] 2.1 修改 `app/Http/Controllers/V2/Admin/ConfigController.php` 与 `app/Http/Requests/Admin/ConfigSave.php`
  - 预期变更: `site` 配置组返回 `frontend_enable`，保存接口允许布尔值。
  - 完成标准: 后台配置接口能读取和保存开关，不影响现有配置字段。
  - 验证方式: 特性测试或静态检查配置映射和校验规则。
  - depends_on: [1.1]

- [√] 2.2 修改 `admin-frontend/src/utils/systemConfig.ts`
  - 预期变更: 在站点设置中新增“开放用户前端”开关字段。
  - 完成标准: 管理端系统配置页能渲染并序列化 `frontend_enable`。
  - 验证方式: `npm --prefix admin-frontend run build`
  - depends_on: [2.1]

### 3. 验证与同步

- [√] 3.1 新增并执行验证
  - 预期变更: 增加 `tests/Feature/UserFrontendAccessToggleTest.php`，覆盖默认开启、关闭隐藏、节点 API 不被隐藏。
  - 完成标准: 相关 PHPUnit 测试通过；管理端构建通过或明确记录阻断原因。
  - 验证方式: `vendor/bin/phpunit tests/Feature/UserFrontendAccessToggleTest.php`、`npm --prefix admin-frontend run build`
  - depends_on: [1.3, 2.2]

---

## 执行日志

| 时间 | 任务 | 状态 | 备注 |
|------|------|------|------|
| 2026-04-29 15:59 | DESIGN | in_progress | 已完成上下文收集与方案规划 |
| 2026-04-29 16:16 | DEVELOP | completed | 已完成代码实现、PHPUnit 测试与管理端构建验证 |

---

## 执行备注

- 当前方案默认 `frontend_enable=true`，避免升级后自动隐藏现有用户站点。
- 保留 `/api/v1/guest/payment/notify/*` 与 `/api/v1/guest/telegram/webhook` 这类外部回调，不将其视为用户前端展示接口。
- `php artisan route:list --path=api/v1/server` 在本地因 PHP 缺少 Redis 扩展失败；节点 API 不被前端开关拦截已由 `UserFrontendAccessToggleTest` 覆盖。
- 历史方案包 `202604250006_ticket-closed-reply-reopen` 仍标记 `in_progress`，与本次任务无依赖。
