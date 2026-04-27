# 任务清单: node-gfw-auto-check-and-online

> **@status:** completed | 2026-04-28 00:41

```yaml
@feature: node-gfw-auto-check-and-online
@created: 2026-04-28
@status: completed
@mode: R2
```

## LIVE_STATUS
```json
{"status":"completed","completed":8,"failed":0,"pending":0,"total":8,"percent":100,"current":"开发实施完成，准备归档方案包","updated_at":"2026-04-28 00:48:00"}
```

## 进度概览

| 完成 | 失败 | 跳过 | 总数 |
|------|------|------|------|
| 8 | 0 | 0 | 8 |

---

## 任务列表

### 1. 后端数据与调度

- [√] 1.1 新增 `database/migrations/*_add_gfw_auto_fields_to_v2_server_table.php`
  - 预期变更: 为 `v2_server` 增加 `gfw_check_enabled`、`gfw_auto_hidden`、`gfw_auto_action_at` 字段。
  - 完成标准: 迁移包含字段存在性保护和 down 回滚逻辑；默认节点自动墙检测开启。
  - 验证方式: 代码审查迁移字段和回滚逻辑。
  - depends_on: []

- [√] 1.2 修改 `app/Models/Server.php`、`app/Http/Requests/Admin/ServerSave.php`、`app/Http/Controllers/V2/Admin/Server/ManageController.php`
  - 预期变更: 模型 casts、保存、单节点更新和批量更新支持新增字段。
  - 完成标准: `getNodes/save/update/batchUpdate` 可读写新增字段，现有字段不回退。
  - 验证方式: 代码审查请求校验和 payload 字段。
  - depends_on: [1.1]

- [√] 1.3 修改 `app/Services/ServerGfwCheckService.php`
  - 预期变更: 新增自动检测批量创建、跳过 active 任务、blocked/normal 自动显隐、blocked 状态查询辅助。
  - 完成标准: 自动检测只作用父节点；子节点继承检测结果但不创建任务；partial/failed 不改变 show。
  - 验证方式: 单元测试覆盖自动检测、自动隐藏、自动恢复和子节点联动。
  - depends_on: [1.2]

- [√] 1.4 新增 `app/Console/Commands/SyncServerGfwChecks.php` 并修改 `app/Console/Kernel.php`
  - 预期变更: 新增 `sync:server-gfw-checks` 命令并加入 Laravel Scheduler。
  - 完成标准: 命令输出 started/skipped/active 等统计；调度不与现有命令冲突。
  - 验证方式: 代码审查命令签名、调度频率和 withoutOverlapping。
  - depends_on: [1.3]

- [√] 1.5 修改 `app/Services/ServerAutoOnlineService.php`
  - 预期变更: 自动上线同步时把最新 inherited/source 墙状态 `blocked` 作为显示否决条件。
  - 完成标准: `auto_online=1` 且在线的 blocked 节点仍保持隐藏；非 blocked 继续按在线状态同步。
  - 验证方式: 单元测试覆盖 blocked 不被自动上线重新显示。
  - depends_on: [1.3]

### 2. 管理端前端

- [√] 2.1 修改 `admin-frontend/src/types/api.d.ts`、`admin-frontend/src/api/admin.ts`、`admin-frontend/src/utils/nodes.ts`
  - 预期变更: 类型、更新 payload、统计和搜索文本支持 `gfw_check_enabled/gfw_auto_hidden/gfw_auto_action_at`。
  - 完成标准: TypeScript 可识别新增字段；搜索可命中自动墙检/自动隐藏相关关键词。
  - 验证方式: `npm run build`。
  - depends_on: [1.2]

- [√] 2.2 修改 `admin-frontend/src/views/nodes/NodesView.vue`、`NodeBatchEditDialog.vue`、节点编辑映射工具
  - 预期变更: 新增刷新数据按钮、墙检测托管开关、批量设置入口和编辑保存字段映射。
  - 完成标准: 父节点可切换自动墙检测；子节点开关提示只控制随父节点自动隐藏/恢复；刷新按钮显示加载反馈。
  - 验证方式: `npm run build` + 代码级 UI 审查。
  - depends_on: [2.1]

### 3. 测试与知识库

- [√] 3.1 修改/新增 `tests/Unit/ServerAutoOnlineServiceTest.php`、`tests/Unit/ServerGfwCheckServiceTest.php`
  - 预期变更: 覆盖自动墙检测任务创建、blocked 自动隐藏、normal 自动恢复、自动上线 blocked 否决。
  - 完成标准: 测试断言自动动作不会误恢复手动隐藏节点。
  - 验证方式: 本机可用时运行 PHPUnit；不可用时报告 PHP CLI 缺失。
  - depends_on: [1.3, 1.5]

---

## 执行日志

| 时间 | 任务 | 状态 | 备注 |
|------|------|------|------|
| 2026-04-28 00:24 | 方案设计 | in_progress | 已选择方案 A，准备开发实施 |
| 2026-04-28 00:36 | 后端开发 | completed | 已新增自动检测字段、命令、服务联动和测试 |
| 2026-04-28 00:43 | 前端开发 | completed | 已新增刷新按钮、墙检测托管开关和批量设置入口 |
| 2026-04-28 00:48 | 验证 | completed | `npm run build` 通过；PHP/Composer 不在 PATH，PHPUnit 未执行 |

---

## 执行备注

- 用户确认“隐藏”即不发布到订阅配置；当前实现以 `show=0` 为发布边界。
- 子节点不创建自动检测任务，但可继承父节点检测结果；子节点开关只控制是否随父节点自动隐藏/恢复。
- `partial/failed` 不主动恢复由墙检测隐藏的节点；只有 `normal` 结果会恢复 `gfw_auto_hidden=1` 的节点。
