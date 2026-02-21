# 配置迁移指南

本指南说明如何将配置文件从 v2board 迁移到 Xboard。Xboard 将配置存储在数据库中，而不是文件中。

### 1. Docker Compose 环境

1. 准备配置文件：
```bash
# 创建 config 目录
mkdir config

# 复制旧配置文件
cp old-project-path/config/v2board.php config/
```

2. 修改 `docker-compose.yaml`，取消注释以下行：
```yaml
- ./config/v2board.php:/www/config/v2board.php
```

3. 执行迁移：
```bash
docker compose run -it --rm web php artisan migrateFromV2b config
```

### 2. aaPanel 环境

1. 复制配置文件：
```bash
cp old-project-path/config/v2board.php config/v2board.php
```

2. 执行迁移：
```bash
php artisan migrateFromV2b config
```

### 3. aaPanel + Docker 环境

1. 复制配置文件：
```bash
cp old-project-path/config/v2board.php config/v2board.php
```

2. 执行迁移：
```bash
docker compose run -it --rm web php artisan migrateFromV2b config
```

### 重要说明

- 修改后台路径后需要重启服务：
  - Docker 环境：`docker compose restart`
  - aaPanel 环境：重启 Octane 守护进程