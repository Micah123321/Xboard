# PHP2Shell 运行时修复

本项目通过升级 PHP 引擎处理 CVE-2026-109618 与 CVE-2026-109619。Laravel 中间件或依赖更新不能替代这次引擎升级。

## 修复版本

| 分支 | 最低修复版本 |
| --- | --- |
| 8.2 | 8.2.35 |
| 8.3 | 8.3.36 |
| 8.4 | 8.4.27 |
| 8.5 | 8.5.12 |

## 镜像构建

修复时实测浮动 phpswoole/swoole:php8.2-alpine 仍为 PHP 8.2.34，官方已修复的 PHP 容器标签尚未可用。两份 Dockerfile 固定现有 Swoole/Alpine 基础镜像 digest，再从官方 PHP 8.2.35 发布源码重建引擎，保留原 configure 参数。

- 源码：https://www.php.net/distributions/php-8.2.35.tar.xz
- SHA256：5737b98601fe5ab1b8c5db0c82ed3158acb9283ed3081ffe2faffb9ac81455e6

下载必须先校验 SHA256，失败即中止；同时更新扩展安装器使用的 /usr/src/php.tar.xz，避免继续用旧源码编译扩展。保留固定基础镜像中的 Swoole 扩展，并用加载与协程检查验证补丁级升级兼容性。后续更换上游镜像时仍须实测引擎版本和扩展兼容性。

## 构建和启动门禁

check-runtime.php 按分支判断补丁下限，拒绝旧版本、预发行版及尚未核实的新分支。两份构建均执行门禁和 PHP 扩展、SQLite、Swoole 冒烟检查。多架构发布的每个架构都必须通过构建内检查。

默认 ENTRYPOINT 在应用更新或服务启动之前，使用 /usr/local/bin/php -n 检查真实二进制版本，不合格以退出码 78 中止。Compose 的 command 覆写仍经过门禁；显式 --entrypoint 覆写可跳过启动门禁，验收仍须单独核对实际二进制。

zz-xboard-security.ini 在请求解析之前关闭 session.upload_progress.enabled，仅作附加防护。保留有限的默认 memory_limit，不以 memory_limit=-1 替代引擎修复，不全局禁用正常业务上传。

## 发布和验收

先备份配置、镜像和数据库，再用明确镜像版本重建 web、horizon、scheduler、ws-server。仅上传源码、更新宿主机 PHP、拉取基础镜像或重启旧容器都不会替换运行中的 PHP。长驻进程必须通过重新创建容器载入新引擎。

```sh
docker build --pull -t xboard:php2shell-fixed .
docker run --rm --entrypoint /usr/local/bin/php xboard:php2shell-fixed /usr/local/bin/xboard-smoke-php
for service in web horizon scheduler ws-server; do
  docker compose exec -T "$service" /usr/local/bin/php -n /usr/local/bin/xboard-check-php
  docker compose exec -T "$service" /usr/local/bin/php /usr/local/bin/xboard-smoke-php
done
```

还须核验 HTTP API、正常 JSON/multipart、错误请求后的后续请求、队列、调度与 WebSocket。请求测试先在无生产数据的隔离容器执行，不向生产发送利用或内存耗尽载荷，不开放 phpinfo/诊断接口。

旧镜像回滚会重新暴露旧 PHP 风险；若业务必须回滚，应限制入口或维护停机，不能将旧镜像视为安全退路。

非 Docker 部署必须升级实际处理请求的宿主 PHP 并重启 FPM/Apache/Octane/队列。Docker 门禁不会自动更新宿主机或其他项目；CLI 版本也不能代替 Web 进程验收。

## 本次验证状态

生产和本地两份 Dockerfile 已在 linux/amd64 实际构建，镜像内为 PHP 8.2.35；扩展、SQLite、Swoole 协程与正常 JSON/multipart/文件上传通过，错误 JSON 后可继续处理正常请求。修复版 PHP 中的 85 项业务与安全回归、893 条断言通过；真实默认入口和 Octane 配置 API 在隔离 SQLite 夹具中返回 200。旧 PHP 8.2.34 镜像在新启动门禁下以 78 退出。

同时为 shell 文件设置 LF 换行，修复 Windows 构建上下文中入口脚本因 CRLF 无法执行的问题。arm64 尚未执行，本地 Windows PHP 未升级。

候选构建验证记录位于 output/php2shell/verification-result.json。生产部署须单独核对明确提交、镜像 ID，以及四类 PHP 容器的实际引擎版本；不能从候选测试通过推断线上已经修复。当前发布结果另记于 output/releases/php2shell-deployment/deployment-result.json。

## 来源

- [PHP 官方安全发布说明](https://www.php.net/index.php)
- [8.2.35 发布邮件和源码 SHA256](https://discourse.thephp.foundation/t/php-dev-php-8-2-35-released/6276)
- [OOM corruption 官方公告](https://github.com/php/php-src/security/advisories/GHSA-w8hw-w9fx-j79w)
- [Stale global state 官方公告](https://github.com/php/php-src/security/advisories/GHSA-6wmf-4rv5-p3rp)
- [Sansec PHP2Shell 分析](https://sansec.io/research/php2shell)
