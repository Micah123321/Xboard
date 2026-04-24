# 变更提案: admin-frontend-theme-management

## 元信息
```yaml
类型: 新功能
方案类型: implementation
优先级: P1
状态: 已完成
创建: 2026-04-24
```

---

## 1. 需求

### 背景
当前 `admin-frontend` 已经完成系统配置真实页，但 `#/system/themes` 仍停留在结构化占位状态。用户本轮要求继续基于 `apple/DESIGN.md`、项目级 `.helloagents/DESIGN.md` 和提供的参考图，把“主题配置”升级为真实可用的主题管理页面。

### 目标
- 将 `#/system/themes` 从占位页升级为真实主题管理工作台。
- 展示可用主题列表、当前主题标记和主题基础信息。
- 提供主题设置抽屉，支持动态主题配置保存。
- 提供 zip 主题包上传入口，并支持把某个主题设为当前主题。

### 约束条件
```yaml
技术约束: 继续使用 Vue3 + TypeScript + Element Plus + Vite，不新增主题编辑器或上传库
业务约束: 后端沿用现有 `theme/*` 与 `config/save(frontend_theme)` 能力，不修改 Laravel API
范围约束: 本轮先完成主题列表、当前主题切换、主题设置与上传；删除主题保留到下一轮
视觉约束: 保持 Apple 化后台语义，页面结构优先贴近参考图中的“标题 + 说明 + 主题卡片 + 上传入口”
```

### 验收标准
- [ ] `#/system/themes` 能展示真实主题列表，不再使用占位页。
- [ ] 页面能明确标记当前主题，并允许把其他主题设为当前主题。
- [ ] 点击“主题设置”可打开配置抽屉，按主题 schema 渲染字段并保存。
- [ ] 页面提供 zip 主题包上传入口，并接入真实后端上传接口。
- [ ] `admin-frontend` 构建通过，且知识库记录主题管理已从占位页升级为真实页面。

---

## 2. 方案

### 技术方案
1. 在 `src/types/api.d.ts` 与 `src/api/admin.ts` 中补齐主题列表、主题配置和主题上传所需类型与接口封装。
2. 新增 `src/utils/themes.ts`，统一处理主题列表排序、配置默认值回填和表单序列化。
3. 新增 `src/views/system/ThemesView.vue` 与 `ThemeConfigDrawer.vue`：
   - `ThemesView` 负责标题区、当前主题/数量摘要、主题卡片、上传按钮与切换逻辑。
   - `ThemeConfigDrawer` 负责根据主题 schema 动态渲染配置项并保存。
4. 在 `src/router/index.ts` 中把 `system/themes` 路由从占位页切换到真实页面组件。

### 风险评估
| 风险 | 等级 | 应对 |
|------|------|------|
| 主题切换没有独立 `switchTheme` 路由 | 中 | 复用 `config/save` 的 `frontend_theme` 保存链路，由后端内部触发 `ThemeService::switch` |
| 主题配置字段完全动态，前端容易误假设字段类型 | 中 | 仅支持后端 schema 已声明的 `input / textarea / select` 三类字段 |
| 本地缺少后台登录态与浏览器截图工具 | 低 | 本轮以构建通过 + 代码级视觉自检作为验收证据，并在知识库中注明限制 |

---

## 3. 技术决策

### admin-frontend-theme-management#D001: 主题切换复用 `config/save(frontend_theme)`，不前端直连未开放路由
**日期**: 2026-04-24
**状态**: ✅采纳
**背景**: 后端 `ThemeController` 存在 `switchTheme()`，但当前管理路由未公开对应 endpoint。
**决策**: 前端将“设为当前主题”统一走 `saveAdminConfig({ frontend_theme })`。
**理由**: 这条链路已被 `ConfigController` 正式支持，且内部会触发 `ThemeService->switch`，无需扩展后端接口。

### admin-frontend-theme-management#D002: 主题配置使用右侧抽屉，不在卡片内直接展开表单
**日期**: 2026-04-24
**状态**: ✅采纳
**背景**: 参考图强调卡片式主题列表；主题配置字段是动态 schema，直接展开会破坏列表节奏。
**决策**: 卡片只承载主题信息与主操作，详细配置统一放进 `ThemeConfigDrawer`。
**理由**: 更贴合 Apple 化后台“主列表 + 聚焦设置面板”的结构，也便于后续扩展删除/预览等动作。
