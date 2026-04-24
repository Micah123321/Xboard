# admin-frontend 礼品卡管理首版交付 — 实施规划

## 目标与范围
- 在现有订阅管理分组中补齐礼品卡管理真实页，替换原先的禁用入口。
- 页面聚焦“礼品卡运营工作台”主链路：建模板、生成兑换码、查看使用记录、追踪统计数据。

## 架构与实现策略
- 在 `AdminLayout` 中开放 `/subscriptions/gift-cards` 导航入口，并在路由中新增对应页面。
- 新增 `GiftCardsView` 作为整页工作台，整体结构参考用户截图：
  - 顶部标题与说明
  - 四段式分段导航（模板管理 / 兑换码管理 / 使用记录 / 统计数据）
  - 每个页签独立的筛选条、表格/卡片内容与操作按钮
- 新增两个业务弹层组件：
  - `GiftCardTemplateDrawer.vue`：负责模板新增与编辑
  - `GiftCardCodeBatchDialog.vue`：负责批量生成兑换码
- 在 `src/utils/giftCards.ts` 中集中处理：
  - 类型/状态映射
  - 模板表单序列化与反序列化
  - 金额(元)/流量(GB)/倍率/日期等展示与提交格式转换
  - 本地搜索筛选与统计卡片整理
- API 层在 `src/api/admin.ts` 中新增礼品卡接口封装；类型定义统一补到 `src/types/api.d.ts`。

## 完成定义
- 侧边栏中的“礼品卡管理”不再是禁用入口，能正常进入 `#/subscriptions/gift-cards`。
- 模板管理页可真实连接模板列表与 CRUD 接口，支持搜索、筛选、启停与删除。
- 模板抽屉可完整编辑截图展示的主要字段分组，并正确序列化为后端 `conditions / rewards / limits / special_config` 结构。
- 兑换码页可真实连接兑换码列表，支持批量生成、复制、启停、导出当前批次、编辑有效期/次数与删除。
- 使用记录与统计数据页可真实连接后端数据，不使用硬编码假数据。

## 文件结构
- `admin-frontend/src/layouts/AdminLayout.vue`
- `admin-frontend/src/router/index.ts`
- `admin-frontend/src/api/admin.ts`
- `admin-frontend/src/types/api.d.ts`
- `admin-frontend/src/utils/giftCards.ts`
- `admin-frontend/src/views/subscriptions/GiftCardsView.vue`
- `admin-frontend/src/views/subscriptions/GiftCardsView.scss`
- `admin-frontend/src/views/subscriptions/GiftCardTemplateDrawer.vue`
- `admin-frontend/src/views/subscriptions/GiftCardCodeBatchDialog.vue`

## UI / 设计约束
- 页面采用“标题说明 + 轻量分段页签 + 白色工作台”的 Apple 化运营后台节奏，不额外叠加夸张 hero 或营销化视觉。
- 四个页签保持统一信息架构与表格密度，让用户能快速在模板、兑换码、记录、统计之间切换。
- 模板抽屉使用分组 section 和双列表单布局，对齐截图中的信息分区；在窄屏下自动堆叠为单列。
- 状态标签、奖励摘要、统计卡片继续沿用单一蓝色强调和语义色状态胶囊，不引入新配色体系。

## 风险与验证
- 风险 1：模板表单字段较多，若直接散落在组件内易导致提交结构和展示结构不一致，因此统一收敛到 `src/utils/giftCards.ts`。
- 风险 2：兑换码列表接口不支持关键词搜索，需要前端在当前拉取结果上做本地搜索，并明确这是列表内过滤。
- 风险 3：本地环境缺少真实后台登录态时，只能做结构与构建验证，不能替代完整联调。
- 验证方式：
  - `npm run build`
  - 代码级结构自检 `#/subscriptions/gift-cards`
  - 结构化视觉验收记录（无浏览器工具时以 code inspection 说明边界）

## 决策记录
- [2026-04-24] 礼品卡管理采用单页四段式导航，而不是四个独立路由，以贴近用户截图中的运营切换路径。
- [2026-04-24] 模板抽屉使用分组式大表单，不把复杂字段塞进居中弹窗，以保证高密度配置仍可读。
- [2026-04-24] 兑换码导出先按“当前选中批次”提供显式出口，不额外扩展复杂多选批量导出流程。
