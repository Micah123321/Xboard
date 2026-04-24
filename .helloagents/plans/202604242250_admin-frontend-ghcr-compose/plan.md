# admin-frontend GHCR 自动构建与 compose 接入 — 实施规划

## 目标与范围
- 让 `admin-frontend` 从“仓内前端目录”升级为“可独立构建、可独立分发、可独立部署”的静态前端镜像。
- 保持当前主应用镜像发布链路不变，同时补一条面向前端的独立 GHCR 发布通道。
- 让 `compose` 分支可直接拉起独立 `admin` 服务，对外暴露单独端口。

## 架构与实现策略
- 构建链路：
  - 在 `admin-frontend/` 下新增独立 Dockerfile，采用 `Node -> Caddy` 多阶段构建。
  - 通过环境变量把容器构建输出切到 `dist`，避免影响当前仓内 `public/assets/admin` 输出。
- 发布链路：
  - 新增单独的 GitHub Actions workflow，仅在 `admin-frontend/**` 或 workflow 自身变更时触发。
  - 镜像发布到 `ghcr.io/<owner>/xboard-admin-frontend`，标签策略与主应用保持一致：分支、sha、`new`、`latest`。
- 运行链路：
  - 通过 Caddy 在容器内提供静态资源，根路径自动重定向到 `/assets/admin/`。
  - `compose` 分支新增 `admin` 服务，直接拉取 GHCR 镜像并暴露 `7002:80`。

## 完成定义
- `admin-frontend` 本地可通过 Dockerfile 正常构建镜像。
- GitHub Actions 可在 push 后自动构建并推送 `admin-frontend` 镜像到 GHCR。
- `compose` 分支的 `compose.yaml` 已包含独立 `admin` 服务，且镜像名与端口配置正确。
- `npm run build` 与 workflow/compose 语法检查通过。

## 风险与验证
- 风险 1：`vite.config.ts` 目前默认输出到仓根 `public/assets/admin`，容器构建若不切换输出目录会污染镜像构建路径，因此必须引入可覆写输出目录。
- 风险 2：若前端服务直接暴露 `/` 而未处理 `/assets/admin/`，现有资源前缀会失配，因此需要容器内重定向和静态路径兜底。
- 风险 3：当前仓库已有大量未提交业务改动，不能直接切换分支修改 `compose.yaml`，需在独立 worktree 中处理 `compose` 分支文件。
- 验证方式：
  - `npm run build`
  - `docker build -f admin-frontend/Dockerfile admin-frontend`
  - `python -c "import yaml; ..."` 检查 workflow 与 compose YAML

## 决策记录
- [2026-04-24] 采用独立 `admin` 服务，并按用户确认暴露单独端口，不内嵌回 Laravel 主服务容器。
- [2026-04-24] 采用独立 workflow，而不是把前端镜像构建塞进现有后端 `docker-publish.yml`，以降低耦合和回归面。
- [2026-04-24] 前端运行时采用 Caddy 提供静态资源并做 `/ -> /assets/admin/` 重定向，兼容当前 `base: '/assets/admin/'`。
