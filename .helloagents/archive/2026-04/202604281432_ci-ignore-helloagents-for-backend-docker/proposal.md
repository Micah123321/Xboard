# 变更提案: ci-ignore-helloagents-for-backend-docker

## 元信息
```yaml
类型: 优化
方案类型: implementation
优先级: P2
状态: 已规划
创建: 2026-04-28
```

---

## 1. 需求

### 背景
上一个 commit 只改动了 `admin-frontend/src/views/nodes/NodesView.vue` 和 `.helloagents/CHANGELOG.md`。当前后端 Docker 发布工作流 `.github/workflows/docker-publish.yml` 使用 `paths-ignore`，只忽略了 `admin-frontend/**` 和前端发布 workflow，本次 `.helloagents/CHANGELOG.md` 变更仍会触发后端 Docker 构建。

### 目标
只修当前误触发问题：让 `.helloagents/**` 知识库和方案记录变更不触发 `Backend Docker Build and Publish`，同时保留后端相关文件变更触发后端镜像构建的现有行为。

### 约束条件
```yaml
时间约束: 无
性能约束: 不增加 CI 运行成本
兼容性约束: 保持现有 master/new-dev 分支触发策略和 workflow_dispatch 手动触发能力
业务约束: 不改变前端 Docker 发布 workflow，不扩大到完整后端 paths 白名单重构
```

### 验收标准
- [ ] `.github/workflows/docker-publish.yml` 的 `paths-ignore` 包含 `.helloagents/**`
- [ ] 仅 `.helloagents/**` 与 `admin-frontend/**` 变更时不会触发后端 Docker 发布工作流
- [ ] 后端代码、后端 Dockerfile 或后端依赖文件变更仍会触发后端 Docker 发布工作流
- [ ] workflow YAML 语法可解析，变更 diff 范围可人工核对

---

## 2. 方案

### 技术方案
在 `.github/workflows/docker-publish.yml` 的 `on.push.paths-ignore` 中追加 `.helloagents/**`。该路径仅排除 HelloAGENTS 知识库、方案包、归档和变更记录等本地协作元数据，不影响后端源码、部署模板、Dockerfile、Composer 依赖或 GitHub Actions workflow 自身的正常触发。

### 影响范围
```yaml
涉及模块:
  - ci: 后端 Docker 发布工作流路径过滤规则
  - knowledge-base: 记录 CI 触发规则的项目知识
预计变更文件: 2-3
```

### 风险评估
| 风险 | 等级 | 应对 |
|------|------|------|
| `.helloagents/**` 下未来放入真正影响镜像构建的文件时不会触发后端构建 | 低 | `.helloagents/**` 按项目约定只存储知识库和方案记录，不作为运行时构建输入 |
| `paths-ignore` 语义被误解为所有路径都忽略才跳过 workflow | 中 | 验证 GitHub Actions 规则：当 push 中所有变更路径都匹配 ignore 时才跳过；混有后端文件时仍触发 |

### 方案取舍
```yaml
唯一方案理由: 本次误触发来自 `.helloagents/CHANGELOG.md`，追加 `.helloagents/**` 是最小、可回滚、影响面最小的修复。
放弃的替代路径:
  - 改成后端正向 paths 白名单: 可更严格控制触发，但需要完整梳理后端构建输入，当前需求不需要承担该范围和漏触发风险。
  - 扩大忽略文档/元数据文件: 可减少更多 CI 噪音，但会引入额外路径判断，超过本次已确认范围。
回滚边界: 删除 `.github/workflows/docker-publish.yml` 中新增的 `.helloagents/**` ignore 项即可恢复原行为。
```

---

## 3. 技术设计

N/A。本次不改变架构、API 或数据模型。

---

## 4. 核心场景

### 场景: 管理端变更附带知识库记录
**模块**: ci
**条件**: push 仅包含 `admin-frontend/**` 与 `.helloagents/**` 文件变更
**行为**: GitHub Actions 根据后端 workflow 的 `paths-ignore` 判断后端发布工作流无需运行
**结果**: 只运行管理端前端 Docker 发布工作流，后端 Docker 发布工作流被跳过

### 场景: 后端变更附带知识库记录
**模块**: ci
**条件**: push 包含后端源码或构建输入文件，同时包含 `.helloagents/**` 文件变更
**行为**: GitHub Actions 发现存在未被 `paths-ignore` 覆盖的后端相关路径
**结果**: 后端 Docker 发布工作流正常运行

---

## 5. 技术决策

### ci-ignore-helloagents-for-backend-docker#D001: 后端 Docker workflow 忽略 HelloAGENTS 知识库路径
**日期**: 2026-04-28
**状态**: 采纳
**背景**: HelloAGENTS 知识库更新是开发协作记录，不参与后端镜像构建，却会因为 `paths-ignore` 未覆盖而误触发后端 Docker 发布。
**选项分析**:
| 选项 | 优点 | 缺点 |
|------|------|------|
| A: 在 `paths-ignore` 中追加 `.helloagents/**` | 最小改动，直接修复当前误触发，可快速验证和回滚 | 仍依赖 `paths-ignore` 维护排除列表 |
| B: 改为后端 `paths` 白名单 | 触发范围更严格 | 需要完整列出所有后端构建输入，漏列会导致后端镜像不发布 |
| C: 只在 commit 时不提交知识库记录 | 避免触发 CI | 破坏项目知识库同步要求，且不能解决未来同类元数据变更 |
**决策**: 选择方案 A
**理由**: 当前问题来源明确，方案 A 影响范围最小并符合用户选择的“只修当前问题”。
**影响**: 后端 Docker 发布工作流不会再因 `.helloagents/**` 单独变更而运行。

---

## 6. 验证策略

```yaml
verifyMode: review-first
reviewerFocus:
  - .github/workflows/docker-publish.yml 的 paths-ignore 缩进和路径匹配语义
  - 是否仅新增 `.helloagents/**`，避免误改前端 workflow 或构建步骤
testerFocus:
  - 解析 workflow YAML
  - 核对上一 commit 路径集合在新规则下是否全部被 ignore 覆盖
uiValidation: none
riskBoundary:
  - 不执行 Docker build/push
  - 不改 GitHub Actions secrets、registry、镜像 tag 或分支策略
```

---

## 7. 成果设计

N/A。本次不涉及视觉产出。
