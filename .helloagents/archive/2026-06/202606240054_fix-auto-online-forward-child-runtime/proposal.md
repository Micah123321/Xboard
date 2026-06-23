# 变更提案: fix-auto-online-forward-child-runtime

## 元信息
```yaml
类型: 修复
方案类型: implementation
优先级: P1
状态: 已完成
创建: 2026-06-24
```

---

## 1. 需求

### 背景
最近 4 个提交中有 3 个涉及节点自动上线、父子节点运行缓存和转发子节点显隐隔离：

- `ef31c65` 将父节点自动上线、墙检测和流量限额对子节点的显隐联动拆开，并让运行缓存优先按子节点自身读取。
- `a3f27de` 为只由父入口上报运行状态的转发子节点加入父运行缓存兜底。
- `02bf268` 将自动上线写入 `show` 时的判断改为仅看子节点自身心跳，导致只依赖父入口运行缓存的转发子节点会被 `sync:server-auto-online` 判定离线并隐藏。
- `d4f0ea9` 只涉及本地 PHP 环境和知识库记录，与本次回归无直接业务代码关系。

当前代码中 `Server::available_status` 已能通过父运行缓存展示转发子节点在线，但 `ServerAutoOnlineService` 使用 `ownAvailableStatus()` 写入 `show`，造成展示态与自动显隐态不一致。

### 目标
- 修复自动上线误隐藏只由父入口上报运行缓存的转发子节点。
- 保留父子显隐隔离：父节点的 `show`、墙状态和流量限额状态不得直接改写子节点。
- 保留管理端展示态对父运行缓存的兜底能力。
- 补充回归测试，覆盖自动上线使用父运行缓存兜底的场景。

### 约束条件
```yaml
时间约束: 本轮完成修复、验证、review 和 commit
性能约束: 不新增跨节点批量查询；沿用当前 Server 运行缓存读取机制
兼容性约束: 兼容旧父入口上报缓存键和当前按子节点上报缓存键
业务约束: 不执行生产环境操作，不改数据库结构，不恢复历史父节点对子节点显隐联动
```

### 验收标准
- [ ] 子节点自身没有新鲜运行缓存，但父节点运行缓存新鲜时，自动上线不会把子节点隐藏。
- [ ] 子节点自身和父节点都没有有效运行缓存时，自动上线仍会隐藏该节点。
- [ ] 父节点墙状态 blocked 不会阻止子节点按自身/父入口运行状态上线。
- [ ] `tests/Unit/ServerAutoOnlineServiceTest.php` 相关用例通过。
- [ ] 本次改动完成后执行 review 并提交 commit。

---

## 2. 方案

### 技术方案
将 `ServerAutoOnlineService` 的在线判断从 `ownAvailableStatus()` 调整为 `available_status`，让自动上线使用与管理端展示一致的有效运行状态来源：当前节点自身运行缓存优先，必要时回退父节点运行缓存。

同时恢复并补充测试：

- 转发子节点仅有父节点运行缓存时，`syncServer($child)` 应保持或显示子节点。
- 子节点没有自身和父节点运行缓存时，`syncServer($child)` 应隐藏子节点。
- 子节点不继承父节点 GFW blocked 状态的现有测试保持不变。

### 影响范围
```yaml
涉及模块:
  - node-auto-online: 自动上线 show 写入依据和回归测试
  - knowledge-base: 同步 node-auto-online 行为说明和 CHANGELOG
预计变更文件: 4
```

### 风险评估
| 风险 | 等级 | 应对 |
|------|------|------|
| 仅恢复父缓存兜底可能让真实离线子节点继续显示 | 中 | 自动上线仍要求存在有效运行缓存；无自身和无父缓存时仍隐藏；父节点显隐/墙/流量限额不直接联动子节点 |
| 旧测试与新业务预期冲突 | 中 | 更新测试名称和断言，使其表达“父入口运行缓存是有效运行状态来源” |
| 缓存键兼容遗漏 | 低 | 沿用现有 `runtimeCacheKeys()`，其已同时兼容父真实 type 和旧子 type + parent_id 键 |

