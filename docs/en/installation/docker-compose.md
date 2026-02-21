# Docker Compose 快速部署指南

本文档介绍如何使用 Docker Compose 快速部署 Xboard。默认使用 SQLite 数据库，无需额外安装 MySQL。

### 1. 环境准备

安装 Docker：
```bash
curl -sSL https://get.docker.com | bash

# CentOS 系统还需要执行：
systemctl enable docker
systemctl start docker
```

### 2. 部署步骤

1. 获取项目文件：
```bash
git clone -b compose --depth 1 https://github.com/Micah123321/Xboard
cd Xboard
```

2. 安装数据库：  

- 快速安装（推荐新手）
```bash
docker compose run -it --rm \
    -e ENABLE_SQLITE=true \
    -e ENABLE_REDIS=true \
    -e ADMIN_ACCOUNT=admin@demo.com \
    web php artisan xboard:install
```
- 自定义配置安装（高级用户）
```bash
docker compose run -it --rm web php artisan xboard:install
```
> 请保存安装完成后显示的管理后台地址、用户名和密码

3. 启动服务：
```bash
docker compose up -d
```

4. 访问站点：
- 默认端口：7001
- 访问地址：http://your-server-ip:7001

### 3. 版本更新

> 重要说明：更新命令会因你安装的版本不同而有所区别：
> - 如果是最近安装（新版本），使用：
```bash
cd Xboard
docker compose pull && \
docker compose run -it --rm web php artisan xboard:update && \
docker compose up -d
```
> - 如果是较早安装（旧版本），请把 `web` 替换为 `xboard`：
```bash
cd Xboard
docker compose pull && \
docker compose run -it --rm xboard php artisan xboard:update && \
docker compose up -d
```
> 不确定该用哪个命令？先尝试新版本命令，失败后再使用旧版本命令。

### 4. 版本回滚

1. 修改 `docker-compose.yaml` 中的版本号为你要回滚的版本
2. 执行：`docker compose up -d`

### 重要提示

- 如果需要使用 MySQL，请单独安装后重新部署
- 代码有变更时，需要重启服务后才会生效
- 可以配置 Nginx 反向代理以使用 80 端口
