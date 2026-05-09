# 任务清单: fix-user-frontend-api-upstream

> **@status:** completed | 2026-05-09 16:49

```yaml
@feature: fix-user-frontend-api-upstream
@created: 2026-05-09
@status: completed
@mode: R2
```

## 进度概览

| 完成 | 失败 | 跳过 | 总数 |
|------|------|------|------|
| 4 | 0 | 0 | 4 |

---

## 任务列表

### LIVE_STATUS

```json
{"status":"completed","completed":4,"failed":0,"pending":0,"total":4,"percent":100,"current":"已归档到 archive/2026-05","updated_at":"2026-05-09 16:49:16","skipped":0,"uncertain":0,"done":4}
```

### 1. 部署模板

- [√] 1.1 修改 `deploy/xboard-server/compose.test.yaml` 的用户前端上游
  - 预期变更: `user` 服务增加 `depends_on: web` 与 `extra_hosts: host.docker.internal:host-gateway`，`XBOARD_BACKEND_UPSTREAM` 默认改为用户前端专用 host-gateway 上游。
  - 完成标准: 用户前端默认不再依赖 `web:7001` 的 Docker 内部 IP。
  - 验证方式: 检查 compose 文件中 `XBOARD_USER_BACKEND_UPSTREAM`、`host.docker.internal` 和 `USER_PORT` 配置存在。
  - depends_on: []

- [√] 1.2 修改 `deploy/xboard-server/compose.test.yaml` 的管理端上游与端口变量
  - 预期变更: `admin` 服务使用独立 `XBOARD_ADMIN_BACKEND_UPSTREAM`，用户端使用 `USER_PORT`，管理端使用 `ADMIN_PORT`。
  - 完成标准: 用户前端默认端口 `7003`、管理前端默认端口 `7002`，二者不共用同一个变量。
  - 验证方式: 检查 compose 文件端口映射和环境变量插值。
  - depends_on: [1.1]

### 2. 文档与环境样例

- [√] 2.1 更新 `deploy/xboard-server/.env.example`
  - 预期变更: 新增 `USER_PORT`、`XBOARD_USER_BACKEND_UPSTREAM`、`XBOARD_ADMIN_BACKEND_UPSTREAM`，保留兼容说明。
  - 完成标准: 新部署复制 `.env.example` 后即可获得稳定默认上游。
  - 验证方式: 检查环境变量样例与 compose 插值一致。
  - depends_on: [1.2]

- [√] 2.2 更新 `deploy/xboard-server/README.md`
  - 预期变更: 说明当前 502 日志含义、临时恢复命令和持久配置方式。
  - 完成标准: 运维人员可根据 README 判断是否为前端上游连接拒绝，并执行正确恢复步骤。
  - 验证方式: 文档审阅，确认不包含明文敏感 token。
  - depends_on: [2.1]

---

## 执行日志

| 时间 | 任务 | 状态 | 备注 |
|------|------|------|------|
| 2026-05-09 16:40 | DESIGN | in_progress | 已确认根因为用户前端 nginx 连接 Docker 内部上游 IP 被拒绝 |
| 2026-05-09 16:47 | 1.1 | completed | 用户前端默认改为 host-gateway 后端上游 |
| 2026-05-09 16:47 | 1.2 | completed | 用户端与管理端端口和上游变量已拆分 |
| 2026-05-09 16:48 | 2.1 | completed | `.env.example` 已补齐新变量 |
| 2026-05-09 16:49 | 2.2 | completed | README 已补齐 API 502 排障与恢复步骤 |
| 2026-05-09 16:50 | 验证 | warning | Docker CLI 不可用，已完成文本约束和 diff 检查 |

---

## 执行备注

> 记录执行过程中的重要说明、决策变更、风险提示等
