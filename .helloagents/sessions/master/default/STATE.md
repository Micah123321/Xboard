# 恢复快照

## 主线目标
为 `admin-frontend` 用户管理高级筛选新增“活跃状态”条件，并补齐对应后端复合过滤规则。

## 正在做什么
当前任务已完成，已补齐活跃 / 非活跃筛选与前后端联动，并完成前端构建验证。

## 关键上下文
- 高级筛选弹窗新增了 `activity_status` 字段，前端支持选择“活跃 / 非活跃”，默认无该条件即代表“全部”。
- 后端 `UserController::fetch()` 现支持 `activity_status=eq:1|0` 的复合规则：`plan_id` 非空、剩余流量大于 0、`last_online_at` 在近半年内即视为活跃。
- 已新增 `tests/Unit/Admin/UserControllerActivityStatusFilterTest.php` 覆盖值解析与 SQL 条件拼装，但当前环境缺少可执行 `php` 命令，尚未本机跑通该 PHPUnit 用例。
- 已完成 `admin-frontend` 的 `npm run build`，最新产物已写入 `public/assets/admin` 子模块。

## 下一步
当前任务已完成；如继续同一业务域，建议在具备 PHP 运行时的环境补跑 `UserControllerActivityStatusFilterTest`，并用真实后台登录态手动验证“高级筛选 → 活跃 / 非活跃切换”的结果集。

## 阻塞项
- 当前终端不存在 `php`

## 方案
`.helloagents/archive/2026-04/202604250018_admin-frontend-user-activity-status-filter/`

## 已标记技能
hello-ui, hello-verify
