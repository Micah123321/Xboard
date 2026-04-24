# 变更提案: admin-frontend-knowledge-management

## 元信息
```yaml
类型: 功能增强
方案类型: implementation
优先级: P1
状态: 已完成
创建: 2026-04-24
```

---

## 1. 需求

### 背景
`admin-frontend` 当前已经完成系统管理分组与系统配置页，但 `#/system/knowledge` 仍停留在结构化占位页。用户已提供目标截图，希望继续补齐真实“知识库管理”页面，并保持现有 Apple 化后台的低噪音运营风格。

### 目标
- 将 `#/system/knowledge` 从占位页升级为真实知识库管理工作台。
- 支持知识列表查看、搜索、分类筛选、显隐切换、编辑排序、删除和新增/编辑。
- 编辑器采用用户刚确认的“轻量 Markdown 编辑器”方案，支持常用格式插入与预览，不新增富文本依赖。

### 约束条件
```yaml
范围约束: 仅实现 admin-frontend 的知识库管理页，不改 Laravel 后端接口行为
技术约束: 继续使用 Vue3 + TypeScript + Element Plus + markdown-it 现有栈，不新增第三方编辑器依赖
视觉约束: 保持 Apple 风格后台气质，贴近用户截图中的“轻表格 + 中央编辑弹窗”结构
业务约束: 后端真相源固定为 knowledge/fetch、knowledge/getCategory、knowledge/save、knowledge/show、knowledge/drop、knowledge/sort
```

### 验收标准
- [√] `#/system/knowledge` 可以展示真实知识列表，并支持关键字搜索与分类筛选。
- [√] 列表支持显隐切换、删除、排序调整与编辑入口。
- [√] 新增/编辑弹窗支持标题、分类、语言、显示状态与正文编辑；正文采用轻量 Markdown 方案并支持预览。
- [√] `admin-frontend` 构建通过，产物成功输出到 `public/assets/admin`。

---

## 2. 方案

### 信息架构
1. 列表页采用“页头说明 + 操作工具条 + 白色数据表格”结构，贴近用户截图的运营后台感。
2. 编辑页采用中央 `ElDialog`，而非侧滑抽屉，保持与截图一致的工作流。
3. 排序采用本地编辑对话框，复用当前列表顺序生成 `ids`，再调用 `/knowledge/sort`。

### 技术方案
1. 在 `src/types/api.d.ts` 与 `src/api/admin.ts` 中补充知识库实体类型和请求封装。
2. 新增 `src/utils/knowledge.ts`，统一处理分类、表单模型、Markdown 渲染和本地过滤逻辑。
3. 新建 `SystemKnowledgeView.vue` 与 `KnowledgeEditorDialog.vue`，分别承载知识库列表页和编辑弹窗。
4. 路由层将 `/system/knowledge` 从 `SystemPlaceholderView` 切换为真实页面组件。

### 风险评估
| 风险 | 等级 | 应对 |
|------|------|------|
| 后端列表接口不返回正文与语言 | 中 | 编辑时单独调用 `knowledge/fetch?id=` 拉取详情 |
| 轻量 Markdown 编辑体验弱于完整富文本 | 低 | 用工具栏 + 预览补齐高频编辑动作，优先满足当前截图与范围 |
| 排序与显隐属于真实写操作 | 中 | 保持明确按钮、反馈提示与失败回滚，避免静默提交 |

---

## 3. 技术决策

### admin-frontend-knowledge-management#D001: 编辑器采用轻量 Markdown 方案
**日期**: 2026-04-24
**状态**: ✅采纳
**背景**: 用户在执行前确认选择了“轻量 Markdown 编辑器（推荐）”。
**决策**: 复用仓内已有 `markdown-it` 能力，自建工具栏 + 预览编辑器，不引入额外富文本依赖。
**理由**: 能更快贴近当前截图的编辑体验，同时控制依赖和实现复杂度。

### admin-frontend-knowledge-management#D002: 列表页采用真实表格，编辑页采用中央对话框
**日期**: 2026-04-24
**状态**: ✅采纳
**背景**: 用户截图呈现的是“列表页 + 中央弹窗”的运营后台工作流。
**决策**: 保持列表、筛选、开关、排序留在主页面，新增/编辑放入对话框集中处理。
**理由**: 更符合知识库管理的批量维护场景，也能最大程度贴合用户提供的视觉参考。

### admin-frontend-knowledge-management#D003: 排序采用本地草稿编辑后统一提交
**日期**: 2026-04-24
**状态**: ✅采纳
**背景**: 后端排序接口为 `POST /knowledge/sort`，需要提交有序 `ids`。
**决策**: 先在前端弹窗维护当前顺序草稿，再一次性提交排序结果。
**理由**: 避免列表页直接拖拽带来的交互复杂度和误操作风险，保持后台操作克制清晰。
