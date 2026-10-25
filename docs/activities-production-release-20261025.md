# 控制台活动发布与充值限额生产发布记录

2026-10-25，Asia/Shanghai。发布版本 activities-20261025。完成实现、独立 review、修复、回归与实际页面验收后上线。

## 生效内容

AI 充值本金按人民币限制为 5 至 2000 元，手续费另计。自定义面额保留整数 USD，并按既有整单阶梯价格换算。默认价格下可售 34 至 19047 USD，对应 5.10 至 1999.94 元；33 USD 为 4.95 元、19048 USD 为 2000.04 元，服务端均返回 422。每个阶梯独立计算有效区间，价格跳档中不满足本金范围的面额也逐单校验。旧订单沿用已保存快照和创建 UUID，既有待付单、真实到账履约及原订阅业务保留。

后台新增「营销管理 → 活动发布」，支持创建/编辑、草稿、发布/下架、排序、开始/结束时间、配图、权益条目、跳转按钮及内容预览；编辑使用版本检查，冲突保留未保存内容。定时活动仅在有效时间展示，到期自动隐藏。内置业务活动还跟随对应 AI 福利/充值开关，接口失败不回退到写死广告。

用户控制台在标题、日期欢迎语和当前订阅信息上方增加动态活动轮播，整合原底部邀请返利卡片。支持自动/手动切换、圆点、暂停、悬停/焦点/页面隐藏暂停、减少动态效果和手机滑动；没有当前活动时整体收起。当前已发布：

- 邀请好友，双方都有奖励：好友注册并首购套餐额外获得 10% 流量，系统自动发放，无需工单；邀请人获得首次购买金额 15% 返利，邀请人数与奖励总额不限。比例来自实际用户/全局返利规则，首购流量由原 OrderService 流程兑现。生产全局 15% 且首次购买开关已核实。
- AI 中转站额度免费送：跳转原 AI 福利领取页，条件与领取金额由原活动规则决定。
- 充值 AI 额度，再享流量赠送：跳转 AI 充值页，当前赠送比例 1.5%，动态读取设置。

入口：

- 用户控制台：https://spkun.cc/dashboard
- 原后台活动发布：http://152.53.48.209:7001/adminadmin#/marketing/activities
- 独立管理端：http://152.53.48.209:7002/assets/admin/#/marketing/activities
- AI 充值：https://spkun.cc/dashboard/finance/ai-topup

## 活动配图

三张图片均由 generate_codex_images 实际生成，模型 gpt-image-2.5-flare，分别为邀请礼盒、AI 晶体礼物、充值数据能量。请求 1600×800，网关实际返回 1774×887，已按实际 2:1 图片适配，没有宣称达到请求尺寸。原 PNG 保存在 output/activities-20261025/generated，WebP 保留实际尺寸以减少传输：

| 图片 | WebP 字节 | SHA-256 |
| --- | ---: | --- |
| invite-rewards.webp | 57354 | 8c94f6fe5d85d571a47dd1ab3d917c79baa93d9407b5b2ec4b3f2b5e083b7643 |
| ai-credit-gift.webp | 41948 | 013ad8146123eff6bde01dd3ddf978d2bc0965430f4ddc2d1519ab8a5f10b112 |
| ai-topup-traffic.webp | 53688 | 7123606838b97155d3aa0e8c7a0b7cd2fc50d2fb12e580355dbfebab212f98e4 |

用户静态 /activities/ 与两种后台 /assets/admin/activities/ 均包含真实图片。管理编辑保留存储的通用相对地址，预览按管理端 BASE_URL 解析；正式域名验收发现的独立后台根路径 404 已修复并再次验证。7001 与 7002 图片字节 SHA-256 均核对成功。

## Review 与验证

独立后端 review 发现两项 P2：旧 USD 范围没有新可售面额时误把服务视为关闭、阻断旧单；基础单价极低时试算 1 USD 的 0 分无效端点导致合法区间被拦。均已修复并加入回归：保留真实开关、允许管理员停用，旧单仍可结算；按真实可售区间验证低单价。独立前端 review 未发现可确认的 P1/P2。

