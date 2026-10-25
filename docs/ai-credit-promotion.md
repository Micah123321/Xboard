# AI 中转福利活动

## 行为与业务口径

- 套餐标称 GB × 0.05 USD；200/500/1000/3000/8000 GB 分别赠送 10/25/50/150/400 USD。
- 每笔完成开通且有付款凭据的新购、续费、升档订单独立领取一次。年付每单赠一次；升档按目标套餐全额赠送；以 GB 大小比较升档。
- 实付严格大于 1500 分；不包含手续费、优惠或旧套餐折抵。本次外部支付的人民币订单本金使用 total_amount；include_balance 默认 false，后台开启后另计 balance_amount。原始支付回调可能使用其他币种，不直接当人民币门槛。
- 免费、后台手工开通、流量重置包、降档和同档切换不产生新资格。每笔权益保留生效时的套餐 GB、金额、计价规则与实付口径快照。
- 补领范围仅为以下并集：北京时间 2026-10-20 00:00:00 起（含当日、按 paid_at 支付时间）的合格已完成订阅订单，以及现有有效订阅对应的最新合格来源订单。近期订单按订单逐笔一次，即使该用户当前订阅已过期也可纳入；早于该日期的订单只允许当前有效订阅补一次，不追溯更早的续费历史。同一来源订单重合时只建立一条资格。
- 首次启用仍冻结待补订单的上界；上线后新订单经订单开通事务建立资格。先续费/升级时，在事务内先保存当前旧订阅资格。已评估订单不再混入补领；用户刷新每次最多准备 100 条自己的记录，多次刷新可续接。后台批量执行严格按预览订单 ID 和批次上限处理。
- 活动暂停后停止新增领取及恢复任务中的新发码；已生成的兑换码可继续查询。结果不确定时保留同一业务编号，先查询原结果。

## 项目与入口

| 范围 | 入口 |
| --- | --- |
| Xboard 后端 | app/Services/AiCredit/AiCreditService.php |
| 用户前端 | Micah：/dashboard/finance/ai-credit |
| 管理员 | 营销管理 → AI 中转福利：#/marketing/ai-credit |
| 插件 | account-batch-tester：/api/integrations/xboard/rewards |
| sub2api 后端 | /api/v1/admin/redeem-codes/external-rewards |

默认用户入口：

- 站点：https://codex.ccbvv.com/
- 模型及价格：https://codex-agent.ccbvv.com/pricing
- 公开缓存率：https://codex-agent.ccbvv.com/transparency

mi-node 无需变更。该福利不改变代理流量计费、重置或节点鉴权。

## 部署与启用顺序

本次完成代码、构建和隔离验证，未执行生产迁移、生产发码或线上部署。

1. 更新 sub2api Go 后端。启动迁移需要包含 229_redeem_external_rewards.sql；它创建永久业务编号绑定，并保护兑换码身份与面额。
2. 更新插件服务端，设置 XBOARD_REWARDS_TOKEN 和 XBOARD_REWARDS_ADMIN_API_KEY。前者使用至少 32 位随机 token 字符，后者为独立管理员 API Key。Compose 已透传这两项变量；现有容器需要重建以读取新配置。
3. 更新 Xboard 后端，按部署流程备份后执行指定增量迁移：

~~~sh
php artisan migrate --path=database/migrations/2026_10_25_000001_create_ai_credit_tables.php --force
~~~

4. 更新管理员与 Micah 用户前端产物。管理员生产构建使用仓库既有构建命令；本次为保留原有 public/assets/admin 修改，将验收构建输出在 admin-frontend/dist-ai-credit-review。用户生产产物在 Micah 仓库 dist。
5. 管理员打开“营销管理 → AI 中转福利”，填写插件根 URL 及与插件一致的桥接 Token，保留比例 0.05 和门槛 1500。空套餐列表表示全部套餐；余额抵扣是否统计按经营口径显式配置。
6. 确认配置后开启活动及历史补领。预览仅列出 2026-10-20 起的合格订单和现有有效订阅的补领记录，不包含全历史；先预览再分批执行，用户进入福利页也会准备自己的记录。此过程仅建权益，不提前批量生成兑换码。
7. 保持现有 Laravel Scheduler 正常运行；新增 ai-credit:reconcile 每分钟恢复未完成请求并同步已发码状态。可以手动只读检查：

~~~sh
php artisan ai-credit:reconcile --dry-run
~~~

配置中的 provider_ready 仅表示本地桥接 URL 与凭据已配置，实际服务连通性和上游授权仍以领取或核对结果为准。桥接使用服务端专用凭据，不依赖浏览器登录会话。

