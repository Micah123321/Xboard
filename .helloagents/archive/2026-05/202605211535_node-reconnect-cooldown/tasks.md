# 任务清单: node-reconnect-cooldown

```yaml
@feature: node-reconnect-cooldown
@created: 2026-05-21
@status: completed
@mode: R2
```

## LIVE_STATUS

```json
{"status":"completed","completed":5,"failed":0,"pending":0,"total":5,"percent":100,"current":"重连冷却功能已实现并通过验证","updated_at":"2026-05-21 15:55:00"}
```

## 进度概览

| 完成 | 失败 | 跳过 | 总数 |
|------|------|------|------|
| 5 | 0 | 0 | 5 |

---

## 任务列表

### 1. 后端数据与服务

- [√] 1.1 在 `database/migrations/*_add_auto_online_cooldown_to_v2_server_table.php` 增加节点级开关字段
  - 预期变更: 新增 `auto_online_cooldown_enabled` 布尔字段，默认关闭，并提供 down 回滚。
  - 完成标准: 迁移文件符合 Laravel schema 写法，重复执行前检查字段是否存在。
  - 验证方式: `php -l database/migrations/*_add_auto_online_cooldown_to_v2_server_table.php`
  - depends_on: []

- [√] 1.2 在 `app/Models/Server.php`、`app/Http/Requests/Admin/ServerSave.php`、`app/Http/Controllers/V2/Admin/Server/ManageController.php` 接入字段
  - 预期变更: 模型 docblock/casts、保存校验、单节点更新接口均支持 `auto_online_cooldown_enabled`。
  - 完成标准: 管理端保存、新建和快捷更新不会丢失该字段。
  - 验证方式: `php -l` 检查相关 PHP 文件。
  - depends_on: [1.1]

- [√] 1.3 新增 `app/Services/ServerReconnectCooldownService.php` 并接入 `ServerAutoOnlineService`
  - 预期变更: 记录在线/离线状态切换，超过阈值后写入 6 小时冷却；自动上线同步时冷却状态否决展示。
  - 完成标准: 仅 `auto_online=true` 且 `auto_online_cooldown_enabled=true` 的节点参与冷却；首次状态不计数；冷却期内 `show=false`。
  - 验证方式: `php -l` 检查服务文件，并用最小脚本或代码审查验证状态切换逻辑。
  - depends_on: [1.2]

### 2. 管理端接入

- [√] 2.1 更新 `admin-frontend/src/types/api.d.ts`、`admin-frontend/src/utils/nodeEditorOptions.ts`、`admin-frontend/src/utils/nodeEditorMapper.ts`
  - 预期变更: API 类型、表单默认值、节点填充和 payload 输出均包含 `auto_online_cooldown_enabled`。
  - 完成标准: TypeScript 构建能够识别新字段，保存节点时提交该字段。
  - 验证方式: `npm --prefix admin-frontend run build`
  - depends_on: [1.2]

- [√] 2.2 更新 `admin-frontend/src/views/nodes/NodeEditorDialog.vue` 显示重连冷却开关
  - 预期变更: 在自动上线同组区域新增“重连冷却”开关，并在自动上线关闭时禁用该开关。
  - 完成标准: UI 文案简洁，延续现有 `switch-card` 结构，不引入新视觉体系。
  - 验证方式: `npm --prefix admin-frontend run build`
  - depends_on: [2.1]

---

## 执行日志

| 时间 | 任务 | 状态 | 备注 |
|------|------|------|------|
| 2026-05-21 15:35 | DESIGN | 已完成 | 方案包已创建并填充 |
| 2026-05-21 15:55 | DEVELOP | 已完成 | 后端、管理端和知识库同步完成；PHP 语法检查与前端构建通过 |

---

## 执行备注

- 用户确认范围: 节点级开关，交互式执行。
- 冷却规则: 1 小时内在线/离线切换超过 10 次，下线 6 小时。
- 冷却状态采用 Redis/Cache 短期状态，不做持久审计。
