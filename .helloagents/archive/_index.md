# 方案归档索引

> 通过此文件快速查找历史方案
> 历史年份: [2024](_index-2024.md) | [2023](_index-2023.md) | ...

## 快速索引（当前年份）

| 时间戳 | 名称 | 类型 | 涉及模块 | 决策 | 结果 |
|--------|------|------|---------|------|------|
| 202604241703 | admin-frontend-gift-card-management | implementation | admin-frontend | admin-frontend-gift-card-management#D001,#D002,#D003 | ✅完成 |
| 202604241659 | admin-frontend-node-group-management | implementation | admin-frontend | admin-frontend-node-group-management#D001,#D002,#D003 | ✅完成 |
| 202604241655 | admin-frontend-sidebar-height-overflow | - | - | - | ✅完成 |
| 202604241620 | admin-frontend-order-management | implementation | admin-frontend | admin-frontend-order-management#D001,#D002,#D003 | ✅完成 |
| 202604241558 | admin-frontend-payment-management | implementation | admin-frontend | admin-frontend-payment-management#D001,#D002,#D003 | ✅完成 |
| 202604241553 | admin-frontend-plugin-management | implementation | admin-frontend | admin-frontend-plugin-management#D001,#D002,#D003 | ✅完成 |
| 202604241607 | admin-frontend-theme-management | implementation | admin-frontend | admin-frontend-theme-management#D001,#D002 | ✅完成 |
| 202604241551 | admin-frontend-coupon-management | implementation | admin-frontend | admin-frontend-coupon-management#D001,#D002,#D003 | ✅完成 |
| 202604232345 | traffic-rank-limit-backend-adapt | implementation | admin-frontend,backend | traffic-rank-limit-backend-adapt#D001,#D002 | ✅完成 |
| 202604232329 | admin-frontend-system-management | implementation | admin-frontend | admin-frontend-system-management#D001,#D002,#D003 | ✅完成 |
| 202604232320 | admin-frontend-node-management | implementation | admin-frontend | admin-frontend-node-management#D001,#D002 | ✅完成 |
| 202604232318 | admin-frontend-rank-limit-scroll | implementation | admin-frontend | admin-frontend-rank-limit-scroll#D001,#D002 | ✅完成 |
| 202604232313 | admin-frontend-dashboard-trend-count-toggle | implementation | admin-frontend | admin-frontend-dashboard-trend-count-toggle#D001,#D002 | ✅完成 |
| 202604231515 | admin-frontend-dashboard-refresh-button | implementation | admin-frontend | admin-frontend-dashboard-refresh-button#D001,#D002 | ✅完成 |
| 202604210441 | admin-frontend-user-management | - | - | - | ✅完成 |
| 202604210400 | admin-frontend-apple-performance-refresh | implementation | admin-frontend | admin-frontend-apple-performance-refresh#D001,#D002 | ✅完成 |
| 202604210326 | admin-frontend-composio-dashboard | implementation | admin-frontend | admin-frontend-composio-dashboard#D001,#D002 | ✅完成 |
| 202604180040 | optimize-docker-publish-workflow | - | - | - | ✅完成 |
| 202604180029 | fix-clashmeta-flow-map-export | - | - | - | ✅完成 |
| 202604161703 | create-git-merge-preserve-local-skill | - | - | - | ✅完成 |
| 202604161655 | merge-upstream-preserve-local | - | - | - | ✅完成 |

## 按月归档

### 2026-04
- [202604241703_admin-frontend-gift-card-management](./2026-04/202604241703_admin-frontend-gift-card-management/) - 开放“礼品卡管理”入口，交付模板管理、兑换码管理、使用记录与统计数据四页签工作台，并接入真实 gift-card 接口
- [202604241659_admin-frontend-node-group-management](./2026-04/202604241659_admin-frontend-node-group-management/) - 将 `#/node-groups` 从占位页升级为真实权限组管理工作台，并补齐到 `#/nodes` 的权限组筛选联动
- [202604241620_admin-frontend-order-management](./2026-04/202604241620_admin-frontend-order-management/) - 开放“订单管理”入口，交付真实订单列表、筛选、分配订单、详情抽屉、手动支付与佣金状态维护
- [202604241558_admin-frontend-payment-management](./2026-04/202604241558_admin-frontend-payment-management/) - 将 `#/system/payments` 从占位页升级为真实支付配置工作台，接入支付方式列表、动态配置抽屉、启停、删除与排序
- [202604241553_admin-frontend-plugin-management](./2026-04/202604241553_admin-frontend-plugin-management/) - 将 `#/system/plugins` 从占位页升级为真实插件管理工作台，接入插件列表、筛选、上传、安装、启停、升级、卸载，以及 README / 动态配置抽屉
- [202604241607_admin-frontend-theme-management](./2026-04/202604241607_admin-frontend-theme-management/) - 将 `#/system/themes` 从占位页升级为真实主题管理页面，接入主题列表、当前主题切换、动态配置抽屉与 zip 主题上传
- [202604241551_admin-frontend-coupon-management](./2026-04/202604241551_admin-frontend-coupon-management/) - 开放“优惠券管理”入口，完整交付优惠券列表、类型筛选、启停、删除，以及接入真实 coupon 接口的新增/编辑弹窗
- [202604232345_traffic-rank-limit-backend-adapt](./2026-04/202604232345_traffic-rank-limit-backend-adapt/) - traffic rank 接口新增 limit=10|20 支持，并让 dashboard 的 10/20 切换真正驱动后端返回条数
- [202604232329_admin-frontend-system-management](./2026-04/202604232329_admin-frontend-system-management/) - 新增“系统管理”侧边栏分组，完整交付系统配置页，并接入插件/主题/公告/支付/知识库结构化占位页
- [202604232320_admin-frontend-node-management](./2026-04/202604232320_admin-frontend-node-management/) - 新增“节点管理”侧边栏分组、节点管理工作台，以及权限组/路由管理占位页
- [202604232318_admin-frontend-rank-limit-scroll](./2026-04/202604232318_admin-frontend-rank-limit-scroll/) - 仪表盘节点流量排行和用户流量排行新增 10/20 显示切换，并在面板内提供滚动查看
- [202604232313_admin-frontend-dashboard-trend-count-toggle](./2026-04/202604232313_admin-frontend-dashboard-trend-count-toggle/) - 仪表盘收入趋势新增“按金额 / 按数量”切换，并让摘要和最近记录同步切换口径
- [202604231515_admin-frontend-dashboard-refresh-button](./2026-04/202604231515_admin-frontend-dashboard-refresh-button/) - 为 Apple 风格仪表盘新增 Hero 区“刷新全部数据”按钮，并补齐加载反馈与最后刷新时间
- [202604210441_admin-frontend-user-management](./2026-04/202604210441_admin-frontend-user-management/) - 新增用户管理工作台、抽屉表单、用户操作菜单，以及“用户管理 / 工单管理”导航与路由骨架
- [202604210400_admin-frontend-apple-performance-refresh](./2026-04/202604210400_admin-frontend-apple-performance-refresh/) - Apple 风格重构登录页、主布局和仪表盘，并移除高成本装饰层以缓解卡顿
- [202604210326_admin-frontend-composio-dashboard](./2026-04/202604210326_admin-frontend-composio-dashboard/) - 深色 Composio 风格管理端仪表盘、登录回跳与真实统计数据接入

## 结果状态说明
- ✅ 完成
- ⚠️ 部分完成
- ❌ 失败/中止
- ⏸ 未执行
- 🔄 已回滚
- 📄 概述
