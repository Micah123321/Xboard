# 变更提案: admin-frontend-notice-management

## 元信息
```yaml
类型: 功能开发
方案类型: implementation
优先级: P1
状态: 已完成
创建: 2026-04-24
```

---

## 1. 需求

### 背景
用户要求继续完成 `admin-frontend` 的“公告管理”模块，并明确选择“完整 CRUD 工作台”方案。当前 `/system/notices` 仍是占位页，但 Laravel 后端已存在 `notice/fetch`、`notice/save`、`notice/show`、`notice/drop`、`notice/sort` 管理接口，可直接承接真实公告管理工作流。

### 目标
- 将 `#/system/notices` 从占位页升级为真实可用的公告管理页。
- 提供符合当前 Apple 风格后台体系的公告列表、搜索、显隐切换、编辑弹窗、删除与排序能力。
- 保持前后端字段契约与现有 `NoticeController` / `NoticeSave` 一致，不在前端猜测额外接口。

### 约束条件
```yaml
范围约束: 仅实现公告管理工作台，不扩展插件/主题/支付/知识库等其他系统模块
技术约束: 继续使用 Vue3 + TypeScript + Element Plus，不新增第三方编辑器依赖
业务约束: 公告保存字段以 title/content/img_url/tags/show/popup/id 为准；排序继续调用 /notice/sort
视觉约束: 延续 apple/DESIGN.md 与 .helloagents/DESIGN.md 的黑色 hero + 白色工作台 + 克制蓝色交互体系
```

### 验收标准
- [ ] `#/system/notices` 可真实拉取公告列表，并显示 ID、显隐状态、标题与操作列。
- [ ] 页面支持标题关键词搜索，筛选后结果与计数同步更新。
- [ ] 支持新增/编辑公告，字段覆盖标题、内容、背景图、标签、显示状态与弹窗公告开关。
- [ ] 支持删除公告与显隐切换，并给出明确成功/失败反馈。
- [ ] 支持独立排序模式，保存后调用 `/notice/sort` 同步顺序。
- [ ] `admin-frontend` 执行 `npm run build` 通过。

---

## 2. 方案

### 页面结构
1. 延续系统配置/套餐管理的 Apple 风格后台结构，顶部保留黑色 hero，右侧展示公告统计摘要。
2. 主工作区使用白色表格容器，头部提供“添加公告”“搜索公告标题”“编辑排序”。
3. 编辑公告使用 `ElDialog` 弹窗，参考用户截图采用“标题 + 公告内容 + 公告背景 + 节点标签 + 显示开关”的集中编辑方式。
4. 排序使用独立对话框，通过上移/下移维护本地顺序，再提交到 `/notice/sort`。

### 前端实现策略
1. 在 `src/types/api.d.ts` 新增公告类型，明确列表项与保存载荷结构。
2. 在 `src/api/admin.ts` 新增公告管理 API 封装：`fetchNotices / saveNotice / toggleNoticeVisibility / deleteNotice / sortNotices`。
3. 在 `src/utils/notices.ts` 下沉公告筛选、标签归一化、表单回填、排序移动等逻辑，避免页面组件膨胀。
4. 新增：
   - `src/views/system/SystemNoticesView.vue`
   - `src/views/system/SystemNoticeEditorDialog.vue`
   - `src/views/system/SystemNoticeEditorDialog.scss`
5. 将路由 `/system/notices` 指向真实页面，保留其他系统入口占位不变。

### 风险评估
| 风险 | 等级 | 应对 |
|------|------|------|
| 后端 `show` / `popup` 可能返回 `0/1` | 中 | 前端统一布尔归一化，提交时再按接口兼容输出 |
| 公告内容编辑器缺少富文本能力 | 低 | 本轮延续轻量文本/Markdown 输入策略，优先保证 CRUD 与数据流闭环 |
| 构建会刷新 `public/assets/admin` 子模块产物 | 中 | 仅执行 `admin-frontend` 构建验证，不代做子模块发布 |

---

## 3. 技术决策

### admin-frontend-notice-management#D001: 公告管理采用“真实列表页 + 独立编辑弹窗 + 独立排序对话框”
**日期**: 2026-04-24  
**状态**: ✅采纳  
**背景**: 用户明确要求对齐截图所表达的后台管理工作台体验。  
**决策**: 列表与行操作保留在主页面，新增/编辑使用集中弹窗，排序单独进入对话框处理。  
**理由**: 最符合当前后台已有套餐管理模式，也能覆盖截图中的核心操作链路。

### admin-frontend-notice-management#D002: 公告内容编辑延续轻量文本输入，不引入新的富文本依赖
**日期**: 2026-04-24  
**状态**: ✅采纳  
**背景**: 现有 `admin-frontend` 已尽量控制依赖规模，且后端只要求标题/内容/图片/标签等基本字段。  
**决策**: 本轮使用增强型 textarea 输入公告内容，并保留 Markdown 友好提示，不新增第三方富文本编辑器。  
**理由**: 优先完成真实 CRUD 与可维护数据流，避免为了富文本皮层扩大实现成本。

### admin-frontend-notice-management#D003: 公告显隐与弹窗开关在前端统一做布尔归一化
**日期**: 2026-04-24  
**状态**: ✅采纳  
**背景**: 后台现有开关型字段历史上存在 `0/1` 与布尔混用情况。  
**决策**: 拉取、回填和提交前都通过工具层统一归一化 `show / popup / tags`。  
**理由**: 可避免再次出现 Element Plus 开关初始渲染误触发写操作的回归。
