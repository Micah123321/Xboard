# 子节点 TCP 端口连通检测

## 背景

转发子节点可能没有接入 mi-node 上报链路，现有子节点运行状态会在自身心跳缺失或过期时回退父节点运行缓存。转发入口实际端口断开时，子节点仍可能因父节点在线而被自动上线显示。

## 方案

- 新增 `v2_server.tcp_check_enabled`，默认关闭，只对子节点生效。
- 子节点开启 TCP 检测后，运行状态访问器不再回退父节点缓存，优先使用子节点自己的 TCP 检测结果。
- 新增 `ServerTcpCheckService` 与 `sync:server-tcp-checks` 命令，定时检测开启开关的子节点 `host:server_port` TCP 连通性。
- 检测成功写入子节点自身 `LAST_CHECK_AT` 缓存；检测失败清理自身 `LAST_CHECK_AT`，并触发现有自动上线同步。
- 管理端节点编辑弹窗在选择父节点后展示 TCP 端口检测开关，保存到后端。

## 验收

- PHP 单测覆盖子节点开启 TCP 检测后不继承父节点运行缓存。
- PHP 单测覆盖本地 TCP 端口连通 / 断开时的缓存与自动上线显隐结果。
- 前端构建通过，保存 payload 含 `tcp_check_enabled`。
