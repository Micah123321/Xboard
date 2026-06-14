# 变更提案: fix-auto-online-child-offline

## 元信息
```yaml
类型: 修复
方案类型: implementation
优先级: P1
状态: 已规划
创建: 2026-06-14
```

---

## 1. 需求

### 背景
最近两个节点状态相关提交先把子节点运行缓存改为自身独立读取，随后又允许子节点在自身心跳缺失或过期时回退读取父节点运行缓存。该回退能让仅由父入口上报的转发子节点在列表中展示运行指标，但也让 `ServerAutoOnlineService` 的显隐判定复用了父节点心跳：当子节点自身已经两天没有心跳而父节点仍在线时，自动上线可能继续认为子节点可显示，导致后台列表中“离线”与“显隐开启”长期不一致。

### 目标
- 保留管理端列表、在线人数、metrics 等运行态字段对父入口缓存的兼容回退。
- 自动上线写入 `v2_server.show` 时只依据当前节点自身心跳判断是否可显示，避免子节点被父节点心跳误判为在线。
- 补充回归测试覆盖“子节点自身离线但父节点在线时自动隐藏”的场景。

### 约束条件
```yaml
时间约束: 无
性能约束: 不引入额外批量查询或外部 I/O
兼容性约束: 不破坏转发子节点列表指标对父缓存的回退展示
业务约束: 自动上线只托管已开启 auto_online 的节点；未开启自动上线的节点仍保持手动显隐
```

### 验收标准
- [ ] `ServerAutoOnlineService` 对 `auto_online=1` 的子节点进行显隐同步时，若子节点自身 `LAST_CHECK_AT` 缺失或过期，应将 `show` 写为 false，即使父节点运行缓存仍在线。
- [ ] 管理端节点列表读取 `available_status`、`online`、`metrics`、`load_status` 时仍可在子节点自身缓存缺失或过期时回退父节点缓存。
- [ ] 相关单元测试通过，覆盖父缓存回退展示与自动上线自身心跳判定的边界。

---

## 2. 方案

### 技术方案
- 在 `App\Models\Server` 中新增面向自动上线的自身运行态方法，例如 `own_available_status` 访问器或显式方法，用当前节点自身 `LAST_CHECK_AT` / `LAST_PUSH_AT` 缓存计算状态，不走父缓存回退。
- `ServerAutoOnlineService` 的 `$shouldShow` 改为使用该自身状态，继续保留墙检测、重连冷却和 `gfw_auto_hidden` 否决逻辑。
- 保持现有 `available_status`、`last_check_at`、`online`、`metrics`、`load_status` 访问器的父缓存回退能力，用于管理端展示和运行指标兼容。
- 调整 `ServerAutoOnlineServiceTest`，把“父缓存可回退展示”和“自动上线不可用父缓存决定显隐”拆成两个独立断言。

### 影响范围
```yaml
涉及模块:
  - node-auto-online: 修正自动上线显隐判定的数据来源
  - node-gfw-check: 保持墙状态作为当前节点显示否决条件，不扩展到父节点
  - admin-frontend: 不改前端代码，仅依赖后端返回字段恢复一致
预计变更文件: 3-5
```

### 风险评估
| 风险 | 等级 | 应对 |
|------|------|------|
| 仅父入口上报、子节点没有自身心跳的转发节点会被自动上线隐藏 | 中 | 这是本次修复的目标行为；列表指标仍可回退展示，自动显示必须由当前节点自身心跳证明 |
| 新增自身状态方法与现有访问器语义混淆 | 低 | 方法命名明确区分 `available_status` 展示态和自身心跳态，并用测试固定边界 |
| 本地环境测试受 PHP 扩展或数据库配置影响 | 中 | 优先运行目标单测；若环境缺失，记录阻断原因并提供可执行命令 |

### 方案取舍
```yaml
唯一方案理由: 将展示态运行缓存和自动上线写入判定拆开，是当前最小且边界清晰的修复。它保留 a3f27de 引入的父缓存回退展示能力，同时避免该展示回退影响真实显隐写入。
放弃的替代路径:
  - 完全移除父缓存回退: 会回退 a3f27de 的兼容目标，使仅父入口上报的转发子节点在列表中重新全部离线。
  - 前端按离线状态强制显示显隐关闭: 只能修正视觉，不会修改订阅可见性的真实数据源 `v2_server.show`。
  - 改节点端上报协议: 影响 mi-node 与部署链路，超出当前回归修复范围。
回滚边界: 可独立回退 `Server` 自身状态方法、`ServerAutoOnlineService` 判定调整和对应测试；不涉及数据库结构和生产数据批量改写。
```

---

## 3. 技术设计

### 状态来源边界

```text
管理端展示字段
  Server::available_status / last_check_at / online / metrics
  允许当前节点缓存缺失或过期时回退父节点运行缓存

自动上线显隐写入
  ServerAutoOnlineService
  只使用当前节点自身 LAST_CHECK_AT / LAST_PUSH_AT 计算在线状态
```

### 数据模型

不新增数据库字段，不修改迁移。

---

## 4. 核心场景

> 执行完成后同步到对应模块文档

### 场景: 子节点自身离线但父节点在线
**模块**: node-auto-online
**条件**: 子节点 `auto_online=1` 且 `show=1`，子节点自身 `LAST_CHECK_AT` 缺失或过期，父节点 `LAST_CHECK_AT` 仍新鲜。
**行为**: 执行 `sync:server-auto-online` 或 `ServerAutoOnlineService::syncServer($child)`。
**结果**: 子节点 `show=false`；管理端运行指标仍可通过展示态访问器回退父缓存。

---

## 5. 技术决策

> 本方案涉及的技术决策，归档后成为决策的唯一完整记录

### fix-auto-online-child-offline#D001: 分离展示态缓存回退与自动上线自身心跳判定
**日期**: 2026-06-14
**状态**: ✅采纳
**背景**: 父缓存回退解决了转发子节点列表展示问题，但自动上线是写入订阅可见性的业务动作，不能用父节点心跳证明当前子节点仍可用。
**选项分析**:
| 选项 | 优点 | 缺点 |
|------|------|------|
| A: 自动上线使用自身心跳，展示字段保留父缓存回退 | 最小改动，兼容展示需求，修复真实显隐写入 | 需要引入清晰命名避免语义混淆 |
| B: 移除父缓存回退 | 语义简单 | 破坏仅父入口上报的转发子节点展示兼容 |
| C: 只改前端展示 | 改动小 | 真实 `show` 仍错误，订阅侧仍受影响 |
**决策**: 选择方案 A
**理由**: 自动上线是订阅可见性控制，应采用当前节点自身运行证据；父缓存回退只应服务于管理端观察和历史兼容。
**影响**: `app/Models/Server.php`、`app/Services/ServerAutoOnlineService.php`、自动上线单元测试和 node-auto-online 知识库文档。

---

## 6. 验证策略

```yaml
verifyMode: test-first
reviewerFocus:
  - app/Models/Server.php 的运行缓存访问器语义
  - app/Services/ServerAutoOnlineService.php 的 shouldShow 判定
testerFocus:
  - tests/Unit/ServerAutoOnlineServiceTest.php
  - php artisan test --filter=ServerAutoOnlineServiceTest
uiValidation: none
riskBoundary:
  - 不执行生产数据库写入
  - 不连接远程服务器
  - 不改数据库结构
```

---

## 7. 成果设计

N/A。本次不产生视觉变更。
