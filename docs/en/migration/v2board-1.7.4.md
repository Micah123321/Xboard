# V2board 1.7.4 迁移指南

本指南说明如何从 V2board 1.7.4 版本迁移到 Xboard。

### 1. 数据库变更概览

- 新增数据表：
  - `v2_server_vless`

### 2. 前置条件

> 注意：请先完成 Xboard 基础安装（不支持 SQLite）：
- [Docker Compose 部署](../installation/docker-compose.md)
- [aaPanel + Docker 部署](../installation/aapanel-docker.md)
- [aaPanel 部署](../installation/aapanel.md)

### 3. 迁移步骤

#### Docker 环境

以下命令默认使用 split/dev 模板中的 `web` 服务；如果你的 `compose.yaml` 来自 `compose.host.sample.yaml` 或 `compose.1panel.sample.yaml`，请把命令中的 `web` 替换为 `xboard`。

```bash
# 1. 停止服务
docker compose down

# 2. 清空数据库
docker compose run -it --rm web php artisan db:wipe

# 3. 导入旧数据库（重要）
# 请手动导入 V2board 1.7.4 数据库

# 4. 执行迁移
docker compose run -it --rm web php artisan migratefromv2b 1.7.4
```

#### aaPanel 环境

```bash
# 1. 清空数据库
php artisan db:wipe

# 2. 导入旧数据库（重要）
# 请手动导入 V2board 1.7.4 数据库

# 3. 执行迁移
php artisan migratefromv2b 1.7.4
```

### 4. 配置迁移

数据迁移完成后，还需要迁移配置文件：
- [配置迁移指南](./config.md)