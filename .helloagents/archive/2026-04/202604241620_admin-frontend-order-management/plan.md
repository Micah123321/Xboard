# admin-frontend 订单管理首版交付 — 实施规划

## 目标与范围
- 在现有订阅管理分组中补齐订单管理真实页，替换原先的禁用态入口。
- 页面聚焦“订单运营工作台”主链路：查订单、分配订单、看详情、手工补单、管理佣金状态。

## 架构与实现策略
- 在现有 `AdminLayout` 中开放 `/subscriptions/orders` 导航入口，并新增对应路由。
- 新增 `OrdersView` 作为真实列表页，整体结构参考用户截图：
  - 顶部标题与说明
  - “添加订单”主按钮
  - 搜索框
  - 类型 / 周期 / 订单状态 / 佣金状态筛选按钮
  - 数据表格与分页
- 新增两个子组件：
  - `OrderAssignDrawer.vue`：负责手动分配订单
  - `OrderDetailDrawer.vue`：负责查看详情与行级动作
- 在 `src/utils/orders.ts` 中集中处理：
  - 金额分→元格式化
  - 类型 / 状态 / 周期映射
  - 筛选参数组装
  - 分配订单周期选项生成
- API 层在 `src/api/admin.ts` 中新增订单接口封装；类型定义统一收敛到 `src/types/api.d.ts`。

## 完成定义
- 侧边栏中的“订单管理”不再是禁用入口，能正常进入 `#/subscriptions/orders`。
- 订单列表可真实连接 `/order/fetch`，并响应搜索、筛选、排序与分页。
- 订单详情抽屉可真实连接 `/order/detail`，且能触发已支付、取消、佣金状态更新。
- 分配订单抽屉可真实连接 `/order/assign`。
- 订单金额相关字段统一正确展示为人民币元值。

## 文件结构
- `admin-frontend/src/router/index.ts`
- `admin-frontend/src/layouts/AdminLayout.vue`
- `admin-frontend/src/api/admin.ts`
- `admin-frontend/src/types/api.d.ts`
- `admin-frontend/src/utils/orders.ts`
- `admin-frontend/src/views/subscriptions/OrdersView.vue`
- `admin-frontend/src/views/subscriptions/OrdersView.scss`
- `admin-frontend/src/views/subscriptions/OrderAssignDrawer.vue`
- `admin-frontend/src/views/subscriptions/OrderDetailDrawer.vue`

## UI / 设计约束
- 列表页以白色工作台为主，不堆叠多余卡片；重点放在表格可读性与运营效率。
- 订单号作为主入口，点击后进入详情抽屉，不额外拉长操作列。
- 筛选入口使用紧凑 pill 风格按钮，对齐截图中的轻量筛选条。
- 详情抽屉用黑色 hero + 白色信息卡的节奏，兼顾 Apple 风格与运营后台的信息密度。

## 风险与验证
- 风险 1：`/order/fetch` 返回的 `period` 已被后端转换成 legacy key，筛选时需要继续使用数据库真实值。
- 风险 2：订单金额与佣金金额在后端仍以分为单位存储，若前端直接展示会再次出现后台金额口径错误。
- 风险 3：本地环境缺少真实后台登录态时，只能做结构与构建验证，不能替代完整联调。
- 验证方式：
  - `npm run build`
  - 代码级结构自检 `#/subscriptions/orders`
  - 结构化视觉验收记录（无浏览器工具时以 code inspection 说明边界）

## 决策记录
- [2026-04-24] 订单主操作收口到详情抽屉，不额外新增宽操作列，优先对齐用户截图。
- [2026-04-24] 金额展示统一由 `src/utils/orders.ts` 处理，避免分/元换算逻辑散落在页面组件。
- [2026-04-24] 分配订单抽屉默认按所选套餐周期自动回填金额，但允许运营手动覆盖。
