# 从 `new-dev` 升级到最新 Docker 版本

本指南使用 Docker Compose，将旧的 `new-dev` 部署流程迁移到最新版本。

## 1. 获取项目

```bash
git clone -b compose --depth 1 https://github.com/Micah123321/Xboard Xboard-new
cd Xboard-new
```

如果你的本地文件是 `compose.sample.yaml`，请先创建 `compose.yaml`：

```bash
cp compose.sample.yaml compose.yaml
```

## 2. 使用内置 SQLite + Redis 初始化

```bash
docker compose run -it --rm \
    -e ENABLE_SQLITE=true \
    -e ENABLE_REDIS=true \
    -e ADMIN_ACCOUNT=admin@demo.com \
    web php artisan xboard:install
```

## 3. 将 `.env` 更新为 MySQL 配置

编辑 `.env`，将数据库配置切换为 MySQL：

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=xboard
DB_USERNAME=your_user
DB_PASSWORD=your_password
```

然后执行：

```bash
docker compose run -it --rm web php artisan xboard:update
```

## 4. 启动服务

```bash
docker compose up -d
```

## 5. 检查状态

```bash
docker compose ps
docker compose logs -f web
docker compose logs -f horizon
```