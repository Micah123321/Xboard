# 项目上下文

## 基本信息

- 项目: Xboard-new
- 当前工作目录: `E:\code\php\Xboard-new`
- 主要栈: Laravel(PHP) + Vue3/TypeScript/Vite/Element Plus (`admin-frontend`)

## 技术上下文

- 管理端前端位于 `admin-frontend/`
- 管理端 API 通过 `window.settings.secure_path` 或 `VITE_ADMIN_PATH` 解析 `/api/v2/{secure_path}` 前缀
- 登录接口复用 `/api/v2/passport/auth/login`
- 管理端仪表盘现已接入:
  - `stat/getStats`
  - `stat/getOrder`
  - `stat/getTrafficRank`
  - `system/getSystemStatus`
  - `system/getQueueStats`

## 项目概述

- 主仓仍以 Laravel 为后端真相源
- `admin-frontend` 负责独立管理后台 UI 与交互逻辑
- `public/assets/admin` 为构建产物输出位置

## 开发约定

- 管理端路由使用 Hash 模式
- Bearer Token 存储于 `sessionStorage/localStorage`
- `admin-frontend` 的视觉方向当前以 Apple 风格为基线，优先纯色分区、系统字体栈和低装饰成本

## 当前约束

- 本地预览环境默认缺少真实 `secure_path` 与管理员凭证
- 后端接口契约以仓库内 Controller/Route 为准，不在前端推断字段
