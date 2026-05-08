# 任务清单: admin-temporary-user-traffic

> **@status:** completed | 2026-05-09 00:25

```yaml
@feature: admin-temporary-user-traffic
@created: 2026-05-08
@status: completed
@mode: R2
```

## LIVE_STATUS
```json
{"status":"completed","completed":8,"failed":0,"pending":0,"total":8,"percent":100,"current":"已归档到 archive/2026-05","updated_at":"2026-05-09 00:25:34","skipped":0,"uncertain":0,"done":8}
```

## 进度概览

| 完成 | 失败 | 跳过 | 总数 |
|------|------|------|------|
| 8 | 0 | 0 | 8 |

---

## 任务列表

### 1. 数据模型

- [√] 1.1 新增用户临时流量字段
  - 作用范围: `database/migrations/*_add_temporary_transfer_enable_to_v2_user_table.php`, `app/Models/User.php`
  - 预期变更: 为 `v2_user` 增加 `temporary_transfer_enable` 字段，模型文档补充字段语义。
  - 完成标准: 新迁移可被 Laravel 识别，字段默认值为 0，单位为字节。
  - 验证方式: `php artisan migrate:status` 或静态检查迁移语法。
  - depends_on: []

### 2. 后端业务规则

- [√] 2.1 封装管理员临时流量分配服务
  - 作用范围: `app/Services/UserService.php`
  - 预期变更: 新增方法在事务中增加 `transfer_enable` 与 `temporary_transfer_enable`，拒绝非正数额度。
  - 完成标准: 分配 50G 后当前总额和临时额度都增加 50G。
  - 验证方式: 单元测试覆盖服务方法。
  - depends_on: [1.1]

- [√] 2.2 调整重置、订单和套餐同步路径
  - 作用范围: `app/Services/TrafficResetService.php`, `app/Services/OrderService.php`, `app/Http/Controllers/V2/Admin/PlanController.php`, `app/Services/UserService.php`
  - 预期变更: 流量重置扣除并清空临时额度；套餐重写用户基础流量时清空临时额度。
  - 完成标准: 临时额度不会进入新套餐或新周期。
  - 验证方式: 单元测试覆盖重置扣除、订单开通清空、force_update 清空。
  - depends_on: [1.1]

### 3. 管理端接口

- [√] 3.1 增加管理端分配一次性流量接口
  - 作用范围: `app/Http/Requests/Admin`, `app/Http/Controllers/V2/Admin/UserController.php`, `app/Http/Routes/V2/AdminRoute.php`
  - 预期变更: 新增请求校验、控制器方法和 `user/assignTemporaryTraffic` 路由。
  - 完成标准: 接口接收用户 ID 和 GB 数量，返回更新后的总额度和临时额度。
  - 验证方式: 静态检查路由和请求校验；后端测试覆盖核心服务。
  - depends_on: [2.1]

### 4. 管理端前端

- [√] 4.1 增加前端类型、API 和用户操作
  - 作用范围: `admin-frontend/src/types/api.d.ts`, `admin-frontend/src/api/admin.ts`, `admin-frontend/src/views/users/useUsersManagement.ts`, `admin-frontend/src/views/users/UsersView.vue`
  - 预期变更: 用户列表类型包含临时流量字段；新增 API 调用；行操作增加“分配流量”。
  - 完成标准: 点击用户操作可打开一次性流量弹窗并成功刷新列表。
  - 验证方式: `npm run build --prefix admin-frontend`。
  - depends_on: [3.1]

- [√] 4.2 增加一次性流量分配弹窗组件
  - 作用范围: `admin-frontend/src/views/users/UserTemporaryTrafficDialog.vue`
  - 预期变更: 提供 GB 输入、提示临时流量不会继承到重置/升级后、提交加载和错误反馈。
  - 完成标准: 表单校验阻止非正数，提交成功后关闭并刷新。
  - 验证方式: `npm run build --prefix admin-frontend`。
  - depends_on: [4.1]

### 5. 验证与知识库

- [√] 5.1 增加单元测试
  - 作用范围: `tests/Unit`
  - 预期变更: 增加临时流量分配、流量重置扣除、订单开通清空临时额度测试。
  - 完成标准: 相关测试能独立运行并断言核心业务规则。
  - 验证方式: `vendor/bin/phpunit tests/Unit/UserTemporaryTrafficServiceTest.php tests/Unit/TrafficResetTemporaryTrafficTest.php tests/Unit/Orders/OrderServiceTemporaryTrafficTest.php`
  - depends_on: [2.1, 2.2]

- [√] 5.2 验证并同步知识库
  - 作用范围: `.helloagents/modules`, `.helloagents/CHANGELOG.md`, 当前方案包
  - 预期变更: 记录用户临时流量行为、执行验证命令、更新任务状态。
  - 完成标准: 方案包任务状态完整，知识库反映本次行为变更。
  - 验证方式: `python -X utf8 ~/.codex/helloagents/scripts/validate_package.py --path E:\code\php\Xboard-new 202605082348_admin-temporary-user-traffic`
  - depends_on: [4.2, 5.1]

---

## 执行日志

| 时间 | 任务 | 状态 | 备注 |
|------|------|------|------|
| 2026-05-08 23:48:00 | DESIGN | in_progress | 已确认方案 A 并创建方案包 |
| 2026-05-09 00:08:00 | 1.1-4.2 | completed | 已完成迁移、后端业务规则、管理端接口和前端弹窗入口 |
| 2026-05-09 00:13:00 | 5.1 | completed | 定向 PHPUnit 通过：6 tests, 11 assertions |
| 2026-05-09 00:18:00 | 5.2 | completed | PHP 语法检查、定向 PHPUnit 与 admin-frontend 构建通过，知识库已同步 |
| 2026-05-09 00:19:00 | 方案包校验 | completed | validate_package.py 通过，8/8 任务完成 |

---

## 执行备注

- 当前工作树存在与本任务无关的未提交改动，开发实施阶段只做定点修改，不回滚他人改动。
