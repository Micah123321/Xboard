# AI 充值生产发布记录

2026-10-25，Asia/Shanghai。版本 ai-topup-20261025-2050。完成实现、独立 review、问题修复和复验后发布；生产浏览器验收通过，测试会话已撤销。

## 生效行为

人民币购买 AI 中转美元面额，整数 USD 1 至 1000000；采用用户确认的整单阶梯价格，共 9 档。默认 200 USD 面额需支付本金 30 元。单价由 0.150 CNY/USD 至 15000 USD 档的 0.105 CNY/USD，订单保存下单时价格和规则快照。

复用现有 Xboard 支付配置和验签插件。独立充值订单以 AIT 开头，付款成功自动生成余额兑换码，与订阅套餐订单及免费 AI 福利活动分别管理。新充值开关已开启，原 AI 福利活动仍开启，桥接配置保持原有设置。mi-node、sub2api 和桥接插件无需本次重新部署。

赠流量按实付本金的 1.5% 价值，以 10 GiB/CNY 换算，手续费排除：30 × 1.5% × 10 = 4.5 GiB = 4831838208 字节。未激活赠流量长期保留，每单可启用一次；须有有效订阅，启用后进入现有临时流量，下次流量重置时清除。订阅套餐和有效期不因激活变更。

## Review 与修复

- Coinbase 仅确认结算事件和最终 COMPLETED 状态通过；保留精确 CNY 金额，稳定 charge ID 用于收款凭据。合法的创建、待确认事件不发码。
- BTCPay 同时验证已签名 InvoiceSettled 事件及 API 返回 Settled，核对原 CNY 金额。CoinPayments 核对已签名原始请求的成功状态和 CNY 金额，保留完整充值回跳地址。
- 所有充值回调要求绑定渠道和精确本金加手续费证据；迟到、已取消单的真实到账仍履约。充值仅展示已适配金额/状态证据的既有 CNY 渠道，普通订阅渠道保持现有行为。
- 创建 UUID 持久化恢复；收银台每单仅认领一次网关请求并加密缓存结果，重复请求返回同一收银台。网关结果不明时保留查询/取消路径。不同收款凭据保留并提示核对，余额码和赠流量均保持一次。
- 修复激活后兑换码消失和发码轮询停止，支付方式切换清除旧二维码，刷新恢复并锁定绑定渠道，手机充值页帮助浮层避开付款按钮。
- 最终独立复查未发现指定范围内可确认的 P1/P2 回归。

## 验证证据

PHP 回归 246 项、2107 条断言通过，覆盖新充值、支付证据适配、原 AI 福利、原订阅和 BEpusdt。用户前端类型检查、7 项实质工具测试、两端生产构建通过。

真实 MySQL 8 隔离库每阶段 16 进程并发，五阶段分别验证创建、收银台、回调、流量激活和发码。最终订单 1、网关认领 1、队列派发 1、提供方 POST 1、赠流量 4831838208 字节；测试不使用生产库，临时容器和网络已清理。

隔离端到端采用真实 Laravel 路由、原 EPay 插件签名、Fastify 桥接及 HTTP 提供方，覆盖支付回跳、自动发码、复制、重复/迟到回调、单次激活和套餐不变。浏览器测试未模拟业务 API。额外前端场景覆盖响应丢失后刷新恢复、已绑定渠道、二维码/表单、发码中激活、明暗主题和桌面/手机。

生产实际页面七组浏览器检查全部通过，控制台错误 0、接口错误 0。验证公开域名、原后台及独立管理端、报价九档、创建/取消未付款单、原支付渠道和台账过滤。视口 1440×1000、390×844、1440×1080；截图已亲自查看。生产未执行真实扣款，完整到账发码与权益测试在隔离链路完成。临时测试账号、令牌和未付订单已删除，剩余测试用户/令牌均为 0。

入口：

- 用户：https://spkun.cc/dashboard/finance/ai-topup
- 原后台：http://152.53.48.209:7001/adminadmin#/marketing/ai-topup
- 独立管理端：http://152.53.48.209:7002/assets/admin/#/marketing/ai-topup

本地验收报告与截图位于 output/ai-topup-production-20261025/browser-evidence/；隔离真实链路证据位于 output/ai-topup-e2e/browser-evidence/。

生产 Horizon 已运行；每分钟 ai-topup:reconcile 已列入 scheduler。原福利桥接过期 canary 查询通过，没有为发布创建新的可兑换余额。

## 部署与恢复

生产发布目录 /root/Xboard-new/.deploy/ai-topup-20261025-2050。数据库备份 backup/xboard-before-ai-topup.sql.gz 为 434437444 字节，gzip 完整性验证通过；同目录备份原 Compose、六服务镜像 ID、公开用户静态包、TokenPay 和 nginx 配置。

仅应用新增表迁移 2026_10_25_000002_create_ai_topup_tables.php，更新 web/horizon/scheduler/ws-server/admin/user 六服务及公开用户静态目录 /root/nginxhome/vue-xboard-theme-micah。TokenPay 为宿主机挂载插件，已同步本次回跳适配。PHP 镜像同时包含管理员 assets 与 manifest，原后台入口已实测；既有 nginx API 上游未改动。

| 组件 | 镜像 | SHA-256 |
| --- | --- | --- |
| PHP 四服务 | xboard:ai-topup-20261025-2050 | ae856fdba31af9b6890efacffd5bde36a3d53ede8b7671997a917df603356a4d |
| Admin | xboard-admin:ai-topup-20261025-2050 | e38b25090fd41654ed7f6e586468b5e734ca16d211b696feed45235a93d9e20e |
| User | xboard-user:ai-topup-20261025-2050 | 2dd4bcd7c03b030dc3b5c7d34b3192b2c57a381cf583b982b7f9e690473d2352 |

最终后端包 SHA-256 65c0d1f03494a96563f1000ad12788e74c21c9431f58cad79f859f460804007c；管理员包 3fea0411df731ca26c731830bfc11bf0bb70d17f5e9eb5a8efa3a592be257aa6；用户包 61bdd760bc4bca4baab8a57f1f78f3c0ea6accd98e37924cf5c234ac3d18abb5。SFTP 上传后回读校验通过。

回滚脚本 rollback-production.py 已保存在发布目录：关闭新充值、恢复原镜像/Compose、TokenPay 和公开静态入口。回滚保留充值订单、真实付款、兑换码、已启用流量及提供方永久引用；不会回滚业务数据库。线上新入账以后，历史数据库备份仅作灾难恢复材料，不能覆盖新到账记录。
