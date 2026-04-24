# order-payment

## 职责

- 负责订单支付成功后的支付快照保存，包括支付渠道、支付方法、实付金额与支付 IP
- 维护第三方支付回调到订单支付完成的元信息透传链路
- 为后台订单详情提供可追溯的支付成功信息，而不是只依赖当前支付配置

## 行为规范

- `app/Http/Controllers/V1/Guest/PaymentController.php` 负责接收第三方支付回调，并把验签通过后的支付元信息传入 `OrderService::paid()`
- `OrderService::paid()` 会在订单转为 `开通中` 之前写入支付快照；若第三方字段缺失，则回退到当前 `payment` 关联信息
- `payment_amount` 统一按“分”存储，前端继续复用订单金额格式化链路展示
- 后台 `app/Http/Controllers/V2/Admin/OrderController.php` 的详情接口必须加载 `payment` 关联，供旧订单或人工标记支付时做展示回退
- `plugins/TokenPay/Plugin.php` 当前会优先从回调中提取 `Id / OutOrderId / ActualAmount / IP / Method` 等字段；缺失时允许只返回基础单号，不得阻断支付成功链路

## 依赖关系

- 依赖 `app/Models/Order.php` 与 `app/Models/Payment.php` 提供订单和支付配置模型
- 依赖 `app/Services/OrderService.php` 执行支付成功状态转换与快照落库
- 依赖 `plugins/TokenPay/Plugin.php` 提供第三方支付回调字段映射
- 依赖 `admin-frontend/src/views/subscriptions/OrderDetailDrawer.vue` 与 `src/utils/orders.ts` 展示后台支付成功信息

## 已知限制

- 当前工作区缺少 PHP 运行时与 `vendor`，本地无法直接运行 Laravel / PHPUnit 验证，只能完成代码级检查与前端构建验证
- 历史订单不会自动补写新增支付快照字段，仅对本次改动上线后的新支付成功订单逐步生效
