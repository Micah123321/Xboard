# deploy

## 职责

- 维护可复制到服务器的一键部署模板
- 收敛 Docker Compose 服务拓扑、环境变量模板、运行目录初始化脚本和常用运维命令
- 为依赖 Laravel Scheduler 的后台任务提供明确的部署进程入口

## 行为规范

- `deploy/xboard-server/` 是面向服务器复制部署的自包含目录，不依赖仓库根目录的 compose 样例
- `compose.yaml` 默认包含 `web / horizon / scheduler / admin / ws-server / redis` 六个服务
- `scheduler` 服务固定执行 `php artisan schedule:work`，用于持续触发 `sync:server-gfw-checks`、`sync:server-auto-online` 和其他 Laravel Scheduler 任务
- 模板默认使用外部 MySQL，不在 compose 中创建数据库服务，避免改变现有生产拓扑
- `.env.example` 同时覆盖 Docker Compose 变量和 Laravel 运行变量，但不得包含真实 `APP_KEY`、数据库密码、邮箱密码或真实业务域名
- `scripts/init.sh` 只创建挂载目录并在 `.env` 不存在时复制模板，不执行数据库迁移
- `scripts/deploy.sh` 只负责初始化、拉取镜像和启动服务，不自动执行生产数据库迁移
- `scripts/update.sh` 执行 `docker compose pull`、`docker compose run -it --rm web php artisan xboard:update`、`docker compose up -d`；非交互终端会自动去掉 `-it`
- `scripts/status.sh` 输出 compose 状态、scheduler 日志、`schedule:list` 结果和手动墙检测同步命令

## 依赖关系

- 依赖 `ghcr.io/micah123321/xboard:new` 作为后端默认镜像
- 依赖 `ghcr.io/micah123321/xboard-admin-frontend:new` 作为管理端默认镜像
- 依赖 `redis:8-alpine` 提供 `/data/redis.sock`
- 依赖外部 MySQL，由 `.env` 中的 `DB_*` 配置提供
- 依赖 `admin-frontend/Caddyfile` 支持 `XBOARD_BACKEND_UPSTREAM` 和 `XBOARD_UPLOAD_UPSTREAM`
- 依赖 `app/Console/Kernel.php` 注册 `sync:server-gfw-checks` 等定时任务
