# user-frontend-access

## 职责

- 控制用户前端网页、用户登录注册、用户中心 API、客户端订阅 API 和公开用户展示接口是否对外开放
- 保留节点通信 API、管理后台页面、管理 API 和外部回调接口的原有访问边界
- 为后台系统配置提供 `frontend_enable` 开关，默认开启以兼容已有部署

## 行为规范

- `frontend_enable` 存储在 `v2_settings`，通过 `admin_setting('frontend_enable', 1)` 读取；缺省值为开启
- `EnsureUserFrontendEnabled` 关闭时返回空 404，不渲染用户主题，不输出 `app_name`、站点描述、主题标题或其他站点识别信息
- `routes/web.php` 的 `/` 和 `/{subscribe_path}/{token}` 挂载 `user.frontend`，关闭时不会进入主题渲染和订阅控制器
- `/api/v1/passport/*`、`/api/v1/user/*`、`/api/v2/user/*`、`/api/v1/client/*`、`/api/v2/client/*` 挂载 `user.frontend`
- `/api/v1/guest/plan/fetch` 与 `/api/v1/guest/comm/config` 挂载 `user.frontend`
- `/api/v1/guest/payment/notify/*` 与 `/api/v1/guest/telegram/webhook` 保持开放，避免影响支付和 Telegram 回调
- `/api/v1/server/*` 与 `/api/v2/server/*` 不挂载 `user.frontend`，确保 mi-node 拉配置、上报在线和上报流量不受用户前端开关影响
- 管理后台路由和管理 API 不受 `frontend_enable` 控制；管理后台自身继续依赖 `secure_path` 与既有后台鉴权

## 依赖关系

- 依赖 `app/Http/Middleware/EnsureUserFrontendEnabled.php` 执行访问控制
- 依赖 `app/Http/Kernel.php` 注册 `user.frontend` 路由中间件别名
- 依赖 `app/Http/Controllers/V2/Admin/ConfigController.php` 在 `site` 配置组返回 `frontend_enable`
- 依赖 `app/Http/Requests/Admin/ConfigSave.php` 校验并保存 `frontend_enable`
- 依赖 `admin-frontend/src/utils/systemConfig.ts` 在站点设置中渲染“开放用户前端”开关
- 依赖 `tests/Feature/UserFrontendAccessToggleTest.php` 验证默认开启、关闭隐藏和节点 API 不被误拦截
