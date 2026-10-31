# 管理员多账号入口 IP 同步

管理员后台 **节点管理 → 入口 IP 同步** 支持添加最多 10 个 NYA 账号。同步依次登录已启用的账号并获取各自规则，按连接端口分别匹配主入口与随机额外入口；两个入口可来自不同账号，IP 独立更新，保留原端口。

## 设置与运行

1. 填写公共 NYA 地址（默认 https://nya.trp.sh）、可选后端代理及请求超时；这些连接设置由各账号共用。
2. 旧的单账号设置会自动显示为“账号 1”，原用户名和密码继续有效。点击“添加账号”，填写名称、用户名、密码，可分别启用、停用或删除账号。密码加密保存，留空保留该账号原密码；页面只显示是否已设置。
3. 选择需要同步的 Xboard 节点，主入口与其全部已保存额外入口一起参与匹配。按需设置连接端口白名单，留空表示不限制；额外入口自己的端口也受白名单过滤。
4. 保存设置后点击“预览变更”，检查来源账号、节点、主/额外入口、原 IP、新 IP 与规则 ID。“立即同步”会在确认后实际写库；未保存修改会先保存。
5. 开启自动同步并保存后，现有 Laravel Scheduler 每 5 分钟按最新配置执行。页面显示调度心跳、检查账号和规则数量、最近运行的来源及结果。

例如，主入口来自 mxmicah 的 11843、12192 等端口，随机额外入口来自 saobi 的 11823、12122 等端口时，添加并启用两个账号即可各自匹配。额外入口不会根据主入口 IP 推断或替换，也不需要修改备用连接端口。

## 匹配规则

- 先完整读取所有启用账号的转发规则，再在事务中锁定所选节点并保存；任一账号登录、网络、响应或 DNS 失败时，本轮节点数据保持原值，错误指出失败账号。
- 每组入口按自己的连接端口匹配 NYA `listen_port`，从对应入口组 `connect_host` 取得地址。不存在规则的入口保留并提示。
- 同一被匹配端口在多个账号或多个规则中重复时整轮停止，并报告冲突端口；不会根据账号遍历顺序随意选取。
- 多个账号的规则 ID 可以相同，结果以来源账号区分。账号顺序只决定读取顺序。
- 保留端口、监听端口、父子关系、倍率、随机开关和额外入口数量；关闭随机开关后保留的额外入口仍同步。
- 当前 IP 仍在其规则候选中时保留，否则按 IPv4 优先的固定顺序选择新 IP。再次执行且 IP 未变时不写库。

多账号目录读取有约 180 秒总预算，每账号的三次请求会根据剩余预算限制超时；域名解析等待仍受后端系统 DNS 配置影响。前端运行请求超时为 240 秒，反向代理和 PHP 也应允许相应请求时长。

## 密码、兼容和调度

所有账号在现有 `v2_settings` 中以加密 JSON 保存，依赖稳定的 `APP_KEY`。API 与审计日志不输出密码；保存后各账号密码输入清空。删除账号会从保存配置中移除该账号凭据。旧页面只提交单账号字段时保留其他已配置账号。

现有 `NYA_USERNAME`、`NYA_PASSWORD` 等环境变量继续作为尚未保存配置时的初始默认值。保存后的页面设置优先，不需要重启配置缓存才能读取新账号。本功能没有新增数据库迁移。

自动任务依赖 `php artisan schedule:work` 或系统每分钟执行 `php artisan schedule:run`。手动、CLI 和自动任务共用 10 分钟运行锁，最近结果缓存 7 天；多台后端应使用共享 Redis 缓存。异常终止后，锁过期且释放时遗留运行状态会标为中断，允许重试。

```powershell
php artisan sync:nya-entry-ips --dry-run
php artisan sync:nya-entry-ips
php artisan sync:nya-entry-ips --scheduled
```

更新入口地址使旧健康缓存失效；开启 TCP 检测的随机入口须等待现有每分钟检查通过后参与订阅。客户端重新获取订阅才会收到新 IP，已有连接不主动迁移。

## 管理接口与验证

接口前缀 `/api/v2/{secure_path}`，全部要求管理员身份：`GET/POST /server/entry-sync/settings`、`GET /server/entry-sync/status`、`POST /server/entry-sync/run`（`dry_run=true` 预览）。设置包含 `accounts` 数组，公开账号只返回 `id/name/username/enabled/password_set`；保存时可提供可选 `password/clear_password`。结果包含 `accounts_checked`、`account_summaries`，变更条目包含 `account_name`。

```powershell
$env:APP_ENV='testing'
$env:CACHE_DRIVER='array'
php vendor/bin/phpunit --bootstrap vendor/autoload.php tests/Unit/NyaClientTest.php tests/Feature/Server/NyaEntrySyncTest.php tests/Feature/Server/NyaEntryAdminTest.php
```
