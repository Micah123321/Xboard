# admin-frontend 插件管理首版交付 — 需求

确认后冻结，执行阶段不可修改。如需变更必须回到设计阶段重新确认。

## 核心目标
- 在 `admin-frontend` 中把 `/system/plugins` 从结构化占位页升级为真实插件管理工作台。
- 页面视觉继续遵循 `apple/DESIGN.md` 与当前后台 Apple 化风格，并尽量贴近用户提供的目标截图：顶部搜索 / 分组切换 / 状态筛选 / 上传入口，下方插件卡片列表。
- 让管理员可以在同一页面完成插件浏览、筛选、上传、安装、启用 / 禁用、升级、卸载，以及 README / 配置查看与编辑。

## 功能边界
- 必须接入现有 Laravel 管理接口的真实数据链路：
  - `GET /plugin/types`
  - `GET /plugin/getPlugins`
  - `POST /plugin/upload`
  - `POST /plugin/install`
  - `POST /plugin/uninstall`
  - `POST /plugin/enable`
  - `POST /plugin/disable`
  - `GET /plugin/config`
  - `POST /plugin/config`
  - `POST /plugin/upgrade`
- 必须支持：
  - 按关键词搜索插件
  - 按插件类型切换（全部 / 功能 / 支付方式）
  - 按状态筛选（全部 / 已启用 / 已安装未启用 / 未安装 / 可升级）
  - 上传 zip 插件包
  - 列表中直接执行安装、启用、禁用、升级、卸载动作
  - 打开插件详情工作台，查看 README、基础元信息，并对可配置插件进行配置保存
- 必须覆盖加载、空列表、错误、按钮提交中、配置保存成功 / 失败等状态。

## 非目标
- 本轮不实现主题、公告、支付配置、知识库管理的真实 CRUD 页面。
- 本轮不新增或重构 Laravel 插件管理接口。
- 本轮不接入浏览器端拖拽上传、批量操作或插件市场远程下载能力。
- 本轮不修改 `public/assets/admin` 子模块之外的发布流程。

## 技术约束
- 技术栈固定为 `Vue 3 + TypeScript + Vite + Element Plus`。
- 后端真相源以现有 `PluginController` / `PluginConfigService` / `PluginManager` 为准，不在前端猜测额外字段。
- 视觉契约优先级：本方案 > `.helloagents/DESIGN.md` > `apple/DESIGN.md` 参考原则。
- 构建验证使用 `admin-frontend/package.json` 中已有 `npm run build`。

## 质量要求
- 插件管理页必须保持 Apple 风格后台的一致性，同时比现有占位页更强调运营效率与状态可读性。
- 卡片与详情工作台中的插件状态、危险动作和受保护插件边界必须清晰可辨。
- README 展示与配置编辑必须是真实数据流，不允许停留在纯展示占位。
- 最终至少完成一次构建验证，并补一份本轮 UI 验收结论。
