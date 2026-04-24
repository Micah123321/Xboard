# admin-frontend GHCR 自动构建与 compose 接入 — 任务分解

## 任务列表
- [x] 任务1：补齐本轮方案与合同产物（涉及文件：`.helloagents/plans/202604242250_admin-frontend-ghcr-compose/*`；完成标准：存在需求、方案、任务、合同与状态文件；验证方式：文件检查）
- [x] 任务2：实现 `admin-frontend` 独立 Docker 构建链路（涉及文件：`admin-frontend/Dockerfile`、`admin-frontend/Caddyfile`、`admin-frontend/.dockerignore`、`admin-frontend/vite.config.ts`；完成标准：可输出独立静态镜像；验证方式：`npm run build` + `ADMIN_BUILD_OUT_DIR=dist npm run build`，本地 `docker build` 因环境缺少 docker CLI 未执行）
- [x] 任务3：新增前端 GHCR 发布 workflow（涉及文件：`.github/workflows/admin-frontend-docker-publish.yml`；完成标准：push 后可自动构建并推送前端镜像；验证方式：YAML 解析 + 工作流自检）
- [x] 任务4：在 compose 分支接入独立 `admin` 服务并完成知识库同步（涉及文件：`compose.yaml`（compose 分支 worktree）、`.helloagents/CHANGELOG.md`、`.helloagents/context.md`、`.helloagents/modules/admin-frontend.md`、`.helloagents/sessions/master/default/STATE.md`；完成标准：`compose` 分支文件完成接入且知识库已更新；验证方式：YAML 解析 + 文件检查）

## 进度
- [x] 已确认采用独立 `admin` 服务，并在 compose 分支暴露单独端口。
- [x] 已完成方案包、前端镜像构建链路与 GHCR workflow 编排。
- [x] 已完成 compose 分支 `compose.yaml` 接入与 YAML 语法校验。
- [x] 已完成 `npm run build` 与 `ADMIN_BUILD_OUT_DIR=dist npm run build` 验证；本地 `docker build` 因环境缺少 `docker` 命令未执行。
