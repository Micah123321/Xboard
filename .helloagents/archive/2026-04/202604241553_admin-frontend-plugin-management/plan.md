# admin-frontend 插件管理首版交付 — 实施规划

## 目标与范围
- 把 `#/system/plugins` 从占位页升级为真实插件管理页面。
- 本轮范围聚焦“单页工作台 + 详情抽屉”模型：列表承担检索、筛选和高频动作，抽屉承担 README、配置与补充说明。
- 其余系统管理子页保持现状，不借本轮需求扩展其他模块。

## 架构与实现策略
- 在 `admin-frontend/src/api/admin.ts` 与 `src/types/api.d.ts` 中补齐插件管理接口和类型定义，保证所有页面动作都走真实后端契约。
- 新增 `src/utils/plugins.ts`，集中处理插件类型文案、状态判断、筛选、README 渲染与配置表单值序列化，避免视图组件堆积逻辑。
- 将 `/system/plugins` 路由替换为独立的 `PluginManagementView.vue`，继续保留黑色 hero + 白色工作台层次，首屏承载搜索、类型切换、状态筛选、上传入口与运营摘要。
- 新增 `PluginDetailDrawer.vue` 作为插件详情工作台：
  - 左侧 / 顶部展示插件基本信息与状态
  - 中部切换 README / 配置两个视图
  - 配置基于后端返回的动态 schema 渲染，不额外臆造字段
- 列表卡片提供高频动作按钮：
  - 未安装：安装
  - 已安装未启用：启用、卸载
  - 已启用：禁用
  - 可升级：升级
  - 受保护 / 核心插件：明确显示保护边界，避免危险误操作

## 完成定义
- `#/system/plugins` 能真实拉取插件列表，并按类型 / 状态 / 关键词筛选。
- 页面支持上传 zip 插件包，并在上传成功后刷新列表。
- 管理员可以对插件执行安装、启用、禁用、升级、卸载动作，并获得明确成功 / 失败反馈。
- 详情抽屉可查看 README 和插件基础信息；对存在配置 schema 的插件，可读取并保存配置。
- 验证主路径：`review-first`
- reviewer 关注边界：
  - 插件管理首屏是否与现有 Apple 风格后台一致，且不像占位页
  - 列表动作优先级、危险按钮和受保护插件边界是否清晰
  - 详情抽屉的 README / 配置双视图是否足够清楚
- tester 关注边界：
  - `/plugin/getPlugins`、`/plugin/upload`、`/plugin/install`、`/plugin/enable`、`/plugin/disable`、`/plugin/uninstall`、`/plugin/upgrade` 是否都已接入真实数据流
  - 插件配置读取 / 保存是否真实命中 `/plugin/config`
  - 搜索、类型切换、状态筛选是否真实影响渲染结果

## 文件结构
- `admin-frontend/src/router/index.ts`
- `admin-frontend/src/api/admin.ts`
- `admin-frontend/src/types/api.d.ts`
- `admin-frontend/src/utils/plugins.ts`（新增）
- `admin-frontend/src/views/system/PluginManagementView.vue`（新增）
- `admin-frontend/src/views/system/PluginManagementView.scss`（新增）
- `admin-frontend/src/views/system/PluginDetailDrawer.vue`（新增）

## UI / 设计约束
- 首屏延续当前系统管理模块的黑色 hero，右侧摘要卡片改为“插件总数 / 已启用 / 可升级 / 用户上传”等运营信息。
- 列表采用大卡片而不是传统密表格，强调插件名称、类型、版本、作者、描述与状态标签，贴近用户截图的阅读方式。
- 顶部筛选区使用轻量 segmented control + select + search 组合，不引入多层复杂过滤器。
- 配置表单要兼容 `boolean / string / text / json / select` 等基础字段类型，字段说明与 placeholder 保持可见。
- README 区域使用真实 Markdown 渲染，保留代码块、列表和标题层级。

## 风险与验证
- 风险 1：后端返回的插件配置 schema 是动态结构，前端需要兼容多种字段类型与空配置插件。
- 风险 2：`getPlugins` 已带部分配置和 README，但已安装插件的配置需要保证与 `/plugin/config` 拉取一致，避免抽屉内旧数据。
- 风险 3：本地环境缺少真实登录态时，无法做完整浏览器联调；需要用 build + 代码级结构自检给出本轮 UI 验收结论。
- 验证方式：
  - `npm run build`
  - 对构建产物与代码结构做 UI 自检，确认搜索、筛选、卡片操作与抽屉视图均已真实连接数据流

## 决策记录
- [2026-04-24] D001：插件管理采用“卡片列表 + 详情抽屉”，不回退到纯表格，兼顾截图风格和后台可操作性。
- [2026-04-24] D002：配置编辑采用动态 schema 渲染，不为单个插件写死字段。
- [2026-04-24] D003：README 与配置合并进同一个详情工作台，避免列表页信息密度失控。
