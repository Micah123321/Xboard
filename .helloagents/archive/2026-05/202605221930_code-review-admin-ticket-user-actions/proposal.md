# 变更提案: code-review-admin-ticket-user-actions

## 元信息
```yaml
类型: 修复
方案类型: implementation
优先级: P1
状态: 执行中
创建: 2026-05-22
selected_plan: conservative
```

---

## 1. 需求

### 背景
`~review 审查本次改动` 检查到工单工作台新增用户操作后，`分配订单` 从工单弹窗内打开时存在两个可见交互风险：
- `OrderAssignDrawer` 没有 `append-to-body`，在 `TicketWorkspaceDialog` 内嵌打开时容易受父级弹窗层级、遮罩和焦点陷阱影响。
- `ensurePlansLoaded()` 遇到已有套餐加载请求时直接返回，快速连续点击会让订单分配抽屉在套餐数据仍为空时打开。

### 目标
- 保守修复工单工作台内的订单分配弹层层级问题。
- 复用进行中的套餐加载请求，确保打开订单分配抽屉前已有明确加载结果。
- 不改变用户管理页、订单页现有分配订单接口和提交语义。

### 约束条件
```yaml
兼容性约束: 保持 Element Plus 组件用法和现有用户管理页行为
业务约束: 不新增接口，不调整订单分配载荷
风险边界: 不重构工单工作台整体弹层结构
```

### 验收标准
- [ ] 工单工作台触发 `分配订单` 时，若套餐加载正在进行，会等待同一个请求完成后再打开抽屉。
- [ ] `OrderAssignDrawer` 作为全局层级抽屉挂载，避免嵌套在工单弹窗内部。
- [ ] `admin-frontend` 构建通过。

---

## 2. 方案

### 技术方案
保守方案：
- 在 `TicketWorkspaceDialog.vue` 中缓存套餐加载 Promise，并让后续调用等待同一个 Promise。
- 在 `OrderAssignDrawer.vue` 的 `ElDrawer` 上补齐 `append-to-body`，与用户编辑抽屉、订单详情抽屉的挂载方式保持一致。

激进方案：
- 把工单工作台内所有用户操作弹层统一抽成独立 overlay coordinator，并统一管理加载状态、层级和关闭事件。

### 影响范围
```yaml
涉及模块:
  - admin-frontend: 工单工作台用户操作与订阅订单分配抽屉
预计变更文件: 2 个源码文件 + 知识库记录
```

### 风险评估
| 风险 | 等级 | 应对 |
|------|------|------|
| 全局挂载改变订单分配抽屉 DOM 位置 | 低 | 复用项目内其他抽屉同类实践，保持组件 API 不变 |
| 等待已有请求可能延后抽屉打开 | 低 | 这是预期行为，避免空套餐列表提前展示 |

### 方案取舍
```yaml
conservative_plan:
  - 只修复已确认的层级和并发加载问题
  - 不改业务接口和弹层组合方式
aggressive_plan:
  - 统一重构弹层协调层
  - 可提升长期一致性，但超出本次审查修复范围
selected_plan: conservative
方案取舍: 本次 review 只执行保守方案；激进方案作为后续重构备选，不进入默认任务列表。
回滚边界: 可单独回退 TicketWorkspaceDialog.vue 的 Promise 缓存和 OrderAssignDrawer.vue 的 append-to-body 属性。
```

---

## 3. 技术设计

N/A。本次不新增 API、数据模型或跨模块架构。

---

## 4. 核心场景

### 场景: 工单工作台分配订单
**模块**: admin-frontend  
**条件**: 管理员在工单工作台查看带用户信息的工单  
**行为**: 打开用户操作菜单并选择“分配订单”  
**结果**: 系统先获取或复用套餐列表请求，请求结束后打开挂载到 body 的订单分配抽屉。

---

## 5. 技术决策

### code-review-admin-ticket-user-actions#D001: 分配订单抽屉使用 body 挂载并等待套餐加载
**日期**: 2026-05-22  
**状态**: ✅采纳  
**背景**: 工单工作台本身是 `ElDialog`，再嵌套订单分配 `ElDrawer` 会增加遮罩、z-index 和焦点管理风险；同时套餐加载中重复触发会提前打开空数据抽屉。  
**选项分析**:
| 选项 | 优点 | 缺点 |
|------|------|------|
| A: 保守修复层级和请求复用 | 影响小，贴合现有组件实践，可快速验证 | 不统一治理所有弹层 |
| B: 重构弹层协调层 | 长期一致性更好 | 变更范围大，超出审查修复 |
**决策**: 选择方案 A  
**理由**: 当前风险集中在新接入的分配订单路径，保守修复能直接消除用户可感知问题。  
**影响**: `OrderAssignDrawer` 在所有使用处改为 body 挂载；工单工作台等待套餐加载结果后再打开分配订单抽屉。

---

## 6. 验证策略

```yaml
verifyMode: review-first
reviewerFocus:
  - TicketWorkspaceDialog.vue 中套餐加载并发控制
  - OrderAssignDrawer.vue 层级挂载属性
testerFocus:
  - npm run build
uiValidation: optional
riskBoundary:
  - 不修改订单分配接口
  - 不修改用户管理页筛选和工单返回 query 语义
```

---

## 7. 成果设计

N/A。本次只修复弹层层级和加载时序，不调整视觉风格。
