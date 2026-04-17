# 变更提案: optimize-docker-publish-workflow

## 元信息
```yaml
类型: 优化
方案类型: implementation
优先级: P1
状态: 已确认
创建: 2026-04-18
```

---

## 1. 需求

### 背景
当前 `.github/workflows/docker-publish.yml` 在每次 push 到 `master` / `new-dev` 时都会执行双架构镜像构建并推送。
现有流程存在几个明显的性能与正确性问题：

- Dockerfile 在构建过程中重新 `git clone` 仓库和子模块，绕过了 Actions 已 checkout 的工作区。
- workflow 通过 `CACHEBUST=${{ github.sha }}` 强制让源码层每次失去缓存，以确保 `git clone` 拉到新代码，但这也让缓存收益极低。
- workflow 中“Get version”在“Extract metadata”之后，`steps.get_version.outputs.version` 的引用顺序不正确。
- `build-push-action` 同时声明了 `push: true` 和 `outputs: type=registry,push=true`，存在冗余。
- QEMU 配置包含 `amd64`，但原生 `ubuntu-latest` 已能直接构建 `amd64`。

### 目标
- 在不改变现有分支触发与双架构产物策略的前提下，缩短 Docker 发布耗时。
- 保持 `master` / `new-dev` 的镜像输出逻辑不变。
- 修正 workflow 中的明显顺序与冗余配置问题。

### 约束条件
```yaml
时间约束: 当前回合内完成方案、实现和基本验证
性能约束: 优先优化热路径，不引入新的外部依赖
兼容性约束: 保持 master/new-dev 双架构发布行为不变
业务约束: 不通过减少镜像产物种类来换速度
```

### 验收标准
- [ ] `docker-publish.yml` 保持 `master` / `new-dev` 触发和双架构推送
- [ ] Dockerfile 改为使用 GitHub Actions 工作区源码，而不是在构建阶段重新 `git clone`
- [ ] workflow 去除明显冗余和错误顺序配置
- [ ] `.dockerignore` 收紧不必要上下文，减少上传到 BuildKit 的内容

---

## 2. 方案

### 技术方案
采用“保留产物策略不变、优化构建输入与缓存路径”的方案：

1. `actions/checkout` 增加 `submodules: recursive`，让 CI 工作区直接具备完整源码与子模块。
2. 修改 Dockerfile，删除构建期 `git clone` / `git submodule update` 逻辑，改为直接 `COPY` 工作区源码。
3. 删除 workflow 中为配合构建期 `git clone` 而存在的 `CACHEBUST` build arg。
4. 修正 metadata/version 步骤顺序，移除无效的“Update version in app.php”和冗余 `outputs` 配置。
5. 收紧 `.dockerignore`，排除 `.git`、`.github`、`.helloagents` 等不会进入运行时镜像的目录。
6. 保留双架构构建，但只在 QEMU 中声明需要模拟的 `arm64`。

### 影响范围
```yaml
涉及模块:
  - .github/workflows/docker-publish.yml: 发布编排与构建参数
  - Dockerfile: 镜像构建输入与缓存路径
  - .dockerignore: 构建上下文裁剪
预计变更文件: 3
```

### 风险评估
| 风险 | 等级 | 应对 |
|------|------|------|
| 直接使用工作区源码后，若 checkout 未带子模块，构建会缺少 admin dist | 中 | 在 checkout 中显式启用 `submodules: recursive` |
| `.dockerignore` 排除过多文件导致运行时缺资源 | 中 | 只排除明显不应进入镜像的 Git/CI/本地知识库目录 |
| 删除构建期 `git clone` 后，版本号注入逻辑与现有预期不一致 | 低 | 移除当前本就不会进镜像的版本更新步骤，避免继续保留伪生效逻辑 |

---

## 3. 技术设计（可选）

> 本次为 CI 与构建链优化，不涉及业务 API 或数据模型设计。

### 架构设计
N/A

### API设计
N/A

### 数据模型
N/A

---

## 4. 核心场景

### 场景: push 到发布分支时执行镜像发布
**模块**: GitHub Actions / Docker Buildx
**条件**: 开发者 push 到 `master` 或 `new-dev`
**行为**: checkout 完整源码与子模块，直接用工作区作为 Docker 构建输入，利用 GHA 缓存构建并推送双架构镜像
**结果**: 在不减少镜像产物的前提下减少无谓的源码重拉取、上下文与步骤浪费

---

## 5. 技术决策

### optimize-docker-publish-workflow#D001: 改为基于工作区源码构建镜像
**日期**: 2026-04-18
**状态**: ✅采纳
**背景**: 当前 Dockerfile 构建期重新 `git clone`，导致必须用 `CACHEBUST` 强制刷新源码层，缓存收益差且 workflow 内对工作区做的修改无法进入镜像。
**选项分析**:
| 选项 | 优点 | 缺点 |
|------|------|------|
| A: 保持构建期 `git clone`，仅修修 workflow 小问题 | 改动小 | 无法根治缓存失效与无效步骤问题 |
| B: 直接基于 Actions 工作区 `COPY` 构建 | 能复用 checkout 结果，减少源码重复获取，缓存路径更合理 | 需要同步处理子模块与构建上下文 |
**决策**: 选择方案 B
**理由**: 这是在不改变发布产物策略的前提下，收益最大且风险可控的提速路径。
**影响**: workflow checkout 配置、Dockerfile 源码输入方式、`.dockerignore` 内容

---

## 6. 成果设计

### 设计方向
- N/A

### 视觉要素
- N/A

### 技术约束
- **可访问性**: N/A
- **响应式**: N/A
