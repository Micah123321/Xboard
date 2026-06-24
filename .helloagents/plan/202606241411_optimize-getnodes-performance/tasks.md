# 任务清单: optimize-getnodes-performance

```yaml
@feature: optimize-getnodes-performance
@created: 2026-06-24
@status: completed
@mode: R2
```

## LIVE_STATUS
```json
{"status":"completed","completed":5,"failed":0,"pending":0,"total":5,"percent":100,"current":"全部任务完成，19 tests / 120 assertions 通过","updated_at":"2026-06-24 15:00:00"}
```

## 进度概览

| 完成 | 失败 | 跳过 | 总数 |
|------|------|------|------|
| 5 | 0 | 0 | 5 |

---

## 任务列表

### 1. 后端查询批量化

- [√] 1.1 优化 `app/Http/Controllers/V2/Admin/Server/ManageController.php`
  - 预期变更: `getNodes` 批量预取权限组和父节点，循环内只做内存查找；保持 `groups`、`parent`、`traffic_stats`、`traffic_limit_snapshot` 响应字段不变。
  - 完成标准: 节点列表字段结构保持兼容，权限组和父节点不再按节点数量触发额外 SQL。
  - 验证方式: 新增或更新 PHPUnit 测试断言字段完整性和关键查询数量上限。
  - depends_on: []

- [√] 1.2 优化 `app/Services/ServerTrafficLimitService.php`
  - 预期变更: `buildSnapshotsForServers()` 按 `machine_id` / host / server scope 批量计算当前账期用量、panel 用量和 runtime metrics，避免同 scope 重复查询。
  - 完成标准: 既有单节点 `buildTrafficLimitSnapshot()` 行为保持兼容；批量入口对重复 scope 只做一次账期聚合。
  - 验证方式: `vendor/bin/phpunit tests/Unit/ServerTrafficLimitServiceTest.php`。
  - depends_on: []

### 2. 测试覆盖

- [√] 2.1 更新 `tests/Unit/ServerTrafficLimitServiceTest.php`
  - 预期变更: 增加批量快照复用测试，覆盖同 host / same scope 下的统计结果和查询数量。
  - 完成标准: 新测试能证明同 scope 用量计算只执行一次关键聚合查询。
  - 验证方式: 运行目标 PHPUnit 测试文件。
  - depends_on: [1.2]

- [√] 2.2 增加或更新 `tests/Unit/Admin/*`
  - 预期变更: 覆盖 `ManageController::getNodes()` 对权限组、父节点、墙检测、流量统计和限额快照的字段装饰。
  - 完成标准: 测试能防止权限组 / 父节点 N+1 回归，并确认响应字段仍存在。
  - 验证方式: 运行目标 PHPUnit 测试文件。
  - depends_on: [1.1, 1.2]

### 3. 验证与知识库

- [√] 3.1 运行验证、同步知识库并准备 review/commit
  - 预期变更: 运行相关 PHPUnit 测试和语法检查；更新 `.helloagents` 相关模块文档和 CHANGELOG；执行本次改动 review；生成 git commit。
  - 完成标准: 测试结果、review 结论、commit hash 均可追溯；如验证受本地环境限制，明确记录阻断原因。
  - 验证方式: `php -l`、目标 PHPUnit、`git show --stat HEAD`。
  - depends_on: [2.1, 2.2]

---

## 执行日志

| 时间 | 事件 | 详情 |
|------|------|------|
| 2026-06-24 14:34:56 | 进度快照(自动) | 完成:0 失败:0 跳过:0 待做:5 (0%) |
| 2026-06-24 14:37:54 | 进度快照(自动) | 完成:0 失败:0 跳过:0 待做:5 (0%) |
| 2026-06-24 14:41:14 | 进度快照(自动) | 完成:0 失败:0 跳过:0 待做:5 (0%) |
| 2026-06-24 14:42:43 | 进度快照(自动) | 完成:0 失败:0 跳过:0 待做:5 (0%) |
| 2026-06-24 14:45:09 | 进度快照(自动) | 完成:5 失败:0 跳过:0 待做:0 (100%) |

## 执行备注

- 当前工作树已有用户未提交变更 `public/assets/admin`，本流程不回滚、不纳入本次 commit。
