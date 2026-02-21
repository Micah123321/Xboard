# aaPanel + Docker 环境下的 Xboard 部署指南

## 目录
1. [环境要求](#环境要求)
2. [快速部署](#快速部署)
3. [详细配置](#详细配置)
4. [维护指南](#维护指南)
5. [故障排查](#故障排查)

## 环境要求

### 硬件要求
- CPU：1 核及以上
- 内存：2GB 及以上
- 存储：可用空间 10GB+

### 软件要求
- 操作系统：Ubuntu 20.04+ / CentOS 7+ / Debian 10+
- aaPanel 最新版本
- Docker 和 Docker Compose
- Nginx（任意版本）
- MySQL 5.7+

## 快速部署

### 1. 安装 aaPanel
```bash
curl -sSL https://www.aapanel.com/script/install_6.0_en.sh -o install_6.0_en.sh && \
bash install_6.0_en.sh aapanel
```

### 2. 基础环境配置

#### 2.1 安装 Docker
```bash
# 安装 Docker
curl -sSL https://get.docker.com | bash

# CentOS 系统还需要执行：
systemctl enable docker
systemctl start docker
```

#### 2.2 安装必需组件
在 aaPanel 面板中安装：
- Nginx（任意版本）
- MySQL 5.7
- PHP 和 Redis 不需要安装

### 3. 网站配置

#### 3.1 创建网站
1. 进入：aaPanel > 网站 > 添加站点
2. 填写信息：
   - 域名：填写你的网站域名
   - 数据库：选择 MySQL
   - PHP 版本：选择纯静态

#### 3.2 部署 Xboard
```bash
# 进入网站目录
cd /www/wwwroot/your-domain

# 清理目录
chattr -i .user.ini
rm -rf .htaccess 404.html 502.html index.html .user.ini

# 克隆仓库
git clone https://github.com/Micah123321/Xboard.git ./

# 准备配置文件
cp compose.sample.yaml compose.yaml

# 安装依赖并初始化
docker compose run -it --rm web sh init.sh
```
> 请保存安装完成后显示的管理后台地址、用户名和密码

#### 3.3 启动服务
```bash
docker compose up -d
```

#### 3.4 配置反向代理
将以下内容添加到网站配置：
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

## 维护指南

### 版本更新

> 重要说明：更新命令会因你安装的版本不同而有所区别：
> - 如果是最近安装（新版本），使用：
```bash
docker compose pull && \
docker compose run -it --rm web sh update.sh && \
docker compose up -d
```
> - 如果是较早安装（旧版本），请把 `web` 替换为 `xboard`：
```bash
git config --global --add safe.directory $(pwd)
git fetch --all && git reset --hard origin/master && git pull origin master
docker compose pull && \
docker compose run -it --rm xboard sh update.sh && \
docker compose up -d
```
> 不确定该用哪个命令？先尝试新版本命令，失败后再使用旧版本命令。

### 日常维护
- 定期查看日志：`docker compose logs`
- 监控系统资源使用情况
- 定期备份数据库和配置文件

## 故障排查

如果在安装或运行中遇到问题，请检查：
1. 系统要求是否满足
2. 所有必需端口是否可用
3. Docker 服务是否正常运行
4. Nginx 配置是否正确
5. 查看日志以获取详细报错信息
