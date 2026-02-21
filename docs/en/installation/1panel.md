# 1Panel 快速部署指南

本文档介绍如何使用 1Panel 部署 Xboard。

## 1. 环境准备

安装 1Panel：
```bash
curl -sSL https://resource.fit2cloud.com/1panel/package/quick_start.sh -o quick_start.sh && \
sudo bash quick_start.sh
```

## 2. 环境配置

1. 从应用商店安装：
   - OpenResty（任意版本）
     - 勾选“外部端口访问”以放行防火墙
   - MySQL 5.7（ARM 架构请使用 MariaDB）

2. 创建数据库：
   - 数据库名：`xboard`
   - 用户名：`xboard`
   - 权限：所有主机（%）
   - 请保存数据库密码，安装时需要使用

## 3. 部署步骤

1. 添加网站：
   - 进入“网站” > “创建网站” > “反向代理”
   - 域名：填写你的域名
   - 代号：`xboard`
   - 代理地址：`127.0.0.1:7001`

2. 配置反向代理：
```nginx
location ^~ / {
    proxy_pass http://127.0.0.1:7001;
    proxy_http_version 1.1;
    proxy_set_header Connection "";
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Real-PORT $remote_port;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header Host $http_host;
    proxy_set_header Scheme $scheme;
    proxy_set_header Server-Protocol $server_protocol;
    proxy_set_header Server-Name $server_name;
    proxy_set_header Server-Addr $server_addr;
    proxy_set_header Server-Port $server_port;
    proxy_cache off;
}
```

3. 安装 Xboard：
```bash
# 进入网站目录
cd /opt/1panel/apps/openresty/openresty/www/sites/xboard/index

# 安装 Git（未安装时执行）
## Ubuntu/Debian
apt update && apt install -y git
## CentOS/RHEL
yum update && yum install -y git

# 克隆仓库
git clone -b compose --depth 1 https://github.com/Micah123321/Xboard ./

# 配置 Docker Compose
```

4. 编辑 compose.yaml：
```yaml
services:
  web:
    image: ghcr.io/Micah123321/xboard:new
    volumes:
      - ./.docker/.data/redis/:/data/
      - ./.env:/www/.env
      - ./.docker/.data/:/www/.docker/.data
      - ./storage/logs:/www/storage/logs
      - ./storage/theme:/www/storage/theme
      - ./plugins:/www/plugins
    environment:
      - docker=true
    depends_on:
      - redis
    command: php artisan octane:start --host=0.0.0.0 --port=7001
    restart: on-failure
    ports:
      - 7001:7001
    networks:
      - 1panel-network

  horizon:
    image: ghcr.io/Micah123321/xboard:new
    volumes:
      - ./.docker/.data/redis/:/data/
      - ./.env:/www/.env
      - ./.docker/.data/:/www/.docker/.data
      - ./storage/logs:/www/storage/logs
      - ./plugins:/www/plugins
    restart: on-failure
    command: php artisan horizon
    networks:
      - 1panel-network
    depends_on:
      - redis

  redis:
    image: redis:7-alpine
    command: redis-server --unixsocket /data/redis.sock --unixsocketperm 777
    restart: unless-stopped
    networks:
      - 1panel-network
    volumes:
      - ./.docker/.data/redis:/data

networks:
  1panel-network:
    external: true
```

5. 初始化安装：
```bash
# 安装依赖并初始化
docker compose run -it --rm web php artisan xboard:install
```

重要配置说明：
1. 数据库配置
   - Database Host：按部署方式填写：
     1. 如果数据库与 Xboard 在同一网络，填写 `mysql`
     2. 如果连接失败，进入：数据库 -> 选择数据库 -> 连接信息 -> 容器连接，使用其中的 Host 值
     3. 如果使用外部数据库，填写实际数据库地址
   - Database Port：`3306`（默认端口，除非你另有配置）
   - Database Name：`xboard`（前面创建的数据库）
   - Database User：`xboard`（前面创建的用户）
   - Database Password：填写前面保存的密码

2. Redis 配置
   - 选择使用内置 Redis
   - 无需额外配置

3. 管理员信息
   - 保存安装完成后显示的管理员账号信息
   - 记录管理后台访问地址

配置完成后，启动服务：
```bash
docker compose up -d
```

6. 启动服务：
```bash
docker compose up -d
```

## 4. 版本更新

> 重要说明：更新命令会因安装版本不同而有所区别：
> - 如果是最近安装（新版本），使用以下命令：
```bash
docker compose pull && \
docker compose run -it --rm web php artisan xboard:update && \
docker compose up -d
```
> - 如果是较早安装（旧版本），请把 `web` 替换为 `xboard`：
```bash
docker compose pull && \
docker compose run -it --rm xboard php artisan xboard:update && \
docker compose up -d
```
> 不确定该用哪个命令？先尝试新版本命令，失败后再使用旧版本命令。

## 重要提示

- 请确保已开启防火墙，避免 7001 端口直接暴露到公网
- 代码修改后需要重启服务才能生效
- 建议配置 SSL 证书以保障访问安全
