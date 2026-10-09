# BEpusdt 支付插件

对接 [BEpusdt 官方 API](https://github.com/v03413/BEpusdt/blob/main/docs/api/api.md) 的 `POST /api/v1/order/create-order` 收银台模式，无需指定支付网络。

## 安装与配置

1. 部署本目录及本次 Guest PaymentController 回调适配。在管理后台插件管理中安装并启用 BEpusdt（插件代码 `bepusdt`）。使用常驻 PHP/Octane 进程时按现有部署流程重载。
2. 在支付配置中新增支付方式，选择 `BEpusdt`，填入 API 根地址、API Token、法币、支付币种及付款返回地址，并启用该支付方式。
3. API 地址示例为 `https://pay.example.com`，不要附加 `/api/v1/order/create-order`。Token 使用 BEpusdt 的 API Token。HTTPS 始终校验证书；证书问题应修复证书链或服务器 CA 配置。连接超时 10 秒、请求超时 30 秒，不自动重试或跟随 HTTP 重定向。
4. 法币默认 `CNY`，支持 `CNY/USD/EUR/GBP/JPY`，应与本站订单计价币种一致；插件不做换汇。支付币种默认 `USDT`，支持 `USDT,USDC`，留空不限制，`-ETH,-BNB` 表示排除。
5. 返回地址必填完整 URL。Micah 示例：`https://用户前端域名/dashboard/finance/orders`，请替换为实际域名。该配置覆盖公共 PaymentService 的 hash 回跳路径。返回页面仅用于展示，不作为付款成功依据。
6. 回调地址由 PaymentService 自动生成：`/api/v1/guest/payment/notify/BEpusdt/{支付方式UUID}`，可通过支付方式的通知域名设置覆盖域名。确保网关可直接 POST 到此地址，不被登录页、重定向或 WAF 拦截。

## 金额、回调与重试

- 下单金额是传入的含手续费总分数除以 100，不重复加手续费；零金额直接报错，避免进入任意金额到账模式。
- 签名排除 `signature`、null 和空字符串，保留 0/false；按参数名 ASCII 排序，原值以 `key=value` 和 `&` 拼接，末尾直接追加 Token，再取小写 MD5。
- 回调验证签名、本地订单、支付方式 ID、BEpusdt 渠道，以及 `amount` 是否等于本地订单金额加手续费；带 `fiat` 时还校验法币。`actual_amount` 是币数量，不用于法币对账或金额快照。
- `status=1/3` 验证通过后仅返回 `success`，不发货、不取消本地订单；仅 `status=2` 进入付款处理。未知状态返回失败。已取消订单不自动重新开通，需要人工核对。
- 成功回调在数据库事务内锁定订单并重新核对金额和渠道。已完成/已折抵订单重复通知直接应答；其他未完成的非待付状态返回失败，不伪装成已发货。同步发货失败会回滚本次数据库修改并返回非成功应答，后续通知可以重试。
- 应答使用上述官方 API 文档规定的 `success`；不使用其他 SDK 或旧通知文档中的 `ok`。
- 事务保护依赖支持事务与行锁的数据库（生产建议沿用项目 MySQL/InnoDB）。第三方钩子若发送外部消息等非数据库副作用，应自行保持幂等。

## 验证

```sh
php vendor/bin/phpunit --bootstrap vendor/autoload.php tests/Unit/BepusdtPaymentTest.php tests/Unit/Orders/OrderServicePaymentSnapshotTest.php
```

聚焦测试使用 SQLite 内存数据库、模拟网关 HTTP 与同步发货任务，覆盖官方签名样例、下单含费金额、TLS 选项、异常响应、签名/金额/渠道错误、等待/超时、成功/重复通知、事务失败回滚与重试。测试不读取生产配置或发起真实付款。真实网关到账、证书链、前端浏览器回跳和 MySQL 多连接并发尚需部署环境验证。
