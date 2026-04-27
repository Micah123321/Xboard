# 变更提案: ticket-chat-image-dnd-paste-upload

## 元信息
```yaml
类型: 新功能
方案类型: implementation
优先级: P2
状态: 已确认
创建: 2026-04-27
```

---

## 1. 需求

### 背景
`admin-frontend` 工单工作台当前已支持通过按钮选择图片，并将上传后的 URL 以 Markdown 图片语法插入回复框。客服处理截图类问题时，图片常来自本地文件拖入或剪贴板截图，单一按钮选择会降低连续回复效率。

### 目标
- 在 `admin-frontend/src/views/tickets/TicketWorkspaceDialog.vue` 的工单聊天回复区域支持拖拽图片上传。
- 在回复框聚焦时支持从剪贴板粘贴图片上传。
- 复用现有 `uploadImage()`、图片类型校验、10MB 大小限制和 Markdown 图片插入方式。
- 保留原有点击上传入口，并补齐上传中、拖拽悬停、失败提示等状态反馈。
- 将超大 SFC 的样式拆分到同目录 SCSS 文件，避免继续扩大 533 行组件文件。

### 约束条件
```yaml
时间约束: 无
性能约束: 不引入新依赖；拖拽/粘贴只处理图片文件，不做额外预览缓存
兼容性约束: 保持 Vue3 + Element Plus + Vite 现有技术栈；保留原有 /upload/rest/upload 上传接口
业务约束: 不修改后端工单回复语义，不改变 replyTicket payload，仅插入 Markdown 图片链接
```

### 验收标准
- [ ] 点击“上传图片”仍可上传图片并插入 `![image](url)`。
- [ ] 拖拽图片到回复区域可上传并插入 Markdown 图片。
- [ ] 在回复框中粘贴剪贴板图片可上传并插入 Markdown 图片。
- [ ] 非图片文件、超过 10MB 图片会被拒绝并展示明确提示。
- [ ] 上传中回复区有可见状态，重复上传不会破坏现有回复内容。
- [ ] `npm run build` 在 `admin-frontend` 通过。

---

## 2. 方案

### 技术方案
在 `TicketWorkspaceDialog.vue` 中抽出统一的 `uploadReplyImages(files, source)` 流程：从 Element Plus 上传、拖拽事件、粘贴事件统一收敛到同一校验与上传路径。回复区外层增加 drag/drop 事件和视觉状态，`ElInput` 增加 paste 事件。上传成功后继续以 Markdown 图片语法追加到 `replyMessage`，失败时按具体来源给出错误提示。样式迁移到 `TicketWorkspaceDialog.scss`，并新增拖拽激活态、上传提示行和响应式细节。

### 影响范围
```yaml
涉及模块:
  - admin-frontend: 工单工作台回复区图片上传交互
预计变更文件: 4
```

### 风险评估
| 风险 | 等级 | 应对 |
|------|------|------|
| 粘贴文本时误触上传逻辑 | 低 | 仅当 clipboardData 中存在 image File 时拦截默认行为，否则保持原粘贴行为 |
| 多图拖拽上传时状态混乱 | 中 | 顺序上传并复用同一个上传状态，逐个插入 Markdown，失败时提示具体失败原因 |
| 样式拆分造成 scoped 样式失效 | 低 | 使用项目已有 `<style scoped lang="scss" src="./*.scss">` 模式，并通过构建验证 |
| 上传接口不可用导致本地无法端到端验证 | 中 | 构建验证覆盖类型和打包，真实上传需在可访问 `/upload/rest/upload` 的环境人工核对 |

### 方案取舍
```yaml
唯一方案理由: 当前路径复用现有上传工具和 Markdown 写入规则，新增交互入口但不改变后端契约，风险最小且符合客服聊天输入场景。
放弃的替代路径:
  - 引入新的富文本/附件组件: 会增加依赖和迁移成本，且当前消息渲染以 Markdown 为事实标准。
  - 新增图片预览队列: 本需求只要求上传方式增强，预览队列会扩大状态管理和失败恢复范围。
  - 修改后端工单消息结构为附件字段: 会跨前后端协议，超出本次图片上传交互增强范围。
回滚边界: 回滚 `TicketWorkspaceDialog.vue` 中拖拽/粘贴逻辑和 `TicketWorkspaceDialog.scss` 样式拆分即可，不涉及数据库、接口或构建配置。
```

