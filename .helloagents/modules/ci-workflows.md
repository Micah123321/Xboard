# ci-workflows

## 职责

- 维护 GitHub Actions 镜像发布工作流的触发边界
- 区分 Laravel 后端镜像与 `admin-frontend` 独立静态镜像的构建发布链路
- 记录不参与镜像构建的协作元数据路径，避免无意义触发 Docker 发布任务

## 行为规范

- 后端镜像发布工作流位于 `.github/workflows/docker-publish.yml`，名称为 `Backend Docker Build and Publish`
- 后端 workflow 在 `master` 和 `new-dev` 分支 push 时触发，并保留 `workflow_dispatch` 手动触发入口
- 后端 workflow 使用 `paths-ignore` 排除 `admin-frontend/**`、`.helloagents/**` 和 `.github/workflows/admin-frontend-docker-publish.yml`
- GitHub Actions 的 `paths-ignore` 语义是：push 中所有变更路径都被 ignore 覆盖时跳过 workflow；只要混有未被 ignore 的后端相关路径，后端 workflow 仍会运行
- 管理端前端镜像发布工作流位于 `.github/workflows/admin-frontend-docker-publish.yml`，只关注 `admin-frontend/**` 和自身 workflow 变更
- 管理端前端镜像发布默认只构建 `linux/amd64`，不启用 QEMU/ARM64 跨架构构建，以压缩 push 后的前端镜像发布时间
- 管理端前端镜像发布使用 GitHub Actions Cache 作为 BuildKit 缓存来源，缓存导出采用 `mode=min`，避免每次发布完整导出多阶段构建缓存拖慢总耗时

## 依赖关系

- 依赖 GitHub Actions 的 `paths-ignore` 过滤行为
- 依赖 GHCR 作为镜像发布目标
- 依赖 `.helloagents/**` 仅作为知识库、方案包、归档和协作记录，不作为后端镜像构建输入
