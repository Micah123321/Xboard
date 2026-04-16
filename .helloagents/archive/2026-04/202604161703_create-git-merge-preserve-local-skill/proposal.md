# 变更提案: create-git-merge-preserve-local-skill

## 元信息
```yaml
类型: 新功能
方案类型: implementation
优先级: P1
状态: 已确认
创建: 2026-04-16
```

---

## 1. 需求

### 背景
本次已在 `Xboard-new` 仓库中完成一次“以 `upstream/master` 为基线、保留本地改动优先”的真实合并。
这类需求在多仓协作中出现频率高，且容易踩到 dirty worktree、`modify/delete` 冲突、子模块 gitlink
冲突、错误远端基线等问题，适合沉淀成一个可复用 skill。

### 目标
- 在全局技能目录创建一个可自动发现的 skill。
- 让 skill 能指导 Codex 安全地把远端最新代码合并到当前分支，同时尽量保住本地改动并在冲突时偏向本地实现。
- 覆盖常见高风险场景，包括未提交改动、`modify/delete` 冲突、子模块冲突和合并后核验。

### 约束条件
```yaml
时间约束: 当前回合内完成创建、校验和可用性说明
性能约束: N/A
兼容性约束: skill 需放在 C:/Users/xiaohuli/.codex/skills 下以便全局自动发现
业务约束: 说明必须体现“保本地优先”语义，避免把 merge 写成 rebase/reset/强推流程
```

### 验收标准
- [ ] `C:/Users/xiaohuli/.codex/skills/git-merge-preserve-local/` 下存在完整 skill 目录
- [ ] `SKILL.md` 明确描述触发场景、工作流和关键风险点
- [ ] 至少有一份参考文档沉淀具体命令模板与冲突处理要点
- [ ] skill 通过 `quick_validate.py` 校验

---

## 2. 方案

### 技术方案
创建一个轻量级 workflow skill：

1. 用 `init_skill.py` 在全局技能目录初始化 `git-merge-preserve-local`。
2. 将核心触发条件和执行骨架写入 `SKILL.md`，保持内容精炼。
3. 将具体命令模板、冲突配方和核验清单下沉到 `references/merge-playbook.md`。
4. 用 `generate_openai_yaml.py` 生成 `agents/openai.yaml`，补齐 UI 元数据。
5. 运行 `quick_validate.py` 做结构校验。

### 影响范围
```yaml
涉及模块:
  - 全局 Codex skills: 新增 git-merge-preserve-local skill
  - 当前仓库 .helloagents: 新增本次方案包与执行记录
预计变更文件: 4-6
```

### 风险评估
| 风险 | 等级 | 应对 |
|------|------|------|
| 说明过长、触发词不清，导致 skill 不易命中或上下文成本过高 | 中 | 将流程骨架留在 `SKILL.md`，把命令细节下沉到 references |
| 将“保本地优先”误写成 `reset`、`rebase` 或 `push --force` | 高 | 明确写出推荐 merge 路径与禁用路径 |
| 忽略子模块 gitlink 冲突，导致 skill 在真实仓库里误导操作 | 高 | 单独增加子模块冲突章节和验证清单 |

---

## 3. 技术设计（可选）

> 本次为全局 skill 创建，不涉及业务系统架构/API/数据模型设计。

### 架构设计
N/A

### API设计
N/A

### 数据模型
N/A

---

## 4. 核心场景

> 该 skill 面向 Git 合并流程复用，不同步项目业务模块文档。

### 场景: 将上游最新代码合并到当前分支且保留本地优先
**模块**: 全局技能 / Git 工作流
**条件**: 用户要求同步远端最新代码，但不希望丢失本地改动，且冲突时偏向本地实现
**行为**: 先识别真实上游基线、保护现场，再执行 merge、处理冲突并核验
**结果**: 能在真实仓库中复用一套较稳健的“保本地优先合并”操作流程

---

## 5. 技术决策

> 本方案涉及的技术决策，归档后成为决策的唯一完整记录

### create-git-merge-preserve-local-skill#D001: 采用“SKILL.md + references”而非附带脚本自动执行 merge
**日期**: 2026-04-16
**状态**: ✅采纳
**背景**: 这类 Git 合并任务高度依赖现场状态，直接自动执行脚本容易因远端、分支、dirty worktree、子模块结构差异而误伤仓库。
**选项分析**:
| 选项 | 优点 | 缺点 |
|------|------|------|
| A: `SKILL.md + references` | 复用性高，允许结合现场状态判断，适合不同仓库差异 | 不是一键脚本，需要代理自己执行命令 |
| B: 直接附自动 merge 脚本 | 执行更快 | 风险高，容易在错误远端/错误分支/脏工作树下误操作 |
**决策**: 选择方案 A
**理由**: 该问题的关键不是缺少命令，而是缺少“什么时候用什么命令”的判断框架；skill 更适合作为操作协议而不是盲目自动化脚本。
**影响**: skill 主要由说明文档构成，强调流程判断、冲突配方和验证步骤

---

## 6. 成果设计

> 含视觉产出的任务由 DESIGN Phase2 填充。非视觉任务整节标注"N/A"。

### 设计方向
- N/A

### 视觉要素
- N/A

### 技术约束
- **可访问性**: N/A
- **响应式**: N/A
