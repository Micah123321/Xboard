# 流量计费与统计修复升级说明

## 计量契约

- 节点向 `/api/v2/server/report` 上报原始字节增量，`traffic` 为 `{用户ID: [上传, 下载]}`。
- 新节点为每批数据提供 `report_id`；重试保持 ID 和流量内容不变，新流量使用另一批次。
- 后端按节点 ID 与上报 ID 去重；去重台账、用户额度扣量、用户日统计、节点日统计和节点累计在同一个数据库事务提交。
- 用户 `u/d` 与用户统计 `u/d` 已乘倍率并统一向下取整；节点统计保留原始字节。前端直接相加用户已计费字节，不再乘倍率。
- 后端以请求接收时刻固定倍率和统计日期。该口径不把离线上报增量拆回实际发生的每一天；旧协议没有逐时间段采样数据。
- 用户日志仅返回日桶，包含本月及昨日，并返回 `stat_timezone`；图表与今日/昨日卡片使用 `record_at`，更新时间仅用于明细展示。

## 升级顺序

1. 备份数据库及三个项目的当前发布版本。暂缓节点上报，确认旧 `traffic_fetch` / `stat` 队列已排空；保留失败任务清单，避免盲目重放曾部分成功的旧任务。
2. 发布后端并执行新增迁移 `2026_08_28_000001_create_traffic_reports_table.php`，建立 `v2_traffic_report`。本次源码修复没有对现有数据库执行迁移。
3. 重启队列及常驻 HTTP 工作进程，确保加载新计费任务。任务超时为 60 秒，队列 `retry_after` 必须大于任务超时（仓库默认 90 秒）；大节点整批事务须按实际用户数压测。
4. 发布配套节点与两个前端，恢复上报。节点 `kernel.config_dir` 必须可写，待报批次按面板/节点身份保存为 `report-<identity>.json`；已有 systemd 服务应同步设置 `TimeoutStopSec=130`，给 90 秒报告关闭预算及 120 秒程序退出上限留出时间。无 `report_id` 的旧节点仅保证队列任务内部去重，HTTP 响应丢失重发仍需新节点才能消除。
5. 用小额流量验证：倍率 2 的原始 1 MiB，节点统计增加 1 MiB，用户额度及统计增加 2 MiB，用户前端显示 2 MiB；同批 ID 重发后数值保持不变。

## 历史数据与运维

- 节点先原子保存稳定批次再发送，ACK 后更新本地队列；退出时先保存独立尾量，网络失败可在下次启动重发同 ID。损坏文件报错并保留，避免静默丢量。硬杀或持续磁盘故障仍可能丢失最后持久化后的内存流量。
- 修复不自动重写历史账单或补偿用户额度。已有重复/漏计数据须结合原始上报、队列失败记录、用户重置记录核对后再修正。
- `v2_traffic_report` 是持久去重依据，不应随意清空。若将来设置保留期限，必须同时规定节点最大重试/持久批次保留期，避免旧批次在台账清理后再次入账。
- 管理端全历史原始流量按现存节点日桶汇总，历史聚合缓存 300 秒、仪表盘缓存 30 秒。已归档或删除的日桶不在该统计中。
- 旧序列化统计任务没有接收时间时保留执行日回退；新任务不受该限制。
- 源码回归使用内存 SQLite、模拟缓存和本地节点 HTTP/内核实例。未对线上 MySQL/PostgreSQL 做并发压测，未执行线上部署。

## 回归命令

```powershell
php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php tests/Unit/TrafficBillingJobTest.php tests/Unit/StatisticalTrafficBucketTest.php tests/Unit/Admin/StatControllerTrafficRankWindowTest.php
```

用户前端运行流量工具和首页测试及类型检查；管理端运行 `scripts/dashboard-date-range.test.mjs`、类型检查和构建。节点运行 tracker、panel、controlplane、service、machine、Xray、SingBox 及程序入口的相关测试。
