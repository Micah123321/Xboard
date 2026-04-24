# admin-frontend 礼品卡管理首版交付 — 需求

确认后冻结，执行阶段不可修改。如需变更必须回到设计阶段重新确认。

## 核心目标
- 在 `admin-frontend` 中开放“订阅管理 / 礼品卡管理”入口，不再保留禁用态。
- 参考用户提供的 5 张截图，交付礼品卡管理真实工作台，覆盖模板管理、兑换码管理、使用记录与统计数据四个页签。
- 保持 `apple/DESIGN.md` 与 `.helloagents/DESIGN.md` 定义的 Apple 化后台视觉语言，同时优先贴近截图中的高密度运营视图与轻量分段导航。

## 功能边界
- 必须实现 `#/subscriptions/gift-cards` 真实页面。
- 页面必须包含：
  - 顶部标题说明与四段式页签导航
  - 模板管理列表、搜索、类型/状态筛选、显隐切换、新增/编辑、删除
  - 模板新增/编辑大表单，覆盖基础配置、奖励内容、使用条件、使用限制、特殊配置、显示效果
  - 兑换码管理列表、模板/状态筛选、复制、启停、编辑、删除、批量生成、批次导出
  - 使用记录列表与用户邮箱搜索
  - 统计数据总览，至少展示模板总数、活跃模板数、兑换码总数、已使用兑换码
- 必须接入现有 Laravel 管理接口：
  - `GET /gift-card/templates`
  - `POST /gift-card/create-template`
  - `POST /gift-card/update-template`
  - `POST /gift-card/delete-template`
  - `POST /gift-card/generate-codes`
  - `GET /gift-card/codes`
  - `POST /gift-card/toggle-code`
  - `GET /gift-card/export-codes`
  - `POST /gift-card/update-code`
  - `POST /gift-card/delete-code`
  - `GET /gift-card/usages`
  - `GET /gift-card/statistics`
  - `GET /gift-card/types`

## 非目标
- 本轮不改造 Laravel 礼品卡后端逻辑、校验规则或数据库结构。
- 本轮不实现用户端礼品卡兑换体验。
- 本轮不引入复杂图表库，只使用现有栈完成统计展示。

## 技术约束
- 技术栈固定为 `Vue 3 + TypeScript + Vite + Element Plus`。
- 后端真相源以仓库内 `App\Http\Controllers\V2\Admin\GiftCardController`、`GiftCardTemplate`、`GiftCardCode` 与 `GiftCardUsage` 为准。
- 构建验证使用 `admin-frontend/package.json` 中已有 `npm run build`。
- 构建产物继续输出到 `public/assets/admin` 子模块。

## 质量要求
- 礼品卡页面需要对齐截图中的运营后台结构：白色工作台、轻量页签、克制筛选条、高密度表格。
- 表单字段需要覆盖加载、保存、取消、校验失败与成功提示等基本状态。
- 金额、流量、时间与倍率展示必须按人类可读方式格式化，不直接暴露原始后端数值。
- 最终至少完成一次构建验证，并留下结构化视觉验收与交付证据。
