# 节点访问流量

2026-10-18自然日复评：北京时间17日全天、18日截至17:13:41，1394834条明细，10000组覆盖99.8908%未知字节；原unknown逐日覆盖8.3384%→23.8228%、48.2456%→61.4829%，新增45.865GiB/958组。GeoSite124组13753规则，新增Zenodo等学术/图库/VR/分发服务与来源分级社区资源规则；剩余缺目标93.133GiB、仅IP48.068GiB。分段只读查询不改变业务数据，PHP89项866断言、日期检查10项通过；18日全天尚待日终补采，未部署。详见../../docs/access-traffic-calendar-review-2026-10-17-18.md。

2026-10-18真实unknown复评：生产只读最近3小时（北京时间13:56:49–16:56:49）前2000组覆盖99.9472%未知字节，同样本规则覆盖3.5644%→68.7057%，新增263组/26.521GiB。GeoSite96组12461规则，补库洛/鸣潮、独立AI网关full匹配、公共CDN与专用DNS；证据及反例保存在resources/data/access-traffic/reviewed-evidence.json。流量频次仅排序调查，不提高置信度；该结果为同样本覆盖率，非模型准确率。PHP89项800断言、采样SQL11项通过；本轮未部署或线上重分类，详见../../docs/access-traffic-unknown-review-2026-10-18.md。

2026-10-18服务覆盖补齐：72组11451条域名规则，crypto/finance/tools/push/telemetry/social/games/shopping/productivity/education分类覆盖金融钱包、推送统计及常见平台整类；OKX、OneKey、OneSignal等有官方来源依据。20类筛选使用LABELS自动校验，历史重分类与原账单不变、4次SQL。PHP87项690断言和Chrome16项通过，构建位于admin-frontend/dist-access-service-coverage。详情见../../docs/access-traffic-service-coverage.md，本轮未发布。

2026-10-18 AI与未知推测：ai/messaging分类支持OpenAI等服务与Telegram官方14网段，GeoSite快照37组5786规则，排除共享跟踪域名。activity_hint/confidence/reason按原始字节方向生成低可信度线索，未知服务分类保持未知；查询/导出可见，无需写回。新快照按updated_at选择，历史重分类不改计费。PHP84项540断言与Chrome15项通过，当前为本地交付，详情见../../docs/access-traffic-service-inference.md。

管理员路由 /access-traffic 提供域名排行和时间片段视图，可按用户、节点、时间、域名/IP、类别与TCP/UDP筛选，并导出CSV和中文可打印HTML报告。入口位于用户菜单、原流量记录弹窗及节点菜单。用户主题兼容原接口。

节点项目 E:/code/go/mi-node 的 sing-box/Xray 通过有界Collector收集认证用户目标字节，不改原计费计数；采集配置热更新不重启监听器。独立report通过/api/v2/server/access/report复用节点认证，固定ID+payload指纹幂等，关联原report_id。普通缓存512批次/32MiB，故障时计费缓存暂存128批次/8MiB；过期410记录缺口并继续新明细，网络失败保留重试。

后端迁移2026_09_01_000001_create_access_traffic_tables新增明细报告和目标记录，扩展计费账本实际倍率及hook后用户扣费快照。每用户每方向按实际扣费分摊，整数尾差归并，计费和明细两种到达顺序均可结算。独立access_traffic队列在production和local环境均由Horizon独立supervisor处理。schedule每分钟修复待处理/结算报告，每日按实际last_seen清理过期片段；计费幂等键继续保留。

域名分类规则在config/access_traffic.php，另有124个服务组、13753条MIT许可GeoSite本地域名索引，支持每周原子更新、失败保留和30秒内刷新缓存；视频、网页、下载、更新、同步、EOS云存储为服务类型推断，CDN用途不被猜测，源IP不充当目标IP。管理员可见数据库数量、更新时间和识别依据；access-traffic:reclassify按500行批次更新历史分类，不改扣费与流量。缺目标计入未归属量，覆盖状态、未结算和节点级计费缺口明确返回。查询纳入与筛选范围相交的整个采集片段，节点名称为接收时快照。

默认关闭、300秒分桶、30天保留，管理员采集配置可指定节点ID。上线先备份和迁移后端，再更新管理员资源和Horizon/scheduler，保持采集关闭；试点节点更新并通过验收后再启用。环境部署证据位于output/releases/access-traffic-deployment。完整接口及启用步骤见../../docs/node-access-traffic.md。

2026-10-18查询优化：合并明细统计与窗口分页计数，正常8→4条SQL；新增processed_at/observed_at与received_at/node_id/report_id索引。用户列与导出补邮箱，::/0.0.0.0视为未采集目标而保留原报告wire指纹。百万行隔离SQLite中位2.64s→1.55s（约41%），响应对照一致；76项438断言，Go access/singbox/xray/service四包、Linux构建、最终Vue构建与Chrome14项mock交互通过。本轮未发布或执行生产迁移，生产MySQL耗时仍需实测，详情见../../docs/access-traffic-query-optimization.md。
