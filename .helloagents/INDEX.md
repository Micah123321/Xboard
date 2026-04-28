# Xboard-new 知识库

```yaml
kb_version: 2
project: Xboard-new
updated_at: 2026-04-28
active_package: 无
```

## 项目概览

- 类型: PHP Laravel 主仓 + `admin-frontend` Vue3 管理端前端
- 当前重点模块: `admin-frontend`、`deploy`、`node-gfw-check`、`order-payment`、`queue-mail`、`subscription-protocols`
- 最新归档: `202604281303_xboard-reusable-server-deploy`

## 活跃模块

- [admin-frontend](modules/admin-frontend.md): 管理端登录、主布局、仪表盘、用户/节点/订阅/系统管理与管理 API 前端封装
- [ci-workflows](modules/ci-workflows.md): GitHub Actions 后端与管理端前端镜像发布工作流、路径触发边界和 GHCR 发布规则
- [deploy](modules/deploy.md): 可复制到服务器的 Xboard Compose 部署模板、环境变量模板和运维脚本
- [node-gfw-check](modules/node-gfw-check.md): 节点墙状态检测任务、父/子节点继承规则、mi-node 检测上报链路
- [order-payment](modules/order-payment.md): 订单支付成功快照、第三方回调元信息透传与后台支付成功信息展示
- [queue-mail](modules/queue-mail.md): 邮件发送队列、SMTP 运行时配置、Horizon 超时与失败重试边界
- [subscription-protocols](modules/subscription-protocols.md): 客户端订阅导出入口、协议适配器与版本兼容过滤

## 归档与变更

- 归档索引: [archive/_index.md](archive/_index.md)
- 变更日志: [CHANGELOG.md](CHANGELOG.md)