### 方案取舍
```yaml
唯一方案理由: 回归点集中在 02bf268 将 show 写入从有效运行状态改成自身心跳；恢复自动上线使用有效运行状态能最小化修复，同时保留 a3f27de 的父入口缓存兼容能力。
放弃的替代路径:
  - 直接回滚 02bf268: 会丢失其新增的 ownAvailableStatus 辅助方法和相关文档上下文，影响面更大。
  - 恢复历史父节点对子节点显隐联动: 会逆转 ef31c65 的隔离目标，重新引入父节点故障误伤转发入口的问题。
  - 新增配置开关区分父缓存兜底策略: 当前缺少用户侧配置需求，会扩大实现与 UI/API 影响范围。
回滚边界: 可独立回退 ServerAutoOnlineService 的判断行与对应测试/文档，不涉及数据库迁移或外部环境状态。
```

---

## 3. 技术设计

### 核心判断
```php
$server->available_status !== Server::STATUS_OFFLINE
```

`available_status` 来自 `last_check_at` / `last_push_at`，而这两个访问器通过 `getRuntimeCacheValue()` 读取运行缓存：

- 父节点或普通节点：只读取自身缓存。
- 转发子节点：自身缓存新鲜时读取自身；自身缓存缺失或过期时回退父节点缓存。

自动上线不恢复父节点对子节点的批量显隐联动；它仍只写入当前传入节点的 `show`。

---

## 4. 核心场景

### 场景: 父入口上报的转发子节点自动上线
**模块**: node-auto-online
**条件**: 子节点 `auto_online=true`，自身缓存缺失，父节点缓存新鲜。
**行为**: 执行 `ServerAutoOnlineService::syncServer($child)`。
**结果**: 子节点不会被隐藏；若原本隐藏则会显示。

### 场景: 无有效运行缓存的托管节点自动下线
**模块**: node-auto-online
**条件**: 节点 `auto_online=true`，自身和父节点均无有效运行缓存。
**行为**: 执行 `ServerAutoOnlineService::syncServer($node)`。
**结果**: 节点 `show=false`。

---

## 5. 技术决策

### fix-auto-online-forward-child-runtime#D001: 自动上线使用有效运行状态而非仅自身心跳
**日期**: 2026-06-24
**状态**: ✅采纳
**背景**: 自动上线写入 `show` 与管理端展示态不一致，导致父入口运行缓存可展示在线的转发子节点被定时任务隐藏。
**选项分析**:
| 选项 | 优点 | 缺点 |
|------|------|------|
| A: 使用 `available_status` | 最小修复，复用现有缓存兜底，符合父入口上报兼容目标 | 需要测试明确父缓存兜底是有效上线依据 |
| B: 使用 `ownAvailableStatus()` | 能严格区分子节点自身心跳 | 会误隐藏只由父入口上报的转发子节点 |
| C: 恢复父节点批量联动子节点 | 旧行为简单 | 会重新引入父节点故障误伤子节点的问题 |
**决策**: 选择方案 A
**理由**: `available_status` 已封装当前节点优先、父缓存兜底的运行状态来源，正好匹配只由父入口上报的转发子节点兼容需求。
**影响**: `ServerAutoOnlineService` 的 `show` 写入依据和 `ServerAutoOnlineServiceTest` 回归覆盖。

---

## 6. 验证策略

```yaml
verifyMode: test-first
reviewerFocus:
  - app/Services/ServerAutoOnlineService.php 中 show 写入依据是否恢复父缓存兜底
  - tests/Unit/ServerAutoOnlineServiceTest.php 是否覆盖父缓存兜底与无缓存隐藏两类行为
  - 是否意外恢复父节点批量改写子节点 show 的历史联动
testerFocus:
  - vendor/bin/phpunit tests/Unit/ServerAutoOnlineServiceTest.php
  - 必要时运行 vendor/bin/phpunit tests/Unit/ServerGfwCheckServiceTest.php tests/Unit/ServerTrafficLimitServiceTest.php
uiValidation: none
riskBoundary:
  - 不连接远程服务器
  - 不执行生产环境命令
  - 不执行数据库破坏性操作
```

---

## 7. 成果设计

N/A：本任务不涉及视觉或交互界面变更。
