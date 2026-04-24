# admin-frontend 订单管理首版交付 — 任务分解

## 任务列表
- [x] 任务1：补齐订单管理的方案与知识产物（涉及文件：`.helloagents/archive/2026-04/202604241620_admin-frontend-order-management/*`；完成标准：存在需求、方案、任务与合同文件；验证方式：文件检查）
- [x] 任务2：开放导航与路由入口（涉及文件：`admin-frontend/src/layouts/AdminLayout.vue`、`admin-frontend/src/router/index.ts`；完成标准：侧边栏可进入 `#/subscriptions/orders`；验证方式：`npm run build`）
- [x] 任务3：补齐订单 API、类型与工具层（涉及文件：`admin-frontend/src/api/admin.ts`、`admin-frontend/src/types/api.d.ts`、`admin-frontend/src/utils/orders.ts`；完成标准：前端可消费 `order/*` 接口并统一金额/状态映射；验证方式：`npm run build`）
- [x] 任务4：实现订单列表页（涉及文件：`admin-frontend/src/views/subscriptions/OrdersView.vue`、`admin-frontend/src/views/subscriptions/OrdersView.scss`；完成标准：列表页支持搜索、筛选、排序、分页与详情入口；验证方式：`npm run build`）
- [x] 任务5：实现分配订单与详情抽屉（涉及文件：`admin-frontend/src/views/subscriptions/OrderAssignDrawer.vue`、`admin-frontend/src/views/subscriptions/OrderDetailDrawer.vue`；完成标准：支持分配订单、查看详情、手动支付、取消订单与佣金状态维护；验证方式：`npm run build`）
- [x] 任务6：完成验证与知识库同步（涉及文件：`.helloagents/CHANGELOG.md`、`.helloagents/context.md`、`.helloagents/modules/admin-frontend.md`、`.helloagents/.ralph-visual.json`、`.helloagents/.ralph-closeout.json`；完成标准：构建通过、知识库更新、交付证据写入；验证方式：命令输出 + 证据文件）

## 进度
- [x] 已确认订单管理首版范围，聚焦真实列表、详情抽屉与分配订单。
- [x] 已完成订单管理页面、抽屉与订单接口接入。
- [x] 已完成构建验证，待输出最终交付摘要。
