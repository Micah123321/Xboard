# Xboard 服务器部署模板

这个目录是一套可复制到服务器的 Compose 部署模板，拓扑对齐当前生产用法：

- `web`: Laravel Octane HTTP 服务，默认发布宿主机 `7001`
- `horizon`: 队列进程
- `scheduler`: Laravel Scheduler，负责持续触发 `sync:server-gfw-checks` 等定时任务
- `admin`: 独立管理端前端容器，默认发布宿主机 `7002`
- `ws-server`: 节点 WebSocket 服务，默认发布宿主机 `8076`
- `redis`: 通过 `redis-data` 卷提供 `/data/redis.sock`

模板默认不包含 MySQL。数据库继续使用宿主机、面板或云数据库中的外部 MySQL。

## 首次部署

服务器需要已安装 Docker，并支持 `docker compose` 命令。

```sh
cd xboard-server
sh ./scripts/init.sh
vi .env
sh ./scripts/deploy.sh
```

`.env` 至少需要检查这些项：

- `APP_URL`: 对外访问域名，例如 `https://example.com`
- `APP_KEY`: 新安装可留空后通过 `xboard:install` 生成；已安装实例必须填原来的值
- `DB_HOST / DB_PORT / DB_DATABASE / DB_USERNAME / DB_PASSWORD`: 外部 MySQL 连接
- `MAIL_*`: 邮件发送配置
- `WEB_PORT / ADMIN_PORT / WS_PORT`: 宿主机端口，和现有服务冲突时修改
- `XBOARD_UPLOAD_UPSTREAM`: 管理端图片上传反向代理目标

## 初始化或迁移数据库

全新安装时，先确认 `.env` 里的数据库指向正确，再执行交互式安装：

```sh
docker compose exec web php artisan xboard:install
```

已有数据库升级时，不要重新执行安装命令。需要迁移时执行：

```sh
docker compose exec -T web php artisan migrate --force
```

项目自带更新命令也会执行迁移、默认插件检查和缓存刷新：

```sh
docker compose exec -T web php artisan xboard:update
```

## 启动与更新

启动或重新拉起服务：

```sh
docker compose up -d
```

更新镜像但不自动迁移数据库：

```sh
sh ./scripts/update.sh
```

更新镜像并显式执行数据库迁移：

```sh
sh ./scripts/update.sh --migrate
```

查看服务状态：

```sh
docker compose ps
```

查看日志：

```sh
docker compose logs -f web
docker compose logs -f horizon
docker compose logs -f scheduler
docker compose logs -f ws-server
docker compose logs -f admin
```

## Scheduler 检查

自动墙检测依赖 `scheduler` 容器持续运行。该容器执行 `php artisan schedule:work`。节点开启墙检测托管后，`sync:server-gfw-checks` 默认每 30 分钟由 Laravel Scheduler 创建检测任务。

常用检查命令：

```sh
docker compose ps scheduler
docker compose logs -f scheduler
docker compose exec -T web php artisan schedule:list
```

手动触发一次墙检测同步：

```sh
docker compose exec -T web php artisan sync:server-gfw-checks
```

如果节点页一直显示“未检测”或“等待节点领取”，优先检查：

- `scheduler` 是否在线
- `php artisan schedule:list` 是否能列出 `sync:server-gfw-checks`
- `ws-server` 是否在线，节点端是否已连接
- 节点是否是父节点；子节点不会单独创建检测任务
- 目标节点是否开启了墙检测托管

## 管理端代理

`admin` 容器通过环境变量把管理端请求代理到后端：

```env
XBOARD_BACKEND_UPSTREAM=http://web:7001
XBOARD_UPLOAD_UPSTREAM=https://pic.535888.xyz
```

在默认 Compose 网络内，`http://web:7001` 是后端服务地址，不需要改成宿主机 IP。只有上传服务需要按你的实际图片上传入口调整。

## 目录说明

- `.env`: 服务器真实配置，不应提交到仓库
- `.docker/.data/`: Xboard 容器运行时数据
- `storage/logs/`: Laravel 日志
- `storage/theme/`: 主题资源
- `plugins/`: 插件目录
- `redis-data`: Docker 命名卷，保存 Redis socket 和持久化数据
