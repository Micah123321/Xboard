# 任务清单: admin-frontend-theme-management

> **@status:** completed | 2026-04-24 16:12

```yaml
@feature: admin-frontend-theme-management
@created: 2026-04-24
@status: completed
@mode: R2
```

## 进度概览

| 完成 | 失败 | 跳过 | 总数 |
|------|------|------|------|
| 5 | 0 | 0 | 5 |

---

## 任务列表

### 1. 数据与路由准备

- [√] 1.1 在 `admin-frontend/src/types/api.d.ts` 与 `admin-frontend/src/api/admin.ts` 中补齐主题管理类型、配置接口与上传接口 | depends_on: []
- [√] 1.2 新增 `admin-frontend/src/utils/themes.ts`，统一处理主题列表排序、动态配置默认值和序列化逻辑 | depends_on: [1.1]
- [√] 1.3 在 `admin-frontend/src/router/index.ts` 中把 `system/themes` 路由切换到真实页面 | depends_on: [1.1]

### 2. 主题管理页面实现

- [√] 2.1 新增 `admin-frontend/src/views/system/ThemesView.vue`，实现标题区、主题卡片、当前主题标记、切换和上传按钮 | depends_on: [1.1,1.2,1.3]
- [√] 2.2 新增 `admin-frontend/src/views/system/ThemeConfigDrawer.vue`，实现主题设置抽屉、动态字段表单与保存动作 | depends_on: [1.1,1.2]

### 3. 验证与同步

- [√] 3.1 运行 `admin-frontend` 构建验证，并结合页面代码完成主题管理视觉/交互自检 | depends_on: [2.1,2.2]

---

## 执行日志

| 时间 | 任务 | 状态 | 备注 |
|------|------|------|------|
| 2026-04-24 16:07 | 方案包初始化 | completed | 已确认本轮范围为主题列表、当前主题切换、主题设置抽屉与上传主题 |
| 2026-04-24 16:09 | 1.1 / 1.2 / 1.3 | completed | 已补齐主题类型、API、工具函数，并将 `system/themes` 指向真实页面 |
| 2026-04-24 16:11 | 2.1 / 2.2 | completed | 已完成主题卡片页与动态设置抽屉，接入真实主题配置/上传能力 |
| 2026-04-24 16:12 | 3.1 | completed | `npm run build` 通过；当前环境缺少后台登录态与浏览器工具，已完成代码级视觉自检 |

---

## 执行备注

- “设为当前主题”当前复用 `config/save(frontend_theme)` 完成，因为管理路由中没有公开独立的主题切换 endpoint。
- 本轮未实现删除主题，避免在没有额外确认和危险操作设计的情况下引入破坏性入口。
- `public/assets/admin` 为构建产物子模块；本轮构建已刷新对应产物，但未代做子模块提交与根仓发布。
