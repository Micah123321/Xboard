# queue-mail

## 职责

- 承接注册验证码、登录链接、工单通知、订阅到期/流量提醒和后台群发邮件的异步发送。
- 通过 `App\Jobs\SendEmailJob` 统一进入 `send_email` 或 `send_email_mass` 队列。
- 通过 `App\Services\MailService` 统一渲染邮件模板、应用运行时 SMTP 配置、发送邮件并写入 `MailLog`。
- 通过 `App\Services\MailSuppressionService` 和 `v2_mail_suppressions` 维护全局邮箱禁发表，命中退信或永久拒收后后续邮件按邮箱地址跳过发送。

## 行为规范

- `SendEmailJob` 的默认 `timeout` 为 60 秒，`tries` 为 3，`backoff()` 为 `[60, 300]`。
- `SendEmailJob::$failOnTimeout = true`，超时作业应直接失败，避免同一封邮件在不确定是否已发出的情况下反复重试。
- 邮件发送返回错误时，`SendEmailJob` 抛出 `RuntimeException`，由 Laravel Queue/Horizon 统一处理重试和失败记录；不再手动 `release(60)`。
- 邮箱已存在于 `v2_mail_suppressions` 时，`MailService::sendEmail()` 不调用真实 mail transport，仍写入 `MailLog`，错误前缀为 `Email suppressed:`；`SendEmailJob` 不再为该类跳过结果重试。
- 同步 SMTP 错误中包含明确永久失败文本和 5xx 状态码时，会写入 `v2_mail_suppressions`；当前覆盖 `550 Mailbox not found`、`550 Mail is rejected by recipients`、`user unknown`、`no such user` 和 `recipient address rejected` 等保守规则。
- `MailSuppressionService::suppressFromBounceMessage()` 可解析 `MAILER-DAEMON` 退信正文中的 `<recipient@example.com>:` 或 `Final-Recipient: rfc822;` 收件人，用于后续入站邮箱、命令或 webhook 接入。
- SMTP 传输超时由 `MAIL_TIMEOUT` 控制，默认 30 秒；`QUEUE_RETRY_AFTER` 默认 90 秒，必须大于邮件 job timeout。
- Horizon 长驻 worker 每次发送前会通过 `MailRuntimeConfig` 应用后台邮件配置，并刷新已解析 mailer，避免后台 SMTP 配置变更后仍使用旧连接。
- `MailLog.config` 只保存脱敏后的邮件配置，`password`、`secret`、`token`、`key` 字段不得以明文持久化。
- `send_email_mass` 队列仍会在邮件正文追加 `[Send-Time: ...]` 标记，用于区分批量发送内容。

## 依赖关系

- 队列配置: `config/queue.php`
- Horizon supervisor: `config/horizon.php`
- 邮件配置: `config/mail.php`
- 运行时配置: `App\Services\MailRuntimeConfig`
- 禁发记录: `App\Models\MailSuppression`, `App\Services\MailSuppressionService`, `v2_mail_suppressions`
- HTML 通知内容: `App\Services\MailHtmlContent`
- 邮件日志模型: `App\Models\MailLog`

## 验证要点

- `SendEmailJob::$timeout` 小于 `config('queue.connections.redis.retry_after')`。
- `MAIL_TIMEOUT` 小于 `SendEmailJob::$timeout`，确保网络层先于 job 层超时。
- 单测应覆盖 job 超时/backoff、邮件错误抛出、批量邮件发送时间标记、`MailLog` 配置脱敏、禁发命中跳过发送、永久失败自动禁发和退信正文解析。