---

## 3. 技术设计

### 事件流程
```mermaid
flowchart TD
    A[点击上传 / 拖拽放下 / 粘贴图片] --> B[提取 File[]]
    B --> C[beforeImageUpload 校验类型与大小]
    C --> D[uploadImage(file)]
    D --> E[追加 Markdown 图片到 replyMessage]
    D --> F[失败时展示 ElMessage.error]
```

### API 设计
无新增 API。继续使用 `uploadImage(file)` 访问 `/upload/rest/upload`。

### 数据模型
无数据模型变更。

---

## 4. 核心场景

### 场景: 客服从剪贴板粘贴截图回复工单
**模块**: admin-frontend  
**条件**: 管理员打开工单工作台并聚焦回复框  
**行为**: 管理员复制截图后在回复框中粘贴  
**结果**: 图片自动上传，回复框追加 Markdown 图片链接，管理员可继续输入文字并发送

### 场景: 客服拖拽本地图片到回复区
**模块**: admin-frontend  
**条件**: 管理员打开工单工作台并准备回复  
**行为**: 管理员把本地图片拖入回复区域并松开  
**结果**: 回复区显示拖拽激活和上传中状态，上传成功后插入 Markdown 图片链接

---

## 5. 技术决策

### ticket-chat-image-dnd-paste-upload#D001: 统一图片入口到现有 Markdown 上传链路
**日期**: 2026-04-27  
**状态**: ✅采纳  
**背景**: 工单消息当前以 Markdown 渲染，图片上传已经通过 `uploadImage()` 返回可复制 URL。  
**选项分析**:
| 选项 | 优点 | 缺点 |
|------|------|------|
| A: 复用现有上传工具并追加 Markdown | 后端零变更、行为一致、实现集中 | 无本地预览队列 |
| B: 引入附件队列和独立发送 payload | 可展示上传前预览 | 需要后端协议或发送语义调整 |
| C: 接入富文本编辑器 | 编辑体验更强 | 依赖和迁移成本高，与当前 Markdown 渲染不一致 |
**决策**: 选择方案 A  
**理由**: 满足拖拽和粘贴上传目标，同时保持当前工单消息的渲染、发送和后端语义不变。  
**影响**: 仅影响管理端工单工作台图片输入体验。

---

## 6. 验证策略

```yaml
verifyMode: review-first
reviewerFocus:
  - admin-frontend/src/views/tickets/TicketWorkspaceDialog.vue 中拖拽、粘贴、点击上传是否复用同一校验链路
  - admin-frontend/src/views/tickets/TicketWorkspaceDialog.scss 是否只承载原样式拆分和新增状态样式
testerFocus:
  - npm run build
  - 人工核对点击上传、拖拽上传、粘贴上传、非图片拒绝、超 10MB 拒绝
uiValidation: optional
riskBoundary:
  - 不修改后端接口和数据库
  - 不覆盖现有 public/assets/admin 未提交构建产物改动
  - 不引入新依赖
```

---

## 7. 成果设计

### 设计方向
- **美学基调**: Apple 式精确客服工作台，白色回复区、单一 Apple Blue 强调和克制状态层，强调“把截图放到对话里”的直接感。
- **记忆点**: 拖拽图片进入回复区时出现轻量蓝色描边和提示条，像系统级 drop target 一样明确但不打断会话。
- **参考**: `apple/DESIGN.md` 中的纯色表面、系统字体、单一蓝色交互强调和低装饰成本。

### 视觉要素
- **配色**: 使用现有 `--xboard-primary` / `--xboard-link`，背景保持 `#ffffff` 与 `#fbfbfd`，不新增强调色。
- **字体**: 沿用项目 `--xboard-font-sans`，即 Apple 系统字体栈，符合 `apple/DESIGN.md`。
- **布局**: 回复区保持底部固定工具栏结构，拖拽提示作为输入框下方的细提示行，不改变主对话布局。
- **动效**: 拖拽进入/离开使用边框、背景和提示透明度的短过渡；上传中复用 Element Plus loading。
- **氛围**: 无纹理、无渐变装饰；以纯色表面、轻描边和 Apple Blue 状态表达交互。

### 技术约束
- **可访问性**: 拖拽提示不替代按钮上传；键盘和屏幕阅读器用户仍可使用上传按钮。
- **响应式**: 小屏下回复按钮组可换行，上传提示不挤压发送按钮。
