# 任务清单: mail-bounce-suppression

> **@status:** completed | 2026-06-02 16:11

```yaml
@feature: mail-bounce-suppression
@created: 2026-06-02
@status: completed
@mode: R2
```

## LIVE_STATUS

```json
{"status":"completed","completed":8,"failed":0,"pending":0,"total":8,"percent":100,"current":"已归档到 archive/2026-06","updated_at":"2026-06-02 16:11:04","skipped":0,"uncertain":0,"done":8}
```

## 进度概览

| 完成 | 失败 | 跳过 | 总数 |
|------|------|------|------|
| 8 | 0 | 0 | 8 |

---

## 任务列表

### 1. 数据模型

- [√] 1.1 新增 `database/migrations/*_create_mail_suppressions_table.php`
  - 预期变更: 创建 `v2_mail_suppressions` 表，包含全局唯一归一化邮箱、禁发原因、来源、诊断码、原始错误摘要和时间戳。
  - 完成标准: 迁移具备幂等 `up()` 和可回滚 `down()`，`email` 有唯一索引。
  - 验证方式: PHPUnit 测试迁移到 SQLite 后可创建并查询禁发记录。
  - depends_on: []
- [√] 1.2 新增 `app/Models/MailSuppression.php`
  - 预期变更: 提供 `v2_mail_suppressions` 的 Eloquent 模型，允许写入必要字段。
  - 完成标准: 服务层能通过模型创建、更新、查询禁发记录。
  - 验证方式: `MailSuppressionServiceTest` 断言记录字段。
  - depends_on: [1.1]

### 2. 禁发服务

- [√] 2.1 新增 `app/Services/MailSuppressionService.php`
  - 预期变更: 实现邮箱归一化、禁发查询、幂等禁发、永久失败识别、退信正文解析。
  - 完成标准: 能解析用户示例中的 `550 Mailbox not found` 和 `550 Mail is rejected by recipients`，非永久失败不禁发。
  - 验证方式: `tests/Unit/MailSuppressionServiceTest.php` 覆盖解析与幂等行为。
  - depends_on: [1.2]

### 3. 发送链路

- [√] 3.1 修改 `app/Services/MailService.php`
  - 预期变更: 在真实发送前检查禁发表；命中则跳过传输并写入 `MailLog`；捕获永久 SMTP 失败时自动写入禁发表。
  - 完成标准: `notify` HTML 路径和普通模板路径都经过同一禁发检查，错误日志不泄露敏感配置。
  - 验证方式: `tests/Unit/MailServiceSuppressionTest.php` 使用 `Mail::fake()` 断言不发送、记录日志和自动禁发。
  - depends_on: [2.1]
- [√] 3.2 检查 `app/Jobs/SendEmailJob.php`
  - 预期变更: 保持现有超时、重试和失败记录语义；必要时让 suppressed 错误不触发重试。
  - 完成标准: 已禁发邮箱不会被队列反复重试，永久失败仍可触发禁发。
  - 验证方式: `tests/Unit/Jobs/SendEmailJobTest.php` 覆盖 suppressed 返回行为。
  - depends_on: [3.1]

### 4. 验证与文档

- [√] 4.1 新增/更新邮件禁发相关 PHPUnit 测试
  - 预期变更: 添加服务与发送链路单测，更新现有 job 单测。
  - 完成标准: 测试覆盖需求中的成功、跳过、禁发、非禁发边界。
  - 验证方式: `vendor/bin/phpunit tests/Unit/MailSuppressionServiceTest.php tests/Unit/MailServiceSuppressionTest.php tests/Unit/Jobs/SendEmailJobTest.php`
  - depends_on: [3.2]
- [√] 4.2 更新 `.helloagents/modules/queue-mail.md` 与 `.helloagents/CHANGELOG.md`
  - 预期变更: 记录全局禁发表、退信解析能力、发送前拦截行为和本方案链接。
  - 完成标准: 知识库与代码事实一致，CHANGELOG 使用 R2 标准格式。
  - 验证方式: 文件检查。
  - depends_on: [4.1]
- [√] 4.3 执行 review、修复问题并提交 commit
  - 预期变更: 审查本次改动范围，修复阻断问题，提交包含本次业务代码和知识库变更的 commit。
  - 完成标准: `git status` 只剩用户原有 `public/assets/admin` 改动或干净；最新 commit 包含本次变更。
  - 验证方式: `git status --short --branch`、`git show --stat --oneline HEAD`。
  - depends_on: [4.2]

---

## 执行日志

| 时间 | 任务 | 状态 | 备注 |
|------|------|------|------|
| 2026-06-02 15:47 | 方案设计 | in_progress | 已创建方案包并完成唯一方案规划 |
| 2026-06-02 16:05 | 1.1-4.1 | completed | 新增禁发表、模型、服务、发送拦截与测试；相关 PHPUnit 通过 |
| 2026-06-02 16:12 | 4.2 | completed | 已同步 queue-mail 模块、context 和 CHANGELOG |
| 2026-06-02 16:20 | 4.3 | completed | 审查发现并修复退信兜底误抓邮箱与永久失败重试边界；验证通过 |

---

## 执行备注

- 当前工作树已有用户未提交变更: `public/assets/admin`，本流程不得修改或纳入 commit。
- 当前仓库无入站邮箱拉取或退信 webhook 入口，本次先提供退信正文解析服务和发送链路自动禁发，后续可接命令或入站任务。
