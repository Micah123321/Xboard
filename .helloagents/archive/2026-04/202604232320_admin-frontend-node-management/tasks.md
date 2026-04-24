# admin-frontend 节点管理首批交付 — 任务分解

## 任务列表
- [x] 任务1：补齐本轮 UI 契约与方案产物（涉及文件：`.helloagents/DESIGN.md`、`.helloagents/plans/202604232320_admin-frontend-node-management/*`；完成标准：存在可执行需求、方案、任务与合同文件；验证方式：文件检查）
- [x] 任务2：扩展后台导航与路由结构（涉及文件：`admin-frontend/src/router/index.ts`、`admin-frontend/src/layouts/AdminLayout.vue`；完成标准：侧边栏出现节点管理分组并可进入 3 个子页面；验证方式：`npm run build` + 浏览器检查导航）
- [x] 任务3：接入节点管理数据模型与 API（涉及文件：`admin-frontend/src/api/admin.ts`、`admin-frontend/src/types/api.d.ts`、`admin-frontend/src/utils/nodes.ts`；完成标准：前端可拉取节点与权限组并完成必要格式化；验证方式：`npm run build`）
- [x] 任务4：实现节点管理主页面（涉及文件：`admin-frontend/src/views/nodes/NodesView.vue`；完成标准：节点列表具备搜索、筛选、显隐切换、复制/删除基础能力，并覆盖加载/空/错误状态；验证方式：`npm run build` + 浏览器检查 `/nodes`）
- [x] 任务5：实现权限组 / 路由管理占位页（涉及文件：`admin-frontend/src/views/nodes/NodeGroupsView.vue`、`admin-frontend/src/views/nodes/NodeRoutesView.vue`；完成标准：可从侧边栏进入，页面明确说明下一阶段接入范围；验证方式：`npm run build` + 浏览器检查对应路由）
- [x] 任务6：完成验证、视觉验收与知识库同步（涉及文件：`.helloagents/CHANGELOG.md`、`.helloagents/.ralph-visual.json`、`.helloagents/.ralph-closeout.json`；完成标准：构建通过、视觉检查完成、知识库记录本轮变更；验证方式：命令输出 + 证据文件）

## 进度
- [x] 已创建方案包并冻结首批交付范围。
- [x] 已完成 admin-frontend 节点管理首批页面与知识库同步。
