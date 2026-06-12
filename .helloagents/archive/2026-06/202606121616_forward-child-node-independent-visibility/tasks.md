# 任务清单: forward-child-node-independent-visibility

> **@status:** completed | 2026-06-12 16:46

```yaml
@feature: forward-child-node-independent-visibility
@created: 2026-06-12
@status: completed
@mode: R2
```

## LIVE_STATUS

```json
{"status":"completed","completed":6,"failed":0,"pending":0,"total":6,"percent":100,"current":"已归档到 archive/2026-06","updated_at":"2026-06-12 16:46:59","skipped":0,"uncertain":0,"done":6}
```

## 进度概览

| 完成 | 失败 | 跳过 | 总数 |
|------|------|------|------|
| 6 | 0 | 0 | 6 |

---

## 任务列表

### 1. 取消父节点显隐联动

- [√] 1.1 修改 `app/Services/ServerAutoOnlineService.php`
  - 预期变更: 自动在线同步只更新当前节点自身，不再调用父子显隐联动隐藏或恢复子节点。
  - 完成标准: 父节点离线时子节点 `show` 和 `parent_auto_hidden` 不变化。
  - 验证方式: `vendor/bin/phpunit tests/Unit/ServerAutoOnlineServiceTest.php`
  - depends_on: []
- [√] 1.2 修改 `app/Services/ServerGfwCheckService.php`
  - 预期变更: 墙检测 blocked/normal 的自动显隐只作用于上报检测的源节点自身，不再批量改写直接子节点。
  - 完成标准: 父节点 blocked 时子节点 `show/gfw_auto_hidden` 不变化。
  - 验证方式: `vendor/bin/phpunit tests/Unit/ServerGfwCheckServiceTest.php`
  - depends_on: []
- [√] 1.3 修改 `app/Services/ServerTrafficLimitService.php`
  - 预期变更: 限额状态刷新、重置和 runtime metrics 不再调用父子显隐联动。
  - 完成标准: 父节点限额 suspended 时子节点 `show/parent_auto_hidden` 不变化。
  - 验证方式: `vendor/bin/phpunit tests/Unit/ServerTrafficLimitServiceTest.php`
  - depends_on: []
- [√] 1.4 修改 `app/Models/Server.php`
  - 预期变更: `last_check_at`、`last_push_at`、在线用户、metrics 和负载状态按节点自身 ID 读取缓存，不再让子节点读取父节点运行状态。
  - 完成标准: 父节点在线不会让离线子节点被自动上线，父节点 blocked 不会阻止在线子节点显示。
  - 验证方式: `vendor/bin/phpunit tests/Unit/ServerAutoOnlineServiceTest.php`
  - depends_on: [1.1]

### 2. 回归测试与知识库

- [√] 2.1 更新相关单元测试
  - 预期变更: 把既有“父节点隐藏/恢复子节点”的断言改为“父节点不影响子节点”，覆盖自动在线、运行状态缓存、墙检测、流量限额链路。
  - 完成标准: 三个测试文件均表达新转发语义，旧联动预期不再存在；自动上线补充父节点在线/blocked 不影响子节点判定的断言。
  - 验证方式: `vendor/bin/phpunit tests/Unit/ServerAutoOnlineServiceTest.php tests/Unit/ServerGfwCheckServiceTest.php tests/Unit/ServerTrafficLimitServiceTest.php`
  - depends_on: [1.1, 1.2, 1.3, 1.4]
- [√] 2.2 同步 `.helloagents` 知识库模块与上下文
  - 预期变更: 更新 `context.md`、`modules/node-auto-online.md`、`modules/node-gfw-check.md`、`modules/node-traffic-limit.md` 和 `CHANGELOG.md`，记录父节点不再影响子节点。
  - 完成标准: 文档与代码事实一致，不再描述父节点自动隐藏/恢复子节点作为当前行为。
  - 验证方式: 人工检查相关段落和 `rg "父节点.*子节点|parent_auto_hidden" .helloagents`
  - depends_on: [2.1]

---

## 执行日志

| 时间 | 任务 | 状态 | 备注 |
|------|------|------|------|
| 2026-06-12 16:16 | DESIGN | in_progress | 已完成上下文收集，创建方案包 |
| 2026-06-12 16:24 | 1.1-1.3 | completed | 自动在线、墙检测、流量限额不再通过父节点改写子节点显隐 |
| 2026-06-12 16:31 | 2.1-2.2 | completed | 已更新回归测试和知识库行为契约 |
| 2026-06-12 16:42 | 1.4 | completed | 运行状态缓存访问器改为按节点自身 ID 读取，阻断子节点继续继承父节点在线状态 |
| 2026-06-12 16:43 | 验证 | completed | `E:/php/php.exe vendor/bin/phpunit tests/Unit/ServerAutoOnlineServiceTest.php tests/Unit/ServerGfwCheckServiceTest.php tests/Unit/ServerTrafficLimitServiceTest.php` 通过，21 tests / 126 assertions |

---

## 执行备注

- 子代理工具当前仅允许用户显式要求时使用，已按更严格工具边界降级为主代理并行搜索。
- 代码证据显示 mi-node 机器模式按 `enabled=true` 发现节点，用户订阅可见性问题来自 Xboard 服务层自动改写子节点 `show`。
- Composer 依赖已使用 `E:/php/php.exe E:/php/composer.phar install --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix --no-interaction --no-progress --no-scripts` 安装到本地 `vendor`，未执行 Composer scripts。
