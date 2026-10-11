# BEpusdt 服务器

- SSH 配置：当前 Xboard-new 工作区，环境 `bepusdt`。凭据由宿主凭据服务保存。
- 服务器：`89.106.76.60:22`，用户 `root`。
- 远程目录：`/root/docker_server/BEpusdt`。
- 访问地址与 Xboard 网关 API 根地址：`http://89.106.76.60:55555`。
- 镜像：`v03413/bepusdt:latest`（本次已拉取）。
- Compose 项目：`bepusdt`；服务：`bepusdt`；容器：`bepusdt-bepusdt-1`。
- 端口：宿主机 `55555` 映射容器 `8080`。
- SQLite 数据目录：`/root/docker_server/BEpusdt/data`，挂载到 `/var/lib/bepusdt`。

## 本次验证

Compose 上传 SHA256 已回读验证；服务器 Compose 配置检查、镜像拉取和容器启动成功。启动后容器显示 Up，IPv4/IPv6 均发布 55555。公网首页返回 HTTP 200；空 JSON 请求到 `/api/v1/order/create-order` 返回业务状态 400（签名丢失），确认网关 API 可达且执行验签。未创建支付订单。

后续 SSH 连接出现 `Timed out while waiting for handshake`，因此容器重启计数及后续本机 HTTP 检查尚未完成，初始登录信息尚未提取。首次首页访问触发了该版本的一次性初始化页，初始登录信息仍在容器启动日志中。

## 取回初始登录信息

在服务器执行以下命令，仅输出该新服务的初始管理员账号、密码和专用入口，避免输出 API Token：

```sh
docker compose -p bepusdt -f /root/docker_server/BEpusdt/compose.yaml logs --no-color bepusdt | awk '/登录账号|登录密码|安全入口/ {print}'
```

管理员访问地址为网关根地址加上日志中的安全入口。实际收款前需要在后台配置收款钱包；API Token 在 BEpusdt 后台获取，并填写到 Xboard 的 BEpusdt 支付配置。

## 日常运维

```sh
docker compose -p bepusdt -f /root/docker_server/BEpusdt/compose.yaml ps
docker compose -p bepusdt -f /root/docker_server/BEpusdt/compose.yaml pull
docker compose -p bepusdt -f /root/docker_server/BEpusdt/compose.yaml up -d
```

更新镜像前备份 data；保持现有数据目录挂载。官方部署文档：https://github.com/v03413/BEpusdt/blob/main/docs/docker/docker.md
