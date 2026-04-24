# admin-frontend 礼品卡管理首版交付 — 任务分解

## 任务列表
- [x] 任务1：补齐礼品卡管理方案与合同产物（涉及文件：`.helloagents/plans/202604241703_admin-frontend-gift-card-management/*`；完成标准：存在需求、方案、任务与合同文件；验证方式：文件检查）
- [x] 任务2：开放礼品卡导航与路由入口（涉及文件：`admin-frontend/src/layouts/AdminLayout.vue`、`admin-frontend/src/router/index.ts`；完成标准：侧边栏可进入 `#/subscriptions/gift-cards`；验证方式：`npm run build`）
- [x] 任务3：补齐礼品卡 API、类型与工具层（涉及文件：`admin-frontend/src/api/admin.ts`、`admin-frontend/src/types/api.d.ts`、`admin-frontend/src/utils/giftCards.ts`；完成标准：前端可消费 `gift-card/*` 接口并统一完成字段映射；验证方式：`npm run build`）
- [x] 任务4：实现礼品卡管理主页面（涉及文件：`admin-frontend/src/views/subscriptions/GiftCardsView.vue`、`admin-frontend/src/views/subscriptions/GiftCardsView.scss`；完成标准：四个页签支持真实数据展示、搜索筛选、表格/统计渲染与关键操作入口；验证方式：`npm run build`）
- [x] 任务5：实现模板抽屉与兑换码生成弹层（涉及文件：`admin-frontend/src/views/subscriptions/GiftCardTemplateDrawer.vue`、`admin-frontend/src/views/subscriptions/GiftCardCodeBatchDialog.vue`；完成标准：支持模板新增/编辑与兑换码批量生成；验证方式：`npm run build`）
- [x] 任务6：完成验证与知识库同步（涉及文件：`.helloagents/CHANGELOG.md`、`.helloagents/context.md`、`.helloagents/modules/admin-frontend.md`、`.helloagents/.ralph-visual.json`、`.helloagents/.ralph-closeout.json`；完成标准：构建通过、知识库更新、交付证据写入；验证方式：命令输出 + 证据文件）

## 进度
- [x] 已确认礼品卡管理按完整四页签首版推进。
- [x] 已完成礼品卡管理真实页面、模板抽屉、兑换码生成弹层与后端接口接入。
- [x] 已完成构建验证，待输出最终交付摘要。
