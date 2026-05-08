# user-temporary-traffic

## 职责

- 管理管理员在用户管理中分配的一次性临时流量
- 隔离套餐基础流量与管理员临时补偿流量，避免临时额度进入新套餐或新周期
- 维护用户当前总额度 `transfer_enable` 与临时额度来源字段 `temporary_transfer_enable` 的一致性

## 行为规范

- `v2_user.temporary_transfer_enable` 以字节记录管理员分配的本期临时流量，默认值为 0。
- `transfer_enable` 仍作为系统当前可用总流量的物化字段；管理员分配临时流量时，`transfer_enable` 和 `temporary_transfer_enable` 必须同时增加。
- `UserService::assignTemporaryTraffic()` 是管理端分配临时流量的后端入口，必须在事务和行锁中更新用户，防止并发分配覆盖。
- `TrafficResetService::performReset()` 执行套餐流量重置时，必须在事务内锁定并刷新用户行，再从最新 `transfer_enable` 扣除 `temporary_transfer_enable`，然后清空 `temporary_transfer_enable` 与 `u/d`，确保临时流量只在当前周期有效且不会被旧模型覆盖。
- `OrderService` 在套餐升级、新购、续费写入套餐基础流量和一次性套餐购买时，必须清空 `temporary_transfer_enable`，避免旧临时额度滚入新套餐。
- `UserService::setPlanForUser()`、`UserService::assignPlan()`、`UserService::setTryOutPlan()` 写入套餐基础流量时也必须清空 `temporary_transfer_enable`。
- `PlanController::save(force_update)` 强制同步套餐参数给用户时，必须同步把 `temporary_transfer_enable` 置 0。
- 礼品卡、订单一次性流量包和其他已有流量奖励语义不在本模块中重构；本模块只处理管理员在用户管理处分配的临时流量。

## 依赖关系

- 依赖 `database/migrations/2026_05_08_235000_add_temporary_transfer_enable_to_v2_user_table.php` 提供临时额度字段。
- 依赖 `app/Services/UserService.php` 封装临时流量分配和清空逻辑。
- 依赖 `app/Services/TrafficResetService.php` 在套餐重置时移除临时额度。
- 依赖 `app/Services/OrderService.php` 在套餐开通路径清空临时额度。
- 依赖 `app/Http/Controllers/V2/Admin/UserController.php`、`app/Http/Requests/Admin/UserAssignTemporaryTraffic.php` 和 `app/Http/Routes/V2/AdminRoute.php` 暴露管理端接口。
- 依赖 `admin-frontend/src/views/users/UserTemporaryTrafficDialog.vue`、`UsersView.vue`、`useUsersManagement.ts`、`src/api/admin.ts` 和 `src/types/api.d.ts` 提供管理端操作入口。

## 验证

- `php -l` 覆盖新增迁移、请求类、控制器、路由和相关服务文件。
- `vendor/bin/phpunit --bootstrap vendor/autoload.php tests/Unit/UserTemporaryTrafficServiceTest.php tests/Unit/TrafficResetTemporaryTrafficTest.php tests/Unit/Orders/OrderServiceTemporaryTrafficTest.php`
- 2026-05-09 审查补测: `vendor/bin/phpunit --bootstrap vendor/autoload.php tests/Unit/TrafficResetTemporaryTrafficTest.php tests/Unit/UserTemporaryTrafficServiceTest.php tests/Unit/Orders/OrderServiceTemporaryTrafficTest.php` 通过，覆盖重置时旧模型与锁定模型不一致场景。
- `npm run build --prefix admin-frontend`
