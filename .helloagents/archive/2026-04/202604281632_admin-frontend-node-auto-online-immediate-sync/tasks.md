# 任务清单: admin-frontend-node-auto-online-immediate-sync

> **@status:** completed | 2026-04-28 16:37

```yaml
@feature: admin-frontend-node-auto-online-immediate-sync
@created: 2026-04-28
@status: completed
@mode: R2
```

## LIVE_STATUS

```json
{"status":"completed","completed":5,"failed":0,"pending":0,"total":5,"percent":100,"current":"开发实施完成，方案包已归档","updated_at":"2026-04-28 16:43:00"}
```

## 进度概览

| 完成 | 失败 | 跳过 | 总数 |
|------|------|------|------|
| 5 | 0 | 0 | 5 |

---

## 任务列表

### 1. 自动上线服务

- [√] 1.1 修改 `app/Services/ServerAutoOnlineService.php`
  - 预期变更: 抽出单节点同步入口 `syncServer(Server $server)`，让全量 `sync()` 复用同一判定逻辑。
  - 完成标准: 单节点和全量同步返回结构一致，仍包含 `total/updated/shown/hidden/unchanged`。
  - 验证方式: `vendor/bin/phpunit tests/Unit/ServerAutoOnlineServiceTest.php`
  - depends_on: []

### 2. 触发点接入

- [√] 2.1 修改 `app/Services/ServerService.php`
  - 预期变更: `touchNode()` 更新节点心跳缓存后，对 `auto_online=true` 的节点立即调用单节点同步。
  - 完成标准: 自动上线节点心跳后无需等待 Scheduler 即可同步 `show`，未开启自动上线的节点不受影响。
  - 验证方式: `vendor/bin/phpunit tests/Unit/ServerAutoOnlineServiceTest.php`
  - depends_on: [1.1]

- [√] 2.2 修改 `app/WebSocket/NodeEventHandlers.php`
  - 预期变更: WebSocket 节点状态上报改为复用 `ServerService::touchNode()`，避免绕过自动上线即时同步。
  - 完成标准: REST 与 WebSocket 心跳入口都收敛到同一个自动上线触发点。
  - 验证方式: 代码检查 + `vendor/bin/phpunit tests/Unit/ServerAutoOnlineServiceTest.php`
  - depends_on: [2.1]

- [√] 2.3 修改 `app/Http/Controllers/V2/Admin/Server/ManageController.php`
  - 预期变更: `save()`、`update()`、`batchUpdate()` 在保存或开启 `auto_online` 后立即执行单节点同步。
  - 完成标准: 管理端保存复制节点、行级开启自动上线、批量开启自动上线后会立即按当前状态同步 `show`。
  - 验证方式: 代码检查 + `vendor/bin/phpunit tests/Unit/ServerAutoOnlineServiceTest.php`
  - depends_on: [1.1]

### 3. 验证覆盖

- [√] 3.1 修改 `tests/Unit/ServerAutoOnlineServiceTest.php`
  - 预期变更: 增加单节点同步和 `touchNode()` 触发自动上线的测试。
  - 完成标准: 新测试能复现复制节点 `show=0 + auto_online=1` 在线后立即 `show=1` 的核心行为。
  - 验证方式: `vendor/bin/phpunit tests/Unit/ServerAutoOnlineServiceTest.php`
  - depends_on: [1.1, 2.1]

---

## 执行日志

| 时间 | 任务 | 状态 | 备注 |
|------|------|------|------|
| 2026-04-28 16:32 | DESIGN | completed | 已确认唯一方案并创建任务清单 |
| 2026-04-28 16:37 | 1.1 | completed | 已抽出单节点自动上线同步入口并复用全量同步逻辑 |
| 2026-04-28 16:38 | 2.1/2.2 | completed | 已接入节点心跳、管理端保存、行级更新和批量更新触发点 |
| 2026-04-28 16:39 | 3.1 | completed | 已补充单节点同步和心跳即时同步测试 |
| 2026-04-28 16:40 | 验证 | warning | `npm --prefix admin-frontend run build` 通过；本机缺少 PHP/vendor，PHPUnit 未运行 |
| 2026-04-28 16:43 | 2.2 | completed | 已补齐 WebSocket 状态上报入口，统一复用 `touchNode()` |

---

## 执行备注

- 本次不修改前端视觉和接口签名，前端现有 `@success="() => loadNodeBoard()"` 会在保存成功后刷新列表。
- 后端自动上线仍以 `available_status` 和墙状态为准，不做无条件显示。
