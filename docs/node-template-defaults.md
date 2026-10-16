# 节点协议模板默认值

管理端新建节点时自动填充通用协议参数。节点地址、连接端口、实际证书域名及配对密钥仍需按部署填写。

| 模板或选项 | 新建默认值与辅助功能 |
| --- | --- |
| Hysteria | Hysteria 2，上下行 0/0 Mbps，跳跃间隔 30 秒；开启 Salamander 并自动生成 32 位十六进制混淆密码，可重新生成。 |
| TUIC | V5、cubic、HTTP/3 ALPN、native UDP relay；空版本按 V5 回填。 |
| VMess / VLESS / Trojan | 默认 TCP；Trojan 默认 TLS。切换协议时，重置目标协议没有的传输或 TLS 模式。 |
| WS / H2 / HTTPUpgrade / XHTTP | 默认路径 `/`；XHTTP 模式默认 auto，并使用下拉选择。 |
| gRPC | Service Name 默认 grpc。 |
| TCP HTTP 头部 | 默认请求路径 `/`；清空路径后保存或导出仍回退到 `/`。 |
| mKCP | 默认 none 头部，独立头部枚举；新建时生成 seed，并支持主动重新生成。 |
| Reality | 目标端口默认 443，新建 Short ID 自动生成，并提供随机按钮；目标域名、公钥、私钥按实际部署填写。 |
| Shadowsocks | 默认 2022-blake3-aes-128-gcm、关闭混淆与插件；常用插件可选，也可输入自定义名称。 |
| Naive | 新建默认 TLS；SNI 填写证书覆盖的域名。 |
| SOCKS / HTTP | 默认关闭可选 TLS；按部署选择 TLS。 |
| Mieru | 默认 TCP；Traffic Pattern 留空使用核心默认行为，保留随机生成按钮。 |
| AnyTLS | 使用现有有效 Padding Scheme，保留随机填充及随机域名按钮。 |
| Snell | 保持 v4 / TCP、默认关闭混淆；HTTP Host 为可选项。 |
| 多路复用 | 默认关闭；参数预填 yamux、4 个连接。Brutal 默认关闭，预填 10/10 Mbps，开启前按实际线路调整。 |

## 已有节点与新建状态

编辑已有节点会保留实际保存的带宽、零跳跃间隔、密码和未知扩展字段。仅显示在当前传输中的字段会被编辑；相同传输下的自定义 WS 请求头、early-data 与 TLS 扩展不会因普通保存丢失。切换安全层时保留表单草稿，序列化时按选定安全层输出。

编辑后再新建会显式清除原节点 ID 和原协议设置，避免新建请求更新上一条节点。新建表单的随机值独立生成；新建 Reality 与启用 VLESS 自定义加密时，会检查密钥是否齐备。

## 服务端配套与兼容性

TUIC 自定义 ALPN 同时下发给客户端和节点 TLS 配置。已有 TCP HTTP 空路径订阅也会回退到 `/`，无需批量修改数据库。

mi-node 的 Xray 构建修正 TCP HTTP 请求配置、H2 Host 列表形状和 mKCP 头部/seed 转换。当前依赖已把 mKCP 头部与加密迁移至 UDP finalmask，节点按现行格式输出，并使用实际 Xray 配置解析器验证。

当前 mi-node TUIC 入站使用 V5；V4 需相应兼容内核。当前 Xray 依赖已移除旧 H2 传输，表单对此显示提示；H2 Host 结构修正不恢复已移除的传输。旧客户端与新版节点的实际端到端连接需结合部署验证。

## 本地验证

```powershell
node --test scripts/node-hysteria.test.mjs scripts/node-template-defaults.test.mjs scripts/node-snell.test.mjs scripts/node-plan-restriction.test.mjs
node scripts/check-node-editor-randomizers.mjs
$env:ADMIN_BUILD_OUT_DIR='../output/node-template-defaults/admin'
npm run build
```

以上命令在 admin-frontend 目录执行。浏览器交互脚本为 tests/node-hysteria.browser.mjs，使用隔离管理 API，支持 PLAYWRIGHT_MODULE、BROWSER_EXECUTABLE 与 ADMIN_BUILD_OUT_DIR。

```powershell
php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php tests/Unit/ServerProtocolDefaultsTest.php tests/Unit/Protocols/UpstreamProtocolFixesTest.php
```

PHP 命令在 Xboard 根目录执行；Go 命令在 mi-node 根目录执行：

```powershell
go test -tags with_quic ./internal/kernel/xray -count=1 -timeout=90s
```
