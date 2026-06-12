# 变更提案: php-environment-install

## 元信息
```yaml
类型: 环境安装
方案类型: implementation
优先级: P1
状态: 已确认
创建: 2026-06-12
```

---

## 1. 需求

### 背景
当前项目是 Laravel 12 后端与 Vue3 管理端前端组合项目。已有未完成方案包 `202604250006_ticket-closed-reply-reopen` 卡在“等待具备 PHP/Composer 的环境补跑后端验证”。本机当前未检测到 `php` 与 `composer` 命令，用户要求根据当前项目编译/运行要求，把 PHP 环境安装到 `E:\php`。

### 目标
- 安装可在 Windows 本机使用的 PHP 8.2 环境到 `E:\php`。
- 安装 Composer 2.x 并提供 `composer` 命令入口。
- 启用 Composer 与 Laravel 基础验证所需扩展，包含 `curl`、`fileinfo`、`mbstring`、`mysqli`、`openssl`、`pdo_mysql`、`pdo_sqlite`、`sodium`、`zip`、`redis`。
- 将 `E:\php` 写入用户级 PATH，便于后续新终端直接使用。
- 明确 Windows 原生 PHP 与项目 Docker 运行时的差异边界。

### 约束条件
```yaml
安装目录: E:\php
项目硬约束: composer.json 要求 php ^8.2
Docker 参考运行时: phpswoole/swoole:php8.2-alpine
Windows 边界: ext-pcntl、ext-posix、Swoole/Octane 完整运行不适用于原生 Windows PHP
写入范围: E:\php、用户级 PATH、.helloagents 方案包
不做事项: 不安装 MySQL/Redis 服务端，不运行生产或远程命令，不覆盖非空未知 PHP 目录
```

### 验收标准
- [ ] `E:\php\php.exe` 存在，`php -v` 显示 PHP 8.2.x。
- [ ] `E:\php\php.ini` 使用 UTF-8 无 BOM 写入，并启用目标扩展。
- [ ] `php -m` 能看到 `curl`、`fileinfo`、`mbstring`、`openssl`、`PDO`、`pdo_mysql`、`pdo_sqlite`、`redis`、`sodium`、`zip`。
- [ ] `composer --version` 显示 Composer 2.x。
- [ ] 当前会话可直接运行 `php` 与 `composer`；用户级 PATH 包含 `E:\php`。
- [ ] 对项目运行一次平台依赖验证，明确 Windows 原生缺口。

---

## 2. 方案

### 技术方案
使用官方 Windows PHP 发布接口确认当前 PHP 8.2 稳定包，下载 `php-8.2.31-nts-Win32-vs16-x64.zip` 并校验官方 SHA256 后解压到 `E:\php`。使用 PECL Windows Redis 6.3.0 对应 `8.2-nts-vs16-x64` 包安装 `php_redis.dll`。下载 Composer 2.10.1 `composer.phar`，校验 SHA256 后写入 `composer.bat`。基于 `php.ini-development` 生成 `php.ini`，启用本项目所需扩展并设置 `memory_limit=512M`、`date.timezone=Asia/Shanghai`。

### 影响范围
```yaml
涉及模块:
  - local-php-runtime: Windows 本机 PHP/Composer 环境
  - project-validation: 为 Laravel 后端基础验证提供命令前置条件
预计变更文件: E:\php 目录内运行时文件 + .helloagents 方案包文件
```

### 风险评估
| 风险 | 等级 | 应对 |
|------|------|------|
| Windows 原生 PHP 不支持 `pcntl`/`posix`，Composer 全量平台检查会报告缺口 | 中 | 安装阶段明确边界；完整 Horizon/Octane 使用 Docker/WSL |
| 下载包被篡改或不完整 | 中 | PHP 与 Composer 使用官方 SHA256 校验，失败即停止 |
| `E:\php` 已有未知内容被覆盖 | 中 | 仅在目录为空或已存在 `php.exe` 时继续；未知非空目录停止 |
| 用户级 PATH 更新不会影响已打开终端 | 低 | 当前会话临时 prepend PATH；提示新终端需重开 |

### 方案取舍
```yaml
唯一方案理由: 项目 Dockerfile 固定 PHP 8.2，composer.json 要求 ^8.2；安装 PHP 8.2 NTS x64 能最大限度匹配项目运行时且避免 PHP 8.4/8.5 带来的新版本偏差。
放弃的替代路径:
  - PHP 8.4/8.5: 虽满足 ^8.2，但与 Docker 运行时不一致，验证结果可能偏离生产镜像。
  - Thread Safe 版本: CLI/Composer 本机开发不需要 TS，NTS 更适合命令行和常规开发。
  - 仅安装 Docker/WSL 环境: 更接近完整运行时，但用户明确要求安装到 E:\php。
回滚边界: 删除或重命名 E:\php，并从用户级 PATH 移除 E:\php；项目源码不受影响。
```

---

## 3. 技术设计

N/A。此任务不改变项目架构、API 或数据模型。

---

## 4. 核心场景

### 场景: Windows 本机后端基础验证
**模块**: local-php-runtime  
**条件**: 当前项目位于 `E:\code\php\Xboard-new`，本机可访问官方 PHP/Composer/PECL 下载源。  
**行为**: 在当前会话或新终端运行 `php -v`、`php -m`、`composer --version` 和 Composer 平台依赖检查。  
**结果**: PHP/Composer 可用；Laravel 基础命令具备执行前置条件；Windows 不支持的运行时扩展被明确标注。

---

## 5. 技术决策

### php-environment-install#D001: 采用 PHP 8.2 NTS x64 匹配项目 Docker 运行时
**日期**: 2026-06-12  
**状态**: ✅采纳  
**背景**: 项目 `composer.json` 要求 `php ^8.2`，Dockerfile 使用 `phpswoole/swoole:php8.2-alpine`。本机安装应服务于 Composer、Artisan 基础检查和后端验证前置条件。  
**选项分析**:
| 选项 | 优点 | 缺点 |
|------|------|------|
| A: PHP 8.2 NTS x64 | 与 Docker PHP 主版本一致，稳定，适合 CLI/Composer | Windows 原生仍缺 `pcntl`/`posix`/Swoole |
| B: PHP 8.4/8.5 NTS x64 | 更新，安全维护周期更长 | 与项目镜像主版本不一致，可能暴露非目标版本差异 |
| C: 只用 Docker/WSL | 最接近完整运行时 | 不满足安装到 `E:\php` 的目标 |
**决策**: 选择方案 A。  
**理由**: 最符合项目当前 Docker 构建事实和用户指定安装路径，能解决本机缺 PHP/Composer 的阻断问题。  
**影响**: 后续本地验证优先使用 `E:\php`；完整运行时验证仍以 Docker/WSL 为准。

---

## 6. 验证策略

```yaml
verifyMode: review-first
reviewerFocus:
  - 安装路径是否限定在 E:\php
  - 下载包是否来自官方源并通过校验
  - php.ini 启用扩展是否覆盖 Composer/Laravel 基础需要
testerFocus:
  - E:\php\php.exe -v
  - E:\php\php.exe -m
  - E:\php\composer.bat --version
  - composer install --dry-run 或 composer check-platform-reqs 的平台依赖反馈
uiValidation: none
riskBoundary:
  - 不删除非空未知 E:\php
  - 不写入系统级 PATH
  - 不连接生产环境或远程服务器
  - 不把 Windows 原生 PHP 声称为完整 Docker/Swoole 等价运行时
```

---

## 7. 成果设计

N/A。此任务无视觉产出。
