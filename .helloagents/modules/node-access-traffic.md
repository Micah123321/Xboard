# 节点访问流量

管理员路由 /access-traffic 提供域名排行和时间片段视图，可按用户、节点、时间、域名/IP、类别与TCP/UDP筛选，并导出CSV和中文可打印HTML报告。入口位于用户菜单、原流量记录弹窗及节点菜单。用户主题兼容原接口。

节点项目 E:/code/go/mi-node 的 sing-box/Xray 通过有界Collector收集认证用户目标字节，不改原计费计数；采集配置热更新不重启监听器。独立report通过/api/v2/server/access/report复用节点认证，固定ID+payload指纹幂等，关联原report_id。普通缓存512批次/32MiB，故障时计费缓存暂存128批次/8MiB；过期410记录缺口并继续新明细，网络失败保留重试。

后端迁移2026_09_01_000001_create_access_traffic_tables新增明细报告和目标记录，扩展计费账本实际倍率及hook后用户扣费快照。每用户每方向按实际扣费分摊，整数尾差归并，计费和明细两种到达顺序均可结算。独立access_traffic队列由Horizon独立生产supervisor处理。schedule每分钟修复待处理/结算报告，每日按实际last_seen清理过期片段；计费幂等键继续保留。

域名分类规则在config/access_traffic.php，按域名标签边界匹配；视频、网页、下载、更新、同步为服务类型推断，CDN用途不被猜测，源IP不充当目标IP。缺目标计入未归属量，覆盖状态、未结算和节点级计费缺口明确返回。查询纳入与筛选范围相交的整个采集片段，节点名称为接收时快照。

默认关闭、300秒分桶、30天保留，管理员采集配置可指定节点ID。上线先备份和迁移后端，再更新管理员资源和Horizon/scheduler，保持采集关闭；试点节点更新并通过验收后再启用。环境部署证据位于output/releases/access-traffic-deployment。完整接口及启用步骤见../../docs/node-access-traffic.md。

验证：PHP37项206断言（隔离SQLite），Go8个相关包回归，最终Vue构建与Chrome13项mock交互通过；内核实现代理报告Linux race通过。生产真实客户端Mux/XUDP重连、Linux socket splice及MySQL并发仍需试点验收。
