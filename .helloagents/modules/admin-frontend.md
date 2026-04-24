# admin-frontend

## 职责

- 提供 Vue3 管理端登录页、认证状态、路由守卫和主布局
- 封装管理端统计/系统状态、用户管理、节点管理、套餐管理和系统配置接口
- 渲染后台仪表盘、用户管理工作台、节点管理工作台、订阅套餐管理页、系统配置页，以及预留的工单管理入口

## 行为规范

- 登录成功后优先跳转 `redirect` 指定路由，否则回到 `/dashboard`
- 受保护路由在未登录时会自动附加 `redirect` 查询参数
- API 基础路径使用 `/api/v2/{secure_path}`，其中 `secure_path` 来自运行时配置
- 仪表盘以真实后端接口返回值为准，不在前端伪造业务统计
- 仪表盘“收入趋势”支持在同一张趋势图中切换“按金额 / 按数量”，数量模式同步切换摘要卡片、Y 轴标签与最近记录
- 仪表盘“作业详情”支持打开失败作业报错弹窗，集中查看 Horizon 失败作业的报错摘要、失败时间与队列信息
- 仪表盘“节点流量排行 / 用户流量排行”均支持独立的 `10个 / 20个` 显示切换，长列表固定在面板内滚动，避免首页高度失控
- `stat/getTrafficRank` 现支持 `limit=10|20`，前端会按当前排行面板的显示数量重新请求；24h 口径也继续显示涨跌百分比
- 仪表盘 Hero 区提供“刷新全部数据”入口，统一触发总览、趋势、排行和系统状态刷新，并在页面内展示最近一次刷新时间
- 用户管理页通过真实后端 `user/fetch`、`user/update`、`user/generate`、`user/resetSecret`、`user/destroy` 与 `plan/fetch` 完成数据读写
- 新增用户时采用“先 generate，后按邮箱回查并 update”的两段式流程，以兼容后端基础创建接口
- 节点管理页通过真实后端 `server/manage/getNodes` 与 `server/group/fetch` 获取列表，并通过 `server/manage/update`、`server/manage/copy`、`server/manage/drop` 完成首批行级操作
- 节点相关导航入口固定归入“节点管理”分组；`/node-groups` 与 `/node-routes` 本轮先交付结构化占位页，不伪装为完整功能
- 订阅管理新增独立“订阅管理”侧边栏分组，本轮完整实现 `#/subscriptions/plans`，其余订单/优惠券/礼品卡入口先保留禁用态
- 套餐管理页使用真实后端 `plan/fetch`、`plan/save`、`plan/update`、`plan/drop`、`plan/sort` 与 `server/group/fetch`
- 套餐说明编辑采用轻量 Markdown/HTML 编辑器与预览模式，不引入额外富文本依赖
- 系统管理新增独立“系统管理”侧边栏分组，本轮完整实现 `#/system/config`，其余插件/主题/公告/支付/知识库入口先交付结构化占位页
- 系统配置页使用真实后端 `config/fetch`、`config/save`、`config/testSendMail` 与 `config/setTelegramWebhook`，并按站点、安全、订阅、邀请佣金、节点、邮件、Telegram、APP、订阅模板 9 个分组组织长表单
- 当前首页视觉基线为 Apple 风格：纯色分区、系统字体栈、单一蓝色强调和轻量层次
- 性能优化优先级高于装饰性表达，避免远程字体、全局模糊背景和固定特效层

## 依赖关系

- 依赖 `src/api/client.ts` 处理 axios 与认证头
- 依赖 `src/utils/users.ts` 负责用户管理表单转换、筛选组装和状态计算
- 依赖 `src/utils/plans.ts` 负责套餐价格、说明渲染、排序与表单转换
- 依赖 `src/utils/systemConfig.ts` 负责系统配置字段元信息、默认值、回填与保存序列化
- 依赖 Laravel 注入的 `window.settings`
- 构建输出到 `public/assets/admin`
