# 交易所、钱包及通用服务覆盖

本轮补充服务识别覆盖，只在本地修改、构建和验证。没有新增迁移或节点程序改动，未发布线上。

## 问题与修复

原GeoSite快照只导入37个服务组，遗漏了上游已有的OKX、OneKey和加密货币类别。少量内置规则也未覆盖推送、SDK统计及网络工具，因此真实可识别域名被保存为unknown。不是HTTPS加密使已知域名失去归属，而是导入的数据集和服务分类范围不完整。

现快照72个服务组、11451条suffix/full规则，新增crypto、finance、tools、push、telemetry、social、games、shopping、productivity、education分类。具体命名服务先于宽泛类别，同一域名仍保留域名标签边界验证。分类筛选使用后端LABELS作为校验依据，页面类别与报告同步增加。

采用已核验的v2fly/domain-list-community官方源码包（MIT），422个实际依赖文件，下载上限从256调整为512，单文件和总索引容量限制保持原值；域名查找仍为域名标签数复杂度且不联网。共享广告与遥测域名不再冒充其他平台，但经官方文档核实的SDK端点可单独识别为telemetry。

## 截图样本与证据

| 目标 | 结果 | 来源 |
| --- | --- | --- |
| static.okx.com / www.okx.com | OKX交易所及静态资源，crypto，高可信度 | https://tr.okx.com/en ，页面引用static.okx.com图像和内容资源 |
| static.oklink.com / www.oklink.com | OKLink区块链浏览器及静态资源，crypto，高可信度 | https://www.okx.com/help/how-to-check-the-withdrawal-status-on-the-blockchain |
| data.onekey.so | OneKey加密货币钱包，crypto，高可信度 | https://onekey.so/ |
| utility.onekeycn.com | OneKey钱包API，crypto，高可信度 | https://github.com/OneKeyHQ/app-monorepo/blob/a8ef4730/packages/shared/src/config/appConfig.ts |
| uni.onekey-asset.com | OneKey钱包资源，crypto，高可信度 | 同上，官方源码中API host及资源URL |
| www.itdog.cn | ITDOG网络测速与诊断工具，tools，高可信度 | https://www.itdog.cn/ |
| api.onesignal.com | OneSignal消息推送，push，高可信度 | https://documentation.onesignal.com/reference/rest-api-overview |
| toblog.tobsnssdk.com | BytePlus SDK日志与统计上报，telemetry，高可信度 | https://docs.byteplus.com/en/docs/data-intelligence/Data_Reporting_URL |

OKLink是区块链浏览器、OneKey是钱包，不将它们标记为交易所。crypto分类表示加密货币相关服务归属，不意味着用户已交易、转账或持有数字资产。域名规则依据附官方来源，且理由长度验证适配数据库255字符列。

同时导入金融支付、证券银行、开发工具、通讯、社交、游戏、电商和教育等类别，例如Binance、Coinbase、Bybit、Trust Wallet、PayPal、TradingView、Speedtest、PyPI、Discord、Instagram、Epic Games、eBay、Notion和扇贝均有回归样本。AI、网盘、媒体和原有未知形态提示继续保留。

## 仍保留未知的样本

- hiadwwa.003939.xyz：没有核实到可靠的服务归属，未从hiad前缀臆测成华为广告。
- 17.253.84.125：苹果官方资料确认17.0.0.0/8为Apple网络，https://support.apple.com/en-sa/101555 ，该范围包含多种业务，未将整个网段识别成推送或视频。
- 194.221.250.50：仅IP网络归属不足以确定具体应用，仍保留unknown。网络所有者、共同CDN或同用户邻近记录不会被当成具体App证据。

## 发布与更新

发布后端与admin-frontend/dist-access-service-coverage管理员资源，按既有流程重载配置、应用和队列。内置快照可直接使用，新报告按新规则处理。已有记录的分类已落盘，需要一次重分类才能同步旧行、汇总和筛选：

~~~bash
php artisan access-traffic:reclassify
~~~

命令500条一批，支持--after-id续接，不修改原始量、计费量、倍率或用户账户字段。后续手动更新上游使用php artisan access-traffic:sync-domains；每周调度继续有效。无新增数据库迁移或节点升级要求。

## 验证

后端87项690断言通过，涵盖截图样本、域名伪后缀、20类筛选、相同分组/汇总、4次SQL、历史分页重分类与不变计费，以及原有AI/IP/行为线索和子节点倍率回归。管理端类型检查及独立生产构建通过。Chrome16项隔离API交互全部通过、零页面异常、零未处理API，新增四类筛选验证及服务覆盖截图。浏览器验收与截图位于admin-frontend/dist-access-service-coverage/browser-evidence，使用本地构建和隔离API，线上仍需发布后核验。
