# 变更提案: ticket-closed-reply-reopen

## 元信息
```yaml
类型: 缺陷修复
方案类型: implementation
优先级: P1
状态: 进行中
创建: 2026-04-25
```

---

## 1. 需求

### 背景
用户要求在 `admin-frontend` 的工单工作台中允许“已关闭工单再次回复”，并明确选择“前后台统一”方案：无论管理员还是用户，只要对已关闭工单再次回复，就自动把该工单重新开启。当前代码中，`V1/User/TicketController::reply()` 会直接拒绝 closed ticket；`TicketService::reply()` 也没有把工单状态改回开启；同时管理端 `TicketWorkspaceDialog.vue` 还会在 closed 状态禁用发送按钮。

### 目标
- 打通用户端与管理端的统一规则：已关闭工单再次回复后自动重开。
- 保持现有 `/ticket/reply` 与 `/ticket/close` 接口不变，只修正回复链路的业务语义。
- 管理端 `#/tickets` 保持现有 Apple 风格后台工作台，只做必要交互修复，不引入新的视觉系统分叉。

### 约束条件
```yaml
范围约束: 仅修复工单再次回复与自动重开链路，不扩展其他客服、通知或工单字段
技术约束: Laravel 后端继续复用现有 TicketService；管理端继续使用 Vue3 + TypeScript + Element Plus
兼容约束: 用户端主题源码不在仓内，仅有编译产物 bundle，因此优先通过后端语义修复保证用户侧链路可用
业务约束: 仍需保留 ticket_must_wait_reply 限制，避免同一角色连续刷消息破坏既有等待规则
视觉约束: 管理端交互保持 .helloagents/DESIGN.md 的 Apple 风格后台，不做大改版
```

### 验收标准
- [ ] 用户侧对已关闭工单调用回复接口时不再收到 “The ticket is closed and cannot be replied”，且回复后工单状态自动回到开启。
- [ ] 管理端 `#/tickets` 中已关闭工单仍可进入工作台发送回复，回复成功后状态刷新为开启中的工单。
- [ ] 回复后 `reply_status` 与 `last_reply_user_id` 仍保持当前系统既有语义，`ticket_must_wait_reply` 规则不回归。
- [ ] 至少补齐 1 个针对工单自动重开语义的自动化测试。
- [ ] `admin-frontend` 构建验证通过；后端目标测试通过。

---

## 2. 方案

### 技术方案
1. 以 `TicketService::reply()` 为单一真相源补齐“回复即自动重开”的状态回写，确保用户端、管理端、Telegram 插件管理员回复等所有复用该服务的链路统一生效。
2. 移除 `V1/User/TicketController::reply()` 中“closed ticket 直接拒绝”的硬拦截，让用户端能走到统一服务逻辑；保留参数校验和 `ticket_must_wait_reply` 限制。
3. 调整管理端 `TicketWorkspaceDialog.vue` 的关闭态发送限制：closed ticket 允许继续输入并发送；关闭按钮仍只在开启态显示。
4. 基于当前仓内可维护代码补齐自动化测试，重点覆盖“回复 closed ticket 会自动 reopen”的核心业务语义；如果用户主题 bundle 不存在额外前端禁用，则不对 minified 用户端产物做无谓 patch。

### 影响范围
- 后端：`app/Services/TicketService.php`、`app/Http/Controllers/V1/User/TicketController.php`
- 管理端：`admin-frontend/src/views/tickets/TicketWorkspaceDialog.vue`
- 测试：`tests/Unit` 或 `tests/Feature`
- 文档：`.helloagents/context.md`、`.helloagents/modules/admin-frontend.md`、`.helloagents/CHANGELOG.md`

### 风险评估
| 风险 | 等级 | 应对 |
|------|------|------|
| 统一在服务层自动 reopen 可能影响管理员插件回复链路 | 中 | 只在“reply 成功后”把 status 设回开启，保持 reply_status/last_reply_user_id 语义不变，并补测试验证 |
| 用户主题只有编译 bundle，若前端本身禁用 closed reply，后端修复仍不足 | 中 | 已先排查 bundle，当前回复页未发现 closed 态本地禁用；若实施后验证发现仍受限，再最小化补丁 bundle |
| 管理端放开发送后，状态徽章与列表刷新可能不同步 | 低 | 回复成功后继续复用现有 `refreshWorkspace()` 与 `updated` 刷新链路 |

---

## 3. 技术决策

### ticket-closed-reply-reopen#D001: 自动重开规则下沉到 TicketService::reply()
**日期**: 2026-04-25  
**状态**: ✅采纳  
**背景**: 用户、管理员和插件管理员回复都复用工单服务，但当前 reopen 语义分散且缺失。  
**决策**: 在 `TicketService::reply()` 内统一把成功回复后的工单状态改为 `STATUS_OPENING`。  
**理由**: 这是所有回复链路共享的唯一稳定汇合点，能避免只修某个 controller 导致语义不一致。

### ticket-closed-reply-reopen#D002: 用户端优先通过后端语义修复打通，不直接改 minified bundle
**日期**: 2026-04-25  
**状态**: ✅采纳  
**背景**: 仓内不存在用户主题源代码，只保留 `theme/Xboard/assets/umi.js` 编译产物。  
**决策**: 先确认用户端详情页没有本地禁用 closed reply，再仅通过后端修复放开用户端；只有验证发现前端仍阻塞时才补 bundle。  
**理由**: 降低对 minified 产物的高风险修改，优先用可维护的后端真相源完成统一业务规则。

### ticket-closed-reply-reopen#D003: 管理端仅修复交互门禁，不改变现有页面结构
**日期**: 2026-04-25  
**状态**: ✅采纳  
**背景**: 当前工单工作台已经符合项目既定 Apple 风格后台基线，本轮是行为修复不是页面重做。  
**决策**: 放开发送按钮的 closed 态禁用，并继续保留现有 hero / 工作台 / 对话区布局。  
**理由**: 最小化视觉影响，避免为了一个业务修复引入额外 UI 回归。
