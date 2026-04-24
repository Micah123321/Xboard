# admin-frontend 节点管理分页、置顶与批量修改 — 任务分解

## 任务列表
- [x] 任务1：补齐本轮方案与合同产物（涉及文件：`.helloagents/plans/202604242245_admin-frontend-node-pagination-batch-edit/*`；完成标准：存在需求、方案、任务、合同与状态文件；验证方式：文件检查）
- [x] 任务2：扩展节点批量修改 API 与后端兼容（涉及文件：`admin-frontend/src/api/admin.ts`、`admin-frontend/src/types/api.d.ts`、`app/Http/Controllers/V2/Admin/Server/ManageController.php`；完成标准：前后端支持按已勾选节点批量更新 `host / group_ids / rate`；验证方式：`npm run build` + 代码检查）
- [x] 任务3：重构节点列表工作台并接入分页 / 父子筛选 / 置顶（涉及文件：`admin-frontend/src/views/nodes/NodesView.vue`、`admin-frontend/src/utils/nodes.ts`；完成标准：节点页支持分页、父/子节点筛选与单节点置顶；验证方式：`npm run build`）
- [x] 任务4：新增节点批量修改弹窗并接入勾选流（涉及文件：`admin-frontend/src/views/nodes/NodeBatchEditDialog.vue`、`admin-frontend/src/views/nodes/NodeBatchEditDialog.scss`、`admin-frontend/src/views/nodes/NodesView.vue`；完成标准：只对已勾选节点执行批量修改，支持地址 host、权限组、倍率三项；验证方式：`npm run build`）
- [x] 任务5：完成验证与知识库同步（涉及文件：`.helloagents/CHANGELOG.md`、`.helloagents/context.md`、`.helloagents/modules/admin-frontend.md`、`.helloagents/.ralph-visual.json`、`.helloagents/.ralph-closeout.json`、`.helloagents/sessions/master/default/STATE.md`；完成标准：构建通过、知识库更新、证据落盘；验证方式：命令输出 + 证据文件）

## 进度
- [x] 已确认批量修改范围固定为“仅已勾选节点”。
- [x] 已完成节点分页、父/子节点筛选、置顶动作与批量修改弹窗。
- [x] 已完成构建验证、知识库同步与交付收尾。
