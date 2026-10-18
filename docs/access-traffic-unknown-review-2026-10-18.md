# 最近三小时未知访问：证据校准与覆盖复评

本轮已从生产读取匿名目标聚合数据，完成归属核实、本地规则补充与同样本复评。没有发布、执行生产重分类或更改线上业务数据。

## 样本与结果

采样窗口为北京时间2026-10-18 13:56:49至16:56:49（UTC 05:56:49至08:56:49，Unix [1792303009,1792313809)）。与现有报表一致，查询相交采集片段并保留整个片段字节，不虚构片段内每秒流量。

| 指标 | 结果 |
| --- | ---: |
| 窗口全部采集明细 | 152913条 |
| 已存为unknown的明细 | 48820条 |
| 窗口全部原始流量 | 110237779589字节，102.667 GiB |
| 已存unknown原始流量 | 43739063694字节，40.735 GiB |
| 取样目标组数 | 流量最高2000组，存在截断 |
| 取样unknown字节 | 43715959490字节，占全部unknown的99.9472% |
| 未提取的unknown长尾 | 23104204字节，约22.03 MiB |
| 当前本地规则基线可识别 | 147组，1558221528字节，1.451 GiB |
| 本次补充后可识别 | 410组，30035350524字节，27.973 GiB |
| 本次新增识别 | 263组，28477128996字节，26.521 GiB |
| 样本unknown字节识别率 | 3.5644% → 68.7057% |

已识别字节仅指规则覆盖，未做独立人工全量标注或留出集准确率评估。采样数据用于选择规则，结果是同批样本回放效果，不是模型训练准确率。按全部unknown作分母时，识别率为68.6694%，未采样长尾按未知处理。

生产已存分类的全窗口已识别比例为60.3230%。若发布本地规则并重分类这一窗口的unknown、且保持其他已知分类不变，推算可达87.5690%。这只是本地回放投影；生产页面和历史分类尚未更新。

## 核实归属与置信度

| 代表目标 | 原始流量 | 本地结果 | 证据与可信度 |
| --- | ---: | --- | --- |
| cdn-huoshan-hw-mc.aki-game.net | 19.837 GiB | 库洛 / 鸣潮游戏服务 | 中：维护清单直接匹配aki-game.net，官方启动器也引用该根域名的下载主机 |
| codex.ccbvv.com | 4.121 GiB | ccb codex独立AI API网关 | 中：公开首页自述AI API Gateway，精确匹配完整主机，未验证具体上游模型 |
| hls-cdn77.xvideos-cdn.com | 0.482 GiB | XVideos视频平台 | 中：GeoSite专用服务清单 |
| cdn.jsdelivr.net | 0.444 GiB | jsDelivr公共资源CDN | 高：官方页面直接列出该资源URL，不据此反推具体App |
| update.hwdns.net | 0.056 GiB | 软件更新资源代理 | 中：Clash Verge Rev官方源码引用该更新代理，不确定是哪款软件被访问 |
| static.higgsfield.ai | 0.050 GiB | Higgsfield AI影像服务 | 高：官网直接引用静态资源，不代表用户生成了视频 |
| 8.8.8.8 / 8.8.4.4 | 合计约0.106 GiB | Google Public DNS | 高：官方专用地址及IPv6，保持域名证据优先 |

同时补充DigiCert证书服务、TED、TMDB、飞书/Lark、知网、Springer、CSDN、Apifox、Vimeo、Pinterest、WPS、Postimages、小黑盒，以及部分媒体平台的维护清单。GeoSite索引由72组11451规则扩至96组12461规则，仍排除共享广告、跟踪、遥测、宽泛LiveKit端点及不支持的匹配规则。

发现原category-games-cn中的kurogames为带属性include，旧解析器按约定跳过整条带属性include，因此即便导入games大类，也会遗漏库洛。此次显式导入具体服务组，保留属性include的原边界，避免将整组未经筛选地扩大。

置信度规则：官方明确列出的专用域名或地址支持高可信度；社区维护清单、独立站点自述保持中；上下行比例只形成低可信度传输线索。流量大小、出现频次、用户数只用于核实排序，不自动增加可信度。新增完整主机匹配，独立网关的兄弟域名和子域名不会自动继承其AI分类。

可回归的正例、反例和来源保存在[reviewed-evidence.json](../resources/data/access-traffic/reviewed-evidence.json)，其中不含邮箱、账号ID或生产流量量值。数据驱动修正规则不等于把访问内容、交易操作或用户意图视为已知。

## 剩余缺口

剩余全部unknown约12.763 GiB（含未提取长尾）。已复评部分中，7.136 GiB仅有目标IP且未匹配专用服务地址，2.529 GiB缺少有效目标，另有3303243748字节为尚未核实的域名。共享云/CDN的网络归属、域名中的track/ai/download字样及同用户相邻访问不会变成具体App证据。

例如Hembed、部分独立下载/CDN、临时任务域名的可靠归属证据仍不足，本轮保留未知。继续提升这部分覆盖需要可靠域名证据或完善节点目标域名采集，而非单纯提高置信度。

## 证据、验证与复用

匿名原始聚合、修改前后回放和完整对照保存在本地被Git忽略的output/access-unknown-learning目录，不将原始访问清单写进版本库。核心文件：production-unknown-sample.json、baseline-review.json、final-review.json、comparison.json、evidence目录、sample-unknown.php及evaluate-sample.php。

原始样本SHA256：1a032abf18c2440c21bdc5db1e92bd6d3695728654d1a9995dcab246acda53e7。复评同时记录规则及GeoSite SHA256，比较脚本校验同样本、同范围、原始上下行/总量/连接数不变。

官方与维护证据：

- 游戏清单：https://github.com/v2fly/domain-list-community/blob/master/data/kurogames
- 官方启动器资源索引：https://prod-alicdn-gamestarter.kurogame.com/launcher/game/G153/50004_obOHXFrFanqsaIEOmuKroCcbZkQRBC7c/index.json ，HTTPS读取并保留gzip原件与解压JSON，其中下载URL明确使用aki-game.net。
- 独立网关：https://codex.ccbvv.com/ ，公开首页HTTP200，title为ccb codex - AI API Gateway；仅主机归属中可信度，不推断请求内容。
- 公共CDN：https://www.jsdelivr.com/
- DNS专用地址：https://developers.google.com/speed/public-dns/docs/using
- AI影像平台：https://higgsfield.ai/ai-video
- 更新代理源码：https://github.com/clash-verge-rev/clash-verge-rev/blob/409e59df/scripts/updater.mjs

后端89项800断言通过：包含证据分级、精确主机/伪后缀、专用IP/IPv6边界、20类筛选、4条SQL、历史分批重分类、原始字节/倍率/计费/用户账户不变与原有子节点回归。采样工具11项SQLite检查通过，覆盖时间边界、聚合、截断、身份排除和只读会话。本轮没有前端修改，无新增迁移或节点改动。

后续同类分析可复用采样和复评脚本，每次固定同一份样本、保存修改前基线、按证据补规则并运行回归。若要使页面与历史分类采用本轮结果，需后续发布后端规则、重载应用/队列，并执行php artisan access-traffic:reclassify；本轮没有执行这些生产写入。
