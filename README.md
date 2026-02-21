# Xboard

<div align="center">

[![Telegram](https://img.shields.io/badge/Telegram-Channel-blue)](https://t.me/XboardOfficial)
![PHP](https://img.shields.io/badge/PHP-8.2+-green.svg)
![MySQL](https://img.shields.io/badge/MySQL-5.7+-blue.svg)
[![License](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

</div>

## 项目简介

Xboard 是基于 Laravel 11 构建的现代化面板系统，专注于提供简洁高效的使用体验。

## 功能特性

- 基于 Laravel 12 + Octane，显著提升性能
- 全新后台界面（React + Shadcn UI）
- 现代化用户前端（Vue3 + TypeScript）
- 开箱即用的 Docker 部署方案
- 优化系统架构，提升可维护性

## 快速开始

```bash
git clone -b compose --depth 1 https://github.com/Micah123321/Xboard && \
cd Xboard && \
docker compose run -it --rm \
    -e ENABLE_SQLITE=true \
    -e ENABLE_REDIS=true \
    -e ADMIN_ACCOUNT=admin@demo.com \
    web php artisan xboard:install && \
docker compose up -d
```

> 安装完成后访问： http://SERVER_IP:7001  
> 请务必保存安装过程中显示的管理员凭据

## 文档

### 升级说明
> **重要：** 此版本包含较大变更。升级前请严格遵循升级文档并备份数据库。请注意，“升级”与“迁移”是不同流程，不要混淆。

### 开发指南
- [插件开发指南](./docs/en/development/plugin-development-guide.md) - Xboard 插件开发完整说明
- [开发者指南（ZH-CN）](./docs/development-guide.zh-CN.md) - 本地开发、测试、运行与构建指南
- [V2bX 对接指南（ZH-CN）](./docs/v2bx-integration.zh-CN.md) - Xboard 到 V2bX 的完整对接指南

### 部署指南
- [使用 1Panel 部署](./docs/en/installation/1panel.md)
- [使用 Docker Compose 部署](./docs/en/installation/docker-compose.md)
- [使用 aaPanel 部署](./docs/en/installation/aapanel.md)
- [使用 aaPanel + Docker 部署](./docs/en/installation/aapanel-docker.md)（推荐）

### 迁移指南
- [从 v2board dev 迁移](./docs/en/migration/v2board-dev.md)
- [从 v2board 1.7.4 迁移](./docs/en/migration/v2board-1.7.4.md)
- [从 v2board 1.7.3 迁移](./docs/en/migration/v2board-1.7.3.md)
- [从 new-dev 升级到最新 Docker 版本](./docs/en/migration/new-dev-to-latest-docker.md)

## 技术栈

- 后端：Laravel 11 + Octane
- 管理后台：React + Shadcn UI + TailwindCSS
- 用户前端：Vue3 + TypeScript + NaiveUI
- 部署方式：Docker + Docker Compose
- 缓存：Redis + Octane Cache

## 预览
![Admin Preview](./docs/images/admin.png)

![User Preview](./docs/images/user.png)

## 免责声明

本项目仅供学习与交流使用。使用本项目所产生的任何后果由使用者自行承担。

## 维护说明

本项目当前处于轻度维护状态。我们将：
- 修复关键 bug 与安全问题
- 审核并合并重要的 Pull Request
- 提供必要的兼容性更新

但新功能开发可能会相对有限。

## 重要提示

1. 修改后台路径后需要重启：
```bash
docker compose restart
```

2. 使用 aaPanel 安装时，请重启 Octane 守护进程

## 参与贡献

欢迎提交 Issue 和 Pull Request 来共同改进项目。

## Star 历史

[![Stargazers over time](https://starchart.cc/Micah123321/Xboard.svg)](https://starchart.cc/Micah123321/Xboard)