最终 PHP 回归 359 项、2417 条断言通过，包含 111 项活动测试、原充值支付证据、福利、订阅和 BEpusdt。用户类型检查、活动 API 6 项测试、后台类型检查及两端生产构建通过。额外前端 fixture 测试覆盖发布/下架、未来/到期/空列表、CTA、轮播暂停/自动/触摸、减少动态效果、60 秒/焦点刷新、请求竞态、卸载和版本冲突恢复。

真实 Laravel + SQLite 的联调未模拟业务 API，9 组浏览器场景通过，控制台与 HTTP 错误均 0：实际管理界面新建草稿、发布并在首页刷新出现、下架隐藏、删除；三张图加载；欢迎语上方布局和原邀请卡移入；手机及减少动态效果；充值本金边界；真实未来/空活动收起和客户端时钟到期隐藏；后台人民币与有效 USD 范围显示。

正式域名 8 组浏览器验收通过，控制台与接口错误均 0；验证三类活动实际规则、三图和 CTA、桌面/手机轮播、最低有效 USD 报价、后台编辑图预览、原后台入口、后台范围显示。管理端实际创建仅未来才生效的临时活动，完成发布/下架/删除；未对真实用户暴露测试活动，未发起真实扣款。视口 1440×1080、390×844，正式截图均已亲自查看。

本地证据：output/activities-20261025/browser-evidence/report.json 与同目录 PNG；联调证据 output/activities-e2e/browser-evidence/report.json。短效验收账号、令牌及临时活动清理完成；本地与远端验收凭据文件移除。Horizon 正常运行，每分钟 ai-topup:reconcile 保持生效。

## 部署与恢复

发布目录 /root/Xboard-new/.deploy/activities-20261025。数据库一致性备份 backup/xboard-before-activities.sql.gz 为 435823682 字节，gzip 完整性验证通过；备份原 Compose、六服务镜像 ID、公开用户静态、TokenPay 和 nginx 配置。仅迁移新增活动表 2026_10_25_000003_create_marketing_activities_table.php，显式、幂等创建三个默认活动，迁移本身不发布数据。

已更新公开用户静态目录 /root/nginxhome/vue-xboard-theme-micah 和持久 Compose 镜像设置。PHP 镜像内保留原后台 assets 与 manifest；生产旧 nginx API 上游、桥接凭据、付款发码、订阅和邀请奖励兑现逻辑保持原有配置。原后台与独立管理端的预览基路径修复只更新 web/admin 的后台静态资产，其余三个 PHP 任务服务沿用同版本业务代码镜像。

| 服务 | 镜像 | SHA-256 |
| --- | --- | --- |
| web | xboard:activities-20261025-preview | 261a60ec51e9c9c5c031a777b2092303ba6fe972f2a97063d4d0c9cdf0043195 |
| horizon/scheduler/ws-server | xboard:activities-20261025 | 6b0c35e16ab87cdc5923e0b18dc1e0c1d726c7262f84c5971b26ebad7763c4f8 |
| admin | xboard-admin:activities-20261025-preview | f3cf411759e737da16c02acad50e34ca6866d73a69caaa61cbe002d9eb053180 |
| user | xboard-user:activities-20261025 | 2b593f380201b46e022cd7c9b0d2db200bc0e511d4868edc1f4c9c615a7cfc8a |

后端包 1cab059b148c9faca2977a017cde913e516ce4d437a076ae3a9e37d077f9750e；用户包 8b272221b2e1603e4a7ba0def8795b2853ac414f108e9269da89394d94a47ba4；最终后台预览修复包 9222a7a0a63d6bcf5dfbcfdfc9c6fe5c5dae95cc2f0e8636d92c3857c6cd6864。SFTP 回读校验通过。

rollback-production.py 恢复发布前充值版镜像、Compose 和公开静态入口，保留数据库中的付款、兑换码、流量激活和活动记录，不回滚业务数据。后台图片修复前另保存 compose-before-preview.yaml。已有真实到账后，历史数据库备份仅供灾难恢复评估，不能覆盖新付款记录。
