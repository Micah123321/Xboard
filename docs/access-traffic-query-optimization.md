# 访问流量查询与服务识别优化

本轮只完成本地源码、隔离测试和构建，未连接生产数据库、执行迁移或发布。

## 查询性能

原列表请求对相同明细执行 8 次 SQL：汇总、分类、采集时间、计费缺口、待处理数量、结算异常、完整分组计数和分页分组。现在合并汇总/分类/时间/异常，MySQL 8+、MariaDB 10.2+ 与 SQLite 3.25+ 使用窗口计数复用分页分组，正常页面共 4 次 SQL。旧数据库使用只选择分组键的独立计数，兼容原有分页；超出末页时仍返回真实总数。导出数量检查同样使用轻量计数，并在超过上限后停止计数。

用户邮箱在流量分组之后关联，不增加原始明细扫描宽度，已删除用户保留原记录，邮箱为空。CSV/HTML 导出同步包含邮箱。原历史节点名称、每用户每节点分组、重叠时间片段、NULL 未结算与混合倍率语义保持一致。

新增迁移 2026_10_18_000001_add_access_traffic_query_indexes.php：待处理报告使用 processed_at + observed_at + node_id；节点级计费缺口扫描使用 received_at + node_id + report_id 覆盖索引。原有报告关联唯一索引继续使用。

本地 PHP 8.2.31 + 隔离 SQLite，100 万明细、10 万报告、约 14 小时筛选范围，优化前后完整响应对照一致（排除新增邮箱和数据库状态字段）。三次交替测量原查询 2615.17 / 2648.58 / 2640.70 ms，优化查询 1567.71 / 1507.14 / 1545.98 ms；中位数约 2.64 秒 → 1.55 秒，减少 41%。无网络延迟、无结果缓存。索引单次样本 1619.61 ms，执行计划确认待处理过滤使用 access_pending_capture_node。该结果不代表生产 MySQL 实测；上线后还需按实际数据量确认响应时间。

完整测量：output/access-query-benchmark/results-1m.json；复现脚本和原查询快照在同目录，全部测试数据位于隔离内存数据库。

## 域名数据库与可信度

预置 resources/data/access-traffic/geosite.json，源为 v2fly/domain-list-community（GeoSite 的社区数据源，MIT 许可）。实际快照 24 个服务组、5414 条 suffix/full 域名规则。每份上游数据均保存 SHA256；上游源码压缩包 SHA256：33af033711bb4270ac890ad971098d537a25032bbe5f3fa0437defbe6ecbcc7c。

配置服务组见 config/access_traffic_geosite.php。受控源采用 HTTPS 固定仓库、请求超时和容量限制；更新完整成功后原子替换，失败保留有效文件并使用随代码提供的快照。Octane 进程按文件签名和最多 30 秒缓存重新加载。命中是域名标签边界匹配或 full 精确匹配，单次查询为域名层级数复杂度；分类不联网，不在访问查询时遍历整个库。35 条不支持的 regexp/plain 或带属性过滤 include 规则被明确跳过，不扩大成后缀匹配。

内置精确服务规则优先于宽泛平台名单，例如 googlevideo.com 仍识别视频分发；Bilibili/YouTube 广泛平台列表只说明服务归属。新增 storage“云存储”分类，截图 ykj-eos-dg5-01.eos-dongguan-6.cmecloud.cn 命中“移动云 EOS 对象存储”，不以此推断视频播放。页面显示规则数量与更新时间，“可信度”明确指服务识别。

GeoIP/MMDB/ASN 主要提供地域、运营商和网络归属，未用它们猜测 HTTPS 内容。未知域名保留未知，不将宽泛 CN/GFW/直连列表当成视频或网页行为。

## 未指定 IP

::、0.0.0.0 和 IPv4-mapped 未指定地址不再显示为真实访问目标。新节点采集器保留请求域名及有依据的唯一目标解析地址，过滤未指定地址；后端处理旧节点上报时同样归入未归属，不更改稳定 wire 指纹，保证升级前后同一批次重试仍幂等。已有记录即时展示“目标未采集”，重分类命令可批量清理这类占位地址。历史未采集的 IP 不会被猜测补齐。

## 更新步骤

1. 按原部署流程发布后端，并应用本次新增索引迁移；发布 admin-frontend/dist-access-query-optimization 的资源。迁移只增加索引，不重建业务明细。
2. 随代码已带初始数据库，可直接识别新报告。执行下面的历史重分类命令，使旧记录和分类筛选也使用最新规则；500 行一批，不修改任何原始字节、计费字段或账户余额。中断后可从最后输出的 last_id 继续。
3. 发布修正后的 mi-node 节点构建，使后续访问目标采集使用新版逻辑。安装包位于 E:/code/go/mi-node/output/access-traffic/mi-node-linux-amd64.tar.gz。


部署后使用的命令：

~~~bash
php artisan migrate --path=database/migrations/2026_10_18_000001_add_access_traffic_query_indexes.php --force
php artisan access-traffic:reclassify
php artisan access-traffic:sync-domains --status
~~~

需要手动更新上游数据时执行 php artisan access-traffic:sync-domains，随后按需重分类。scheduler 每周日 03:20 自动更新域名库，更新失败不会影响正常计费或访问查询。本机 PHP 证书配置缺失的问题已在快照构建中通过公开 CA 包解决，应用代码保留 TLS 证书验证。

## 验证

- PHP 76 项测试、438 个断言：域名解析、suffix/full/include、失败保留、原子更新缓存、真实快照、历史重分类不改计费、分页与旧版本回退、邮箱、未指定地址、增量索引及子节点倍率兼容。
- Go access/singbox/xray/service 四包回归及 Linux amd64 完整标签构建通过，确认字节计数不变、监听地址不当目标、单一 DNS 地址与路由覆盖不混淆。
- 管理员类型检查/生产构建、Chrome 14 项隔离 API 交互通过，零页面异常和零未处理 API；涵盖邮箱、目标说明、GeoSite 状态、可信度、筛选、分页、导出与手机布局。
