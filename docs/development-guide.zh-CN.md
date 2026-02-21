# Xboard 开发文档（开发环境 / 本地测试 / 运行 / 编译）

本文面向仓库维护者与二次开发者，覆盖本项目在本地和容器中的完整开发流程。

## 1. 项目现状与边界

- 后端框架：Laravel 12（`laravel/framework`）+ Octane（Swoole）+ Horizon。
- 运行依赖：数据库（SQLite / MySQL / PostgreSQL）+ Redis。
- 前端资产：当前仓库已包含编译产物，主要位于：
  - `theme/Xboard/assets`
  - `theme/v2board/assets`
  - `public/assets/admin`
- `package.json` 仅含 `chokidar` 依赖，主要用于 Octane `--watch` 开发体验，不是完整前端工程。

## 2. 开发环境要求

## 2.1 必需依赖

- Git
- PHP `8.2+`
- Composer `2.x`
- Redis `7+`
- 数据库三选一：
  - SQLite（最快）
  - MySQL `5.7+`
  - PostgreSQL

## 2.2 推荐依赖（开发体验）

- Docker + Docker Compose（推荐）
- Node.js `18+`（仅在使用 `php artisan octane:start --watch` 时需要）

## 2.3 操作系统建议

- 推荐 Linux / WSL2。
- `compose.sample.yaml` 使用 `network_mode: host`，在非 Linux 环境可能需要改为端口映射（如 `7001:7001`）。

## 3. Docker 开发模式（推荐）

## 3.1 初始化

```bash
git clone -b compose --depth 1 https://github.com/cedar2025/Xboard
cd Xboard
cp compose.sample.yaml compose.yaml
```

## 3.2 安装（首次）

推荐使用 SQLite + 内置 Redis 的快速安装：

```bash
docker compose run -it --rm \
  -e ENABLE_SQLITE=true \
  -e ENABLE_REDIS=true \
  -e ADMIN_ACCOUNT=admin@demo.com \
  web php artisan xboard:install
```

安装完成后请记录：

- 管理员账号/密码（安装命令输出）
- 后台路径（安装命令输出中的 `secure_path`）

## 3.3 启动与停止

```bash
docker compose up -d
docker compose ps
docker compose logs -f web
docker compose logs -f horizon
```

停止：

```bash
docker compose down
```

## 3.4 访问地址

- 用户前台：`http://<host>:7001/`
- 管理后台：`http://<host>:7001/<secure_path>`

说明：`secure_path` 默认不是固定字符串，而是安装后生成并可在后台配置修改。

## 4. 宿主机本地开发（非 Docker）

## 4.1 安装依赖

```bash
composer install
cp .env.example .env
```

如果你计划使用 SQLite，可先准备文件：

```bash
mkdir -p .docker/.data
touch .docker/.data/database.sqlite
```

## 4.2 首次安装

```bash
php artisan xboard:install
```

安装向导会交互配置：

- 数据库类型（SQLite / MySQL / PostgreSQL）
- Redis 连接（安装阶段会强校验可连通）
- 管理员账号

## 4.3 启动服务（开发三进程）

建议至少开 3 个终端：

1. Web 服务（Octane）
```bash
php artisan octane:start --host=0.0.0.0 --port=7001 --watch
```

2. 队列消费（Horizon）
```bash
php artisan horizon
```

3. 定时任务（开发态）
```bash
php artisan schedule:work
```

说明：

- 生产环境通常由 `cron` 调 `schedule:run`，开发环境直接 `schedule:work` 更直观。
- 若不使用 `--watch`，可不安装 Node.js。

## 4.4 权限问题（Linux）

```bash
chmod -R 775 storage bootstrap/cache .docker/.data
```

## 5. 本地测试与质量检查

## 5.1 当前仓库事实

- 当前仓库未包含 `tests/` 目录。
- 当前仓库未包含 `phpunit.xml` / `phpunit.xml.dist`。

因此默认没有可执行的项目内单元测试套件，建议先补齐测试基建再启用 CI 强校验。

## 5.2 建议的本地检查基线

```bash
php artisan about
php artisan migrate:status
php artisan route:list
vendor/bin/phpstan analyse --memory-limit=1G
```

如后续补充测试用例后，再执行：

```bash
timeout 60s php artisan test
```

> 约定：自动化执行测试时，单条任务建议设置 60s 超时，避免阻塞流水线。

## 6. 运行与调试要点

## 6.1 调度与队列健康

- 定时任务心跳来源：`App\Console\Kernel::schedule` 中写入缓存键 `SCHEDULE_LAST_CHECK_AT`。
- Horizon 状态可在后台系统状态页查看。

若后台提示异常，先检查：

```bash
php artisan schedule:list
php artisan horizon:status
```

## 6.2 关键日志位置

- 应用日志：`storage/logs`
- Docker 运行日志：`docker compose logs -f web|horizon`

## 7. 编译 / 构建说明

## 7.1 应用层“编译”边界

- 本仓库不包含完整的后台/前台前端源码工程。
- 默认是消费已编译好的静态资源，不存在常规 `npm run build` 的全量前端构建链。

## 7.2 Docker 镜像构建

CI 使用 `.github/workflows/docker-publish.yml` 进行多架构构建并推送镜像。

本地可执行：

```bash
docker build \
  --build-arg CACHEBUST=$(date +%s) \
  --build-arg REPO_URL=https://github.com/cedar2025/Xboard \
  --build-arg BRANCH_NAME=master \
  -t xboard:dev .
```

重要说明：

- 当前 `Dockerfile` 在构建时会 `git clone` 指定仓库分支，不直接打包你本地未提交改动。
- 若目标是“本地改动实时调试”，优先使用 Docker Compose 挂载源码运行，而非用该 `Dockerfile` 打镜像。

## 8. 升级、回滚与危险脚本

常用命令：

```bash
php artisan xboard:update
php artisan xboard:rollback
```

注意事项：

- `update.sh` 包含 `git reset --hard origin/master`，会覆盖本地改动，不建议在开发分支使用。
- `xboard:update` / `xboard:install` 内部包含插件目录恢复逻辑，可能回退 `plugins/` 下被 Git 跟踪且非新增的修改。

## 9. 建议开发流程（最小闭环）

1. 建立分支并完成代码改动。
2. 启动 `octane + horizon + schedule:work` 做手工联调。
3. 运行静态检查（`phpstan`）和基础 smoke 检查（`about` / `migrate:status`）。
4. 确认后台路径、队列、定时任务均正常后再提交。

