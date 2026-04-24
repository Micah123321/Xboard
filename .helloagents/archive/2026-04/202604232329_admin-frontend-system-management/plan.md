# admin-frontend 系统管理首批交付 — 实施规划

## 目标与范围
- 为 `admin-frontend` 增加“系统管理”信息架构，并先交付“系统配置”主页面。
- 页面目标不是一次性做完整后台系统中心，而是先打通“可进入、可读取、可编辑、可保存”的配置主链路，同时把其余 5 个系统管理入口先稳定为可访问占位页。

## 架构与实现策略
- 在现有 `AdminLayout` 基础上新增“系统管理”二级分组，保持侧边栏结构统一。
- 新增以下路由：
  - `/system/config` 为真实功能页
  - `/system/plugins`
  - `/system/themes`
  - `/system/notices`
  - `/system/payments`
  - `/system/knowledge`
- 系统配置页直接消费现有后端接口，不在前端猜测或重塑接口契约：
  - `/config/fetch`
  - `/config/save`
  - `/config/testSendMail`
  - `/config/setTelegramWebhook`
- 配置字段分组、控件元信息与序列化逻辑下沉到 `utils/systemConfig.ts`，避免页面组件膨胀。
- 占位页使用统一的 `SystemPlaceholderView`，以一致的结构说明本轮范围与下一阶段扩展点。

## 完成定义
- 侧边栏出现“系统管理”分组，且可以进入 6 个子入口。
- `/system/config` 页面可真实拉取配置数据，并按 9 个配置分组组织内容。
- 用户可以修改并保存系统配置，保存后获得成功/失败反馈。
- 邮件设置与 Telegram 设置保留辅助动作入口（测试邮件 / 设置 Webhook），但不在本轮额外扩展复杂工作流。
- 其余 5 个系统管理子页可从侧边栏正常进入，并明确标注“下一阶段接入”。
- 验证主路径：`review-first`
- reviewer 关注边界：系统管理信息架构是否清晰、系统配置表单层级是否贴近 Apple 风格后台、占位页是否透明说明未实现范围。
- tester 关注边界：菜单与路由是否真实连通、系统配置页是否真实连接 `/config/fetch` 与 `/config/save`、保存链路与辅助按钮是否存在真实数据流。

## 文件结构
- `admin-frontend/src/router/index.ts`
- `admin-frontend/src/layouts/AdminLayout.vue`
- `admin-frontend/src/api/admin.ts`
- `admin-frontend/src/types/api.d.ts`
- `admin-frontend/src/utils/systemConfig.ts`（新增）
- `admin-frontend/src/views/system/SystemConfigView.vue`（新增）
- `admin-frontend/src/views/system/SystemPlaceholderView.vue`（新增）
- `.helloagents/DESIGN.md`

## UI / 设计约束
- 系统配置首页保留黑色 hero + 白色配置壳层的 Apple 后台节奏。
- 左侧使用紧凑分组导航，右侧使用连续表单 section，优先满足“快速定位配置块”的后台效率诉求。
- 页面首屏只保留一个主保存动作和少量辅助描述，不堆砌营销式视觉元素。
- 占位页不做空白页，而是交付可继续扩展的结构化提示页。

## 风险与验证
- 风险 1：配置接口字段类型包含布尔、数值、数组和长文本，前端需要统一序列化与表单回填。
- 风险 2：`/config/fetch` 返回分组对象，系统配置页必须避免直接把后端分组名暴露成低可读开发术语。
- 风险 3：本地静态预览环境可能缺少 Laravel 注入或后台认证，浏览器验收要区分“结构验收”和“真实联调”边界。
- 验证方式：
  - `npm run build`
  - 本地预览 + 浏览器检查 `#/system/config`
  - 浏览器检查 `#/system/plugins`、`#/system/themes`、`#/system/notices`、`#/system/payments`、`#/system/knowledge`

## 决策记录
- [2026-04-23] 系统管理首批交付聚焦“系统配置真实页 + 其余入口占位页”，避免在一轮内同时展开多个 CRUD 模块。
- [2026-04-23] “系统配置”保留左侧配置分组导航，优先满足后台场景中的长表单定位效率。
- [2026-04-23] 前台主题相关配置不混入本轮系统配置页，而是留在“主题配置”入口的后续阶段实现。

