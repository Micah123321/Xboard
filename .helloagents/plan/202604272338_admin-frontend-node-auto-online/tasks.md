# 任务清单: admin-frontend-node-auto-online

```yaml
@feature: admin-frontend-node-auto-online
@created: 2026-04-27
@status: in_progress
@mode: R2
@workflow: INTERACTIVE
@complexity: complex
```

## LIVE_STATUS

```json
{"status":"in_progress","completed":0,"failed":0,"pending":8,"total":8,"percent":0,"current":"方案包已创建，准备进入开发实施","updated_at":"2026-04-27 23:38:00"}
```

## 进度概览

| 完成 | 失败 | 跳过 | 总数 |
|------|------|------|------|
| 0 | 0 | 0 | 8 |

---

## 任务列表

### 1. 后端数据与同步机制

- [ ] 1.1 新增 `database/migrations/*_add_auto_online_to_v2_server_table.php`
  - 预期变更: 为 `v2_server` 增加 `auto_online` 布尔字段，默认 `false`，down 可回滚字段。
  - 完成标准: 迁移文件存在，字段名、默认值和回滚逻辑明确。
  - 验证方式: `php -l database/migrations/*_add_auto_online_to_v2_server_table.php`
  - depends_on: []

- [ ] 1.2 修改 `app/Models/Server.php`
  - 预期变更: 增加 `auto_online` 属性注释和 boolean cast，保持现有在线状态访问器与墙状态关系不变。
  - 完成标准: `Server` JSON/API 输出包含 `auto_online`，现有 `gfwChecks()` 不被移除。
  - 验证方式: `php -l app/Models/Server.php`
  - depends_on: [1.1]

- [ ] 1.3 新增 `app/Services/ServerAutoOnlineService.php`
  - 预期变更: 封装自动上线同步逻辑，只处理 `auto_online=true` 的节点，在线/待同步写 `show=true`，离线写 `show=false`。
  - 完成标准: 服务返回同步统计，跳过未托管节点，不引入生产外部副作用。
  - 验证方式: `php -l app/Services/ServerAutoOnlineService.php`
  - depends_on: [1.2]

- [ ] 1.4 新增命令并接入调度 `app/Console/Commands/SyncServerAutoOnline.php`, `app/Console/Kernel.php`
  - 预期变更: 新增 `sync:server-auto-online` 命令，每 5 分钟调度，使用 `onOneServer()` 与 `withoutOverlapping()`。
  - 完成标准: 命令可调用服务并输出统计，调度不影响现有任务。
  - 验证方式: `php -l app/Console/Commands/SyncServerAutoOnline.php`; `php -l app/Console/Kernel.php`
  - depends_on: [1.3]

### 2. 后端 API 契约

- [ ] 2.1 修改 `app/Http/Requests/Admin/ServerSave.php` 与 `app/Http/Controllers/V2/Admin/Server/ManageController.php`
  - 预期变更: `save`、`update`、`batchUpdate` 支持 `auto_online`，批量更新保持字段显式传入才更新。
  - 完成标准: 手动显隐字段 `show` 和自动上线字段 `auto_online` 可独立保存。
  - 验证方式: `php -l app/Http/Requests/Admin/ServerSave.php`; `php -l app/Http/Controllers/V2/Admin/Server/ManageController.php`
  - depends_on: [1.2]

### 3. 管理端前端

- [ ] 3.1 修改 `admin-frontend/src/types/api.d.ts`, `admin-frontend/src/utils/nodeEditorOptions.ts`, `admin-frontend/src/utils/nodeEditorMapper.ts`
  - 预期变更: 类型、表单模型、默认值、编辑回填和保存 payload 支持 `auto_online`。
  - 完成标准: 新建默认关闭，编辑能正确回填，保存能提交布尔值。
  - 验证方式: `npm run build`
  - depends_on: [2.1]

- [ ] 3.2 修改 `admin-frontend/src/views/nodes/NodeEditorDialog.vue`, `NodeBatchEditDialog.vue`, `NodesView.vue`, `admin-frontend/src/utils/nodes.ts`
  - 预期变更: 编辑弹窗和批量修改弹窗增加自动上线开关；节点表格展示自动托管状态；现有墙状态检测 UI 保持可用。
  - 完成标准: 管理员可单节点和批量设置 `auto_online`；未开启批量字段时不覆盖。
  - 验证方式: `npm run build`
  - depends_on: [3.1]

### 4. 知识库与验收

- [ ] 4.1 更新 `.helloagents/context.md`, `.helloagents/modules/*`, `.helloagents/CHANGELOG.md`
  - 预期变更: 同步记录节点自动上线能力、后端命令和管理端节点页行为。
  - 完成标准: 知识库反映代码事实，CHANGELOG 包含方案链接和决策 ID。
  - 验证方式: 人工检查文档条目与本次改动一致。
  - depends_on: [1.4, 2.1, 3.2]

---

## 执行日志

| 时间 | 任务 | 状态 | 备注 |
|------|------|------|------|
| 2026-04-27 23:38 | DESIGN | in_progress | 用户选择方案 A，方案包创建并进入开发实施 |

---

## 执行备注

- 当前工作树已有节点墙状态检测相关未提交改动，本任务必须保留并兼容这些改动。
- 按上级工具约束，本轮不调度子代理，复杂任务由主代理直接实施并在验收中说明。
