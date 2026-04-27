# 恢复快照

## 主线目标
补充 HelloAGENTS 通用避坑指南中的“多个任务并行执行”处理规则。

## 正在做什么
当前任务已完成，通用文档已补充“多任务并行执行规则”。

## 关键上下文
- 已有项目版文档：`docs/helloagents-workflow-pitfalls.md`。
- 通用版目标路径：`docs/helloagents-universal-pitfalls.md`。
- 通用版面向任意已激活 HelloAGENTS 项目，重点覆盖状态恢复、输出格式、目录边界、验证证据、子仓/子模块、多平台 Shell、外部依赖和收尾流程。
- 文档不放入 `.helloagents/`，避免触发模板格式约束。
- 本次新增重点：并行任务必须先建任务矩阵；主代理处理关键路径，子代理仅在用户明确授权并行 / 委托时使用；并行写入必须分配不重叠文件范围；状态文件记录并行泳道和合并点。
- 已在 `docs/helloagents-universal-pitfalls.md` 新增 `## 多任务并行执行规则` 章节。
- 已检查章节存在性与 `git diff --check`，无空白错误；仅提示 Git 未来可能将 `STATE.md` LF 替换为 CRLF。

## 下一步
当前补充已完成；后续可把该章节抽成团队并行任务模板。

## 阻塞项
- 暂无

## 方案
快速文档沉淀（无独立方案包）。

## 已标记技能
hello-ui, hello-verify
