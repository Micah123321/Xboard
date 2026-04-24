# admin-frontend 用户管理高级筛选与批量操作 — 任务分解

## 任务列表
- [x] 任务1：补齐本轮方案与合同产物（涉及文件：`.helloagents/plans/202604242200_admin-frontend-user-advanced-filter-batch-ops/*`；完成标准：存在需求、方案、任务、合同与状态文件；验证方式：文件检查）
- [x] 任务2：扩展用户筛选工具层与批量操作 API（涉及文件：`admin-frontend/src/utils/users.ts`、`admin-frontend/src/types/api.d.ts`、`admin-frontend/src/api/admin.ts`；完成标准：前端可构造高级筛选与批量操作 payload；验证方式：`npm run build`）
- [x] 任务3：重构用户管理页并新增高级筛选 / 批量邮件组件（涉及文件：`admin-frontend/src/views/users/*`；完成标准：用户页支持高级筛选、勾选、多种批量操作；验证方式：`npm run build`）
- [x] 任务4：补齐批量恢复正常后端兼容（涉及文件：`app/Http/Controllers/V2/Admin/UserController.php`；完成标准：`/user/ban` 支持 `banned=0|1`；验证方式：代码检查 + `npm run build` 间接通过）
- [x] 任务5：完成验证与知识库同步（涉及文件：`.helloagents/CHANGELOG.md`、`.helloagents/context.md`、`.helloagents/modules/admin-frontend.md`、`.helloagents/.ralph-visual.json`、`.helloagents/.ralph-closeout.json`、`.helloagents/sessions/master/default/STATE.md`；完成标准：构建通过、知识库更新、证据落盘；验证方式：命令输出 + 证据文件）

## 进度
- [x] 已确认本轮除截图中的三项批量操作外，再额外补“批量恢复正常”。
- [x] 已完成高级筛选弹窗、批量操作菜单、批量邮件弹窗与前后端批量恢复链路。
- [x] 已完成 `npm run build` 构建验证，待输出最终交付摘要。
