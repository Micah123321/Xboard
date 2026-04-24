# 任务清单: admin-frontend-node-status-filter-batch-delete

> **@status:** completed | 2026-04-25 00:15

```yaml
@feature: admin-frontend-node-status-filter-batch-delete
@created: 2026-04-25
@status: completed
@mode: R2
```

## 进度概览

| 完成 | 失败 | 跳过 | 总数 |
|------|------|------|------|
| 4 | 0 | 0 | 4 |

---

## 任务列表

- [√] 1. 梳理节点状态筛选口径、批量删除接口与当前节点页交互边界
- [√] 2. 扩展节点工具层与管理端 API，补齐状态筛选和批量删除封装
- [√] 3. 调整节点工作台筛选区、选择摘要区与删除确认流程，完成在线/离线筛选与批量删除
- [√] 4. 执行 `admin-frontend` 构建验证，并同步 `.helloagents` 记录

---

## 执行日志

| 时间 | 任务 | 状态 | 备注 |
|------|------|------|------|
| 2026-04-25 00:15 | 方案设计 | completed | 用户确认“离线节点”仅筛显式离线状态，不包含待同步 / 已停用 |
| 2026-04-25 00:15 | 工具层与 API | completed | 已补齐 `statusFilter` 筛选口径与 `batchDeleteNodes()` 封装 |
| 2026-04-25 00:15 | 页面实现 | completed | 节点页已新增状态筛选、批量删除按钮、确认提示与选择摘要文案 |
| 2026-04-25 00:15 | 构建验证与知识库同步 | completed | `admin-frontend` 执行 `npm run build` 通过，并同步 context/modules/CHANGELOG |

---

## 执行备注

- 本轮只增强 `#/nodes` 现有工作台，不改动节点新增 / 编辑协议表单与其他节点子页面。
- 批量删除属于危险操作，必须保留明确确认文案并在成功后清空跨分页勾选状态。
