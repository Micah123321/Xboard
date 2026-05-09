# Xboard 服务器部署模板

这个目录是一套可复制到服务器的 Compose 部署模板，拓扑对齐当前生产用法：

- `web`: Laravel Octane HTTP 服务，默认发布宿主机 `7001`
- `horizon`: 队列进程
- `scheduler`: Laravel Scheduler，负责持续触发 `sync:server-gfw-checks` 等定时任务
- `user`: 用户前端容器，默认发布宿主机 `7003`
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
- `WEB_PORT / USER_PORT / ADMIN_PORT / WS_PORT`: 宿主机端口，和现有服务冲突时修改
- `XBOARD_USER_BACKEND_UPSTREAM / XBOARD_ADMIN_BACKEND_UPSTREAM`: 用户端和管理端的后端 API 代理目标，默认指向 `host.docker.internal:${WEB_PORT}`
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

更新镜像并执行项目更新流程：

```sh
sh ./scripts/update.sh
```

该脚本在交互终端中的等价命令：

```sh
docker compose pull
docker compose run -it --rm web php artisan xboard:update
docker compose up -d
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

## 前端代理

`user` 和 `admin` 容器通过环境变量把 `/api` 请求代理到后端：

```env
XBOARD_USER_BACKEND_UPSTREAM=http://host.docker.internal:7001
XBOARD_ADMIN_BACKEND_UPSTREAM=http://host.docker.internal:7001
XBOARD_UPLOAD_UPSTREAM=https://pic.535888.xyz
```

默认使用 `host.docker.internal:${WEB_PORT}`，这样前端容器会通过宿主机发布的稳定后端端口访问 Laravel，不依赖 Docker 内部服务名解析出的容器 IP。只有上传服务需要按你的实际图片上传入口调整。

### API 502 排查

如果用户端或节点端请求 `/api/v1/*` 返回 502，并且前端容器日志出现类似信息：

```text
connect() failed (111: Connection refused) while connecting to upstream, upstream: "http://172.x.x.x:7001/api/v1/..."
```

同时直接访问宿主机后端端口正常，例如：

```sh
curl -I http://127.0.0.1:7001/api/v1/user/getSubscribe
```

说明 Laravel 后端本身可达，故障在前端容器缓存或解析到的上游地址。即时恢复可以先重启前端容器刷新上游解析：

```sh
docker compose restart user admin
```

持久修复请确保 `.env` 中使用稳定的 host-gateway 上游：

```env
XBOARD_USER_BACKEND_UPSTREAM=http://host.docker.internal:7001
XBOARD_ADMIN_BACKEND_UPSTREAM=http://host.docker.internal:7001
```

旧版 `.env` 中如果仍有 `XBOARD_BACKEND_UPSTREAM=http://web:7001`，可以保留给旧模板使用，但新模板的用户端和管理端应优先设置上面两个专用变量。

然后重新拉起容器：

```sh
docker compose up -d user admin
```

## 目录说明

- `.env`: 服务器真实配置，不应提交到仓库
- `.docker/.data/`: Xboard 容器运行时数据
- `storage/logs/`: Laravel 日志
- `storage/theme/`: 主题资源
- `plugins/`: 插件目录
- `redis-data`: Docker 命名卷，保存 Redis socket 和持久化数据
