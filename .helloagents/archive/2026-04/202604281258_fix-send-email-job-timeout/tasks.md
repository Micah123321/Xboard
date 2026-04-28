# 任务清单: fix-send-email-job-timeout

> **@status:** completed | 2026-04-28 13:10

```yaml
@feature: fix-send-email-job-timeout
@created: 2026-04-28
@status: completed
@mode: R2
@type: implementation
@complexity: moderate
```

## LIVE_STATUS

```json
{"status":"completed","completed":5,"failed":0,"pending":0,"total":5,"percent":100,"current":"代码修复、配置调整、测试补充、知识库同步和可用验证已完成；PHP 运行环境缺失导致语法检查与 PHPUnit 待补跑","updated_at":"2026-04-28 13:11:00"}
```

## 进度概览

| 完成 | 失败 | 跳过 | 总数 |
|------|------|------|------|
| 5 | 0 | 0 | 5 |

---

## 任务列表

### 1. 邮件队列执行策略

- [√] 1.1 修改 `app/Jobs/SendEmailJob.php`
  - 预期变更: 将 job timeout 从 10 秒提升到适合 SMTP 的值，增加 timeout 失败处理、backoff，并在邮件发送返回错误时抛出异常。
  - 完成标准: job timeout 大于 `MAIL_TIMEOUT` 默认值且小于 Redis `retry_after` 默认值；失败摘要不再依赖手动 `release(60)`。
  - 验证方式: `php -l app/Jobs/SendEmailJob.php`；相关单元测试断言 timeout/backoff/失败抛出。
  - depends_on: []

### 2. 邮件服务运行时配置

- [√] 2.1 修改 `app/Services/MailService.php`
  - 预期变更: 统一应用运行时 SMTP 配置和 timeout，刷新 MailManager 缓存，并对写入 `MailLog` 的 config 脱敏。
  - 完成标准: `MAIL_TIMEOUT` 能写入 legacy 和 smtp mailer 配置；`password` 不以明文进入 `MailLog.config`。
  - 验证方式: `php -l app/Services/MailService.php`；相关单元测试断言配置和脱敏行为。
  - depends_on: []

### 3. 队列与环境配置

- [√] 3.1 修改 `config/mail.php`、`config/queue.php` 和 `.env.example`
  - 预期变更: 增加 `MAIL_TIMEOUT` 默认配置；Redis/database/beanstalkd `retry_after` 支持环境变量，默认值保持大于邮件 job timeout。
  - 完成标准: 默认 `MAIL_TIMEOUT=30`、`QUEUE_RETRY_AFTER=90`，不会低于 `SendEmailJob::$timeout=60`。
  - 验证方式: 文件检查；`php -l config/mail.php`；`php -l config/queue.php`。
  - depends_on: [1.1]

### 4. 测试覆盖

- [√] 4.1 新增邮件队列相关单元测试
  - 预期变更: 覆盖 `SendEmailJob` 的 timeout/backoff/失败抛出，以及 `MailService` 邮件配置脱敏。
  - 完成标准: 测试不依赖真实 SMTP，不读取真实 `.env` 密码。
  - 验证方式: `vendor/bin/phpunit --filter SendEmailJobTest`；`vendor/bin/phpunit --filter MailServiceTest`。
  - depends_on: [1.1, 2.1, 3.1]

### 5. 验收与知识库同步

- [√] 5.1 执行验证并同步知识库
  - 预期变更: 运行可用的语法检查/单测，更新 `.helloagents` 知识库和方案包状态。
  - 完成标准: 验证结果记录在执行日志；相关知识库模块或 CHANGELOG 反映本次修复。
  - 验证方式: 查看命令输出、`tasks.md` 状态和 `.helloagents/CHANGELOG.md`。
  - depends_on: [4.1]

---

## 执行日志

| 时间 | 任务 | 状态 | 备注 |
|------|------|------|------|
| 2026-04-28 13:11 | 5.1 | completed | 已完成 diff 检查、方案包校验、知识库同步；本地缺少 php/composer/docker，PHP 语法检查和 PHPUnit 待在目标环境补跑 |
| 2026-04-28 13:08 | 1.1-4.1 | completed | 已完成邮件 job、运行时邮件配置、环境配置和单测补充；本地缺少 php/composer，自动化执行待补跑 |
| 2026-04-28 12:58 | DESIGN | completed | 已完成上下文收集和唯一方案规划 |

---

## 执行备注

- 生产历史失败作业不在本方案内自动重试，避免重复邮件。
- 当前环境未安装 `php`、`composer` 或 `docker`，无法执行 `php -l` 与 PHPUnit；已完成静态 diff 检查、方案包校验和人工代码审查，仍需在具备 PHP/Composer 的环境补跑命令。
