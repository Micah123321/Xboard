# admin-frontend 节点管理首批交付 — 实施规划

## 目标与范围
- 为 `admin-frontend` 增加节点管理信息架构，并先交付“节点管理”主页面。
- 页面目标不是一次性做完整节点中后台，而是先打通“可看、可筛、可切显隐、可做基础行级操作”的运营主链路。

## 架构与实现策略
- 在现有 `AdminLayout` 基础上新增“节点管理”二级分组，保持侧边栏结构统一。
- 新增 `/nodes`、`/node-groups`、`/node-routes` 三个路由，其中 `/nodes` 为真实功能页，其余两页为明确占位页。
- 节点列表页直接消费现有后端接口，不在前端猜测或重塑接口契约：
  - `/server/manage/getNodes`
  - `/server/group/fetch`
  - `/server/manage/update`
  - `/server/manage/copy`
  - `/server/manage/drop`
- 复杂的节点格式化逻辑（地址、状态、标签、筛选选项）下沉到 `utils/nodes.ts`，避免页面组件膨胀。

## 完成定义
- 侧边栏出现“节点管理”分组，且可以进入 3 个子入口。
- `/nodes` 页面可真实拉取节点与权限组数据。
- 用户可以通过搜索、节点类型和权限组筛选列表。
- 用户可以切换节点显隐状态，并在界面中获得成功/失败反馈。
- 用户可以通过更多菜单执行复制节点与删除节点；未覆盖的编辑/排序功能有明确边界提示。
- 验证主路径：`review-first`
- reviewer 关注边界：导航结构是否清晰、页面是否与 Apple 风格一致、未实现功能是否被透明标注。
- tester 关注边界：构建是否通过、节点列表是否真实连接 API、筛选与显隐切换是否存在数据流。

## 文件结构
- `admin-frontend/src/router/index.ts`
- `admin-frontend/src/layouts/AdminLayout.vue`
- `admin-frontend/src/api/admin.ts`
- `admin-frontend/src/types/api.d.ts`
- `admin-frontend/src/utils/nodes.ts`（新增）
- `admin-frontend/src/views/nodes/NodesView.vue`（新增）
- `admin-frontend/src/views/nodes/NodeGroupsView.vue`（新增）
- `admin-frontend/src/views/nodes/NodeRoutesView.vue`（新增）

## UI / 设计约束
- 节点管理首页保留黑色 hero + 白色表格壳层的 Apple 后台节奏。
- 过滤器采用紧凑 pill / select 混合布局，优先满足快速运营判断。
- 列表状态用圆点、标签和辅助文字三层表达，不只靠颜色。
- 占位页不做空白页，而是交付可继续扩展的结构化提示页。

## 风险与验证
- 风险 1：后端节点字段可能存在空值或差异，页面要做健壮格式化。
- 风险 2：节点显隐切换的字段是 `show`，前端需保持与布尔/整型兼容。
- 风险 3：权限组接口若返回结构偏轻，前端需要容错处理。
- 验证方式：
  - `npm run build`
  - 本地预览 + 浏览器检查 `/nodes`、`/node-groups`、`/node-routes`

## 决策记录
- [2026-04-23] 节点管理首批交付聚焦列表运营链路，不在本轮接入完整节点编辑表单，避免 UI 范围失控。
- [2026-04-23] 权限组管理 / 路由管理先交付占位页，保证侧边栏信息架构先稳定下来。
