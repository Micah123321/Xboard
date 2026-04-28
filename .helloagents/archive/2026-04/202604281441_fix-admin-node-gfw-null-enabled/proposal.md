# 变更提案: fix-admin-node-gfw-null-enabled

## 元信息
```yaml
类型: 修复
方案类型: implementation
优先级: P1
状态: 已规划
创建: 2026-04-28
```

---

## 1. 需求

### 背景
管理后台 `#/nodes` 页面中，部分节点显示已开启“墙检测托管”和“自动上线”，但墙状态长期为“未检测”。代码检查显示项目大部分逻辑把 `parent_id = 0` 或 `NULL` 都视为父节点，但自动墙检入队只匹配 `parent_id IS NULL`；同时前端、服务层默认语义均把 `gfw_check_enabled` 的空值视为开启，而自动查询只匹配数据库值 `true`。这两处语义不一致会导致页面显示可自动检测的父节点没有被自动任务入队。

### 目标
- 统一父节点判断：`parent_id = 0` 与 `parent_id = NULL` 均参与自动墙检父节点筛选。
- 统一 `gfw_check_enabled` 的启用判断：仅明确为 `false` 时关闭，`true` 或 `NULL` 均按开启处理。
- 自动墙检命令 `sync:server-gfw-checks` 能为启用托管的父节点创建检测任务。
- 自动上线联动能读取启用托管节点的最新墙检状态，避免节点被墙状态漏管。
- 前端统计与后端默认语义一致，减少“开关显示开启但实际未参与自动检测”的误导。

### 约束条件
```yaml
时间约束: 当前轮完成代码修复、测试和构建验证
性能约束: 自动任务查询仍保持按父节点、排序和可选 limit 过滤，不引入全表重计算
兼容性约束: 保留既有字段与 API 响应结构，不新增迁移破坏已有数据库
业务约束: 子节点仍不独立检测，只继承父节点墙检状态
```

### 验收标准
- [ ] `parent_id = 0` 的父节点会参与自动墙检入队。
- [ ] `gfw_check_enabled = NULL` 的父节点在兼容旧库结构时会参与自动墙检入队。
- [ ] `gfw_check_enabled = false` 的父节点仍不会参与自动墙检入队。
- [ ] 自动上线联动对 `NULL` 启用语义保持一致，可读取对应父节点最新墙状态。
- [ ] 管理端自动墙检统计只统计父节点，并与后端自动入队范围一致。
- [ ] 相关后端单元测试与 `admin-frontend` 构建通过，若环境缺依赖需明确记录。

---

## 2. 方案

### 技术方案
在 `ServerGfwCheckService` 中抽出父节点查询方法和启用过滤查询方法，父节点查询使用 `(parent_id IS NULL OR parent_id = 0)`，启用查询使用 `(gfw_check_enabled = true OR gfw_check_enabled IS NULL)`，与项目现有父/子节点判断和 `isGfwCheckEnabled()` 的服务层判断保持一致。同步更新前端节点工具函数，让“自动墙检”统计只统计启用托管的父节点。补充单元测试覆盖 `parent_id = 0` 的自动入队回归。

### 影响范围
```yaml
涉及模块:
  - app/Services/ServerGfwCheckService.php: 统一自动墙检启用查询和最新状态查询
  - admin-frontend/src/utils/nodes.ts: 调整自动墙检统计口径
  - tests/Unit/ServerGfwCheckServiceTest.php: 补充空值启用语义回归测试
预计变更文件: 3
```

### 风险评估
| 风险 | 等级 | 应对 |
|------|------|------|
| 历史 `parent_id = 0` 父节点突然进入自动检测队列 | 中 | 该行为与前端父节点展示、手动检测和服务层父节点判断一致 |
| 历史 `NULL` 启用值节点进入自动检测队列 | 低 | 该行为与前端开关和服务默认语义一致；仅明确 `false` 的节点继续关闭 |
| 自动墙检统计口径变化导致数量下降 | 低 | 旧统计包含子节点，新统计改为父节点，与实际检测范围一致 |
| 本地 PHP 依赖不可用导致后端测试无法执行 | 中 | 优先运行目标测试；失败时记录环境缺口并至少完成前端构建验证 |

