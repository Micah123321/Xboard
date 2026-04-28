# 变更提案: node-traffic-yesterday-stats

## 元信息
```yaml
类型: 修复 + 新功能
方案类型: implementation
优先级: P1
状态: 已规划
创建: 2026-04-29
```

---

## 1. 需求

### 背景
管理后台 `#/nodes` 的节点流量详情卡当前展示“今日 / 本月 / 累计”。用户反馈某节点“今日下行很多，但本月上行最多”看起来不匹配，并要求统计加入“昨日”。

### 目标
- 核对节点统计的上行/下行字段映射，确认是否存在前后端反转或聚合口径错误。
- 在节点流量详情卡中新增“昨日”统计，口径与“今日”一致。
- 收紧统计窗口边界，保证“今日 / 昨日 / 本月 / 累计”各自窗口清晰。

### 约束条件
```yaml
时间约束: 无
性能约束: 节点列表接口仍按当前批量聚合方式查询，避免逐节点查询
兼容性约束: 保持现有 traffic_stats.today/month/total 字段兼容，新增 yesterday 字段
业务约束: 不改变 StatServer.u/d 的含义，不迁移历史数据
```

### 验收标准
- [ ] `server/manage/getNodes` 响应中的 `traffic_stats` 包含 `yesterday`。
- [ ] `today` 只统计当天 `[today, tomorrow)`，`yesterday` 只统计 `[yesterday, today)`，`month` 只统计 `[monthStart, nextMonthStart)`。
- [ ] 前端节点流量详情卡按“今日 / 昨日 / 本月 / 累计”展示。
- [ ] 后端测试覆盖新窗口边界，前端构建通过。

---

## 2. 方案

### 技术方案
在 `ManageController` 内扩展节点流量窗口构建：
- `emptyNodeTrafficStats()` 增加 `yesterday` 默认值。
- `buildNodeTrafficStats()` 使用 `strtotime('today')`、`strtotime('tomorrow')`、`strtotime('yesterday')` 和下月月初计算窗口。
- `fillTrafficWindow()` 支持可选结束时间，查询使用半开区间：`record_at >= startAt` 且 `record_at < endAt`。

前端同步：
- `AdminNodeTrafficStats` 增加 `yesterday: TrafficAmount`。
- `NodeTrafficDetail.key` 增加 `yesterday`。
- `getNodeTrafficDetails()` 在“今日”和“本月”之间插入“昨日”。

测试：
- 新增/扩展 `ManageController` 单元测试，通过反射调用私有构建方法，构造跨天、跨月和未来记录，验证各窗口不会互相污染。

### 影响范围
```yaml
涉及模块:
  - admin-frontend: 节点流量详情卡类型与展示行
  - backend-admin-api: 节点列表接口 traffic_stats 聚合窗口
  - tests: 节点统计窗口单元测试
预计变更文件: 4-6
```

### 风险评估
| 风险 | 等级 | 应对 |
|------|------|------|
| 历史数据中 u/d 语义本身来自节点端上报，若节点端上报方向定义与面板相反，面板无法单独纠正 | 中 | 本次只核对面板字段链路；不改变历史语义，避免误修 |
| 新增窗口可能增加查询次数 | 低 | 仍为按 server_id 批量聚合，仅从 3 个窗口增至 4 个窗口 |
| 月统计加入上界后不再包含未来 record_at | 低 | 这是更严格的窗口口径，符合预期 |

### 方案取舍
```yaml
唯一方案理由: 后端统一输出 yesterday 字段，前端只按接口字段展示，可以保持统计口径单一且兼容现有调用方。
放弃的替代路径:
  - 仅前端用 today/month/total 推导昨日: 无法准确还原昨日上行/下行。
  - 修改 StatServerJob 的 u/d 写入方向: 当前面板链路内 u=上行、d=下行一致，贸然反转会破坏历史数据和用户统计。
  - 新增独立节点详情接口: 本次只影响列表详情卡，新增接口会扩大维护面。
回滚边界: 可独立回退 ManageController 的 yesterday/window 变更、前端类型/展示变更和测试文件，不涉及数据库迁移。
```

---

