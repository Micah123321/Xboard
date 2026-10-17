# Node access traffic

Status: implemented and reviewed, with local regression checks passed. Production rollout keeps capture disabled until a pilot is selected; environment-specific deployment evidence is recorded under output/releases/access-traffic-deployment.

## Scope

mi-node captures authenticated-user destination bytes in sing-box and Xray. Xboard stores independent, idempotent access reports and associates them with the actual billing report. The admin UI provides destination/time views, category analysis and CSV/printable HTML exports. The user theme remains compatible. Existing workspace changes must be preserved.

## Wire contract v1

Node config includes `access_log`: `{ enabled: boolean, bucket_seconds: 300, max_entries: 2000 }`. Missing config means disabled. Settings are global with an optional node ID allowlist.

POST `/api/v2/server/access/report` uses existing node authentication. Body:

```json
{
  "version": 1,
  "report_id": "unique-access-batch-id",
  "billing_report_id": "existing-traffic-report-id",
  "kernel": "sing-box",
  "observed_at": 1790000000,
  "coverage": "complete",
  "dropped_entries": 0,
  "totals": { "123": [1000, 2000] },
  "entries": [{
    "user_id": 123, "domain": "video.example", "ip": "",
    "port": 443, "network": "tcp", "domain_source": "request",
    "bucket_at": 1789999800, "first_seen": 1790000000, "last_seen": 1790000000,
    "u": 1000, "d": 2000, "connections": 1
  }]
}
```

At most 10000 entries. Source values: request, sniff, ip, unknown. Network values: tcp, udp, unknown. An unattributed row has empty domain/IP, port 0 and source unknown. It records counted bytes without a proven destination. Totals are the ORIGINAL raw traffic map from the linked billing report. Fit attributed entries into each user's direction budgets; retain excess for the next batch and represent missing bytes explicitly. Never infer destinations from client IP or reverse DNS.

Access reports are independently spooled with stable IDs/payloads, retried after failure, removed only after durable acknowledgement, and never charge users. Backend duplicate receipt + same fingerprint is a no-op; same ID + different fingerprint is an error. Capture/receive/billing times are separate. Billing snapshots use final post-hook bytes and effective rate from TrafficBillingJob; do not recompute with current settings. Separate detail jobs and durable billing snapshot permit either arrival order.

## Admin contract

GET `/stat/getAccessTraffic`: user_id/node_id optional positive integers, start_time/end_time half-open timestamps, search, category, network, group_by=domain|time, page, pageSize (max100). Default recent 24h; cap time range to retention. Return unwrapped `{data,total,summary,analysis,meta}`. Include collection slices overlapping the interval (`last_seen >= start_time AND first_seen < end_time`); all bytes in that slice are included, not estimated per second. Node names are receipt-time snapshots and survive rename/deletion. Classification rules live in `config/access_traffic.php`.

Rows: id(string), user_id, node_id, node_name, domain, ip, port, network, domain_source, first_seen, last_seen, raw_u, raw_d, raw_total, billed_u(nullable), billed_d(nullable), billed_total(nullable), rate(nullable; mixed rates stay null), service, category, confidence(high|medium|low|unknown), reason, connections, share(percent), coverage. Grouping never silently merges different users or nodes.

Summary: raw_u/raw_d/raw_total, billed_u/billed_d/billed_total, identified_bytes, unidentified_bytes, identified_percent, unattributed_bytes, report_count, partial_report_count, unsettled_report_count. All summaries cover the entire filtered set, not just the page.

Analysis array: category, category_label, raw_total, share(percent), confidence, reason. Categories: video, web, download, update, sync, storage, cdn, unknown. Classifications are evidence-based inferences; generic CDN stays cdn. Never describe a title, page or user intent as observed. Match normalized domains on label-boundary suffixes only.

Meta: timezone, bucket_seconds, retention_days, enabled, first_collected_at(nullable), last_collected_at(nullable), coverage(complete|partial|none), reason. Complete applies only to the collected reports, not proof of all traffic across uncaptured intervals/nodes; expose collection gaps.

