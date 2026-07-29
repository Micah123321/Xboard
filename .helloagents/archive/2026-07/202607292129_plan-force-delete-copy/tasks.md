# 任务清单: plan-force-delete-copy

> **@status:** completed | 2026-07-29 21:39

```yaml
@feature: plan-force-delete-copy
@created: 2026-07-29
@status: completed
@mode: R2
```

## 进度概览

| 完成 | 失败 | 跳过 | 总数 |
|------|------|------|------|
| 3 | 0 | 0 | 3 |

---

## 任务列表

### 1. 后端套餐生命周期

- [√] 1.1 在 `app/Http/Controllers/V2/Admin/PlanController.php` 与 `app/Http/Routes/V2/AdminRoute.php` 实现迁移删除和复制接口
  - 预期变更: 验证替代套餐，事务性批量迁移订单和用户后删除源套餐；增加复制套餐端点。
  - 完成标准: 无效替代套餐不会修改数据；有效替代套餐能替换全部关联记录；复制套餐配置一致、名称追加 `--复制`。
  - 验证方式: Laravel 单元测试与 PHP 语法检查。
  - depends_on: []

### 2. 管理端套餐操作

- [√] 2.1 在 `admin-frontend/src/api/admin.ts`、`admin-frontend/src/views/subscriptions/PlansView.vue` 与新增 `PlanDeleteDialog.vue` 中接入替代删除和复制
  - 预期变更: 删除前要求从其他套餐中选择替代套餐；列表新增复制图标操作并刷新数据。
  - 完成标准: 请求包含 `replacement_plan_id`；未选替代套餐不能提交；复制成功提示且列表刷新。
  - 验证方式: TypeScript 类型检查与生产构建。
  - depends_on: [1.1]

### 3. 验证与知识库同步

- [√] 3.1 在 `tests/Unit/Admin/PlanControllerTest.php` 补充套餐迁移删除与复制覆盖，并同步知识库模块说明和变更日志
  - 预期变更: 覆盖成功、无效替代、复制字段三类行为；记录后台套餐管理的新 API 行为。
  - 完成标准: 相关测试通过，知识库与代码接口一致。
  - 验证方式: PHPUnit、前端构建、方案包状态检查。
  - depends_on: [1.1, 2.1]

---

## 执行日志

| 时间 | 任务 | 状态 | 备注 |
|------|------|------|------|
| 2026-07-29 21:29 | 方案设计 | completed | 已确认后端事务迁移、复制 API 与管理端替代选择方案。 |
| 2026-07-29 21:38 | 1.1 | completed | 事务迁移删除、`plan/copy` 路由与套餐订单计数已完成。 |
| 2026-07-29 21:38 | 2.1 | completed | 已新增替代套餐选择对话框及复制入口。 |
| 2026-07-29 21:38 | 3.1 | completed | PHPUnit 通过 6 tests/19 assertions；PHP 语法通过；前端打包构建通过，完整 `npm run build` 仍被无关礼品卡未使用变量阻断。 |
| 2026-07-29 22:14 | 审查修复 | completed | 增加 `v2_user.plan_id` / `v2_order.plan_id` 索引；删除锁定关联行，复制锁定末尾排序记录；前端补齐弹窗关闭与复制防重复提交保护。 |

---

## 执行备注

> Codex Phase1 未启动子代理：相关范围为 2 个一级域且远低于 Codex Phase1 的 4 个独立域及 80 个相关文件门槛，主代理并行读取已覆盖所需证据。

> UI 浏览器检查在本地 Vite 服务确认登录守卫无控制台错误；未提供可用管理端认证与本地 API，无法加载套餐数据页进行端到端操作验证。

> 审查遗留：当前 PHPUnit 使用轻量 `TestPlanRequest` 绕过 Laravel 请求验证，尚未覆盖真实路由中间件、并发数据库、异常回滚和迁移回滚场景；生产路径已通过事务、行锁与索引收敛并发风险。