## 3. 技术设计

### API 设计
#### GET server/manage/getNodes
- **响应新增字段**: `traffic_stats.yesterday`
- **结构**:
```json
{
  "traffic_stats": {
    "today": {"upload": 0, "download": 0, "total": 0},
    "yesterday": {"upload": 0, "download": 0, "total": 0},
    "month": {"upload": 0, "download": 0, "total": 0},
    "total": {"upload": 0, "download": 0, "total": 0}
  }
}
```

### 数据模型
不新增数据表或字段，继续读取 `v2_stat_server` 的日统计记录：

| 字段 | 类型 | 说明 |
|------|------|------|
| `u` | bigint | 上行流量 |
| `d` | bigint | 下行流量 |
| `record_at` | int | 日统计归属日的 00:00:00 Unix 时间戳 |
| `record_type` | char | 本节点页只读取 `d` |

---

## 4. 核心场景

### 场景: 节点流量详情卡查看昨日统计
**模块**: admin-frontend  
**条件**: 管理员打开 `#/nodes` 并悬停节点名称  
**行为**: 前端读取 `traffic_stats.yesterday` 并渲染“昨日”行  
**结果**: 管理员可以直接对比今日、昨日、本月和累计的上行/下行分布

### 场景: 节点列表接口按清晰窗口聚合
**模块**: backend-admin-api  
**条件**: `v2_stat_server` 存在昨天、今天、本月其他日期和未来日期记录  
**行为**: `server/manage/getNodes` 构建半开时间窗口  
**结果**: 今日、昨日、本月统计互不串窗，累计仍覆盖全部历史记录

---

## 5. 技术决策

### node-traffic-yesterday-stats#D001: 保持 u/d 语义并新增后端 yesterday 字段
**日期**: 2026-04-29  
**状态**: ✅采纳  
**背景**: 用户反馈上行/下行看起来不匹配，同时要求加入昨日统计。代码链路显示前端、接口和入库任务均使用 `u=upload`、`d=download`。  
**选项分析**:
| 选项 | 优点 | 缺点 |
|------|------|------|
| A: 后端新增 `traffic_stats.yesterday` 并收紧窗口 | 口径统一、可测试、兼容当前字段 | 增加一个聚合查询 |
| B: 前端推导昨日 | 不改后端 | 无法准确得到昨日上行/下行 |
| C: 反转 u/d 字段 | 可能符合某些节点端方向理解 | 会破坏现有面板语义和历史统计 |
**决策**: 选择方案 A  
**理由**: 问题核心是缺少可对比的昨日窗口和窗口边界不够明确，不是面板链路内字段反转。  
**影响**: `server/manage/getNodes` 响应字段增加，节点页详情卡增加一行展示。

---

## 6. 验证策略

```yaml
verifyMode: test-first
reviewerFocus:
  - app/Http/Controllers/V2/Admin/Server/ManageController.php 的窗口边界和兼容字段
  - admin-frontend/src/utils/nodes.ts 的展示顺序与空值兜底
testerFocus:
  - php artisan test --filter NodeTrafficStatsTest
  - npm run build（admin-frontend）
uiValidation: optional
riskBoundary:
  - 不执行数据库删除、重置或生产环境操作
  - 不修改历史 StatServer 数据
```

---

## 7. 成果设计

### 设计方向
- **美学基调**: 延续现有 Apple 风格节点详情卡，新增“昨日”作为同等层级数据行，不引入额外视觉系统。
- **记忆点**: 今日与昨日紧邻展示，便于直接比较日流量方向变化。
- **参考**: 现有节点流量 popover。

### 视觉要素
- **配色**: 沿用现有白底、浅灰行底和蓝色总量强调。
- **字体**: 沿用现有管理端字体栈，不新增字体依赖。
- **布局**: 维持纵向统计行结构，顺序为今日、昨日、本月、累计。
- **动效**: 沿用 Element Plus Popover 行为，不新增动效。
- **氛围**: 与当前节点页一致。

### 技术约束
- **可访问性**: 不改变现有 hover/focus 触发方式。
- **响应式**: Popover 宽度维持现状，新增一行不改变表格布局。
