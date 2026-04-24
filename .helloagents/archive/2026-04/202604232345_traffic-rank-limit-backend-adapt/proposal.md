# 变更提案: traffic-rank-limit-backend-adapt

## 元信息
```yaml
类型: 功能增强 + 接口适配
方案类型: implementation
优先级: P1
状态: 已完成
创建: 2026-04-23
```

---

## 1. 需求

### 背景
当前 `admin-frontend` 的节点/用户流量排行已经补上了 `10个 / 20个` 切换 UI，但后端 `stat/getTrafficRank` 接口仍固定只取前 10 条，所以前端切到 20 条时实际上拿不到更多数据。同时，用户进一步明确：24 小时口径下依然要显示增幅/减幅，不需要特殊隐藏。

### 目标
- 让后端 `getTrafficRank` 支持按请求返回 `10` 或 `20` 条数据。
- 让前端在节点排行/用户排行切换显示数量时，把对应的 `limit` 传给后端重新请求。
- 保持 24h 口径下继续返回并显示涨幅/减幅，不额外关闭该能力。

### 约束条件
```yaml
范围约束: 仅调整 traffic rank 相关前后端逻辑，不扩展到其他 dashboard 模块
接口约束: 不新增新接口，在现有 /stat/getTrafficRank 基础上增量支持 limit 参数
业务约束: 24h / 7天 / 30天 都继续允许返回 change，前端不对 24h 单独隐藏
工作树约束: 在当前脏工作树基础上最小增量修改，只触达本轮确有关系的文件
```

### 验收标准
- [ ] `stat/getTrafficRank` 接口支持接收 `limit=10|20`，并按参数返回对应条数。
- [ ] dashboard 前端在节点/用户排行切换显示数量时，会向后端请求对应 limit，而不是仅前端截断。
- [ ] 24h 口径下排行仍显示增幅/减幅，前后端都不额外屏蔽该字段。
- [ ] `admin-frontend` 构建通过，相关 PHP 文件语法检查通过。

---

## 2. 方案

### 技术方案
1. 在 `app/Http/Controllers/V2/Admin/StatController.php` 的 `getTrafficRank()` 中新增 `limit` 参数校验，并把当前节点/用户排行查询的 `limit(10)` 改为动态 limit。
2. 在 `admin-frontend/src/api/admin.ts` 的 `getTrafficRank()` 中支持传入 `limit` 参数。
3. 在 `admin-frontend/src/views/dashboard/DashboardView.vue` 的 `loadRankings()` 中分别把 `nodeRankLimit`、`userRankLimit` 传给对应接口，并在显示数量变化时重新请求排行数据。

### 影响范围
```yaml
涉及模块:
  - app/Http/Controllers/V2/Admin/StatController.php
  - admin-frontend/src/api/admin.ts
  - admin-frontend/src/views/dashboard/DashboardView.vue
  - public/assets/admin (构建产物输出)
预计变更文件: 3-4
```

### 风险评估
| 风险 | 等级 | 应对 |
|------|------|------|
| limit 参数放开后请求值异常 | 中 | 后端仅允许 `10` 或 `20`，避免任意放大查询 |
| 前端 limit 切换后仍沿用旧数据 | 中 | 对 `nodeRankLimit` / `userRankLimit` 增加 watch，变化即重新拉取 |
| 当前工作树已有 dashboard 相关脏改动 | 中 | 只做最小补丁，并通过构建与 git diff 核对本轮触达文件 |

---

## 3. 成果设计

### 设计方向
- **交互基线**: 维持现有 Apple 风格排行面板，不新增可视化噪音。
- **数据行为**: 数量切换真正驱动后端返回更多排行项，而不是仅靠前端裁切。
- **显示规则**: 24 小时口径仍保留涨跌百分比展示，与 7 天/30 天保持一致。

---

## 4. 技术决策

### traffic-rank-limit-backend-adapt#D001: 在现有 getTrafficRank 接口上新增 limit 参数
**日期**: 2026-04-23
**状态**: ✅采纳
**背景**: 前端已经存在 10/20 切换控件，但后端固定 limit 10 导致能力不完整。
**决策**: 不新增新接口，直接在 `getTrafficRank` 上增加受控 `limit` 参数。
**理由**: 改动最小，且与现有 dashboard 请求模型保持一致。

### traffic-rank-limit-backend-adapt#D002: 24h 口径继续显示 change
**日期**: 2026-04-23
**状态**: ✅采纳
**背景**: 用户明确要求 24 小时口径也允许展示增幅/减幅。
**决策**: 保持后端始终返回 `change`，前端不为 24h 增加隐藏逻辑。
**理由**: 行为统一，避免不同时间口径的 UI 规则分裂。
