# 任务清单: parent-node-auto-visibility

> **@status:** completed | 2026-04-29 02:07

```yaml
@feature: parent-node-auto-visibility
@created: 2026-04-29
@status: completed
@mode: R2
@type: implementation
```

## LIVE_STATUS

```json
{"status":"completed","completed":6,"failed":0,"pending":0,"total":6,"percent":100,"current":"父节点自动下线联动子节点隐藏与恢复已完成并通过验证","updated_at":"2026-04-29 02:14:00"}
```

## 进度概览

| 完成 | 失败 | 跳过 | 总数 |
|------|------|------|------|
| 6 | 0 | 0 | 6 |

---

## 任务列表

### 1. 数据模型与联动服务

- [√] 1.1 新增 `v2_server` 父级自动隐藏标记字段
  - 文件路径或作用范围: `database/migrations/*_add_parent_auto_visibility_fields_to_v2_server_table.php`, `app/Models/Server.php`
  - 预期变更: 增加 `parent_auto_hidden` 与 `parent_auto_action_at` 字段，补充模型 docblock 和 casts。
  - 完成标准: 新字段迁移幂等，模型可布尔/整数转换字段。
  - 验证方式: `php -l` 检查新增迁移和模型语法。
  - depends_on: []

- [√] 1.2 新增父节点子节点展示联动服务
  - 文件路径或作用范围: `app/Services/ServerParentVisibilityService.php`
  - 预期变更: 实现隐藏当前展示子节点、恢复被标记子节点、清除手动标记的集中方法。
  - 完成标准: 只标记本次自动隐藏的子节点；恢复时不恢复未标记或仍被墙检测隐藏的节点。
  - 验证方式: 单元测试覆盖自动隐藏和恢复行为。
  - depends_on: [1.1]

### 2. 自动状态入口接入

- [√] 2.1 接入自动上线同步
  - 文件路径或作用范围: `app/Services/ServerAutoOnlineService.php`
  - 预期变更: 父节点自动同步后根据最终展示状态隐藏或恢复直接子节点；结果统计包含子节点联动更新。
  - 完成标准: 父节点离线自动隐藏时同步隐藏展示中的子节点；父节点恢复在线时只恢复 `parent_auto_hidden=1` 的子节点。
  - 验证方式: `tests/Unit/ServerAutoOnlineServiceTest.php` 新增断言。
  - depends_on: [1.2]

- [√] 2.2 接入流量限额超额和恢复
  - 文件路径或作用范围: `app/Services/ServerTrafficLimitService.php`
  - 预期变更: `refreshSchedule()`、`resetServer()`、`applyRuntimeMetrics()` 状态落库后触发父节点子节点隐藏/恢复。
  - 完成标准: suspended 隐藏子节点，normal/reset 恢复被标记子节点，未启用限额不触发误恢复。
  - 验证方式: `tests/Unit/ServerTrafficLimitServiceTest.php` 新增断言。
  - depends_on: [1.2]

- [√] 2.3 清理手动 show 修改的自动联动标记
  - 文件路径或作用范围: `app/Http/Controllers/V2/Admin/Server/ManageController.php`
  - 预期变更: 单节点保存、快速更新、批量更新接收 `show` 时清除 `parent_auto_hidden` 和 `parent_auto_action_at`。
  - 完成标准: 手动显示/隐藏子节点后，后续父节点恢复不会覆盖人工决定。
  - 验证方式: 代码检查和相关服务测试覆盖标记清除方法。
  - depends_on: [1.1, 1.2]

### 3. 验证与知识库

- [√] 3.1 补充测试、知识库和变更记录
  - 文件路径或作用范围: `tests/Unit/ServerAutoOnlineServiceTest.php`, `tests/Unit/ServerTrafficLimitServiceTest.php`, `.helloagents/modules/node-traffic-limit.md`, `.helloagents/context.md`, `.helloagents/CHANGELOG.md`
  - 预期变更: 增加自动上线与流量限额联动测试，更新知识库说明和变更记录。
  - 完成标准: 目标测试通过或明确记录环境阻塞；知识库反映代码事实。
  - 验证方式: `vendor/bin/phpunit tests/Unit/ServerAutoOnlineServiceTest.php tests/Unit/ServerTrafficLimitServiceTest.php`
  - depends_on: [2.1, 2.2, 2.3]

---

## 执行日志

| 时间 | 任务 | 状态 | 备注 |
|------|------|------|------|
| 2026-04-29 01:53 | 方案设计 | in_progress | 已完成上下文收集与任务拆分 |
| 2026-04-29 02:03 | 1.1-2.3 | completed | 已完成迁移、模型、联动服务和入口接入 |
| 2026-04-29 02:08 | 3.1 | completed | 已补充自动上线和流量限额测试 |
| 2026-04-29 02:14 | 验证 | completed | PHP 语法、PHPStan 和目标 PHPUnit 测试通过 |

---

## 执行备注

- 当前已有遗留方案包 `202604250006_ticket-closed-reply-reopen` 标记 `in_progress`，本任务作为新方案包独立执行。
- 不执行生产数据库迁移，仅提交迁移文件和测试。
- 验证命令:
  - `php -l` 检查新增/修改 PHP 文件。
  - `vendor\bin\phpstan analyse app\Services\ServerParentVisibilityService.php app\Services\ServerAutoOnlineService.php app\Services\ServerTrafficLimitService.php app\Http\Controllers\V2\Admin\Server\ManageController.php app\Models\Server.php --memory-limit=1G`
  - 使用一次性 SQLite 文件执行 `vendor\bin\phpunit tests\Unit\ServerAutoOnlineServiceTest.php tests\Unit\ServerTrafficLimitServiceTest.php`，结果 17 tests / 83 assertions 通过。
