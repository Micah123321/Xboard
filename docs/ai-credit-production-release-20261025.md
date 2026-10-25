# AI 中转福利生产发布记录

发布日期：2026-10-25（Asia/Shanghai）。状态：全部组件已部署，活动已启用，真实页面与接口验收通过。

## 实际上线行为

- 套餐 GB × 0.05 USD；200 / 500 / 1000 / 3000 / 8000 GB 分别为 $10 / $25 / $50 / $150 / $400。
- 单笔实付严格大于 1500 分，不计手续费、折扣及旧套餐抵扣，当前配置不计余额抵扣。
- 自北京时间 2026-10-20 00:00:00 起，按支付时间纳入合格已完成订单；日期以前只允许当前有效订阅的来源订单补领一次。交集按订单去重。
- 新购、续费、真正升档逐订单一次；年付一次；升档按目标套餐 GB 计。历史升档必须具有更小 GB 的前序合格来源订单，排除降档、同档及缺失来源。
- 用户自行进入福利页准备资格并领取；本次仅执行管理员只读补领预览，没有全历史批量发码。

## 生产组件与入口

| 范围 | 已部署版本 / URL |
| --- | --- |
| Xboard HTTP、Horizon、Scheduler、WebSocket | xboard:ai-credit-20261025-1912-entry |
| 管理员静态站点 | xboard-admin:ai-credit-20261025-1912-entry |
| Micah 用户容器 | xboard-user:ai-credit-20261025-1912 |
| 公开 Micah 静态站点 | https://spkun.cc/dashboard/finance/ai-credit |
| 管理员独立入口 | http://152.53.48.209:7002/assets/admin/#/marketing/ai-credit |
| 管理员原始入口 | http://152.53.48.209:7001/adminadmin#/marketing/ai-credit |
| sub2api | sub2api:ai-credit-20261025-1912 |
| 插件 | sub2api-account-batch-tester:ai-credit-20261025-1912 |
| AI 站点 / 价格 / 透明度 | https://codex.ccbvv.com/ / https://codex-agent.ccbvv.com/pricing / https://codex-agent.ccbvv.com/transparency |

Xboard 的常规 Compose override 与 sub2api/插件的常规 Compose 已持久保存本次镜像。实际部署以当前生产镜像和源码为基线，只叠加本次活动文件，保留已有 plus/pool 提醒等功能。

公开域名原来使用 nginxWebUI 的独立挂载目录，并未经过 user 容器。已备份并更新同一主机上的该静态目录。两个用户站点原 API 代理绕行历史域名造成 502，已改为本机 7001，并通过 nginx -t 和平滑重载。上传服务代理保持原配置。

原管理员隐藏入口已补齐 Vite manifest、Vue app 挂载点和内嵌图标，独立静态入口与原入口均验证通过。

最终运行镜像 ID：

~~~text
Xboard PHP: sha256:4bddfce65356733341ca9300af508c534e1b54646f4de5e84a6d4b9da99b3c3d
Admin:      sha256:0eff4f0c14f4e6410986f4004017627db0b44ea99e3d19187a15e13d8624f419
User:       sha256:afef4928b1c11df677b98c4e84b1e11659e3ceae589ac3698d341d09920d20c7
sub2api:    sha256:79084f0f34d47b29cec151071b01c627bd5ab619af3471944cf971053e7dc168
Plugin:     sha256:a275e39912567523be3fecc5cde3f4860567aba6ca704443ddb48252e98ab7db
~~~

## 验证证据

