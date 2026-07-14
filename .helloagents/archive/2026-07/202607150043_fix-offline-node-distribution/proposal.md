# 变更提案: fix-offline-node-distribution

## 元信息
```yaml
类型: 修复
方案类型: implementation
优先级: P1
状态: 已确认
创建: 2026-07-15
复杂度: moderate
```

---

## 1. 需求

### 背景
管理后台可以判断节点已经离线，但用户重新接收节点配置后，离线节点仍出现在节点分发结果和客户端“自动选择”候选中。代码核查确认，用户节点 API、订阅导出和 App 配置共用 `ServerService::getAvailableServers()`，该方法只过滤权限组、显隐和流量额度，没有使用已经计算出的 `available_status`。

### 目标
- 所有 `available_status=STATUS_OFFLINE` 的节点不得进入用户节点列表、订阅分发和“自动选择”候选。
- `STATUS_ONLINE_NO_PUSH` 与 `STATUS_ONLINE` 节点继续正常分发。
- 节点恢复有效心跳后，在下一次请求中自动重新进入分发结果。
- 节点成员变化必须使用户节点 API 的 ETag 正确失效，避免旧的空列表或在线列表返回错误的 304。

### 约束条件
```yaml
性能约束: 运行状态来自缓存访问器，过滤在已加载的节点集合上完成，不新增数据库查询循环
兼容性约束: 保留父节点运行缓存回退语义；保留两种在线状态；不改变管理端 show/auto_online 行为
业务约束: 只改变面向用户的节点分发准入，不隐藏管理后台中的离线节点
安全约束: 只运行本地测试，不连接或修改生产环境
```

### 验收标准
- [√] 同组且 `show=true` 的离线节点不出现在 `getAvailableServers()` 结果中。
- [√] `STATUS_ONLINE_NO_PUSH` 与 `STATUS_ONLINE` 节点仍出现在分发结果中。
- [√] 节点从在线变为离线后被排除，恢复心跳后自动重新加入。
- [√] 用户节点 API 在成员变化后不错误返回 304，并能返回恢复后的节点。
- [√] 相关节点状态回归测试全部通过。

---

## 2. 方案

### 技术方案
在 `ServerService::getAvailableServers()` 完成节点查询和运行态追加后、动态端口与用户密码转换前，对集合按 `available_status !== Server::STATUS_OFFLINE` 过滤并调用 `values()` 重建连续索引。三个用户侧入口继续复用同一个服务，不在控制器中重复规则。

新增服务与 HTTP 集成回归测试，覆盖离线排除、两种在线状态保留、在线到离线再恢复的状态转换，以及 ETag 随节点成员变化正确失效。

`client.subscribe.servers` 插件钩子和显式传入 `ClientController::doSubscribe()` 的节点集合属于受信扩展边界，可有意替换标准服务结果；核心服务不在插件钩子后重复过滤自定义节点。

### 影响范围
```yaml
涉及模块:
  - node-distribution: 用户节点 API、订阅导出和 App 自动选择共享的节点准入规则
  - node-auto-online: 复用 available_status 与父节点运行缓存回退，不修改其状态计算
  - tests: 增加分发状态矩阵和 ETag 回归覆盖
预计变更文件: 4-6
```

### 风险评估
| 风险 | 等级 | 应对 |
|------|------|------|
| 将 `STATUS_ONLINE_NO_PUSH` 误判为离线 | 中 | 明确仅排除 `STATUS_OFFLINE`，用测试固定状态 1 与状态 2 都保留 |
| 过滤后数组索引不连续影响协议遍历 | 低 | 在集合过滤后调用 `values()` |
| 父入口代报的转发子节点被错误排除 | 中 | 使用现有 `available_status`，不改用 `ownAvailableStatus()`，保留父缓存回退契约 |
| 客户端持有旧 ETag 看不到恢复节点 | 中 | HTTP 集成测试验证离线与恢复时成员变化返回 200，稳定状态才返回 304 |
| 本地测试数据库配置不隔离 | 中 | 先核对测试环境，使用明确的 SQLite 测试数据库运行 `RefreshDatabase` |

### 方案取舍
```yaml
唯一方案理由: 三个分发入口共用 ServerService::getAvailableServers()，在共享服务准入点过滤能一次修复全部标准调用方，并复用模型现有运行态真相源
放弃的替代路径:
  - 分别修改三个控制器: 重复准入逻辑，容易遗漏新调用方且无法保证一致性
  - 将离线状态写入 show: 会混淆管理显隐与运行状态，并改变手动节点和自动上线既有语义
  - 只保留 STATUS_ONLINE: 会错误排除有有效心跳但尚无推送的可用节点
  - 将运行态条件下推 SQL: available_status 来源于缓存访问器，数据库查询无法正确表达
回滚边界: 服务层集合过滤、对应测试和知识库记录可以作为一个独立提交整体回退，不涉及数据迁移
```

---

## 3. 技术设计

### 数据流
```text
v2_server 查询
  -> append available_status/cache_key
  -> 排除 STATUS_OFFLINE 并重建索引
  -> 动态端口、用户密码、倍率转换
  -> User 节点 API / Client 订阅 / App 自动选择
```

不新增或修改 API 字段，不变更数据库结构。

---

## 4. 核心场景

### 场景: 离线节点退出分发并在恢复后重入
**模块**: node-distribution
**条件**: 节点属于用户权限组且 `show=true`
**行为**: 每次分发请求根据当前 `available_status` 过滤节点
**结果**: 状态 0 被排除；状态 1/2 被保留；心跳恢复后节点重新加入，ETag 与当前成员集合一致

---

## 5. 验证策略

```yaml
verifyMode: test-first
reviewerFocus:
  - 共享服务过滤位置是否覆盖全部标准分发入口
  - STATUS_OFFLINE 与两种在线状态的边界是否准确
  - 父节点运行缓存回退与集合索引是否保持兼容
  - ETag 是否随节点成员变化正确失效
testerFocus:
  - 服务层状态矩阵：离线排除、无推送在线保留、完整在线保留
  - 在线到离线再恢复的 HTTP 响应和 ETag 序列
  - ServerAutoOnlineService 与 ServerGfwCheckService 相关回归
uiValidation: none
riskBoundary:
  - 不连接生产环境或读取真实凭据
  - 不修改数据库结构、show 语义或父子状态回退算法
  - 不提交工作区原有的 public/assets/admin 子模块改动和 .helloagents/user 标记
```

---

## 6. 成果设计

N/A：本次为后端分发准入修复，不产生视觉或交互变更。
