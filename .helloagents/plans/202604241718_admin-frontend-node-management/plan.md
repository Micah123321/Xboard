# admin-frontend 节点管理真实工作台补全 — 实施规划

## 目标与范围
- 在现有节点列表工作台内补齐“添加节点 / 编辑节点 / 编辑排序”三条真实运营链路。
- 让节点配置从“只读列表 + 局部开关”升级为“列表 + 动态协议编辑器 + 排序对话框”的完整后台工作台。

## 架构与实现策略
- 保留现有 `NodesView` 列表骨架、统计卡片、筛选条、显隐切换、复制和删除逻辑，在此基础上接入真实新增 / 编辑 / 排序流程。
- 新增 `NodeEditorDialog.vue`：
  - 负责节点新增与编辑
  - 中央大弹窗布局，顶部标题与说明左对齐，协议选择器右置
  - 采用“通用信息 → 动态倍率 → 协议安全层 → 传输层 → 协议专属设置”分组结构
- 新增 `NodeSortDialog.vue`：
  - 负责可见顺序调整
  - 使用本地排序草稿 + 上移 / 下移交互
  - 保存时转换为 `[{ id, order }]` 并提交到 `/server/manage/sort`
- 新增 `src/utils/nodeEditor.ts`：
  - 收敛协议选项、TLS / 传输 / 协议字段默认值
  - 负责节点实体与表单模型的双向转换
  - 负责把动态表单序列化为 `ServerSave` 所需 payload
- 在 `src/types/api.d.ts` 与 `src/api/admin.ts` 中补齐节点保存 / 排序的类型和接口封装。

## 完成定义
- `#/nodes` 的“添加节点”按钮能打开真实节点编辑弹窗并提交保存。
- 列表行菜单中的“编辑节点”能回填当前节点数据，并允许修改后提交。
- 不同协议切换后，表单配置区会随协议变化，不再是统一占位结构。
- “编辑排序”能打开真实排序对话框，调整顺序并保存到后台。
- 现有节点列表的显隐 / 复制 / 删除能力保持可用，不因本轮重构回归。

## 文件结构
- `.helloagents/plans/202604241718_admin-frontend-node-management/*`
- `admin-frontend/src/api/admin.ts`
- `admin-frontend/src/types/api.d.ts`
- `admin-frontend/src/utils/nodes.ts`
- `admin-frontend/src/utils/nodeEditor.ts`
- `admin-frontend/src/views/nodes/NodesView.vue`
- `admin-frontend/src/views/nodes/NodeEditorDialog.vue`
- `admin-frontend/src/views/nodes/NodeEditorDialog.scss`
- `admin-frontend/src/views/nodes/NodeSortDialog.vue`
- `admin-frontend/src/views/nodes/NodeSortDialog.scss`

## UI / 设计约束
- 列表页继续保持“黑色 Hero + 白色工作台”的 Apple 化后台节奏，不额外引入营销化视觉。
- 节点编辑弹窗整体贴近用户截图：标题与说明在顶部，协议切换器独立，表单区以白底轻边框输入为主，操作栏固定在底部。
- 协议差异应体现在配置结构与字段显隐上，不使用“JSON 文本编辑器”替代真实表单。
- 动态倍率、TLS、Reality、ECH、多路复用等高级项默认折叠在分组内，保证高密度但仍可读。
- 节点排序流程沿用现有套餐排序的 Apple 化列表草稿模式，避免引入沉重拖拽依赖。

## 风险与验证
- 风险 1：协议差异字段较多，如果全部堆在 `NodesView.vue` 会导致页面过大，因此节点表单与序列化逻辑必须拆到专属组件 / util。
- 风险 2：后端 `protocol_settings` 存在嵌套对象和协议差异，若序列化不统一容易导致编辑后字段丢失，因此统一通过 `src/utils/nodeEditor.ts` 生成 payload。
- 风险 3：本地环境缺少真实后台登录态时，只能做结构与构建验证，不能替代完整联调。
- 验证方式：
  - `npm run build`
  - 代码级结构自检 `#/nodes`
  - 结构化视觉验收记录（无浏览器工具时以 code inspection 说明边界）

## 决策记录
- [2026-04-24] 节点新增与编辑共用同一中央大弹窗，而不是拆成独立路由页。
- [2026-04-24] 排序沿用“本地草稿 + 上移 / 下移”的后台排序模式，不引入额外拖拽库。
- [2026-04-24] 协议配置采用“通用字段 + 动态协议块”结构，以同时满足截图风格和 11 种协议的差异表达。