## 一致性与恢复

- Xboard 使用 order_id 唯一约束、legacy_user_id 唯一约束；数据库锁序为用户 → 订单 → 权益。权益与订单开通同事务，不在事务内进行远程请求。
- 外部编号为“持久实例 UUID:order:订单 ID”。同一订单重建权益时仍使用同一编号；活动版本修改不改变去重范围。
- Go 在 PostgreSQL 事务中按编号加锁，将兑换码与永久业务编号一起提交；同编号同金额返回原码，异金额返回 409。
- PHP 发码前保存处理租约；超时后进入 reconciling。查询和重试始终使用同一编号及冻结金额。旧地址异常记录退避，避免占满自动恢复批次。
- 生成后的码加密保存；Token 加密保存且不回显，管理员请求日志隐藏 bridge_token。台账与列表不返回码，仅用户本人详情接口返回；相关响应 no-store。
- 已发放未兑换的码可在后台停用，远端作废成功后同步本地状态。处理中先核对；已兑换余额不自动扣回。
- 停用活动应保留台账与持久实例 ID。备份恢复需同时保留数据库和原 APP_KEY；已有发码记录后不应通过回退迁移清空台账。服务 URL 变更后，未确认的旧请求需要先恢复原服务查询，避免转向另一上游重新发放。

## API

用户接口位于 /api/v1/user/ai-credit，沿用用户登录鉴权：

| 方法 | 路径 | 作用 |
| --- | --- | --- |
| GET | /overview | 只读汇总、活动信息、计数与近期记录 |
| GET | /records | 服务端分页；current、page_size、kind=available/history、可选 status |
| POST | /refresh | 准备当前用户最多 100 条近期订单及现有有效订阅补领资格并返回汇总 |
| POST | /claim | grant_id，领取一次；重复返回原结果 |
| GET | /detail | grant_id，查看本人的码并查询上游状态 |

管理员接口位于 /api/v2/{secure_path}/ai-credit，沿用管理员鉴权与日志：settings（GET/POST）、records（GET）、reconcile、revoke、legacy/preview、legacy/backfill（POST）。

桥接 POST /api/integrations/xboard/rewards 接受 external_ref 和十进制字符串 amount_usd；GET /:external_ref 返回原结果；POST /:external_ref/expire 使用空请求体。认证为专用 Bearer Token。金额范围 0.01–1,000,000 USD，最多两位小数。上游接口用独立 x-api-key，普通用户浏览器不会接触这些凭据。

## 验证与复现

~~~sh
# Xboard：隔离 SQLite，含真实订单开通、历史补领、金额门槛、恢复与作废
php vendor/bin/phpunit --bootstrap vendor/autoload.php tests/Unit/AiCredit tests/Unit/Orders

# 插件：认证、契约、错误、金额、重放、作废
npm test -- tests/xboard-rewards.test.ts
npx tsc -p tsconfig.server.json --noEmit

# 从插件目录启动跨进程测试：真实 PHP HTTP 客户端 → 真实插件 → 隔离 HTTP 上游
node --import tsx tests/verify-xboard-php.mjs <Xboard绝对路径>

# sub2api backend：永久发码与原子作废
go test ./internal/service ./internal/repository ./internal/handler/admin ./internal/server/routes -run ExternalReward -count=1
~~~

已验证：日期范围调整后 PHP 143 项、989 条断言（含北京时间边界、近期过期订单、旧有效订阅例外与并集去重）；插件 75 项；Go 聚焦测试；PHP 与真实 Fastify 的网络烟测；Micah 类型检查、57 项单测及生产构建；管理员生产构建。

浏览器验证使用隔离 API 夹具，没有真实发码：1440×1000 用户桌面、390×844 用户移动、明暗主题、领取/复制/重复查看、107 条可领记录分页、筛选、失败重试、卸载取消；管理员 1440×1080/390×844 配置、筛选分页、核对、停用业务错误、补领预览/确认/取消/重复执行。用户当前生产预览 http://127.0.0.1:5176/dashboard/finance/ai-credit，管理员验收临时 URL 与截图由 admin-frontend/tests/ai-credit.browser.mjs 输出。

截图：Micah output/ai-credit-verification；管理员 admin-frontend/dist-ai-credit-review/browser-evidence。

真实 PostgreSQL 16 路并发与兑换/作废竞态用例已编写，但本机缺少 Docker，容器集成测试跳过；未将 SQL mock 或 HTTP 夹具结果视为真实 PostgreSQL 并发实测。Xboard 的 MySQL 双连接并发也未在本机实测。
