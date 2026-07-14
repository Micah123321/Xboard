# 任务清单: fix-offline-node-distribution

> **@status:** completed | 2026-07-15 01:17

```yaml
@feature: fix-offline-node-distribution
@created: 2026-07-15
@status: completed
@mode: R2-DELEGATED
@complexity: moderate
```

## LIVE_STATUS

```json
{"status":"completed","completed":3,"failed":0,"pending":0,"total":3,"percent":100,"current":"已归档到 archive/2026-07","updated_at":"2026-07-15 01:17:20","skipped":0,"uncertain":0,"done":3}
```

## 进度概览

| 完成 | 失败 | 跳过 | 总数 |
|------|------|------|------|
| 3 | 0 | 0 | 3 |

---

## 任务列表

### 1. 回归测试

- [√] 1.1 新增 `tests/Feature/User/AvailableServerDistributionTest.php`
  - 预期变更: 覆盖离线排除、两种在线状态保留、状态恢复重入及 ETag 失效
  - 完成标准: 测试在服务修复前能稳定暴露离线节点仍被分发的缺陷；断言以节点 ID 和响应状态为主
  - 验证方式: 在 `APP_ENV=testing`、`DB_CONNECTION=sqlite`、`CACHE_DRIVER=array` 且未设置 `DB_DATABASE` / `DATABASE_URL` 的环境中运行 `php vendor/bin/phpunit tests/Feature/User/AvailableServerDistributionTest.php`
  - depends_on: []

### 2. 节点分发准入

- [√] 2.1 修改 `app/Services/ServerService.php::getAvailableServers()`
  - 预期变更: 在运行态追加后统一排除 `Server::STATUS_OFFLINE`，保留状态 1/2，并重建集合索引
  - 完成标准: 用户节点 API、订阅导出和 App 自动选择的共享输入均不包含离线节点，恢复心跳后节点重新进入
  - 验证方式: 运行新增的状态矩阵与 HTTP 集成测试
  - depends_on: [1.1]

### 3. 验收与知识库同步

- [√] 3.1 更新 `.helloagents/modules/subscription-protocols.md`、归档方案包并执行相关验证
  - 预期变更: 文档记录分发仅接收有效运行态；运行新增测试及节点自动上线/墙检测回归；完成自动审查
  - 完成标准: 静态语法、目标测试和相关回归通过，知识库与代码一致，审查无阻断性问题
  - 验证方式: `php -l app/Services/ServerService.php`；使用隔离 SQLite 测试库分别运行 `php vendor/bin/phpunit tests/Feature/User/AvailableServerDistributionTest.php`、`tests/Unit/ServerAutoOnlineServiceTest.php`、`tests/Unit/ServerGfwCheckServiceTest.php`
  - depends_on: [2.1]

---

## 执行日志

| 时间 | 任务 | 状态 | 备注 |
|------|------|------|------|
| 2026-07-15 00:43 | DESIGN | [√] | 并行完成分发链路与测试基础设施分析，确定共享服务过滤方案 |
| 2026-07-15 00:46 | DEVELOP | [√] | 按 test-first 调整任务依赖，开始编写回归测试 |
| 2026-07-15 00:54 | 1.1 | [√] | 2 个测试、9 个断言稳定复现离线节点仍被分发的两处红灯 |
| 2026-07-15 00:55 | 2.1 | [√] | 共享服务过滤已实现；目标测试 2 个、14 个断言全部通过 |
| 2026-07-15 01:08 | 3.1 | [√] | 目标与真实订阅测试 3/19、自动上线 13/60、墙检测 4/37 全通过；自动审查无阻断问题；知识库已同步 |

---

## 执行备注

- 子代理扫描预算：分发链路 `scope_units=5, dependency_depth=0, task_weight=2, budget=540s`；测试链路 `scope_units=4, dependency_depth=0, task_weight=2, budget=480s`。两项均在预算内完成只读交接。
- 工作区原有 `.helloagents/user/.kb_sync_needed` 修改和 `public/assets/admin` 子模块状态不属于本任务，提交时必须排除。
- 用户要求实现完成后自动执行 `~review` 与 `~commit`。
- PHPStan 对本次新增过滤行无报错；完整文件仍有第 92、426 行两项既有告警，未在本任务中扩展修复。
- 自动审查接受标准入口过滤方案；`client.subscribe.servers` 与显式自定义节点集合记录为受信扩展边界。