### 方案取舍
```yaml
唯一方案理由: 根因是父节点和启用语义在 UI/服务层与查询层不一致，修复查询条件能直接解决自动检测漏入队问题，同时保持现有 API 和数据模型不变。
放弃的替代路径:
  - 新增数据迁移批量回填 NULL: 只能修复一次历史数据，不能防止未来异常数据或旧库结构导入导致同类问题。
  - 前端把 NULL 显示为关闭: 与服务层 `isGfwCheckEnabled()` 默认开启语义冲突，会改变用户已经看到的托管状态。
  - 只提示用户重新切换开关: 属于人工绕过，不能解决自动任务与状态查询的根因。
回滚边界: 可独立回退 `ServerGfwCheckService` 查询辅助方法、前端统计函数和新增测试，不涉及数据库结构回滚。
```

---

## 3. 技术设计

### 服务层父节点与启用语义
```php
where(function ($query) {
    $query->whereNull('parent_id')
        ->orWhere('parent_id', 0);
})
```

```php
where(function ($query) {
    $query->where('gfw_check_enabled', true)
        ->orWhereNull('gfw_check_enabled');
})
```

该语义只作用于自动墙检父节点筛选和最新墙状态查询；手动开关保存、批量更新和子节点继承逻辑保持不变。

---

## 4. 核心场景

### 场景: 历史节点参与自动墙检
**模块**: 节点墙检服务  
**条件**: 父节点 `parent_id` 为 `0` 或 `NULL`，且无未完成墙检任务。  
**行为**: 执行 `sync:server-gfw-checks` 或调用 `startAutomaticChecks()`。  
**结果**: 为该父节点创建 `pending` 墙检任务，管理端刷新后显示“等待节点领取”或“检测中”。

### 场景: 显式关闭节点不参与自动墙检
**模块**: 节点墙检服务  
**条件**: 父节点 `gfw_check_enabled` 为 `false`。  
**行为**: 执行自动墙检。  
**结果**: 不创建墙检任务。

---

## 5. 技术决策

### fix-admin-node-gfw-null-enabled#D001: 自动墙检查询对齐项目父节点与启用语义
**日期**: 2026-04-28  
**状态**: ✅采纳  
**背景**: 管理端和大部分后端逻辑把 `parent_id = 0` 与 `NULL` 都视为父节点，并把 `gfw_check_enabled` 非 `false` 视为开启；自动墙检查询只匹配 `parent_id IS NULL` 与 `gfw_check_enabled = true`，导致部分页面显示开启的父节点没有被自动入队。  
**选项分析**:
| 选项 | 优点 | 缺点 |
|------|------|------|
| A: 查询层兼容 `parent_id=0/NULL` 与 `gfw_check_enabled=true/NULL` | 与现有 UI、手动检测和服务层语义一致，不改变 API 与数据库结构 | 历史 `parent_id=0` 节点会重新进入自动墙检范围 |
| B: 数据迁移统一回填父节点与启用字段 | 数据更整洁 | 对已存在但结构不同的部署风险更高，且不能防止导入旧数据后复发 |
**决策**: 选择方案 A。  
**理由**: 当前问题本质是查询条件与既有运行时语义不一致，查询层对齐能最小化修复漏检，并避免数据库结构变更风险。  
**影响**: `ServerGfwCheckService` 自动入队、最新墙状态查询和管理端统计口径。

---

## 6. 验证策略

```yaml
verifyMode: test-first
reviewerFocus:
  - app/Services/ServerGfwCheckService.php 中父节点过滤、启用过滤与服务默认语义是否一致
  - 前端统计是否仍与实际自动墙检范围一致
testerFocus:
  - php artisan test --filter=ServerGfwCheckServiceTest
  - npm run build（admin-frontend）
uiValidation: optional
riskBoundary:
  - 不新增数据库迁移
  - 不改变节点端上报 API
  - 不改变子节点继承检测规则
```

---

## 7. 成果设计

N/A。本次不是视觉改版，只做状态语义与统计口径修复。
