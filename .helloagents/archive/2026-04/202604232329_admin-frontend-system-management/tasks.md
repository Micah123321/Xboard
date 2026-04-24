# admin-frontend 系统管理首批交付 — 任务分解

## 任务列表
- [x] 任务1：补齐本轮 UI 契约与方案产物（涉及文件：`.helloagents/DESIGN.md`、`.helloagents/plans/202604232329_admin-frontend-system-management/*`；完成标准：存在可执行需求、方案、任务与合同文件；验证方式：文件检查）
- [x] 任务2：扩展后台导航与路由结构（涉及文件：`admin-frontend/src/router/index.ts`、`admin-frontend/src/layouts/AdminLayout.vue`；完成标准：侧边栏出现系统管理分组并可进入 6 个子页面；验证方式：`npm run build` + 浏览器检查导航）
- [x] 任务3：接入系统配置数据模型与 API（涉及文件：`admin-frontend/src/api/admin.ts`、`admin-frontend/src/types/api.d.ts`、`admin-frontend/src/utils/systemConfig.ts`；完成标准：前端可拉取并保存系统配置，字段可完成必要的回填与序列化；验证方式：`npm run build`）
- [x] 任务4：实现系统配置主页面（涉及文件：`admin-frontend/src/views/system/SystemConfigView.vue`；完成标准：页面具备 9 个配置分组、加载/错误/保存反馈、左侧导航与真实保存入口；验证方式：`npm run build` + 浏览器检查 `#/system/config`）
- [x] 任务5：实现其余系统管理占位页（涉及文件：`admin-frontend/src/views/system/SystemPlaceholderView.vue`；完成标准：其余 5 个入口可正常访问，并明确说明下一阶段接入范围；验证方式：`npm run build` + 浏览器检查对应路由）
- [x] 任务6：完成验证、视觉验收与知识库同步（涉及文件：`.helloagents/CHANGELOG.md`、`.helloagents/modules/admin-frontend.md`、`.helloagents/.ralph-visual.json`、`.helloagents/.ralph-closeout.json`；完成标准：构建通过、视觉检查完成、知识库记录本轮变更；验证方式：命令输出 + 证据文件）

## 进度
- [x] 已冻结系统管理首批范围与系统配置优先级。
- [x] 已完成 admin-frontend 系统管理导航、路由与系统配置页面。
