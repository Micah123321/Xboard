# Xboard 对接 V2bX 完整文档

本文基于以下两套源码交叉整理：

- 面板端：`Xboard-new`
- 节点端：`E:/code/go/V2bX`

适用目标：让 `V2bX` 节点稳定对接 `Xboard`，完成配置拉取、用户同步、流量上报、在线设备上报。

## 1. 对接结论（先看这个）

1. `V2bX` 当前固定请求 `Xboard` 的 `V1 UniProxy` 路径（不是 `V2` 路径）：
   - `GET /api/v1/server/UniProxy/config`
   - `GET /api/v1/server/UniProxy/user`
   - `GET /api/v1/server/UniProxy/alivelist`
   - `POST /api/v1/server/UniProxy/push`
   - `POST /api/v1/server/UniProxy/alive`
2. 鉴权依赖 URL Query 参数：`token`、`node_id`、`node_type`。
3. `Xboard` 的 `server_token` 长度需要 >= 16。
4. `V2bX` 启动时如果拉不到可用用户会直接报错退出：`add users error: not have any user`。
5. 在线设备数据写入由队列任务处理，面板端必须保证队列消费者（通常 `horizon`）在运行。

## 2. 源码级接口对齐

## 2.1 V2bX 侧（请求方）

关键实现：

- `api/panel/panel.go`：初始化 Query 参数（`node_type/node_id/token`），并限制 `NodeType` 枚举。
- `api/panel/node.go`：拉取节点配置（`/api/v1/server/UniProxy/config`）。
- `api/panel/user.go`：拉取用户、在线列表，上报流量和在线 IP。
- `node/controller.go`：启动时先拉配置和用户，再注册节点。
- `node/task.go`：按 `pull_interval/push_interval` 定时同步与上报。

## 2.2 Xboard 侧（服务方）

关键实现：

- `app/Http/Routes/V1/ServerRoute.php`：`/api/v1/server/UniProxy/*` 路由定义。
- `app/Http/Middleware/Server.php`：校验 `token/node_id/node_type`，并注入 `node_info`。
- `app/Http/Controllers/V1/Server/UniProxyController.php`：实现 `config/user/push/alive/alivelist/status`。
- `app/Services/ServerService.php`：按 `node_id`（支持 `code` 或 `id`）和 `node_type` 查节点，按权限组取可用用户。
- `app/Jobs/UpdateAliveDataJob.php`：异步写在线设备计数。

## 3. 类型与协议兼容要点

`V2bX` 允许的 `NodeType`（`api/panel/panel.go`）：

- `vmess`
- `vless`
- `trojan`
- `shadowsocks`
- `hysteria`
- `hysteria2`
- `tuic`
- `anytls`
- 兼容别名：`v2ray -> vmess`

`Xboard` 节点类型（`app/Models/Server.php`）有别名：

- `v2ray -> vmess`
- `hysteria2 -> hysteria`

建议：

1. 绝大多数场景按面板节点真实类型填写 `NodeType`。
2. Hysteria v2 场景建议仍使用 `NodeType: "hysteria"`，在面板协议设置里把 `version` 设为 `2`，避免类型语义混乱。
3. `V2bX` 并不对接 `Xboard` 的 `socks/naive/http/mieru` 这几类。

## 4. Xboard 面板端配置步骤

## 4.1 全局通信参数

后台系统配置里设置：

- `server_token`：与 V2bX 的 `ApiKey` 完全一致，长度建议 32+。
- `server_pull_interval`：节点拉配置周期（秒）。
- `server_push_interval`：节点推送流量周期（秒）。
- `device_limit_mode`：设备限制统计策略（按你的业务选）。

源码依据：

- `app/Http/Controllers/V2/Admin/ConfigController.php`
- `app/Http/Requests/Admin/ConfigSave.php`

## 4.2 创建节点（每个 NodeID 对应一个节点）

至少需要正确配置：

- `type`
- `name`
- `host`
- `port`
- `server_port`
- `group_ids`
- `rate`
- `protocol_settings`（按协议必填项）

源码依据：`app/Http/Requests/Admin/ServerSave.php`

## 4.3 用户可用性条件（非常关键）

节点可拉到的用户必须同时满足（`ServerService::getAvailableUsers`）：

- 用户 `group_id` 在节点 `group_ids` 内
- `u + d < transfer_enable`
- 未过期
- 未被禁用

否则 `V2bX` 首次启动可能直接失败（无可用用户）。

## 4.4 队列进程

`/alive` 上报会派发 `UpdateAliveDataJob` 到 `online_sync` 队列。
若没有队列消费者，在线设备统计会滞后或不更新。

建议至少保证：

- `php artisan horizon` 正常运行

## 5. V2bX 配置（可直接改）

参考 `E:/code/go/V2bX/example/config.json`。

## 5.1 单节点最小可用示例

```json
{
  "Log": {
    "Level": "info",
    "Output": ""
  },
  "Cores": [
    {
      "Type": "sing",
      "Log": {
        "Level": "info",
        "Timestamp": true
      },
      "OriginalPath": "/etc/V2bX/sing_origin.json"
    }
  ],
  "Nodes": [
    {
      "Core": "sing",
      "ApiHost": "https://panel.example.com",
      "ApiKey": "请填写与Xboard一致的server_token",
      "NodeID": 1,
      "NodeType": "vmess",
      "Timeout": 30,
      "ListenIP": "0.0.0.0",
      "SendIP": "0.0.0.0",
      "DeviceOnlineMinTraffic": 200,
      "MinReportTraffic": 0
    }
  ]
}
```