GET `/stat/exportAccessTraffic`: same filters, format=csv|html. Export all matching rows with limits/streaming; explicitly label raw vs allocated billed values, timezone, filters and coverage. Escape CSV formula injection and HTML.

GET/POST `/stat/accessTrafficConfig`: `{enabled,bucket_seconds,retention_days,max_entries,node_ids}`. Defaults false/300/30/2000/[]; [] means all nodes when enabled. Range validation and admin/log middleware required. Node IDs authenticated independently of body.

## Kernel handoff

New `internal/access` package owns Config, Entry, Snapshot and a concurrent bounded Collector. Optional kernel methods `ConfigureAccess(access.Config)` and `DrainAccess() access.Snapshot` do not extend the mandatory Kernel interface. Both kernels preserve existing billing counters. Capture-only settings do not restart listeners. Request domains and available HTTP/TLS/QUIC metadata are accepted; reverse DNS is never treated as a proven domain. This feature does not automatically alter routing or enable global sniffing.

最新查询优化、邮箱、GeoSite 数据库、云存储分类与历史重分类见 [访问流量查询优化](access-traffic-query-optimization.md)。

## Verification

- PHP: AccessTrafficTest + TrafficBillingJobTest: 37 tests, 206 assertions passed on isolated SQLite. Covers migration, durable receipts, retry/collision, both arrival orders, timed rate/hooks, exact allocation, unknown targets, name snapshots, overlap filters, independent queue, exports and admin/server routing.
- Go: access, kernel, singbox, xray, model, panel, controlplane, service package tests passed. Service regression additionally covers detail spool corruption with durable deferred batches and continuing billing. Kernel worker reports Linux race checks passed for collector/singbox/xray. Real deployed-client mux/XUDP reconnect and socket-splice behavior remain rollout checks.
- Admin: Vue/TypeScript production build passed; Chrome mock API test passed 13 checks with zero page exceptions and zero unhandled API calls. Covers user/node/log entrances, filtering, pages, config, race responses, CSV/HTML downloads and 390px layout.
- Linux amd64 release build with QUIC/uTLS/WireGuard/ACME/Clash tags passed. Output archive: E:/code/go/mi-node/output/access-traffic/mi-node-linux-amd64.tar.gz. Admin build: admin-frontend/dist-access-traffic. Existing public/assets/admin changes were preserved.

## 启用与部署顺序

1. 部署后端源码并应用新增迁移；默认采集关闭。这里未操作现有数据库。上线前备份数据库并使用项目原有部署流程。
2. 发布管理员构建资源，重载应用与 Horizon，确认独立 access_traffic 队列和 scheduler 运行。生产 supervisor 的并发默认上限为 2，可用 HORIZON_ACCESS_TRAFFIC_MAX 调整。
3. 更新试点节点的 mi-node，然后在管理员“节点访问流量”页面打开“采集配置”，启用并填写试点节点 ID。空列表代表全部节点。默认 300 秒分桶、30 天保留、单次 collector 容量 2000。
4. 验证域名记录与计费分摊，再逐步扩展。从用户管理“TA的访问流量分析”、原流量记录弹窗或节点菜单进入；导出中文 HTML 报告可打印为 PDF，CSV 保留字节原值并兼容 Excel UTF-8。

明细上报独立于计费。磁盘缓存保留最多 512 个批次/32 MiB；溢出时丢弃最旧明细，后续报告及后台缺口显示部分采集。独立缓存故障时，计费缓存可暂存最多 128 份 / 8 MiB 待交接明细；正常计费继续。新明细从启用后开始，历史计费记录没有访问目标可回填。超出后端保留期的批次返回 HTTP 410，节点记录缺口并继续后续明细；其他暂时失败仍保留重试。清理依据实际片段末次活动时间，保留期内的片段不会因桶起点较早而提前清除。

关闭采集保留已存历史直至保留期清理；独立待上报批次继续重试。分类表示域名服务类型推断，视频标题、页面路径、主动观看意图未被采集。缺域名时保留真实目标 IP；缺目标时保留未归属字节。原始量＋未归属量按批次对齐，实际扣费按历史快照分摊。
