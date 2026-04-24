# admin-frontend GHCR 自动构建与 compose 接入 — 需求

确认后冻结，执行阶段不可修改。如需变更必须回到设计阶段重新确认。

## 核心目标
- 为 `admin-frontend` 增加独立的 Docker 构建与 GitHub Actions 发布链路。
- 在代码提交后，自动构建 `admin-frontend` 镜像并推送到 GHCR。
- 按用户已确认的方案，把 `compose` 分支的 `compose.yaml` 增加独立 `admin` 服务，并暴露独立访问端口。

## 功能边界
- 镜像必须只面向 `admin-frontend/` 构建，不混入 Laravel 主应用镜像逻辑。
- GHCR 发布链路需支持与主仓当前镜像发布策略并存，不能破坏现有后端 `docker-publish.yml`。
- 新增的 `admin` 服务需直接引用 GHCR 镜像，不走本地 `build:`。
- `admin` 服务需可独立访问，并保留把 `/assets/admin/` 继续挂到反向代理的能力。

## 非目标
- 本轮不改造 Laravel 主应用 Dockerfile。
- 本轮不重做 `admin-frontend` 的业务代码与视觉界面。
- 本轮不处理 GitHub Secrets 之外的外部部署脚本。

## 技术约束
- `admin-frontend` 仍使用 `Vue 3 + TypeScript + Vite`。
- 本地现有构建输出 `../public/assets/admin` 不能被破坏；容器构建需使用独立输出目录。
- 发布目标为 GHCR，多架构与登录方式尽量沿用现有主仓工作流模式。
- 视觉与前端基线继续遵循 `apple/DESIGN.md`，但本轮主要产出为工程/部署配置。

## 质量要求
- `admin-frontend` 镜像需可直接运行并稳定提供静态资源。
- 工作流命名、镜像命名与标签策略需清晰，不和主应用镜像冲突。
- `compose.yaml` 中新增服务后，配置语义应一眼可读，端口、镜像名和用途明确。
- 最终至少完成一次 `admin-frontend` 构建验证与工作流 YAML 语法级自检。