## 5.2 字段说明（高频踩坑项）

- `ApiHost`：必须是面板根地址，不要写成 `.../api/v1`。
- `ApiKey`：对应 `Xboard server_token`。
- `NodeID`：对应面板节点 `id`（或数值型 `code`）。
- `NodeType`：必须是 V2bX 支持的枚举。
- `Timeout`：HTTP 超时秒数。
- `ApiSendIP`（可选）：多网卡时指定请求源 IP。

源码依据：`E:/code/go/V2bX/conf/node.go`

## 6. 启动与热重载

## 6.1 启动命令

```bash
V2bX server -c /etc/V2bX/config.json
```

默认支持 `--watch`（开启配置监听），配置文件变化后会自动重载核心和节点。

源码依据：`E:/code/go/V2bX/cmd/server.go`

## 6.2 建议运行形态

- 使用 `systemd` 托管 `V2bX`
- 配置 `Restart=always`
- 单独落日志文件，便于排错

## 7. 联调验证（建议按顺序）

## 7.1 手工验证面板接口可达

在节点机执行（把参数换成你的真实值）：

```bash
curl -sS "https://panel.example.com/api/v1/server/UniProxy/config?node_type=vmess&node_id=1&token=YOUR_TOKEN"
```

预期：返回 JSON，包含 `base_config.push_interval` 与 `base_config.pull_interval`。

```bash
curl -sS "https://panel.example.com/api/v1/server/UniProxy/user?node_type=vmess&node_id=1&token=YOUR_TOKEN"
```

预期：返回 `users` 列表，且非空（至少有一个可用用户）。

## 7.2 启动后看 V2bX 日志

应出现这类信息：

- `Core ... started`
- `Nodes started`
- 周期性 `Report N users traffic`
- 周期性在线用户上报日志

## 7.3 面板侧确认

- 节点状态 `last_check_at`、`last_push_at` 持续刷新
- 用户流量持续累加
- `online_count` 有变化（需要队列正常）

## 8. 常见问题与处理

## 8.1 `unsupported Node type`

原因：`NodeType` 不在 V2bX 白名单。  
处理：改为支持值（如 `vmess/vless/trojan/shadowsocks/hysteria/tuic/anytls`）。

## 8.2 `Invalid token`

原因：`ApiKey != server_token`。  
处理：面板与节点统一同一密钥，并确认没有前后空格。

## 8.3 `Server does not exist`

原因：

- `NodeID` 错误
- `NodeType` 与面板节点类型不匹配

处理：先在面板确认节点 ID/类型，再校正 V2bX 配置。

## 8.4 `add users error: not have any user`

原因：该节点按权限组筛选后没有可用用户。  
处理：

1. 确认节点 `group_ids`
2. 确认至少一个用户满足流量/到期/禁用条件

## 8.5 在线设备不上报或不更新

原因：面板队列消费者未运行。  
处理：启动并确认 `horizon` 正常消费（尤其 `online_sync` 队列）。

## 8.6 配置路径重复导致 404

原因：`ApiHost` 写成 `https://panel/api/v1`，最终拼接成重复路径。  
处理：`ApiHost` 只保留根域名（如 `https://panel.example.com`）。

## 9. 请求/响应数据形状（排错用）

## 9.1 配置拉取

- 请求：`GET /api/v1/server/UniProxy/config?node_type=...&node_id=...&token=...`
- 响应：按协议字段 + `base_config`

## 9.2 用户拉取

- 请求：`GET /api/v1/server/UniProxy/user?...`
- 响应：`{"users":[{"id":1,"uuid":"...","speed_limit":0,"device_limit":0}]}`（也兼容 msgpack）

## 9.3 流量上报

- 请求：`POST /api/v1/server/UniProxy/push?...`
- Body（V2bX 实际发送）：

```json
{
  "1001": [12345, 67890],
  "1002": [11111, 22222]
}
```

## 9.4 在线上报

- 请求：`POST /api/v1/server/UniProxy/alive?...`
- Body：

```json
{
  "1001": ["1.1.1.1", "2.2.2.2"],
  "1002": ["3.3.3.3"]
}
```

## 10. 安全建议

1. `ApiHost` 必须用 HTTPS。
2. `server_token` 使用高强度随机值并定期轮换。
3. 在反向代理/Nginx 层限制节点来源 IP 访问 `server/UniProxy` 接口。
4. 变更 `server_token` 后，面板与全部节点必须同时切换。

## 11. 代码参考索引

Xboard：

- `app/Http/Routes/V1/ServerRoute.php`
- `app/Http/Middleware/Server.php`
- `app/Http/Controllers/V1/Server/UniProxyController.php`
- `app/Services/ServerService.php`
- `app/Jobs/UpdateAliveDataJob.php`
- `app/Http/Requests/Admin/ConfigSave.php`
- `app/Http/Requests/Admin/ServerSave.php`

V2bX：

- `api/panel/panel.go`
- `api/panel/node.go`
- `api/panel/user.go`
- `node/controller.go`
- `node/task.go`
- `node/user.go`
- `conf/node.go`
- `cmd/server.go`
- `example/config.json`

