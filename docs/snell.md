# Snell v4 接入

Snell 使用独立的 Go 模块和进程，源码位于 E:/code/go/mi-node/snell-agent。现有 mi-node 的 sing-box、Xray 和主模块依赖保持原样。面板仍使用 v2_server 表的 type 和 protocol_settings 字段，本次接入不需要新增数据库迁移。

## 面板配置

在节点管理中添加 Snell，选择用户权限组，填写对外连接地址、连接端口及 Agent 监听的服务端口。协议固定为 v4 / TCP，混淆可选关闭或 HTTP。HTTP 的混淆 Host 随用户订阅下发。

Snell 按节点 ID 对接独立 Agent，不绑定机器，不配置面板路由、自定义出站或证书。转发节点保留原有父子关系及对外端口语义；实际转发由现有网络设施承担。

每位用户的 PSK 为其 UUID 的原始 UTF-8 字符串，由订阅生成器和 Agent 用户接口自动同步。节点表单无需共享 PSK。用户重置订阅密钥后，Agent 在下一次用户同步时移除旧密钥并关闭对应连接。

## 独立 Agent

在独立模块目录构建：

```bash
go build -trimpath -o snell-agent ./cmd/snell-agent
```

配置示例：

```json
{
  "panel_url": "https://panel.example.com",
  "token": "REPLACE_WITH_SERVER_TOKEN",
  "node_id": 41,
  "state_dir": "/var/lib/snell-agent",
  "stale_after": "5m"
}
```

启动命令：

```bash
./snell-agent -config /etc/snell-agent/config.json
```

panel_url 为面板根地址，远端面板使用 HTTPS，HTTP 仅接受 localhost 或回环 IP。Agent 自动追加 /api/v2/server 路径。node_id 使用面板实际节点 ID。state_dir 保存待确认的流量上报；重启应复用原目录，避免丢失重试状态。可选 pull_interval、report_interval 均为 Go 时长字符串；省略时使用面板同步周期。

面板接口沿用 GET /api/v2/server/config、GET /api/v2/server/user 和 POST /api/v2/server/report。上报使用稳定 report_id 重试，用户流量分别记入各自 ID。用户列表异常不作为空列表处理；超过 stale_after 未获得有效同步时停止提供代理。

具体安装、systemd 服务与运行参数参见独立模块内的 README.md。

## 客户端

Mihomo 内核需为 1.19.26 或更新版本。Clash Party 的界面版本与内核版本分别管理；此前截图里的 Mihomo 1.19.16 需更新内核。面板输出 Mihomo 和 Surge 的 Snell v4 配置，已识别的旧 Mihomo 内核和未实现 Snell 的订阅格式会过滤该节点。Surge 配置已验证输出格式，实际连接验证以 Mihomo 为准。

## 验证

本地验收：PHP 97 项测试、328 项断言通过（含双用户倍率入账、Snell 统计和报告重试去重）；管理端配置脚本 12 项通过，两个前端类型检查与构建通过。管理端新增与编辑回填、用户端节点展示与订阅提示已通过隔离浏览器验证。独立 Agent 的 23 个顶层测试、Linux 竞态检测、静态检查及 Linux amd64 构建通过。

真实 Mihomo v1.19.26 使用 PHP 实际生成的节点配置与订阅 PSK，在关闭混淆和 HTTP 混淆下完成双用户连接、流量分别上报、失败重试保持 report_id、删除用户后停用及另一用户继续使用。联调使用回环模拟面板与目标服务，未连接线上面板。

PHP 在内存 SQLite 和缓存中测试，不连接项目运行数据库：

```bash
php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php --do-not-cache-result tests/Unit/Protocols/SnellTest.php tests/Unit/Admin/SnellMachineBindingTest.php tests/Unit/TrafficBillingJobTest.php tests/Unit/Protocols/UpstreamProtocolFixesTest.php tests/Unit/Protocols/StashAnyTlsCompatibilityTest.php
```

管理端在 admin-frontend 目录运行：

```bash
node --test scripts/node-snell.test.mjs scripts/node-bandwidth.test.mjs
npx vue-tsc -b
npx vite build --emptyOutDir=false
```

已有管理端静态资源存在本地修改，构建时保留旧资源文件。用户端位于 E:/code/vue/vue-xboard-theme-micah，使用 npm run build 进行类型检查与构建。

独立 Agent 使用固定版本的 OpenSnell 多用户实现，上游该功能仍为 alpha。上线前应按实际用户数量测试首次鉴权并发和资源占用。首版仅开放 TCP，UDP/QUIC 未接入按用户计费链路。设备限制按本节点的来源 IP 去重计数，多节点合计设备数尚未联动限制。活跃流量在上报周期内暂存内存，进程强杀或断电可能损失最近一个周期的增量；已落盘报告会在重启后继续重试。
