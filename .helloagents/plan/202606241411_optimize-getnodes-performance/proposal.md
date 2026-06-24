# 变更提案: optimize-getnodes-performance

## 元信息
```yaml
类型: 优化
方案类型: implementation
优先级: P1
状态: 已确定
创建: 2026-06-24
```

---

## 1. 需求

### 背景
管理端请求 `GET /api/v2/adminadmin/server/manage/getNodes` 在线上加载约 6 秒。代码定位到 `ManageController::getNodes()`，该接口同时返回节点列表、权限组、父节点、墙检测状态、流量统计和节点月流量限额快照。当前实现存在节点循环内重复查询权限组 / 父节点的 N+1 风险，并且 `ServerTrafficLimitService::buildSnapshotsForServers()` 会按节点重复计算相同 scope 的当前账期用量。

### 目标
- 保持 `server/manage/getNodes` 现有响应字段契约不变。
- 批量化节点权限组、父节点、墙检测和流量限额快照计算，减少节点数量增长时的 SQL 数量。
- 保持节点流量详情卡和月流量限额展示口径不变。
- 为批量聚合行为补充测试，避免后续回归。

### 约束条件
```yaml
时间约束: 本轮完成实现、验证、review 和 commit
性能约束: 优先降低 SQL 数量和大表重复聚合，不引入额外线上副作用
兼容性约束: 不改变前端字段名和字段结构；不要求线上立即新增索引
业务约束: 节点墙检测继承、共享月流量 scope、父子节点运行缓存语义保持不变
```

### 验收标准
- [ ] `getNodes` 不再在每个节点循环中单独查询 `v2_server_group` 或父节点。
- [ ] `buildSnapshotsForServers()` 对同一 `machine_id` / host scope 只计算一次账期统计，再复用到 scope 内节点。
- [ ] 现有节点流量窗口和节点月流量限额测试通过。
- [ ] 新增测试覆盖重复 scope 下的批量快照复用和 `getNodes` 装饰后的字段完整性。

---

## 2. 方案

### 技术方案
- 在 `ManageController::getNodes()` 中先获取完整节点集合，再批量构建权限组查找表和父节点查找表，避免循环内查询。
- 保留 `ServerGfwCheckService::decorateServers()` 的批量装饰入口，必要时优化内部最新检测记录查询。
- 在 `ServerTrafficLimitService::buildSnapshotsForServers()` 内按 scope 预计算：一次分组、一次批量读取 runtime metrics、一次批量聚合当前账期 `v2_stat_server` 数据，然后按节点生成快照。
- 为批量聚合新增或调整私有 helper，保持 `buildTrafficLimitSnapshot()` 单节点入口兼容现有调用。
- 补充 PHPUnit 测试，优先验证行为和查询数量上限。

### 影响范围
```yaml
涉及模块:
  - app/Http/Controllers/V2/Admin/Server/ManageController.php: 节点列表装饰批量化
  - app/Services/ServerTrafficLimitService.php: 节点限额快照批量聚合
  - tests/Unit/ServerTrafficLimitServiceTest.php: 补充批量聚合回归测试
  - tests/Unit/Admin/NodeTrafficStatsWindowTest.php 或新增测试: 覆盖 getNodes 字段完整性和 N+1 防护
预计变更文件: 4-6
```

### 风险评估
| 风险 | 等级 | 应对 |
|------|------|------|
| 批量化后共享 scope 用量口径偏移 | 中 | 复用既有测试用例，新增同 host / machine scope 测试 |
| 父节点字段返回结构变化 | 中 | 使用已加载父节点模型赋值，保持 `$item['parent']` 字段存在 |
| 查询数量测试受 Laravel 内部查询波动影响 | 低 | 只断言关键表查询数量上限，不把缓存读取纳入 SQL 断言 |
| 本地 PHP 扩展不完整导致全量测试不可跑 | 低 | 运行目标 PHPUnit 测试；若环境缺失则记录验证失败原因 |

### 方案取舍
```yaml
唯一方案理由: 慢点来自已确认的重复查询和重复聚合，批量化能直接改善接口耗时且不改变 API 契约。
放弃的替代路径:
  - 前端分页 / 懒加载: 能减少渲染压力，但不能解决后端 6 秒请求本身。
  - 新增缓存整个 getNodes 响应: 可降低重复访问耗时，但节点在线状态、墙检测和 metrics 时效性强，缓存失效复杂。
  - 立即新增复合索引迁移: 可能有帮助，但当前已有 server_id / record_at 索引，首要问题是重复查询数量；避免本轮引入线上迁移风险。
回滚边界: 可独立回退 `ManageController` 和 `ServerTrafficLimitService` 的批量化改动；测试文件随代码回退。
```

---

## 3. 技术设计

### API 设计
#### GET `/api/v2/{secure_path}/server/manage/getNodes`
- **请求**: 现有请求不变。
- **响应**: 现有节点数组不变，继续包含 `groups`、`parent`、`gfw_check`、`traffic_stats`、`traffic_limit_snapshot` 等字段。

### 数据模型
本方案不新增字段、不新增迁移。

---

## 4. 核心场景

### 场景: 管理端节点列表加载
**模块**: admin-frontend / node-traffic-limit / node-gfw-check
**条件**: 管理员打开 `#/nodes`，前端请求 `server/manage/getNodes`
**行为**: 后端一次加载节点集合并批量装饰权限组、父节点、墙状态、流量统计和限额快照
**结果**: 返回结构保持不变，节点数增加时 SQL 查询数量不再按节点线性增长

---

## 5. 技术决策

### optimize-getnodes-performance#D001: 优先批量化装饰而非整接口缓存
**日期**: 2026-06-24
**状态**: ✅采纳
**背景**: `getNodes` 响应包含实时性较强的节点在线状态、metrics、墙检测状态和流量限额状态，整接口缓存容易产生 stale 数据和复杂失效逻辑。
**选项分析**:
| 选项 | 优点 | 缺点 |
|------|------|------|
| A: 批量化查询和聚合 | 保持实时性，降低 SQL 数量，不改变前端契约 | 需要重构部分服务内部计算 |
| B: 整接口缓存 | 实现简单，重复访问快 | 容易返回过期在线状态，失效边界复杂 |
| C: 前端分页懒加载 | 降低前端渲染压力 | 不解决后端接口自身 6 秒耗时 |
**决策**: 选择方案 A。
**理由**: 直接消除代码证据中的 N+1 和重复聚合问题，收益稳定且回滚边界清晰。
**影响**: 影响节点列表接口和节点月流量限额快照生成，不改变数据库 schema。

---

## 6. 验证策略

```yaml
verifyMode: test-first
reviewerFocus:
  - app/Http/Controllers/V2/Admin/Server/ManageController.php 的响应契约和 N+1 防护
  - app/Services/ServerTrafficLimitService.php 的批量 scope 聚合口径
testerFocus:
  - vendor/bin/phpunit tests/Unit/ServerTrafficLimitServiceTest.php
  - vendor/bin/phpunit tests/Unit/Admin/NodeTrafficStatsWindowTest.php
  - 新增 getNodes 相关单元/功能测试
uiValidation: none
riskBoundary:
  - 不连接生产数据库
  - 不执行生产缓存清理或部署
  - 不改变前端字段契约
```

---

## 7. 成果设计

N/A。本任务不涉及视觉产出。
