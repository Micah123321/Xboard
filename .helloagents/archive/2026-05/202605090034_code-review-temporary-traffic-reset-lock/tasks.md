# 任务清单: code-review-temporary-traffic-reset-lock

> **@status:** completed | 2026-05-09 00:48

```yaml
@feature: code-review-temporary-traffic-reset-lock
@created: 2026-05-09
@status: completed
@mode: R2
```

## LIVE_STATUS
```json
{"status":"completed","completed":3,"failed":0,"pending":0,"total":3,"percent":100,"current":"已归档到 archive/2026-05","updated_at":"2026-05-09 00:48:06","skipped":0,"uncertain":0,"done":3}
```

## 进度概览

| 完成 | 失败 | 跳过 | 总数 |
|------|------|------|------|
| 3 | 0 | 0 | 3 |

---

## 任务列表

### 1. 并发一致性修复

- [√] 1.1 在 `app/Services/TrafficResetService.php` 中锁定最新用户
  - 预期变更: `performReset()` 在事务内使用 `User::lockForUpdate()->findOrFail($user->id)` 获取锁定用户，并在后续更新、日志、缓存和 hook 中使用锁定模型。
  - 完成标准: 临时额度扣除基于数据库最新用户状态，不再基于调用方旧模型。
  - 验证方式: `php -l app/Services/TrafficResetService.php`
  - depends_on: []

### 2. 测试覆盖

- [√] 2.1 增加旧模型与锁定模型不一致场景测试
  - 预期变更: `tests/Unit/TrafficResetTemporaryTrafficTest.php` 覆盖 `performReset()` 使用锁定模型计算临时额度。
  - 完成标准: 旧模型没有临时流量、锁定模型有临时流量时，重置更新值按锁定模型扣除。
  - 验证方式: `vendor/bin/phpunit --bootstrap vendor/autoload.php tests/Unit/TrafficResetTemporaryTrafficTest.php`
  - depends_on: [1.1]

### 3. 验证与归档

- [√] 3.1 执行验证并同步知识库
  - 预期变更: 运行定向语法检查、单元测试、方案包校验，更新本任务状态和知识库记录。
  - 完成标准: 验证通过，方案包归档。
  - 验证方式: `validate_package.py`
  - depends_on: [1.1, 2.1]

---

## 执行日志

| 时间 | 任务 | 状态 | 备注 |
|------|------|------|------|
| 2026-05-09 00:34 | REVIEW | in_progress | 发现 `TrafficResetService::performReset()` 未锁定刷新用户，准备保守修复 |
| 2026-05-09 00:40 | 1.1 | completed | `performReset()` 已在事务内锁定并刷新用户，后续更新、日志、缓存和 hook 改用锁定模型 |
| 2026-05-09 00:41 | 2.1 | completed | 已补充旧模型与锁定模型不一致的单元测试，验证临时额度扣除基于锁定模型 |
| 2026-05-09 00:44 | 3.1 | completed | `php -l` 与定向 PHPUnit 通过，知识库记录已同步 |
| 2026-05-09 00:50 | 复核补正 | completed | 复核 diff 后将 `reset_count` 也改为基于锁定模型递增，并重跑语法检查与定向 PHPUnit 通过 |

---

## 执行备注

- 审查范围: 本次临时流量相关改动。
- 保守方案: 只修复重置事务内用户锁定与对应测试，不扩大为流量账本重构。
