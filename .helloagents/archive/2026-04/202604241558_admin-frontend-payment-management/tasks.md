# 任务清单: admin-frontend-payment-management

> **@status:** completed | 2026-04-24 16:14

```yaml
@feature: admin-frontend-payment-management
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

- [√] 1.1 在 `admin-frontend/src/types/api.d.ts` 与 `admin-frontend/src/api/admin.ts` 中补齐支付方式列表、动态配置字段与接口封装 | depends_on: []
- [√] 1.2 新增 `admin-frontend/src/utils/payments.ts`，实现支付方式归一化、过滤、排序移动与保存序列化 | depends_on: [1.1]
- [√] 1.3 在 `admin-frontend/src/router/index.ts` 中将 `/system/payments` 切换到真实支付配置页面 | depends_on: [1.1]

### 2. 支付配置页面实现

- [√] 2.1 新增 `admin-frontend/src/views/system/SystemPaymentsView.vue` 与样式，实现列表、搜索、启停、删除、新增/编辑入口与排序对话框 | depends_on: [1.1,1.2,1.3]
- [√] 2.2 新增 `admin-frontend/src/views/system/SystemPaymentEditorDrawer.vue` 与样式，实现动态支付接口配置抽屉与保存提交流程 | depends_on: [1.1,1.2,1.3]

### 3. 验证与同步

- [√] 3.1 运行 `admin-frontend` 构建验证，并结合页面代码完成支付配置视觉/交互自检 | depends_on: [2.1,2.2]

---

## 执行日志

| 时间 | 任务 | 状态 | 备注 |
|------|------|------|------|
| 2026-04-24 15:58 | 方案包初始化 | completed | 已确认本轮范围为支付配置完整 CRUD 工作台，目标对齐用户截图与 `/payment/*` 后端接口 |
| 2026-04-24 16:05 | 1.1 / 1.2 / 1.3 | completed | 已补齐支付配置类型、API、工具层与真实路由切换 |
| 2026-04-24 16:11 | 2.1 / 2.2 | completed | 已完成支付配置列表、排序对话框与动态支付配置抽屉 |
| 2026-04-24 16:14 | 3.1 | completed | `npm run build` 通过；当前环境无浏览器自动化工具，已完成代码级视觉与交互自检 |

---

## 执行备注

- 当前工作树存在与本轮无关的其他未提交修改，实施时需保持最小作用域，不覆盖已有 in-progress 改动。
- 构建验证会刷新 `public/assets/admin` 子模块产物，本轮仅提供功能实现与验证证据，不自动代做发布。
