# admin-frontend 节点管理真实工作台补全 — 任务分解

## 任务列表
- [x] 任务1：补齐节点管理方案与合同产物（涉及文件：`.helloagents/plans/202604241718_admin-frontend-node-management/*`；完成标准：存在需求、方案、任务与合同文件；验证方式：文件检查）
- [x] 任务2：补齐节点保存 / 排序接口、类型与表单工具层（涉及文件：`admin-frontend/src/api/admin.ts`、`admin-frontend/src/types/api.d.ts`、`admin-frontend/src/utils/nodes.ts`、`admin-frontend/src/utils/nodeEditor.ts`；完成标准：前端具备节点新增、编辑、排序的类型和序列化能力；验证方式：`npm run build`）
- [x] 任务3：实现节点新增 / 编辑弹窗（涉及文件：`admin-frontend/src/views/nodes/NodeEditorDialog.vue`、`admin-frontend/src/views/nodes/NodeEditorDialog.scss`；完成标准：支持 11 种协议的动态配置表单与保存；验证方式：`npm run build`）
- [x] 任务4：实现节点排序流程并接入列表页（涉及文件：`admin-frontend/src/views/nodes/NodeSortDialog.vue`、`admin-frontend/src/views/nodes/NodeSortDialog.scss`、`admin-frontend/src/views/nodes/NodesView.vue`；完成标准：列表页新增真实添加 / 编辑 / 排序入口，排序可保存；验证方式：`npm run build`）
- [x] 任务5：完成验证与知识库同步（涉及文件：`.helloagents/CHANGELOG.md`、`.helloagents/context.md`、`.helloagents/modules/admin-frontend.md`、`.helloagents/.ralph-visual.json`、`.helloagents/.ralph-closeout.json`；完成标准：构建通过、知识库更新、交付证据写入；验证方式：命令输出 + 证据文件）

## 进度
- [x] 已确认按“全量协议首版”推进节点管理新增 / 编辑 / 排序。
- [x] 已完成节点管理方案包、前端实现、验证与知识库同步。
