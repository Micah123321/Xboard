# 任务清单: fix-auto-online-child-offline

> **@status:** completed | 2026-06-14 16:48

```yaml
@feature: fix-auto-online-child-offline
@created: 2026-06-14
@status: completed
@mode: R2
```

## LIVE_STATUS

```json
{"status":"completed","completed":4,"failed":0,"pending":0,"total":4,"percent":100,"current":"已归档到 archive/2026-06","updated_at":"2026-06-14 16:48:27","skipped":0,"uncertain":0,"done":4}
```

## 进度概览

| 完成 | 失败 | 跳过 | 总数 |
|------|------|------|------|
| 4 | 0 | 0 | 4 |

---

## 任务列表

### 1. 自动上线判定修复

- [√] 1.1 在 `app/Models/Server.php` 中新增当前节点自身运行状态读取能力
  - 预期变更: 提供不走父缓存回退的自身 `LAST_CHECK_AT` / `LAST_PUSH_AT` 状态计算入口。
  - 完成标准: 现有展示态访问器继续回退父缓存；新入口只读取当前节点自身缓存。
  - 验证方式: `tests/Unit/ServerAutoOnlineServiceTest.php` 中新增/调整断言。
  - depends_on: []
- [√] 1.2 在 `app/Services/ServerAutoOnlineService.php` 中改用自身运行状态判定 `show`
  - 预期变更: `$shouldShow` 不再因父节点运行缓存新鲜而保持子节点显示。
  - 完成标准: 子节点自身心跳缺失/过期时自动上线会隐藏该子节点；墙检测和冷却逻辑保持不变。
  - 验证方式: `php artisan test --filter=ServerAutoOnlineServiceTest`
  - depends_on: [1.1]

### 2. 回归测试与文档同步

- [√] 2.1 在 `tests/Unit/ServerAutoOnlineServiceTest.php` 中补充离线子节点回归测试
  - 预期变更: 覆盖“父节点在线、子节点自身离线、自动上线应隐藏子节点”的失败场景，同时保留展示态父缓存回退测试。
  - 完成标准: 测试能在修复前暴露回归，在修复后通过。
  - 验证方式: `php artisan test --filter=ServerAutoOnlineServiceTest`
  - depends_on: [1.2]
- [√] 2.2 同步 `.helloagents/modules/node-auto-online.md` 和 `.helloagents/CHANGELOG.md`
  - 预期变更: 知识库明确“展示态可回退父缓存，自动上线显隐判定只看当前节点自身心跳”的边界，并记录本次修复。
  - 完成标准: 文档与代码行为一致，CHANGELOG 有本次方案链接。
  - 验证方式: 人工检查文档条目与代码事实一致。
  - depends_on: [2.1]

---

## 执行日志

| 时间 | 任务 | 状态 | 备注 |
|------|------|------|------|
| 2026-06-14 16:46 | 2.2 知识库同步 | completed | 已同步 node-auto-online 模块、上下文、模块索引与 CHANGELOG |
| 2026-06-14 16:45 | 验证 | completed | 语法检查、PHPStan 和目标 PHPUnit 均通过 |
| 2026-06-14 16:43 | 2.1 回归测试 | completed | 覆盖父节点在线但子节点自身离线时自动上线应隐藏子节点 |
| 2026-06-14 16:40 | 1.1-1.2 开发实施 | completed | 展示态保留父缓存回退，自动上线显隐改用自身心跳 |
| 2026-06-14 16:35 | 方案设计 | completed | 已确认唯一方案：展示态保留父缓存回退，自动上线使用自身心跳判定 |

---

## 执行备注

- 当前判断：`a3f27de` 的父缓存回退对管理端展示是有价值的，但不能直接作为自动上线写入 `show` 的依据。本次修复不触碰生产数据，不新增迁移。
- 验证结果：`php -l` 覆盖 `Server.php`、`ServerAutoOnlineService.php`、`ServerAutoOnlineServiceTest.php`，全部通过。
- 验证结果：`vendor\bin\phpstan.bat analyse app\Models\Server.php app\Services\ServerAutoOnlineService.php tests\Unit\ServerAutoOnlineServiceTest.php --memory-limit=1G --no-progress` 通过。
- 验证结果：`APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=database/testing.sqlite vendor\bin\phpunit.bat tests\Unit\ServerAutoOnlineServiceTest.php` 通过，12 个测试、54 个断言。
- 验证说明：当前项目的 `php artisan test --filter=...` 入口不可用，且 sqlite `:memory:` 会被配置解析为项目路径；目标测试改用仓库忽略的 `database/testing.sqlite` 执行。