- PHP 144 项、999 条断言；插件 75 项；Micah 57 项及类型检查、生产构建；管理员类型检查与生产构建；Go 聚焦测试在实际部署基线上通过。
- 临时 PostgreSQL 18 与 Redis 测试容器中，16 路并发永久编号绑定、重放与金额冲突，以及兑换提交/回滚、作废阻止陈旧兑换全部通过；未连接生产业务数据库。
- 生产 0.01 USD 测试码验证发码、相同编号返回原码、不同金额 409、作废；测试码已作废且未兑换。Xboard 经真实 HTTPS 桥接查询该作废状态通过。
- 实际生产页面使用真实 API、没有路由 mock：公开域名、用户直连、管理员独立入口和原始入口均通过；规则、汇总、链接及管理员只读预览通过。最终控制台错误 0，失败 HTTP 0。
- 桌面视口 1440×1000 / 1440×1080，手机视口 390×844。已亲自查看截图。手机另用全新会话验证，主内容 x=0、宽度 390，福利内容宽度 366；避免将桌面缩放过渡帧误作手机最终布局。
- Horizon 实际状态 running；Redis 共享缓存；Scheduler 心跳持续更新且实际 ai-credit:reconcile 有 DONE 日志；最终 dry-run Pending: 0。
- 两个临时验收 token 已撤销；无订单、无福利的空白测试用户已删除。凭据只在受限目录和加密设置中保存，发布说明无原值。
- 本次未单独执行 Xboard MySQL 双连接压力测试。数据库备份校验覆盖 gzip 完整性与 PostgreSQL 归档目录解析，未在生产库执行恢复演练。

本地证据目录：

~~~text
E:/code/php/Xboard-new/output/ai-credit-production-20261025/browser-evidence/report.json
E:/code/php/Xboard-new/output/ai-credit-production-20261025/browser-evidence/public-user-desktop.png
E:/code/php/Xboard-new/output/ai-credit-production-20261025/browser-evidence/user-fresh-mobile.png
E:/code/php/Xboard-new/output/ai-credit-production-20261025/browser-evidence/admin-original-entry.png
E:/code/php/Xboard-new/output/ai-credit-production-20261025/browser-evidence/admin-mobile.png
~~~

## 备份与应用回滚

以下回滚脚本已上传并通过 Python 语法检查，未实际执行。只回滚应用和入口，不恢复生产数据库、不删除迁移，不清除已经发放的兑换码或永久绑定。执行顺序为先停用并回滚 Xboard，再按需要回滚插件与 sub2api。

~~~sh
# Xboard 服务器
cd /root/Xboard-new/.deploy/ai-credit-20261025-1912
python3 rollback-xboard.py

# sub2api 服务器
cd /root/docker_server/sub2api-deploy/releases/ai-credit-20261025-1912
python3 rollback-sub2api.py
~~~

脚本使用发布前记录的镜像 ID 重新建立本地固定标签，避免依赖可变的远端标签。回滚保留新建的数据表和所有台账，不执行 migrate:rollback。当前已修复的用户 API 本机代理不会因应用回滚而恢复到历史失效域名。

Xboard 发布目录：/root/Xboard-new/.deploy/ai-credit-20261025-1912。

- backup/xboard-before-ai-credit.sql.gz：431446494 字节，gzip -t 通过，受限权限。
- backup/compose.yaml、backup/compose.override.yaml、runtime-before.json：原运行配置与镜像。
- backup/public-user-before.tar.gz：公开用户站点完整静态备份；回滚脚本覆盖恢复旧文件，保留新 hash 文件用于已有浏览器缓存。
- backup/nginx-public-before.conf：API 代理修复前配置，仅在需要回退入口时人工使用；其中没有在日志输出证书私钥内容。

sub2api 发布目录：/root/docker_server/sub2api-deploy/releases/ai-credit-20261025-1912。

- backup/sub2api-before-ai-credit.dump：153986649 字节，pg_restore --list 通过。
- backup/*-runtime.json、backup/*-compose-*：原镜像、Compose 文件链及副本。
- main-stage / agent-stage：基于生产原版本的本次源码；build-state.json、integration-run.log、deployment-success.json 保存构建与验收结果。

数据库备份仅用于明确恢复决策，应用回滚不要覆盖上线后的新订单、余额和兑换记录。既有 APP_KEY 与持久活动实例 ID 必须保留。
