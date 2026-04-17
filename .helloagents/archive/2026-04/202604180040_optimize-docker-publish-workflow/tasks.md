# 任务清单: optimize-docker-publish-workflow

> **@status:** completed | 2026-04-18 00:44

```yaml
@feature: optimize-docker-publish-workflow
@created: 2026-04-18
@status: completed
@mode: R2
```

## 进度概览

| 完成 | 失败 | 跳过 | 总数 |
|------|------|------|------|
| 4 | 0 | 0 | 4 |

---

## 任务列表

### 1. 方案与上下文

- [√] 1.1 确认当前 workflow 和 Dockerfile 的主要耗时点与失效缓存路径 | depends_on: []

### 2. 工作流与构建链优化

- [√] 2.1 优化 `.github/workflows/docker-publish.yml` 的步骤顺序、checkout 配置和冗余项 | depends_on: [1.1]
- [√] 2.2 优化 `Dockerfile` 以直接消费 CI 工作区源码并删除构建期重复拉仓逻辑 | depends_on: [2.1]
- [√] 2.3 收紧 `.dockerignore`，减少不必要构建上下文 | depends_on: [2.2]

---

## 执行日志

| 时间 | 任务 | 状态 | 备注 |
|------|------|------|------|
| 2026-04-18 00:40:00 | 方案包创建 | completed | 已创建 `202604180040_optimize-docker-publish-workflow` |
| 2026-04-18 00:42:00 | 1.1 | completed | 已确认主要瓶颈是构建期重复 `git clone`、`CACHEBUST` 强制失效缓存与 workflow 冗余步骤 |
| 2026-04-18 00:44:00 | 2.1 | completed | 已优化 checkout、QEMU、metadata/version 顺序、缓存 scope 与冗余输出配置 |
| 2026-04-18 00:45:00 | 2.2 | completed | 已移除 Dockerfile 中构建期 `git clone`/`git submodule update`，改为直接 `COPY` 工作区源码 |
| 2026-04-18 00:45:30 | 2.3 | completed | 已在 `.dockerignore` 中排除 `.git`、`.github`、`.helloagents` 上下文 |

---

## 执行备注

> 记录执行过程中的重要说明、决策变更、风险提示等

- 目标是“低风险提速”，不调整双架构产物策略
- 当前仓库不存在 `composer.lock`，本次不围绕 lockfile 进行优化
- 未执行真实多架构镜像构建，仅完成文本级自检与配置核对
