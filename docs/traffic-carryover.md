# 订单剩余流量自动结转

购买升级套餐、续费、一次性套餐或重置流量包时，订单开通会将当前订阅未用完的流量自动转为临时额度。

## 计算和有效期

- 结转额度 = max(购买生效前 transfer_enable - u - d, 0)。有当前 plan_id 的用户参与结转，包含已到期后重新购买的订阅。
- 新合计额度 = 新套餐额度 + 结转额度 + 符合条件的首购赠送额度；已用上传和下载计数归零。
- 原临时额度已经包含在 transfer_enable 中，只结转合计额度的未用部分；已消费的临时额度不会再次赠送。
- 例如购买前剩余 13 GB，升级至 1000 GB，开通后套餐 1000 GB、临时 13 GB、合计和剩余 1013 GB。此后使用 3 GB，剩余为 1010 GB。
- 临时额度沿用既有重置规则，在下一次实际流量重置时清除；无周期重置的套餐按套餐有效期使用。再次购买时按最新未用余额重新结转。
- 续费沿用原到期时间顺延；升级沿用原升级规则计算到期时间；重置包只刷新流量，保留订阅到期时间。
- 新升级订单保留全额未用流量结转，按新套餐价格计价；优惠券、会员折扣照常生效，不再把同一余额同时折抵金额。发布前已经按金额折抵报价的订单保留原报价，开通时不再重复结转旧额度。
- 重置包开通时在用户行锁内复核当前套餐与订单套餐是否一致；套餐已切换的旧重置订单保持待处理，交由客服核对，避免覆盖新套餐额度和重置日程。

## 实现

后端 OrderService::open 在用户行锁和订单行锁内确认订单仍为 PROCESSING，保存剩余额度，执行流量重置和套餐开通，并在配置的重置事件后统一补回结转额度和首购奖励。额度与 COMPLETED 状态在同一事务提交，保存或重置失败时整体回滚，重复开通或支付回调不会再次结转。

开通后根据实际新套餐和到期时间重新计算 next_reset_at。TrafficResetService::checkAndReset 在取得用户行锁后复查是否到期，避免旧定时任务或旧用户访问快照清掉刚结转的额度；手动和订单重置仍可强制执行。

用户 info、getSubscribe 接口新增 temporary_transfer_enable 返回字段，单位为字节；transfer_enable 仍然是已经包含临时流量的合计额度。前端以 total - temporary 拆出套餐额度，不再将临时字段叠加到 total。首页和我的订阅展示套餐、临时、合计、已用和剩余流量，购买说明与重置包提示改为自动结转。

mi-node 无需改动：用户可用性由面板按 u + d < transfer_enable 筛选，节点按自己的流量计数基线持续上报增量。

## 验证

后端使用隔离 SQLite 内存数据库，98 项测试、754 条断言通过，覆盖升级、月付/季付续费、一次性与周期套餐互转、到期后重新购买、重置包、旧临时额度、耗尽、连续购买、重复回调、失败重试、接口字段、实际扣费与重复上报，以及旧自动重置快照在续费后继续执行的交错。

~~~powershell
php vendor/phpunit/phpunit/phpunit --bootstrap vendor/autoload.php --filter 'OrderService|UserTemporaryTraffic|TrafficResetTemporaryTraffic|TrafficBillingJob|GiftCardTrafficAccumulation|GiftCardRedemptionService' tests/Unit
~~~

Micah 用户端 5 个测试文件共 34 用例通过，类型检查通过，独立生产构建完成。浏览器使用本次生产构建和隔离模拟接口，验证桌面首页、订阅页、刷新后剩余额度、390px 手机布局、购买说明及重置后临时额度消失，共 6 项检查通过，页面脚本错误为 0。

~~~powershell
# 在 E:/code/vue/vue-xboard-theme-micah 中执行
npm run test:unit -- --run
npm run type-check
npm run build-only -- --outDir output/traffic-carryover-build

# 在后端项目中执行，复用现有浏览器验证工具
node output/traffic-carryover-2026-10-23/verify-browser.cjs
~~~

mi-node 的 internal/panel 和 internal/tracker 聚焦测试通过。未进行真实 MySQL 多连接并发验证和生产订单支付验证。

## 交付与上线范围

无新增数据库迁移。后端复用已存在的 temporary_transfer_enable 字段，上线环境应已有对应历史迁移。用户端构建保存在 E:/code/vue/vue-xboard-theme-micah/output/traffic-carryover-build；浏览器脚本、结果和截图位于当前项目 output/traffic-carryover-2026-10-23。

本次完成本地代码和验证，未部署生产。发布时需同时更新后端应用进程（含订单队列与调度进程）和用户前端资源。已经完成的历史订单仍保持幂等，不会因重复调用而回补；工单中估算的 13 GB 未写入真实用户账户，历史补偿应按订单与流量记录核实后单独处理。
