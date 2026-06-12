# local-php-runtime

## 职责

- 记录当前 Windows 本机用于 Xboard 后端基础验证的 PHP/Composer 环境。
- 说明本机 PHP 与项目 Docker 运行时之间的能力边界。
- 为后续补跑 Laravel 后端验证、Composer 依赖检查和 Artisan 基础命令提供环境事实。

## 当前环境

```yaml
安装路径: E:\php
PHP: 8.2.31 NTS x64
PHP Extension Build: API20220829,NTS,VS16
Composer: 2.10.1
PATH: 用户级 PATH 已包含 E:\php
配置文件: E:\php\php.ini
时区: Asia/Shanghai
memory_limit: 512M
```

## 已启用扩展

- `bcmath`
- `curl`
- `dom`
- `fileinfo`
- `mbstring`
- `mysqli`
- `openssl`
- `PDO`
- `pdo_mysql`
- `pdo_sqlite`
- `redis`
- `sodium`
- `tokenizer`
- `xml`
- `xmlreader`
- `xmlwriter`
- `zip`

## 行为规范

- Windows 本机环境用于 Composer 安装、依赖解析、Laravel Artisan 基础检查和非 Swoole/非 Horizon 的后端验证。
- 完整运行时验证仍以 Docker/WSL 为准，因为项目 Dockerfile 使用 `phpswoole/swoole:php8.2-alpine` 并依赖 Linux 可用能力。
- `laravel/horizon` 在锁文件中声明 `ext-pcntl` 与 `ext-posix`，这两个扩展在 Windows 原生 PHP 中不可用；本机 Composer 验证需要明确记录该限制。
- 不把 Windows 原生 PHP 视为 Octane/Swoole/Horizon 的完整等价运行环境。

## 验证命令

```powershell
php -v
php -m
composer --version
composer install --dry-run --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix --no-interaction --no-progress
```

## 最近验证

- `php -v`: 通过，显示 PHP 8.2.31 NTS x64。
- `php -m`: 通过，目标扩展已加载。
- `composer --version`: 通过，显示 Composer 2.10.1。
- `composer install --dry-run --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix --no-interaction --no-progress`: 通过，退出码 0。
- `composer check-platform-reqs`: 除 Windows 原生缺失 `ext-pcntl` 和 `ext-posix` 外，其余平台依赖通过。

## 依赖关系

- 项目根 `composer.json` 要求 `php ^8.2`。
- 项目 Dockerfile 当前基于 `phpswoole/swoole:php8.2-alpine`。
- Redis PHP 扩展来自 PECL Windows `php_redis-6.3.0-8.2-nts-vs16-x64.zip`。
