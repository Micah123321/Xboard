# 任务清单: admin-frontend-coupon-management

> **@status:** completed | 2026-04-24 16:28

```yaml
@feature: admin-frontend-coupon-management
@created: 2026-04-24
@status: completed
@mode: R2
```

## 进度概览

| 完成 | 失败 | 跳过 | 总数 |
|------|------|------|------|
| 6 | 0 | 0 | 6 |

---

## 任务列表

### 1. 数据与路由准备

- [√] 1.1 在 `admin-frontend/src/types/api.d.ts` 与 `admin-frontend/src/api/admin.ts` 中补齐优惠券列表、保存载荷与接口封装 | depends_on: []
- [√] 1.2 新增 `admin-frontend/src/utils/coupons.ts`，实现优惠券类型/周期选项、时间与表单转换、列表过滤与过期状态计算 | depends_on: [1.1]
- [√] 1.3 在 `admin-frontend/src/layouts/AdminLayout.vue` 与 `admin-frontend/src/router/index.ts` 中开放“优惠券管理”菜单与路由 | depends_on: [1.1]

### 2. 优惠券页面实现

- [√] 2.1 新增 `admin-frontend/src/views/subscriptions/CouponsView.vue` 与样式，实现列表、搜索、类型筛选、本地分页、启停、编辑与删除入口 | depends_on: [1.1,1.2,1.3]
- [√] 2.2 新增 `admin-frontend/src/views/subscriptions/CouponEditorDialog.vue` 与样式，实现新增/编辑弹窗、双列表单、指定周期/订阅、多码生成与提交保存 | depends_on: [1.1,1.2,1.3]

### 3. 验证与同步

- [√] 3.1 运行 `admin-frontend` 构建验证，并结合页面代码完成优惠券管理视觉/交互自检 | depends_on: [2.1,2.2]

---

## 执行日志

| 时间 | 任务 | 状态 | 备注 |
|------|------|------|------|
| 2026-04-24 15:51 | 方案包初始化 | completed | 已确认本轮范围为完整优惠券管理接入，采用 Apple 化后台风格 |
| 2026-04-24 16:06 | 1.1 / 1.2 / 1.3 | completed | 已补齐 coupon 类型、接口、工具函数，并开放“优惠券管理”菜单与路由 |
| 2026-04-24 16:18 | 2.1 / 2.2 | completed | 已完成优惠券列表页与新增/编辑弹窗，接入真实 coupon 接口与套餐限制项 |
| 2026-04-24 16:28 | 3.1 | completed | `npm run build` 通过；当前环境缺少可复用浏览器与登录态，仅完成代码级视觉/交互自检 |

---

## 执行备注

> 记录执行过程中的重要说明、决策变更、风险提示等

- 当前工作树可能存在与本轮无关的其他改动，实施过程中需保持最小作用域，不覆盖既有未提交修改。
- 当前后端缺少专门的 coupon detail/save 分离接口，编辑流程将复用 `coupon/generate`，并在前端统一做字段序列化。
- 当前视觉验收受限于本地缺少可复用的后台登录态与浏览器截图工具，本轮已通过构建产物、组件结构与样式代码完成代码级验收，建议后续在真实管理登录态下补一次人工点检。
